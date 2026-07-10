# Step 2 — Settings Layer

**Goal:** One class that owns the single `spfw_settings` option: hardcoded defaults,
a statically-cached `get()` (guarantees **1 DB query per request**), targeted
accessors, and a strict `sanitize()` for saves.

## Shared context
- Single autoloaded option: `spfw_settings`. No other DB footprint.
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard at top of file. WPCS.
- This class is used by every module and by the admin save handler.

## Deliverable
`includes/class-spfw-settings.php` → class `SPFW_Settings`.

### Defaults (the canonical schema)
```php
private static function defaults() {
  return [
    'version' => SPFW_VERSION,
    'core' => [
      'disable_emojis'         => true,
      'disable_embeds'         => true,
      'disable_dashicons'      => true,   // front-end, logged-out only
      'disable_xmlrpc'         => true,
      'remove_rsd'             => true,
      'remove_wlwmanifest'     => true,
      'disable_feeds'          => false,
      'feed_redirect_home'     => true,
      'remove_query_strings'   => false,
      'heartbeat_mode'         => 'modify', // default|modify|disable
      'heartbeat_interval'     => 60,       // 15..300
      'disable_jquery_migrate' => true,
    ],
    'restapi' => [
      'require_auth'        => false,
      'disabled_namespaces' => [ 'wp/v2/users', 'wp/v2/themes' ],
      'whitelist_routes'    => [ 'contact-form-7/v1', 'wc/v3', 'wc/store' ],
    ],
    'hardening' => [
      'plugins_htaccess' => false,
      'htaccess_hash'    => '',
    ],
    'fonts' => [
      'localize_google' => false,
      'discovered'      => [],
      'last_scan'       => 0,
    ],
  ];
}
```

### Public API
- `SPFW_Settings::get()` → full settings array. **Static-cache in a `private static
  $cache` property**; call `get_option('spfw_settings', [])` **once**, then
  `wp_parse_args`-merge (recursively for the nested groups) against `defaults()`.
  Subsequent calls return the cached copy — never a second `get_option`.
- `SPFW_Settings::group( $key )` → returns one of `core|restapi|hardening|fonts`.
- `SPFW_Settings::value( $group, $key, $fallback = null )` → convenience getter.
- `SPFW_Settings::update( array $new )` → sanitize + `update_option` +
  **invalidate the static cache** (reset `$cache = null`). Returns bool.
- `SPFW_Settings::sanitize( array $input )` → returns a clean array shaped like
  defaults:
  - Cast every boolean toggle with a strict truthiness helper.
  - `heartbeat_mode` ∈ {default,modify,disable} (whitelist, else 'modify').
  - `heartbeat_interval` = `absint`, clamped 15..300.
  - `disabled_namespaces` / `whitelist_routes`: explode/trim, `sanitize_text_field`
    each entry, strip leading/trailing slashes, drop empties, `array_values` +
    `array_unique`. Allow only chars `[A-Za-z0-9/_.-]`.
  - `htaccess_hash`: `sanitize_text_field` (40-char sha1 or empty).
  - `discovered`: pass through a dedicated validator (keep structure minimal; full
    validation lands with Step 8 — for now accept array or reset to `[]`).
  - `last_scan`: `absint`.
  - Always stamp `version` = `SPFW_VERSION`.

### Notes for the implementing session
- Recursive merge matters: a saved partial `core` array must not drop unset keys.
  Implement a small `wp_parse_args`-per-group merge rather than a single top-level
  call.
- Never write during `get()`. Seeding defaults happens only in activation (Step 1/3).

## Acceptance criteria
- Calling `get()` N times issues exactly **one** `get_option` (verify with a query
  counter or `add_filter('pre_option_spfw_settings', ...)` spy).
- `sanitize()` rejects out-of-range heartbeat intervals and illegal namespace chars.
- `update()` followed by `get()` reflects new values without a stale cache.
- `php -l` clean, WPCS clean.

## Final step (required)
Before ending the session, update `../../STATE.md` per its "Update protocol":
flip this step's row to ✅ (or 🟡 if paused), set the commit hash, refresh Overall
status / Last updated / Next action, and log any deviations. Commit STATE.md **in
the same commit** as this step's code, then push to the branch.
