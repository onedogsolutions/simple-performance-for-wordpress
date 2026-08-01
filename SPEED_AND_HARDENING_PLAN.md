# Speed & Hardening Gap-Closure Plan

Implementation plan for the feature gaps identified in the 2026-08-01 review of
Simple Performance for WordPress 1.12.0, scoped to the target stack
(OpenLiteSpeed + LiteSpeed Cache + QUIC.cloud, WooCommerce, MainWP).

**Working branch:** `claude/wordpress-speed-hardening-ae1kb6`

---

## Scope boundary (read first)

Everything below is chosen because **LiteSpeed Cache does not do it**. The
following are deliberately excluded and must stay excluded — adding them would
create the overlap the plugin exists to avoid:

page caching · minify/combine · critical CSS · UCSS · image optimization &
WebP · browser-cache headers · CDN rewriting · JS defer/delay · DB cleanup ·
query-string dropping · cache preloading/crawling

Also permanently excluded: `Options -Indexes` in any emitted `.htaccess` — it
requires `AllowOverride Options` and can 500 an Apache vhost that lacks it
(rationale already recorded at `includes/class-spfw-htaccess.php:60`).

---

## Cross-cutting decisions

These five decisions shape multiple phases. Settle them before writing code.

### D1 — Changing `SPFW_Htaccess::payload()` invalidates every stored hash

`SPFW_Htaccess::status()` (`includes/class-spfw-htaccess.php:171`) compares
`sha1_file()` against the hash stored in settings. The moment `payload()`
changes (Phase B, item H1), every existing install with hardening enabled
flips to `altered` and fires the scary "file has been modified" admin notice
at `class-spfw-module-hardening.php:105`.

**Required:** a `legacy_payload_hashes()` list and an upgrade migration that,
for each enabled target, compares the on-disk file to the known legacy hashes.
On a match, silently rewrite with the new payload and store the new hash. On no
match, leave it alone and let it report `altered` — that is a genuine foreign
edit. Never rewrite a file we can't prove we authored.

### D2 — `SPFW_Htaccess` needs two ownership modes

Today the class assumes it owns the entire file. The root `.htaccess` is owned
by WordPress (`save_mod_rewrite_rules()` rewrites it whenever permalinks are
saved), so whole-file ownership is not available there.

**Required:** generalize to a targets map where each target declares a mode.

- `own_file` — plugins/, uploads/. We author the whole file. Integrity = sha1 of
  the file. Current behavior, unchanged.
- `marker_block` — site root. Write via `insert_with_markers()` so WordPress'
  own rules are preserved. Integrity = sha1 of the **extracted block between our
  BEGIN/END markers**, not the whole file, so a permalink save doesn't trip the
  altered notice.

Also fold the inconsistent hash keys (`htaccess_hash` vs
`uploads_htaccess_hash`) into the per-target config rather than adding a third
ad-hoc key name.

### D3 — CSP nonces are incompatible with full-page caching; use hashes

The obvious fix for `'unsafe-inline'` in `script-src` (`DEFAULT_CSP`,
`class-spfw-module-hardening.php:39`) is a per-request nonce. **It cannot work
on this stack.** LiteSpeed Cache stores the response headers alongside the
cached HTML, so a cached page serves a frozen nonce to every visitor for the
life of the cache entry. A value every visitor can read is not a nonce, and it
provides no XSS protection whatsoever — while looking like it does.

**Decision:** Phase E uses **hash sources** (`'sha256-…'`), which are stable
across cache hits and therefore correct here. Nonce mode is not implemented.
This must be stated in the UI so nobody files it as a missing feature.

### D4 — Every new toggle touches five places

Mechanical, but a missed step ships a setting that silently resets on save:

1. `SPFW_Settings::defaults()` — `includes/class-spfw-settings.php:29`
2. `SPFW_Settings::sanitize()` — the matching `*_bools` array, or a bespoke
   sanitizer for non-booleans (`:264`)
3. React tab component in `src/components/`
4. `languages/simple-performance-for-wordpress.pot` — regenerate; every new
   `__()` string is untranslated until then
5. Version bump in **three** places — plugin header, `SPFW_VERSION`
   (`simple-performance-for-wordpress.php:4` and `:20`), and `Stable tag` in
   `readme.txt:7` — plus a `== Changelog ==` entry

### D5 — Phase ordering is risk-ordered, not value-ordered

Phase B rewrites server config files and is the only phase that can take a site
down. It is isolated in its own release so a rollback is a single version
revert. Phase A ships user-visible value first with zero server-side risk.

---

## Phase A — 1.13.0 · Low-risk toggles

Six items, no schema surgery, no `.htaccess` changes, no server-config risk.
Every item is the same shape as toggles already shipping in `SPFW_Module_Core`.

### A1 · Disable block-editor frontend CSS

**Why.** Highest-value single omission. On a classic or page-builder theme,
`wp-block-library` + `global-styles` is ~30–60KB of render-blocking CSS that
nothing on the page uses.

**File.** `includes/modules/class-spfw-module-core.php`

**Implementation.** On `wp_enqueue_scripts` at priority 100, frontend only:

```php
wp_dequeue_style( 'wp-block-library' );
wp_dequeue_style( 'wp-block-library-theme' );
wp_dequeue_style( 'wc-blocks-style' );      // only when the Woo toggle is off
wp_dequeue_style( 'classic-theme-styles' );
```

Plus `remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' )` and
`remove_action( 'wp_footer', 'wp_enqueue_global_styles_custom_css' )` for the
theme.json-derived inline blob.

**Guard.** Sub-option `block_css_smart_mode` (default on): skip the dequeue when
`has_blocks( get_queried_object_id() )` is true, so a mostly-classic site with a
few block pages doesn't break. Global styles are site-wide and cannot be
conditionally removed per-post — document that the smart mode covers the
enqueued stylesheets only.

**Settings.** `core.disable_block_css` (bool, default `false`),
`core.block_css_smart_mode` (bool, default `true`).

**Acceptance.** Classic theme, toggle on: `wp-block-library` absent from source,
page renders identically. Block theme or a page with blocks + smart mode on:
stylesheet still present.

**Risk.** Medium — this is the one Phase A item that can visibly break a page.
Default off, and the UI copy must say "for classic / page-builder themes".

### A2 · Dashboard widget bloat

**Why.** `dashboard_primary` makes a **blocking outbound HTTP request** to
wordpress.org on every dashboard load. It is usually the slowest thing in
wp-admin. The module already removes the comments widget
(`class-spfw-module-core.php:557`), so the hook is already wired.

**File.** `includes/modules/class-spfw-module-core.php` — extend
`remove_comments_dashboard_widget()` into a general
`remove_dashboard_widgets()` on `wp_dashboard_setup`.

**Implementation.** `remove_meta_box()` for `dashboard_primary` (Events &
News), `dashboard_quick_press`, `dashboard_activity`, `dashboard_site_health`,
`dashboard_right_now`. One toggle covering the set, not five toggles.

**Settings.** `core.streamline_dashboard` (bool, default `false`).

**Acceptance.** Toggle on: dashboard loads with no request to
`api.wordpress.org` / `wordpress.org/news` in the network log.

**Risk.** Low. Admin-only, fully reversible.

### A3 · Disable Application Passwords

**Why.** The loose thread in the REST work. `restapi.require_auth`
(`class-spfw-module-restapi.php:89`) is satisfied by an application password,
which also bypasses any 2FA plugin. One line closes it.

**File.** `includes/modules/class-spfw-module-hardening.php`

**Implementation.**
`add_filter( 'wp_is_application_passwords_available', '__return_false' )`.

**Settings.** `hardening.disable_app_passwords` (bool, default `false`).

**Acceptance.** Toggle on: the Application Passwords section is gone from
`user-edit.php`; an existing app-password Basic-Auth request to
`/wp-json/wp/v2/posts` returns 401.

**Risk.** Low, but it **will** break existing integrations that authenticate
with app passwords. UI copy must warn explicitly. Note for MainWP users: the
MainWP child channel is signed and does not use app passwords, so the bridge at
`includes/class-spfw-mainwp-child.php` is unaffected.

### A4 · Generic login error messages

**Why.** `block_author_enum` (`class-spfw-module-hardening.php:148`) stops
`?author=N` probing, but `wp-login.php` still discloses whether a username
exists. The existing defense is half-built without this.

**File.** `includes/modules/class-spfw-module-hardening.php`

**Implementation.** Filter `login_errors` to a single generic string. Also
filter `wp_login_errors` to suppress the "check your email" confirmation
variants that leak account existence on password reset.

**Settings.** `hardening.generic_login_errors` (bool, default `false`).

**Acceptance.** Bad username and bad password produce byte-identical responses.

**Risk.** Low. Mild support cost — real users get a less helpful message.

### A5 · Close the sitemap author-enumeration leak

**Why.** A genuine interaction bug, not a new feature. With
`block_author_enum` on but `disable_wp_sitemaps` off,
`/wp-sitemap-users-1.xml` still lists every author nicename — re-leaking exactly
what A4 and the author-redirect are protecting.

**File.** `includes/modules/class-spfw-module-core.php` or the hardening module
(place it with `block_author_enum` so the coupling is obvious in code).

**Implementation.** When `block_author_enum` is on, filter
`wp_sitemaps_add_provider` to return `false` for the `users` provider. **No new
toggle** — this is a correctness fix to an existing one.

**Acceptance.** `block_author_enum` on, sitemaps on: `/wp-sitemap.xml` no longer
indexes a users sitemap, and `/wp-sitemap-users-1.xml` 404s. Other sitemaps
unaffected.

**Risk.** Low. Document it in the `block_author_enum` help text so the behavior
isn't surprising.

### A6 · Preload localized fonts

**Why.** A gap inside a feature already built. `serve_local_fonts()`
(`class-spfw-module-fonts.php:394`) enqueues `fonts.css` as an ordinary
stylesheet, so the browser must fetch and parse that CSS before it discovers any
`.woff2` URL — two serialized round trips before text renders. The file list is
already known on disk.

**File.** `includes/modules/class-spfw-module-fonts.php`

**Implementation.** On `wp_head` at priority 2 (before the stylesheet link),
emit for each localized file:

```html
<link rel="preload" as="font" type="font/woff2" href="…" crossorigin>
```

Cap at the first 4 files, ordered by weight 400 first — preloading a dozen fonts
is a net loss, and unused preloads produce a console warning.

**Also verify.** Confirm `font-display: swap` survives into the written CSS.
It's correct in the Google request URL (`:645`) but `write_css_file()` (`:901`)
should be checked, and `swap` injected if absent.

**Settings.** `fonts.preload_fonts` (bool, default `true` when
`localize_google` is on — no separate UI row needed if it's unconditional;
prefer unconditional unless QA finds a reason otherwise).

**Acceptance.** Lighthouse no longer flags render-blocking font discovery; no
"preloaded but not used" console warnings.

**Risk.** Low.

**Phase A deliverables:** 5 new settings keys, 6 code changes, `.pot`
regeneration, `readme.txt` changelog, version → 1.13.0, STATE.md update.

---

## Phase B — 1.14.0 · `.htaccess` subsystem rework

The only phase that can take a site down. Ship it alone.

### B0 · Refactor `SPFW_Htaccess` (prerequisite)

Implements decisions **D1** and **D2**. No user-visible change on its own —
land it first and verify existing installs still report `ok`.

**File.** `includes/class-spfw-htaccess.php`

**Implementation.**

- Replace `config()`'s hardcoded if/else (`:25`) with a `TARGETS` map:
  `{ path, toggle, hash_key, mode, payload_method }`.
- `payload()` becomes per-target.
- Add `mode` handling: `own_file` (existing sha1-of-file) and `marker_block`
  (write via `insert_with_markers()`, sha1 the extracted block only).
- Add `legacy_payload_hashes( $target )` returning known prior payload hashes.
- Add an upgrade migration in `SPFW_Settings` (mirroring the existing pattern at
  `class-spfw-settings.php:144`) that runs at `< 1.14.0`: for each enabled
  target, if on-disk sha1 matches a legacy hash, rewrite + restore the new hash.
- Extend `SPFW_Module_Hardening::HTACCESS_TARGETS` (`:23`) to the new targets.
- `remove()` (`:137`) must respect `marker_block` — remove only our block, never
  the file.

**Acceptance.** An install upgraded from 1.13.0 with both existing targets
enabled shows `ok` for both, with no admin notice, and the files on disk carry
the new payload.

**Risk.** High. This is the migration that decides whether thousands of installs
see a false "your security file was tampered with" alarm.

### B1 · Fix the deny-PHP extension glob

**Why.** `<Files *.php>` (`class-spfw-htaccess.php:68`) is a literal glob. It
does not match `.phtml`, `.php5`, `.php7`, `.phps`, or `.phar` — precisely the
extensions a dropper uses once `.php` is blocked. This is a correctness fix to a
shipped security feature, not an enhancement.

**Implementation.** Replace both `<Files *.php>` blocks with:

```apache
<FilesMatch "\.(?i:php[0-9]*|phtml|phps|phar|inc)$">
	Require all denied
</FilesMatch>
```

Keep the `<IfModule !mod_authz_core.c>` legacy fallback with the same
`FilesMatch` pattern.

**Acceptance.** Upload a benign `test.phtml` to uploads/ (toggle on) — direct
request returns 403. Existing `.php` denial still works. Media library uploads
and image serving unaffected.

**Risk.** Medium — depends entirely on B0's migration landing correctly.
`(?i:…)` requires Apache 2.4 / OLS with PCRE; verify on the target OLS build
before release.

### B2 · Root `.htaccess` target — sensitive files + `xmlrpc.php`

**Why.** Two gaps, one mechanism (both are root-level rules, so they share a
marker block).

*Sensitive files:* nothing currently blocks direct reads of `readme.html`
(discloses the exact WP version the plugin strips everywhere else —
`class-spfw-module-core.php:364`), `wp-content/debug.log` (one of the most common
real-world WordPress leaks), `.env`, `*.sql`, `*.bak`, or `.git/`.

*xmlrpc.php:* the `xmlrpc_enabled` filter (`class-spfw-module-core.php:36`) only
makes authenticated methods return an error — the file still boots all of
WordPress on every hit. A server-level deny turns a full PHP bootstrap into a
403 from OLS, which matters under a brute-force or `system.multicall` flood.

**Implementation.** New `root` target, `marker_block` mode, written via
`insert_with_markers( $path, 'Simple Performance for WordPress', $lines )`.
Two independently-toggled rule groups composed into one block:

```apache
# group: sensitive_files
<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php|.*\.(log|sql|bak|old|orig|env))$">
	Require all denied
</FilesMatch>

# group: block_xmlrpc
<Files "xmlrpc.php">
	Require all denied
</Files>
```

Rebuild and rewrite the block whenever either toggle changes — extend
`handle_settings_change()` (`class-spfw-module-hardening.php:131`), which
currently only handles on/off transitions, to also detect a *content* change for
composed blocks.

**Settings.** `hardening.protect_sensitive_files` (bool, default `false`),
`hardening.block_xmlrpc_file` (bool, default `false`),
`hardening.root_htaccess_hash` (string).

**UI.** New card in `src/components/HardeningSettings.jsx`, alongside the
existing directory-hardening card (`:75`), reusing its "Allow Override" caveat
copy.

**Acceptance.** `/readme.html` → 403. `/wp-content/debug.log` → 403.
`/xmlrpc.php` → 403 with no PHP execution. **Permalinks still work**, and saving
permalinks in wp-admin does not trip the altered notice (this is the key
`marker_block` regression test). Toggling off removes only our block and leaves
WordPress' rewrite rules intact.

**Risk.** High. A malformed root `.htaccess` 500s the entire site including
wp-admin, locking the admin out of the toggle that caused it. **Mitigation:**
validate the composed block before writing; on the next admin page load after a
write, fire a `wp_remote_get()` self-check against the home URL and auto-remove
the block if it returns 500. This safety net is not optional.

**MainWP note.** Blocking `xmlrpc.php` at the server level does **not** affect
MainWP — the MainWP child communicates over its own signed HTTP channel, not
XML-RPC. Verify against the installed MainWP Child version before release and
state the result in the UI copy either way.

**Phase B deliverables:** `SPFW_Htaccess` refactor + migration, 3 new settings
keys, new UI card, self-check safety net, version → 1.14.0.

---

## Phase C — 1.15.0 · Headers and remaining performance toggles

### C1 · Additional security headers

**File.** `class-spfw-module-hardening.php` — `add_security_headers()` (`:168`).

**Implementation.** Add to the existing four:

```
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
X-Permitted-Cross-Domain-Policies: none
```

Do **not** add `Cross-Origin-Embedder-Policy` — it breaks third-party embeds and
has no safe default for a general-purpose WordPress site.

**Settings.** Fold into the existing `hardening.security_headers` toggle rather
than adding new ones. COOP/CORP `same-origin` is safe for a normal site; if QA
finds embed breakage, split CORP into its own sub-toggle.

### C2 · Configurable Permissions-Policy

**Why.** Currently three hardcoded features (`:176`). Given the CSP builder
already exists in `src/components/CspPolicyCard.jsx`, a directive list here is
architecturally consistent.

**Implementation.** `hardening.permissions_policy` as a `feature => allowlist`
map, sanitized with the same defensive approach as
`sanitize_csp_directives()` (`class-spfw-settings.php:465`) — whitelist feature
names, reject any token containing `;`, `,`, whitespace, or control characters.
Default to the current three plus `payment=()`, `usb=()`,
`interest-cohort=()`.

**UI.** Compact directive editor; reuse `CspPolicyCard`'s row patterns rather
than building a second editor idiom.

### C3 · Security headers in wp-admin

**Why.** `send_headers` does not fire in wp-admin, so the dashboard currently
gets no `nosniff` and no `Referrer-Policy`. (Core already sends
`X-Frame-Options` there.)

**Implementation.** Hook `admin_init` and send `X-Content-Type-Options` and
`Referrer-Policy` only. **Never** send CSP or HSTS from this path — the existing
comments at `:74` and `:81` correctly explain why the dashboard is excluded.

**Known limitation to document:** PHP-emitted headers never apply to static
assets served directly by OpenLiteSpeed. Only an `.htaccess` `Header always set`
covers those. Out of scope here; note it in the UI copy rather than silently
leaving users with a false sense of coverage.

### C4 · WP-Cron control

**Why.** No control today. On a heavily-cached LSCache site, cron firing on
uncached loads is worth managing.

**Implementation.** Same guarded-`define()` pattern already proven for
`AUTOSAVE_INTERVAL` (`class-spfw-module-core.php:149`) and `DISALLOW_FILE_EDIT`
(`class-spfw-module-hardening.php:55`) — `wp_cron()` runs on `init`, which is
after `plugins_loaded`, so defining `DISABLE_WP_CRON` at registration time wins.
Also expose `WP_CRON_LOCK_TIMEOUT`.

**Settings.** `core.disable_wp_cron` (bool, default `false`),
`core.cron_lock_timeout` (int, whitelist `0|60|120|300`).

**UI.** Must include the real-cron setup line
(`*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron`) —
enabling this without a system cron silently stops scheduled posts, backups, and
WooCommerce actions.

**Risk.** Medium, entirely from user misconfiguration. The warning copy is the
feature.

### C5 · Speculation Rules control

**Why.** WP 6.8+ injects prefetch/prerender rules by default; on a CDN-fronted
site that's extra edge and origin traffic.

**Implementation.** Filter the core speculation-rules configuration to disable
or retune (mode `prefetch|prerender`, eagerness `conservative|moderate|eager`).

**⚠ Verify before implementing:** the exact filter name and signature shipped in
the installed core version. Do not code against a remembered name — confirm in
`wp-includes/` on a live 6.8+ install, and no-op cleanly on older cores.

**Settings.** `core.speculation_rules` (enum: `default|disable|conservative`).

### C6 · Disable site search

**Why.** `?s=` requests are uncacheable by definition and are a cheap
scraping/load vector. Real win on brochure sites.

**Implementation.** On `template_redirect` (frontend, anonymous), when
`is_search()`, either 404 or redirect home per sub-option. Also unregister the
core search block/widget and remove the search form from `wp_head` discovery.

**Settings.** `core.disable_search` (bool, default `false`),
`core.search_redirect_home` (bool, default `true`) — mirroring the existing
feed-disable pattern (`class-spfw-settings.php:42`).

**Risk.** Low but high-blast-radius if enabled on a site that uses search. Copy
must be blunt.

### C7 · Image size generation control

**Why.** Disk and upload-time win LSCache doesn't cover.

**Implementation.** Filter `big_image_size_threshold` (disable the `-scaled`
duplicate or set the threshold) and `intermediate_image_sizes_advanced` to
unset selected sizes (`1536x1536`, `2048x2048`, `medium_large`).

**Settings.** `core.disable_scaled_images` (bool),
`core.disabled_image_sizes` (array, whitelisted against
`get_intermediate_image_sizes()`).

**Note.** Affects **new uploads only**. Say so in the UI — the most likely
support question is "why is my media library still full of these".

**Phase C deliverables:** ~8 new settings keys, Permissions-Policy editor,
version → 1.15.0.

---

## Phase D — 1.16.0 · Operational maturity

### D1 · CI, coding standards, and lint

**Why.** STATE.md claims WPCS compliance and lists toggles that were never
runtime-verified. The repo currently has **no** `.github/`, `composer.json`,
`phpcs.xml`, or tests — nothing enforces the claim.

**Implementation.**
- `composer.json` — `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`,
  `phpcompatibility/phpcompatibility-wp`.
- `phpcs.xml.dist` — WordPress ruleset, `testVersion 7.4-`, text-domain and
  prefix rules bound to `simple-performance-for-wordpress` / `SPFW_`.
- `.github/workflows/ci.yml` — `php -l` across all PHP files, `phpcs`,
  `npm ci && npm run build`, `eslint` on `src/`.
- Add `.github`, `composer.json`, `composer.lock`, `phpcs.xml.dist`, and this
  plan file to `.distignore`.

**Reality check.** Expect the first `phpcs` run to produce a large backlog.
Land the config with a baseline and fix incrementally rather than blocking the
phase on a clean run.

**Stretch.** Brain Monkey / WP_Mock unit tests for the pure helpers that already
have no WordPress dependency — `build_policy_from_directives()`,
`parse_policy_to_directives()`, `sanitize_csp_directives()`,
`sanitize_font_families()`, `remove_ver_query_arg()`. These are the highest-value,
lowest-effort tests in the codebase.

### D2 · Settings export / import

**Why.** With the MainWP bridge shipped
(`includes/class-spfw-mainwp-child.php`), pushing a settings profile across a
fleet is the obvious next step, and the bridge already accepts a full settings
array.

**Implementation.** Two REST routes on the existing controller
(`includes/class-spfw-rest-settings.php:29`), both behind the existing
`check_permissions()` (`:100`).

**Critical detail — strip volatile keys on export:**
`hardening.htaccess_hash`, `hardening.uploads_htaccess_hash`,
`hardening.root_htaccess_hash`, `fonts.discovered`, `fonts.last_scan`,
`fonts.needs_rescan`, `version`.

Importing another site's integrity hashes would immediately corrupt this site's
`.htaccess` status detection — it would either report `altered` for a valid file
or, worse, permit `remove()` to delete a file it did not author
(`class-spfw-htaccess.php:146`). Import must run the payload through
`SPFW_Settings::sanitize()` and re-derive hashes locally.

**UI.** Download/upload buttons in the App header near the existing save action.

### D3 · Configuration presets

**Why.** After Phases A–C there are ~55 toggles with no starting point.

**Implementation.** Three named profiles — **Balanced** (safe on any site),
**Aggressive** (classic themes, no block editor, no search), **Locked Down**
(Aggressive + all hardening, app passwords off, xmlrpc blocked). Defined as PHP
constants mapping to full settings arrays; applying one is a normal
`SPFW_Settings::update()` so all existing sanitization, LSCache purging
(`class-spfw-rest-settings.php:138`), and `.htaccess` sync
(`class-spfw-module-hardening.php:131`) fire unchanged.

**Sequencing.** Must land **after** Phases A–C so the preset definitions cover
the final toggle set — otherwise every phase re-edits them.

**UI.** Preset picker with a confirm step showing a diff of what will change.
Applying a preset overwrites deliberate customization; never do it silently.

---

## Phase E — 2.0.0 · CSP `script-src` tightening

The largest item, its own release, research-first.

**Why.** The default policy carries `'unsafe-inline'` in `script-src`
(`class-spfw-module-hardening.php:39`). That is the pragmatic WordPress choice,
but it means the script directive provides **essentially no XSS protection** —
the header is currently doing clickjacking (`frame-ancestors`), `<base>`
hijacking (`base-uri`), and plugin-object defense only. Users reasonably assume
more.

**Approach — hash sources, not nonces.** Per decision **D3**: nonces are
incompatible with full-page caching and are not implemented. Instead:

1. Reuse the font scanner's page-fetch machinery
   (`class-spfw-module-fonts.php:503`, `:427`) to crawl the same representative
   URL sample and extract every inline `<script>` body.
2. Compute `sha256` per unique inline script; store the digest set.
3. Offer a "tighten `script-src`" action that swaps `'unsafe-inline'` for the
   collected `'sha256-…'` list plus `'strict-dynamic'`.
4. Drive verification through the **existing violation collector**
   (`class-spfw-rest-settings.php:218`, `:260`) — which is exactly the right
   tool and already built. Force Report-Only until the violation log is clean.

**Hard constraints to document honestly:**

- Any inline script that varies per request (a cache-busting timestamp, a
  personalization blob) can never be hashed and will always violate.
- Adding or updating a plugin that emits new inline scripts silently breaks the
  policy — the site must be re-scanned after every plugin change. This is a
  standing maintenance cost, not a one-time setup.
- `'strict-dynamic'` changes how host allowlists are interpreted: once present,
  `https:` and host sources in `script-src` are **ignored** by supporting
  browsers. The builder UI must show this, or users will believe allowlist rows
  are active when they are not.

**Recommendation.** Gate the whole flow behind an explicit "Advanced" opt-in
with a plain-language explanation of the re-scan obligation. A CSP that silently
breaks after a routine plugin update is worse for the user than the honest
`'unsafe-inline'` default they have now.

---

## Sequencing summary

| Phase | Version | Items | Risk | Blocks |
|-------|---------|-------|------|--------|
| A | 1.13.0 | Block CSS, dashboard, app passwords, login errors, sitemap-users, font preload | Low | — |
| B | 1.14.0 | `SPFW_Htaccess` refactor + migration, FilesMatch fix, root target | **High** | B0 gates B1/B2 |
| C | 1.15.0 | Headers, Permissions-Policy, admin headers, wp-cron, speculation, search, image sizes | Medium | — |
| D | 1.16.0 | CI/phpcs, export/import, presets | Low | D3 needs A–C |
| E | 2.0.0 | CSP `script-src` hashes | **High** | — |

**Per-phase checklist (D4):** settings defaults → sanitizer → React UI →
regenerate `.pot` → `readme.txt` changelog → bump version in all three places →
update STATE.md Progress table, Next action, and Decisions log per the update
protocol at `STATE.md:1197` → commit STATE.md in the same commit as the code.

---

## Open questions

1. **Speculation Rules filter name** (C5) — must be confirmed against a live WP
   6.8+ install, not from memory.
2. **`(?i:…)` in `FilesMatch`** (B1) — confirm the target OpenLiteSpeed build's
   PCRE support before shipping.
3. **MainWP + `xmlrpc.php` block** (B2) — confirm the installed MainWP Child
   version does not fall back to XML-RPC under any condition.
4. **Phase A default states** — A1 (block CSS) is proposed default-off given its
   breakage potential. Confirm that matches the intended product posture, since
   most other Core toggles that are safe ship default-on.
5. **No live WordPress instance** — STATE.md's Next action already records a
   backlog of toggles never runtime-verified (`disable_wp_sitemaps`,
   `remove_robots_max_image_preview`, CSP reporting behind QUIC.cloud). That
   backlog grows with every phase here. Standing up a staging site is arguably a
   higher priority than any single item in this plan.
