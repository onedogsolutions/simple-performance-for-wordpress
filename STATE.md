# Build State — Simple Performance for WordPress

**Single source of truth for build progress AND the step-by-step implementation
plan.** Every build session MUST update this file as its final action (see "Update
protocol" below). Read this first before starting any step. Full-detail specs (with
exact hook lists, pseudo-code, and design rationale) live in `docs/build-steps/`;
this file inlines the condensed version of each so progress and plan travel
together in one top-level document.

- **Branch:** `claude/simple-performance-wordpress-plugin-6qbso2` (Step 10 on
  `claude/feature-parity-quick-toggles-sf64kt`)
- **Plugin version target:** 1.1.0
- **Last updated:** 2026-07-11
- **Overall status:** ✅ Phase 1 complete (9/9); ✅ Step 10 (Perfmatters
  quick-toggle parity + WooCommerce tab) implemented

## Shared project facts (true for every step)

- **Plugin name:** Simple Performance for WordPress
- **Text domain / slug:** `simple-performance-for-wordpress`
- **Prefix:** `spfw_` (functions/options), `SPFW_` (constants/classes)
- **Author:** Ryan Waterbury — One Dog Solutions (https://onedog.solutions/)
- **License:** GPL-3.0-or-later · **Min WP:** 6.0 · **Min PHP:** 7.4
- **Target stack:** OpenLiteSpeed + LiteSpeed Cache
- **Single option key:** `spfw_settings` (autoloaded, one serialized array — the
  only DB footprint; schema in Step 2 below)
- **Standards:** WordPress Coding Standards; escape on output, sanitize on input,
  nonce + `manage_options` capability check on every write, `ABSPATH` guard at the
  top of every PHP file, no direct DB access, no new tables/post-meta/transients
  (except the optional font-scan working cache in Step 8)
- **Admin UI (as of Step 5):** a single React app (`@wordpress/element` — no
  jQuery, no separate React dependency) built with `@wordpress/scripts` +
  Tailwind v4, matching the sister plugin
  `onedogsolutions/google-security-for-wordpress`'s architecture exactly. One
  REST endpoint (`spfw/v1/settings`) is the only persistence path — no PHP form
  views, no `admin-post.php` handler. `build/` is gitignored, produced by
  `npm run build`; `package-lock.json` **is** committed (pins transitive
  dependency versions — see the Step 5 deviation entry below for why this
  matters).

## Progress

| Step | Deliverable | Status | Commit |
|------|-------------|--------|--------|
| — | Architecture blueprint (`IMPLEMENTATION_PLAN.md`) | ✅ Done | 5f938f7 |
| — | Per-step build specs (`docs/build-steps/`) | ✅ Done | 5f938f7 |
| 1 | Bootstrap file | ✅ Done | 96d41e3 |
| 2 | Settings layer (`SPFW_Settings`) | ✅ Done | 1859f2f |
| 3 | Core loader + module interface | ✅ Done | 169c712 |
| 4 | Module 1 — core toggles | ✅ Done | 793acd0 |
| 5 | Admin skeleton (React + Tailwind v4 + REST) | ✅ Done | 26fefd7 |
| 6 | Module 2 — REST API controls | ✅ Done | 22f3b40 |
| 7 | Module 3 — directory hardening | ✅ Done | 2326c84 |
| 8 | Module 4 — Google Fonts localizer | ✅ Done | a294f3b |
| 9 | Uninstall cleanup | ✅ Done | 92afbf5 |
| 10 | Perfmatters quick-toggle parity + WooCommerce tab + card UI | ✅ Done | (this commit) |

Status legend: ⬜ Not started · 🟡 In progress · ✅ Done · ⚠️ Blocked

## Next action

**Step 10 is implemented** (see `docs/build-steps/10-feature-parity-quick-toggles-and-woocommerce.md`
for the full spec and "what shipped"). Remaining before a 1.1.0 release:
regenerate `languages/simple-performance-for-wordpress.pot` (new strings not yet
extracted), and manual QA on a live WordPress + OpenLiteSpeed + WooCommerce
install. Phase 1 (Steps 1–9) remains complete.

---

## Implementation steps

### Step 1 — Bootstrap file ✅
`simple-performance-for-wordpress.php`: plugin header (name/version/author/
license/text-domain, min WP 6.0 / PHP 7.4), `ABSPATH` guard, constants
`SPFW_VERSION`/`SPFW_FILE`/`SPFW_PATH`/`SPFW_URL`/`SPFW_BASENAME`, text-domain load
on `init`, require + boot `SPFW_Plugin` on `plugins_loaded`, activation/
deactivation hooks wired to `SPFW_Plugin::activate()`/`deactivate()`. Never deletes
options on deactivation (that's uninstall's job).

### Step 2 — Settings layer ✅
`includes/class-spfw-settings.php` → `SPFW_Settings`. Owns the single
`spfw_settings` option (schema below). `get()` is statically cached (1
`get_option()` per request, ever). `update()` deep-merges a partial/full array
against **current stored settings** (not just defaults), sanitizes, persists, and
invalidates the cache. `sanitize()` casts booleans strictly, clamps
`heartbeat_interval` to 15–300, whitelists `heartbeat_mode`, and filters route-list
entries to `[A-Za-z0-9/_.-]`.

**Canonical schema:**
```php
[
  'version' => SPFW_VERSION,
  'core' => [
    'disable_emojis' => true, 'disable_embeds' => true, 'disable_dashicons' => true,
    'disable_xmlrpc' => true, 'remove_rsd' => true, 'remove_wlwmanifest' => true,
    'disable_feeds' => false, 'feed_redirect_home' => true,
    'remove_query_strings' => false,
    'heartbeat_mode' => 'modify', // default|modify|disable
    'heartbeat_interval' => 60,   // 15..300
    'disable_jquery_migrate' => true,
  ],
  'restapi' => [
    'require_auth' => false,
    'disabled_namespaces' => ['wp/v2/users', 'wp/v2/themes'],
    'whitelist_routes' => ['contact-form-7/v1', 'wc/v3', 'wc/store'],
  ],
  'hardening' => ['plugins_htaccess' => false, 'htaccess_hash' => ''],
  'fonts' => ['localize_google' => false, 'discovered' => [], 'last_scan' => 0],
]
```

### Step 3 — Core loader + module interface ✅
`includes/interface-spfw-module.php` → `SPFW_Module` (single method `register()`).
`includes/class-spfw-plugin.php` → `SPFW_Plugin` singleton: `boot()` requires
Settings + the interface, walks an explicit `MODULES` class⇒file map (each
`file_exists`-guarded so partial builds never fatal), instantiates + registers each
present module, and loads `admin/class-spfw-admin.php` only when `is_admin()`.
`activate()` seeds default settings if the option is absent (idempotent);
`deactivate()` is a no-op until Step 7 adds `.htaccess` teardown.

### Step 4 — Module 1: core performance toggles ✅
`includes/modules/class-spfw-module-core.php` → `SPFW_Module_Core`. `register()`
reads `SPFW_Settings::group('core')` and attaches, per toggle, only when true:
- **Emojis:** strip `print_emoji_detection_script`/`print_emoji_styles` (wp_head,
  admin), staticize-emoji filters, `wpemoji` TinyMCE plugin, s.w.org dns-prefetch.
- **Embeds:** remove oEmbed discovery/host-js/register-route hooks, deregister
  `wp-embed` script on `wp_footer`.
- **Dashicons:** deregister for logged-out visitors on `wp_enqueue_scripts` (100).
- **XML-RPC:** `xmlrpc_enabled` → false, strip pingback methods + `X-Pingback`
  header.
- **RSD / WLWManifest:** remove their `wp_head` actions.
- **Feeds:** remove feed-link head tags; redirect (301 home) or `wp_die` every
  `do_feed*` hook depending on `feed_redirect_home`.
- **Query strings:** strip `ver` from `script_loader_src`/`style_loader_src`
  (frontend only).
- **Heartbeat:** `modify` → filter `heartbeat_settings` interval (clamped
  15–300); `disable` → deregister the `heartbeat` script on `init`.
- **jQuery Migrate:** strip from `jquery`'s deps via `wp_default_scripts`
  (frontend only).
Verified via stubbed harness: defaults register exactly the expected hook set,
all-off attaches zero hooks, and every pure-logic helper (query-arg stripping,
pingback filtering, emoji dns-prefetch filtering, heartbeat interval override,
jQuery Migrate dep removal) behaves correctly in isolation.

### Step 5 — Admin skeleton (React + Tailwind v4 + REST) ✅
**Architecture pivot (2026-07-10):** the admin UI is a single React app —
`@wordpress/element` (vanilla JS/React, **no jQuery**), built with
`@wordpress/scripts` + Tailwind v4, matching the sister plugin
`onedogsolutions/google-security-for-wordpress` exactly (verified by cloning it:
`package.json`/`webpack.config.js`/`postcss.config.js` shape, `src/index.js`
mounting into a root div, `src/styles/index.css` with `@import "tailwindcss"`
scoped under an isolation class, tab components reading/writing state via
`@wordpress/api-fetch` against one REST endpoint, Tailwind conventions —
indigo-600 primary buttons, gray-900/500 text scale, toast notifications, `role`
tablist/tabpanel with arrow-key nav). **No PHP form views, no `admin-post.php`
save handler, no plain admin.js/admin.css** — those are superseded by this.
- **Build tooling** (repo root): `package.json` (`@wordpress/scripts`,
  `tailwindcss`^4, `@tailwindcss/postcss`^4, `postcss`, `autoprefixer`),
  `webpack.config.js` (extends `@wordpress/scripts` default), `postcss.config.js`
  (`@tailwindcss/postcss` + `autoprefixer`). `build/` gitignored — produced by
  `npm run build`, never committed (same as the sister plugin).
- `src/index.js` → mounts `<App />` into `#spfw-admin-root`.
- `src/components/App.jsx` → bootstraps from `window.spfwAdminData`
  (`{restUrl, nonce, settings}`), refreshes via `apiFetch({path:'/spfw/v1/settings'})`
  on mount, holds `settings`/`isSaving`/`toast` state, `handleChange(group,key,val)`,
  `handleSave` POSTs the full settings object and shows a toast. Tabs:
  `core` (ships now via `CoreSettings.jsx`), `restapi`/`hardening`/`fonts`
  (placeholder body until Steps 6–8 add their components).
- `src/components/SettingsTabs.jsx` → generic tablist/tabpanel, keyboard nav.
- `src/components/CoreSettings.jsx` → every Step 4 `core` setting; props
  `{settings, onChange}`.
- `includes/class-spfw-rest-settings.php` → `SPFW_Rest_Settings`. Registers
  `spfw/v1/settings` (`GET`→`SPFW_Settings::get()`, `POST`→
  `SPFW_Settings::update($request->get_json_params())`, both capped
  `manage_options`). **Loads unconditionally** (required + instantiated directly
  in `SPFW_Plugin::boot()`, not admin-gated, not in the toggleable `MODULES`
  list) — REST requests aren't admin context, so `rest_api_init` must fire on
  every request. `spfw/v1` is treated as always-whitelisted by Module 2 (Step 6)
  so the settings screen can never lock itself out.
- `admin/class-spfw-admin.php` → `SPFW_Admin`, loaded only in `is_admin()`.
  `add_options_page` under Settings → "Simple Performance" (slug
  `spfw-settings`, cap `manage_options`). `render_page()` outputs only the root
  div (`<div id="spfw-admin-root" class="spfw-admin-isolated">`) — no PHP form
  markup. `admin_enqueue_scripts` bails unless
  `$hook === 'settings_page_spfw-settings'`; reads `build/index.asset.php` for
  deps/version (falls back to `['wp-element','wp-api-fetch','wp-i18n']` +
  `SPFW_VERSION` if not yet built); enqueues `build/index.js`/`build/index.css`;
  `wp_localize_script`s `spfwAdminData` with `restUrl`, a `wp_rest` nonce, and
  the current `SPFW_Settings::get()` snapshot.

### Step 6 — Module 2: REST API controls ✅
`includes/modules/class-spfw-module-restapi.php` → `SPFW_Module_RestApi`. Reads
`SPFW_Settings::group('restapi')`.
- **A. Unregister disabled namespaces** via `rest_endpoints` filter (only if
  `disabled_namespaces` non-empty): drop any route matching a disabled prefix
  **unless** it's in `whitelist_routes` — removes it from the `/wp-json/` index
  entirely (strongest anti-enumeration measure).
- **B. `rest_authentication_errors` gate** (only if `require_auth` OR
  `disabled_namespaces` non-empty): whitelist routes always pass; else if
  `require_auth` and anonymous → `401`; else if route matches a disabled namespace
  and user lacks `manage_options` → **404** (`rest_no_route` — no signal the route
  exists, not 403).
- Helper `route_in_list($route, $list)`: prefix match, `"$item"` or `"$item/"`,
  **plus `spfw/v1` hardcoded always-whitelisted** (never let the plugin lock out
  its own settings API).
- `src/components/RestApiSettings.jsx` (React tab, props `{settings,onChange}`):
  require-auth toggle; disabled-namespaces from a live `/` index fetch
  (`apiFetch({path:'/'})`) as checkboxes + advanced textarea; whitelist textarea
  pre-seeded with CF7/WooCommerce placeholder examples.
- Zero overhead when both `require_auth` is false and `disabled_namespaces` is
  empty — don't touch the filters at all.

### Step 7 — Module 3: directory-level security hardening ✅
`includes/class-spfw-htaccess.php` → `SPFW_Htaccess` utility (shared file logic):
`path()`, `payload()` (the `<Files *.php> Require all denied </Files>` block, with
an `!mod_authz_core.c` fallback for older Apache), `write()` (via `WP_Filesystem`,
0644, stores `sha1(payload())` into `hardening.htaccess_hash`), `remove()` (only
deletes if `sha1_file()` matches the stored hash — never touches a foreign
`.htaccess`), `status()` → `ok|missing|altered|disabled`.

`includes/modules/class-spfw-module-hardening.php` → `SPFW_Module_Hardening`:
on `admin_init`, if `missing`/`altered`, show an `admin_notices` warning (native
WP notice, above the React root); toggling the setting on/off calls
`write()`/`remove()`; wire into `SPFW_Plugin::activate()`/`deactivate()`.
`SPFW_Rest_Settings::get_settings()` (Step 5) gains a computed read-only
`hardening_status` field, plus a `spfw/v1/settings/restore-htaccess` POST route
(cap `manage_options`) so the React Restore button needs no page reload.

`src/components/RestApiSettings.jsx` sibling
`src/components/HardeningSettings.jsx` (props `{settings, onChange,
hardeningStatus, onRestore}`): toggle with an explicit **OpenLiteSpeed note** —
OLS only honors `.htaccess` when "Allow Override" is enabled at the vhost level
(LiteSpeed WebAdmin → Rewrite → Auto Load from .htaccess); the write is fail-safe
(only adds restriction) if override is off. Also warn that rare legacy plugins
serve front-facing PHP from `/plugins/`. Status indicator + Restore button driven
by `hardeningStatus`.

### Step 8 — Module 4: Google Fonts localizer & discovery ✅
`includes/modules/class-spfw-module-fonts.php` → `SPFW_Module_Fonts`.
- **Discover** (triggered by `spfw/v1/settings/scan-fonts` POST route on
  `SPFW_Rest_Settings`, cap `manage_options` — not `admin-ajax.php`): fetch the
  homepage, regex-scan for `fonts.googleapis.com` references, fetch each Google
  CSS URL with a modern Chrome UA (so Google returns `.woff2`), parse `@font-face`
  blocks, download each unique `.woff2` via `wp_remote_get`/`WP_Filesystem` into
  `wp_upload_dir()['basedir'].'/ods-fonts/'`, rewrite `src: url()` to local URLs,
  persist `discovered = {css, families, files, hash}` + `last_scan` via
  `SPFW_Settings::update()`; the route returns the refreshed settings.
- **Serve** (frontend, only when `localize_google` on and `discovered['css']` set):
  on `wp_enqueue_scripts` (~99), dequeue any style whose **src** (not handle)
  contains `fonts.googleapis.com`, strip the gstatic/googleapis resource hints,
  enqueue the generated local stylesheet versioned by `discovered['hash']`.
  **Fallback:** no local CSS yet → leave the original Google enqueue untouched,
  never break rendering.
- `src/components/FontsSettings.jsx` (props `{settings, onChange, onScan}`):
  toggle + "Scan fonts now" button (`onScan()` → local `isScanning` state) +
  summary (families/files/last-scan, read from `settings.fonts.discovered`) +
  re-scan control.
- After a hash-changing scan, trigger `litespeed_purge_all` so LSCache picks up
  the rewrite; the `hash` in the stylesheet version busts OLS's static-file cache
  on re-scan without a manual purge.

### Step 9 — Uninstall cleanup ✅
`uninstall.php`: `defined('WP_UNINSTALL_PLUGIN') || exit;` guard (runs outside the
normal plugin load — don't assume plugin classes are loaded). Read
`hardening.htaccess_hash` from the stored option; delete the plugins-directory
`.htaccess` only if its `sha1_file()` matches (never a foreign file); recursively
delete `uploads/ods-fonts/` if present; `delete_option('spfw_settings')` (loop
`get_sites()` for multisite); every filesystem/DB action guarded by an existence
check so double-running uninstall is a no-op.

---

## Decisions & deviations log

Record here anything a later step needs to know: choices that differ from the spec,
handles/paths that turned out different in practice, WP/PHP quirks encountered, or
follow-ups deferred. Keep entries dated and terse.

- 2026-07-10: Step 1 built exactly to spec. `plugins_loaded`/activation/deactivation
  callbacks use closures guarded by `class_exists`/`file_exists` checks since
  `SPFW_Plugin` (Step 3) doesn't exist yet — no fatals on this partial build.
- 2026-07-10: Step 2 built exactly to spec. `update()` deep-merges the incoming
  (possibly partial) array against the **current stored settings** (not just
  defaults) before sanitizing, so a save from one admin tab never clobbers another
  group's values. `merge_recursive()` distinguishes associative "group" arrays
  (deep-merged) from list arrays like `disabled_namespaces` (replaced wholesale on
  update, per the spec's "list values replace outright" intent). Verified all four
  acceptance criteria with a stubbed WP-function test harness (single `get_option`
  call across repeated `get()`, sanitize clamps/filters, update+get reflects new
  values, partial update preserves other keys) — harness was scratch-only, not
  committed.
- 2026-07-10: Step 3 built exactly to spec. `SPFW_Plugin::MODULES` is the explicit
  class => file map named in the spec; each entry is `file_exists`-guarded so
  partial builds (Steps 4/6/7/8 not yet present) boot cleanly with no fatal.
  Simplified Step 1's activation/deactivation hook registration to
  `array('SPFW_Plugin','activate')` / `array('SPFW_Plugin','deactivate')` directly
  (the class now always exists, so the earlier `class_exists` closure-guard is
  redundant) and made the `class-spfw-plugin.php` require unconditional. Verified
  with a stubbed harness: activation seeds `spfw_settings` idempotently, a
  frontend-context `boot()` never includes anything under `admin/`, and `boot()`
  with zero modules present does not fatal.
- 2026-07-10: Step 4 built exactly to spec. `handle_disabled_feed()` reads
  `feed_redirect_home` fresh from `SPFW_Settings::group('core')` at fire time
  rather than capturing it in a closure at `register()` time, since settings could
  change between requests but the option is only ever queried once per request via
  the Step 2 cache — no extra DB cost. Verified with a stubbed WP hook-registry
  harness: with schema defaults, exactly the expected hook set is attached (and
  `disable_feeds`/`remove_query_strings`, which default off, correctly attach
  nothing); with every toggle forced off, zero hooks are attached; every
  pure-logic helper (ver-stripping, pingback method/header filtering, emoji
  dns-prefetch filtering, heartbeat interval override, jQuery Migrate dependency
  removal) was exercised directly and behaves correctly. Harness was scratch-only,
  not committed.
- 2026-07-10: **Architecture pivot before Step 5** — the user required vanilla
  JS/no jQuery and asked for styling to match the sister plugin
  `onedogsolutions/google-security-for-wordpress`, built with React + Tailwind
  v4. Cloned that repo (added via `add_repo`, at `/workspace/google-security-for-wordpress`)
  to copy its actual architecture rather than guess: `@wordpress/element` (React
  bundled with WP core, so genuinely no separate React dep and no jQuery),
  `@wordpress/scripts` (wp-scripts) build, Tailwind v4 via `@tailwindcss/postcss`,
  one settings REST endpoint consumed via `@wordpress/api-fetch`, a
  `.{plugin}-admin-isolated` class scoping Tailwind's `@layer base` so wp-admin's
  unlayered styles can't clobber it (and vice versa), `build/` gitignored and
  produced by `npm run build`. Rewrote the already-in-progress Step 5 (which had
  started as plain PHP form views/admin-post.php before this correction) and
  updated `IMPLEMENTATION_PLAN.md` §1 plus `docs/build-steps/00,05,06,07,08` to
  reflect this for all remaining steps: Steps 6–8's "admin tab" deliverable is now
  a React component (`RestApiSettings.jsx`/`HardeningSettings.jsx`/
  `FontsSettings.jsx`) wired into `App.jsx`, not a `tab-*.php` view; Step 8's
  "Scan fonts" AJAX action became a `spfw/v1/settings/scan-fonts` REST route (and
  Step 7 similarly gained `spfw/v1/settings/restore-htaccess`) for consistency
  with the REST-driven persistence model — **these two routes are specified but
  not yet implemented; they land with Steps 7/8 respectively.**
  Added `includes/class-spfw-rest-settings.php` (`SPFW_Rest_Settings`): GET/POST
  `spfw/v1/settings`, both capped `manage_options`, POST sanitizing via the
  existing `SPFW_Settings::update()`. Loads **unconditionally** from
  `SPFW_Plugin::boot()` (required directly, not through the toggleable `MODULES`
  registry and not inside the `is_admin()` branch) because REST requests aren't
  admin context — verified with a stubbed harness that `rest_api_init` still
  fires and the route registers even when `is_admin()` returns false, and that
  `admin/` is still never loaded on that same request. Rewrote
  `admin/class-spfw-admin.php` to just register the menu, render a bare
  `#spfw-admin-root` mount div, and enqueue `build/index.js`/`build/index.css`
  (reading `build/index.asset.php` for deps/version, falling back to
  `['wp-element','wp-api-fetch','wp-i18n']`/`SPFW_VERSION` if the asset file
  doesn't exist yet, i.e. before the first `npm run build`) with
  `spfwAdminData` (`restUrl`, a `wp_rest` nonce, and the current
  `SPFW_Settings::get()` snapshot) localized onto it.
  Added `package.json`/`webpack.config.js`/`postcss.config.js` mirroring the
  sister plugin, plus `src/index.js`, `src/styles/index.css`
  (`.spfw-admin-isolated`-scoped), and components `App.jsx`, `SettingsTabs.jsx`
  (generic tablist/tabpanel with arrow-key nav, copied near-verbatim — it's
  settings-shape-agnostic), `Toggle.jsx` and `SettingsRow.jsx` (small shared
  pieces factored out for reuse by Steps 6–8's components — the toggle-switch
  and title/description/control row markup would otherwise be copy-pasted four
  times), and `CoreSettings.jsx` (every Step 4 `core` setting). `App.jsx` renders
  `restapi`/`hardening`/`fonts` as a placeholder "not available yet" body until
  each step's component exists.
  **Toolchain gotcha, fixed:** a fresh `npm install` (no inherited lockfile)
  resolved `typescript@7.0.2` transitively, which crashes
  `@typescript-eslint@6.21`'s internals (`ts-api-utils`) with `Cannot read
  properties of undefined (reading 'Intrinsic')` — confirmed by diffing against
  the sister plugin's `node_modules`, which resolves `typescript@6.0.3` only
  because its committed `package-lock.json` pins it. Fixed by adding an explicit
  `"typescript": "6.0.3"` devDependency pin in our `package.json` (matching the
  sister plugin's resolved version) so `npm run lint:js` doesn't crash for future
  contributors doing a clean install; `package-lock.json` is now committed for
  the same reason (unlike `node_modules/`/`build/`, which stay gitignored).
  Fixed 8 real `wp-scripts lint-js` findings (prettier formatting + two
  `jsx-a11y/label-has-associated-control` violations, resolved via explicit
  `htmlFor`/`id` pairs) — 6 were auto-fixed with `--fix`.
  **Verified:** `npm install && npm run build` succeeds, producing
  `build/index.js`/`index.css`/`index.asset.php`; `npm run lint:js` and
  `npm run lint:css` both clean; REST controller behavior confirmed with a
  stubbed harness (route registers with GET+POST, permission_callback rejects
  non-`manage_options`, GET returns the full settings snapshot, POST persists a
  partial update while preserving untouched keys).
- 2026-07-10: Step 6 built exactly to spec (per the React-pivot revision of
  `06-module-restapi.md`). `route_in_list()` hardcodes `spfw/v1` as
  always-exempt (equality or `spfw/v1/` prefix) ahead of checking the
  user-configured `whitelist_routes`, so the plugin's own settings API can never
  be locked out regardless of configuration — verified explicitly in the test
  harness by listing `spfw/v1/settings` as if it were itself a disabled
  namespace and confirming it's still kept. `RestApiSettings.jsx` fetches the
  live namespace list via `apiFetch({path:'/'})` (the WP-JSON index's
  `namespaces` array) on mount for the checkbox list, with a newline-separated
  textarea as the single source of truth for `disabled_namespaces` (checkboxes
  and textarea both read/write the same flat array — no separate state to
  reconcile). Two `jsx-a11y/label-has-associated-control` findings needed
  explicit `htmlFor`/`id` pairs (as in Step 5 — this project's eslint config
  doesn't accept label-nesting alone as valid association). **Verified** with a
  stubbed PHP harness covering every acceptance criterion: disabled namespaces
  are fully unregistered from `rest_endpoints` (not just gated) unless
  whitelisted; anonymous requests to a disabled namespace get 404
  (`rest_no_route`), never 403; a logged-in `manage_options` user is exempt from
  that 404; `require_auth` returns 401 for anonymous requests on unrestricted
  routes while whitelisted routes still pass; `spfw/v1` passes through
  untouched in every scenario; and with both `require_auth` off and
  `disabled_namespaces` empty, neither filter is attached at all (zero
  overhead). `npm run lint:js`/`lint:css` clean, `npm run build` succeeds.
  Harness was scratch-only, not committed.
- 2026-07-10: Step 7 built exactly to the React-pivot revision of
  `07-module-hardening.md`, with one deliberate simplification: rather than a
  separate nonce-protected `admin_post_spfw_restore_htaccess` GET-triggered
  handler (the spec's original phrasing, left over from before the REST pivot),
  the Restore action is **only** the REST route
  (`spfw/v1/settings/restore-htaccess`, `manage_options`-capped, added to
  `SPFW_Rest_Settings`) that the React button already calls — a GET request
  triggering a filesystem write is an anti-pattern anyway, and a second
  independent restore code path would just be duplicated logic with nothing
  gained. The native `admin_notices` warning (missing/altered) is kept as
  plain server-rendered HTML above the React root — it doesn't need JS to be
  visible — and just links to the Hardening tab rather than performing the
  restore itself.
  `SPFW_Module_Hardening` reacts to the toggle via
  `add_action('update_option_' . SPFW_Settings::OPTION_KEY, ..., 10, 2)`
  (comparing old vs new `hardening.plugins_htaccess`) rather than special-casing
  anything in the REST controller — this means **any** future code path that
  flips the toggle (REST, WP-CLI, direct `update_option`, etc.) gets the
  write/remove side effect for free. Note: `write()`/`remove()` themselves call
  `SPFW_Settings::update()` to persist/clear the hash, which re-triggers this
  same `update_option_*` hook once more — harmlessly, since old/new
  `plugins_htaccess` are now equal on that second pass and the handler no-ops;
  documented here rather than added complexity to prevent a one-level bounce
  that already self-terminates.
  `SPFW_Htaccess` (the shared file utility) is required **unconditionally** in
  `SPFW_Plugin::boot()` (not just when Module 3's file happens to exist),
  because `SPFW_Rest_Settings::get_settings()` (always loaded, Step 5) needs
  `SPFW_Htaccess::status()` for the `hardening_status` field it now returns.
  `SPFW_Plugin::activate()`/`deactivate()` (Step 3) now call
  `SPFW_Htaccess::write()`/`remove()`.
  **Verified** against a real (temp-directory) filesystem, not just mocks: the
  full lifecycle — disabled → toggle on writes file (`ok`) → manual edit
  detected as `altered` → Restore (`write()`) fixes it back to `ok` → deleting
  the file detected as `missing` → Restore recreates it → toggle off removes
  the file (`disabled`) — plus confirmation that a foreign/pre-existing
  `.htaccess` (hash never matches) is never touched by `remove()`. Also
  confirmed the REST controller's new `restore-htaccess` route registers and
  `GET /settings` includes `hardening_status`. `npm run lint:js`/`lint:css`
  clean, `npm run build` succeeds. Harnesses were scratch-only, not committed.
- 2026-07-10: Step 8 built exactly to the React-pivot revision of
  `08-module-fonts.md`, following the `restore-htaccess` REST pattern from
  Step 7 for `scan-fonts` (`SPFW_Rest_Settings::scan_fonts()` calls
  `(new SPFW_Module_Fonts())->scan()` directly — no defensive re-require, since
  by the time any REST callback fires, `SPFW_Plugin::boot()` has already run
  the full `MODULES` loop synchronously within the same `plugins_loaded`
  invocation, same reasoning already established for `SPFW_Htaccess` in Step 7).
  One meaningful deviation from the literal spec text: the static
  `ods-fonts/fonts.css` file is written **once, during `scan()`** rather than
  regenerated on every frontend request inside `wp_enqueue_scripts` — writing a
  file on every single page load would be wasteful I/O directly contradicting
  the plugin's "no heavy footprint" goal, and it's unnecessary since OLS/LSCache
  already hard-caches the static file per the spec's own LSCache note.
  `serve_local_fonts()` only falls back to a **self-heal** rewrite (via the
  cached `discovered.css` still held in the option) if the physical file has
  gone missing since the last scan (moved/deleted), and if that regeneration
  also fails, it returns immediately **before** dequeuing anything — this is
  what actually implements "if the local file/CSS is missing, do nothing" from
  the acceptance criteria, since a naive "dequeue first, enqueue second" order
  would risk leaving a page with no font styles at all if the enqueue step
  failed.
  Google stylesheets are matched by **registered `src` substring**, never by
  handle, exactly per the design constraint (themes use arbitrary handle
  names). `wp_resource_hints` filtering handles both string and
  `{href: ...}` array hint shapes and only touches `preconnect`/`dns-prefetch`
  relations.
  **Verified** with a stubbed harness that fakes `wp_remote_get` per URL
  pattern (homepage HTML → Google CSS → `.woff2` binary) and writes to a real
  temp uploads directory: `scan()` discovers both `@font-face` blocks from a
  two-weight Google Fonts response, downloads both `.woff2` files to disk,
  rewrites the CSS to local URLs with zero remaining `fonts.gstatic.com`
  references, and persists `discovered`/`last_scan`; enabling `localize_google`
  (with cached CSS present) attaches the serve hooks, zero hooks attach
  otherwise; `serve_local_fonts()` dequeues only the style whose **src**
  matches Google (leaving an unrelated enqueued style untouched) and enqueues
  the local stylesheet; the resource-hints filter strips Google entries only
  for `preconnect`/`dns-prefetch`; and — using a fake filesystem that can be
  told to simulate a write failure — a missing physical file that can't be
  regenerated is confirmed to leave both dequeue and enqueue untouched (the
  "never break rendering" fallback). `npm run lint:js`/`lint:css` clean,
  `npm run build` succeeds. Harness was scratch-only, not committed.

  **All four functional modules (Steps 4, 6, 7, 8) are now complete** — only
  Step 9 (uninstall cleanup) remains before the plugin is feature-complete
  per the original implementation plan.
- 2026-07-10: Step 9 built exactly to spec. `uninstall.php` is fully
  self-contained (per the spec's own guidance) — it inlines the hash-gated
  `.htaccess` removal and `ods-fonts/` deletion logic directly rather than
  requiring `SPFW_Htaccess`, since that class's `status()`/`write()` also read
  the *toggle* setting (which is irrelevant at uninstall — we only care whether
  the stored hash matches what's on disk, regardless of whether the toggle was
  left on or off) and pulling in `SPFW_Settings`'s static-cache machinery for a
  script that runs exactly once and exits would be pure overhead. A tiny local
  `spfw_uninstall_filesystem()` helper mirrors the same `WP_Filesystem` init
  pattern used in `SPFW_Htaccess`/`SPFW_Module_Fonts` (Steps 7/8) to avoid
  duplicating that boilerplate twice within the same file. Multisite is handled
  by looping `get_sites()`/`switch_to_blog()`/`restore_current_blog()` around
  the same per-site cleanup function — no network option exists in v1, so
  there's nothing to `delete_site_option`.
  **Verified** with a stubbed harness against a real temp filesystem: a normal
  run removes the authored `.htaccess`, the whole `ods-fonts/` directory
  (recursively), and the `spfw_settings` option; re-running cleanup with
  everything already absent produces no errors (idempotent); and a foreign or
  stale-hash `.htaccess` is confirmed untouched. `php -l` clean across every
  PHP file in the repo. Harness was scratch-only, not committed.

  **Phase 1 is now fully complete: all 9 steps built, verified, and pushed to
  `claude/simple-performance-wordpress-plugin-6qbso2`.** The plugin implements
  all four modules from `IMPLEMENTATION_PLAN.md` (core toggles, REST API
  controls, directory hardening, Google Fonts localizer) behind a single
  React + Tailwind v4 admin app talking to one REST settings endpoint, backed
  by the single autoloaded `spfw_settings` option. Not yet done (out of scope
  for these 9 steps): a `.pot` translation file, a `readme.txt`
  (WordPress.org-style), and end-to-end manual QA against a live OpenLiteSpeed
  + LiteSpeed Cache install (everything so far has been verified with stubbed
  PHP harnesses and a real `npm run build`/lint pipeline, not a running
  WordPress site).
- 2026-07-10 (post-Phase-1 follow-up): added WordPress.org submission
  readiness — `readme.txt` (standard `.org` format: headers, Description,
  Installation, FAQ, Changelog; short description 128 chars, under the
  150-char limit; 5 tags; `Stable tag: 1.0.0` matches the plugin header
  version), `languages/simple-performance-for-wordpress.pot` (generated for
  real via WP-CLI's `wp i18n make-pot` — downloaded the phar since it wasn't
  preinstalled — scanning **both** PHP and JSX sources, 77 unique strings with
  correct file:line references; confirmed by spot-checking entries attributed
  to `src/components/*.jsx`), a `Domain Path: /languages` header added to the
  main plugin file, and `.distignore` (excludes `node_modules/`, `src/`,
  `docs/`, `IMPLEMENTATION_PLAN.md`, `STATE.md`, `README.md`, and the npm/build
  config files from the release ZIP — none of that dev tooling belongs in a
  shipped plugin). **Still manual/outstanding before an actual `.org`
  submission:** run `npm install && npm run build` and zip per `.distignore`
  to produce the release artifact; the submission itself
  (wordpress.org/plugins/developers/add/) requires a human with a WP.org
  account; and optional SVN `/assets` graphics (banner/icon/screenshots)
  can't be produced without a live running install to actually screenshot.
  This work sits outside the original 9-step Phase 1 plan, so it isn't a row
  in the Progress table above — noted here for continuity only.
- 2026-07-10 (Step 10): Perfmatters quick-toggle parity + WooCommerce tab,
  built on branch `claude/feature-parity-quick-toggles-sf64kt`. Per user
  direction: **no Change Login URL**; **Heartbeat and post options match
  Perfmatters** (replaced `heartbeat_mode`/`heartbeat_interval` with
  `heartbeat_control` [default|disable|allow_posts] + separate
  `heartbeat_frequency`; added `post_revisions` [default|disable|1–30] and
  `autosave_interval` [0=default|1–5 min]); **Google Maps included** as a
  `template_redirect` output-buffer scrub of external Maps scripts + map
  iframes. Autosave uses `define('AUTOSAVE_INTERVAL', …)` in
  `SPFW_Module_Core::register()` — safe because `plugins_loaded` (when the
  module registers) runs before `wp_functionality_constants()` defines the WP
  default, so our value wins via the `if (!defined())` guard. New WooCommerce
  module bails immediately unless `class_exists('WooCommerce')`, added to
  `SPFW_Plugin::MODULES`. `hide_wp_version`/`remove_shortlink`/
  `disable_self_pingbacks` default **on** (harmless cleanup, matching the
  plugin's existing opinionated defaults) — a slight deviation from the plan's
  "all new toggles default off" note, chosen for consistency with the existing
  aggressive Core defaults; all behavior-changing toggles (comments, maps,
  password meter, feeds, favicon, etc.) default off. Admin now localizes
  `woocommerceActive`; the WooCommerce React tab only mounts when true. REST
  save fires `litespeed_purge_all` (no-op without LSCache). Admin UI adopted
  the sister plugin's floating meta-box cards via a shared `SettingsCard`
  component (Core split into 5 cards; REST/Hardening/Fonts each wrapped in one).
  **Verified:** `php -l` clean; scratch PHP hook-registry/sanitize/helper
  harness green (default vs all-on wiring, sanitize whitelists+clamps,
  WooCommerce no-op-without-Woo, self-pingback/Maps-scrub/revisions/version-arg/
  comment-URL helpers); `npm run build` + `lint:js` + `lint:css` clean. Harness
  scratch-only, not committed. **Outstanding:** regenerate `.pot`; live QA.

- 2026-07-11 (REST tab fixes, branch `claude/toggles-404-routes-fix-zufbab`):
  two UI/behavior corrections to the REST API tab.
  **(1) Disable-namespaces layout** — the namespace toggles were a fixed
  `grid grid-cols-1 sm:grid-cols-2` crammed into `SettingsRow`'s right-hand
  control column, which handled poorly on sites with many registered routes.
  Pulled the "Disable namespaces" section out of `SettingsRow` into a
  full-width block inside the card: title/description on top, then the toggles
  flowing **underneath** as a flexbox grid (`flex flex-wrap gap-3`, each chip
  `grow basis-72`) so they wrap cleanly across the full card width regardless
  of route count. The advanced textarea stays beneath.
  **(2) 404 section only showed `users`** — `SPFW_Module_RestApi::unregister_disabled_namespaces()`
  (hooked on `rest_endpoints`) stripped every disabled namespace from the route
  table for **all** requests, including the admin loading the settings page.
  The React checklist is built from `apiFetch('/')`'s `data.namespaces`, so
  every currently-disabled namespace (defaults now disable users/themes/
  comments/settings/taxonomies) had already vanished from the index the admin
  fetched — gutting the checklist. Fix: added `user_is_exempt()`
  (`is_user_logged_in() && current_user_can('edit_posts')` — "can edit content")
  and early-return the endpoints untouched in `unregister_disabled_namespaces()`
  when exempt, so admins/editors always see the full index (restriction still
  applies to anonymous scanners). Also switched `authenticate_request()`'s 404
  branch from `manage_options` to the same `user_is_exempt()` helper so editors
  aren't inconsistently 404'd on routes the filter left registered for them.
  Updated the tab's description copy to match the behavior ("…for logged-out
  visitors… Logged-in users who can edit content are never restricted.").
  **Verified:** `php -l` clean; `npm run build` succeeds; `npm run lint:js`
  clean on the changed `RestApiSettings.jsx` (2 pre-existing
  `CoreSettings.jsx:476` a11y errors are untouched and out of scope);
  `npm run lint:css` clean.

## Open questions / blockers

- _(none yet)_

---

## Update protocol (every build session, read this)

When you finish a step (or stop partway), before ending your turn you MUST:

1. Flip that step's **Status** in the Progress table (🟡 while working, ✅ when its
   acceptance criteria pass) and fill in the short commit hash. Also mark the
   matching `### Step N` heading above with ✅ (or leave unmarked/🟡 if paused).
2. Update **Overall status**, **Last updated** (today's date), and **Next action**.
3. Append any surprises to **Decisions & deviations log** and any unresolved items to
   **Open questions / blockers**.
4. Commit STATE.md **in the same commit** as the step's code so state never drifts
   from the tree, then push.

Do not mark a step ✅ unless its acceptance criteria (in the step's spec file, or the
condensed instructions above) are actually met. If you stop mid-step, leave it 🟡
and note exactly where you paused under Decisions & deviations so the next session
can resume cleanly.
