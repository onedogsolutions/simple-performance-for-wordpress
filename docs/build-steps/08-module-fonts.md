# Step 8 — Module 4: Google Fonts Localizer & Discovery

**Goal:** Discover Google Fonts referenced by the site, download the `.woff2` files
locally, rewrite the frontend to serve them from `/uploads/ods-fonts/`, and drop the
external Google requests. Two phases: **discover** (admin AJAX, cached) and **serve**
(frontend rewrite). LSCache-aware cache busting.

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS.
- Depends on Steps 2, 3, 5. Settings under `fonts`: `localize_google` (bool),
  `discovered` (array cache), `last_scan` (int ts).
- Local dir: `wp_upload_dir()['basedir'] . '/ods-fonts/'`,
  URL `wp_upload_dir()['baseurl'] . '/ods-fonts/'`.
- Never break rendering: if no local CSS exists yet, leave the original Google
  enqueue untouched (graceful fallback).

## Deliverables
1. `includes/modules/class-spfw-module-fonts.php` →
   `class SPFW_Module_Fonts implements SPFW_Module`.
2. `src/components/FontsSettings.jsx` (React tab component, calling a REST route
   for the scan — the admin UI is React/Tailwind per Step 5, not PHP form views
   or `admin-ajax.php`).
3. A `spfw/v1/settings/scan-fonts` `POST` route on `SPFW_Rest_Settings` (Step 5),
   cap `manage_options`, following the same pattern as Step 7's
   `restore-htaccess` route: runs the discovery/download logic below, persists
   `discovered`/`last_scan` via `SPFW_Settings::update()`, and returns the
   refreshed settings so the component can update from the response with no
   page reload.

### `discovered` structure (persisted)
```php
'discovered' => [
  'css'        => "/* generated @font-face with local src urls */",
  'families'   => [ 'Inter:400,700', 'Roboto:400' ],  // human-readable list
  'files'      => [ 'inter-400.woff2', ... ],          // basenames in ods-fonts/
  'hash'       => 'sha1-of-css',                        // for cache-busting the enqueue
],
```

### Phase 1 — Discovery (admin, on demand)
- Triggered by the `spfw/v1/settings/scan-fonts` REST route (cap
  `manage_options`, registered on `SPFW_Rest_Settings` per the Deliverables
  above — not `admin-ajax.php`).
- **Find Google references:**
  1. Fetch the homepage: `wp_remote_get( home_url('/'), [timeout=>15] )`.
  2. Regex-scan the HTML for `href`/`@import`/`url()` matching
     `fonts.googleapis.com/css` (v1 and `css2`). Collect the full Google CSS URLs.
     (Also capture inline `<style>` and enqueued `<link>`s.)
- **Fetch & parse each Google CSS URL** with a browser-like `User-Agent` header
  (Google returns `.woff2` only when the UA supports it — send a modern Chrome UA):
  - `wp_remote_get($cssUrl, ['user-agent' => '<modern chrome UA>'])`.
  - Parse `@font-face` blocks; extract each `src: url(https://fonts.gstatic.com/...
    .woff2)`.
- **Download** each unique `.woff2` via `wp_remote_get` to `ods-fonts/` using
  **`WP_Filesystem`**; name deterministically (family-weight-style or a hash).
- **Rewrite** the parsed CSS: replace each gstatic `src url()` with the local
  `uploads/ods-fonts/<file>` URL. Concatenate all `@font-face` blocks into one CSS
  string. Compute `sha1` → `hash`.
- Persist the whole `discovered` array + `last_scan = time()` via
  `SPFW_Settings::update()`. The REST route returns the refreshed
  `SPFW_Settings::get()` (families found, files saved, timestamp are all visible
  to the component from `settings.fonts.discovered`/`last_scan`).
- Use a short-lived transient only as an in-progress working buffer if needed
  (optional); the durable cache is the option.

### Phase 2 — Serve (frontend rewrite)
Only when `localize_google` is on AND `discovered['css']` is non-empty.
- On `wp_enqueue_scripts` (priority ~99):
  - **Dequeue Google:** iterate `wp_styles()->registered`; for any handle whose
    `src` contains `fonts.googleapis.com`, `wp_dequeue_style`/`wp_deregister_style`
    it. (Themes often enqueue under handles like `<theme>-fonts`,
    `google-fonts`, etc. — match by src, not by guessed handle.)
  - **Drop preconnect/dns-prefetch:** `add_filter('wp_resource_hints', remove
    fonts.googleapis.com + fonts.gstatic.com entries, 10, 2)`.
- **Inject local CSS:** either
  - write the generated CSS to `ods-fonts/fonts.css` and
    `wp_enqueue_style('spfw-fonts', <url>, [], discovered['hash'])`, **or**
  - print it inline in `wp_head` (priority high) via `wp_add_inline_style` on a tiny
    registered handle. Prefer the enqueued file (cacheable by OLS/LSCache); version
    by `hash` so re-scans bust caches.
- **Fallback:** if the local file/CSS is missing, do nothing (leave Google enqueue).

### `FontsSettings.jsx`
Props: `{ settings, onChange, onScan }` (same `onChange` contract as the other
tab components; `onScan` POSTs to `/spfw/v1/settings/scan-fonts` and updates
local state from the response).
- Toggle `settings.fonts.localize_google`.
- "Scan fonts now" button → `onScan()` → local `isScanning` state shows a
  spinner, then a summary (families + file count + last-scan time) read from
  `settings.fonts.discovered`/`last_scan`. Warn that scanning fetches the
  homepage and Google CSS.
- List currently localized families (`settings.fonts.discovered.families`); a
  "Re-scan" control (same button, re-runs `onScan()`).

### LSCache / OLS notes (put in help text + code comments)
- After a successful scan that changes `hash`, trigger
  `do_action('litespeed_purge_all')` so cached pages pick up the rewrite.
- `.woff2` in `/uploads/` are static and hard-cached by OLS; the `hash` in the
  stylesheet version query invalidates on re-scan without manual purge.

## Design constraints
- All network fetches via `wp_remote_get` (respects proxies/timeouts); handle
  `is_wp_error` and non-200 gracefully (never fatal, never partial-write a broken
  CSS).
- All file writes via `WP_Filesystem`; create `ods-fonts/` if absent; add an
  `index.html`/empty guard to prevent listing (optional).
- Match Google styles by **URL substring**, not handle name.
- Only run Phase 2 hooks when enabled + cache present (zero overhead otherwise).

## Acceptance criteria
- On a site enqueuing Google Fonts, "Scan" downloads `.woff2` into
  `uploads/ods-fonts/` and stores rewritten CSS.
- With `localize_google` on, the rendered frontend has **no** requests to
  `fonts.googleapis.com`/`fonts.gstatic.com`; fonts still render from local URLs.
- Re-scan changes the stylesheet version (hash) and (if LSCache present) purges.
- Disabling the toggle restores the original Google enqueue (no local injection).
- `php -l` clean, WPCS clean.

## Final step (required)
Before ending the session, update `../../STATE.md` per its "Update protocol":
flip this step's row to ✅ (or 🟡 if paused), set the commit hash, refresh Overall
status / Last updated / Next action, and log any deviations. Commit STATE.md **in
the same commit** as this step's code, then push to the branch.
