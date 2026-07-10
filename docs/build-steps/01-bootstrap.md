# Step 1 — Bootstrap File

**Goal:** Create the main plugin entry file, define constants, wire the (not-yet-
existing) core loader, and register activation/deactivation hooks. This is the only
file WordPress loads directly; everything else is required from here.

## Shared context
- Plugin: **Simple Performance for WordPress**, slug/text-domain
  `simple-performance-for-wordpress`, prefix `SPFW_`/`spfw_`.
- Author: Ryan Waterbury — One Dog Solutions (https://onedog.solutions/). GPL-3.0-or-later.
- Min WP 6.0, Min PHP 7.4. Every PHP file starts with an `ABSPATH` guard.
- Single autoloaded option `spfw_settings` is the only DB footprint.

## Deliverable
`simple-performance-for-wordpress.php` in the repo root.

### Requirements
1. **Plugin header** with Name, Description (one line), Version `1.0.0`, Author +
   Author URI, License `GPL-3.0-or-later`, License URI, Text Domain, Requires at
   least `6.0`, Requires PHP `7.4`.
2. `defined('ABSPATH') || exit;` guard.
3. **Constants:**
   - `SPFW_VERSION` = `'1.0.0'`
   - `SPFW_FILE` = `__FILE__`
   - `SPFW_PATH` = `plugin_dir_path( __FILE__ )`
   - `SPFW_URL`  = `plugin_dir_url( __FILE__ )`
   - `SPFW_BASENAME` = `plugin_basename( __FILE__ )`
4. **Load text domain** on `init` (`load_plugin_textdomain`) from `/languages`.
5. **Require + boot the core loader** (created in Step 3):
   `require_once SPFW_PATH . 'includes/class-spfw-plugin.php';` then instantiate via
   its singleton accessor on `plugins_loaded`. Since Step 3 doesn't exist yet, guard
   with `if ( file_exists(...) )` so this file is testable in isolation, OR stub the
   require and leave a clearly-marked `// TODO: added in Step 3`. Prefer the
   file_exists guard.
6. **Activation hook** (`register_activation_hook`): call a static
   `SPFW_Plugin::activate()` (stub-safe — guard as above). Its job later: seed default
   options if the option is absent, and (Step 7) write the hardening `.htaccess` if
   that setting defaults on. For now it just ensures `spfw_settings` exists.
7. **Deactivation hook** (`register_deactivation_hook`): call
   `SPFW_Plugin::deactivate()` (stub-safe). Later removes the hardening `.htaccess`
   if authored. For now, no-op.
8. **Do NOT** delete options on deactivation (that's uninstall's job, Step 9).

## Acceptance criteria
- Plugin activates and deactivates in a WP install with no PHP notices/warnings.
- Constants resolve correctly (verify by logging or a temporary admin notice).
- `spfw_settings` option exists after activation (once Step 2/3 land; for now the
  activation callback can be a safe stub).
- File passes `php -l` and WPCS sniff for the header block.
