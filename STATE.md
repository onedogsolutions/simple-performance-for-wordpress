# Build State — Simple Performance for WordPress

**Single source of truth for build progress AND the step-by-step implementation
plan.** Every build session MUST update this file as its final action (see "Update
protocol" below). Read this first before starting any step. This file inlines the
condensed version of each build step so progress and plan travel together in one
top-level document. (The original full-detail per-step specs that once lived in
`docs/build-steps/` and the `IMPLEMENTATION_PLAN.md` blueprint were removed after
Phase 1 shipped — the condensed steps below plus the dated decisions log are now
the authoritative record.)

- **Branch:** `main` (font-weight fix merged from
  `claude/plugin-font-weight-issues-2xfjms`; prior work on
  `claude/missing-security-headers-x8gyp9`,
  `claude/simple-performance-wordpress-plugin-6qbso2` / Step 10 on
  `claude/feature-parity-quick-toggles-sf64kt`)
- **Plugin version target:** 1.10.0
- **Last updated:** 2026-07-22
- **Overall status:** ✅ Phase 1 complete (9/9); ✅ Step 10 (Perfmatters
  quick-toggle parity + WooCommerce tab) implemented; ✅ Google Fonts discovery
  reliability fix (branch `claude/google-fonts-discovery-plan-tjsdwr`); ✅
  Hardening-toggle write bug fixed + hardening options expanded (branch
  `claude/toggle-htaccess-plan-fsl3p0`); ✅ Content-Security-Policy header added
  with safety/exclusion options (branch `claude/state-md-missing-header-pbhit2`);
  ✅ Strict-Transport-Security (HSTS) header added, proxy-aware (branch
  `claude/missing-security-headers-x8gyp9`); ✅ CSP visual policy builder +
  live violation-report warnings (branch `claude/missing-security-headers-x8gyp9`);
  ✅ Localized-fonts wrong-weight bug fixed (variable-font block-identity dedupe,
  1.7.1, branch `claude/plugin-font-weight-issues-2xfjms`); ✅ Disable WP
  Sitemaps + Remove robots max-image-preview Core toggles added (absorbs two
  single-hook standalone plugins, 1.8.0, branch
  `claude/wp-sitemaps-robots-toggles-eaoris`, merged to `main`); ✅ CSP policy
  builder coverage gaps fixed (`worker-src` row added, `script-src-elem`/
  `style-src-elem` effective directives collapsed to their base row, 1.9.0,
  branch `claude/policy-builder-coverage-gaps-3dwztj`, merged to `main`); ✅
  Beaver Builder settings-based font discovery added (reads Google Fonts from
  Beaver Builder's stored global + per-layout settings, immune to page-cache/
  optimizer tag stripping, 1.10.0, salvaged from the obsolete
  `claude/branch-cleanup-state-ck3owq`, merged to `main`)

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

**1.10.0 (Beaver Builder settings-based font discovery) is the current release,
implemented and merged to `main`.** A test release ZIP
(`simple-performance-for-wordpress-1.10.0.zip`) was built for WordPress
install/QA. Recent releases now merged to `main`: 1.9.0 (CSP policy-builder
coverage gaps — `worker-src` + effective-directive collapse) and 1.10.0 (Beaver
Builder font discovery). See the 2026-07-22 decisions entries for detail.

**The long-standing `.pot` backlog item is now cleared.**
`languages/simple-performance-for-wordpress.pot` was regenerated with
`wp i18n make-pot` against the current tree, extracting all accumulated strings
from Step 10, the font fixes, the security headers/CSP work, the sitemaps/robots
toggles, and the 1.9.0 `worker-src` row (225 msgids; Project-Id-Version bumped to
1.10.0). The Beaver Builder discovery added no user-facing strings.

Remaining before release is manual QA on a live WordPress + OpenLiteSpeed +
WooCommerce install — including an end-to-end fonts scan against a theme that
enqueues Google Fonts (confirm `.woff2` land in `uploads/ods-fonts/`, families
list, and the rendered frontend makes no `fonts.googleapis.com`/`fonts.gstatic.com`
requests) and, when Beaver Builder is active, that fonts set only in its layout
settings are discovered. Prior toggle QA still outstanding: enabling **Disable WP
sitemaps** should 404 `/wp-sitemap.xml`; **Remove robots max-image-preview**
should drop `max-image-preview:large` from the robots meta tag. None of these
were runtime-verified in the build environment (no WordPress instance available).
Phase 1 (Steps 1–9) and Step 10 remain complete.

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

- 2026-07-11 (Google Fonts discovery fix, branch
  `claude/google-fonts-discovery-plan-tjsdwr`): the fonts localizer reliably
  discovered **zero** fonts on real sites. Root cause: discovery was a single
  loopback fetch of the homepage HTML + a narrow regex
  (`#https://fonts\.googleapis\.com/css2?\?…#`) that only matched `https://`
  URLs with the query starting immediately — so protocol-relative
  (`//fonts.googleapis.com/…`), v1 `css?` used inside theme CSS, `@import`ed
  fonts, and any font not literally in the homepage markup were all missed, and
  a blocked/cached/redirected loopback returned no fonts at all.
  **Fix (in `class-spfw-module-fonts.php`):** discovery now captures fonts from
  WordPress's own style pipeline during an instrumented loopback render. `scan()`
  mints a one-time token (stored in the `spfw_font_scan_token` transient) and
  loads the homepage with that token + a cache-buster; `register()` — on the
  loopback request only, gated by `hash_equals()` against the transient and never
  in `is_admin()` — attaches a `style_loader_src` filter that records every
  `//fonts.googleapis.com/css` src into the `spfw_font_scan_urls` transient
  (flushed on `shutdown`), which `scan()` reads back. This catches enqueued
  Google Fonts regardless of protocol/version/handle — the primary reliability
  win. Two fallbacks union in alongside it: a **broadened** regex
  (`(?:https?:)?//…/css2?\?…`, entity-decoded) over the returned HTML, and
  **same-origin CSS following** (fetch up to `MAX_LINKED_CSS=10` linked
  stylesheets and scan them for `@import`ed Google Fonts). Loopback hardened
  (browser UA, `timeout` 20, `redirection` 5, `sslverify=>false` retry on
  `WP_Error`). `scan()` now distinguishes a real fetch failure (→ `WP_Error`
  with an actionable "server may block loopback" message) from "loaded fine, no
  fonts found" (→ soft result; **existing `discovered` is left intact** so a
  transient blip never wipes working fonts — only `last_scan` refreshes).
  `parse_font_faces()` hardened: dedupe by src URL, keep `font-style`
  (italic → `:400i` label suffix) and weight ranges (`font-weight: 100 900`).
  The `scan-fonts` REST route returns a `scan_result` summary
  (`{families, files, message}`); `FontsSettings.jsx` shows a family/file count,
  a "No Google Fonts detected" zero-state, and the scan message, and `App.jsx`
  toasts that message (info vs success). **No reference to any third-party
  plugin in code or docs.**
  **Verified:** `php -l` clean on both changed PHP files; `npm run build` +
  `lint:js` + `lint:css` clean on the changed files (2 pre-existing
  `CoreSettings.jsx:476` a11y errors untouched/out of scope). Two scratch PHP
  harnesses (reflection over private methods; not committed): (1) broadened URL
  matching for protocol-relative/v1/v2/entity-encoded, normalize→https+dedupe,
  parse dedupe+italic+weight-range, `capture_style_src` records Google-only, and
  the token gate (no token / valid token / wrong token / admin context) all
  behave correctly; (2) full `scan()` flow with mocked network+filesystem
  confirms success (captured + HTML union → families/files/CSS persisted),
  empty (soft result, `discovered` preserved, `last_scan` refreshed), and
  fetch-fail (`WP_Error`). **Live end-to-end QA against a real Google-Fonts
  theme still outstanding** (no running WP in this environment).

- 2026-07-11 (Hardening toggle write bug + expanded hardening options, branch
  `claude/toggle-htaccess-plan-fsl3p0`, version → 1.3.0):
  **Root cause of "the toggle does nothing / nothing written to .htaccess":**
  a re-entrancy / stale-static-cache defect in `SPFW_Settings::update()`. It
  called `update_option()` (which fires `update_option_{$option}`
  **synchronously**, before control returns) and only invalidated the static
  cache on the *next* line. The hardening module's `update_option_*` listener
  writes the .htaccess and then calls `SPFW_Settings::update()` again to store
  the file hash; that nested `update()` read the **still-stale** cache (holding
  the pre-save `plugins_htaccess = false`), merged the hash onto the old
  toggle value, and persisted `plugins_htaccess = false` — silently reverting
  the user's ON toggle and re-firing the hook so `remove()` ran. Net effect:
  DB ended false, REST echoed false, UI snapped the toggle back off, status
  `disabled`. The old Step 7 log note (this nested pass "harmlessly no-ops")
  was wrong precisely because of the stale cache. **Fix:** seed
  `self::$cache = $clean` **before** the `update_option()` call (and drop the
  trailing `self::$cache = null`), so every re-entrant `get()` during the hook
  is consistent with what's being written. Verified with a scratch harness
  that fires the real `update_option_*` hook: the fixed code persists the
  toggle + hash + on-disk file (`ok`); reverting to the old ordering makes the
  same harness fail exactly as reported (toggle → false, status not `ok`).
  Also hardened the failure path: `restore_htaccess` (REST) now returns a 500
  with an actionable message when the filesystem write fails (previously the
  boolean was ignored and the UI showed a false success), so hosts without
  direct `WP_Filesystem` write access get real feedback.
  **Expanded hardening (Hardening tab, per user request):**
  - `SPFW_Htaccess` generalized from a single hardcoded plugins target to a
    `plugins`/`uploads` target map (per-target path/toggle/hash). New
    **"Block PHP execution in uploads"** drops the same `<Files *.php>` deny
    file into `wp-content/uploads/` (uploads is the top malware landing spot).
  - New `SPFW_Module_Hardening` runtime toggles (no .htaccess, so
    OLS-override-independent): **Disable theme/plugin file editor**
    (`DISALLOW_FILE_EDIT` define, guarded so wp-config.php always wins);
    **Block author enumeration** (`template_redirect` priority 1 — before
    `redirect_canonical` can leak a username — redirects anonymous `?author=N`
    / `/author/slug/` to home); **Send security headers** (`send_headers`:
    `nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, a restrictive
    `Permissions-Policy`; deliberately no HSTS/CSP).
  - Schema: `hardening` gains `uploads_htaccess`, `uploads_htaccess_hash`,
    `disable_file_editing`, `block_author_enum`, `security_headers` (all
    default **false** — opt-in). No migration needed: `merge_recursive` fills
    the new keys from defaults for existing installs.
  - `activate()`/`deactivate()` and `uninstall.php` now handle both .htaccess
    targets (uninstall still hash-gated per target — never removes a foreign
    file). REST `get_settings` adds `uploads_hardening_status`;
    `restore-htaccess` accepts a `{target}` body param.
  - `HardeningSettings.jsx` restructured into two cards (Directory Hardening:
    plugins + uploads file toggles with per-file status/Restore; Site
    Hardening: the three runtime toggles). `App.jsx` restore handler takes a
    target.
  **Deliberate choice:** the .htaccess payload stays `<Files *.php>`-only — I
  did **not** add `Options -Indexes` (directory-browsing block), because
  `Options` in .htaccess requires `AllowOverride Options` and 500s an Apache
  vhost that lacks it; too risky for the uploads dir especially. Payload
  comment genericized to "this directory"; existing plugins files keep their
  stored hash and stay `ok` until the next write (no false `altered`).
  **Verified:** `php -l` clean on all changed PHP; `npm run build` +
  `lint:js` (changed files) + `lint:css` clean; scratch harness (16 checks:
  toggle-on persists, dual independent targets, altered-detect + restore,
  foreign-file protection, toggle-off removal) green, and proven to fail on the
  pre-fix ordering. **Outstanding:** live QA on a real OLS + WP install
  (confirm both .htaccess files land and are honored with Allow Override on;
  confirm the file editor disappears, `?author=1` redirects home, and the
  security headers appear in responses); regenerate `.pot` for the new strings.

- 2026-07-11 (release housekeeping): merged `claude/toggle-htaccess-plan-fsl3p0`
  into `main` (fast-forward — `main` previously held only the initial commit, so
  this brings the entire built plugin onto `main` for the first time). Deleted the
  historical `docs/build-steps/` per-step spec files (Phase 1 is shipped; the
  condensed steps + decisions log in this file are now the authoritative record)
  and pruned the matching stale `docs`/`IMPLEMENTATION_PLAN.md` entries from
  `.distignore`. Bumped `readme.txt` to `Stable tag: 1.3.0` with a 1.3.0 changelog
  entry and expanded its hardening section. Produced a test ZIP
  (`simple-performance-for-wordpress-1.3.0.zip`) from a fresh `npm run build`,
  packaged per `.distignore`.

- 2026-07-11 (Content-Security-Policy header, branch
  `claude/state-md-missing-header-pbhit2`, version → 1.4.0): added the last
  missing security header flagged by an external scan (all others —
  X-Content-Type-Options / X-Frame-Options / Referrer-Policy /
  Permissions-Policy / HSTS — already pass; only CSP was red). CSP is the one
  header that routinely breaks sites, so it is a **separate** opt-in toggle from
  the existing `security_headers` set, with safety/exclusion controls rather
  than a single always-on line.
  **Schema (`hardening` group, all safe defaults):** `csp_enabled` (false),
  `csp_report_only` (**true** — first enables `Content-Security-Policy-Report-Only`
  so violations are logged in the console without blocking; admin flips it off to
  enforce), `csp_exclude_logged_in` (**true** — skips the header for logged-in
  users so the block editor / customizer / admin bar, all heavy inline JS, never
  break), `csp_policy` ('' — the full policy string; empty ⇒ the shipped
  recommended default is used). Sanitizer for `csp_policy` deliberately does
  **not** use `sanitize_text_field()` (it would strip the `'self'`/`'unsafe-inline'`
  single quotes CSP requires) — instead it flattens line breaks (UI uses a
  textarea for readability), collapses whitespace, strips control chars, caps at
  2000 chars.
  **PHP (`SPFW_Module_Hardening`):** `DEFAULT_CSP` constant — a pragmatic
  WP-safe baseline (`default-src 'self'`; `'unsafe-inline'` + `https:` for
  style/script since WP/themes emit inline styles+scripts; `data:` images/fonts;
  `object-src 'none'`; `base-uri 'self'`; `frame-ancestors 'self'`). Registered
  on `send_headers` (never fires in wp-admin ⇒ dashboard auto-excluded), gated on
  `csp_enabled`, independent of the `security_headers` hook. `add_csp_header()`
  bails on `headers_sent()`, bails for logged-in users when
  `csp_exclude_logged_in`, picks the report-only vs enforcing header name by
  toggle, uses `csp_policy` or falls back to `DEFAULT_CSP`.
  **REST:** `get_settings()` now also returns read-only `csp_default`
  (= `SPFW_Module_Hardening::DEFAULT_CSP`) so the React "Load recommended
  policy" button and the textarea placeholder can show it without hardcoding the
  policy in JS. No new route (generic settings POST persists the new keys).
  **React (`HardeningSettings.jsx`):** new "Content-Security-Policy" card in the
  Hardening tab — master toggle; when on, reveals Report-Only toggle,
  do-not-apply-to-logged-in toggle, and an editable mono policy textarea
  (placeholder = recommended default) with a "Load recommended policy" button.
  Prominent copy: test in Report-Only until the console is clean before
  enforcing. Updated the `security_headers` row copy (no longer says CSP is
  omitted). **Verified:** `php -l` clean on the 3 changed PHP files;
  `npm run build` succeeds; `wp-scripts lint-js` clean on `HardeningSettings.jsx`;
  `npm run lint:css` clean. **Outstanding (unchanged):** live QA on a real
  OLS + WP install (confirm the CSP header appears on front-end responses, is
  absent in wp-admin and for logged-in users when excluded, and Report-Only vs
  enforce switch correctly); regenerate `.pot` for the new strings.

- 2026-07-13 (Strict-Transport-Security header, branch
  `claude/missing-security-headers-x8gyp9`, version → 1.5.0): an external
  Security Headers scan (securityheaders.com) flagged `hayeswindows.com` at
  grade B — Strict-Transport-Security was the only actually-missing header;
  the plugin had no code emitting it anywhere. (Content-Security-Policy was
  also flagged, but that's expected/by-design: the site had CSP enabled in
  Report-Only mode, which the scanner doesn't count as the enforced header —
  not a bug, just the deliberate default from the 1.4.0 CSP work.) Same gap
  applies whether or not the site sits behind a QUIC.cloud reverse proxy.
  **PHP (`SPFW_Module_Hardening`):** new `add_hsts_header()`, hooked on
  `send_headers` like CSP — a **separate opt-in toggle** (`hsts_enabled`),
  not folded into the existing `security_headers` toggle, because HSTS is
  sticky: once a browser sees it, it refuses plain HTTP for `max-age`
  regardless of later settings changes, so it deserves its own explicit
  consent exactly like CSP already does. Bails on `headers_sent()` and on a
  new `is_https_request()` check. **`is_https_request()` is the key fix for
  proxied sites:** LiteSpeed/QUIC.cloud (and most reverse proxies) terminate
  TLS at the edge, so `is_ssl()` alone sees only the plain-HTTP connection to
  the origin and would never fire HSTS on an HTTPS site sitting behind such a
  proxy. The helper additionally accepts `X-Forwarded-Proto: https`,
  `X-Forwarded-Ssl: on`, or `X-Forwarded-Port: 443` — standard reverse-proxy
  signals — so the header fires correctly with or without a proxy in front.
  Header value assembled from three settings: `hsts_max_age` (whitelisted to
  1 day/1 week/1 month/6 months/1 year/2 years, default 1 year — matches
  Security Headers' own recommended value),
  `hsts_include_subdomains` (adds `; includeSubDomains`), `hsts_preload`
  (adds `; preload`).
  **Schema (`hardening` group, all safe defaults):** `hsts_enabled` (false),
  `hsts_max_age` (31536000), `hsts_include_subdomains` (false), `hsts_preload`
  (false). Sanitizer whitelists `hsts_max_age` against the six UI-offered
  durations, falling back to the 1-year default for anything else. No new
  REST route — HSTS persists through the same generic settings POST as every
  other hardening toggle. No migration needed (new keys fill in via
  `merge_recursive`/`sanitize` fallbacks for existing installs, same pattern
  as every prior hardening addition).
  **React (`HardeningSettings.jsx`):** new "HTTP Strict Transport Security"
  card after the CSP card — master toggle with an explicit warning that HSTS
  forces HTTPS for the chosen duration; when on, reveals a max-age `<select>`,
  an "Include subdomains" toggle (with a warning to only enable once every
  subdomain is confirmed HTTPS-ready), and a "Preload" toggle (with a warning
  that hstspreload.org submission is very hard to reverse).
  **Verified:** `php -l` clean on all 3 changed PHP files; a scratch PHP
  harness (not committed) exercised the `hsts_max_age` whitelist/fallback
  logic, the `is_https_request()` proxy-header matrix (direct HTTPS, plain
  HTTP, `X-Forwarded-Proto: https`/`HTTPS`, `X-Forwarded-Ssl: on`,
  `X-Forwarded-Port: 443`, and a proxy explicitly forwarding `http` which
  must NOT trigger HSTS), and the assembled header string for all three
  toggle combinations — all passed. `npm install && npm run build` succeeds;
  `wp-scripts lint-js --fix` cleaned 6 prettier-only formatting findings on
  the new `<select>` options in `HardeningSettings.jsx` (no logic changes);
  `npm run lint:css` clean. **Outstanding:** live QA on a real HTTPS install
  (confirm the header appears on front-end HTTPS responses, is absent over
  plain HTTP, and correctly appears when simulating `X-Forwarded-Proto`
  behind a proxy); regenerate `.pot` for the new strings.

- 2026-07-14 (CSP visual builder + live violation warnings, branch
  `claude/missing-security-headers-x8gyp9`, version → 1.6.0): two-part feature
  on top of the 1.4.0 CSP header. **Part 1 — toggle builder.** The single raw
  `csp_policy` string is replaced (for new/default installs) by a structured
  `csp_directives` map edited via per-directive chips + an "additional hosts"
  field, with a live-generated preview. `csp_mode` ('builder'|'custom') picks
  the source: builder serializes `csp_directives`; custom (Advanced raw mode)
  keeps the existing textarea for arbitrary directives. New PHP in
  `SPFW_Module_Hardening`: `build_policy_from_directives()` (skips empty
  directives, collapses 'none'), `parse_policy_to_directives()`, and
  `default_csp_directives()` (derived by parsing `DEFAULT_CSP` so the string
  and the structured default can never drift — single source of truth, verified
  by a round-trip test). Sanitizer whitelists directive names
  (`SPFW_Settings::CSP_DIRECTIVES`) and per-token charset, **rejecting any token
  with whitespace/`;`/control chars** so a token can't inject a new directive,
  and **preserves empty token-lists** (does not drop them) because the builder
  submits a fixed row set — storing `[]` is what makes a cleared directive stick
  instead of the default resurrecting on the next `merge_recursive`. Migration
  to 1.6.0: an install that already had a non-empty `csp_policy` is pinned to
  `csp_mode='custom'` (its hand-tuned policy stays authoritative); everyone else
  defaults to the builder. **Part 2 — violation warnings, gated on Report-Only
  mode** (per user amendment: no separate collect toggle). `add_csp_header()`
  appends `report-uri` + `report-to` (with a `Reporting-Endpoints` header) **only
  when `csp_report_only` is on**. New REST routes on `SPFW_Rest_Settings`: a
  **public** `POST /spfw/v1/csp-report` whose callback is **closed (403, stores
  nothing) unless `csp_enabled && csp_report_only`** — the plugin's first
  intentionally public route; hardened with an 8 KB body cap, content-shape
  parsing for both legacy `application/csp-report` and modern
  `application/reports+json` batches, dedupe by (directive, blocked-origin) with
  count bumping, a 50-entry cap with least-recently-seen eviction, and a 7-day
  transient store (never the autoloaded option). Admin-only `GET`/`DELETE` view
  and clear the log. `get_settings()` now also returns `csp_default_directives`
  and `csp_reports`. **React:** extracted the CSP card into
  `src/components/CspPolicyCard.jsx` (chips per directive, live preview,
  Advanced raw toggle, per-directive amber warning boxes with one-click
  "Allow", an "Other violations" bucket, Refresh/Clear, and a 20s poll while
  Report-Only is on); `App.jsx` gained `handleRefreshCspReports`/
  `handleClearCspReports` (functional `setSettings` so polling never clobbers
  unsaved edits). Fixed a controlled-input trap where deriving the hosts field
  value from parsed tokens on every keystroke stripped the trailing space
  needed to type a second host — the field is now backed by local `hostText`
  state, cleared per-directive on the discrete actions (Allow, 'none', reset)
  that change hosts out-of-band. **Verified:** `php -l` clean on all changed
  PHP; two scratch harnesses (27 checks total, not committed) covering
  serializer/parser round-trip, sanitizer token-whitelist + injection rejection
  + empty-preservation, full `sanitize()` integration, the 1.6.0 migration,
  violation extraction (both report shapes), directive/origin normalization,
  and dedup/eviction storage — all green. `npm run build` +
  `wp-scripts lint-js` (changed files, incl. the new component) +
  `lint-style` clean. **Outstanding:** live QA on a real HTTPS install (confirm
  a blocked host in Report-Only surfaces on the right directive, "Allow" adds
  it, enforcing removes `report-uri` and closes `POST /csp-report`); regenerate
  `.pot` for the new strings.

- 2026-07-14 (CSP reporting reliability + enforce-mode collection, branch
  `claude/missing-security-headers-x8gyp9`, version → 1.6.1): shipped 1.6.0 to
  the user for QA; a console screenshot of onedog.solutions showed a wall of
  `script-src` violations blocking `data:text/javascript;base64,…` scripts
  (LiteSpeed/QUIC.cloud inlines JS as data: URIs) with **none** collected in the
  admin. Diagnosis: (1) the site was **enforcing** ("has been blocked", no
  "[Report Only]" prefix), and 1.6.0 only collected in Report-Only mode, so
  nothing was captured; (2) even in Report-Only, `add_csp_header()` emitted
  **both** `report-uri` and `report-to` — Chrome ignores `report-uri` when
  `report-to` is present and switches to the Reporting API, which batches/delays
  reports up to a minute, so they appeared to never arrive. **Fixes:**
  - `add_csp_header()` now emits **`report-uri` only** (removed `report-to` +
    `Reporting-Endpoints` + the `CSP_REPORT_GROUP` constant) for immediate,
    per-violation delivery — the reliability fix.
  - **Collect whenever CSP is enabled, enforce mode included** (user decision,
    reversing the earlier Report-Only-only gate): `add_csp_header()` appends
    `report-uri` in both modes, and `receive_csp_report()` is gated on
    `csp_enabled` alone (still fully closed 403 when CSP is off). So real
    production breakage after enforcing is still surfaced as warnings. Poll +
    UI copy updated to match (enforce-mode warnings flagged amber as "currently
    blocked on your live site").
  - `data:` added to the default `script-src` (in both `DEFAULT_CSP` and the
    `csp_directives` schema default) so LiteSpeed's data:-URI inline scripts
    aren't blocked out of the box on the plugin's own target platform. Marginal
    XSS tradeoff since `'unsafe-inline'` is already present.
  - React "Allow" now maps bare scheme blocks to real tokens
    (`data`→`data:`, `blob`→`blob:`, plus the existing `inline`→`'unsafe-inline'`
    / `eval`→`'unsafe-eval'`), since browsers report a blocked data: script as
    the bare word "data".
  **Verified:** `php -l` clean; scratch harness confirms the new default
  round-trips and `report-to`/`CSP_REPORT_GROUP` are gone; `npm run build` +
  `lint-js` (changed component) clean. **Note for user:** on their live site the
  instant un-break (no update needed) is to flip Report-Only back ON — it never
  blocks. **Outstanding:** live QA of the 1.6.1 build (confirm reports now land
  promptly in both modes and "Allow" of a data: block adds `data:`); regenerate
  `.pot`.

- 2026-07-15 (localized fonts render bold — root cause + plan, branch
  `claude/plugin-font-weight-issues-2xfjms`): user reported (with DevTools
  screenshots of onedog.solutions) that after font localization, footer
  newsletter links and blog archive/single-post body copy render at ~700
  while computed styles show `font-weight: 400`. **Root cause found and
  verified live against Google's API — this is NOT a discovery gap** (which
  is what 1.7.0/`e986f48` addressed): Google serves variable fonts for many
  families now, and for Roboto Condensed v31 both `css?…:300,400,700` (v1,
  what BB Theme enqueues) and `css2?…wght@300;400;700` return 21 `@font-face`
  blocks over only **7 unique `.woff2` URLs** (one shared file per
  unicode-range subset, blocks in ascending weight order). The module dedupes
  faces **by src URL** in three places (`parse_font_faces()`,
  `scan()`'s union, `find_inlined_gstatic_faces()`), so the last block per
  URL — always the heaviest weight — is the only one that survives into the
  generated `fonts.css`. The stylesheet ends up declaring the family at 700
  only; the browser uses that sole face for all weights (VF instanced at the
  700 descriptor), body text renders bold, computed style still reports 400.
  Wrote `FONT_WEIGHT_FIX_PLAN.md` (root, `.distignore`d) specifying the fix.

- 2026-07-15 (font-weight-collapse fix implemented, → 1.7.1, same branch):
  implemented `FONT_WEIGHT_FIX_PLAN.md` in full.
  **F1 — identity-keyed dedupe** (`class-spfw-module-fonts.php`):
  `parse_font_faces()` now keys each parsed face on `sha1()` of its
  whitespace-normalized block text (added as `$face['key']`) instead of
  `$face['src_url']`, so every distinct weight/style/unicode-range block
  survives even when several share one `.woff2` URL; byte-identical blocks
  seen twice (e.g. captured via both the enqueue pipeline and an HTML regex
  pass) still collapse to one. `scan()`'s per-CSS-URL union and
  `find_inlined_gstatic_faces()` both switched from keying on `src_url` to
  keying on `key`.
  **F2 — per-scan download memoization**: faces now legitimately share a
  `src_url` (up to 3 weights per file for a typical VF family), so `scan()`
  gained a `$downloaded[ $src_url ]` memo keyed by URL — each unique
  `.woff2` is fetched from `fonts.gstatic.com` exactly once per scan
  regardless of how many faces reference it; `$files`/`families` still
  report one row per face (correct — same file, different weight labels).
  **F3 — stale-install remediation**: `fonts.needs_rescan` (bool, default
  false) added to the schema (`class-spfw-settings.php` defaults +
  `sanitize()` via `to_bool()`). New migration
  `run_font_rescan_migration()` fires once, when
  `version_compare($stored_ver, '1.7.1', '<')` and
  `fonts.discovered.css` is non-empty — flips `needs_rescan` true without
  touching the existing (still-serving) CSS, so nothing breaks mid-upgrade.
  `SPFW_Module_Fonts::finish_scan()` now always sets `needs_rescan = false`
  (found or empty result) since any scan under the fixed generator
  supersedes the stale marker. Surfaced two ways, mirroring
  `SPFW_Module_Hardening`'s missing/altered pattern: a dismiss-by-fixing
  `admin_notices` warning (`maybe_show_rescan_notice()`/
  `render_rescan_notice()`, gated `manage_options`, linking to the Fonts
  tab — note the link's `tab=fonts`/`tab=hardening` query arg is decorative
  only, since `App.jsx` doesn't read a tab param from the URL; this matches
  the pre-existing Hardening notice's same limitation, not a new one) and an
  amber banner at the top of `FontsSettings.jsx`'s card when
  `fonts.needs_rescan` is true.
  **F4**: bumped to 1.7.1 (plugin header + `SPFW_VERSION` +
  `readme.txt` stable tag/changelog).
  **Verified:** three scratch PHP harnesses (reflection + mocked
  `wp_remote_get`/temp-dir filesystem/stubbed `get_option`, not committed),
  built against the *real* Google Fonts API responses captured live this
  date (21 blocks / 7 URLs for `Roboto Condensed:300,400,700` on both v1 and
  v2 endpoints): (1) parser — all 21 blocks survive (vs. 7 under the old
  URL-keyed dedupe, confirmed by deliberately reproducing the old logic
  inline and showing it collapses to weight-700-only), every shared URL
  keeps all 3 weights, duplicate-content union still dedupes correctly,
  static per-weight-URL families unaffected, `find_inlined_gstatic_faces()`
  keeps all 21; (2) full `scan()` flow — exactly 7 network fetches for 21
  faces (memoization), exactly 7 files on disk, generated CSS has 7 blocks
  each at weight 300/400/700 with zero remaining `gstatic.com` references,
  `needs_rescan` clears; (3) migration — fresh installs and never-scanned
  1.7.0 installs stay `false`; a 1.7.0 install with existing `discovered.css`
  flips to `true` and it's actually persisted (re-fetched with cache
  cleared); a 1.7.1 install that's already been rescanned is left alone.
  `php -l` clean on all 3 changed PHP files; `npm install && npm run build`
  succeeds; `wp-scripts lint-js` clean on `FontsSettings.jsx` (the 2
  pre-existing `CoreSettings.jsx:476` a11y errors are untouched/out of
  scope, confirmed by a full `src/` lint pass). **Outstanding:** live
  QA on onedog.solutions (re-scan, confirm the banner/notice clear, confirm
  all three weights present in the served `fonts.css`, purge LSCache, and
  visually confirm footer/blog copy renders at the correct weight); `.pot`
  regeneration remains outstanding project-wide (unchanged backlog item).

- 2026-07-15 (release housekeeping): merged `claude/plugin-font-weight-issues-2xfjms`
  into `main` (fast-forward — no divergence). Produced a test ZIP
  (`simple-performance-for-wordpress-1.7.1.zip`, gitignored, not committed) from a
  fresh `npm run build`, staged and packaged per `.distignore` (verified `build/`
  present, all excluded dev paths absent, `php -l` clean on every staged PHP file,
  version header confirmed 1.7.1) for the user to install and QA on a live
  WordPress site.

- 2026-07-20 (Disable WP Sitemaps + Remove robots max-image-preview, → 1.8.0,
  branch `claude/wp-sitemaps-robots-toggles-eaoris`, merged to `main`): folded
  two single-hook standalone plugins ("Disable WP Sitemaps" 1.8.9 and "Disable
  WP Robots" 2.4) into the Core module as two new toggles rather than new
  modules — both map directly onto the existing boolean-setting → conditional-
  hook pattern. **Schema** (`class-spfw-settings.php`): added
  `core.disable_wp_sitemaps` and `core.remove_robots_max_image_preview` to
  `defaults()` (both `false`) and to the `$core_bools` sanitize list. No
  migration needed — additive boolean defaults merge in on every `get()` via
  `merge_recursive()`, so existing installs pick them up as OFF with no
  behavior change. **Behavior** (`class-spfw-module-core.php`): in `register()`,
  `disable_wp_sitemaps` → `add_filter( 'wp_sitemaps_enabled', '__return_false' )`
  (disables core `wp-sitemap.xml`); `remove_robots_max_image_preview` →
  `remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' )` (drops the
  `max-image-preview:large` directive from the robots meta tag). The
  `remove_filter` runs at `plugins_loaded`/`register()` time, before `wp_robots`
  fires in `wp_head`, matching the source plugin. **UI**
  (`CoreSettings.jsx`): two `toggleRow()` entries appended to the **Head
  Cleanup** card (robots meta + sitemap are both head/discovery output); no
  wiring changes since `App.jsx` already routes Core toggles through
  `handleChange('core', …)`. **Versioning**: bumped to 1.8.0 (plugin header +
  `SPFW_VERSION` + `readme.txt` stable tag, feature list, and `= 1.8.0 =`
  changelog). Only the trivial, un-copyrightable hook calls were reimplemented;
  the source plugins' GPL headers/readmes and their `disable-wp-sitemaps` /
  `disable-wp-robots` text domains were not carried over (new strings use this
  plugin's `simple-performance-for-wordpress` domain). **Verified:** `php -l`
  clean on both changed PHP files; `npm install && npm run build` succeeds and
  the minified `build/index.js` contains both new setting keys.
  **Outstanding:** live WordPress QA (see Next action) — the two runtime
  behaviors were not exercised in the build environment; `.pot` regeneration
  remains the unchanged project-wide backlog item.

- 2026-07-20 (release housekeeping): merged
  `claude/wp-sitemaps-robots-toggles-eaoris` into `main` (fast-forward — no
  divergence). Produced a test ZIP (`simple-performance-for-wordpress-1.8.0.zip`,
  gitignored, not committed) from a fresh `npm run build`, staged and packaged
  per `.distignore` (verified `build/` present, dev paths absent, `php -l` clean
  on staged PHP, version header 1.8.0) for the user to install and QA on a live
  WordPress site.

- 2026-07-22 (CSP policy builder coverage gaps, → 1.9.0, branch
  `claude/policy-builder-coverage-gaps-3dwztj`, merged to `main`): landed two
  coverage-gap fixes to the CSP policy builder that were developed in parallel
  and originally tagged 1.8.0 on their branch; since `main` had already shipped
  1.8.0 for the sitemaps/robots toggles, the work was re-versioned to **1.9.0**
  on merge (plugin header + `SPFW_VERSION` + `readme.txt` stable tag and
  changelog). **worker-src:** added a "Workers (Web / Service / Shared Workers)"
  directive row to the builder (`CspPolicyCard.jsx`), added `worker-src`
  to the managed-directive allowlist and to the recommended default policy
  (`class-spfw-settings.php` — `'self' blob:`), and to `DEFAULT_CSP`
  (`class-spfw-module-hardening.php`); `blob:` added as a preset chip on the
  Scripts and Styles rows. **Effective-directive collapse:** violations reported
  against the granular `script-src-elem`/`script-src-attr`/`style-src-elem`/
  `style-src-attr` fallbacks are now folded to their base `script-src`/
  `style-src` directive via a new `DIRECTIVE_ALIASES` map in
  `class-spfw-rest-settings.php`, so they group under (and can be "Allow"-ed
  from) the row the policy actually emits instead of the "other" bucket.
  **Verified:** `php -l` clean on all changed PHP; `npm run build` succeeds.
  **Outstanding:** `.pot` regeneration remains the standing backlog item; live
  WordPress QA of the new worker-src row and violation grouping not exercised in
  the build environment.

- 2026-07-22 (Beaver Builder settings-based font discovery, → 1.10.0, salvaged
  from `claude/branch-cleanup-state-ck3owq`, merged to `main`): during branch
  cleanup, the obsolete `branch-cleanup-state-ck3owq` (v1.2.0, 17 commits behind
  `main`; merging it wholesale would have reverted the plugin) was found to
  carry one genuinely unique, still-wanted feature not in `main`: discovering
  Google Fonts directly from Beaver Builder's stored settings instead of only
  from rendered HTML. Rather than merge the stale branch, the feature was
  **reimplemented on current `main`'s (much-evolved) fonts module**. The
  branch's rendered-page/multi-URL scanning was *not* ported — `main` already
  does that (and better) via its instrumented capture + `scan_targets()`
  sampling. Added to `class-spfw-module-fonts.php`: `beaver_builder_css_urls()`
  (reads `FLBuilderModel::get_global_settings()` + each `_fl_builder_enabled`
  post's `_fl_builder_data`), `find_font_fields()`/`flatten_settings()` (pull
  `*family`/`*weight` sibling pairs out of the layout node settings),
  `google_specs_to_urls()` (filter to real Google families, union weights, and
  reuse the existing `build_google_css_url()` spec builder), plus
  `beaver_builder_google_catalog()` and `family_is_google_font()` (allow-list via
  Beaver Builder's own `FLBuilderFontFamilies` catalog when present, else a
  system-font exclusion list). `scan()` merges these URLs alongside the manual
  and rendered-page URLs; all collectors no-op when Beaver Builder is inactive.
  **Versioning:** bumped 1.9.0 → 1.10.0 (new discovery source is a feature).
  **Verified:** `php -l` clean; `npm run build` succeeds (no JS changes — the
  new families surface through the existing Fonts UI). **Outstanding:** live
  WordPress + Beaver Builder QA (the FLBuilder integration was not exercised in
  the build environment); `.pot` regeneration remains the standing backlog item.

- 2026-07-22 (translation template regenerated, 1.10.0): cleared the
  long-standing `.pot` backlog item that every recent release entry had deferred.
  Ran `wp i18n make-pot . languages/simple-performance-for-wordpress.pot
  --slug=simple-performance-for-wordpress
  --domain=simple-performance-for-wordpress
  --exclude=node_modules,build,vendor,.git,tests` (WP-CLI 2.12.0, same generator
  as the prior file). Extracts all strings that had accumulated unextracted since
  the file was last generated at 1.0.0 — Step 10 (quick-toggle parity +
  WooCommerce), the font-weight and discovery fixes, the security-headers/HSTS/CSP
  work, the sitemaps/robots toggles, and the 1.9.0 `worker-src` row — growing the
  template from ~40 to **225 msgids** and bumping Project-Id-Version to 1.10.0.
  The 1.10.0 Beaver Builder discovery is backend-only and added no user-facing
  strings. No `.po`/`.mo` locale files exist yet, so nothing downstream needed
  reconciling. **Verified:** WP-CLI reported success; the new
  `Workers (Web / Service / Shared Workers)` string is present. A fresh release
  ZIP (`simple-performance-for-wordpress-1.10.0.zip`) was built afterwards for
  live install/QA.

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
