# Step 9 — Uninstall Cleanup

**Goal:** Leave nothing behind on uninstall: remove the single option, the authored
hardening `.htaccess`, and the localized fonts directory.

## Shared context
- Prefix `SPFW_`/`spfw_`. WPCS.
- Uninstall runs in a **restricted context** — WordPress loads `uninstall.php`
  directly, not the full plugin. Do **not** assume plugin classes are loaded; either
  guard `class_exists`/`require` what you need, or inline the logic.
- Single option: `spfw_settings`. Files: `WP_CONTENT_DIR.'/plugins/.htaccess'` (only
  if we authored it — sha1 matches stored hash), `uploads/ods-fonts/`.

## Deliverable
`uninstall.php` in the repo root.

### Requirements
1. Guard: `defined('WP_UNINSTALL_PLUGIN') || exit;`
2. **Read settings once** (`get_option('spfw_settings')`) to learn the stored
   `hardening.htaccess_hash`.
3. **Hardening file:** if `WP_CONTENT_DIR.'/plugins/.htaccess'` exists **and**
   `sha1_file()` equals the stored hash, delete it (via `WP_Filesystem` if you init
   it, else `wp_delete_file`). Never delete a foreign `.htaccess`.
4. **Fonts dir:** recursively delete `wp_upload_dir()['basedir'].'/ods-fonts/'` if it
   exists (files + directory). Use `WP_Filesystem` / `global $wp_filesystem` where
   possible; guard against a missing uploads dir.
5. **Delete the option:** `delete_option('spfw_settings')`. For multisite, loop sites
   (`get_sites`) and `delete_option` per site, plus `delete_site_option` if any
   network option were ever added (none in v1, but handle the multisite option loop
   for completeness).
6. No output. No fatals if files/dirs are already gone.

## Design constraints
- Idempotent and defensive: every filesystem/DB action guarded by an existence check.
- Reuse `SPFW_Htaccess`/`SPFW_Settings` only if you explicitly `require_once` them and
  they don't have side effects on load; otherwise inline the small logic to keep
  uninstall self-contained.
- Multisite: wrap option/file cleanup in a per-site loop when `is_multisite()`.

## Acceptance criteria
- After uninstall: `spfw_settings` is gone; `uploads/ods-fonts/` is gone; the
  authored plugins `.htaccess` is gone (a user-authored one is untouched).
- Running uninstall twice (or with files already absent) produces no errors.
- `php -l` clean, WPCS clean.
