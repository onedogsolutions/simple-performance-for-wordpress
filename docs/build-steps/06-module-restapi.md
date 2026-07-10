# Step 6 — Module 2: Advanced REST API Controls

**Goal:** Whitelist-first REST gating: global auth requirement, per-namespace
disabling (fully unregister to kill enumeration), and an essential-plugin whitelist.
Plus the REST API admin tab.

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS.
- Depends on Steps 2, 3, 5. Settings under the `restapi` group (see `02-settings.md`):
  `require_auth` (bool), `disabled_namespaces` (string[]), `whitelist_routes`
  (string[]).
- **Anti-enumeration principle:** disabled endpoints return **404**, not 403 — no
  signal that the endpoint exists.

## Deliverables
1. `includes/modules/class-spfw-module-restapi.php` →
   `class SPFW_Module_RestApi implements SPFW_Module`.
2. `admin/views/tab-restapi.php`.

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
        if ( ! spfw_route_in_list($norm, $r['whitelist_routes']) ) {
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
  if (spfw_route_in_list($route, $r['whitelist_routes'])) return $result; // always allow
  if ($r['require_auth'] && ! is_user_logged_in()) {
    return new WP_Error('spfw_rest_forbidden',
      __('REST API restricted to authenticated users.', ...),
      ['status' => 401]);
  }
  if (spfw_route_in_list($route, $r['disabled_namespaces'])
      && ! current_user_can('manage_options')) {
    return new WP_Error('rest_no_route', __('No route was found matching the URL.', ...),
      ['status' => 404]);
  }
  return $result;
});
```

**Helper** `spfw_route_in_list($route, $list)`: true if `$route` equals or is
prefixed by any `"$item"` / `"$item/"` in `$list`. Keep it a private method or a
prefixed function; guard against empty list.

### `tab-restapi.php`
- **Require auth** checkbox → `spfw[restapi][require_auth]`.
- **Disable namespaces**: build the checkbox list from **live data** —
  `rest_get_server()->get_namespaces()` — so admins pick real namespaces
  (`wp/v2`, `oembed/1.0`, plugin namespaces...). Also allow finer per-route entries
  via a textarea for advanced prefixes (`wp/v2/users`, `wp/v2/themes`). Persist as
  the flat `disabled_namespaces` array. Field: `spfw[restapi][disabled_namespaces]`
  (textarea, newline-separated) plus checkbox convenience that populates it.
- **Whitelist**: textarea `spfw[restapi][whitelist_routes]` (newline-separated),
  pre-seeded with CF7/WooCommerce examples; help text explaining these bypass both
  the auth gate and namespace disabling.
- Escape all output; the save handler (Step 5) already sanitizes via
  `SPFW_Settings::sanitize()` (which trims/validates these arrays).

## Design constraints
- Whitelist is checked **before** every restriction in both filters A and B.
- Never break `wp-admin` internal REST calls: logged-in `manage_options` users are
  exempt from the namespace 404 (as coded); the auth gate naturally passes for
  logged-in users.
- Do not touch `rest_authentication_errors` at all when both `require_auth` is false
  and `disabled_namespaces` is empty (zero overhead).

## Acceptance criteria
- With defaults: `/wp-json/` index does **not** list `wp/v2/users` or
  `wp/v2/themes`; hitting `/wp-json/wp/v2/users` as an anon user returns 404.
- Enabling `require_auth`: anon `/wp-json/wp/v2/posts` → 401, but a whitelisted
  route (e.g. `contact-form-7/v1`) still works.
- A logged-in admin can still reach disabled namespaces (block/site editors keep
  working).
- `php -l` clean, WPCS clean.
