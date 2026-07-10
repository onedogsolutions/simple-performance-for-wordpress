# Step 6 — Module 2: Advanced REST API Controls

**Goal:** Whitelist-first REST gating: global auth requirement, per-namespace
disabling (fully unregister to kill enumeration), and an essential-plugin whitelist.
Plus the REST API admin tab (a React component — the admin UI is React/Tailwind
per Step 5, not PHP form views).

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS.
- Depends on Steps 2, 3, 5. Settings under the `restapi` group (see `02-settings.md`):
  `require_auth` (bool), `disabled_namespaces` (string[]), `whitelist_routes`
  (string[]).
- **Anti-enumeration principle:** disabled endpoints return **404**, not 403 — no
  signal that the endpoint exists.
- **Never block the plugin's own settings API:** `route_in_list()` (below) must
  treat `spfw/v1` as always-whitelisted, in addition to whatever's in
  `whitelist_routes` — this is what keeps the Settings screen itself usable even
  with `require_auth` or namespace-disabling turned on (see Step 5's
  self-whitelisting note).

## Deliverables
1. `includes/modules/class-spfw-module-restapi.php` →
   `class SPFW_Module_RestApi implements SPFW_Module`.
2. `src/components/RestApiSettings.jsx` (React tab component — replaces the old
   `admin/views/tab-restapi.php` PHP-view plan from before the React pivot). Wire
   it into `src/components/App.jsx`'s `restapi` tab slot.

### Runtime logic (`register()`)
Read `$r = SPFW_Settings::group('restapi')`.

**A. Unregister disabled namespaces** (strongest measure) — only if
`disabled_namespaces` non-empty:
```
add_filter('rest_endpoints', function($endpoints) use ($r) {
  foreach ($endpoints as $route => $handler) {
    $norm = ltrim($route, '/');                       // e.g. 'wp/v2/users'
    foreach ($r['disabled_namespaces'] as $ns) {
      if ($norm === $ns || strpos($norm, $ns . '/') === 0) {
        // don't unregister if this route is whitelisted
        if ( ! route_in_list($norm, $r['whitelist_routes']) ) {
          unset($endpoints[$route]);
        }
      }
    }
  }
  return $endpoints;
});
```
This removes them from the `/wp-json/` index entirely.

**B. Global auth gate + belt-and-suspenders 404** — only if `require_auth` OR
`disabled_namespaces` non-empty:
```
add_filter('rest_authentication_errors', function($result) use ($r) {
  if (is_wp_error($result)) return $result;           // respect prior errors
  $route = ltrim($GLOBALS['wp']->query_vars['rest_route'] ?? '', '/'); // current route
  if (route_in_list($route, $r['whitelist_routes'])) return $result; // always allow
  if ($r['require_auth'] && ! is_user_logged_in()) {
    return new WP_Error('spfw_rest_forbidden',
      __('REST API restricted to authenticated users.', ...),
      ['status' => 401]);
  }
  if (route_in_list($route, $r['disabled_namespaces'])
      && ! current_user_can('manage_options')) {
    return new WP_Error('rest_no_route', __('No route was found matching the URL.', ...),
      ['status' => 404]);
  }
  return $result;
});
```

**Helper** `route_in_list($route, $list)`: true if `$route` equals or is prefixed
by any `"$item"` / `"$item/"` in `$list`, **or** if `$route` is `spfw/v1` / starts
with `spfw/v1/` (hardcoded, always-on — see Shared context above). Keep it a
private method; guard against empty list.

### `RestApiSettings.jsx`
Props: `{ settings, onChange }` (same contract as `CoreSettings.jsx` from Step 5;
`onChange(key, value)` calls the parent's `handleChange('restapi', key, value)`).
- **Require auth** toggle → `settings.restapi.require_auth`.
- **Disable namespaces**: fetch the live namespace list once on mount via
  `apiFetch({ path: '/' })` (the WP-JSON index response includes a `namespaces`
  array) so admins pick real namespaces (`wp/v2`, `oembed/1.0`, plugin
  namespaces...) as checkboxes, plus a free-text `<textarea>` (newline-separated)
  for advanced per-route prefixes (`wp/v2/users`, `wp/v2/themes`). Both feed the
  same flat `disabled_namespaces` array.
- **Whitelist**: `<textarea>` (newline-separated) for `whitelist_routes`,
  pre-seeded placeholder text showing the CF7/WooCommerce examples; help text
  explaining these bypass both the auth gate and namespace disabling, and that
  the plugin's own settings API (`spfw/v1`) is always exempt automatically.
- Sanitization happens server-side in `SPFW_Settings::sanitize()` on save (Step
  2) — the component doesn't need its own validation beyond basic textarea→array
  splitting for display/round-trip.

## Design constraints
- Whitelist is checked **before** every restriction in both filters A and B.
- Never break `wp-admin` internal REST calls: logged-in `manage_options` users are
  exempt from the namespace 404 (as coded); the auth gate naturally passes for
  logged-in users.
- Do not touch `rest_authentication_errors` at all when both `require_auth` is false
  and `disabled_namespaces` is empty (zero overhead).
- `spfw/v1` (this plugin's own settings API) must never be blockable by these
  filters, regardless of user configuration — hardcode it in `route_in_list()`
  rather than relying on the user having it in their whitelist textarea.

## Acceptance criteria
- With defaults: `/wp-json/` index does **not** list `wp/v2/users` or
  `wp/v2/themes`; hitting `/wp-json/wp/v2/users` as an anon user returns 404.
- Enabling `require_auth`: anon `/wp-json/wp/v2/posts` → 401, but a whitelisted
  route (e.g. `contact-form-7/v1`) still works.
- A logged-in admin can still reach disabled namespaces (block/site editors keep
  working).
- `php -l` clean, WPCS clean.

## Final step (required)
Before ending the session, update `../../STATE.md` per its "Update protocol":
flip this step's row to ✅ (or 🟡 if paused), set the commit hash, refresh Overall
status / Last updated / Next action, and log any deviations. Commit STATE.md **in
the same commit** as this step's code, then push to the branch.
