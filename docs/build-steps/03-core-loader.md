# Step 3 — Core Loader & Module Interface

**Goal:** The dispatcher. A singleton that loads settings once, instantiates each
enabled module, calls its `register()`, and loads the admin layer only inside
`is_admin()`. Also owns `activate()`/`deactivate()` referenced by Step 1.

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS. Min PHP 7.4.
- Depends on Step 2 (`SPFW_Settings`). Modules land in Steps 4/6/7/8.
- `includes/` loads on every request; `admin/` must load **only** in admin.

## Deliverables
1. `includes/interface-spfw-module.php` → interface `SPFW_Module`:
   ```php
   interface SPFW_Module {
     // Read pre-loaded settings and attach WP hooks. No DB work here.
     public function register(): void;
   }
   ```
2. `includes/class-spfw-plugin.php` → class `SPFW_Plugin`:
   - **Singleton:** `public static function instance()` returns the one instance;
     private constructor.
   - `boot()` (called from the main file on `plugins_loaded`):
     - Require the interface + all existing module files under
       `includes/modules/`. Use a small `require_once` list (explicit, not glob, so
       load order is deterministic and missing files fail loudly during dev).
     - Build the module registry: instantiate each module class that exists, call
       `->register()` on each. Wrap in `if ( class_exists(...) )` so partial builds
       (before Steps 6/7/8) still boot.
     - If `is_admin()`, `require_once SPFW_PATH . 'admin/class-spfw-admin.php'` and
       instantiate `SPFW_Admin` (guard with `class_exists`/`file_exists` until Step 5).
   - **Static `activate()`:** if `get_option('spfw_settings')` is absent, seed it via
     `SPFW_Settings::update( [] )` (which sanitizes defaults into place). Leave a
     documented hook for Step 7 to write the hardening `.htaccess` on activation when
     its default is on (default is off, so no-op for now).
   - **Static `deactivate()`:** no-op now; Step 7 adds `.htaccess` teardown.
3. Update Step 1's main file to actually require/boot this (remove the file_exists
   stub once this exists).

### Module registry (explicit list to grow across steps)
```php
$modules = [
  'SPFW_Module_Core',       // Step 4
  'SPFW_Module_RestApi',    // Step 6
  'SPFW_Module_Hardening',  // Step 7
  'SPFW_Module_Fonts',      // Step 8
];
foreach ( $modules as $class ) {
  if ( class_exists( $class ) ) { ( new $class() )->register(); }
}
```

## Design constraints
- Modules must **not** call `get_option` themselves — pass `SPFW_Settings::group()`
  results in, or let each module call the static cached getter (still 1 query total).
- No hooks are attached for a feature whose toggle is off — each module checks its
  settings inside `register()` and only then `add_action`/`add_filter`.

## Acceptance criteria
- Fresh activation seeds `spfw_settings` exactly once with sanitized defaults.
- On a frontend request, the admin class is **never** required (confirm via
  `get_included_files()` or a debug log).
- Booting with only Step 4's module present does not fatal (guards work).
- `php -l` clean, WPCS clean.
