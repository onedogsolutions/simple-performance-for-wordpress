# Build State — Simple Performance for WordPress

**Single source of truth for build progress AND the step-by-step implementation
plan.** Every build session MUST update this file as its final action (see "Update
protocol" below). Read this first before starting any step. This file inlines the
condensed version of each build step so progress and plan travel together in one
top-level document. (The original full-detail per-step specs that once lived in
`docs/build-steps/` and the `IMPLEMENTATION_PLAN.md` blueprint were removed after
Phase 1 shipped — the condensed steps below plus the dated decisions log are now
the authoritative record.)

- **Branch:** `claude/funny-lamport-589dr7` (2.12.2 logged-out dashicons dependency fix); prior `claude/modest-mayer-6rm967` (2.11.0 LiteSpeed compatibility); prior `main` (font-weight fix merged from
  `claude/plugin-font-weight-issues-2xfjms`; prior work on
  `claude/missing-security-headers-x8gyp9`,
  `claude/simple-performance-wordpress-plugin-6qbso2` / Step 10 on
  `claude/feature-parity-quick-toggles-sf64kt`)
- **Plugin version target:** 2.12.2
- **Last updated:** 2026-09-08
- **Overall status:** ✅ Step 15 (2.12.2 — logged-out visitors no longer lose stylesheets that depend on dashicons); ✅ Step 14 (2.12.0 — OpenLiteSpeed restart cost reduced to one restart, staleness now reported); ✅ Step 13 (2.11.0 LiteSpeed Cache compatibility — whitelist authz fix, `blob:` in the default CSP, whitelist allow-canaries); ✅ Phase 1 complete (9/9); ✅ Step 10 (quick-toggle
  parity + WooCommerce tab) implemented; ✅ Google Fonts discovery
  reliability fix (branch `claude/google-fonts-discovery-plan-tjsdwr`); ✅
  Upgrade-compatibility probe and leftover cleanup removed (2.9.0); ✅
  Hardening-toggle write bug fixed + hardening options expanded (branch
  `claude/toggle-htaccess-plan-fsl3p0`); ✅ Content-Security-Policy header added
  with safety/exclusion options (branch `claude/state-md-missing-header-pbhit2`);
  ✅ Strict-Transport-Security (HSTS) header added, proxy-aware (branch
  `claude/missing-security-headers-x8gyp9`); ✅ CSP visual policy builder +
  live violation-report warnings (branch `claude/missing-security-headers-x8gyp9`);
  ✅ Localized-fonts wrong-weight bug fixed (variable-font block-identity dedupe,
  1.7.1, branch `claude/plugin-font-weight-issues-2xfjms`); ✅ Disable WP
  Sitemaps + Remove robots max-image-preview Core toggles added (absorbs two
  single-hook standalone plugins, 1.8.0, branch
  `claude/wp-sitemaps-robots-toggles-eaoris`, merged to `main`); ✅ CSP policy
  builder coverage gaps fixed (`worker-src` row added, `script-src-elem`/
  `style-src-elem` effective directives collapsed to their base row, 1.9.0,
  branch `claude/policy-builder-coverage-gaps-3dwztj`, merged to `main`); ✅
  XML-RPC disable toggle moved from Core to Hardening tab (PHP-filter default
  plus optional server-level `.htaccess` block) and Permissions-Policy
  save-revert bug fixed (2.0.2); ✅ Generic login errors `wp_login_errors`
  filter fixed to return a `WP_Error` object instead of a string, preventing
  fatal errors on customized login pages such as Divi + LoginPress (2.0.3); ✅
  CSP violation collection reworked into a time-boxed window with sampling,
  write coalescing, locking, flood resistance, and an Allow-confirms-and-clears
  UI (2.1.0, branch `claude/plugin-report-errors-a6pq5k`); ✅
  Beaver Builder settings-based font discovery removed (was causing fewer fonts
  to be discovered, 1.10.0); ✅ CSP violation
  reporting fixed behind QUIC.cloud/Cloudflare CDN (proxy-aware report-uri,
  no-store cache headers on report endpoint, connect-src auto-injection,
  CDN diagnostic UI hint, 1.11.0); ✅ ZIP packaging fix (root directory
  wrapper for WordPress overwrite detection, 1.11.1); ✅ Textarea multiline
  input fix (local-state + blur-commit pattern, 1.11.2); ✅ MainWP child-side
  bridge added (companion dashboard extension support, 1.12.0); ✅ Phase A
  speed & hardening gap-closure (block editor CSS removal, dashboard
  streamlining, disable app passwords, generic login errors, sitemap
  author-leak fix, font preloading, 1.13.0); ✅ Phase B .htaccess
  subsystem rework (FilesMatch fix, root target with marker_block mode,
  legacy hash migration, self-check safety net, 1.14.0); ✅ Phase C
  headers & performance toggles (COOP/CORP/X-Permitted-Cross-Domain-Policies,
  configurable Permissions-Policy, admin security headers, WP-Cron control,
  Speculation Rules control, disable site search, image size generation
  control, 1.15.0); ✅ Phase D operational maturity (CI/phpcs, settings
  export/import, configuration presets, 1.16.0); ✅ Phase E CSP
  script-src tightening (hash sources, strict-dynamic, inline script
  scanner, 2.0.0); ✅ Migration recursion hotfix (cache-before-migration
  ordering, PHPUnit regression test, AllowOverride FAQ, 2.0.1); ✅ Option
  Cleaner & Ghost Capability Cleaner ported (orphaned wp_options scanner,
  ghost capability stripper, on-demand REST endpoints, dedicated React tab,
  simple-performance/v1 namespace); ✅ Database cleanup & optimization
  module (scan/optimize revisions, drafts, trashed content, spam, transients,
  table fragmentation, WP-Cron scheduling, 2.2.0); ✅ CSP reporting
  diagnostics & origin-allowlist normalization (report-uri CORS fix,
  connect-src port handling, diagnostics panel, emitted-policy preview,
  Permissions-Policy allowlists, 2.3.0); ✅ PHP execution whitelist +
  file integrity monitor (whitelist-aware .htaccess RewriteRule payloads,
  sha256 snapshot scanner, twice-daily cron, email alerts, on-demand scan
  endpoint, CSP-style whitelist UI card, 2.4.0); ✅ Scan results list
  collapsed by default behind a "Show file list" expand button (2.5.0); ✅
  Root `.htaccess` self-check deferred off update/upload requests (2.6.0); ✅ `.htaccess`
  enforcement honesty — runtime verification of whether the vhost actually
  applies the file-protection rules, three-state integrity+enforcement badges,
  self-healing `reconcile()` of authored root-block drift, root Restore +
  import mapping fixes, and always-on XML-RPC PHP fallback (2.7.0); ✅ WooCommerce
  Add to Cart fix — the "non-store pages" toggle no longer dequeues the Add to
  Cart handler chain, store content is detected in blocks/shortcodes, and a
  `spfw_is_woo_page` filter covers page-builder layouts — plus CSP admin
  honesty (emitted header labelled with its real name and an enforcing badge,
  `frame-ancestors` stripped from report-only policies, unsaved-changes
  tracking) (2.8.0); ✅ OpenLiteSpeed-compatible mod_rewrite fallbacks added
  to root and deny-PHP .htaccess payloads, whitelist allow-then-deny chain
  fixed, and automatic reconciliation on upgrade (2.10.0)

## Shared project facts (true for every step)

- **Plugin name:** Simple Performance for WordPress
- **Text domain / slug:** `simple-performance-for-wordpress`
- **Prefix:** `spfw_` (functions/options), `SPFW_` (constants/classes)
- **Author:** Ryan Waterbury — One Dog Solutions (https://onedog.solutions/)
- **License:** GPL-3.0-or-later · **Min WP:** 6.0 · **Min PHP:** 8.0
- **Target stack:** OpenLiteSpeed + LiteSpeed Cache
- **Single option key:** `spfw_settings` (autoloaded, one serialized array — the
  only DB footprint; schema in Step 2 below)
- **Standards:** WordPress Coding Standards; escape on output, sanitize on input,
  nonce + `manage_options` capability check on every write, `ABSPATH` guard at the
  top of every PHP file, no direct DB access, no new tables/post-meta/transients
  (except the optional font-scan working cache in Step 8)
- **Admin UI (as of Step 5):** a single React app (`@wordpress/element` — no
  jQuery, no separate React dependency) built with `@wordpress/scripts` +
  Tailwind v4, matching the sister plugin
  `onedogsolutions/google-security-for-wordpress`'s architecture exactly. One
  REST endpoint (`spfw/v1/settings`) is the only persistence path — no PHP form
  views, no `admin-post.php` handler. `build/` is gitignored, produced by
  `npm run build`; `package-lock.json` **is** committed (pins transitive
  dependency versions — see the Step 5 deviation entry below for why this
  matters).

## Progress

| Step | Deliverable | Status | Commit |
|------|-------------|--------|--------|
| — | Architecture blueprint (`IMPLEMENTATION_PLAN.md`) | ✅ Done | 5f938f7 |
| — | Per-step build specs (`docs/build-steps/`) | ✅ Done | 5f938f7 |
| 1 | Bootstrap file | ✅ Done | 96d41e3 |
| 2 | Settings layer (`SPFW_Settings`) | ✅ Done | 1859f2f |
| 3 | Core loader + module interface | ✅ Done | 169c712 |
| 4 | Module 1 — core toggles | ✅ Done | 793acd0 |
| 5 | Admin skeleton (React + Tailwind v4 + REST) | ✅ Done | 26fefd7 |
| 6 | Module 2 — REST API controls | ✅ Done | 22f3b40 |
| 7 | Module 3 — directory hardening | ✅ Done | 2326c84 |
| 8 | Module 4 — Google Fonts localizer | ✅ Done | a294f3b |
| 9 | Uninstall cleanup | ✅ Done | 92afbf5 |
| 10 | Quick-toggle parity + WooCommerce tab + card UI | ✅ Done | (this commit) |
| 11 | Option Cleaner & Ghost Capability Cleaner | ✅ Done | (this commit) |
| 12 | Database Cleanup & Optimization Module | ✅ Done | (this commit) |
| 13 | LiteSpeed compatibility: whitelist authz fix, `blob:` CSP, allow-canaries | ✅ Done | 7615267 |
| 14 | OpenLiteSpeed restart cost: auto-allow, staleness reporting, no-op writes | ✅ Done | 685112b |
| 15 | Dashicons dequeue-not-deregister (logged-out stylesheet loss) | ✅ Done | 5cabb31 |

Status legend: ⬜ Not started · 🟡 In progress · ✅ Done · ⚠️ Blocked

## Next action

**2.12.2 (logged-out dashicons dependency loss) is implemented.** Field report
from maddogproducts.com: with the plugin active, anonymous visitors got a
WooCommerce product page whose add-on fields rendered as bare unstyled selects
— including the `<select>` the swatch UI is supposed to replace — and could not
complete the required fields, so no order could be placed. Logged in, the same
page rendered correctly. Root cause and fix are in the dated log entry below.

Worth watching after this ships, because they are the other two behaviors in
the plugin that apply to logged-out visitors only and neither is exercised by
the report: `restapi.require_auth` (off by default, and `wc/store` + `wc/v3` are
in the default whitelist, so guest checkout survives it as shipped — but an
admin who edits that whitelist can break the Store API for guests with no
warning), and `hardening.csp_exclude_logged_in` (on by default, so an enforcing
CSP is applied to customers and never to the admin testing it).

Also unchanged and unrelated to this fix, but noted while reading the hardening
paths: `SPFW_Plugin::deactivate()` removes the plugins/ and uploads/ .htaccess
files but not the root `.htaccess` marker block, so root hardening rules outlive
a deactivation. Not touched here — it is a separate decision about what
deactivation should mean — but it means "deactivate the plugin" is not a clean
A/B test of the root rules.

### Prior release context (2.12.0/2.11.0, retained)

2.11.0 is merged to `main` (PR #5, merge commit `1a60c6e`).

Step 14 answers the follow-up question from the field report: the 2.11.0 fix was
correct but inert on the reporting server until OpenLiteSpeed was restarted,
because OLS caches .htaccess rewrite rules from startup. That restart cannot be
avoided and the plugin must not attempt it. What 2.12.0 changes is how *often*
it is needed and whether the UI is honest while it is pending — see Step 14.

Gates: PHPUnit 99 tests / 218 assertions (11 new — auto-allow present/absent/
disabled/de-duplicated, no-op vs. real writes, and five staleness cases),
Jest 26/26, `npm run build` clean, `php -l` clean, PHPCS (88 errors / 161
warnings) and `lint:js` (266 problems) both at their pre-existing baselines,
`.pot` regenerated to 485 entries, version synchronized to 2.12.0.

**Validated on real hardware (2026-09-08, maddogproducts.com, OpenLiteSpeed
1.9.1).** The earlier caveat is discharged:
- `wp-content/plugins/index.php` → **403** (deny enforcing)
- `wp-content/plugins/litespeed-cache/guest.vary.php` → **200** (allowance
  working, nothing whitelisted by hand)
- `readme.html` → **403** (root block enforcing)

So the allow-then-deny RewriteCond/RewriteRule chain does work on OLS, and the
auto-allow puts the LiteSpeed endpoint through without admin intervention.

Two field observations worth keeping:
- The plugins `.htaccess` sat on disk with valid deny rules while
  `plugins/index.php` still returned 200; after toggling the blocks on and a
  graceful restart, the rules bit. Consistent with the OLS rule-caching thesis,
  though the toggle and the restart moved together so it is not a clean
  attribution.
- `autoLoadHtaccess 1` was set on the vhost the whole time. A site can have
  correct config, a correct `.htaccess`, and still enforce nothing, purely
  because the running server has not reloaded — which is exactly the gap the
  2.12.0 staleness banner reports.

**Uploads canary gap, found in the field and fixed in this step.** The Hardening
tab showed plugins "Enforced" but uploads "Present (enforcement unverified)",
because the uploads probe ran only when `wp-content/uploads/index.php` existed
and WordPress does not reliably create it. The probe now falls back to
requesting `SYNTHETIC_CANARY`, a path that should not exist: `[F]` fires on the
URL before any file-existence check, so 403 proves the rule ran and 404 proves
the request reached the filesystem — decisive, and needing nothing on disk. The
uploads card reads either canary via `combine_enforcement()`.

**CI remains red on `main` for a pre-existing, unrelated reason** —
`PHPUnit Tests (8.0)` fails inside `composer install` because `composer.lock`
pins PHPUnit 10.5.64 whose `sebastian/*` deps require PHP >= 8.1, while the
matrix runs 8.0 and the plugin header declares `Requires PHP: 8.0`. Diagnosis
and two candidate fixes are on PR #5; neither is applied because both change CI
or dependency policy. This is the one outstanding item that is nobody's
follow-up yet.

Longer-term, worth considering: `KNOWN_DIRECT_ACCESS_PHP` currently holds a
single entry. Other plugins with direct-access PHP endpoints (the ShortPixel /
Imagify / EWWW / UpdraftPlus paths already in the UI pre-fill list) are
candidates, but each needs verifying against the current vendor code before
being auto-allowed rather than merely suggested. Also still open: packaging is
a hand-run Python walk rather than a committed script, and
`csp_tighten_script_src` is expected to be unusable alongside LiteSpeed's JS
optimization (hash drift per cache entry) and has no UI warning saying so.

---

## Implementation steps

### Step 1 — Bootstrap file ✅
`simple-performance-for-wordpress.php`: plugin header (name/version/author/
license/text-domain, min WP 6.0 / PHP 8.0), `ABSPATH` guard, constants
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
    'remove_rsd' => true, 'remove_wlwmanifest' => true,
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
  'hardening' => ['plugins_htaccess' => false, 'htaccess_hash' => '', 'disable_xmlrpc' => false, 'block_xmlrpc_file' => false],
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

### Step 5 — Admin skeleton (React + Tailwind v4 + REST) ✅
**Architecture pivot (2026-07-10):** the admin UI is a single React app —
`@wordpress/element` (vanilla JS/React, **no jQuery**), built with
`@wordpress/scripts` + Tailwind v4, matching the sister plugin
`onedogsolutions/google-security-for-wordpress` exactly (verified by cloning it:
`package.json`/`webpack.config.js`/`postcss.config.js` shape, `src/index.js`
mounting into a root div, `src/styles/index.css` with `@import "tailwindcss"`
scoped under an isolation class, tab components reading/writing state via
`@wordpress/api-fetch` against one REST endpoint, Tailwind conventions —
indigo-600 primary buttons, gray-900/500 text scale, toast notifications, `role`
tablist/tabpanel with arrow-key nav). **No PHP form views, no `admin-post.php`
save handler, no plain admin.js/admin.css** — those are superseded by this.
- **Build tooling** (repo root): `package.json` (`@wordpress/scripts`,
  `tailwindcss`^4, `@tailwindcss/postcss`^4, `postcss`, `autoprefixer`),
  `webpack.config.js` (extends `@wordpress/scripts` default), `postcss.config.js`
  (`@tailwindcss/postcss` + `autoprefixer`). `build/` gitignored — produced by
  `npm run build`, never committed (same as the sister plugin).
- `src/index.js` → mounts `<App />` into `#spfw-admin-root`.
- `src/components/App.jsx` → bootstraps from `window.spfwAdminData`
  (`{restUrl, nonce, settings}`), refreshes via `apiFetch({path:'/spfw/v1/settings'})`
  on mount, holds `settings`/`isSaving`/`toast` state, `handleChange(group,key,val)`,
  `handleSave` POSTs the full settings object and shows a toast. Tabs:
  `core` (ships now via `CoreSettings.jsx`), `restapi`/`hardening`/`fonts`
  (placeholder body until Steps 6–8 add their components).
- `src/components/SettingsTabs.jsx` → generic tablist/tabpanel, keyboard nav.
- `src/components/CoreSettings.jsx` → every Step 4 `core` setting; props
  `{settings, onChange}`.
- `includes/class-spfw-rest-settings.php` → `SPFW_Rest_Settings`. Registers
  `spfw/v1/settings` (`GET`→`SPFW_Settings::get()`, `POST`→
  `SPFW_Settings::update($request->get_json_params())`, both capped
  `manage_options`). **Loads unconditionally** (required + instantiated directly
  in `SPFW_Plugin::boot()`, not admin-gated, not in the toggleable `MODULES`
  list) — REST requests aren't admin context, so `rest_api_init` must fire on
  every request. `spfw/v1` is treated as always-whitelisted by Module 2 (Step 6)
  so the settings screen can never lock itself out.
- `admin/class-spfw-admin.php` → `SPFW_Admin`, loaded only in `is_admin()`.
  `add_options_page` under Settings → "Simple Performance" (slug
  `spfw-settings`, cap `manage_options`). `render_page()` outputs only the root
  div (`<div id="spfw-admin-root" class="spfw-admin-isolated">`) — no PHP form
  markup. `admin_enqueue_scripts` bails unless
  `$hook === 'settings_page_spfw-settings'`; reads `build/index.asset.php` for
  deps/version (falls back to `['wp-element','wp-api-fetch','wp-i18n']` +
  `SPFW_VERSION` if not yet built); enqueues `build/index.js`/`build/index.css`;
  `wp_localize_script`s `spfwAdminData` with `restUrl`, a `wp_rest` nonce, and
  the current `SPFW_Settings::get()` snapshot.

### Step 6 — Module 2: REST API controls ✅
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
- Helper `route_in_list($route, $list)`: prefix match, `"$item"` or `"$item/"`,
  **plus `spfw/v1` hardcoded always-whitelisted** (never let the plugin lock out
  its own settings API).
- `src/components/RestApiSettings.jsx` (React tab, props `{settings,onChange}`):
  require-auth toggle; disabled-namespaces from a live `/` index fetch
  (`apiFetch({path:'/'})`) as checkboxes + advanced textarea; whitelist textarea
  pre-seeded with CF7/WooCommerce placeholder examples.
- Zero overhead when both `require_auth` is false and `disabled_namespaces` is
  empty — don't touch the filters at all.

### Step 7 — Module 3: directory-level security hardening ✅
`includes/class-spfw-htaccess.php` → `SPFW_Htaccess` utility (shared file logic):
`path()`, `payload()` (the `<Files *.php> Require all denied </Files>` block, with
an `!mod_authz_core.c` fallback for older Apache), `write()` (via `WP_Filesystem`,
0644, stores `sha1(payload())` into `hardening.htaccess_hash`), `remove()` (only
deletes if `sha1_file()` matches the stored hash — never touches a foreign
`.htaccess`), `status()` → `ok|missing|altered|disabled`.

`includes/modules/class-spfw-module-hardening.php` → `SPFW_Module_Hardening`:
on `admin_init`, if `missing`/`altered`, show an `admin_notices` warning (native
WP notice, above the React root); toggling the setting on/off calls
`write()`/`remove()`; wire into `SPFW_Plugin::activate()`/`deactivate()`. Runtime
behaviors include application-password disable, generic login errors, security
headers, CSP/HSTS, author-enumeration blocking, and **XML-RPC disable** (`xmlrpc_enabled`
→ false plus pingback method/header stripping when the PHP-filter path is chosen,
or a root `.htaccess` block when server-level blocking is selected).
`SPFW_Rest_Settings::get_settings()` (Step 5) gains a computed read-only
`hardening_status` field, plus a `spfw/v1/settings/restore-htaccess` POST route
(cap `manage_options`) so the React Restore button needs no page reload.

`src/components/RestApiSettings.jsx` sibling
`src/components/HardeningSettings.jsx` (props `{settings, onChange,
hardeningStatus, onRestore}`): toggle with an explicit **OpenLiteSpeed note** —
OLS only honors `.htaccess` when "Allow Override" is enabled at the vhost level
(LiteSpeed WebAdmin → Rewrite → Auto Load from .htaccess); the write is fail-safe
(only adds restriction) if override is off. Also warn that rare legacy plugins
serve front-facing PHP from `/plugins/`. Status indicator + Restore button driven
by `hardeningStatus`.

### Step 8 — Module 4: Google Fonts localizer & discovery ✅
`includes/modules/class-spfw-module-fonts.php` → `SPFW_Module_Fonts`.
- **Discover** (triggered by `spfw/v1/settings/scan-fonts` POST route on
  `SPFW_Rest_Settings`, cap `manage_options` — not `admin-ajax.php`): fetch the
  homepage, regex-scan for `fonts.googleapis.com` references, fetch each Google
  CSS URL with a modern Chrome UA (so Google returns `.woff2`), parse `@font-face`
  blocks, download each unique `.woff2` via `wp_remote_get`/`WP_Filesystem` into
  `wp_upload_dir()['basedir'].'/ods-fonts/'`, rewrite `src: url()` to local URLs,
  persist `discovered = {css, families, files, hash}` + `last_scan` via
  `SPFW_Settings::update()`; the route returns the refreshed settings.
- **Serve** (frontend, only when `localize_google` on and `discovered['css']` set):
  on `wp_enqueue_scripts` (~99), dequeue any style whose **src** (not handle)
  contains `fonts.googleapis.com`, strip the gstatic/googleapis resource hints,
  enqueue the generated local stylesheet versioned by `discovered['hash']`.
  **Fallback:** no local CSS yet → leave the original Google enqueue untouched,
  never break rendering.
- `src/components/FontsSettings.jsx` (props `{settings, onChange, onScan}`):
  toggle + "Scan fonts now" button (`onScan()` → local `isScanning` state) +
  summary (families/files/last-scan, read from `settings.fonts.discovered`) +
  re-scan control.
- After a hash-changing scan, trigger `litespeed_purge_all` so LSCache picks up
  the rewrite; the `hash` in the stylesheet version busts OLS's static-file cache
  on re-scan without a manual purge.

### Step 9 — Uninstall cleanup ✅
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

- 2026-09-08 (logged-out visitors lost dependent stylesheets, → 2.12.2, branch
  `claude/funny-lamport-589dr7`): reported as "the file hardening breaks
  variations and checkout for logged-out users", with paired screenshots of the
  same product page in incognito and logged in. The hardening `.htaccess` files
  were not involved. The difference between the two screenshots is purely CSS:
  incognito rendered browser-default-width `<select>` controls and left the Font
  select visible next to the swatch images that are supposed to replace it, so a
  stylesheet was missing for anonymous visitors and present for the admin.
  **Root cause:** `SPFW_Module_Core::maybe_deregister_dashicons()` called
  `wp_deregister_style( 'dashicons' )`, gated on `! is_user_logged_in()`.
  Deregistering removes the handle from the registry, and
  `WP_Dependencies::all_deps()` then drops every enqueued item whose deps are
  not all registered — "item requires dependencies that don't exist" — silently,
  with no notice and no console error, taking anything that depends on those
  items with it. So the toggle's ~28 KB saving also removed whichever add-on /
  variation-swatch stylesheet declared `dashicons` as a dependency. Required
  add-on fields could not be completed, which is why the report reached us as a
  checkout failure rather than a styling one.
  **Why it was hard to see:** `disable_dashicons` defaults to **on**, and the
  removal applies only to logged-out visitors — so the site renders correctly
  for the admin looking at it and broken for every customer. It is also the
  ONLY code path in the plugin that removes a front-end asset for logged-out
  visitors and not for logged-in ones (`grep is_user_logged_in` over
  `includes/`: this, the CSP exclusion, and the REST auth gate), which is what
  made the attribution decisive rather than a guess.
  **Fix:** `wp_dequeue_style( 'dashicons' )`. It takes the same saving —
  nothing needs it, nothing prints it — and when a queued stylesheet does
  declare it as a dependency WordPress resolves and prints it, which is the
  correct outcome because that dependent needs it. The method is renamed
  `maybe_dequeue_dashicons()`; the old name is kept as a delegating alias so a
  site that unhooked it by name is not silently left on a dead callback.
  **Decisions:** (1) Dequeue rather than "deregister only when nothing depends
  on it": a dependency added after our priority-100 hook would defeat the
  scan, and dequeue gets the same answer with no scan. (2) The logged-out gate
  is kept — `wp_dequeue_style()` would strip the admin bar's icons for
  logged-in users, since the bar enqueues the handle directly. (3) The test
  bootstrap grew recording stubs for `add_action`, `wp_dequeue_style`,
  `wp_deregister_style` and `is_user_logged_in`, plus `remove_action` /
  `remove_filter` / `is_admin` no-ops so `SPFW_Module_Core::register()` can be
  called under test at all; `add_action` was previously an empty function, so
  no existing test depended on its return.
  **Not fixed here (same footgun, different blast radius):**
  `deregister_embed_script()` calls `wp_deregister_script( 'wp-embed' )` on
  `wp_footer` priority 1, before footer scripts print, so a footer script
  declaring `wp-embed` as a dependency would be dropped the same way. Left
  alone because it applies to logged-in and logged-out visitors alike and so
  cannot be the reported bug, and because almost nothing depends on `wp-embed`
  — but it is the same defect and should get the same treatment.

- 2026-09-08 (CI red on `main`, pre-existing): `PHPUnit Tests (8.0)` has been
  failing on every recent `main` run (`1a7fe32`, `fda55bb`, `3b70ace`,
  `ab48a33`, `e7be924`, `eb22647` — six for six). It is not a test failure:
  `composer install` aborts in resolution because `composer.lock` pins PHPUnit
  10.5.64 whose `sebastian/*` deps require PHP >= 8.1, while the matrix runs
  8.0 and the plugin header declares `Requires PHP: 8.0`. `composer.json`
  permits `^9.6 || ^10.5`, but `install` always honors the lock, so the `^9.6`
  alternative is never reached. Fail-fast then cancels the 8.2/8.3 legs, which
  makes the run look worse than it is. Two candidate fixes are written up on
  PR #5: `composer update` in the phpunit job (keeps 8.0 coverage, loses
  lockfile fidelity in CI), or dropping 8.0 from the phpunit matrix only (keeps
  the lock, narrows what `Requires PHP: 8.0` is actually verified against).
  Deliberately NOT applied here — it is a CI/dependency policy change, outside
  the 2.11.0 fix, and the maintainer's call. Note `phpcs` is
  `continue-on-error: true`, so it can never redden the run.

- 2026-09-08 (release packaging): there is no packaging script in the repo —
  `.distignore` exists but nothing consumes it, and `rsync` is absent from the
  build container, so the ZIP was assembled with a short Python walk that
  applies `.distignore` (top-level path prefixes plus basename globs at any
  depth) and writes every entry under a `simple-performance-for-wordpress/`
  root wrapper, which is what makes WordPress treat an upload as an overwrite
  of the existing plugin rather than a new one (the 1.11.1 fix). `npm run
  build` must run first: `build/` is gitignored but ships in the release. The
  2.11.0 archive is 25 files / 177 KB and correctly omits `src`, `tests`,
  `tools`, `vendor`, `node_modules`, `STATE.md`, and the composer/npm/webpack
  config files. Worth turning into a committed script if packaging recurs.

- 2026-09-08 (LiteSpeed compatibility, → 2.11.0): the 2.10.0 whitelist was
  never correct on Apache or LiteSpeed Enterprise, only on OpenLiteSpeed. The
  allow-then-deny RewriteRule chain is a *rewrite*-layer decision, and
  `[L]` ends only the rewrite pass — authorization runs afterwards and the
  `<FilesMatch> … Require all denied` block refused the file regardless. It
  looked correct because the one server we tested on (OLS) ignores
  `<FilesMatch>` entirely, so the rewrite chain was the only thing running.
  The fix relies on Apache merging `<Files>`/`<FilesMatch>` in source order,
  last match winning, so the grant is emitted *after* the deny.
  Considered and rejected: `<If "%{REQUEST_URI} =~ …">`, which would express
  the path directly instead of a basename, but requires `AllowOverride All`
  and 500s a vhost without it — the same constraint that keeps
  `Options -Indexes` out of these payloads. Path precision instead comes from
  the existing RewriteCond chain, which still answers the same basename at any
  other path with `[F,L]`.

- 2026-09-08 (allow-canaries, → 2.11.0): `shape_enforcement_result()` gained a
  per-canary `mode`. An allow-mode row deliberately moves neither
  `$any_enforced` nor `$any_bypassed`: a whitelisted file is reachable both
  when the rules work as intended *and* when the server ignores .htaccess
  entirely, so it carries no information about the vhost-level
  `htaccess_honored` verdict. It sets a separate `whitelist_blocked` flag
  instead. Note that all whitelist rows share the `whitelist` target key, so
  they carry a per-row `label` and the React list key had to become
  `target:label` — `derive_enforcement()` in the REST layer collapses them to
  one entry, which is harmless because no card reads that key.

- 2026-09-08 (whitelist charset, → 2.11.0): whitelist paths are interpolated
  into both a RewriteCond pattern and a `<Files "…">` argument, so a quote
  would produce an .htaccess that 500s the directory. The sanitizer now
  restricts them to `[A-Za-z0-9._/-]`, and `payload_deny_php_for_target()`
  re-checks independently — the sanitizer only runs on save, so values stored
  before this release would otherwise reach the payload unchecked.

- 2026-09-08 (env note): `vendor/bin/phpcs` ships with no `installed_paths`
  configured in a fresh clone, so it fails with "Referenced sniff WordPress
  does not exist" until you run
  `vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs,vendor/phpcompatibility/php-compatibility,vendor/phpcompatibility/phpcompatibility-paragonie,vendor/phpcompatibility/phpcompatibility-wp,vendor/phpcsstandards/phpcsutils,vendor/phpcsstandards/phpcsextra`.
  Not a code issue; recorded so the next session does not read it as a
  regression. Baselines to compare against: PHPCS 88 errors / 161 warnings,
  `lint:js` 266 problems.

- 2026-09-08 (2.8.1 version bump): released as 2.8.1 rather than re-cutting
  2.8.0, because a 2.8.0 package had already been handed over during the
  session — including one build carrying the Hardening-tab render crash. Same
  version number would not have prompted an update on an install already
  holding 2.8.0. **No code difference from the final 2.8.0 build**; the
  changelog entry says so rather than inventing a delta.

- 2026-09-08 (upgrade-compatibility removal, → 2.9.0): the probe + cleanup
  feature shipped in 2.6.0 was removed wholesale at the user's direction — it
  had done its job (the real cause was orphaned `upgrade-temp-backup` debris,
  not SPFW) and was no longer needed. Removed: `UPGRADE_DIRS`, the eleven probe/
  shape/cleanup methods in `SPFW_Module_Hardening`, the two REST routes and
  handlers, the `upgradeCheck`/`isCheckingUpgrade`/`isCleaningUpgrade` state and
  handlers in `App.jsx`, the card + results panel + `CheckPill` in
  `HardeningSettings.jsx`, and `tests/Upgrade_Compat_Check_Test.php`.
  **Decisions:** (1) historical changelog entries for 2.6.0 were kept and
  annotated "(Removed in 2.9.0.)" rather than rewritten, and the 2.6.0 decision
  log entry below was annotated likewise, so the record of *why* the probe
  existed survives its removal; (2) the 2.8.0 changelog line listing
  side-effect endpoints was edited to drop the two removed routes, since that
  line describes current behavior; (3) stale cross-references in comments
  (`mirrors run_upgrade_compat_check()` in the enforcement probe docblock, the
  `shape_upgrade_check_result()` mention in `Htaccess_Enforcement_Test.php`) were
  rewritten rather than left dangling; (4) `package.json` version was brought
  into line at 2.9.0 — it had been pinned at `1.0.0` and never tracked the
  plugin version, so this aligns it going forward; (5) `.pot` regenerated via
  `tools/make-pot.php` (475 entries, down from 497) so the removed strings no
  longer ship. **Verified:** `npm run build` clean; PHPUnit 71 tests / 155
  assertions (was 82 tests before the 11 removed upgrade-check tests); PHPCS and
  `lint:js` identical to their pre-existing baselines; repo-wide grep shows zero
  runtime references to the removed symbols; ZIP packaged per `.distignore`
  with the top-level `simple-performance-for-wordpress/` wrapper.

- 2026-09-08 (CSP cache coherence + a shipped render crash, → 2.8.0, same
  branch): the last two Part E items, plus a regression this session
  introduced and shipped.
  **The regression, first, because it matters most:** the connect-src commit
  placed `const gaps = connectSrcGaps( directives )` ABOVE `const directives =
  hardening.csp_directives || {}` in `CspPolicyCard.jsx`. Webpack compiles a
  temporal-dead-zone reference without complaint; it throws only at render, so
  `npm run build` succeeding proved nothing. The Hardening tab raised
  `ReferenceError: Cannot access 'directives' before initialization` and did
  not render. That went out in a ZIP and was merged to `main`. Fixed by
  reordering, and — since a green build is evidently not evidence the admin
  screen loads — `src/components/test/renders.test.js` now mounts each
  component. Verified the test actually catches it by running the suite against
  the shipped file: all five CspPolicyCard cases fail with that exact error and
  pass on the fix. **Lesson for future sessions: a webpack build is a syntax
  check, not a smoke test. Run `npm run test:js`.**
  Making that possible needed `jest.config.js`, mapping `@wordpress/element` to
  `react` (the former is a webpack external, not an installed package; the
  latter is a thin re-export and IS installed). `react`/`react-dom` were
  promoted from transitive to explicit devDependencies, since the test imports
  them directly and `import/no-extraneous-dependencies` is right to object.
  **Item 1 — `csp_exclude_logged_in` vs page caching.** The exclusion was
  decided at generation time and then cached with the response. Two directions,
  only one of which PHP can fix. The fixable one is the one that matters: a
  page generated for a logged-in user carries NO header, and if the cache
  stores it, that headerless copy is served to logged-out visitors for the rest
  of the TTL — the policy silently stops applying to exactly the people it
  protects, and nothing is reported because no header was sent. Such responses
  are now marked uncacheable (`DONOTCACHEPAGE` plus LiteSpeed's
  `litespeed_control_set_nocache`). The unfixable direction — a logged-out
  entry served to a logged-in user by a CDN not varying on the login cookie —
  cannot be addressed from PHP, because PHP never runs on a cache hit; that is
  stated in the method docblock and in the toggle's UI copy rather than
  pretended away. Cost: with logged-in page caching enabled, front-end pages
  are uncached for logged-in users while this toggle is on. Correctness over
  hit rate for a small population, and the copy says so.
  **Item 2 — window expiry.** `csp_collect_until` lapsing was previously just a
  timestamp going stale, so pages cached while it was open kept advertising
  `report-uri` and browsers kept POSTing to an endpoint answering 403 — the
  per-report uncacheable bootstrap the time-boxed window exists to prevent.
  `set_csp_collection()` now schedules `CSP_EXPIRE_CRON` for the deadline plus a
  minute; the handler zeroes the setting and purges. Registered unconditionally
  rather than behind `csp_enabled`, because a window outlives the toggle that
  opened it, and backed by an `admin_init` catch-up for installs where WP-Cron
  is unreliable (a single read of already-cached settings when idle).
  `deactivate()` clears it, along with the file-monitor scan, which had been
  left scheduled.
  **Deviations:** (i) `prevent_page_caching()` is not unit-tested — it defines
  a constant, so a second call in the same process is a no-op and the test
  would be order-dependent. Covered by reading, not by assertion. (ii) The
  three new third-party names (`DONOTCACHEPAGE`,
  `litespeed_control_set_nocache`, `litespeed_purge_all`) take `phpcs:ignore`
  with reasons, keeping the project total at its baseline. Note the
  inconsistency: pre-existing `litespeed_purge_all` calls in
  `class-spfw-rest-settings.php` and `class-spfw-module-fonts.php` are NOT
  ignored and sit inside the baseline count.
  **Verified:** 82 PHPUnit tests / 246 assertions (4 new); 26 JS tests across 3
  suites (6 new render smoke tests); phpcs unchanged at 97E/161W;
  CspPolicyCard.jsx lint total unchanged at 68 with zero errors on added lines;
  lint:css clean; build succeeds; `.pot` unchanged at 510 msgids, one string
  reworded.

- 2026-09-08 (connect-src gaps + silent token truncation, → 2.8.0, same
  branch): closing the Part E item flagged during the CSP work — `connect-src`
  is an explicit allowlist while `frame-src` carries the payment origins, so
  enforcing would break checkout.
  **The blocker found first:** `sanitize_csp_directives()` capped each directive
  at **15** tokens and truncated silently. The reporting site's live
  `connect-src` was at exactly 15 (`'self'` + 14 tracker origins), so any
  payment origin added to it would have been dropped on save and the "fix"
  would have done nothing, invisibly. Raised to `CSP_MAX_TOKENS = 30` (a named
  constant, no longer a magic number), and the builder now warns when a
  directive reaches the cap. Wildcard host sources already survived
  sanitization (`^(https?://)?(\*\.)?...`), which matters because the vendors'
  own guidance is written in wildcards and they are how a policy stays under
  the cap — pinned by a test so a future sanitizer tightening cannot silently
  break the vendor lists.
  **Decisions:** (1) The gap is detected structurally rather than waited for.
  The Allow flow writes a reported origin into the directive that reported it,
  which is correct but incomplete for an SDK that loads a frame first and calls
  its API only at the payment step — that second violation may never be
  reported on a site nobody test-buys from, so a clean violation log reads as
  "safe to enforce" when it is not. `connectSrcGaps()` checks the policy
  directly: if any of a provider's origins is already present anywhere (proof
  the site uses it) and its connect-src origins are not, the card says so, in
  amber while report-only and red while enforcing, with a one-click fix.
  (2) Provider lists live in `src/lib/csp-bundles.js`, pure and unit-tested,
  and are labelled in the UI as a starting point rather than a guarantee —
  integrations differ (PayPal Fastlane pulls in Braintree, Stripe address
  autocomplete pulls in Google Maps) and providers add hosts. The violation log
  stays authoritative. (3) Nothing is written to the stored policy without an
  explicit click: the gap fix and the new "Pre-fill payment provider origins"
  button are both admin actions, the latter behind a confirm like the existing
  tracker pre-fill.
  **Sourcing caveat:** `docs.stripe.com` is blocked by this environment's
  egress proxy, so the Stripe origins come from vendor guidance surfaced via
  web search rather than fetched from the docs directly; PayPal's wildcard
  recommendation was confirmed from its developer docs. Stripe's documented set
  is `js.stripe.com`/`*.js.stripe.com`/`hooks.stripe.com` (script/frame) and
  `api.stripe.com` (connect); `https://*.stripe.com` was added to connect-src
  to cover the telemetry hosts (`q.`, `errors.`) that appear in practice, and
  `m.stripe.network` for fraud detection, which no wildcard on `stripe.com`
  covers. Over-allowing a vendor's own origins is the safe direction of error
  here; under-allowing is what breaks checkout. **Worth re-verifying against
  Stripe's docs from an unblocked network before relying on it.**
  **Deviations:** (i) The plan said to extend the Allow action to offer
  connect-src alongside the reporting directive. The standalone gap check
  supersedes that and is strictly better — it fires whether or not a violation
  was ever reported, which is the whole failure mode. Allow is unchanged.
  (ii) `wp-scripts lint-js --fix <file>` ignores the path argument and
  reformats the default glob; it silently rewrote 8 unrelated component files
  and was reverted. **Do not use `--fix` in this repo** while the repo-wide
  prettier baseline is red — hand-format instead, or it buries the diff and
  rewrites the baseline CI runs `continue-on-error` against.
  **Still deferred:** `csp_exclude_logged_in` vs page caching, and purging when
  `csp_collect_until` expires on its own.
  **Verified:** 78 PHPUnit tests / 242 assertions (3 new); 20 JS tests across 2
  suites (12 new); phpcs full-project unchanged at 97E/161W; CspPolicyCard.jsx
  lint total unchanged at 68 with zero errors on added lines; new lib files
  lint clean; build succeeds; `.pot` 504 → 510, the 9 added strings and nothing
  else. Noted in passing (pre-existing, not fixed): `tools/make-pot.php` does
  not decode `\uXXXX` escapes, so the existing "visitors\u2019 browsers" msgid
  ships mangled; new strings use literal characters to avoid joining it.

- 2026-09-08 (unsaved-edit clobber on side-effect endpoints, → 2.8.0, same
  branch): follow-up to the dirty-tracking work above, fixing the pre-existing
  bug that work exposed. Seven endpoints persist something of their own and
  return the **full** settings payload — restore-htaccess, csp-report/collect,
  scan-fonts, scan-files, verify-htaccess — and
  `App.jsx` applied each one wholesale. Any edit the admin had made but not
  saved was discarded with no indication: toggle Report-Only, press "Start
  collecting", lose the toggle. Adding the dirty banner made this worse, not
  better — the banner would correctly go clean while the edit vanished.
  **Decisions:** (1) Two paths, not one. `commitSettings()` stays an
  authoritative replace for the four cases where the payload IS the new truth
  (initial load, Save, import, preset — the last two are destructive by
  intent and confirmed by the admin). The seven side-effect endpoints now go
  through `mergeServerSettings()`, which makes the payload the new saved
  baseline and layers pending edits back on top, leaving the form dirty.
  (2) Edits are diffed **per key**, not per group, so a payload that writes one
  key in `hardening` (a collection deadline) does not have to discard an
  unsaved edit to a different key in the same group. Values compare by
  `JSON.stringify` so `csp_directives` arrays/objects compare by value.
  (3) The helpers moved to `src/lib/settings-merge.js`, free of any WordPress
  or React import. That was forced rather than chosen: `@wordpress/element` is
  a webpack external (`wp.element`) and is not an installed package, so jest
  cannot resolve `App.jsx` and the helpers were untestable where they were.
  They are pure functions with nothing React-specific about them anyway.
  **Deviations:** the plan had no JS test layer; `@wordpress/scripts` already
  ships jest, so `npm run test:js` was added to `package.json` and
  `src/lib/test/settings-merge.test.js` covers the diff/merge behavior (8
  tests). No new dependency, no jest config file. `src/` is in `.distignore`,
  so none of it ships in the ZIP.
  **Verified:** 8 JS tests pass; 75 PHPUnit tests / 237 assertions unchanged;
  phpcs full-project total unchanged (97E/161W); the two new JS files lint
  clean and App.jsx has zero lint errors on added lines; `npm run build`
  succeeds.

- 2026-09-08 (WooCommerce Add to Cart breakage + CSP admin honesty, → 2.8.0,
  branch `claude/csp-generator-enforced-policy-7mriy7`): reported as "Report-Only
  is on but an enforced CSP is being emitted, and it is blocking Add to Cart".
  **The CSP half of the report was a misdiagnosis, and the plugin caused the
  misdiagnosis.** Live `curl -sI` on maddogproducts.com returned exactly one
  header, `content-security-policy-report-only`, freshly generated (no
  `x-litespeed-cache: hit`) — the emission logic was correct all along
  (`add_csp_header()` is the only CSP emitter; `security_headers` never emits
  one; nothing writes CSP into `.htaccess`). What made it look enforced:
  (a) the "Actual emitted header" panel printed a bare policy string with **no
  header name**, so report-only and enforcing are visually identical; (b) the
  panel only rendered when the emitted string differed from the built one, so it
  blinked in and out; (c) the settings screen is one form with a single Save and
  **no dirty tracking**, so a toggled-but-unsaved Report-Only switch disagrees
  with the server-derived panel directly beneath it; (d) `frame-ancestors 'self'`
  was emitted in report-only, where browsers ignore it and log a console error
  per page load — the error spam that anchored the whole diagnosis.
  **The real cause of the Add to Cart failure** was
  `SPFW_Module_WooCommerce::disable_scripts_styles()`: its dequeue list included
  `wc-add-to-cart` plus `jquery-blockui` and `js-cookie`, dropped on every page
  where `is_woocommerce() || is_cart() || is_checkout() || is_account_page()` is
  false. That is false for page-builder landing pages (the site runs Avada/Fusion),
  the front page, and posts using `[products]` — all of which render Add to Cart
  buttons. Confirmed on a live non-store page with JS combining off:
  `wc_add_to_cart_params` 0, `cart-fragments` 0, `blockUI` 0, `js.cookie` 0,
  `woocommerce` 11. The list was also internally inconsistent — it dropped
  `wc-add-to-cart` while leaving `wc-add-to-cart-variation` enqueued, so variable
  products ran a script with its dependency and data object both removed.
  **Decisions:** (1) The Add to Cart chain is now **never** dequeued by this
  toggle (`KEEP_SCRIPTS`), rather than trying to detect Add to Cart markup
  perfectly. No route conditional or content sniff can see a product grid a page
  builder renders through its own shortcode, so any heuristic that decides to
  drop the handler will eventually drop it on a page that needs it — and the
  failure is silent, with no console error. The handler is a few KB; the
  stylesheets and the cart-fragments request are where the savings are.
  (2) Content sniffing (`content_has_woo_markup()`, a pure static so it is
  unit-testable) and a `spfw_is_woo_page` filter were added anyway — they fix a
  second, quieter bug where a page with `[products]` kept its markup but lost
  WooCommerce's stylesheets and rendered unstyled.
  (3) `frame-ancestors`/`sandbox` are stripped from report-only headers via
  `REPORT_ONLY_IGNORED` + `remove_directives()`, and restored automatically when
  the policy enforces; `X-Frame-Options: SAMEORIGIN` covers clickjacking in the
  interim. Both `add_csp_header()` and `get_emitted_policy_preview()` apply the
  strip so the preview matches the wire byte for byte.
  (4) `csp_header_name()` is the single source for the header name, consumed by
  the header itself and by `get_settings()` (new `csp_emitted_header` and
  `csp_excludes_logged_in` fields) so the UI can never disagree with the wire.
  (5) `App.jsx` gained `savedSettings` + `commitSettings()` (every full-payload
  response advances both copies) and a `persistedFingerprint()` comparison over
  the six persisted groups only — the computed read-only fields (violation logs,
  scan results, probe state) change on their own schedule and would report the
  form as permanently unsaved. Unsaved state drives a footer banner, a
  `beforeunload` guard, and a line in the CSP panel.
  **Deviations:** (i) The plan proposed dequeuing `wc-add-to-cart-variation`
  alongside `wc-add-to-cart`; keeping the whole chain instead is strictly safer
  and makes the two lists trivially disjoint (asserted in the tests).
  (ii) The emitted-header panel was moved out of the builder-only branch to sit
  above "Violation reports", so Advanced/custom-mode users see the enforcing
  badge too — it was previously unreachable in custom mode.
  (iii) `tools/make-pot.php` takes `<root> <outfile>` as arguments; running it
  bare fatals. Noted here because the 2.7.0 entry does not say so.
  **Deferred (not this release):** `csp_exclude_logged_in` is decided at page
  generation and then cached with the response (`/shop/` returns
  `x-litespeed-cache-control: public,max-age=604800`), so the wrong visitor
  population can receive or miss the header; nothing purges when
  `csp_collect_until` expires on its own; and `connect-src` is an explicit
  allowlist missing `api.stripe.com`, `m.stripe.network` and `c.paypal.com`
  while `frame-src` carries the payment origins — that gap will break checkout
  on the day Report-Only is switched off, because the "Allow" flow only ever
  adds an origin to the directive that reported it.
  **Verified:** see the commit message for the test/lint/build results.

- 2026-09-08 (OpenLiteSpeed-compatible hardening payloads, → 2.10.0): the
  `.htaccess` payloads written by `SPFW_Htaccess` used only Apache authz
  directives (`<FilesMatch>`, `<Files>`, `Require all denied`,
  `Order allow,deny`, `Deny from all`). On OpenLiteSpeed these directives are
  ignored in `.htaccess` even when "Auto Load from .htaccess" is enabled; only
  `RewriteEngine`/`RewriteRule`/`RewriteCond` are honored. The root block, the
  blanket deny-PHP files, and the whitelist-aware deny-PHP files now emit
  `RewriteRule` denials first and keep the existing authz blocks as Apache
  fallbacks. The whitelist payload previously used a no-op `[L]` rule that
  relied on `<FilesMatch>` to perform the actual denial; it now uses an
  allow-then-deny chain (`RewriteCond` whitelist → `RewriteRule … [L]` →
  `RewriteRule … [F,L]`) so non-whitelisted PHP files are refused on OLS too.
  Subdirectory installs are handled by reusing `get_uri_base()` in the root
  `RewriteRule` patterns.
  **Decisions:** (1) Keep the authz directives; they remain effective on Apache
  and are harmless on OLS, and removing them would change behavior for the
  large Apache user base. (2) Add a one-time 2.10.0 reconciliation migration
  that calls `SPFW_Htaccess::reconcile()` so authored files are rewritten to
  the new payload automatically. (3) No new settings or toggles — this is a
  backward-compatible hardening improvement, not a user-facing choice.
  **Verified:** 77 PHPUnit tests / 172 assertions pass (6 new payload tests
  plus subdirectory-install coverage); `vendor/bin/phpcs` reports only the
  pre-existing baseline findings on the touched files (class-spfw-htaccess.php
  4E/0W, tests/Htaccess_Enforcement_Test.php 3E/1W, class-spfw-settings.php
  alignment warnings unchanged from HEAD); `npm run lint:js` reports the same
  pre-existing prettier/JSX-a11y errors in `src/components/HardeningSettings.jsx`
  with zero new errors on the changed string lines; `npm run build` succeeds;
  `.pot` regenerated to 475 entries with the two updated UI strings.

- 2026-09-07 (`.htaccess` enforcement honesty + root-block drift, → 2.7.0):
  two reports on the ott-dev LiteSpeed vhost. (a) Directory Hardening showed a
  green "Active" badge while `readme.html`/`license.txt` returned HTTP 200 and
  `wp-content/plugins/index.php` executed — the vhost has "Auto Load from
  .htaccess" effectively off, so the deny rules are inert, but
  `SPFW_Htaccess::status()` only checks *file present + sha1 == stored hash* and
  reported `ok`. (b) The root marker block had drifted (missing its
  `# group: block_xmlrpc` section) yet still read `ok`, because status compares
  disk to the *stored hash*, never to the payload the *current toggles* require
  (`payload_root()`); root was also excluded from `run_payload_migration()`, and
  the root card's Restore was mis-mapped to the plugins file. A latent gap was
  also closed: `register()` added the PHP `xmlrpc_enabled` filter only when
  `disable_xmlrpc && ! block_xmlrpc_file`, so with the server block inert,
  enabling it left XML-RPC protected by neither layer.
  **Governing constraint:** `readme.html`, `license.txt`, and
  `wp-content/plugins/*.php` are served directly by the web server (Apache/OLS
  `!-f` rewrite skip) — WordPress never bootstraps for those requests, so **no
  PHP hook can intercept them**; only `xmlrpc.php` bootstraps WP and is the one
  protection with a viable PHP path. The honest fix is therefore runtime
  detection + guidance, not a PHP fallback for the file rules.
  **Decisions:** (1) verify enforcement on demand and cache it — a loopback on
  every admin load repeats the cost 2.6.0 deliberately removed, and behind
  QUIC.cloud it risks false readings; one automatic read piggybacks the existing
  post-write root self-check (no new per-load loopback). (2) Enforcement is a
  *separate dimension* from `status()` — the integrity enum is consumed in three
  places (`get_settings`, `maybe_show_notice`, `class-spfw-mainwp-child.php`),
  so folding enforcement in would ripple the contract and conflate two concerns.
  (3) `reconcile()` silently self-heals only *authored* drift (on-disk sha1 ==
  stored hash but content differs from the freshly generated payload), matching
  `run_payload_migration()`'s philosophy — foreign edits are never clobbered
  (stay `altered` + Restore); it now includes root, which the migration skipped.
  (4) The XML-RPC PHP disable now runs whenever `disable_xmlrpc` is set, with
  the server block a performance optimization layered on top.
  **Deviations:** (i) the plan named two stored keys (`htaccess_enforcement`,
  `htaccess_enforcement_time`); only `htaccess_enforcement` is persisted — the
  timestamp lives in `htaccess_enforcement['checked']` and `get_settings()`
  derives `htaccess_enforcement_time` from it, because a 27-char stored key
  would exceed the hardening defaults array's longest key and re-anchor
  `WordPress.Arrays.MultipleStatementAlignment`, flagging ~22 unrelated entries
  (a PHPCS regression). (ii) The plan said to assert the Restore mapping "via a
  spy/stub on `SPFW_Htaccess::write`"; PHP cannot stub a static method and the
  real `write()` fatals on the unstubbed `WP_Filesystem`/`insert_with_markers`,
  so the mapping was extracted into a pure `resolve_restore_target()` static and
  unit-tested directly.
  **Verified:** 53 PHPUnit tests / 207 assertions pass (19 new in
  `tests/Htaccess_Enforcement_Test.php`; the 2 pre-existing CSP deprecations are
  unchanged); `vendor/bin/phpcs` reports byte-identical findings to the HEAD
  baseline on all four touched `includes/` files (htaccess 14E/0W, rest-settings
  5E/27W, settings 24E/65W, hardening 1E/14W); `npm run lint:css` clean;
  `npm run lint:js` has zero errors on the 343 added JSX lines (the repo-wide
  278-error prettier baseline is separately red and CI runs it
  `continue-on-error: true`); `npm run build` succeeds (webpack 5.108.4) and
  `build/index.js` contains the new strings; `.pot` regenerated 470→495 entries
  via `tools/make-pot.php` (exactly one msgid dropped — the old
  `block_xmlrpc_file` copy intentionally replaced in Part D); ZIP packaged as
  `simple-performance-for-wordpress-2.7.0.zip` (32 files, top-level wrapper,
  structure identical to 2.6.0, no dev artifacts).
  **Part E (live ott-dev vhost remediation) is approval-gated and was NOT
  executed** — this is a code-only change; it needs explicit go-ahead + host
  access (see Next action).

- 2026-09-07 (upgrade-compatibility probe, → 2.6.0, removed in 2.9.0): plugin
  installs/updates were reported failing with "Could not move the old version to
  the upgrade-temp-backup directory" (EventKoi) and "Filesystem error. A
  directory could not be read" (Novamira) while file-protection toggles were on,
  and the hardening `.htaccess` rules were blamed. **They were not the cause.**
  Both messages come from pure PHP `WP_Filesystem` calls inside
  `WP_Upgrader::move_to_temp_backup_dir()` and `WP_Upgrader::run()`;
  `.htaccess` governs HTTP requests only and cannot make `rename()` or
  `opendir()` fail. SPFW attaches no hooks to the upgrader.
  Live forensics confirmed ownership/permissions healthy (PHP user owns and can
  write every directory, `direct` filesystem method) and found the real cause:
  **5 orphaned leftovers stranded in `wp-content/upgrade-temp-backup/plugins/`**
  by an interrupted bulk-update run, which later runs then stumbled over.
  A contributing factor was also found and fixed —
  `maybe_run_root_self_check()` fired a 10-second blocking loopback
  `wp_remote_get()` on `admin_init` during update requests, stealing execution
  budget from already-long bulk-update runs.
  **Decisions:** (1) ship a diagnostic that replays the upgrader's exact
  operations rather than change the hardening payloads on a hunch;
  (2) the self-check now *defers without consuming* its pending flag on
  update/upload requests — deliberately **not** moved to cron, because a broken
  root `.htaccess` would also break the `wp-cron.php` loopback and defeat the
  500-error rollback safety net; (3) leftover debris is reported as
  `stale_total` and deliberately excluded from the `pass` verdict, since a
  directory can be fully writable and still hold orphans — conflating the two
  would make the report unreadable; (4) `shape_upgrade_check_result()` is pure
  (no filesystem, WordPress, or translation access) so the verdict logic is
  unit-testable without an install; (5) added a cleanup endpoint beyond the
  original plan scope, because forensics proved debris is the actual cause and
  a diagnostic with no remedy would be incomplete.
  **Removed in 2.9.0:** the probe, cleanup action, and their REST endpoints
  were removed from the plugin after the feature became unnecessary.
  **Verified:** 34 PHPUnit tests / 141 assertions pass (12 new);
  `vendor/bin/phpcs` reports byte-identical findings to the HEAD baseline
  (6 errors / 41 warnings) despite ~500 added PHP lines; `npm run lint:css`
  clean; `npm run build` succeeds (webpack 5.108.4); zero `lint-js` errors on
  the 354 added JSX lines (the repo's prettier baseline is separately red and
  CI runs it `continue-on-error: true`).

- 2026-09-07 (`.pot` regeneration without WP-CLI): `wp i18n make-pot` was
  unavailable and `languages/simple-performance-for-wordpress.pot` was badly
  stale — still generated at 2.0.0 (294 entries, `POT-Creation-Date:
  2026-07-31`), missing every string added in 2.1.0–2.6.0. Added
  `tools/make-pot.php`, a purpose-built extractor covering only the i18n
  functions this plugin actually calls (`__`, `_e`, `_n`, `esc_html__`,
  `esc_html_e` in PHP; `__` in JS). `tools/` is excluded via `.distignore`.
  **Verified** rather than trusted: 497/500 emitted `#: file:line` references
  resolve exactly onto their claimed string (the 3 exceptions are validator
  escaping artifacts on `"NEW FILES:\n"`-style literals, output is correct);
  only 4 prior msgids dropped, all confirmed as copy edited since 2.0.0 rather
  than extraction misses; no code fragments captured. Result: 470 entries from
  504 call sites.

- 2026-08-19 (scan results collapsed by default, → 2.5.0): the scan-results
  panel in `PhpWhitelistCard.jsx` listed every changed file inline, which is
  very long on a first scan (every tracked PHP file reports as "added").
  The list is now collapsed by default: the panel shows a count summary
  ("N new, N modified, N removed") with a "Show file list" / "Hide file
  list" toggle button, and a `useEffect` re-collapses it whenever a fresh
  scan result arrives. Frontend-only change.
  **Verified:** `npm run build` succeeds (webpack 5.108.4, no errors).

- 2026-08-19 (PHP execution whitelist + file integrity monitor, → 2.4.0):
  legitimate tools (ShortPixel and similar image optimizers/backup plugins)
  execute PHP from inside `wp-content/plugins/` and `wp-content/uploads/`,
  which the directory-hardening `.htaccess` blanket-deny breaks. Added a
  whitelist plus a file-integrity monitor to detect unexpected PHP files.
  **Backend:** `class-spfw-settings.php` — five new `hardening` keys
  (`php_whitelist`, `file_monitor_enabled`, `file_monitor_email`,
  `file_monitor_snapshot`, `file_monitor_last_scan`) with
  `sanitize_php_whitelist()` (traversal rejection, `plugins/`|`uploads/`
  prefix restriction, PHP-extension requirement, 50-entry cap); Locked Down
  preset now enables the monitor. `class-spfw-htaccess.php` —
  `payload_deny_php_for_target( $target )` filters the whitelist to the
  target directory (20-entry cap) and, when non-empty, emits
  `RewriteEngine On` + `RewriteCond %{REQUEST_URI}` allow-then-deny rules
  (`[OR]`-chained, last condition without `[OR]`) ahead of the FilesMatch
  deny block; URI prefix derived from `home_url()` path so subdirectory
  installs work; empty whitelist reproduces the exact prior blanket-deny
  payload (hash-stable). `class-spfw-module-hardening.php` —
  `scan_wp_content()` builds a `{relative_path: sha256}` map of
  plugins/ + uploads/ and diffs against the stored snapshot
  (added/modified/removed); `maybe_send_file_alert()` sends one
  consolidated `wp_mail()` grouped by change type with `[NOT ON WHITELIST]`
  flags, rate-limited to 1/hour via the `spfw_file_monitor_cooldown`
  transient; `run_file_monitor_scan()` is the twice-daily cron callback
  (`spfw_file_monitor_scan`); `handle_settings_change()` now rewrites both
  `.htaccess` targets when `php_whitelist` changes while enabled, and
  schedules/clears the cron when the monitor toggle flips.
  `class-spfw-rest-settings.php` — `POST /spfw/v1/settings/scan-files`
  endpoint; GET response gains `file_monitor_last_scan`,
  `file_monitor_snapshot_count`, and `admin_email`; export/import exclude
  the snapshot + last-scan keys as volatile. **Frontend:** new
  `PhpWhitelistCard.jsx` (chip add/remove UI mirroring the CSP builder,
  "Pre-fill common plugin paths" with confirm step, monitor toggle/email,
  scan status, "Scan now", results panel with amber highlighting for
  non-whitelisted entries) rendered between Directory Hardening and Root
  .htaccess Rules in `HardeningSettings.jsx`; `App.jsx` gains
  `handleScanFiles`. **Verified:** `php -l` clean on all four PHP files;
  `npm run build` succeeds (webpack 5.108.4, no errors).

- 2026-08-19 (retroactive — CSP reporting diagnostics, → 2.3.0): 2.3.0
  shipped without a STATE.md entry (commit 24fb734). It fixed
  `origin_already_allowed()` to recognise scheme-sources, normalise
  host tokens, handle wildcard subdomains, and fall back to `default-src`;
  added report-uri CORS headers + OPTIONS preflight; fixed
  `ensure_connect_src_allows()` for non-default ports; and added the admin
  diagnostics panel, emitted-policy preview, per-minute rate-limit selector,
  bulk "Allow all reported origins", "Pre-fill common third-party origins",
  and per-feature Permissions-Policy allowlists. Full detail lives in the
  readme.txt 2.3.0 changelog.

- 2026-08-14 (CSP violation collection rework, → 2.1.0, branch
  `claude/plugin-report-errors-a6pq5k`): user reported that on a
  high-traffic production site (maddogproducts.com, OpenLiteSpeed) the PHP
  analytics panel showed `/wp-json/spfw/v1/csp-report` as the **single most
  requested page on the site** (551 requests/24h, above every real product
  page) and flagged with status 500.
  **Investigation:** reproduced the endpoint on a clean WP 6.8 + PHP 8.4
  install (SQLite drop-in, plugin activated, CSP enabled). Every request
  shape tried — legacy `application/csp-report`, modern
  `application/reports+json`, malformed JSON, empty body, 20 KB body,
  invalid UTF-8, 600-deep nesting, `multipart/form-data`, chunked encoding,
  `X-HTTP-Method-Override`, `?_method=`, HEAD/PUT/GET/DELETE, CSP on and
  off — returned 204/403/400/401/404. **The 500 is not a payload-parsing
  fatal and was not reproducible from request shape**; the endpoint's design
  made it both likely under load and invisible (it swallows every error and
  always answers 204). Four defects were proven instead:
  1. **Design:** `add_csp_header()` attached `report-uri` to every front-end
     response permanently, in enforce mode too (the deliberate 1.6.0/1.11.0
     decision). Every visitor's browser therefore POSTed on every page view,
     forever — an uncacheable full WP bootstrap (~24 ms on a bare install,
     far more on a WooCommerce site) per report, for a log that saturates its
     50 deduped slots within seconds. After that, every request was pure cost.
  2. **Race (proven):** 150 concurrent identical reports recorded a count of
     **70**. `store_violations()` was an unlocked read-modify-write.
  3. **Store poisoning (proven, security):** 80 anonymous POSTs with invented
     origins evicted the entire real log and filled all 50 slots.
     `CspPolicyCard.jsx` renders each attacker-supplied `blocked_origin` with
     a one-click **Allow** that writes it straight into the live `script-src`
     — worse now that Phase E ships `strict-dynamic`.
  4. **Secondary:** the 8 KB body cap silently discarded batched Reporting API
     payloads; the 7-day TTL was reset on every write so the log never aged
     out; `set_transient` fired even when nothing changed.
  **Fixes (6 files):**
  - `class-spfw-settings.php`: new `csp_collect_until` (0 = closed) and
    `csp_collect_sample` (1–100, default 100) in the hardening group, plus
    `CSP_COLLECT_MAX` (7 days) — the sanitizer hard-caps the deadline so a
    stored or imported value can never leave collection open indefinitely.
  - `class-spfw-module-hardening.php`: new `collection_open()` (shared by the
    header and the endpoint so the two can never disagree) and
    `collection_sampled()`. `add_csp_header()` now attaches `report-uri` only
    while a window is open and only on the sampled share of responses.
  - `class-spfw-rest-settings.php`: endpoint returns 403 before touching the
    body unless CSP is on **and** a window is open; body cap 8 KB → 32 KB;
    `{ items, meta }` store envelope (legacy flat shape read transparently —
    entry keys always contain `|`, so the shape test is unambiguous);
    `wp_cache_add()` lock around the read-modify-write; write coalescing via
    `CSP_WRITE_INTERVAL` (5 s) so repeat sightings cost nothing (counts are
    now an explicit lower bound); `CSP_NEW_PER_MINUTE` (5) ceiling on new
    origins; `evict_one()` drops the **least-reported** entry rather than the
    least recently seen; `origin_already_allowed()` suppresses stale reports
    for origins the live policy already permits; new
    `POST /csp-report/collect` route (server computes the deadline, browser
    clock skew can't shorten or extend the window) that purges LSCache since
    the reporting directive lives in a cached response header; DELETE accepts
    an optional `directive`/`origin` pair to drop a single entry; new
    `csp_report_stats` on both GET routes.
  - `CspPolicyCard.jsx`: collection-window controls (start 1h/24h/3 days,
    stop, time remaining, sample select, live counts); polling only while a
    window is open; **Allow is now two-step** — it shows the exact token it
    will add, and on confirm adds the token, hides the row immediately, and
    DELETEs the entry server-side so a later poll can't resurrect it; explicit
    "reports are unauthenticated, only allow origins you recognise" notice.
  - `App.jsx` / `HardeningSettings.jsx`: `handleDismissCspReport` and
    `handleSetCspCollection` wired through.
  - `tests/`: bootstrap gained transient + object-cache stubs and the time
    constants; new `Csp_Report_Collection_Test.php` (11 cases) covering the
    window, the deadline cap and sample clamp, the new-origin rate limit,
    count-based eviction, already-allowed suppression (including keyword
    tokens), custom-mode fall-through, write coalescing, legacy store shape,
    and count ordering.
  **Verified:** 16/16 PHPUnit pass; `php -l` clean; PHPCS clean within all
  changed lines; `npm run build` succeeds; `wp-scripts lint-js` reports no
  issues inside changed lines (the three touched JSX files were already at 82
  pre-existing errors, now 72). End-to-end on the live test install: window
  closed ⇒ no `report-uri` and endpoint 403; window open ⇒ header carries
  `report-uri` and reports store; 30 distinct origins in one minute ⇒ capped
  at 5 new entries; 100 concurrent duplicates ⇒ 1 write; Allow ⇒ entry
  removed and, once saved, stale reports for it are not re-added; window
  elapses ⇒ header and endpoint close themselves; sample=10 ⇒ `report-uri`
  on 6/40 responses.
  **Outstanding:** `.pot` regeneration for the new UI strings; live QA on
  maddogproducts.com to confirm the endpoint drops off the top-requested list
  and the 500s stop. The 500's exact production mechanism remains unconfirmed
  — if it persists after the request volume drops to near zero, it is not
  this endpoint and needs server-side logs.

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
- 2026-07-10: **Architecture pivot before Step 5** — the user required vanilla
  JS/no jQuery and asked for styling to match the sister plugin
  `onedogsolutions/google-security-for-wordpress`, built with React + Tailwind
  v4. Cloned that repo (added via `add_repo`, at `/workspace/google-security-for-wordpress`)
  to copy its actual architecture rather than guess: `@wordpress/element` (React
  bundled with WP core, so genuinely no separate React dep and no jQuery),
  `@wordpress/scripts` (wp-scripts) build, Tailwind v4 via `@tailwindcss/postcss`,
  one settings REST endpoint consumed via `@wordpress/api-fetch`, a
  `.{plugin}-admin-isolated` class scoping Tailwind's `@layer base` so wp-admin's
  unlayered styles can't clobber it (and vice versa), `build/` gitignored and
  produced by `npm run build`. Rewrote the already-in-progress Step 5 (which had
  started as plain PHP form views/admin-post.php before this correction) and
  updated `IMPLEMENTATION_PLAN.md` §1 plus `docs/build-steps/00,05,06,07,08` to
  reflect this for all remaining steps: Steps 6–8's "admin tab" deliverable is now
  a React component (`RestApiSettings.jsx`/`HardeningSettings.jsx`/
  `FontsSettings.jsx`) wired into `App.jsx`, not a `tab-*.php` view; Step 8's
  "Scan fonts" AJAX action became a `spfw/v1/settings/scan-fonts` REST route (and
  Step 7 similarly gained `spfw/v1/settings/restore-htaccess`) for consistency
  with the REST-driven persistence model — **these two routes are specified but
  not yet implemented; they land with Steps 7/8 respectively.**
  Added `includes/class-spfw-rest-settings.php` (`SPFW_Rest_Settings`): GET/POST
  `spfw/v1/settings`, both capped `manage_options`, POST sanitizing via the
  existing `SPFW_Settings::update()`. Loads **unconditionally** from
  `SPFW_Plugin::boot()` (required directly, not through the toggleable `MODULES`
  registry and not inside the `is_admin()` branch) because REST requests aren't
  admin context — verified with a stubbed harness that `rest_api_init` still
  fires and the route registers even when `is_admin()` returns false, and that
  `admin/` is still never loaded on that same request. Rewrote
  `admin/class-spfw-admin.php` to just register the menu, render a bare
  `#spfw-admin-root` mount div, and enqueue `build/index.js`/`build/index.css`
  (reading `build/index.asset.php` for deps/version, falling back to
  `['wp-element','wp-api-fetch','wp-i18n']`/`SPFW_VERSION` if the asset file
  doesn't exist yet, i.e. before the first `npm run build`) with
  `spfwAdminData` (`restUrl`, a `wp_rest` nonce, and the current
  `SPFW_Settings::get()` snapshot) localized onto it.
  Added `package.json`/`webpack.config.js`/`postcss.config.js` mirroring the
  sister plugin, plus `src/index.js`, `src/styles/index.css`
  (`.spfw-admin-isolated`-scoped), and components `App.jsx`, `SettingsTabs.jsx`
  (generic tablist/tabpanel with arrow-key nav, copied near-verbatim — it's
  settings-shape-agnostic), `Toggle.jsx` and `SettingsRow.jsx` (small shared
  pieces factored out for reuse by Steps 6–8's components — the toggle-switch
  and title/description/control row markup would otherwise be copy-pasted four
  times), and `CoreSettings.jsx` (every Step 4 `core` setting). `App.jsx` renders
  `restapi`/`hardening`/`fonts` as a placeholder "not available yet" body until
  each step's component exists.
  **Toolchain gotcha, fixed:** a fresh `npm install` (no inherited lockfile)
  resolved `typescript@7.0.2` transitively, which crashes
  `@typescript-eslint@6.21`'s internals (`ts-api-utils`) with `Cannot read
  properties of undefined (reading 'Intrinsic')` — confirmed by diffing against
  the sister plugin's `node_modules`, which resolves `typescript@6.0.3` only
  because its committed `package-lock.json` pins it. Fixed by adding an explicit
  `"typescript": "6.0.3"` devDependency pin in our `package.json` (matching the
  sister plugin's resolved version) so `npm run lint:js` doesn't crash for future
  contributors doing a clean install; `package-lock.json` is now committed for
  the same reason (unlike `node_modules/`/`build/`, which stay gitignored).
  Fixed 8 real `wp-scripts lint-js` findings (prettier formatting + two
  `jsx-a11y/label-has-associated-control` violations, resolved via explicit
  `htmlFor`/`id` pairs) — 6 were auto-fixed with `--fix`.
  **Verified:** `npm install && npm run build` succeeds, producing
  `build/index.js`/`index.css`/`index.asset.php`; `npm run lint:js` and
  `npm run lint:css` both clean; REST controller behavior confirmed with a
  stubbed harness (route registers with GET+POST, permission_callback rejects
  non-`manage_options`, GET returns the full settings snapshot, POST persists a
  partial update while preserving untouched keys).
- 2026-07-10: Step 6 built exactly to spec (per the React-pivot revision of
  `06-module-restapi.md`). `route_in_list()` hardcodes `spfw/v1` as
  always-exempt (equality or `spfw/v1/` prefix) ahead of checking the
  user-configured `whitelist_routes`, so the plugin's own settings API can never
  be locked out regardless of configuration — verified explicitly in the test
  harness by listing `spfw/v1/settings` as if it were itself a disabled
  namespace and confirming it's still kept. `RestApiSettings.jsx` fetches the
  live namespace list via `apiFetch({path:'/'})` (the WP-JSON index's
  `namespaces` array) on mount for the checkbox list, with a newline-separated
  textarea as the single source of truth for `disabled_namespaces` (checkboxes
  and textarea both read/write the same flat array — no separate state to
  reconcile). Two `jsx-a11y/label-has-associated-control` findings needed
  explicit `htmlFor`/`id` pairs (as in Step 5 — this project's eslint config
  doesn't accept label-nesting alone as valid association). **Verified** with a
  stubbed PHP harness covering every acceptance criterion: disabled namespaces
  are fully unregistered from `rest_endpoints` (not just gated) unless
  whitelisted; anonymous requests to a disabled namespace get 404
  (`rest_no_route`), never 403; a logged-in `manage_options` user is exempt from
  that 404; `require_auth` returns 401 for anonymous requests on unrestricted
  routes while whitelisted routes still pass; `spfw/v1` passes through
  untouched in every scenario; and with both `require_auth` off and
  `disabled_namespaces` empty, neither filter is attached at all (zero
  overhead). `npm run lint:js`/`lint:css` clean, `npm run build` succeeds.
  Harness was scratch-only, not committed.
- 2026-07-10: Step 7 built exactly to the React-pivot revision of
  `07-module-hardening.md`, with one deliberate simplification: rather than a
  separate nonce-protected `admin_post_spfw_restore_htaccess` GET-triggered
  handler (the spec's original phrasing, left over from before the REST pivot),
  the Restore action is **only** the REST route
  (`spfw/v1/settings/restore-htaccess`, `manage_options`-capped, added to
  `SPFW_Rest_Settings`) that the React button already calls — a GET request
  triggering a filesystem write is an anti-pattern anyway, and a second
  independent restore code path would just be duplicated logic with nothing
  gained. The native `admin_notices` warning (missing/altered) is kept as
  plain server-rendered HTML above the React root — it doesn't need JS to be
  visible — and just links to the Hardening tab rather than performing the
  restore itself.
  `SPFW_Module_Hardening` reacts to the toggle via
  `add_action('update_option_' . SPFW_Settings::OPTION_KEY, ..., 10, 2)`
  (comparing old vs new `hardening.plugins_htaccess`) rather than special-casing
  anything in the REST controller — this means **any** future code path that
  flips the toggle (REST, WP-CLI, direct `update_option`, etc.) gets the
  write/remove side effect for free. Note: `write()`/`remove()` themselves call
  `SPFW_Settings::update()` to persist/clear the hash, which re-triggers this
  same `update_option_*` hook once more — harmlessly, since old/new
  `plugins_htaccess` are now equal on that second pass and the handler no-ops;
  documented here rather than added complexity to prevent a one-level bounce
  that already self-terminates.
  `SPFW_Htaccess` (the shared file utility) is required **unconditionally** in
  `SPFW_Plugin::boot()` (not just when Module 3's file happens to exist),
  because `SPFW_Rest_Settings::get_settings()` (always loaded, Step 5) needs
  `SPFW_Htaccess::status()` for the `hardening_status` field it now returns.
  `SPFW_Plugin::activate()`/`deactivate()` (Step 3) now call
  `SPFW_Htaccess::write()`/`remove()`.
  **Verified** against a real (temp-directory) filesystem, not just mocks: the
  full lifecycle — disabled → toggle on writes file (`ok`) → manual edit
  detected as `altered` → Restore (`write()`) fixes it back to `ok` → deleting
  the file detected as `missing` → Restore recreates it → toggle off removes
  the file (`disabled`) — plus confirmation that a foreign/pre-existing
  `.htaccess` (hash never matches) is never touched by `remove()`. Also
  confirmed the REST controller's new `restore-htaccess` route registers and
  `GET /settings` includes `hardening_status`. `npm run lint:js`/`lint:css`
  clean, `npm run build` succeeds. Harnesses were scratch-only, not committed.
- 2026-07-10: Step 8 built exactly to the React-pivot revision of
  `08-module-fonts.md`, following the `restore-htaccess` REST pattern from
  Step 7 for `scan-fonts` (`SPFW_Rest_Settings::scan_fonts()` calls
  `(new SPFW_Module_Fonts())->scan()` directly — no defensive re-require, since
  by the time any REST callback fires, `SPFW_Plugin::boot()` has already run
  the full `MODULES` loop synchronously within the same `plugins_loaded`
  invocation, same reasoning already established for `SPFW_Htaccess` in Step 7).
  One meaningful deviation from the literal spec text: the static
  `ods-fonts/fonts.css` file is written **once, during `scan()`** rather than
  regenerated on every frontend request inside `wp_enqueue_scripts` — writing a
  file on every single page load would be wasteful I/O directly contradicting
  the plugin's "no heavy footprint" goal, and it's unnecessary since OLS/LSCache
  already hard-caches the static file per the spec's own LSCache note.
  `serve_local_fonts()` only falls back to a **self-heal** rewrite (via the
  cached `discovered.css` still held in the option) if the physical file has
  gone missing since the last scan (moved/deleted), and if that regeneration
  also fails, it returns immediately **before** dequeuing anything — this is
  what actually implements "if the local file/CSS is missing, do nothing" from
  the acceptance criteria, since a naive "dequeue first, enqueue second" order
  would risk leaving a page with no font styles at all if the enqueue step
  failed.
  Google stylesheets are matched by **registered `src` substring**, never by
  handle, exactly per the design constraint (themes use arbitrary handle
  names). `wp_resource_hints` filtering handles both string and
  `{href: ...}` array hint shapes and only touches `preconnect`/`dns-prefetch`
  relations.
  **Verified** with a stubbed harness that fakes `wp_remote_get` per URL
  pattern (homepage HTML → Google CSS → `.woff2` binary) and writes to a real
  temp uploads directory: `scan()` discovers both `@font-face` blocks from a
  two-weight Google Fonts response, downloads both `.woff2` files to disk,
  rewrites the CSS to local URLs with zero remaining `fonts.gstatic.com`
  references, and persists `discovered`/`last_scan`; enabling `localize_google`
  (with cached CSS present) attaches the serve hooks, zero hooks attach
  otherwise; `serve_local_fonts()` dequeues only the style whose **src**
  matches Google (leaving an unrelated enqueued style untouched) and enqueues
  the local stylesheet; the resource-hints filter strips Google entries only
  for `preconnect`/`dns-prefetch`; and — using a fake filesystem that can be
  told to simulate a write failure — a missing physical file that can't be
  regenerated is confirmed to leave both dequeue and enqueue untouched (the
  "never break rendering" fallback). `npm run lint:js`/`lint:css` clean,
  `npm run build` succeeds. Harness was scratch-only, not committed.

  **All four functional modules (Steps 4, 6, 7, 8) are now complete** — only
  Step 9 (uninstall cleanup) remains before the plugin is feature-complete
  per the original implementation plan.
- 2026-07-10: Step 9 built exactly to spec. `uninstall.php` is fully
  self-contained (per the spec's own guidance) — it inlines the hash-gated
  `.htaccess` removal and `ods-fonts/` deletion logic directly rather than
  requiring `SPFW_Htaccess`, since that class's `status()`/`write()` also read
  the *toggle* setting (which is irrelevant at uninstall — we only care whether
  the stored hash matches what's on disk, regardless of whether the toggle was
  left on or off) and pulling in `SPFW_Settings`'s static-cache machinery for a
  script that runs exactly once and exits would be pure overhead. A tiny local
  `spfw_uninstall_filesystem()` helper mirrors the same `WP_Filesystem` init
  pattern used in `SPFW_Htaccess`/`SPFW_Module_Fonts` (Steps 7/8) to avoid
  duplicating that boilerplate twice within the same file. Multisite is handled
  by looping `get_sites()`/`switch_to_blog()`/`restore_current_blog()` around
  the same per-site cleanup function — no network option exists in v1, so
  there's nothing to `delete_site_option`.
  **Verified** with a stubbed harness against a real temp filesystem: a normal
  run removes the authored `.htaccess`, the whole `ods-fonts/` directory
  (recursively), and the `spfw_settings` option; re-running cleanup with
  everything already absent produces no errors (idempotent); and a foreign or
  stale-hash `.htaccess` is confirmed untouched. `php -l` clean across every
  PHP file in the repo. Harness was scratch-only, not committed.

  **Phase 1 is now fully complete: all 9 steps built, verified, and pushed to
  `claude/simple-performance-wordpress-plugin-6qbso2`.** The plugin implements
  all four modules from `IMPLEMENTATION_PLAN.md` (core toggles, REST API
  controls, directory hardening, Google Fonts localizer) behind a single
  React + Tailwind v4 admin app talking to one REST settings endpoint, backed
  by the single autoloaded `spfw_settings` option. Not yet done (out of scope
  for these 9 steps): a `.pot` translation file, a `readme.txt`
  (WordPress.org-style), and end-to-end manual QA against a live OpenLiteSpeed
  + LiteSpeed Cache install (everything so far has been verified with stubbed
  PHP harnesses and a real `npm run build`/lint pipeline, not a running
  WordPress site).
- 2026-07-10 (post-Phase-1 follow-up): added WordPress.org submission
  readiness — `readme.txt` (standard `.org` format: headers, Description,
  Installation, FAQ, Changelog; short description 128 chars, under the
  150-char limit; 5 tags; `Stable tag: 1.0.0` matches the plugin header
  version), `languages/simple-performance-for-wordpress.pot` (generated for
  real via WP-CLI's `wp i18n make-pot` — downloaded the phar since it wasn't
  preinstalled — scanning **both** PHP and JSX sources, 77 unique strings with
  correct file:line references; confirmed by spot-checking entries attributed
  to `src/components/*.jsx`), a `Domain Path: /languages` header added to the
  main plugin file, and `.distignore` (excludes `node_modules/`, `src/`,
  `docs/`, `IMPLEMENTATION_PLAN.md`, `STATE.md`, `README.md`, and the npm/build
  config files from the release ZIP — none of that dev tooling belongs in a
  shipped plugin). **Still manual/outstanding before an actual `.org`
  submission:** run `npm install && npm run build` and zip per `.distignore`
  to produce the release artifact; the submission itself
  (wordpress.org/plugins/developers/add/) requires a human with a WP.org
  account; and optional SVN `/assets` graphics (banner/icon/screenshots)
  can't be produced without a live running install to actually screenshot.
  This work sits outside the original 9-step Phase 1 plan, so it isn't a row
  in the Progress table above — noted here for continuity only.
- 2026-07-10 (Step 10): Quick-toggle parity + WooCommerce tab,
  built on branch `claude/feature-parity-quick-toggles-sf64kt`. Per user
  direction: **no Change Login URL**; **Heartbeat and post options match
  the feature-parity spec** (replaced `heartbeat_mode`/`heartbeat_interval` with
  `heartbeat_control` [default|disable|allow_posts] + separate
  `heartbeat_frequency`; added `post_revisions` [default|disable|1–30] and
  `autosave_interval` [0=default|1–5 min]); **Google Maps included** as a
  `template_redirect` output-buffer scrub of external Maps scripts + map
  iframes. Autosave uses `define('AUTOSAVE_INTERVAL', …)` in
  `SPFW_Module_Core::register()` — safe because `plugins_loaded` (when the
  module registers) runs before `wp_functionality_constants()` defines the WP
  default, so our value wins via the `if (!defined())` guard. New WooCommerce
  module bails immediately unless `class_exists('WooCommerce')`, added to
  `SPFW_Plugin::MODULES`. `hide_wp_version`/`remove_shortlink`/
  `disable_self_pingbacks` default **on** (harmless cleanup, matching the
  plugin's existing opinionated defaults) — a slight deviation from the plan's
  "all new toggles default off" note, chosen for consistency with the existing
  aggressive Core defaults; all behavior-changing toggles (comments, maps,
  password meter, feeds, favicon, etc.) default off. Admin now localizes
  `woocommerceActive`; the WooCommerce React tab only mounts when true. REST
  save fires `litespeed_purge_all` (no-op without LSCache). Admin UI adopted
  the sister plugin's floating meta-box cards via a shared `SettingsCard`
  component (Core split into 5 cards; REST/Hardening/Fonts each wrapped in one).
  **Verified:** `php -l` clean; scratch PHP hook-registry/sanitize/helper
  harness green (default vs all-on wiring, sanitize whitelists+clamps,
  WooCommerce no-op-without-Woo, self-pingback/Maps-scrub/revisions/version-arg/
  comment-URL helpers); `npm run build` + `lint:js` + `lint:css` clean. Harness
  scratch-only, not committed. **Outstanding:** regenerate `.pot`; live QA.

- 2026-07-11 (REST tab fixes, branch `claude/toggles-404-routes-fix-zufbab`):
  two UI/behavior corrections to the REST API tab.
  **(1) Disable-namespaces layout** — the namespace toggles were a fixed
  `grid grid-cols-1 sm:grid-cols-2` crammed into `SettingsRow`'s right-hand
  control column, which handled poorly on sites with many registered routes.
  Pulled the "Disable namespaces" section out of `SettingsRow` into a
  full-width block inside the card: title/description on top, then the toggles
  flowing **underneath** as a flexbox grid (`flex flex-wrap gap-3`, each chip
  `grow basis-72`) so they wrap cleanly across the full card width regardless
  of route count. The advanced textarea stays beneath.
  **(2) 404 section only showed `users`** — `SPFW_Module_RestApi::unregister_disabled_namespaces()`
  (hooked on `rest_endpoints`) stripped every disabled namespace from the route
  table for **all** requests, including the admin loading the settings page.
  The React checklist is built from `apiFetch('/')`'s `data.namespaces`, so
  every currently-disabled namespace (defaults now disable users/themes/
  comments/settings/taxonomies) had already vanished from the index the admin
  fetched — gutting the checklist. Fix: added `user_is_exempt()`
  (`is_user_logged_in() && current_user_can('edit_posts')` — "can edit content")
  and early-return the endpoints untouched in `unregister_disabled_namespaces()`
  when exempt, so admins/editors always see the full index (restriction still
  applies to anonymous scanners). Also switched `authenticate_request()`'s 404
  branch from `manage_options` to the same `user_is_exempt()` helper so editors
  aren't inconsistently 404'd on routes the filter left registered for them.
  Updated the tab's description copy to match the behavior ("…for logged-out
  visitors… Logged-in users who can edit content are never restricted.").
  **Verified:** `php -l` clean; `npm run build` succeeds; `npm run lint:js`
  clean on the changed `RestApiSettings.jsx` (2 pre-existing
  `CoreSettings.jsx:476` a11y errors are untouched and out of scope);
  `npm run lint:css` clean.

- 2026-07-11 (Google Fonts discovery fix, branch
  `claude/google-fonts-discovery-plan-tjsdwr`): the fonts localizer reliably
  discovered **zero** fonts on real sites. Root cause: discovery was a single
  loopback fetch of the homepage HTML + a narrow regex
  (`#https://fonts\.googleapis\.com/css2?\?…#`) that only matched `https://`
  URLs with the query starting immediately — so protocol-relative
  (`//fonts.googleapis.com/…`), v1 `css?` used inside theme CSS, `@import`ed
  fonts, and any font not literally in the homepage markup were all missed, and
  a blocked/cached/redirected loopback returned no fonts at all.
  **Fix (in `class-spfw-module-fonts.php`):** discovery now captures fonts from
  WordPress's own style pipeline during an instrumented loopback render. `scan()`
  mints a one-time token (stored in the `spfw_font_scan_token` transient) and
  loads the homepage with that token + a cache-buster; `register()` — on the
  loopback request only, gated by `hash_equals()` against the transient and never
  in `is_admin()` — attaches a `style_loader_src` filter that records every
  `//fonts.googleapis.com/css` src into the `spfw_font_scan_urls` transient
  (flushed on `shutdown`), which `scan()` reads back. This catches enqueued
  Google Fonts regardless of protocol/version/handle — the primary reliability
  win. Two fallbacks union in alongside it: a **broadened** regex
  (`(?:https?:)?//…/css2?\?…`, entity-decoded) over the returned HTML, and
  **same-origin CSS following** (fetch up to `MAX_LINKED_CSS=10` linked
  stylesheets and scan them for `@import`ed Google Fonts). Loopback hardened
  (browser UA, `timeout` 20, `redirection` 5, `sslverify=>false` retry on
  `WP_Error`). `scan()` now distinguishes a real fetch failure (→ `WP_Error`
  with an actionable "server may block loopback" message) from "loaded fine, no
  fonts found" (→ soft result; **existing `discovered` is left intact** so a
  transient blip never wipes working fonts — only `last_scan` refreshes).
  `parse_font_faces()` hardened: dedupe by src URL, keep `font-style`
  (italic → `:400i` label suffix) and weight ranges (`font-weight: 100 900`).
  The `scan-fonts` REST route returns a `scan_result` summary
  (`{families, files, message}`); `FontsSettings.jsx` shows a family/file count,
  a "No Google Fonts detected" zero-state, and the scan message, and `App.jsx`
  toasts that message (info vs success). **No reference to any third-party
  plugin in code or docs.**
  **Verified:** `php -l` clean on both changed PHP files; `npm run build` +
  `lint:js` + `lint:css` clean on the changed files (2 pre-existing
  `CoreSettings.jsx:476` a11y errors untouched/out of scope). Two scratch PHP
  harnesses (reflection over private methods; not committed): (1) broadened URL
  matching for protocol-relative/v1/v2/entity-encoded, normalize→https+dedupe,
  parse dedupe+italic+weight-range, `capture_style_src` records Google-only, and
  the token gate (no token / valid token / wrong token / admin context) all
  behave correctly; (2) full `scan()` flow with mocked network+filesystem
  confirms success (captured + HTML union → families/files/CSS persisted),
  empty (soft result, `discovered` preserved, `last_scan` refreshed), and
  fetch-fail (`WP_Error`). **Live end-to-end QA against a real Google-Fonts
  theme still outstanding** (no running WP in this environment).

- 2026-07-11 (Hardening toggle write bug + expanded hardening options, branch
  `claude/toggle-htaccess-plan-fsl3p0`, version → 1.3.0):
  **Root cause of "the toggle does nothing / nothing written to .htaccess":**
  a re-entrancy / stale-static-cache defect in `SPFW_Settings::update()`. It
  called `update_option()` (which fires `update_option_{$option}`
  **synchronously**, before control returns) and only invalidated the static
  cache on the *next* line. The hardening module's `update_option_*` listener
  writes the .htaccess and then calls `SPFW_Settings::update()` again to store
  the file hash; that nested `update()` read the **still-stale** cache (holding
  the pre-save `plugins_htaccess = false`), merged the hash onto the old
  toggle value, and persisted `plugins_htaccess = false` — silently reverting
  the user's ON toggle and re-firing the hook so `remove()` ran. Net effect:
  DB ended false, REST echoed false, UI snapped the toggle back off, status
  `disabled`. The old Step 7 log note (this nested pass "harmlessly no-ops")
  was wrong precisely because of the stale cache. **Fix:** seed
  `self::$cache = $clean` **before** the `update_option()` call (and drop the
  trailing `self::$cache = null`), so every re-entrant `get()` during the hook
  is consistent with what's being written. Verified with a scratch harness
  that fires the real `update_option_*` hook: the fixed code persists the
  toggle + hash + on-disk file (`ok`); reverting to the old ordering makes the
  same harness fail exactly as reported (toggle → false, status not `ok`).
  Also hardened the failure path: `restore_htaccess` (REST) now returns a 500
  with an actionable message when the filesystem write fails (previously the
  boolean was ignored and the UI showed a false success), so hosts without
  direct `WP_Filesystem` write access get real feedback.
  **Expanded hardening (Hardening tab, per user request):**
  - `SPFW_Htaccess` generalized from a single hardcoded plugins target to a
    `plugins`/`uploads` target map (per-target path/toggle/hash). New
    **"Block PHP execution in uploads"** drops the same `<Files *.php>` deny
    file into `wp-content/uploads/` (uploads is the top malware landing spot).
  - New `SPFW_Module_Hardening` runtime toggles (no .htaccess, so
    OLS-override-independent): **Disable theme/plugin file editor**
    (`DISALLOW_FILE_EDIT` define, guarded so wp-config.php always wins);
    **Block author enumeration** (`template_redirect` priority 1 — before
    `redirect_canonical` can leak a username — redirects anonymous `?author=N`
    / `/author/slug/` to home); **Send security headers** (`send_headers`:
    `nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, a restrictive
    `Permissions-Policy`; deliberately no HSTS/CSP).
  - Schema: `hardening` gains `uploads_htaccess`, `uploads_htaccess_hash`,
    `disable_file_editing`, `block_author_enum`, `security_headers` (all
    default **false** — opt-in). No migration needed: `merge_recursive` fills
    the new keys from defaults for existing installs.
  - `activate()`/`deactivate()` and `uninstall.php` now handle both .htaccess
    targets (uninstall still hash-gated per target — never removes a foreign
    file). REST `get_settings` adds `uploads_hardening_status`;
    `restore-htaccess` accepts a `{target}` body param.
  - `HardeningSettings.jsx` restructured into two cards (Directory Hardening:
    plugins + uploads file toggles with per-file status/Restore; Site
    Hardening: the three runtime toggles). `App.jsx` restore handler takes a
    target.
  **Deliberate choice:** the .htaccess payload stays `<Files *.php>`-only — I
  did **not** add `Options -Indexes` (directory-browsing block), because
  `Options` in .htaccess requires `AllowOverride Options` and 500s an Apache
  vhost that lacks it; too risky for the uploads dir especially. Payload
  comment genericized to "this directory"; existing plugins files keep their
  stored hash and stay `ok` until the next write (no false `altered`).
  **Verified:** `php -l` clean on all changed PHP; `npm run build` +
  `lint:js` (changed files) + `lint:css` clean; scratch harness (16 checks:
  toggle-on persists, dual independent targets, altered-detect + restore,
  foreign-file protection, toggle-off removal) green, and proven to fail on the
  pre-fix ordering. **Outstanding:** live QA on a real OLS + WP install
  (confirm both .htaccess files land and are honored with Allow Override on;
  confirm the file editor disappears, `?author=1` redirects home, and the
  security headers appear in responses); regenerate `.pot` for the new strings.

- 2026-07-11 (release housekeeping): merged `claude/toggle-htaccess-plan-fsl3p0`
  into `main` (fast-forward — `main` previously held only the initial commit, so
  this brings the entire built plugin onto `main` for the first time). Deleted the
  historical `docs/build-steps/` per-step spec files (Phase 1 is shipped; the
  condensed steps + decisions log in this file are now the authoritative record)
  and pruned the matching stale `docs`/`IMPLEMENTATION_PLAN.md` entries from
  `.distignore`. Bumped `readme.txt` to `Stable tag: 1.3.0` with a 1.3.0 changelog
  entry and expanded its hardening section. Produced a test ZIP
  (`simple-performance-for-wordpress-1.3.0.zip`) from a fresh `npm run build`,
  packaged per `.distignore`.

- 2026-07-11 (Content-Security-Policy header, branch
  `claude/state-md-missing-header-pbhit2`, version → 1.4.0): added the last
  missing security header flagged by an external scan (all others —
  X-Content-Type-Options / X-Frame-Options / Referrer-Policy /
  Permissions-Policy / HSTS — already pass; only CSP was red). CSP is the one
  header that routinely breaks sites, so it is a **separate** opt-in toggle from
  the existing `security_headers` set, with safety/exclusion controls rather
  than a single always-on line.
  **Schema (`hardening` group, all safe defaults):** `csp_enabled` (false),
  `csp_report_only` (**true** — first enables `Content-Security-Policy-Report-Only`
  so violations are logged in the console without blocking; admin flips it off to
  enforce), `csp_exclude_logged_in` (**true** — skips the header for logged-in
  users so the block editor / customizer / admin bar, all heavy inline JS, never
  break), `csp_policy` ('' — the full policy string; empty ⇒ the shipped
  recommended default is used). Sanitizer for `csp_policy` deliberately does
  **not** use `sanitize_text_field()` (it would strip the `'self'`/`'unsafe-inline'`
  single quotes CSP requires) — instead it flattens line breaks (UI uses a
  textarea for readability), collapses whitespace, strips control chars, caps at
  2000 chars.
  **PHP (`SPFW_Module_Hardening`):** `DEFAULT_CSP` constant — a pragmatic
  WP-safe baseline (`default-src 'self'`; `'unsafe-inline'` + `https:` for
  style/script since WP/themes emit inline styles+scripts; `data:` images/fonts;
  `object-src 'none'`; `base-uri 'self'`; `frame-ancestors 'self'`). Registered
  on `send_headers` (never fires in wp-admin ⇒ dashboard auto-excluded), gated on
  `csp_enabled`, independent of the `security_headers` hook. `add_csp_header()`
  bails on `headers_sent()`, bails for logged-in users when
  `csp_exclude_logged_in`, picks the report-only vs enforcing header name by
  toggle, uses `csp_policy` or falls back to `DEFAULT_CSP`.
  **REST:** `get_settings()` now also returns read-only `csp_default`
  (= `SPFW_Module_Hardening::DEFAULT_CSP`) so the React "Load recommended
  policy" button and the textarea placeholder can show it without hardcoding the
  policy in JS. No new route (generic settings POST persists the new keys).
  **React (`HardeningSettings.jsx`):** new "Content-Security-Policy" card in the
  Hardening tab — master toggle; when on, reveals Report-Only toggle,
  do-not-apply-to-logged-in toggle, and an editable mono policy textarea
  (placeholder = recommended default) with a "Load recommended policy" button.
  Prominent copy: test in Report-Only until the console is clean before
  enforcing. Updated the `security_headers` row copy (no longer says CSP is
  omitted). **Verified:** `php -l` clean on the 3 changed PHP files;
  `npm run build` succeeds; `wp-scripts lint-js` clean on `HardeningSettings.jsx`;
  `npm run lint:css` clean. **Outstanding (unchanged):** live QA on a real
  OLS + WP install (confirm the CSP header appears on front-end responses, is
  absent in wp-admin and for logged-in users when excluded, and Report-Only vs
  enforce switch correctly); regenerate `.pot` for the new strings.

- 2026-07-13 (Strict-Transport-Security header, branch
  `claude/missing-security-headers-x8gyp9`, version → 1.5.0): an external
  Security Headers scan (securityheaders.com) flagged `hayeswindows.com` at
  grade B — Strict-Transport-Security was the only actually-missing header;
  the plugin had no code emitting it anywhere. (Content-Security-Policy was
  also flagged, but that's expected/by-design: the site had CSP enabled in
  Report-Only mode, which the scanner doesn't count as the enforced header —
  not a bug, just the deliberate default from the 1.4.0 CSP work.) Same gap
  applies whether or not the site sits behind a QUIC.cloud reverse proxy.
  **PHP (`SPFW_Module_Hardening`):** new `add_hsts_header()`, hooked on
  `send_headers` like CSP — a **separate opt-in toggle** (`hsts_enabled`),
  not folded into the existing `security_headers` toggle, because HSTS is
  sticky: once a browser sees it, it refuses plain HTTP for `max-age`
  regardless of later settings changes, so it deserves its own explicit
  consent exactly like CSP already does. Bails on `headers_sent()` and on a
  new `is_https_request()` check. **`is_https_request()` is the key fix for
  proxied sites:** LiteSpeed/QUIC.cloud (and most reverse proxies) terminate
  TLS at the edge, so `is_ssl()` alone sees only the plain-HTTP connection to
  the origin and would never fire HSTS on an HTTPS site sitting behind such a
  proxy. The helper additionally accepts `X-Forwarded-Proto: https`,
  `X-Forwarded-Ssl: on`, or `X-Forwarded-Port: 443` — standard reverse-proxy
  signals — so the header fires correctly with or without a proxy in front.
  Header value assembled from three settings: `hsts_max_age` (whitelisted to
  1 day/1 week/1 month/6 months/1 year/2 years, default 1 year — matches
  Security Headers' own recommended value),
  `hsts_include_subdomains` (adds `; includeSubDomains`), `hsts_preload`
  (adds `; preload`).
  **Schema (`hardening` group, all safe defaults):** `hsts_enabled` (false),
  `hsts_max_age` (31536000), `hsts_include_subdomains` (false), `hsts_preload`
  (false). Sanitizer whitelists `hsts_max_age` against the six UI-offered
  durations, falling back to the 1-year default for anything else. No new
  REST route — HSTS persists through the same generic settings POST as every
  other hardening toggle. No migration needed (new keys fill in via
  `merge_recursive`/`sanitize` fallbacks for existing installs, same pattern
  as every prior hardening addition).
  **React (`HardeningSettings.jsx`):** new "HTTP Strict Transport Security"
  card after the CSP card — master toggle with an explicit warning that HSTS
  forces HTTPS for the chosen duration; when on, reveals a max-age `<select>`,
  an "Include subdomains" toggle (with a warning to only enable once every
  subdomain is confirmed HTTPS-ready), and a "Preload" toggle (with a warning
  that hstspreload.org submission is very hard to reverse).
  **Verified:** `php -l` clean on all 3 changed PHP files; a scratch PHP
  harness (not committed) exercised the `hsts_max_age` whitelist/fallback
  logic, the `is_https_request()` proxy-header matrix (direct HTTPS, plain
  HTTP, `X-Forwarded-Proto: https`/`HTTPS`, `X-Forwarded-Ssl: on`,
  `X-Forwarded-Port: 443`, and a proxy explicitly forwarding `http` which
  must NOT trigger HSTS), and the assembled header string for all three
  toggle combinations — all passed. `npm install && npm run build` succeeds;
  `wp-scripts lint-js --fix` cleaned 6 prettier-only formatting findings on
  the new `<select>` options in `HardeningSettings.jsx` (no logic changes);
  `npm run lint:css` clean. **Outstanding:** live QA on a real HTTPS install
  (confirm the header appears on front-end HTTPS responses, is absent over
  plain HTTP, and correctly appears when simulating `X-Forwarded-Proto`
  behind a proxy); regenerate `.pot` for the new strings.

- 2026-07-14 (CSP visual builder + live violation warnings, branch
  `claude/missing-security-headers-x8gyp9`, version → 1.6.0): two-part feature
  on top of the 1.4.0 CSP header. **Part 1 — toggle builder.** The single raw
  `csp_policy` string is replaced (for new/default installs) by a structured
  `csp_directives` map edited via per-directive chips + an "additional hosts"
  field, with a live-generated preview. `csp_mode` ('builder'|'custom') picks
  the source: builder serializes `csp_directives`; custom (Advanced raw mode)
  keeps the existing textarea for arbitrary directives. New PHP in
  `SPFW_Module_Hardening`: `build_policy_from_directives()` (skips empty
  directives, collapses 'none'), `parse_policy_to_directives()`, and
  `default_csp_directives()` (derived by parsing `DEFAULT_CSP` so the string
  and the structured default can never drift — single source of truth, verified
  by a round-trip test). Sanitizer whitelists directive names
  (`SPFW_Settings::CSP_DIRECTIVES`) and per-token charset, **rejecting any token
  with whitespace/`;`/control chars** so a token can't inject a new directive,
  and **preserves empty token-lists** (does not drop them) because the builder
  submits a fixed row set — storing `[]` is what makes a cleared directive stick
  instead of the default resurrecting on the next `merge_recursive`. Migration
  to 1.6.0: an install that already had a non-empty `csp_policy` is pinned to
  `csp_mode='custom'` (its hand-tuned policy stays authoritative); everyone else
  defaults to the builder. **Part 2 — violation warnings, gated on Report-Only
  mode** (per user amendment: no separate collect toggle). `add_csp_header()`
  appends `report-uri` + `report-to` (with a `Reporting-Endpoints` header) **only
  when `csp_report_only` is on**. New REST routes on `SPFW_Rest_Settings`: a
  **public** `POST /spfw/v1/csp-report` whose callback is **closed (403, stores
  nothing) unless `csp_enabled && csp_report_only`** — the plugin's first
  intentionally public route; hardened with an 8 KB body cap, content-shape
  parsing for both legacy `application/csp-report` and modern
  `application/reports+json` batches, dedupe by (directive, blocked-origin) with
  count bumping, a 50-entry cap with least-recently-seen eviction, and a 7-day
  transient store (never the autoloaded option). Admin-only `GET`/`DELETE` view
  and clear the log. `get_settings()` now also returns `csp_default_directives`
  and `csp_reports`. **React:** extracted the CSP card into
  `src/components/CspPolicyCard.jsx` (chips per directive, live preview,
  Advanced raw toggle, per-directive amber warning boxes with one-click
  "Allow", an "Other violations" bucket, Refresh/Clear, and a 20s poll while
  Report-Only is on); `App.jsx` gained `handleRefreshCspReports`/
  `handleClearCspReports` (functional `setSettings` so polling never clobbers
  unsaved edits). Fixed a controlled-input trap where deriving the hosts field
  value from parsed tokens on every keystroke stripped the trailing space
  needed to type a second host — the field is now backed by local `hostText`
  state, cleared per-directive on the discrete actions (Allow, 'none', reset)
  that change hosts out-of-band. **Verified:** `php -l` clean on all changed
  PHP; two scratch harnesses (27 checks total, not committed) covering
  serializer/parser round-trip, sanitizer token-whitelist + injection rejection
  + empty-preservation, full `sanitize()` integration, the 1.6.0 migration,
  violation extraction (both report shapes), directive/origin normalization,
  and dedup/eviction storage — all green. `npm run build` +
  `wp-scripts lint-js` (changed files, incl. the new component) +
  `lint-style` clean. **Outstanding:** live QA on a real HTTPS install (confirm
  a blocked host in Report-Only surfaces on the right directive, "Allow" adds
  it, enforcing removes `report-uri` and closes `POST /csp-report`); regenerate
  `.pot` for the new strings.

- 2026-07-14 (CSP reporting reliability + enforce-mode collection, branch
  `claude/missing-security-headers-x8gyp9`, version → 1.6.1): shipped 1.6.0 to
  the user for QA; a console screenshot of onedog.solutions showed a wall of
  `script-src` violations blocking `data:text/javascript;base64,…` scripts
  (LiteSpeed/QUIC.cloud inlines JS as data: URIs) with **none** collected in the
  admin. Diagnosis: (1) the site was **enforcing** ("has been blocked", no
  "[Report Only]" prefix), and 1.6.0 only collected in Report-Only mode, so
  nothing was captured; (2) even in Report-Only, `add_csp_header()` emitted
  **both** `report-uri` and `report-to` — Chrome ignores `report-uri` when
  `report-to` is present and switches to the Reporting API, which batches/delays
  reports up to a minute, so they appeared to never arrive. **Fixes:**
  - `add_csp_header()` now emits **`report-uri` only** (removed `report-to` +
    `Reporting-Endpoints` + the `CSP_REPORT_GROUP` constant) for immediate,
    per-violation delivery — the reliability fix.
  - **Collect whenever CSP is enabled, enforce mode included** (user decision,
    reversing the earlier Report-Only-only gate): `add_csp_header()` appends
    `report-uri` in both modes, and `receive_csp_report()` is gated on
    `csp_enabled` alone (still fully closed 403 when CSP is off). So real
    production breakage after enforcing is still surfaced as warnings. Poll +
    UI copy updated to match (enforce-mode warnings flagged amber as "currently
    blocked on your live site").
  - `data:` added to the default `script-src` (in both `DEFAULT_CSP` and the
    `csp_directives` schema default) so LiteSpeed's data:-URI inline scripts
    aren't blocked out of the box on the plugin's own target platform. Marginal
    XSS tradeoff since `'unsafe-inline'` is already present.
  - React "Allow" now maps bare scheme blocks to real tokens
    (`data`→`data:`, `blob`→`blob:`, plus the existing `inline`→`'unsafe-inline'`
    / `eval`→`'unsafe-eval'`), since browsers report a blocked data: script as
    the bare word "data".
  **Verified:** `php -l` clean; scratch harness confirms the new default
  round-trips and `report-to`/`CSP_REPORT_GROUP` are gone; `npm run build` +
  `lint-js` (changed component) clean. **Note for user:** on their live site the
  instant un-break (no update needed) is to flip Report-Only back ON — it never
  blocks. **Outstanding:** live QA of the 1.6.1 build (confirm reports now land
  promptly in both modes and "Allow" of a data: block adds `data:`); regenerate
  `.pot`.

- 2026-07-15 (localized fonts render bold — root cause + plan, branch
  `claude/plugin-font-weight-issues-2xfjms`): user reported (with DevTools
  screenshots of onedog.solutions) that after font localization, footer
  newsletter links and blog archive/single-post body copy render at ~700
  while computed styles show `font-weight: 400`. **Root cause found and
  verified live against Google's API — this is NOT a discovery gap** (which
  is what 1.7.0/`e986f48` addressed): Google serves variable fonts for many
  families now, and for Roboto Condensed v31 both `css?…:300,400,700` (v1,
  what BB Theme enqueues) and `css2?…wght@300;400;700` return 21 `@font-face`
  blocks over only **7 unique `.woff2` URLs** (one shared file per
  unicode-range subset, blocks in ascending weight order). The module dedupes
  faces **by src URL** in three places (`parse_font_faces()`,
  `scan()`'s union, `find_inlined_gstatic_faces()`), so the last block per
  URL — always the heaviest weight — is the only one that survives into the
  generated `fonts.css`. The stylesheet ends up declaring the family at 700
  only; the browser uses that sole face for all weights (VF instanced at the
  700 descriptor), body text renders bold, computed style still reports 400.
  Wrote `FONT_WEIGHT_FIX_PLAN.md` (root, `.distignore`d) specifying the fix.

- 2026-07-15 (font-weight-collapse fix implemented, → 1.7.1, same branch):
  implemented `FONT_WEIGHT_FIX_PLAN.md` in full.
  **F1 — identity-keyed dedupe** (`class-spfw-module-fonts.php`):
  `parse_font_faces()` now keys each parsed face on `sha1()` of its
  whitespace-normalized block text (added as `$face['key']`) instead of
  `$face['src_url']`, so every distinct weight/style/unicode-range block
  survives even when several share one `.woff2` URL; byte-identical blocks
  seen twice (e.g. captured via both the enqueue pipeline and an HTML regex
  pass) still collapse to one. `scan()`'s per-CSS-URL union and
  `find_inlined_gstatic_faces()` both switched from keying on `src_url` to
  keying on `key`.
  **F2 — per-scan download memoization**: faces now legitimately share a
  `src_url` (up to 3 weights per file for a typical VF family), so `scan()`
  gained a `$downloaded[ $src_url ]` memo keyed by URL — each unique
  `.woff2` is fetched from `fonts.gstatic.com` exactly once per scan
  regardless of how many faces reference it; `$files`/`families` still
  report one row per face (correct — same file, different weight labels).
  **F3 — stale-install remediation**: `fonts.needs_rescan` (bool, default
  false) added to the schema (`class-spfw-settings.php` defaults +
  `sanitize()` via `to_bool()`). New migration
  `run_font_rescan_migration()` fires once, when
  `version_compare($stored_ver, '1.7.1', '<')` and
  `fonts.discovered.css` is non-empty — flips `needs_rescan` true without
  touching the existing (still-serving) CSS, so nothing breaks mid-upgrade.
  `SPFW_Module_Fonts::finish_scan()` now always sets `needs_rescan = false`
  (found or empty result) since any scan under the fixed generator
  supersedes the stale marker. Surfaced two ways, mirroring
  `SPFW_Module_Hardening`'s missing/altered pattern: a dismiss-by-fixing
  `admin_notices` warning (`maybe_show_rescan_notice()`/
  `render_rescan_notice()`, gated `manage_options`, linking to the Fonts
  tab — note the link's `tab=fonts`/`tab=hardening` query arg is decorative
  only, since `App.jsx` doesn't read a tab param from the URL; this matches
  the pre-existing Hardening notice's same limitation, not a new one) and an
  amber banner at the top of `FontsSettings.jsx`'s card when
  `fonts.needs_rescan` is true.
  **F4**: bumped to 1.7.1 (plugin header + `SPFW_VERSION` +
  `readme.txt` stable tag/changelog).
  **Verified:** three scratch PHP harnesses (reflection + mocked
  `wp_remote_get`/temp-dir filesystem/stubbed `get_option`, not committed),
  built against the *real* Google Fonts API responses captured live this
  date (21 blocks / 7 URLs for `Roboto Condensed:300,400,700` on both v1 and
  v2 endpoints): (1) parser — all 21 blocks survive (vs. 7 under the old
  URL-keyed dedupe, confirmed by deliberately reproducing the old logic
  inline and showing it collapses to weight-700-only), every shared URL
  keeps all 3 weights, duplicate-content union still dedupes correctly,
  static per-weight-URL families unaffected, `find_inlined_gstatic_faces()`
  keeps all 21; (2) full `scan()` flow — exactly 7 network fetches for 21
  faces (memoization), exactly 7 files on disk, generated CSS has 7 blocks
  each at weight 300/400/700 with zero remaining `gstatic.com` references,
  `needs_rescan` clears; (3) migration — fresh installs and never-scanned
  1.7.0 installs stay `false`; a 1.7.0 install with existing `discovered.css`
  flips to `true` and it's actually persisted (re-fetched with cache
  cleared); a 1.7.1 install that's already been rescanned is left alone.
  `php -l` clean on all 3 changed PHP files; `npm install && npm run build`
  succeeds; `wp-scripts lint-js` clean on `FontsSettings.jsx` (the 2
  pre-existing `CoreSettings.jsx:476` a11y errors are untouched/out of
  scope, confirmed by a full `src/` lint pass). **Outstanding:** live
  QA on onedog.solutions (re-scan, confirm the banner/notice clear, confirm
  all three weights present in the served `fonts.css`, purge LSCache, and
  visually confirm footer/blog copy renders at the correct weight); `.pot`
  regeneration remains outstanding project-wide (unchanged backlog item).

- 2026-07-15 (release housekeeping): merged `claude/plugin-font-weight-issues-2xfjms`
  into `main` (fast-forward — no divergence). Produced a test ZIP
  (`simple-performance-for-wordpress-1.7.1.zip`, gitignored, not committed) from a
  fresh `npm run build`, staged and packaged per `.distignore` (verified `build/`
  present, all excluded dev paths absent, `php -l` clean on every staged PHP file,
  version header confirmed 1.7.1) for the user to install and QA on a live
  WordPress site.

- 2026-07-20 (Disable WP Sitemaps + Remove robots max-image-preview, → 1.8.0,
  branch `claude/wp-sitemaps-robots-toggles-eaoris`, merged to `main`): folded
  two single-hook standalone plugins ("Disable WP Sitemaps" 1.8.9 and "Disable
  WP Robots" 2.4) into the Core module as two new toggles rather than new
  modules — both map directly onto the existing boolean-setting → conditional-
  hook pattern. **Schema** (`class-spfw-settings.php`): added
  `core.disable_wp_sitemaps` and `core.remove_robots_max_image_preview` to
  `defaults()` (both `false`) and to the `$core_bools` sanitize list. No
  migration needed — additive boolean defaults merge in on every `get()` via
  `merge_recursive()`, so existing installs pick them up as OFF with no
  behavior change. **Behavior** (`class-spfw-module-core.php`): in `register()`,
  `disable_wp_sitemaps` → `add_filter( 'wp_sitemaps_enabled', '__return_false' )`
  (disables core `wp-sitemap.xml`); `remove_robots_max_image_preview` →
  `remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' )` (drops the
  `max-image-preview:large` directive from the robots meta tag). The
  `remove_filter` runs at `plugins_loaded`/`register()` time, before `wp_robots`
  fires in `wp_head`, matching the source plugin. **UI**
  (`CoreSettings.jsx`): two `toggleRow()` entries appended to the **Head
  Cleanup** card (robots meta + sitemap are both head/discovery output); no
  wiring changes since `App.jsx` already routes Core toggles through
  `handleChange('core', …)`. **Versioning**: bumped to 1.8.0 (plugin header +
  `SPFW_VERSION` + `readme.txt` stable tag, feature list, and `= 1.8.0 =`
  changelog). Only the trivial, un-copyrightable hook calls were reimplemented;
  the source plugins' GPL headers/readmes and their `disable-wp-sitemaps` /
  `disable-wp-robots` text domains were not carried over (new strings use this
  plugin's `simple-performance-for-wordpress` domain). **Verified:** `php -l`
  clean on both changed PHP files; `npm install && npm run build` succeeds and
  the minified `build/index.js` contains both new setting keys.
  **Outstanding:** live WordPress QA (see Next action) — the two runtime
  behaviors were not exercised in the build environment; `.pot` regeneration
  remains the unchanged project-wide backlog item.

- 2026-07-20 (release housekeeping): merged
  `claude/wp-sitemaps-robots-toggles-eaoris` into `main` (fast-forward — no
  divergence). Produced a test ZIP (`simple-performance-for-wordpress-1.8.0.zip`,
  gitignored, not committed) from a fresh `npm run build`, staged and packaged
  per `.distignore` (verified `build/` present, dev paths absent, `php -l` clean
  on staged PHP, version header 1.8.0) for the user to install and QA on a live
  WordPress site.

- 2026-07-22 (CSP policy builder coverage gaps, → 1.9.0, branch
  `claude/policy-builder-coverage-gaps-3dwztj`, merged to `main`): landed two
  coverage-gap fixes to the CSP policy builder that were developed in parallel
  and originally tagged 1.8.0 on their branch; since `main` had already shipped
  1.8.0 for the sitemaps/robots toggles, the work was re-versioned to **1.9.0**
  on merge (plugin header + `SPFW_VERSION` + `readme.txt` stable tag and
  changelog). **worker-src:** added a "Workers (Web / Service / Shared Workers)"
  directive row to the builder (`CspPolicyCard.jsx`), added `worker-src`
  to the managed-directive allowlist and to the recommended default policy
  (`class-spfw-settings.php` — `'self' blob:`), and to `DEFAULT_CSP`
  (`class-spfw-module-hardening.php`); `blob:` added as a preset chip on the
  Scripts and Styles rows. **Effective-directive collapse:** violations reported
  against the granular `script-src-elem`/`script-src-attr`/`style-src-elem`/
  `style-src-attr` fallbacks are now folded to their base `script-src`/
  `style-src` directive via a new `DIRECTIVE_ALIASES` map in
  `class-spfw-rest-settings.php`, so they group under (and can be "Allow"-ed
  from) the row the policy actually emits instead of the "other" bucket.
  **Verified:** `php -l` clean on all changed PHP; `npm run build` succeeds.
  **Outstanding:** `.pot` regeneration remains the standing backlog item; live
  WordPress QA of the new worker-src row and violation grouping not exercised in
  the build environment.

- 2026-07-22 (Beaver Builder font discovery removed, 1.10.0): the Beaver
  Builder settings-based font discovery (added earlier the same day, salvaged
  from the obsolete `claude/branch-cleanup-state-ck3owq` branch) was removed
  because it was causing fewer fonts to be discovered overall. The methods
  `beaver_builder_css_urls()`, `find_font_fields()`, `flatten_settings()`,
  `google_specs_to_urls()`, `beaver_builder_google_catalog()`, and
  `family_is_google_font()` were deleted from `class-spfw-module-fonts.php`,
  along with the call site in `scan()`. Font discovery reverts to the
  rendered-page scan + manual declarations approach.
  **Verified:** `php -l` clean.

- 2026-07-22 (translation template regenerated, 1.10.0): cleared the
  long-standing `.pot` backlog item that every recent release entry had deferred.
  Ran `wp i18n make-pot . languages/simple-performance-for-wordpress.pot
  --slug=simple-performance-for-wordpress
  --domain=simple-performance-for-wordpress
  --exclude=node_modules,build,vendor,.git,tests` (WP-CLI 2.12.0, same generator
  as the prior file). Extracts all strings that had accumulated unextracted since
  the file was last generated at 1.0.0 — Step 10 (quick-toggle parity +
  WooCommerce), the font-weight and discovery fixes, the security-headers/HSTS/CSP
  work, the sitemaps/robots toggles, and the 1.9.0 `worker-src` row — growing the
  template from ~40 to **225 msgids** and bumping Project-Id-Version to 1.10.0.
  No `.po`/`.mo` locale files exist yet, so nothing downstream needed
  reconciling. **Verified:** WP-CLI reported success; the new
  `Workers (Web / Service / Shared Workers)` string is present. A fresh release
  ZIP (`simple-performance-for-wordpress-1.10.0.zip`) was built afterwards for
  live install/QA.

- 2026-07-22 (CSP CDN reporting fix, → 1.11.0): user reported that after placing
  oregontradeswomen.org behind QUIC.cloud CDN, the CSP Policy Builder's blocked
  script discovery (violation-report collection) stopped working — GTM scripts
  showed `ERR_BLOCKED_BY_ORB` in DevTools but no violations appeared in the admin.
  **Root cause:** `csp_report_url()` used `rest_url()` verbatim, which derives
  from `siteurl` — behind a CDN that terminates TLS at the edge, this produced an
  `http://` report-uri on an `https://` page (mixed-content → browser silently
  drops the POST) or pointed at the origin hostname instead of the public domain.
  The plugin already solved identical proxy-awareness for HSTS (`is_https_request()`)
  but never applied it here. **Fixes (4 files):**
  - `class-spfw-module-hardening.php`: extracted `request_origin()` (returns
    `{scheme, host}` from `X-Forwarded-Proto`/`X-Forwarded-Ssl`/
    `X-Forwarded-Port`/`X-Forwarded-Host`/`HTTP_HOST`, comma-separated first-entry
    handling, port-suffix stripping); refactored `is_https_request()` to delegate
    to it (DRY); rewrote `csp_report_url()` to rewrite the `rest_url()` output's
    scheme+host from `request_origin()`; added `ensure_connect_src_allows()` which
    injects the report origin into the policy's `connect-src` when it differs from
    `home_url()`'s origin (no-op when same-origin or when `'self'`/`https:` already
    present).
  - `class-spfw-rest-settings.php`: `receive_csp_report()` now emits
    `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`,
    `Pragma: no-cache`, and `X-Robots-Tag: noindex, noarchive` before any logic,
    so QUIC.cloud/LiteSpeed Cache never caches the 204/403 response.
  - `CspPolicyCard.jsx`: when no violations are collected and CSP is enforcing
    (not report-only), shows a CDN diagnostic hint explaining forwarded-header
    requirements, REST-endpoint caching pitfalls, and clarifying that
    `ERR_BLOCKED_BY_ORB`/`ERR_BLOCKED_BY_RESPONSE` are not CSP violations.
  - Version bumped to 1.11.0 (plugin header + `SPFW_VERSION` + `readme.txt`
    stable tag + changelog).
  **Verified:** `php -l` clean on all 3 changed PHP files; `npm run build`
  succeeds; `wp-scripts lint-js` clean on `CspPolicyCard.jsx`; `npm run lint:css`
  clean. **Outstanding:** live QA on a QUIC.cloud-proxied site (confirm reports
  arrive, report-uri carries public HTTPS origin, endpoint not cached); `.pot`
  regeneration for the new CDN-hint string.

- 2026-07-22 (textarea multiline input fix, → 1.11.2): user reported that none
  of the textarea fields in the admin UI allowed typing more than one line.
  **Root cause:** the controlled `<textarea>` components in `FontsSettings.jsx`
  (Manual font weights, Extra pages to scan) and `RestApiSettings.jsx`
  (Whitelist routes) ran `textToList()` → `listToText()` on every keystroke;
  `textToList()` calls `.filter(Boolean)` which strips the trailing empty string
  produced by pressing Enter, so the newline was immediately removed from the
  controlled value. The "Disable namespaces" textarea in `RestApiSettings.jsx`
  was already immune (it used local state + `onBlur` commit). **Fix (2 files):**
  - `FontsSettings.jsx`: added `localManualFamilies` and `localExtraUrls` state
    with `useEffect` sync from external settings; both textareas now update only
    local state on change and commit the parsed list on blur.
  - `RestApiSettings.jsx`: added `localWhitelistText` state with `useEffect`
    sync; the Whitelist routes textarea uses the same local-state + blur-commit
    pattern.
  Version bumped to 1.11.2 (plugin header + `SPFW_VERSION` + `readme.txt`
  stable tag + changelog).
  **Verified:** `npm run build` succeeds (webpack 5.108.4, no errors).

- 2026-07-31 (Phase B — .htaccess subsystem rework, → 1.14.0):
  B0: refactored `SPFW_Htaccess` from hardcoded if/else to a TARGETS map
  with two ownership modes (`own_file` for plugins/uploads, `marker_block`
  for site root via `insert_with_markers()`). Added `legacy_payload_hashes()`
  and `run_payload_migration()` so pre-1.14.0 installs are silently migrated
  without a false "file modified" alarm. B1: replaced `<Files *.php>` with
  `<FilesMatch "\.(?i:php[0-9]*|phtml|phps|phar|inc)$">` covering all
  dangerous extensions. B2: new root target with two composed toggles
  (`protect_sensitive_files`, `block_xmlrpc_file`), integrity = sha1 of the
  extracted marker block only. Self-check safety net fires on next admin
  load after a write and auto-removes the block on 500. New UI card in
  HardeningSettings.jsx. Version bumped to 1.14.0.

  **Verified:** `npm run build` succeeds (webpack 5.108.4, no errors).

- 2026-07-31 (Phase A — speed & hardening gap-closure, → 1.13.0):
  implemented six low-risk items from the gap-closure plan. A1: disable block
  editor frontend CSS (`core.disable_block_css` + `core.block_css_smart_mode`)
  in `class-spfw-module-core.php`. A2: streamline dashboard
  (`core.streamline_dashboard`) removes five widgets including the
  blocking outbound HTTP request. A3: disable Application Passwords
  (`hardening.disable_app_passwords`) in `class-spfw-module-hardening.php`.
  A4: generic login error messages (`hardening.generic_login_errors`).
  A5: sitemap author-leak fix — `wp_sitemaps_add_provider` filter removes
  the users provider when `block_author_enum` is on (no new toggle).
  A6: font preloading — unconditional `<link rel="preload">` for up to 4
  localized .woff2 files in `class-spfw-module-fonts.php`. React UI updated
  in CoreSettings.jsx and HardeningSettings.jsx. Version bumped to 1.13.0
  (plugin header + SPFW_VERSION + readme.txt stable tag + changelog). .pot
  regenerated.

  **Verified:** `npm run build` succeeds (webpack 5.108.4, no errors).

- 2026-07-23 (MainWP child-side bridge, → 1.12.0): added
  `includes/class-spfw-mainwp-child.php` — a lightweight bridge class
  (`SPFW_MainWP_Child`) that hooks `mainwp_child_extra_execution` to let the
  companion "MainWP for Simple Performance for WordPress" dashboard extension
  read (`get_settings`) and update (`update_settings`) plugin settings over
  MainWP's signed dashboard-to-child channel. The class is loaded and
  instantiated in the main plugin bootstrap file. Version bumped to 1.12.0
  (plugin header + `SPFW_VERSION` + `readme.txt` stable tag + changelog).

- 2026-08-16 (Option Cleaner & Ghost Capability Cleaner ported):
  ported the orphaned-options scanner and ghost-capability stripper from
  `beaver-builder-custom-admin` into Simple Performance. Created
  `includes/modules/class-spfw-option-cleaner.php` (`SPFW_Option_Cleaner` —
  auto-scan with prefix grouping, owned-prefix derivation from installed
  plugins, core safelist, transient cleanup on delete),
  `includes/modules/class-spfw-capability-cleaner.php`
  (`SPFW_Capability_Cleaner` — role scan, core-capability lookup map,
  prefix-based `remove_cap()` across all roles), and
  `includes/api/class-rest-option-cleaner.php` (`SPFW_Rest_Option_Cleaner`)
  registering four endpoints under `simple-performance/v1`:
  `GET /option-cleaner/scan`, `POST /option-cleaner/delete`,
  `GET /option-cleaner/capabilities`,
  `POST /option-cleaner/capabilities/delete`. All endpoints enforce
  `manage_options` capability; inputs sanitized via `sanitize_key`.
  The plugin's own `spfw` prefix is always protected from deletion.
  Both module files and the REST controller are loaded in
  `SPFW_Plugin::boot()` (on-demand — no runtime hooks). Added
  `src/components/OptionCleanerSettings.jsx` with auto-scan, manual-prefix
  search, ghost-capability scan, select-all/delete, and inline status
  feedback; registered as the "Option Cleaner" tab in `App.jsx`.
  **Verified:** `php -l` clean on all 4 PHP files.

### Step 12 — Database Cleanup & Optimization Module ✅
`includes/modules/class-spfw-module-database.php` (`SPFW_Module_Database`).
Eight cleanup targets: post revisions, auto-drafts, trashed posts, spam
comments, trashed comments, expired transients, all transients, table
optimization. Scan returns counts per target; optimize runs batched
(500 rows/iteration) cleanup using WordPress APIs (`wp_delete_post_revision`,
`wp_delete_post`, `wp_delete_comment`, `delete_transient`,
`delete_site_transient`, `OPTIMIZE TABLE`). Optional WP-Cron scheduling
(daily/weekly/monthly) registered via `cron_schedules` filter;
schedule changes clear and re-register the event. Deactivation clears the
cron hook. REST routes added to `SPFW_Rest_Settings`:
`GET /spfw/v1/settings/database-scan` and
`POST /spfw/v1/settings/database-optimize`. Settings group `database`
added to `SPFW_Settings` with boolean target toggles and whitelisted
schedule value. React UI (`DatabaseSettings.jsx`) renders inside the
Option Cleaner tab with scan counts, per-target checkboxes, optimize
button with result summary, and schedule dropdown.
**Verified:** `php -l` clean on all modified PHP files.

### Step 13 — LiteSpeed Cache compatibility: whitelist authz fix, blob: CSP, allow-canaries ✅
Field report (maddogproducts.com, 2026-09-08): after enabling the plugins and
uploads directory hardening, LiteSpeed Cache broke in two independent ways. Both
are plugin defects, not server misconfiguration.

**Defect 1 — the PHP whitelist does not work on any server that honors
`<FilesMatch>`.** `SPFW_Htaccess::payload_deny_php_for_target()` emits the
whitelist allow chain (`RewriteCond` → `RewriteRule … [L]`) and then appends the
blanket `<FilesMatch>…Require all denied` block unconditionally. mod_rewrite's
`[L]` does not exempt a file from authz, so on Apache and LiteSpeed Enterprise a
whitelisted file is still 403'd; it only appears to work on OpenLiteSpeed, which
ignores `<FilesMatch>`. Fix: when the whitelist is non-empty, emit a per-file
`<Files "basename">Require all granted</Files>` (plus the pre-2.4
`Order allow,deny` / `Allow from all` fallback) *after* the deny block — Apache
merges `<Files>`/`<FilesMatch>` in source order, so the later section wins.
`<Files>` matches basename only, but the existing `RewriteCond %{REQUEST_URI}`
chain still `[F,L]`s any other path, so the pair stays path-precise. Deliberately
not `<If>`: it requires `AllowOverride All`, the same 500 risk that keeps
`Options -Indexes` out of the payload.

**Defect 2 — `DEFAULT_CSP` omits `blob:` from `script-src`.** LiteSpeed's "Load
JS Delayed" re-executes inline scripts through `URL.createObjectURL(new Blob(…))`.
`blob:` is a distinct scheme that `https:` does not cover, so every delayed script
is refused, which is the real cause of the `jQuery is not defined` /
`wp is not defined` / `setDefaults` cascade in the field report. `worker-src`
already carries `blob:`; `script-src` must too.

**Defect 3 — the enforcement probe cannot see either failure.**
`probe_htaccess_enforcement()` only probes that denies deny. A site 403'ing a file
the admin explicitly whitelisted reports as fully healthy.

Deliverables:
- `includes/class-spfw-htaccess.php`: whitelist authz exemptions after the deny
  block (Defect 1).
- `includes/class-spfw-settings.php`: 2.11.0 `reconcile_htaccess_on_upgrade()`
  migration so authored files pick up the new payload without a manual Restore;
  one-time append of `blob:` to a stored `csp_directives['script-src']` so
  installs already in Builder mode are not left broken.
- `includes/modules/class-spfw-module-hardening.php`: `blob:` in `DEFAULT_CSP`
  (`default_csp_directives()` derives from it, so the builder follows);
  `KNOWN_DIRECT_ACCESS_PHP` map + `whitelist_suggestions()`; allow-mode canaries
  in `probe_htaccess_enforcement()` and a `whitelist_blocked` state in the pure
  `shape_enforcement_result()` (allow rows never move the `htaccess_honored`
  vhost verdict — an inert .htaccess would let them through too).
- `includes/class-spfw-rest-settings.php`: expose `php_whitelist_suggestions`.
- `src/components/PhpWhitelistCard.jsx`: LiteSpeed Guest Mode in the pre-fill
  list; a detected-but-not-whitelisted warning with one-click add. Detection is
  surfaced, never auto-applied — a whitelist that grows unseen is the wrong
  default for a hardening plugin.
- `src/components/HardeningSettings.jsx`: `allowed` / `whitelist_blocked` pills
  and a distinct headline; per-row React key made unique (whitelist rows share
  the `whitelist` target key).
- Tests: whitelist `<Files>` allow must follow the `<FilesMatch>` deny (the
  assertion whose absence let Defect 1 ship); `blob:` present in the emitted
  `script-src`; `whitelist_blocked` shaping.

Acceptance: `npm run build` clean, PHPUnit green, `php -l` clean, `.pot`
regenerated, version synchronized to 2.11.0.

### Step 14 — OpenLiteSpeed restart cost: auto-allow, staleness reporting, no-op writes ✅
Follow-up to Step 13, from the same site. The 2.11.0 fix was correct but did not
help that server, because **OpenLiteSpeed parses .htaccess rewrite rules once —
on first access to the directory after startup — and caches them until a
graceful restart.** Verified against several independent sources; there is no
`autoReload` setting, no mtime check, and no per-directory invalidation in
1.8.x. A forum thread tagged "Implemented" exists but could not be read (the
build environment's egress proxy blocks `forum.openlitespeed.org` and
`docs.openlitespeed.org`), and every other source says 1.8.3/1.8.4 shipped
without it — treat as unconfirmed.

Two consequences that shape this step:
- On OLS the `<Files>`/`<FilesMatch>`/`Require` half of the payload is ignored
  outright, so Step 13's authz grant fixes Apache and LiteSpeed Enterprise and
  does nothing there. On OLS only the RewriteCond/RewriteRule chain matters —
  and that is precisely what is cached.
- The restart itself cannot be avoided, and the plugin must never try: PHP runs
  unprivileged, restarting mid-request kills the request, and a WordPress
  plugin that can restart the web server is a liability. The reload-on-mtime
  cron some admins run belongs in ops config, not here.

So the goal is not to dodge the restart but to need it **once**, and to stop the
UI reporting green while the server is out of step. The old sequence was: enable
hardening → write → restart → front end breaks (guest.vary.php now 403s) →
notice → whitelist → write → restart. Two restarts, broken site in between.

Deliverables:
- `includes/class-spfw-htaccess.php`: `effective_whitelist()` = the admin's
  `php_whitelist` plus `auto_allowed_paths()` — the entries of
  `KNOWN_DIRECT_ACCESS_PHP` that actually exist on disk. The first payload
  written is therefore already correct on a LiteSpeed site: one restart, no
  broken window. Gated on file existence (a site without LiteSpeed gets a
  blanket deny) and on a new toggle.
- `includes/class-spfw-settings.php`: `hardening.auto_allow_known_php`
  (default true) so an admin who wants a total deny keeps that option; 2.12.0
  reconcile migration so existing installs pick the allowance up.
- `includes/class-spfw-htaccess.php`: `write_own_file()` and
  `write_marker_block()` return early when the content already matches byte for
  byte, refreshing only the stored hash. Every needless rewrite costs an OLS
  restart, and the caller's whitelist-change check is order-sensitive, so
  reordering the list used to trigger one.
- `includes/modules/class-spfw-module-hardening.php`:
  `current_htaccess_hashes()` fingerprints the files at probe time (stored as
  `payload_hashes` on the enforcement result) and `htaccess_changed_since_probe()`
  compares it to disk. Returns false when no probe has run or the stored result
  predates the fingerprint — an unknown is not a warning.
  `whitelist_suggestions()` now returns empty while auto-allow is on, since the
  payload already permits those files and warning would send the admin to fix a
  problem they do not have.
- UI: an "Auto-allow known plugin endpoints" toggle that also lists the paths
  being auto-allowed (visible policy, not a hidden hole); a "changed since last
  verified" banner naming the OLS restart and its command; and the
  whitelisted-but-blocked error now leads with the cached-rules explanation.
- `tests/bootstrap.php`: a minimal `WP_Filesystem` stub. The write path was
  previously untestable — `filesystem()` would try to require
  `wp-admin/includes/file.php` and fatal — which is why every payload test pins
  a high stored version to keep migrations from writing.

Also fixed a latent order-dependency the new tests exposed: two subdirectory-
install tests set `$spfw_test_home_url` and never restore it, so every test
defined after them ran against a `/blog` install. Now reset in `setUp()`.

Acceptance: PHPUnit 99/218, Jest 26/26, `npm run build` clean, `php -l` clean,
PHPCS and `lint:js` at baseline, `.pot` regenerated, version 2.12.0.

### Step 15 — Dashicons dequeue-not-deregister (logged-out stylesheet loss) ✅
Field report from the same site: with the plugin active, an anonymous visitor
got a WooCommerce product page whose add-on option fields rendered as bare
unstyled selects — the Font `<select>` the swatch UI replaces was still visible
— and the required fields could not be completed, so no order could be placed.
The same page rendered correctly when logged in. Reported as a hardening
problem; the `.htaccess` files are not involved.

`SPFW_Module_Core::maybe_deregister_dashicons()` called `wp_deregister_style()`
behind a `! is_user_logged_in()` gate. Deregistering removes the handle from
the registry, and `WP_Dependencies::all_deps()` then silently skips every
enqueued item whose dependencies are not all registered, plus anything
depending on those — so the toggle removed whichever add-on / variation-swatch
stylesheet declared `dashicons` as a dependency, for customers only. It is the
only place in the plugin that removes a front-end asset for logged-out visitors
and not for logged-in ones, which is what makes the attribution decisive.

Deliverables:
- `includes/modules/class-spfw-module-core.php`: `maybe_dequeue_dashicons()`
  uses `wp_dequeue_style()`. Same saving when nothing needs the handle; when a
  queued stylesheet declares it as a dependency WordPress resolves and prints
  it, which is the correct outcome. `maybe_deregister_dashicons()` is retained
  as a delegating alias so a site that unhooked it by name still works.
- `tests/bootstrap.php`: recording stubs for `add_action`, `wp_dequeue_style`,
  `wp_deregister_style`, `is_user_logged_in`, plus `remove_action` /
  `remove_filter` / `is_admin` no-ops, so `SPFW_Module_Core` can be loaded and
  `register()` called under test.
- `tests/Dashicons_Dequeue_Test.php`: dequeue-not-deregister when logged out,
  no removal when logged in, the legacy alias gets the fixed behavior, and
  `register()` attaches the new callback (so the behavioral tests cannot pass
  while the hook still points at the old one).
- UI copy and `readme.txt` say what the toggle now does.

Acceptance: PHPUnit 106 tests / 228 assertions (4 new; verified failing against
the old `wp_deregister_style()` call and passing after), Jest 26/26,
`npm run build` clean, `php -l` clean, PHPCS 88E/161W and `lint:js` 266 both
unchanged at their baselines, `.pot` regenerated (485 entries, one string
reworded), version synchronized to 2.12.2 across the plugin header,
`SPFW_VERSION`, `readme.txt` and `package.json`.

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
