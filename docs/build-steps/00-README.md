# Build Steps — Simple Performance for WordPress

This directory contains **nine self-contained implementation specs**, one per
Phase 1 step. Each file is written to be fed into its own fresh Claude session:
it repeats the shared context it needs, states exact deliverables, names the
WordPress hooks/signatures involved, and lists acceptance criteria.

Build them **in order** — each step assumes the prior steps' files exist.

| Step | File | Deliverable |
|------|------|-------------|
| 1 | `01-bootstrap.md` | Main plugin file, constants, activation/deactivation |
| 2 | `02-settings.md` | `Settings` class: defaults, cached `get()`, `sanitize()` |
| 3 | `03-core-loader.md` | Plugin singleton + module interface + admin gating |
| 4 | `04-module-core.md` | Module 1 — core performance toggles |
| 5 | `05-admin-skeleton.md` | Settings page, tabs, save handler, asset enqueue |
| 6 | `06-module-restapi.md` | Module 2 — REST API controls |
| 7 | `07-module-hardening.md` | Module 3 — directory hardening + `.htaccess` writer |
| 8 | `08-module-fonts.md` | Module 4 — Google Fonts localizer |
| 9 | `09-uninstall.md` | Uninstall cleanup |

## Shared project facts (true for every step)

- **Plugin name:** Simple Performance for WordPress
- **Text domain / slug:** `simple-performance-for-wordpress`
- **Prefix:** `spfw_` (functions/options), `SPFW_` (constants), `SPFW_` (class prefix)
- **Author:** Ryan Waterbury — One Dog Solutions (https://onedog.solutions/)
- **License:** GPL-3.0-or-later
- **Min WP:** 6.0 · **Min PHP:** 7.4 · **Target stack:** OpenLiteSpeed + LiteSpeed Cache
- **Single option key:** `spfw_settings` (autoloaded, one serialized array — the
  only DB footprint). Full schema lives in `02-settings.md`.
- **Coding standards:** WordPress Coding Standards; escape on output
  (`esc_html`/`esc_attr`/`esc_url`), sanitize on input, nonce + capability check
  (`manage_options`) on every write, `ABSPATH` guard at the top of every PHP file,
  no direct DB access, no new tables/post-meta/transients (except the optional
  font-scan working cache in Step 8).

## Reference

The high-level architecture is in `../../IMPLEMENTATION_PLAN.md`. These step files
are the executable expansion of that plan's Section 5.
