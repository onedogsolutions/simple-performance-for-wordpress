# Step 5 — Admin Skeleton (React + Tailwind v4 + REST)

**Goal:** The settings screen, matching the sister plugin
`onedogsolutions/google-security-for-wordpress`'s architecture exactly: a single
React app (`@wordpress/element` — vanilla JS/React, **no jQuery**) built with
`@wordpress/scripts` and Tailwind v4, mounted into a root `<div>`, talking to one
REST endpoint for settings. This step ships the build tooling, the REST
controller, the admin PHP shell, and the **Core** tab's React component. Later
steps add their own tab components (no further PHP admin work needed).

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS.
- Depends on Step 2 (`SPFW_Settings`) and Step 3 (loader).
- **No jQuery anywhere.** Use `@wordpress/element` (React), `@wordpress/i18n`,
  `@wordpress/api-fetch` — all bundled with WordPress core as `wp-element`,
  `wp-i18n`, `wp-api-fetch`, so no separate React/npm runtime ships to the
  browser beyond our own compiled bundle.
- Capability: `manage_options` on both the admin page and the REST routes.
- `build/` is gitignored (like the sister plugin) — contributors run
  `npm install && npm run build` before the admin UI works. Document this in the
  plugin's README/CLAUDE.md.

## Deliverables

### 1. Build tooling (repo root)
- `package.json`: name `simple-performance-for-wordpress`, scripts
  `start`/`build` → `wp-scripts start`/`wp-scripts build`, `lint:js`/`lint:css` →
  `wp-scripts lint-js`/`lint-style`. `devDependencies`: `@wordpress/scripts`,
  `tailwindcss` (^4), `@tailwindcss/postcss` (^4), `postcss`, `autoprefixer`.
- `webpack.config.js`: `module.exports = { ...require('@wordpress/scripts/config/webpack.config') };`
- `postcss.config.js`: `{ plugins: { '@tailwindcss/postcss': {}, autoprefixer: {} } }`
- Add `node_modules/` and `build/` to `.gitignore` (already partially covered —
  confirm both are present).

### 2. React source (`src/`)
- `src/index.js`: import `render` from `@wordpress/element`, import `App` from
  `./components/App`, import `./styles/index.css`; mount into
  `document.getElementById('spfw-admin-root')` if present.
- `src/styles/index.css`: `@import "tailwindcss";` plus a `@layer base` block
  scoped under a `.spfw-admin-isolated` class (matches the sister plugin's
  isolation pattern — wp-admin's own unlayered styles otherwise beat Tailwind's
  layered ones for things like link colors, so any such override must live
  **outside** any `@layer`).
- `src/components/App.jsx`: top-level component.
  - Bootstraps from `window.spfwAdminData` (set via `wp_localize_script`):
    `{ restUrl, nonce, settings }` where `settings` is the full
    `SPFW_Settings::get()` array (nested `core`/`restapi`/`hardening`/`fonts`
    groups) — pass it as initial React state.
  - On mount: register the REST nonce via
    `apiFetch.use(apiFetch.createNonceMiddleware(nonce))`, then
    `apiFetch({ path: '/spfw/v1/settings' })` to refresh from the DB.
  - Local state: `settings` (nested object matching the schema), `isSaving`,
    `toast`.
  - `handleChange(group, key, value)` → updates `settings[group][key]` immutably.
  - `handleSave(e)`: `e.preventDefault()`, POST the full `settings` object to
    `/spfw/v1/settings`, update state from the response, show a success/error
    toast (auto-dismiss ~4s).
  - Tabs: `core` (Step 4 done — ships now), `restapi`, `hardening`, `fonts`
    (Steps 6–8 — render a "coming soon" placeholder tab body until each step's
    component exists; **do not** reference components that don't exist yet).
  - Visual conventions (match the sister plugin, i.e. Tailwind defaults, no
    custom design tokens beyond what's already in its `@theme`): `mx-auto
    max-w-5xl px-4 py-8` page container; header `text-2xl font-bold
    text-gray-900` + subtitle `text-sm text-gray-500`; primary button
    `rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white
    shadow-sm hover:bg-indigo-500`; toast `fixed bottom-5 right-5 rounded-lg
    shadow-lg border animate-slideIn`.
- `src/components/SettingsTabs.jsx`: generic `role="tablist"`/`tabpanel`
  component with arrow-key/Home/End navigation (copy the sister plugin's
  `SettingsTabs.jsx` pattern almost verbatim — it's UI-agnostic to the
  settings shape).
- `src/components/CoreSettings.jsx`: renders every `core` setting from Step 4's
  schema — checkboxes (styled toggle switches or plain checkboxes, consistent
  with the sister plugin's form controls) for the booleans, a `<select>` for
  `heartbeat_mode`, a number input (min 15, max 300) for `heartbeat_interval`
  shown only when `heartbeat_mode === 'modify'`. Inline help text per control;
  explicit note next to "Remove query strings" that it's low-value under
  LiteSpeed Cache. Props: `{ settings, onChange }` where `onChange(key, value)`
  calls the parent's `handleChange('core', key, value)`.

### 3. REST controller (always loaded, not admin-gated)
`includes/class-spfw-rest-settings.php` → `class SPFW_Rest_Settings`.
- Constructor: `add_action('rest_api_init', [$this, 'register_routes'])`.
- Route `spfw/v1/settings`:
  - `GET` (`WP_REST_Server::READABLE`) → callback returns
    `new WP_REST_Response(SPFW_Settings::get(), 200)`.
  - `POST` (`WP_REST_Server::CREATABLE`) → callback reads
    `$request->get_json_params()`, calls `SPFW_Settings::update($params)` (which
    sanitizes internally — Step 2), returns the fresh `SPFW_Settings::get()`.
  - `permission_callback` for both: `fn() => current_user_can('manage_options')`.
- **Must load unconditionally** (like the sister plugin's `GSWP_Rest_Api`): REST
  requests are not admin context (`is_admin()` is false for `/wp-json/`), so
  `rest_api_init` never fires if this class is only required inside the
  `is_admin()` branch. Require + instantiate it directly in
  `SPFW_Plugin::boot()` (Step 3), alongside `SPFW_Settings`/the interface — not
  through the toggleable `MODULES` list and not admin-gated.
- **Self-whitelisting note for Step 6:** Module 2's REST-restriction filters
  must never block `spfw/v1` itself (the settings screen has to keep working
  even with "require auth" or namespace-disabling toggles on). Since our route
  always requires `manage_options` via its own `permission_callback` and the
  admin screen is only used by logged-in admins, this is naturally safe — but
  Step 6 should still treat `spfw/v1` as implicitly whitelisted in its route-
  matching helper, documented explicitly rather than left implicit.

### 4. Admin PHP shell
`admin/class-spfw-admin.php` → `class SPFW_Admin` (loaded only in `is_admin()`,
per Step 3).
- `admin_menu` → `add_options_page('Simple Performance', 'Simple Performance',
  'manage_options', 'spfw-settings', [$this, 'render_page'])`.
- `render_page()`: capability check, then just the mount point:
  `<div class="wrap"><div id="spfw-admin-root" class="spfw-admin-isolated">
  <p class="description">Loading…</p></div></div>` — **no PHP form markup, no
  tabs, no save handler in PHP.** All UI and persistence is the React app +
  REST controller.
- `admin_enqueue_scripts`: bail unless `$hook === 'settings_page_spfw-settings'`.
  Read `build/index.asset.php` (produced by `wp-scripts build`) for the
  dependency array + version (fallback to `['wp-element','wp-api-fetch','wp-i18n']`
  and `SPFW_VERSION` if the asset file is missing — i.e. before `npm run build`
  has been run). `wp_enqueue_script('spfw-admin-js', SPFW_URL .
  'build/index.js', $deps, $version, true)`; `wp_enqueue_style('spfw-admin-css',
  SPFW_URL . 'build/index.css', [], $version)`.
- `wp_localize_script('spfw-admin-js', 'spfwAdminData', ['restUrl' =>
  esc_url_raw(rest_url('spfw/v1')), 'nonce' => wp_create_nonce('wp_rest'),
  'settings' => SPFW_Settings::get()])`.

## Design constraints
- No jQuery, no other JS framework — `@wordpress/element` only.
- No inline `<script>` blocks beyond the one `wp_localize_script` object;
  everything else lives in the compiled bundle.
- The React app must render usefully even before Steps 6–8 exist: only wire a
  tab's real component in `App.jsx` once that step's file exists; use a
  placeholder body otherwise so partial builds don't reference missing modules.
- Tailwind styling stays scoped under `.spfw-admin-isolated` so it can't leak
  into the rest of wp-admin, and wp-admin's own cascade can't clobber it either
  (see the `@layer` note above).

## Acceptance criteria
- `npm install && npm run build` completes and produces `build/index.js` +
  `build/index.css` + `build/index.asset.php`.
- Page appears under **Settings → Simple Performance**; only `manage_options`
  users reach it; assets enqueue only on that screen.
- On load, the Core tab reflects `SPFW_Settings::get()`'s current values (via
  either the localized bootstrap data or the REST `GET`).
- Toggling a Core setting and clicking Save persists it (`GET
  /wp-json/spfw/v1/settings` reflects the change after save) and shows a
  success toast; a failed save shows an error toast.
- Anonymous / non-`manage_options` requests to `GET`/`POST
  /wp-json/spfw/v1/settings` are rejected (403).
- `php -l` clean on all PHP; `npm run lint:js`/`lint:css` clean (or only
  pre-existing sister-plugin-style warnings).

## Final step (required)
Before ending the session, update `../../STATE.md` per its "Update protocol":
flip this step's row to ✅ (or 🟡 if paused), set the commit hash, refresh Overall
status / Last updated / Next action, and log any deviations. Commit STATE.md **in
the same commit** as this step's code, then push to the branch.
