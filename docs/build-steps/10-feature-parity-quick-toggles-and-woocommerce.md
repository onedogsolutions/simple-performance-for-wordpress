# Step 10 — Perfmatters feature parity (quick toggles) + WooCommerce tab

**Status:** ⬜ Not started (this file is the plan only — no code yet)
**Branch:** `claude/feature-parity-quick-toggles-sf64kt`
**Depends on:** Phase 1 complete (Steps 1–9). Extends the existing single-option
React + REST architecture — no new architectural patterns.

---

## 1. Goal & scope

Bring the **quick-toggle** surface of the plugin to parity with the "General /
Options" one-click toggles offered by [Perfmatters](https://perfmatters.io/features/),
and add a dedicated **WooCommerce** tab mirroring Perfmatters' WooCommerce
optimizations. Also align the admin screens with the **floating meta-box card
styling** already used by the sister plugin
`onedogsolutions/google-security-for-wordpress` (§3).

### In scope
- New one-click toggles in the **Core** group (§4) to close the parity gap.
- A new **WooCommerce** tab + module (§5), shown only when WooCommerce is active.
- A shared **card** wrapper so every tab renders as floating meta boxes (§3).

### Explicitly out of scope (not "quick toggles" — flag as possible future phases)
Perfmatters' heavier subsystems are **not** part of this step and should not be
implied by the UI: CSS/JS minify-defer-**delay JS**, remove-unused-CSS, the
per-page **Script Manager**, **lazy loading**, **preloading / preconnect /
instant-page**, **CDN rewrite**, **local Analytics**, and **database cleanup
scheduling**. (Google Fonts localization already ships as Module 4; REST API
control as Module 2; directory hardening as Module 3.) Keep this step to
zero-config on/off switches so it stays true to the "lean" positioning.

---

## 2. Parity gap summary

Perfmatters' General tab vs. what we ship today (Module 1 / `core`):

| Perfmatters "General" toggle | Status today | Action |
|---|---|---|
| Disable Emojis | ✅ `disable_emojis` | — |
| Disable Dashicons | ✅ `disable_dashicons` | — |
| Disable Embeds | ✅ `disable_embeds` | — |
| Disable XML-RPC | ✅ `disable_xmlrpc` | — |
| Remove jQuery Migrate | ✅ `disable_jquery_migrate` | — |
| Remove wlwmanifest Link | ✅ `remove_wlwmanifest` | — |
| Remove RSD Link | ✅ `remove_rsd` | — |
| Disable RSS Feeds | ✅ `disable_feeds` | — |
| Disable REST API | ✅ Module 2 (REST tab) | — |
| **Hide WP Version** | ❌ | add `hide_wp_version` |
| **Remove Shortlink** | ❌ | add `remove_shortlink` |
| **Remove REST API head links** | ❌ | add `remove_rest_api_links` |
| **Remove RSS Feed Links** (independent of disabling feeds) | ⚠️ bundled into `disable_feeds` | add `remove_feed_links` |
| **Disable Self Pingbacks** | ❌ | add `disable_self_pingbacks` |
| **Disable Google Maps** | ❌ | add `disable_google_maps` |
| **Disable Password Strength Meter** | ❌ | add `disable_password_meter` |
| **Disable Comments** | ❌ | add `disable_comments` |
| **Remove Comment URLs** | ❌ | add `remove_comment_urls` |
| **Add Blank Favicon** | ❌ | add `blank_favicon` |
| Heartbeat control + frequency | ✅ `heartbeat_mode` / `heartbeat_interval` | — |
| **Limit Post Revisions** | ❌ | add `limit_revisions` + `revisions_max` |
| **Change Autosave Interval** | ❌ | add `autosave_interval` |
| **Change Login URL** | ❌ | add `login_url` (see §4 note — Hardening) |

WooCommerce (Perfmatters "WooCommerce" tab) — none shipped today (§5).

---

## 3. Admin styling: floating meta-box cards

**Current state:** each tab component (`CoreSettings`, `RestApiSettings`, …)
renders a bare `divide-y divide-gray-200` list of `SettingsRow`s directly into
the tab panel. There is **no card**, so the screen reads flat.

**Target (matches the sister plugin, verified in
`google-security-for-wordpress/src/components/PageToggles.jsx` `ToggleGroup` and
`SettingsPanel.jsx`):** every logical group is a floating meta box:

```jsx
<div className="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-xl">
  <div className="px-4 py-6 sm:p-8">
    <h2 className="text-base font-semibold leading-7 text-gray-900">{ title }</h2>
    <p className="mt-1 text-sm leading-6 text-gray-600">{ description }</p>
    <div className="mt-6 divide-y divide-gray-100 border-t border-gray-100">
      { rows }
    </div>
  </div>
</div>
```

### 3.1 New shared component: `src/components/SettingsCard.jsx`
Factor the wrapper out once (the sister plugin inlines it as `ToggleGroup`; we
already have multiple tabs, so a shared component avoids four copies):

```jsx
export default function SettingsCard( { title, description, children } ) {
  return (
    <div className="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-xl">
      <div className="px-4 py-6 sm:p-8">
        <h2 className="text-base font-semibold leading-7 text-gray-900">{ title }</h2>
        { description && (
          <p className="mt-1 text-sm leading-6 text-gray-600">{ description }</p>
        ) }
        <div className="mt-6 divide-y divide-gray-100 border-t border-gray-100">
          { children }
        </div>
      </div>
    </div>
  );
}
```

`SettingsRow` stays as-is (its `py-6` row rhythm already matches the sister
plugin's `Checkpoint`), but its wrapping `divide-y divide-gray-200` container in
each tab is **removed** — the card's inner `divide-y divide-gray-100` now owns
row separation.

### 3.2 Per-tab restructure
`SettingsTabs` already wraps each panel in `space-y-8`, so multiple stacked cards
get correct spacing for free. Re-group each tab's rows into cards:

- **Core tab** → split the (now larger) toggle set into themed cards instead of
  one long list:
  1. **"Head Cleanup"** — Emojis, Embeds, Dashicons, RSD, wlwmanifest, Shortlink,
     WP version, REST API head links.
  2. **"Feeds & Pingbacks"** — Disable RSS feeds (+ redirect sub-option), Remove
     feed links, Disable self pingbacks, Disable XML-RPC.
  3. **"Scripts & Assets"** — jQuery Migrate, Remove query strings, Disable Google
     Maps, Disable password strength meter, Heartbeat control + frequency.
  4. **"Comments"** — Disable comments, Remove comment URLs.
  5. **"Database"** — Limit post revisions (+ count), Autosave interval, Blank
     favicon.
- **REST API / Hardening / Fonts tabs** → wrap their existing content in a single
  `SettingsCard` each (title = the tab's purpose, description = the intro text
  those components already render), so they match visually. No behavior change.

### 3.3 No CSS/tooling changes required
The card classes are stock Tailwind utilities already available in the build;
`src/styles/index.css` needs no edit. `animate-fadeIn` (used by conditional
sub-controls in the sister plugin) is **not** currently in our `@theme` — if a
new conditional control wants it, add the same `--animate-fadeIn` /`@keyframes
fadeIn` block the sister plugin has; otherwise omit it.

---

## 4. Schema additions — `core` group

Add to `SPFW_Settings` defaults (Step 2 schema). All booleans default **off**
except where noted, so upgrading installs change nothing until the user opts in
(parity toggles are opt-in; existing defaults are untouched).

```php
'core' => [
  // ...existing keys unchanged...
  'hide_wp_version'        => false,
  'remove_shortlink'       => false,
  'remove_rest_api_links'  => false,
  'remove_feed_links'      => false, // independent of disable_feeds
  'disable_self_pingbacks' => false,
  'disable_google_maps'    => false,
  'disable_password_meter' => false,
  'disable_comments'       => false,
  'remove_comment_urls'    => false,
  'blank_favicon'          => false,
  'limit_revisions'        => false,
  'revisions_max'          => 5,     // int, clamp 1..100, used when limit_revisions
  'autosave_interval'      => 0,     // 0 = WP default (60s); else clamp 30..600
  // login_url: see note below — recommend placing in `hardening`, not `core`
],
```

**`sanitize()` additions** (extend Step 2's `sanitize()`): cast the new booleans
strictly; `revisions_max` = `absint`, clamp `1..100`; `autosave_interval` =
`absint`, allow `0` (off) else clamp `30..600`; `login_url` (if added) =
`sanitize_title`, reject reserved slugs (`wp-admin`, `wp-login`, `wp-content`,
`wp-includes`, an empty string) and fall back to disabled.

### 4.1 Toggle → WordPress mechanism (Module 1 `class-spfw-module-core.php`)
Each new toggle attaches its hook(s) only when true (same gating discipline as
Step 4). Frontend-only unless noted.

| Key | Mechanism |
|---|---|
| `hide_wp_version` | `remove_action('wp_head','wp_generator')`; `add_filter('the_generator','__return_empty_string')`; strip `ver` on core `?ver=<wpversion>` asset URLs via `style_loader_src`/`script_loader_src` when the ver equals the WP version. |
| `remove_shortlink` | `remove_action('wp_head','wp_shortlink_wp_head')`; `remove_action('template_redirect','wp_shortlink_header',11)`. |
| `remove_rest_api_links` | `remove_action('wp_head','rest_output_link_wp_head')`; `remove_action('template_redirect','rest_output_link_header',11)`; drop the `https://api.w.org/` entry via `wp_resource_hints`. (Head-link cosmetic only — does **not** disable the API; that's Module 2.) |
| `remove_feed_links` | `remove_action('wp_head','feed_links',2)`; `remove_action('wp_head','feed_links_extra',3)`. Independent of `disable_feeds` (which also *blocks* the URLs). |
| `disable_self_pingbacks` | `add_action('pre_ping', …)` stripping any link whose host matches `home_url()` from the `&$links` array. |
| `disable_google_maps` | `add_filter` on the front-end HTML output to strip `maps.googleapis.com`/`maps.google.com` script + iframe embeds. Implement via an `ob_start()` buffer on `template_redirect` gated to non-admin, or the lighter-weight `script_loader_tag`/`wp_resource_hints` removal — **prefer the enqueue-level `wp_dequeue_script` of known Maps handles first**, output-buffer only as a documented fallback (buffering the whole page is heavier and interacts with LSCache — note in UI as "may not catch hard-coded embeds"). |
| `disable_password_meter` | On `wp_print_scripts`/`login_enqueue_scripts` (and `admin_enqueue_scripts` for profile), `wp_dequeue_script('zxcvbn-async')` + `wp_dequeue_script('password-strength-meter')` + `wp_dequeue_script('user-profile')` when not needed. Guard so it never runs on the password-reset screen where the meter is legitimately required (or clearly document that trade-off). |
| `disable_comments` | `add_filter('comments_open','__return_false',20,2)` + `pings_open` false; `remove_post_type_support` for `comment` on all public types (`init`); hide existing comments via `comments_array` → `[]`; remove the admin menu (`admin_menu`), admin-bar node (`wp_before_admin_bar_render`), and dashboard widget. Front-end + admin. |
| `remove_comment_urls` | `add_filter('comment_form_default_fields', …)` unsetting the `url` field; optionally `remove_filter('comment_text','make_clickable')`. |
| `blank_favicon` | On `wp_head` (and `admin_head`, `login_head`) at low priority, echo a 1×1 transparent data-URI `<link rel="icon">` **only if** the site has no Site Icon set (`! has_site_icon()`) — prevents 404s to `/favicon.ico` without overriding a real icon. |
| `limit_revisions` | `add_filter('wp_revisions_to_keep', fn => revisions_max)` (admin/editor context). Note: does not retroactively delete existing revisions — copy in UI says "applies going forward". |
| `autosave_interval` | Can't be a runtime filter (core reads the `AUTOSAVE_INTERVAL` constant). Enqueue-time override: `wp_deregister_script('autosave')` is too blunt; instead `add_action('admin_init', …)` to `wp_localize_script`-adjust is unreliable. **Recommended:** define `AUTOSAVE_INTERVAL` early via the `heartbeat`-style approach only works if set before `wp-admin` loads it — so document that `autosave_interval` is applied by hooking `init` and calling `wp_deregister_script`/re-register `autosave` with a filtered `autosaveL10n.autosaveInterval`. If reliable override proves impractical, **drop `autosave_interval` from this step** and keep only `limit_revisions` (revisions is the higher-value, reliable one). Decide during build; note outcome in STATE.md deviations. |

`login_url` (Change Login URL) is a **security** feature, not performance —
recommend adding it to the **`hardening` group / Hardening tab** rather than
`core`, next to the `.htaccess` control, if included at all. It's non-trivial
(rewrite/`site_url` filtering + 404 on `wp-login.php` for logged-out users) and
carries real lockout risk; treat it as **optional / stretch** and gate behind a
clear warning. Flagged for a scope decision before building.

---

## 5. WooCommerce tab + module

### 5.1 New schema group `woocommerce`
```php
'woocommerce' => [
  'disable_cart_fragments' => false, // AJAX wc-cart-fragments on every page
  'disable_scripts_styles' => false, // load WC CSS/JS only on WC pages
  'disable_block_styles'   => false, // wc-blocks-style / wp-block CSS off store-wide
  'disable_status_widget'  => false, // WC "Status" dashboard meta box
  'disable_widgets'        => false, // legacy WC widgets
  'disable_password_meter' => false, // WC-specific strength meter (my-account)
  'disable_marketing_hub'  => false, // WooCommerce → Marketing admin menu
],
```
All default off. Sanitize = strict boolean casts.

### 5.2 New module `includes/modules/class-spfw-module-woocommerce.php`
Add `SPFW_Module_WooCommerce` to `SPFW_Plugin::MODULES` (file-existence guarded,
same as every other module). `register()` **first bails unless WooCommerce is
active**: `if ( ! class_exists( 'WooCommerce' ) ) return;` — so zero cost on
non-Woo sites and no fatal if the setting is stale after Woo is deactivated.
Reads `SPFW_Settings::group('woocommerce')`, attaches per toggle when true:

| Key | Mechanism |
|---|---|
| `disable_cart_fragments` | On `wp_enqueue_scripts` (priority 99, front-end), `wp_dequeue_script('wc-cart-fragments')`. Guard: never on cart/checkout (`! is_cart() && ! is_checkout()`) so live cart still works where it matters. Biggest single Woo perf win (kills the `?wc-ajax=get_refreshed_fragments` request site-wide). |
| `disable_scripts_styles` | On `wp_enqueue_scripts`, if **not** a WooCommerce context (`! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_account_page()`), dequeue `woocommerce-general`, `woocommerce-layout`, `woocommerce-smallscreen`, `wc-blocks-*`, and the `woocommerce`/`wc-*` scripts + `jquery-blockui` etc. Mirrors Perfmatters "Disable Scripts". |
| `disable_block_styles` | Dequeue `wc-blocks-style`/`wc-blocks-vendors-style` store-wide (for sites not using Woo blocks) via `wp_dequeue_style` on `wp_enqueue_scripts` (100). |
| `disable_status_widget` | `remove_meta_box('woocommerce_dashboard_status','dashboard','normal')` on `wp_dashboard_setup`. |
| `disable_widgets` | `unregister_widget()` for the legacy `WC_Widget_*` classes on `widgets_init` (20). |
| `disable_password_meter` | On `wp_enqueue_scripts`, dequeue `wc-password-strength-meter` off the my-account/checkout pages (document the UX trade-off, same caveat as core §4). |
| `disable_marketing_hub` | `remove_submenu_page('woocommerce','wc-admin&path=/marketing')` on `admin_menu` (99); also filter `woocommerce_admin_features` to drop `marketing` where supported. |

### 5.3 React: `src/components/WooCommerceSettings.jsx`
Same shape as `CoreSettings` but rendered in `SettingsCard`s (§3), props
`{ settings, onChange }` writing the `woocommerce` group. One card
("WooCommerce") is enough; optionally split Frontend vs Admin cards.

### 5.4 Conditional tab (mirror the sister plugin exactly)
- **PHP** (`admin/class-spfw-admin.php`): add `'woocommerceActive' =>
  class_exists( 'WooCommerce' )` to the `spfwAdminData` `wp_localize_script`
  payload (the sister plugin localizes the identical flag).
- **React** (`App.jsx`): read `initialData.woocommerceActive`; **append** the
  `woocommerce` tab to `TABS` only when true, and render
  `<WooCommerceSettings … />` in the panel map. When Woo is inactive the tab
  simply isn't shown (no dead tab, no console noise). The `woocommerce` settings
  group still round-trips through REST untouched so values survive a
  deactivate/reactivate cycle.

---

## 6. OpenLiteSpeed / LiteSpeed Cache considerations
- Toggles that alter **frontend HTML** (`hide_wp_version`, `remove_*` head links,
  `blank_favicon`, `disable_google_maps`, all Woo frontend dequeues) change cached
  output → after saving settings, fire `do_action('litespeed_purge_all')` from the
  REST save handler (the plugin already does this pattern for Fonts in Step 8;
  reuse it — purge once on any successful settings write rather than per-toggle).
- **`disable_cart_fragments`** removes a per-request AJAX call that otherwise
  defeats full-page caching on Woo sites — high value under LSCache; call it out
  in the UI copy.
- **`disable_scripts_styles`** is safe with LSCache's per-URL caching; no special
  handling.
- The **output-buffer** path for `disable_google_maps` (if used) must **not** run
  on cached hits — gate it out when `defined('LSCWP_V')` and the response is a
  cache hit isn't detectable cheaply, so prefer the enqueue-level dequeue and keep
  buffering as an opt-in documented fallback only.
- `limit_revisions` / `autosave_interval` are admin-only; no cache interaction.

---

## 7. i18n / packaging
- All new strings use `__( …, 'simple-performance-for-wordpress' )`.
- Regenerate `languages/simple-performance-for-wordpress.pot` via
  `wp i18n make-pot . languages/simple-performance-for-wordpress.pot` (scans PHP +
  JSX, as in the Step 9 follow-up).
- `readme.txt`: bump changelog, add the new toggles + WooCommerce section to the
  Description/FAQ. Bump `Stable tag` and the plugin-header `Version` together only
  when cutting a release (leave at build time per repo convention).
- No new build dependencies. `npm run build` + `npm run lint:js` + `lint:css`
  must stay clean.

---

## 8. Build order & acceptance criteria

1. **Styling foundation** — add `SettingsCard.jsx`; wrap REST/Hardening/Fonts tab
   bodies in one card each; split Core into themed cards. *Accept:* screens render
   as floating meta boxes matching the sister plugin; no behavior change; lint +
   build clean.
2. **Core parity toggles** — schema keys + `sanitize()` + Module 1 hooks +
   `CoreSettings.jsx` rows. *Accept:* each toggle, with a stubbed hook-registry
   harness, attaches exactly its documented hooks when on and nothing when off;
   sanitize clamps `revisions_max`/`autosave_interval`; defaults leave existing
   installs unchanged.
3. **WooCommerce module** — schema group + `SPFW_Module_WooCommerce` (added to
   `MODULES`) + sanitize. *Accept:* module no-ops entirely when
   `! class_exists('WooCommerce')`; with a WooCommerce stub, each toggle attaches
   its documented hook and cart-fragment/script dequeues respect the page-context
   guards.
4. **WooCommerce tab + gating** — `WooCommerceSettings.jsx`; localize
   `woocommerceActive`; conditional `TABS` entry. *Accept:* tab appears only when
   Woo active; settings persist through REST regardless; no console errors when
   inactive.
5. **Purge + i18n + docs** — LSCache purge on save (if not already), regenerate
   `.pot`, update `readme.txt`, update `IMPLEMENTATION_PLAN.md` §2/§3 and
   `STATE.md` (new Progress rows + deviations).

Verification bar matches Phase 1: stubbed PHP harnesses for hook wiring (scratch,
not committed), `npm run build` + `lint:js` + `lint:css` clean, `php -l` across all
PHP files. Manual QA on a live OpenLiteSpeed + WooCommerce install remains an
outstanding human step (as noted for Phase 1).

---

## 9. Open scope decisions (resolve before building)
1. **`autosave_interval`** — keep only if a reliable, side-effect-free override is
   confirmed during build; otherwise ship `limit_revisions` alone (§4).
2. **`login_url` (Change Login URL)** — include as a Hardening-tab stretch item, or
   defer? It's security, not performance, and carries lockout risk (§4).
3. **`disable_google_maps` implementation** — enqueue-dequeue only (lean, may miss
   hard-coded embeds) vs. output-buffer fallback (heavier, LSCache caveats) (§4/§6).

These are the only judgment calls; everything else follows existing patterns.
