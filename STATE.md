# Build State — Simple Performance for WordPress

**Single source of truth for build progress AND the step-by-step implementation
plan.** Every build session MUST update this file as its final action (see "Update
protocol" below). Read this first before starting any step. Full-detail specs (with
exact hook lists, pseudo-code, and design rationale) live in `docs/build-steps/`;
this file inlines the condensed version of each so progress and plan travel
together in one top-level document.

- **Branch:** `claude/simple-performance-wordpress-plugin-6qbso2`
- **Plugin version target:** 1.0.0
- **Last updated:** 2026-07-10
- **Overall status:** 🟡 Implementation in progress (Step 4 of 9 done)

## Shared project facts (true for every step)

- **Plugin name:** Simple Performance for WordPress
- **Text domain / slug:** `simple-performance-for-wordpress`
- **Prefix:** `spfw_` (functions/options), `SPFW_` (constants/classes)
- **Author:** Ryan Waterbury — One Dog Solutions (https://onedog.solutions/)
- **License:** GPL-3.0-or-later · **Min WP:** 6.0 · **Min PHP:** 7.4
- **Target stack:** OpenLiteSpeed + LiteSpeed Cache
- **Single option key:** `spfw_settings` (autoloaded, one serialized array — the
  only DB footprint; schema in Step 2 below)
- **Standards:** WordPress Coding Standards; escape on output, sanitize on input,
  nonce + `manage_options` capability check on every write, `ABSPATH` guard at the
  top of every PHP file, no direct DB access, no new tables/post-meta/transients
  (except the optional font-scan working cache in Step 8)

## Progress

| Step | Deliverable | Status | Commit |
|------|-------------|--------|--------|
| — | Architecture blueprint (`IMPLEMENTATION_PLAN.md`) | ✅ Done | 5f938f7 |
| — | Per-step build specs (`docs/build-steps/`) | ✅ Done | 5f938f7 |
| 1 | Bootstrap file | ✅ Done | 96d41e3 |
| 2 | Settings layer (`SPFW_Settings`) | ✅ Done | 1859f2f |
| 3 | Core loader + module interface | ✅ Done | 169c712 |
| 4 | Module 1 — core toggles | ✅ Done | (this commit) |
| 5 | Admin skeleton + Core tab | ⬜ Not started | — |
| 6 | Module 2 — REST API controls | ⬜ Not started | — |
| 7 | Module 3 — directory hardening | ⬜ Not started | — |
| 8 | Module 4 — Google Fonts localizer | ⬜ Not started | — |
| 9 | Uninstall cleanup | ⬜ Not started | — |

Status legend: ⬜ Not started · 🟡 In progress · ✅ Done · ⚠️ Blocked

## Next action

Start **Step 5** — full spec at `docs/build-steps/05-admin-skeleton.md`; condensed
instructions below.

---

## Implementation steps

### Step 1 — Bootstrap file ✅
`simple-performance-for-wordpress.php`: plugin header (name/version/author/
license/text-domain, min WP 6.0 / PHP 7.4), `ABSPATH` guard, constants
`SPFW_VERSION`/`SPFW_FILE`/`SPFW_PATH`/`SPFW_URL`/`SPFW_BASENAME`, text-domain load
on `init`, require + boot `SPFW_Plugin` on `plugins_loaded`, activation/
deactivation hooks wired to `SPFW_Plugin::activate()`/`deactivate()`. Never deletes
options on deactivation (that's uninstall's job).

### Step 2 — Settings layer ✅
`includes/class-spfw-settings.php` → `SPFW_Settings`. Owns the single
`spfw_settings` option (schema below). `get()` is statically cached (1
`get_option()` per request, ever). `update()` deep-merges a partial/full array
against **current stored settings** (not just defaults), sanitizes, persists, and
invalidates the cache. `sanitize()` casts booleans strictly, clamps
`heartbeat_interval` to 15–300, whitelists `heartbeat_mode`, and filters route-list
entries to `[A-Za-z0-9/_.-]`.

**Canonical schema:**
```php
[
  'version' => SPFW_VERSION,
  'core' => [
    'disable_emojis' => true, 'disable_embeds' => true, 'disable_dashicons' => true,
    'disable_xmlrpc' => true, 'remove_rsd' => true, 'remove_wlwmanifest' => true,
    'disable_feeds' => false, 'feed_redirect_home' => true,
    'remove_query_strings' => false,
    'heartbeat_mode' => 'modify', // default|modify|disable
    'heartbeat_interval' => 60,   // 15..300
    'disable_jquery_migrate' => true,
  ],
  'restapi' => [
    'require_auth' => false,
    'disabled_namespaces' => ['wp/v2/users', 'wp/v2/themes'],
    'whitelist_routes' => ['contact-form-7/v1', 'wc/v3', 'wc/store'],
  ],
  'hardening' => ['plugins_htaccess' => false, 'htaccess_hash' => ''],
  'fonts' => ['localize_google' => false, 'discovered' => [], 'last_scan' => 0],
]
```

### Step 3 — Core loader + module interface ✅
`includes/interface-spfw-module.php` → `SPFW_Module` (single method `register()`).
`includes/class-spfw-plugin.php` → `SPFW_Plugin` singleton: `boot()` requires
Settings + the interface, walks an explicit `MODULES` class⇒file map (each
`file_exists`-guarded so partial builds never fatal), instantiates + registers each
present module, and loads `admin/class-spfw-admin.php` only when `is_admin()`.
`activate()` seeds default settings if the option is absent (idempotent);
`deactivate()` is a no-op until Step 7 adds `.htaccess` teardown.

### Step 4 — Module 1: core performance toggles ✅
`includes/modules/class-spfw-module-core.php` → `SPFW_Module_Core`. `register()`
reads `SPFW_Settings::group('core')` and attaches, per toggle, only when true:
- **Emojis:** strip `print_emoji_detection_script`/`print_emoji_styles` (wp_head,
  admin), staticize-emoji filters, `wpemoji` TinyMCE plugin, s.w.org dns-prefetch.
- **Embeds:** remove oEmbed discovery/host-js/register-route hooks, deregister
  `wp-embed` script on `wp_footer`.
- **Dashicons:** deregister for logged-out visitors on `wp_enqueue_scripts` (100).
- **XML-RPC:** `xmlrpc_enabled` → false, strip pingback methods + `X-Pingback`
  header.
- **RSD / WLWManifest:** remove their `wp_head` actions.
- **Feeds:** remove feed-link head tags; redirect (301 home) or `wp_die` every
  `do_feed*` hook depending on `feed_redirect_home`.
- **Query strings:** strip `ver` from `script_loader_src`/`style_loader_src`
  (frontend only).
- **Heartbeat:** `modify` → filter `heartbeat_settings` interval (clamped
  15–300); `disable` → deregister the `heartbeat` script on `init`.
- **jQuery Migrate:** strip from `jquery`'s deps via `wp_default_scripts`
  (frontend only).
Verified via stubbed harness: defaults register exactly the expected hook set,
all-off attaches zero hooks, and every pure-logic helper (query-arg stripping,
pingback filtering, emoji dns-prefetch filtering, heartbeat interval override,
jQuery Migrate dep removal) behaves correctly in isolation.

### Step 5 — Admin skeleton + Core tab
`admin/class-spfw-admin.php` → `SPFW_Admin`, loaded only in `is_admin()`.
- `add_options_page` under Settings → "Simple Performance" (slug `spfw-settings`,
  cap `manage_options`).
- Tab registry `core|restapi|hardening|fonts`; current tab from sanitized/
  whitelisted `$_GET['tab']` (default `core`); only `include` a tab view file that
  exists so partial builds don't fatal.
- Save handler on `admin_init`/`admin_post_spfw_save`: cap check +
  `check_admin_referer('spfw_save','spfw_nonce')`, read `$_POST['spfw'][group]`,
  `SPFW_Settings::update()`, PRG redirect back to the same tab with a success
  notice. Field names namespaced `spfw[core][disable_emojis]` etc. so one handler
  serves every tab.
- `admin_enqueue_scripts` bails unless `$hook === 'settings_page_spfw-settings'`;
  enqueues `admin.css`/`admin.js` versioned by `SPFW_VERSION`.
- `admin/views/tab-core.php`: one control per `core` setting (checkboxes, a
  `<select>` for `heartbeat_mode`, a number input 15–300 for `heartbeat_interval`
  shown only when mode=modify via JS), field names `spfw[core][...]`, escaped
  output, LSCache caveat note next to "Remove query strings".
- `admin/assets/admin.js`: heartbeat-interval show/hide; placeholder namespace for
  Step 8's "Scan fonts" button. `admin/assets/admin.css`: light layout only.

### Step 6 — Module 2: REST API controls
`includes/modules/class-spfw-module-restapi.php` → `SPFW_Module_RestApi`. Reads
`SPFW_Settings::group('restapi')`.
- **A. Unregister disabled namespaces** via `rest_endpoints` filter (only if
  `disabled_namespaces` non-empty): drop any route matching a disabled prefix
  **unless** it's in `whitelist_routes` — removes it from the `/wp-json/` index
  entirely (strongest anti-enumeration measure).
- **B. `rest_authentication_errors` gate** (only if `require_auth` OR
  `disabled_namespaces` non-empty): whitelist routes always pass; else if
  `require_auth` and anonymous → `401`; else if route matches a disabled namespace
  and user lacks `manage_options` → **404** (`rest_no_route` — no signal the route
  exists, not 403).
- Helper `route_in_list($route, $list)`: prefix match, `"$item"` or `"$item/"`.
- `admin/views/tab-restapi.php`: require-auth checkbox; disabled-namespaces from
  **live** `rest_get_server()->get_namespaces()` as checkboxes + advanced textarea;
  whitelist textarea pre-seeded with CF7/WooCommerce examples.
- Zero overhead when both `require_auth` is false and `disabled_namespaces` is
  empty — don't touch the filters at all.

### Step 7 — Module 3: directory-level security hardening
`includes/class-spfw-htaccess.php` → `SPFW_Htaccess` utility (shared file logic):
`path()`, `payload()` (the `<Files *.php> Require all denied </Files>` block, with
an `!mod_authz_core.c` fallback for older Apache), `write()` (via `WP_Filesystem`,
0644, stores `sha1(payload())` into `hardening.htaccess_hash`), `remove()` (only
deletes if `sha1_file()` matches the stored hash — never touches a foreign
`.htaccess`), `status()` → `ok|missing|altered|disabled`.

`includes/modules/class-spfw-module-hardening.php` → `SPFW_Module_Hardening`:
on `admin_init`, if `missing`/`altered`, show an `admin_notices` warning with a
nonce-protected Restore link (`admin_post_spfw_restore_htaccess`); toggling the
setting on/off calls `write()`/`remove()`; wire into `SPFW_Plugin::activate()`/
`deactivate()`.

`admin/views/tab-hardening.php`: checkbox with an explicit **OpenLiteSpeed note**
— OLS only honors `.htaccess` when "Allow Override" is enabled at the vhost level
(LiteSpeed WebAdmin → Rewrite → Auto Load from .htaccess); the write is fail-safe
(only adds restriction) if override is off. Also warn that rare legacy plugins
serve front-facing PHP from `/plugins/`. Show live `status()` + Restore button.

### Step 8 — Module 4: Google Fonts localizer & discovery
`includes/modules/class-spfw-module-fonts.php` → `SPFW_Module_Fonts`.
- **Discover** (admin AJAX `wp_ajax_spfw_scan_fonts`, cap+nonce): fetch the
  homepage, regex-scan for `fonts.googleapis.com` references, fetch each Google
  CSS URL with a modern Chrome UA (so Google returns `.woff2`), parse `@font-face`
  blocks, download each unique `.woff2` via `wp_remote_get`/`WP_Filesystem` into
  `wp_upload_dir()['basedir'].'/ods-fonts/'`, rewrite `src: url()` to local URLs,
  persist `discovered = {css, families, files, hash}` + `last_scan` via
  `SPFW_Settings::update()`.
- **Serve** (frontend, only when `localize_google` on and `discovered['css']` set):
  on `wp_enqueue_scripts` (~99), dequeue any style whose **src** (not handle)
  contains `fonts.googleapis.com`, strip the gstatic/googleapis resource hints,
  enqueue the generated local stylesheet versioned by `discovered['hash']`.
  **Fallback:** no local CSS yet → leave the original Google enqueue untouched,
  never break rendering.
- `admin/views/tab-fonts.php`: toggle + "Scan fonts now" AJAX button + summary
  (families/files/last-scan) + re-scan control.
- After a hash-changing scan, trigger `litespeed_purge_all` so LSCache picks up
  the rewrite; the `hash` in the stylesheet version busts OLS's static-file cache
  on re-scan without a manual purge.

### Step 9 — Uninstall cleanup
`uninstall.php`: `defined('WP_UNINSTALL_PLUGIN') || exit;` guard (runs outside the
normal plugin load — don't assume plugin classes are loaded). Read
`hardening.htaccess_hash` from the stored option; delete the plugins-directory
`.htaccess` only if its `sha1_file()` matches (never a foreign file); recursively
delete `uploads/ods-fonts/` if present; `delete_option('spfw_settings')` (loop
`get_sites()` for multisite); every filesystem/DB action guarded by an existence
check so double-running uninstall is a no-op.

---

## Decisions & deviations log

Record here anything a later step needs to know: choices that differ from the spec,
handles/paths that turned out different in practice, WP/PHP quirks encountered, or
follow-ups deferred. Keep entries dated and terse.

- 2026-07-10: Step 1 built exactly to spec. `plugins_loaded`/activation/deactivation
  callbacks use closures guarded by `class_exists`/`file_exists` checks since
  `SPFW_Plugin` (Step 3) doesn't exist yet — no fatals on this partial build.
- 2026-07-10: Step 2 built exactly to spec. `update()` deep-merges the incoming
  (possibly partial) array against the **current stored settings** (not just
  defaults) before sanitizing, so a save from one admin tab never clobbers another
  group's values. `merge_recursive()` distinguishes associative "group" arrays
  (deep-merged) from list arrays like `disabled_namespaces` (replaced wholesale on
  update, per the spec's "list values replace outright" intent). Verified all four
  acceptance criteria with a stubbed WP-function test harness (single `get_option`
  call across repeated `get()`, sanitize clamps/filters, update+get reflects new
  values, partial update preserves other keys) — harness was scratch-only, not
  committed.
- 2026-07-10: Step 3 built exactly to spec. `SPFW_Plugin::MODULES` is the explicit
  class => file map named in the spec; each entry is `file_exists`-guarded so
  partial builds (Steps 4/6/7/8 not yet present) boot cleanly with no fatal.
  Simplified Step 1's activation/deactivation hook registration to
  `array('SPFW_Plugin','activate')` / `array('SPFW_Plugin','deactivate')` directly
  (the class now always exists, so the earlier `class_exists` closure-guard is
  redundant) and made the `class-spfw-plugin.php` require unconditional. Verified
  with a stubbed harness: activation seeds `spfw_settings` idempotently, a
  frontend-context `boot()` never includes anything under `admin/`, and `boot()`
  with zero modules present does not fatal.
- 2026-07-10: Step 4 built exactly to spec. `handle_disabled_feed()` reads
  `feed_redirect_home` fresh from `SPFW_Settings::group('core')` at fire time
  rather than capturing it in a closure at `register()` time, since settings could
  change between requests but the option is only ever queried once per request via
  the Step 2 cache — no extra DB cost. Verified with a stubbed WP hook-registry
  harness: with schema defaults, exactly the expected hook set is attached (and
  `disable_feeds`/`remove_query_strings`, which default off, correctly attach
  nothing); with every toggle forced off, zero hooks are attached; every
  pure-logic helper (ver-stripping, pingback method/header filtering, emoji
  dns-prefetch filtering, heartbeat interval override, jQuery Migrate dependency
  removal) was exercised directly and behaves correctly. Harness was scratch-only,
  not committed.

## Open questions / blockers

- _(none yet)_

---

## Update protocol (every build session, read this)

When you finish a step (or stop partway), before ending your turn you MUST:

1. Flip that step's **Status** in the Progress table (🟡 while working, ✅ when its
   acceptance criteria pass) and fill in the short commit hash. Also mark the
   matching `### Step N` heading above with ✅ (or leave unmarked/🟡 if paused).
2. Update **Overall status**, **Last updated** (today's date), and **Next action**.
3. Append any surprises to **Decisions & deviations log** and any unresolved items to
   **Open questions / blockers**.
4. Commit STATE.md **in the same commit** as the step's code so state never drifts
   from the tree, then push.

Do not mark a step ✅ unless its acceptance criteria (in the step's spec file, or the
condensed instructions above) are actually met. If you stop mid-step, leave it 🟡
and note exactly where you paused under Decisions & deviations so the next session
can resume cleanly.
