# Implementation Plan — Land the font-loader work from `claude/cors-font-loader-errors-01cd2j` on `main`

**Target branch:** `claude/serene-meitner-bybf11` · **Version target:** 2.14.0
**Source:** `claude/cors-font-loader-errors-01cd2j` @ `d4b2b13` (6 commits, 2026-07-27)
**Status:** ⬜ Plan only — no code written
**Author date:** 2026-09-14

---

## 1. What this is

STATE.md's *Open questions* records a branch carrying real font-loader work that
never reached `main`:

> **Unmerged branch: `claude/cors-font-loader-errors-01cd2j` @ `d4b2b13`.** Six
> commits from 2026-07-27 (versions 1.13.0 → 1.15.0) fixing localized fonts
> blocked by CORS after a domain change, plus per-stage font scan reporting.
> **The work is genuinely absent from `main`** […] Treat as a dedicated session
> with a full fonts re-test, not a housekeeping merge.

This plan is that dedicated session's instructions. It covers four distinct
fixes the branch shipped, in the order they were found:

| Src version | Fix |
|---|---|
| 1.13.0 | Localized fonts blocked by CORS after a domain change — portable token CSS, root-relative URLs, self-healing `fonts.css`, ACAO `.htaccess`, upgrade migration, Fonts/CSP diagnostics |
| 1.13.1 | Regenerating `fonts.css` purges QUIC.cloud UCSS/CCSS and the CSS/JS combine cache, not just the page cache |
| 1.14.0 / 1.14.1 | Per-stage scan diagnostics, persisted across reloads, inline counts on the outcome line, retained on the failure path |
| 1.15.0 | **Font discovery was blind to any font it had already localized** — the scan's own loopback dequeued the very stylesheets it was scanning for |

The 1.15.0 item is the one with the widest blast radius on shipped installs: it
means every site with "Self-host Google Fonts" enabled has a font set frozen at
whatever its *first* scan caught, and no re-scan can ever add to it.

## 2. Why this is a replay, not a merge

The branch forked at `84ac510` (2026-07-23). `main` has advanced **48 commits**
since. A `git merge` conflicts on six files, and `main` must win outright on
every version-bearing one — it is at 2.13.0 and the branch would drag it back to
1.15.0.

But the conflict list overstates the actual work. Measured against the fork
point, here is what `main` did to each file the branch touches:

| File | `main` changed it since fork? | Port strategy |
|---|---|---|
| `src/components/FontsSettings.jsx` | **No — byte-identical to the fork point** | Take the branch's file wholesale |
| `includes/modules/class-spfw-module-fonts.php` | Yes, but **only +41 lines in one commit** (`b96a499`), adding `preload_local_fonts()` and its `register()` line | Take the branch's file wholesale, then re-apply `preload_local_fonts()` **adapted** — see H1/H3 |
| `includes/class-spfw-settings.php` | Heavily (+635) — but not in the fonts defaults, fonts sanitize, or migration ordering | Hand-apply the branch's three small hunks |
| `includes/class-spfw-rest-settings.php` | Heavily (+1501) | Hand-apply **one line**; the anchor survives at `:247` |
| `src/components/CspPolicyCard.jsx` | Heavily (+1573) | Hand-apply **one string**; the anchor survives at `:2068` |
| `src/components/App.jsx` | Heavily (+560) | Hand-apply a 5-line hunk, **adapted** — see H4 |
| `readme.txt`, `simple-performance-for-wordpress.php` | Version files | `main` wins; author one new 2.14.0 entry |
| `.distignore` | Yes | Add one line for this plan file |

So the mechanical work is small. **The value of this session is in §4** — four
integration hazards that a clean merge would have introduced silently, and that
neither the branch nor STATE.md records, because they did not exist when the
branch was written.

Do **not** run `git merge origin/claude/cors-font-loader-errors-01cd2j`. Replay
per file, from `d4b2b13` as the reference.

## 3. Order of work

1. §5.1 fonts module (the bulk)
2. §5.2 settings (defaults, sanitize, migration)
3. §5.3 REST
4. §5.4 admin UI
5. §4 hazards — each is checked off as part of the file it lives in, but read
   all four before starting, because H1 and H2 change what you write
6. §6 version + changelog
7. §7 tests
8. §8 verification

## 4. Integration hazards — read before writing code

### H1 — `preload_local_fonts()` bypasses the entire fix (load-bearing)

`main` gained this in `b96a499`, after the branch forked, so the branch has
never seen it. It is in `includes/modules/class-spfw-module-fonts.php:427-466`:

```php
$base_url = $this->fonts_url();
…
printf(
    '<link rel="preload" as="font" type="font/woff2" href="%s" crossorigin>' . "\n",
    esc_url( $base_url . '/' . $file )
);
```

`fonts_url()` returns an **absolute, fully-qualified** URL — exactly the thing
the branch exists to stop emitting. And `rel="preload" as="font"` carries a
mandatory `crossorigin` attribute, so the preload is fetched in CORS mode just
like the CSS-referenced font is.

Consequence: on a site cloned to a new domain, or with `upload_url_path` left
pointing at the original host, the generated `fonts.css` would be correctly
root-relative while `<link rel="preload">` in `<head>` still points at the old
origin — and fails with `blocked by CORS policy` + `ERR_FAILED 200 (OK)`, the
exact console signature of the original report, now reintroduced through a
code path the fix does not touch. The first four fonts (the cap) are the
body-text weights, so the visible symptom is close to the original bug.

**Fix:** `preload_local_fonts()` must build its href from `rendered_base()`,
not `fonts_url()`:

```php
$base_url = $this->rendered_base();
```

`rendered_base()` returns a root-relative path (`/wp-content/uploads/ods-fonts`)
on a same-host install and the absolute URL otherwise, so the preload href and
the `src: url()` inside `fonts.css` resolve to the same absolute URL in both
cases. That match matters for its own sake: if the two ever diverge the browser
fetches each font twice and logs *"preloaded but not used"*.

Note `esc_url()` handles a root-relative path correctly, so no other change is
needed. Add a regression test (§7) asserting the preload href and the CSS `src`
resolve identically on a moved domain — this hazard is invisible to every test
the branch wrote.

### H2 — the migration's version gate is dead code on `main`

The branch gates its portability migration on:

```php
if ( version_compare( $stored_ver, '1.13.0', '<' ) && … )
```

Every install that will ever receive this code is already at 2.13.0. Ported
verbatim, **the migration never runs** — so no existing install gets its stored
CSS tokenized, and the "fixed on upgrade without a re-scan" property (the whole
point of §3.5 in `FONT_CORS_FIX_PLAN.md`) is silently lost. The fix would appear
to work in a fresh-install test and do nothing in the field.

**Fix:** re-gate to the new target version:

```php
if ( version_compare( $stored_ver, '2.14.0', '<' )
    && ! empty( $stored['fonts']['discovered']['css'] ) ) {
    self::run_font_portability_migration( $stored );
```

Place it **after** the existing `2.11.0` block in `get()` and follow the
cache-before-migration ordering the file already documents at `:240-244`
(`Settings_Migration_Recursion_Test.php` exists precisely because a nested
`get()` recursed infinitely once). Update the docblock on
`run_font_portability_migration()` to say 2.14.0.

Keep the branch's deliberate deviation: the migration **recomputes**
`discovered['hash']` from the tokenized CSS. That is the cache bust that evicts
a browser- or LiteSpeed-held copy still carrying absolute URLs. What must stay
true is that the hash is identical on production and staging *after* migration,
so a domain move causes no churn — assert that, not hash stability across the
migration itself.

### H3 — `register()` conflicts with the 1.15.0 scan-blindness fix

The branch rewrites `register()` so both `serve_local_fonts` and
`remove_google_resource_hints` stand down during the scan's own loopback:

```php
if ( ! $is_scan && ! empty( $fonts['localize_google'] ) && ! empty( $fonts['discovered']['css'] ) ) {
```

`main` added a third hook to that same block. Put it inside the same guard:

```php
if ( ! $is_scan && ! empty( $fonts['localize_google'] ) && ! empty( $fonts['discovered']['css'] ) ) {
    add_action( 'wp_enqueue_scripts', array( $this, 'serve_local_fonts' ), 99 );
    add_filter( 'wp_resource_hints', array( $this, 'remove_google_resource_hints' ), 10, 2 );
    add_action( 'wp_head', array( $this, 'preload_local_fonts' ), 2 );
}
```

Preload links point at `ods-fonts`, not `fonts.googleapis.com`, so leaving them
on during a scan would not corrupt discovery — but emitting preloads into a
throwaway loopback render is pure waste, and keeping all three hooks under one
condition is what makes the invariant ("during a scan this module does nothing
to the page") checkable in one place. Extend the branch's existing 8-assertion
`register()` harness to cover the third hook.

### H4 — `App.jsx` uses `mergeServerSettings`, not `setSettings`

The branch's failure-path refetch is:

```js
return apiFetch( { path: '/spfw/v1/settings' } )
    .then( ( data ) => setSettings( data ) )
    .catch( () => {} );
```

`main` gained unsaved-changes tracking in 2.8.0 (`src/lib/settings-merge.js`).
Calling `setSettings()` with a raw server payload is exactly the bug that module
was written to prevent — it discards whatever the admin has typed and not saved.
Use the wrapper `main` already applies at the other four call sites:

```js
return apiFetch( { path: '/spfw/v1/settings' } )
    .then( ( data ) => mergeServerSettings( data ) )
    .catch( () => {} );
```

### H5 — `last_scan_report` sits inside a persisted group (accept, but know it)

`PERSISTED_GROUPS` in `src/lib/settings-merge.js` includes `'fonts'`, so both
new keys — `last_scan_report` (message + full diagnostics array) and
`rendered_for` — are part of the dirty-state fingerprint and are POSTed back on
every Save.

Traced through, this does not break: `pendingEdits()` compares per key against
`savedSettings`, and both keys move from server to client together, so neither
registers as a pending edit and neither leaves the form permanently dirty.

Two real but minor effects, to accept knowingly rather than discover later:

1. Every settings POST carries the diagnostics blob. It is bounded (per-stage
   counts and a handful of URLs), so this is size, not correctness.
2. A browser holding a settings page open across a front-end self-heal can POST
   a stale `rendered_for` over the newer server value. The cost is one extra
   `fonts.css` regeneration on the next front-end request — the self-heal is
   idempotent and transient-locked, so it converges.

Do **not** "fix" this by adding a read-only exclusion mechanism to
`settings-merge.js`; that is a larger change than the problem warrants. Do
tighten sanitization, which is a genuine gap: the branch stores
`last_scan_report` as a raw array with no deep sanitize, and its contents are
partly derived from remote HTTP responses (Google URLs, `WP_Error` messages).
Add a `sanitize_scan_report()` that walks the known keys, `absint()`s the
counts, `esc_url_raw()`s the URLs, and `sanitize_text_field()`s the messages.
phpcs will want this regardless, and CI runs phpcs.

## 5. Per-file work

### 5.1 `includes/modules/class-spfw-module-fonts.php`

Take `d4b2b13`'s version of the file wholesale:

```
git show d4b2b13:includes/modules/class-spfw-module-fonts.php > includes/modules/class-spfw-module-fonts.php
```

It is a strict superset of `main`'s except for `preload_local_fonts()`. Then:

1. Re-add `preload_local_fonts()` from `main` (`b96a499`), with the `$base_url`
   change from **H1**.
2. Apply **H3** to `register()`.
3. Confirm `discovered['files']` is still a list of basenames on both sides
   (it is — `:416` on the branch, `:304` on `main`), so `preload_local_fonts()`
   needs no other adaptation.

What the branch's version brings in:

- `FONTS_URL_TOKEN` / `FONTS_URL_PATTERN` constants; `scan()` writes the token
  instead of an absolute URL.
- `portable_css()` (absolute → token, idempotent), `render_css()` (token →
  base), `rendered_base()` (root-relative when uploads are same-host, absolute
  otherwise).
- `write_css_file()` as the single choke point, normalizing CSS of either
  vintage on the way through.
- `refresh_css_file()` — regenerates on a `rendered_for` mismatch under a
  5-minute transient lock, then purges.
- `purge_generated_css()` — fires `litespeed_purge_all_ucss`, `_ccss`,
  `_cssjs`, then `litespeed_purge_all` last.
- `cors_htaccess_payload()` / `write_cors_htaccess()` — `mod_headers`-guarded
  ACAO for `uploads/ods-fonts/`, written from `write_css_file()` (not
  `ensure_fonts_dir()`, which would re-hash once per downloaded font).
- `runtime_info()` for the admin diagnostics block.
- `$diag` threaded through every `scan()` stage; `diag_summary()`,
  `store_scan_report()`; `finish_scan()` gains `$rendered_for` and
  `$diagnostics` parameters.
- `maybe_capture_during_scan()` returning whether this request is an authorized
  scan loopback (the 1.15.0 fix).

Checks against the rest of `main`:

- `SPFW_Settings::value()`, `::group()`, `::update()` all exist on `main`
  (`:397`, `:383`, `:410`) — `runtime_info()` and `refresh_css_file()` need no
  adaptation.
- `uploads/ods-fonts/.htaccess` does not collide with the hardening subsystem.
  `SPFW_Htaccess` targets are `plugins`/`uploads`/`root` only, and the branch
  deliberately does **not** touch `SPFW_Htaccess::payload()` — doing so would
  change its sha1 and flip every install's hardening status to `altered`,
  firing a false "file has been modified" notice. Keep it that way.
- `uninstall.php:69-76` deletes `ods-fonts/` recursively, so the new
  `.htaccess` is already covered by uninstall cleanup. No change needed —
  confirm, don't assume.

### 5.2 `includes/class-spfw-settings.php`

Three hunks, hand-applied:

1. **Defaults** (`:194` fonts group): add `'last_scan_report' => array()` and
   `'rendered_for' => ''` with the branch's comments.
2. **Sanitize** (`:704-715`): add the two keys. Use `sanitize_scan_report()`
   for the report per **H5**; `sanitize_text_field()` for `rendered_for` as the
   branch has it.
3. **Migration**: `run_font_portability_migration()` plus its gate, re-versioned
   to 2.14.0 per **H2**, placed after the 2.11.0 block.

The token literal stays duplicated in the migration (settings load before the
module files, so `SPFW_Module_Fonts::FONTS_URL_TOKEN` is unavailable there).
Keep the branch's comment saying so on both sides.

### 5.3 `includes/class-spfw-rest-settings.php`

One line, into `get_settings()` after `:247`:

```php
$settings['fonts_runtime'] = ( new SPFW_Module_Fonts() )->runtime_info();
```

Safe to add: `SPFW_Settings::sanitize()` rebuilds `$clean` from an explicit key
list, so an unknown inbound key can never be persisted. `update_settings()`
returns `$this->get_settings()`, so a Save response carries `fonts_runtime`
too and the Fonts tab does not blank its diagnostics after a save — verify this
still holds rather than taking it on faith.

### 5.4 Admin UI

- **`src/components/FontsSettings.jsx`** — take `d4b2b13`'s file wholesale; it
  is unchanged on `main` since the fork. Props are identical
  (`{ settings, onChange, onScan }`), and it reads `settings.fonts_runtime`,
  `settings.scan_result`, and `fonts.last_scan_report` off the payload, so
  `App.jsx` needs no prop wiring. Brings: "Serving fonts from" line,
  cross-origin amber banner, collapsible "Scan details", persisted-report
  fallback, and a prettier fix on the Extra-pages textarea.
- **`src/components/App.jsx`** — the failure-path refetch in
  `handleScanFonts()` (`:286-296`), with the **H4** change.
- **`src/components/CspPolicyCard.jsx`** — replace the CDN hint string at
  `:2068` with the branch's longer version covering CORS failures and the
  misleading `ERR_FAILED 200 (OK)`. String replacement only; the surrounding
  JSX is unchanged.

## 6. Version and changelog

2.14.0 — minor, not patch: new settings keys, a new on-disk artifact, a new
REST response block, and a migration.

- `simple-performance-for-wordpress.php`: header `Version:` and `SPFW_VERSION`.
- `readme.txt`: `Stable tag` and **one** `= 2.14.0 =` changelog entry.

Do not import the branch's `= 1.13.0 =` … `= 1.15.0 =` blocks as-is — they would
insert versions below the existing 2.x history and read as a regression. Fold
their user-facing lines into a single 2.14.0 entry, leading with the discovery
fix (widest impact), then the CORS fix, the purge fix, and the scan reporting.

`.distignore`: add `FONT_LOADER_MERGE_PLAN.md`. The branch also adds
`FONT_CORS_FIX_PLAN.md`; carry that file across too (it is the root-cause
record for §2.4's UCSS finding, which nothing else captures) and ignore it as
well.

## 7. Tests

`main` has CI (`php-lint` on 8.0-8.3, `phpcs`, `phpunit`) and a jest suite that
the branch never had — its verification was ad-hoc harnesses run once and
discarded. Convert them into tests that actually live in the repo:

**PHPUnit** — new `tests/Fonts_Portability_Test.php`, following the existing
stub-and-load pattern in `tests/Asset_Dequeue_Test.php`:

1. `portable_css()` rewrites an absolute `…/ods-fonts/a.woff2` to the token, and
   is idempotent on already-tokenized CSS.
2. `render_css( portable_css( $x ) )` on a same-host install yields
   `/wp-content/uploads/ods-fonts/a.woff2`.
3. Cross-host uploads yield the absolute URL and `same_origin: false`.
4. The regex leaves `local()`, `font-family`, and `unicode-range` alone even
   when one contains the substring `ods-fonts`.
5. `discovered['hash']` is identical on production and staging for the same font
   set (the domain-move property), and changes once across the migration.
6. Self-heal: production URLs on disk → first `serve_local_fonts()` rewrites to
   root-relative, records `rendered_for`, fires all four purge hooks with
   `litespeed_purge_all` **last**, writes the CORS file; subsequent requests do
   nothing.
7. Unwritable uploads → graceful fallback to Google, no enqueue, no write storm.
8. **H1 regression:** after a domain move, the `preload_local_fonts()` href and
   the `src: url()` in the rendered CSS resolve to the same absolute URL.
9. **H3:** `register()` across three request types — normal front-end request
   localizes, strips hints, and preloads; valid-token loopback captures and
   attaches none of the three; forged/stale token leaves localization active
   and captures nothing.
10. **H2:** a stored version of 2.13.0 triggers the portability migration; 2.14.0
    does not.

Add the migration case to `tests/Settings_Migration_Recursion_Test.php`'s
coverage so the new block is proven not to recurse.

**Jest** — extend `src/lib/test/settings-merge.test.js` to cover a payload
carrying `fonts.last_scan_report` and `fonts.rendered_for` alongside a pending
edit to `fonts.manual_families`, asserting the edit survives (H5's reasoning,
made executable).

**Toolchain** — `composer lint`, `npm run build`, `npm run lint:js`,
`npm run lint:css`, `npm run test:js`, and `php -l` on every changed PHP file.

## 8. Verification

### 8.1 Static

Everything in §7, green.

### 8.2 Live QA — still owed, and not satisfiable from the build environment

The branch never completed live QA: its session's network policy blocked
`staging.laseraesthetics.org`, and the four QA items below were carried into
STATE.md as outstanding. They are still outstanding, and this session does not
discharge them. Say so explicitly in STATE.md rather than letting the port read
as verification.

1. `FONT_CORS_FIX_PLAN.md` §5.2 steps 1-8 in order — the two baseline `curl`
   checks **before** installing, so the fix is measured against a confirmed
   before-state.
2. **Ordering hazard:** the reporting site purges all on plugin change, so
   installing can requeue UCSS generation *before* the first front-end request
   regenerates `fonts.css`, rebuilding the derived file from the old
   stylesheet. Regenerate first (load one front-end page), then purge.
3. The `litespeed_purge_all_ucss` / `_ccss` / `_cssjs` hook names are taken
   from LSCache's documented purge API and have **never** been verified against
   a live install. They fire via `do_action`, so a wrong name is a silent no-op
   — which looks exactly like success. Confirm the UCSS file is actually
   rebuilt, not merely that no error appears.
4. `uploads/ods-fonts/.htaccess` has never run on a real OpenLiteSpeed vhost.
   The `<IfModule mod_headers.c>` guard is there so a server without
   `mod_headers` ignores it rather than 500s. Confirm on the live host.
5. **New, from H1:** on a site whose `upload_url_path` still points at another
   host, confirm the `<link rel="preload">` hrefs in `<head>` match the font
   URLs in `fonts.css` and that DevTools shows no duplicate font fetches and no
   "preloaded but not used" warning.
6. **New, from H2:** upgrade an install sitting at 2.13.0 with localized fonts
   and confirm the stored CSS is tokenized and `fonts.css` regenerates on the
   next front-end request, with no re-scan.

### 8.3 The one open question the branch left behind

Both of the user's 1.15.0 scans reported `0 from manual declarations` while the
UI textarea held `Roboto Condensed:400,700` and `Open Sans:400,600,700`. A
reflection harness proved `manual_css_urls()` → `parse_font_faces()` handles
those specs correctly (44 faces → 17 files), so the URL builder is not at fault.
The scan report now records `manual_declared` (what is stored) separately from
`manual` (what could be built), which decides between a persistence bug and a
spec-parsing bug. **That distinction has never been read on a live site.** It is
the first thing to look at on the next real scan.

## 9. Risks

| Risk | Mitigation |
|---|---|
| Preload keeps emitting absolute URLs, so the bug survives the fix | H1 + the §7.8 regression test |
| Migration never fires in the field | H2 + the §7.10 gate test |
| Unsaved admin edits discarded on a failed scan | H4 — use `mergeServerSettings` |
| Root-relative URLs break an uploads-on-CDN install | Host comparison in `rendered_base()` keeps absolute URLs there |
| `Header` directives 500 an OLS vhost without `mod_headers` | `<IfModule mod_headers.c>` guard; QA §8.2.4 |
| Changing the shared htaccess payload flags every install `altered` | Separate font-directory file; `SPFW_Htaccess::payload()` untouched |
| Front-end option write during self-heal | Fires once per base change, transient-locked against retry storms |
| A wrong LSCache purge hook name looks identical to success | QA §8.2.3 confirms the UCSS file is rebuilt, not just that nothing errored |
| Replaying two whole files loses a `main`-side change | Only `class-spfw-module-fonts.php` has one (`preload_local_fonts`); `FontsSettings.jsx` is byte-identical to the fork point. Re-diff both against `origin/main` before committing |

## 10. Out of scope

- Step 18 (server abstraction / nginx). Unrelated and still design-only.
- Deleting `claude/wordpress-speed-hardening-ae1kb6` — needs a repo admin
  (HTTP 403 on ref deletion from a session credential); recorded in STATE.md.
- A general-purpose CORS header UI for arbitrary asset types. The font
  directory is the only place this plugin owns.
- `.pot` regeneration (wp-cli unavailable here). Now covers more untranslated
  strings: "Serving fonts from", the cross-origin warning, the extended CSP
  hint, and the whole Scan-details block.
- The CDN-bypassing loopback (direct-to-origin with a `Host:` header)
  considered in 1.14.0 and deferred until the diagnostics say whether the
  loopback is in fact the problem. Still deferred — see §8.3.

## 11. Afterwards

Per the update protocol: flip Step 19 to ✅ with its commit hash, update
**Overall status**, **Last updated**, and **Next action**, move the
`claude/cors-font-loader-errors-01cd2j` entry out of *Open questions* and into
the decisions log as resolved, and record the outstanding live QA from §8.2 as
the new open item. Do not mark Step 19 ✅ on static tests alone — the acceptance
criteria include the field checks.
