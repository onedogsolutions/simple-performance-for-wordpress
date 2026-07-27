# Implementation Plan — Fix localized fonts blocked by CORS after a domain change

**Branch:** `claude/cors-font-loader-errors-01cd2j` · **Version target:** 1.13.0
**Status:** ✅ Implemented — see STATE.md's decisions log for the two deviations
(the migration recomputes `discovered['hash']` as a one-time cache bust; the
CORS file is written from `write_css_file()` rather than `ensure_fonts_dir()`).
Live QA per §5.2 still outstanding.
**Author date:** 2026-07-27

---

## 1. Problem statement

On `https://staging.laseraesthetics.org/course-registration/` every localized
font fails to load. The console shows a matched pair of errors per font file:

```
Access to font at 'https://laseraesthetics.org/wp-content/uploads/ods-fonts/c97….woff2'
  from origin 'https://staging.laseraesthetics.org' has been blocked by CORS policy:
  No 'Access-Control-Allow-Origin' header is present on the requested resource.

GET https://laseraesthetics.org/wp-content/uploads/ods-fonts/c97….woff2
  net::ERR_FAILED 200 (OK)
```

The reported symptom is that "there are no indicators that the font loading
should be blocked" — and that reading is correct. Nothing in the plugin's own
configuration is blocking these requests:

- **CSP is not the cause.** The generated policy is
  `font-src 'self' data: https:` — `https:` permits a font from *any* HTTPS
  origin, including `laseraesthetics.org`. A CSP block would also have produced
  a `Refused to load the font…` message and a violation report; instead the
  message is a CORS message, and the violation log is empty.
- **LiteSpeed is not the cause.** Remove Query Strings, Load Google Fonts
  Asynchronously, and Remove Google Fonts are all **OFF**.
- **The font files exist and are being served.** Note the `200 (OK)` on the
  `ERR_FAILED` line — the origin returned the file successfully. The browser
  fetched it and then *discarded* it.

That last detail is the whole bug. The request is **cross-origin**: the page is
on `staging.laseraesthetics.org`, the font is on `laseraesthetics.org`. Fonts
requested from CSS are always fetched in CORS mode (`crossorigin=anonymous`) —
this is not configurable from CSS and is not affected by CSP. A cross-origin
font response without an `Access-Control-Allow-Origin` header is fetched
successfully and then thrown away by the browser, which is exactly the
`ERR_FAILED 200 (OK)` signature above.

So there are two independent defects, and both need fixing:

1. **The plugin is generating cross-origin font URLs on a site that should be
   serving them same-origin.** (Root cause of this report.)
2. **The plugin has no CORS story for the case where fonts legitimately *are*
   cross-origin** (uploads offloaded to a CDN/asset host). (Latent defect.)

## 2. Root cause

### 2.1 Absolute font URLs are frozen into stored CSS at scan time

`SPFW_Module_Fonts::scan()` bakes a fully-qualified URL into every rewritten
`@font-face` block (`includes/modules/class-spfw-module-fonts.php:289-290`):

```php
$local_url  = $this->fonts_url() . '/' . $filename;   // https://laseraesthetics.org/wp-content/uploads/ods-fonts
$rewritten .= str_replace( $src_url, $local_url, $face['block'] ) . "\n";
```

That string is persisted verbatim as `fonts.discovered.css` in the
`spfw_settings` option (`:300-305`) and written to
`uploads/ods-fonts/fonts.css` (`:307`).

The staging site was cloned from production, so `spfw_settings` — and the
already-generated `fonts.css` in `uploads/` — arrived carrying
`https://laseraesthetics.org/…` URLs. Nothing in the plugin ever re-resolves
them against the site's *current* URL. The stylesheet `<link>` itself is built
live from `fonts_url()` and therefore correctly points at staging; only the
`src: url()` values inside it are stale. That is why the CSS loads fine and
every font inside it fails.

### 2.2 The generated CSS file is never refreshed once it exists

`serve_local_fonts()` (`:380-395`) only writes `fonts.css` when the file is
**absent**:

```php
if ( ! file_exists( $css_path ) && ! $this->write_css_file( $fonts['discovered']['css'] ) ) {
    return;
}
```

On the cloned site the file exists, so it is served untouched forever. Even
after fixing §2.1 in the stored option, the on-disk artifact would keep serving
production URLs until someone clicked "Scan fonts now". There is also no
integrity check tying the file's contents to `discovered['hash']`, which is what
the enqueue is versioned by — so the version string can claim a state the file
does not actually have.

### 2.3 No `Access-Control-Allow-Origin` is ever emitted for font files

The uploads `.htaccess` the plugin writes (`SPFW_Htaccess::payload()`) contains
deny-PHP rules only. `add_security_headers()` runs on `send_headers`, which
never fires for a static `.woff2` — those are served by the web server, not
PHP. So there is no code path in this plugin that could ever add ACAO to a font
response. Any deployment where uploads are on a different host than the page
(CDN offload, `upload_url_path` override, asset subdomain) fails the same way,
permanently.

### 2.4 Contributing factor: stale LiteSpeed CSS bundle

The console attributes the font URLs to `6795fcd…81e.css?ver=a6a8c…` — a
LiteSpeed *combined* CSS bundle, not `fonts.css` directly. Whatever fixes the
source CSS must also invalidate that bundle, or the browser keeps reading the
old URLs out of the combined file. `finish_scan()` already fires
`litespeed_purge_all`, but the self-healing path added in §3.2 must do the same.

## 3. The fix

### 3.1 Store font CSS in a portable, domain-agnostic form

**File:** `includes/modules/class-spfw-module-fonts.php`

Introduce a placeholder token so the stored CSS never contains a hostname:

```php
const FONTS_URL_TOKEN = '%%SPFW_FONTS_URL%%';
```

- In `scan()` (`:289`), build `$local_url` from the token rather than
  `fonts_url()`. `discovered['css']` and `discovered['hash']` then describe the
  *font set*, independent of which domain the site is on — the same scan result
  is valid on production, staging, and local.
- Add `portable_css( $css )`: rewrites any absolute `…/ods-fonts/` URL back to
  the token, via
  `preg_replace( '#https?://[^\s"\')]+/ods-fonts/#i', self::FONTS_URL_TOKEN . '/', $css )`.
  This is what lets already-stored CSS be healed without a re-scan.
- Add `render_css( $css )`: expands the token for output (see §3.3 for what it
  expands *to*).
- Make `write_css_file()` the single choke point:
  `$css = $this->render_css( $this->portable_css( $css ) );` — so every caller,
  including the legacy-data path, writes correct URLs by construction.

### 3.2 Self-heal the on-disk `fonts.css` instead of writing it once

**File:** `includes/modules/class-spfw-module-fonts.php`, `serve_local_fonts()`

Replace the `! file_exists()` guard with a freshness check. To keep this off the
per-request hot path, record what the file was last rendered against and compare
strings — no `sha1_file()` on every page load:

- Persist `discovered['rendered_for']` = the base URL/path the on-disk file was
  rendered with, set whenever `write_css_file()` succeeds.
- On `wp_enqueue_scripts`, rewrite only when
  `! file_exists( $css_path ) || $rendered_for !== $this->rendered_base()`.
- Guard the rewrite with a short transient lock (5 min) so a filesystem failure
  cannot cause a write attempt on every request.
- When a rewrite actually happens, fire `do_action( 'litespeed_purge_all' )`
  once so the stale combined bundle from §2.4 is regenerated.

Net effect: the moment the cloned staging site serves a front-end request, it
notices the file was rendered for a different base, regenerates it, and purges
the cache — with no admin action at all.

### 3.3 Prefer root-relative URLs when uploads are same-host

`render_css()` should not simply substitute the absolute `fonts_url()`. Compare
the uploads host to the site host:

- **Same host** (the normal case): emit a **root-relative** path —
  `/wp-content/uploads/ods-fonts/c97….woff2`. This is permanently immune to
  domain changes, `http`→`https`, and `www`/non-`www` differences, so the class
  of bug in this report cannot recur. It is also correct under subdirectory
  installs, because the path comes from `wp_upload_dir()['baseurl']`, and it
  survives LiteSpeed relocating the combined bundle (root-relative resolves
  against the origin, not the stylesheet's directory).
- **Different host** (uploads offloaded to a CDN or `upload_url_path`
  overridden): emit the absolute URL, because root-relative would resolve to
  the wrong host. This case is genuinely cross-origin and depends on §3.4.

`rendered_base()` returns whichever form is in effect, and is what
`rendered_for` is compared against.

### 3.4 Emit CORS headers for the font directory

**File:** `includes/modules/class-spfw-module-fonts.php`

Write a dedicated `.htaccess` into `uploads/ods-fonts/` from
`ensure_fonts_dir()`:

```apache
# BEGIN Simple Performance for WordPress
<IfModule mod_headers.c>
	<FilesMatch "\.(woff2?|ttf|otf|eot)$">
		Header set Access-Control-Allow-Origin "*"
		Header append Vary Origin
	</FilesMatch>
</IfModule>
# END Simple Performance for WordPress
```

- `*` is the correct value here: these are public static assets with no
  credentials or per-user variation.
- The `<IfModule>` wrapper matters — bare `Header` directives 500 an Apache
  vhost without `mod_headers`. This mirrors the existing caution in
  `SPFW_Htaccess::payload()` about `Options -Indexes`.
- This is a **separate file** owned by the fonts module, deliberately *not* a
  change to `SPFW_Htaccess::payload()`. Editing the shared payload would change
  its sha1 and flip every existing install's hardening status to `altered`,
  firing a false "file has been modified" admin notice.
- It coexists with the uploads-level deny-PHP `.htaccess`; the two rule sets do
  not overlap.

### 3.5 Migrate existing installs

**File:** `includes/class-spfw-settings.php`

Add a `1.13.0` migration alongside the existing ones (`get()`, `:129-160`),
following the `run_font_rescan_migration()` pattern:

```php
if ( version_compare( $stored_ver, '1.13.0', '<' )
    && ! empty( $stored['fonts']['discovered']['css'] ) ) {
    self::run_font_portability_migration( $stored );
    …
}
```

The migration tokenizes the stored `discovered['css']` (via the same regex as
`portable_css()`) and clears `discovered['rendered_for']`, which makes the next
front-end request regenerate `fonts.css` through §3.2. Existing sites are fixed
on upgrade without a re-scan, and without the admin needing to know any of this
happened.

### 3.6 Surface the diagnosis in the admin UI

The reason this took a round trip to diagnose is that the Fonts tab shows *what*
was discovered but nothing about *where it is served from*. Add that.

**`includes/class-spfw-rest-settings.php`** — expose a computed, read-only
`fonts_runtime` block on the settings response: `{ base_url, site_host,
uploads_host, css_file_exists, cors_file_exists, rendered_for }`. It is
safe to add: `SPFW_Settings::sanitize()` rebuilds `$clean` from an explicit key
list (`:393-404`), so an unknown inbound key can never be persisted.

**`src/components/FontsSettings.jsx`** —

- Add a "Serving fonts from: `…`" line under the discovery summary. If
  `upload_url_path` is still pointing at production on a cloned site, this makes
  it visible immediately.
- Add a warning banner when `uploads_host !== site_host`, stating plainly that
  fonts will be requested cross-origin and therefore need
  `Access-Control-Allow-Origin` at the CDN/asset host — reusing the amber banner
  styling already used for `needs_rescan` (`:64-79`).

**`src/components/CspPolicyCard.jsx`** — extend the existing CDN hint
(`:627`), which already teaches that `ERR_BLOCKED_BY_ORB` is not a CSP
violation, to cover this case too: a CORS-blocked font shows as
`ERR_FAILED 200 (OK)` plus an `Access to font at … blocked by CORS policy`
message, is not a CSP violation, and will not appear in the violation log.

### 3.7 Version bump

1.13.0 rather than a patch: the change adds a settings key
(`discovered['rendered_for']`), a new on-disk artifact (the font-directory
`.htaccess`), a REST response block, and a migration. Update the plugin header,
`SPFW_VERSION`, `readme.txt` stable tag, and the `readme.txt` changelog.

## 4. Files touched

| File | Change |
|---|---|
| `includes/modules/class-spfw-module-fonts.php` | Token constant; `portable_css()`/`render_css()`/`rendered_base()`; tokenized `scan()` output; self-healing `serve_local_fonts()`; CORS `.htaccess` writer |
| `includes/class-spfw-settings.php` | 1.13.0 portability migration |
| `includes/class-spfw-rest-settings.php` | Read-only `fonts_runtime` diagnostic block |
| `src/components/FontsSettings.jsx` | "Serving from" line; cross-origin warning banner |
| `src/components/CspPolicyCard.jsx` | Extend the "not a CSP violation" hint to CORS font failures |
| `simple-performance-for-wordpress.php`, `readme.txt` | 1.13.0 bump + changelog |
| `STATE.md` | Progress, overall status, decisions log |
| `.distignore` | Exclude `FONT_CORS_FIX_PLAN.md` from the release ZIP |

## 5. Verification

### 5.1 Static / harness

No WordPress instance is available in the build environment, so these run as
standalone PHP against extracted functions:

1. `portable_css()` rewrites `https://prod.example/wp-content/uploads/ods-fonts/a.woff2`
   → `%%SPFW_FONTS_URL%%/a.woff2`, and is idempotent when run on already-tokenized CSS.
2. `render_css()` round-trips: `portable_css()` then `render_css()` on a
   same-host install yields `/wp-content/uploads/ods-fonts/a.woff2`.
3. Cross-host uploads (`uploads_host !== site_host`) yield the absolute URL, not
   a root-relative path.
4. The migration leaves `discovered['hash']` describing the same font set before
   and after (the hash is over tokenized CSS, so it must not change on a
   domain move).
5. The regex does not corrupt a `@font-face` block whose `local()` name or
   `unicode-range` happens to contain the substring `ods-fonts`.

### 5.2 Live QA (requires the staging site)

The environment's network policy blocked outbound requests to
`staging.laseraesthetics.org` from this session, so the root cause above is
derived from the code paths plus the console evidence, not from a live fetch.
Confirm on the site:

1. **Before the fix**, `curl -sI https://laseraesthetics.org/wp-content/uploads/ods-fonts/c97….woff2`
   returns `200` with **no** `access-control-allow-origin` header — this
   confirms §2.3 directly.
2. **Before the fix**, `curl -s https://staging.laseraesthetics.org/wp-content/uploads/ods-fonts/fonts.css | grep -o 'url([^)]*)' | head`
   shows `laseraesthetics.org` URLs on a staging page — this confirms §2.1
   directly. Run both before deploying, so the fix is measured against a
   confirmed baseline.
3. After deploying 1.13.0, load any staging front-end page once (this triggers
   the §3.2 self-heal), then re-run the `fonts.css` check: URLs must now be
   root-relative `/wp-content/uploads/ods-fonts/…`.
4. Reload `/course-registration/` — zero CORS errors, zero `ERR_FAILED`, and the
   Network tab shows the `.woff2` files served from `staging.laseraesthetics.org`.
5. Purge LiteSpeed and confirm the regenerated combined CSS bundle carries the
   new URLs (guards against §2.4).
6. Confirm the same page on **production** is unaffected — same-origin
   root-relative URLs must resolve identically there.
7. Confirm `uploads/ods-fonts/.htaccess` exists and that the site does not 500
   (i.e. the `<IfModule>` guard held on OpenLiteSpeed).
8. Fonts tab: "Serving fonts from" shows the staging path; no cross-origin
   warning banner on a same-host install.

## 6. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Root-relative URLs break an uploads-on-CDN install | Host comparison in `render_css()` keeps absolute URLs for that case (§3.3) |
| `Header` directives 500 an OLS vhost without `mod_headers` | `<IfModule mod_headers.c>` wrapper (§3.4) |
| Changing the shared htaccess payload flags every install as `altered` | Separate font-directory file; `SPFW_Htaccess::payload()` untouched (§3.4) |
| Front-end option write during self-heal | Fires once per base change, transient-locked against retry storms (§3.2) |
| Migration corrupts working CSS on a healthy install | Tokenize-then-render is a round trip to the identical string when the site has not moved; hash unchanged (§5.1 tests 2 and 4) |
| `sha1_file()` on every request | Avoided — string compare against `rendered_for` (§3.2) |

## 7. Out of scope

- QUIC.cloud-generated critical CSS (CCSS/UCSS) built while the site was on the
  production domain may hold its own copy of the old URLs. That is edge-cached
  state outside this plugin; a QUIC.cloud purge clears it. Worth checking during
  QA step 5 if any font URL still points at production after the fix.
- A general-purpose CORS header UI for arbitrary asset types. The font directory
  is the only place this plugin owns.
