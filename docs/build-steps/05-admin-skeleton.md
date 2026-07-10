# Step 5 — Admin Skeleton

**Goal:** The settings screen: a single top-level (or under Settings) page with
tabbed navigation, a nonce+capability-guarded save handler, and screen-scoped asset
enqueue. This step ships the **Core** tab; later steps add their own tab views.

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS.
- Loaded **only** in `is_admin()` (Step 3 requires this class conditionally).
- All saves go through `SPFW_Settings::update()` (Step 2), which sanitizes.
- Capability: `manage_options`. Nonce on every write.

## Deliverables
1. `admin/class-spfw-admin.php` → `class SPFW_Admin`.
2. `admin/views/tab-core.php` (Core toggles form partial).
3. `admin/assets/admin.css`, `admin/assets/admin.js`.

### `SPFW_Admin` responsibilities
- Constructor attaches: `admin_menu`, `admin_init` (register settings/save),
  `admin_enqueue_scripts`.
- **Menu:** `add_options_page` (under Settings) titled "Simple Performance", slug
  `spfw-settings`, capability `manage_options`, callback `render_page()`.
- **Tabs:** define a tab registry
  `[ 'core'=>'Core', 'restapi'=>'REST API', 'hardening'=>'Hardening',
  'fonts'=>'Fonts' ]`. Current tab from `$_GET['tab']` (sanitized, whitelisted,
  default `core`). Render `<h2 class="nav-tab-wrapper">` links.
- **render_page():** capability check → `settings_errors()` → tab nav → `include`
  the matching `admin/views/tab-{tab}.php`. Only include a view file that exists
  (so partial builds don't fatal). Wrap the form:
  `<form method="post" action="">` + `wp_nonce_field('spfw_save','spfw_nonce')` +
  a hidden `tab` field + `submit_button()`.
- **Save handler** (on `admin_init`, or `admin_post_spfw_save`): verify
  `current_user_can('manage_options')` and `check_admin_referer('spfw_save',
  'spfw_nonce')`. Read the submitted group(s) from `$_POST`, merge into the current
  settings array by group, call `SPFW_Settings::update()`. Then
  `add_settings_error('spfw','saved','Settings saved.','updated')` and redirect back
  to the same tab (PRG pattern) to avoid resubmission. **Design the POST field names
  namespaced** (e.g. `spfw[core][disable_emojis]`) so one handler serves all tabs.
- **Enqueue:** `admin_enqueue_scripts` — bail unless
  `$hook === 'settings_page_spfw-settings'`. Enqueue `admin.css`/`admin.js` from
  `SPFW_URL . 'admin/assets/...'`, versioned `SPFW_VERSION`. Localize a nonce +
  ajaxurl for Step 8's font scan (define the handle now, reuse later).

### `tab-core.php`
- Render one control per `core` setting from `SPFW_Settings::group('core')`:
  checkboxes for booleans, a `<select>` for `heartbeat_mode`, a number input for
  `heartbeat_interval` (min 15 max 300, shown only when mode=modify — JS toggles
  visibility). Field names use the `spfw[core][...]` convention. Escape all output;
  `checked()`/`selected()` helpers for state.
- Short inline help text per group; note the LSCache caveat next to "Remove query
  strings".

### assets
- `admin.js`: tab-aware show/hide for the heartbeat interval field; placeholder
  namespace for the Step 8 "Scan fonts" button handler.
- `admin.css`: light layout only (section spacing, help-text muted). No framework.

## Acceptance criteria
- Page appears under **Settings → Simple Performance**; only `manage_options` users
  reach it.
- Saving the Core tab persists sanitized values and shows the success notice; reload
  reflects saved state; nonce failure is rejected.
- Assets load **only** on our screen (verify no enqueue elsewhere).
- Visiting `?tab=restapi` before Step 6 exists renders the nav without fataling
  (missing view handled gracefully).
- `php -l` clean, WPCS clean.

## Final step (required)
Before ending the session, update `../../STATE.md` per its "Update protocol":
flip this step's row to ✅ (or 🟡 if paused), set the commit hash, refresh Overall
status / Last updated / Next action, and log any deviations. Commit STATE.md **in
the same commit** as this step's code, then push to the branch.
