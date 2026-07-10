# Step 7 — Module 3: Directory-Level Security Hardening

**Goal:** Programmatically drop a deny-PHP `.htaccess` into `/wp-content/plugins/`,
verify its integrity, and surface an admin notice (with one-click restore) if it's
missing or altered. Plus the Hardening tab. Includes OLS-specific UI guidance.

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS.
- Depends on Steps 2, 3, 5. Settings under `hardening`: `plugins_htaccess` (bool),
  `htaccess_hash` (sha1 of last-authored file, or '').
- **Target stack OLS:** OpenLiteSpeed reads `.htaccess` **only** when "Allow
  Override" is enabled at the vhost/server level. The write still happens; the UI
  must explain the override requirement. The rule is fail-safe (only adds
  restriction), never fail-open.

## Deliverables
1. `includes/class-spfw-htaccess.php` → `class SPFW_Htaccess` (shared file utility).
2. `includes/modules/class-spfw-module-hardening.php` →
   `class SPFW_Module_Hardening implements SPFW_Module`.
3. `admin/views/tab-hardening.php`.
4. Wire `SPFW_Plugin::activate()`/`deactivate()` (Step 3) to write/remove the file.

### The file payload
Target path: `WP_CONTENT_DIR . '/plugins/.htaccess'`. Contents:
```apache
# BEGIN Simple Performance for WordPress
# Block direct PHP execution in the plugins directory (Apache / OLS-with-override).
<Files *.php>
  Require all denied
</Files>
# Fallback for older Apache:
<IfModule !mod_authz_core.c>
  <Files *.php>
    Order allow,deny
    Deny from all
  </Files>
</IfModule>
# END Simple Performance for WordPress
```

### `SPFW_Htaccess` (utility)
- `path()` → the plugins `.htaccess` path.
- `payload()` → the exact string above (single source of truth).
- `write()` → use **`WP_Filesystem`** (init via
  `request_filesystem_credentials`/`WP_Filesystem()`), write the payload at 0644.
  Returns bool. On success, store `sha1( payload() )` into settings
  (`hardening.htaccess_hash`) via `SPFW_Settings::update()`.
- `remove()` → delete the file **only if** it exists and `sha1_file()` matches the
  stored hash (i.e., we authored it and it's unaltered). Clear the stored hash.
- `status()` → returns one of `ok | missing | altered | disabled`:
  - `disabled` if `plugins_htaccess` is off.
  - `missing` if enabled but file absent.
  - `altered` if enabled, file present, but `sha1_file()` ≠ stored hash.
  - `ok` otherwise.

### `SPFW_Module_Hardening::register()`
- On `admin_init` (admin only): compute `status()`.
  - If `missing` or `altered` → register an `admin_notices` warning:
    "The plugins-directory hardening file is {missing/modified}. [Restore now]"
    where Restore is a nonce-protected link to `admin_post_spfw_restore_htaccess`.
  - Handler `admin_post_spfw_restore_htaccess`: cap check + nonce, call
    `SPFW_Htaccess::write()`, redirect back with a settings notice.
- When the setting is toggled **on** (detected in the Step 5 save handler, or via an
  `update_option_spfw_settings` action comparing old/new): call `write()`.
  When toggled **off**: call `remove()`.
- Activation (`SPFW_Plugin::activate()`): if `plugins_htaccess` default were on,
  write it. (Default is off → no-op, but wire the call path.)
- Deactivation (`SPFW_Plugin::deactivate()`): call `remove()` (only removes if
  authored + unaltered).

### `tab-hardening.php`
- Checkbox `spfw[hardening][plugins_htaccess]` with clear help:
  - What it does (blocks direct `/wp-content/plugins/*.php` execution).
  - **OLS note:** "On OpenLiteSpeed, `.htaccess` is honored only when *Allow
    Override* is enabled for this vhost (LiteSpeed WebAdmin → Rewrite → Auto Load
    from .htaccess). If override is off, this file has no effect but causes no harm."
  - Rare-plugin warning: some (legacy) plugins serve front-facing PHP from
    `/plugins/`; if something breaks, disable this.
- Show live `status()` (ok/missing/altered) with a Restore button when relevant.

## Design constraints
- **Never** raw `fopen`/`file_put_contents` — use `WP_Filesystem` for host/perms
  compatibility.
- Only ever delete the file we authored (hash match) — never clobber a user's own
  `.htaccess`. If a foreign `.htaccess` exists at the path, `status()` reports
  `altered` and `remove()` refuses; surface this in the notice.
- All admin actions: `manage_options` + nonce.

## Acceptance criteria
- Enabling the toggle creates `/wp-content/plugins/.htaccess` with the payload and
  stores its sha1; a direct request to a plugin `.php` is denied (on Apache / OLS
  with override).
- Manually editing the file triggers the "modified" admin notice; Restore rewrites
  it and clears the notice.
- Deleting the file triggers the "missing" notice; Restore recreates it.
- Disabling the toggle removes the file (only when authored+unaltered).
- A pre-existing foreign `.htaccess` is never overwritten silently.
- `php -l` clean, WPCS clean.

## Final step (required)
Before ending the session, update `../../STATE.md` per its "Update protocol":
flip this step's row to ✅ (or 🟡 if paused), set the commit hash, refresh Overall
status / Last updated / Next action, and log any deviations. Commit STATE.md **in
the same commit** as this step's code, then push to the branch.
