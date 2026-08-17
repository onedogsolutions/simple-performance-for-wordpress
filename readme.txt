=== Simple Performance for WordPress ===
Contributors: One Dog Solutions
Tags: performance, security, rest-api, litespeed, fonts
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.2.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

An ultra-lightweight performance, REST API hardening, and self-hosted-fonts toolkit built for OpenLiteSpeed and LiteSpeed Cache.

== Description ==

Simple Performance for WordPress consolidates highest-value performance, REST API, and security hardening features into one focused plugin — with no overlapping bloat and no heavy database footprint. It's purpose-built for OpenLiteSpeed running LiteSpeed Cache, but every feature works on any host.

= Core Performance Toggles =
* Disable emojis, embeds, and Dashicons (for logged-out visitors)
* Disable XML-RPC, RSD, and Windows Live Writer manifest links
* Hide the WordPress version, remove the shortlink and REST API header links
* Disable RSS feeds (with optional homepage redirect) or just remove the feed links
* Disable self pingbacks
* Remove query strings (?ver=) from static assets
* Disable Google Maps embeds and the front-end password strength meter
* Disable comments site-wide and remove the comment author URL field
* Limit post revisions and set the autosave interval
* Control the WordPress Heartbeat API (disable, or allow only in the editor) and its frequency
* Add a blank favicon to stop /favicon.ico 404s
* Disable jQuery Migrate on the frontend
* Disable the WordPress core XML sitemap (wp-sitemap.xml)
* Remove the max-image-preview:large directive from the robots meta tag
* Disable block editor frontend CSS (wp-block-library, global-styles) with smart mode for mixed sites
* Streamline the dashboard by removing bloated widgets (eliminates the blocking outbound HTTP request to wordpress.org)
* Preload localized font files for faster text rendering
* Disable WP-Cron (for sites with a real system cron) and tune the cron lock timeout
* Control the Speculative Loading API (prefetch/prerender) — disable or run in conservative mode
* Disable public site search (redirect to homepage or return 404)
* Disable scaled image generation and selectively skip intermediate image sizes

= WooCommerce Optimizations (shown when WooCommerce is active) =
* Disable AJAX cart fragments off the cart/checkout — the biggest single store speed win
* Load WooCommerce scripts and styles only on store pages
* Remove the Status dashboard widget, legacy widgets, and the Marketing hub
* Disable the WooCommerce password strength meter

= Advanced REST API Controls =
* Optionally require authentication for the entire REST API
* Fully unregister specific namespaces (e.g. /wp/v2/users, /wp/v2/themes) to stop user enumeration and automated scanning — matched routes return 404, not 403, so there's no signal the endpoint ever existed
* A whitelist keeps essential integrations (Contact Form 7, WooCommerce, etc.) working even with the above restrictions on
* The plugin's own settings API is always exempt automatically, so you can never lock yourself out of the settings screen

= Security Hardening =
* Drops a deny-PHP .htaccess into /wp-content/plugins/ and (optionally) /wp-content/uploads/ to block direct execution of PHP files (covers .php, .phtml, .php5, .php7, .phps, .phar, .inc) where none should run
* Verifies each file's integrity on every admin page load and flags it if it's missing or has been altered, with a one-click restore — and never overwrites or deletes a pre-existing, unrecognized .htaccess
* Root .htaccess rules: block direct access to sensitive files (readme.html, debug.log, .env, *.sql, *.bak) and deny xmlrpc.php at the server level — written via WordPress markers so permalink rules are preserved, with an automatic safety rollback if the rules cause a 500
* Disable the built-in theme/plugin file editor (DISALLOW_FILE_EDIT) so a compromised admin account can't edit PHP from the dashboard
* Block author enumeration — redirects anonymous ?author=N and /author/slug/ probes so usernames can't be harvested for brute-force attacks, and removes the users sitemap to close the same leak
* Disable Application Passwords (which bypass two-factor authentication)
* Generic login error messages (prevents username existence disclosure)
* Send conservative security response headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, COOP, CORP, X-Permitted-Cross-Domain-Policies, configurable Permissions-Policy); these work regardless of your web server's .htaccess handling
* Add a Content-Security-Policy header with a visual policy builder — toggle the allowed sources per directive (scripts, styles, images, fonts, …) and watch the policy string build itself, or switch to Advanced mode to edit the raw policy and add your own directives
* See exactly what a policy blocks: whenever CSP is active, blocked resources are collected and shown as warnings next to the directive that blocked them, with a one-click "Allow" to add the source — clear every violation in Report-Only before you enforce, and keep catching real breakage after. Can skip logged-in users so the block editor and admin bar keep working
* Add an HTTP Strict Transport Security (HSTS) header with a configurable max-age, includeSubDomains, and preload — only sent on HTTPS responses, including behind a reverse proxy that terminates TLS at the edge (e.g. QUIC.cloud)

= Google Fonts Localizer & Discovery =
* Scans a representative sample of your pages for Google Fonts references and downloads the .woff2 files to your own server
* Rewrites the page to serve fonts locally instead of from fonts.googleapis.com / fonts.gstatic.com
* Falls back gracefully to the original Google-hosted fonts if a local copy isn't available yet, so a scan issue never breaks font rendering

= Built for OpenLiteSpeed + LiteSpeed Cache =
* A single autoloaded settings option keeps the database footprint to one row
* Settings changes that affect the rendered page automatically trigger a LiteSpeed Cache purge
* Clear in-plugin guidance on OpenLiteSpeed's "Allow Override" requirement for .htaccess rules to take effect

= Modern Admin, No jQuery =
The settings screen is a small React application (built on WordPress's own bundled `@wordpress/element`, so no separate React dependency and no jQuery) served from a single REST endpoint.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/simple-performance-for-wordpress` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin.
3. Navigate to **Settings → Simple Performance** to configure Core, REST API, Hardening, Fonts, and (when WooCommerce is active) WooCommerce.

All hardening options are opt-in (off by default) — enable the ones that suit your site.

== Frequently Asked Questions ==

= Does this work on hosts other than OpenLiteSpeed? =
Yes. Every feature works on any WordPress host. The directory-hardening `.htaccess` rule and the LiteSpeed Cache purge integration are the only two features with OpenLiteSpeed/LiteSpeed-specific behavior, and both degrade gracefully elsewhere.

= I enabled the plugins-directory hardening but it doesn't seem to do anything =
On OpenLiteSpeed, `.htaccess` rules are only honored when "Allow Override" is enabled for your site in the LiteSpeed WebAdmin console (Rewrite → Auto Load from .htaccess). If it's off, the file is written but has no effect — it's fail-safe, never fail-open.

= I enabled directory hardening and now my site returns 500 errors =
On Apache (not OpenLiteSpeed), the `Require all denied` directive inside the `.htaccess` file requires `AllowOverride AuthConfig` (or `AllowOverride All`) in the virtual-host configuration. If your host has not granted that override, Apache refuses to parse the directive and returns a 500 for every request under the protected directory. Fix: ask your host to add `AllowOverride AuthConfig` (or `All`) to the `<Directory>` block for your site, or disable the hardening toggle via the database (`wp_options` → `spfw_settings` → set `plugins_htaccess` / `uploads_htaccess` to `false`). OpenLiteSpeed is unaffected — it uses its own override mechanism and does not enforce Apache's `AllowOverride` semantics.

= Will disabling REST API namespaces break other plugins? =
The whitelist is checked before any restriction, so add any route prefix your other plugins rely on (a few common ones, like WooCommerce and Contact Form 7, are pre-seeded). The plugin's own settings API is always exempt, so you can never lock yourself out of this settings screen.

= What happens if a font scan fails or finds nothing? =
Nothing changes. The "self-host Google Fonts" feature only takes effect once a scan successfully discovers and downloads at least one font; until then (or if a scan fails), your site keeps loading fonts exactly as it did before.

= Does this require Node.js or a build step to use? =
No — the compiled admin interface ships in the plugin ZIP. Node.js and npm are only needed if you're developing the plugin itself from source.

== Changelog ==

= 2.2.0 =
* Added: Database cleanup and optimization tool. Scan and clean post revisions, auto-drafts, trashed posts, spam comments, trashed comments, expired transients, all transients, and fragmented tables. Optional WP-Cron scheduling (daily, weekly, or monthly). All cleanup targets are individually toggleable, with a scan-before-clean workflow and per-target deletion counts.

= 2.1.0 =
* Changed: CSP violation collection now runs in a time-boxed window instead of permanently. Previously `report-uri` was attached to every front-end response for as long as CSP was enabled, so every visitor's browser POSTed a violation report on every page view — an uncacheable full WordPress page load each time. On a busy site that made the report endpoint the single most-requested URL on the site. Open a window from the Hardening tab when you want to collect, work through the list, and it closes itself.
* Added: sample rate for violation collection (100% / 25% / 10% / 1% of page views), so a high-traffic site can find the same broken resources at a fraction of the requests.
* Added: the violation report endpoint is now fully closed (403, no work done) unless a collection window is open, and repeat sightings of a violation already in the log coalesce instead of writing to the database on every request.
* Fixed: concurrent violation reports no longer lose counts. The log was read-modify-written with no locking, so under real traffic most increments were discarded — a 150-request burst recorded 70.
* Security: the violation log can no longer be flooded out by anonymous requests. New origins are rate-limited, and eviction now drops the least-reported entry rather than the least recently seen, so a burst of invented origins cannot push out the real violations you need to act on.
* Security: "Allow" now asks for confirmation and shows the exact policy token it will add. Violation reports arrive unauthenticated from visitors' browsers, so the origins listed are not trusted input — only allow origins you recognise.
* Fixed: clicking "Allow" removes the violation from the outstanding list instead of leaving it there, and once the policy is saved, stale reports for an already-allowed origin no longer re-add it.
* Fixed: the report endpoint's body limit was raised from 8 KB to 32 KB — batched Reporting API payloads above the old limit were being discarded whole.

= 2.0.3 =
* Fixed: generic login error messages no longer return a plain string from the `wp_login_errors` filter, which could cause a fatal error on customized login pages such as Divi + LoginPress.

= 2.0.2 =
* Changed: XML-RPC disable moved to the Hardening tab. The default behavior uses PHP filters (`xmlrpc_enabled`, pingback methods, and `X-Pingback` header); an optional "Block xmlrpc.php at server level" toggle switches the block to the root `.htaccess` instead.
* Fixed: Permissions-Policy features no longer revert after saving when unchecked.

= 2.0.1 =
* Fixed: infinite recursion (stack exhaustion / 500) on any site upgrading from a pre-1.14.0 version. The 1.14.0 payload migration called SPFW_Settings::group() before the static cache was populated, re-entering get() in an unbounded loop. The cache is now seeded before the migration fires.
* Added: PHPUnit regression test covering the migration recursion path (runs in CI on PHP 7.4, 8.2, 8.3).
* Note: the deny-PHP and root .htaccess payloads emit `Require all denied`, which requires `AllowOverride AuthConfig` (or `All`) in the Apache vhost. On hosts where that is not granted, Apache returns 500 for requests under the protected directory. OpenLiteSpeed is unaffected (honors .htaccess via its own override mechanism). See the FAQ for details.

= 2.0.0 =
* Added: CSP script-src tightening via hash sources — scan your site's inline scripts and replace 'unsafe-inline' with sha256 hashes plus 'strict-dynamic'. This provides real XSS protection while remaining compatible with full-page caching (hashes are stable across cache hits, unlike nonces).
* Added: script hash scanner — crawls your homepage, most recent post, and most recent page to collect sha256 digests of all inline scripts. Re-scan after any plugin or theme change.
* Important: 'strict-dynamic' changes how browsers interpret script-src — https: and host allowlists are ignored once it's present. Trust propagates from hashed scripts only. Any inline script that varies per request (timestamps, personalization blobs) will always violate and cannot be hashed.
* Recommendation: keep CSP in Report-Only mode until the violation log is clean, then switch to enforcing. This is an advanced feature with a standing re-scan obligation after every plugin/theme update.

= 1.16.0 =
* Added: CI pipeline — GitHub Actions workflow running PHP lint (7.4–8.3), PHPCS with WordPress Coding Standards, and the admin UI build on every push/PR.
* Added: composer.json with PHPCS, WPCS, and PHPCompatibility dev dependencies; phpcs.xml.dist configured for the plugin's text domain and prefix.
* Added: settings export/import — download a portable JSON profile (volatile keys like integrity hashes and font scan cache are stripped) and import it on another site. Import re-derives .htaccess hashes locally so a foreign export never corrupts status detection.
* Added: configuration presets — three one-click starting points (Balanced, Aggressive, Locked Down) with a confirmation step showing what will change. Applying a preset runs through the normal settings path so all sanitization, LSCache purging, and .htaccess sync fire unchanged.

= 1.15.0 =
* Added: additional security headers — Cross-Origin-Opener-Policy: same-origin, Cross-Origin-Resource-Policy: same-origin, and X-Permitted-Cross-Domain-Policies: none are now sent alongside the existing headers when "Send security headers" is enabled.
* Added: configurable Permissions-Policy — the feature allowlist (geolocation, microphone, camera, payment, usb, interest-cohort) is now editable in the UI; blocked features are sent with an empty allowlist.
* Added: security headers in wp-admin — nosniff and Referrer-Policy are now sent on admin pages too (never CSP or HSTS from this path).
* Added: WP-Cron control — disable the virtual cron spawner (DISABLE_WP_CRON) for sites with a real system cron, and tune WP_CRON_LOCK_TIMEOUT to prevent overlapping cron runs.
* Added: Speculation Rules control — disable WordPress's speculative loading (prefetch/prerender) entirely or run in conservative mode (prefetch only, moderate eagerness, no prerender).
* Added: disable site search — blocks public ?s= queries on the frontend (redirect to homepage or return 404). Useful for sites where search is unused and the query is an abuse vector.
* Added: image size generation control — disable the -scaled copy of large images (big_image_size_threshold) and selectively skip intermediate sizes (1536×1536, 2048×2048, medium_large) to save disk space and upload time.

= 1.14.0 =
* Changed: the deny-PHP .htaccess payload now uses FilesMatch with a PCRE pattern covering .php, .php5, .php7, .phtml, .phps, .phar, and .inc — the extensions a dropper uses once .php is blocked. Existing installs are silently migrated (legacy hash detection) with no false "file modified" alarm.
* Added: root .htaccess rules — protect sensitive files (readme.html, license.txt, debug.log, .env, *.sql, *.bak, *.old) and block xmlrpc.php at the server level. Written via WordPress markers (insert_with_markers) so permalink rules are never disturbed. Includes an automatic safety rollback: if the rules cause a 500, they are removed on the next admin page load.
* Changed: SPFW_Htaccess refactored to a targets map with two ownership modes (own_file for plugins/uploads, marker_block for the site root). Integrity checks for the root target hash only the extracted marker block, so a permalink save never trips the altered notice.

= 1.13.0 =
* Added: Disable block editor frontend CSS (wp-block-library, global-styles, classic-theme-styles) — removes ~30–60 KB of render-blocking CSS on classic/page-builder themes. Includes a smart mode (on by default) that skips removal on pages using blocks.
* Added: Streamline dashboard — removes the Events & News, Quick Draft, Activity, Site Health, and At a Glance widgets, eliminating the blocking outbound HTTP request to wordpress.org on every dashboard load.
* Added: Disable Application Passwords — removes the authentication method that bypasses two-factor plugins. MainWP is unaffected (uses its own signed channel).
* Added: Generic login error messages — replaces login/password-reset errors with a single generic string so attackers cannot determine whether a username exists.
* Fixed: author enumeration via sitemaps — when block_author_enum is on, the users sitemap provider (wp-sitemap-users-1.xml) is now removed so it no longer re-leaks author nicenames.
* Added: font preloading — localized .woff2 files now emit <link rel="preload"> tags (up to 4, weight 400 first) so the browser discovers them without first parsing fonts.css.

= 1.12.0 =
* Added: MainWP child-side bridge (`SPFW_MainWP_Child`) so the companion "MainWP for Simple Performance for WordPress" dashboard extension can read and update plugin settings over MainWP's signed channel.

= 1.11.2 =
* Fixed: textarea fields (Manual font weights, Extra pages to scan, Whitelist routes) no longer allow only one line of input. Newlines are now preserved while typing; the list is parsed and saved on blur.

= 1.11.1 =
* Fixed: plugin ZIP now packages files inside a `simple-performance-for-wordpress/` root directory so WordPress correctly detects the existing installation and offers to overwrite on upload.

= 1.11.0 =
* Fixed: CSP violation reports (blocked script discovery) never arriving when the site is behind a QUIC.cloud or Cloudflare CDN. The report-uri now rewrites its scheme and host from the CDN's forwarded headers (X-Forwarded-Proto, X-Forwarded-Host), so it always matches the origin the browser sees — previously it used the origin server's URL, which the browser silently dropped as mixed-content or unreachable.
* Fixed: the report endpoint now sends explicit no-store cache headers, preventing CDNs and page-cache plugins from caching the 204/403 response and silently swallowing subsequent violation reports.
* Added: when a CDN rewrites the report endpoint to a different origin, that origin is automatically injected into the policy's connect-src directive so the browser doesn't block the report POST itself.
* Added: a CDN diagnostic hint in the CSP card when no violations have been collected in enforce mode, explaining common CDN pitfalls (missing forwarded headers, cached REST endpoint) and clarifying that ERR_BLOCKED_BY_ORB errors are not CSP violations.

= 1.10.0 =
* Removed: Beaver Builder settings-based font discovery (was causing fewer fonts to be discovered).

= 1.9.0 =
* Added: a "Workers (Web / Service / Shared Workers)" row to the CSP policy builder (`worker-src`), so scripts that spin up a worker from a `blob:` URL — a common pattern in map, chart, PDF, and analytics libraries — can be allowed like any other directive instead of only being fixable via the raw-policy editor. The recommended default policy now includes `worker-src 'self' blob:`.
* Added: `blob:` as a preset chip on the Scripts and Styles rows.
* Fixed: violations reported against the granular `script-src-elem` / `script-src-attr` / `style-src-elem` / `style-src-attr` effective directives were shown under "Other violations (directives not in the builder)" with no way to resolve them, even though allowing the source on the parent `script-src` / `style-src` directive is exactly what the browser's fallback behavior requires. These now group under, and can be Allowed from, their parent directive's row.

= 1.8.0 =
* New: Disable WordPress core XML sitemaps (absorbs the "Disable WP Sitemaps" plugin).
* New: Remove the robots max-image-preview:large directive (absorbs the "Disable WP Robots" plugin).

= 1.7.1 =
* Fixed: localized Google Fonts could render bolder than specified — e.g. body copy or footer links coming out at weight 700 when 400 was set, with computed styles still showing 400. Root cause: Google serves many families (including Roboto Condensed) as variable fonts, where every requested weight shares one .woff2 file; the localizer deduplicated discovered @font-face blocks by that shared file URL, so only the heaviest weight's block survived into the generated stylesheet. Blocks are now deduplicated by their full content instead, so every discovered weight and style is kept.
* If you previously ran a font scan, a notice will prompt you to re-scan after updating — this regenerates the local stylesheet with the fix applied. Existing localized fonts keep working in the meantime; nothing is removed automatically.

= 1.7.0 =
* Fixed: font discovery missed weights that a CDN/optimizer hid from the page. On OpenLiteSpeed + QUIC.cloud the scan could see only some weights (e.g. Roboto Condensed 700) while the site actually rendered another (400), so the missing weight fell back to a system font. The loopback scan now sends no-cache headers alongside the existing cache-buster so it gets a fresh, un-optimized render.
* Added: multi-page discovery. The scan now covers your homepage plus your most recent post and page — and any extra URLs you list — so a weight enqueued only on inner templates is discovered too.
* Added: manual font-weight declarations. When a proxy blinds automatic discovery, declare families and weights (e.g. "Roboto Condensed:400,700") and the exact weights are downloaded straight from Google, regardless of what the front end exposes.
* Added: CDN-inlined @font-face blocks pointing at fonts.gstatic.com are now localized directly, even when the original Google stylesheet <link> has been stripped from the page.

= 1.6.1 =
* Fixed: CSP violation reports could fail to appear during testing. The policy emitted both report-uri and the newer report-to; when both are present Chrome ignores report-uri and uses the Reporting API, which batches reports and delays them by up to a minute. Now emits report-uri only, so violations are reported immediately.
* Changed: CSP violations are now collected whenever the policy is active, in enforce mode too (not only Report-Only) — so a resource blocked on your live site still shows up as a warning. The report endpoint is still fully closed whenever CSP is disabled.
* Fixed: the "Allow" button now inserts the correct token for scheme blocks — a blocked data: or blob: script (which browsers report as bare "data"/"blob") adds data:/blob: rather than an invalid bare word.
* Changed: data: added to the default script-src, since LiteSpeed/QUIC.cloud JS optimization commonly serves inline scripts as data: URIs — the recommended policy no longer blocks them out of the box.

= 1.6.0 =
* Added: a visual Content-Security-Policy builder. Instead of one raw text box, each directive (script-src, style-src, img-src, …) now has toggle chips for its common sources plus an "additional hosts" field, and the policy string builds itself live. An Advanced switch still exposes the raw editor for custom directives.
* Added: CSP violation reporting. While in Report-Only mode the policy carries a report endpoint, and blocked resources are collected and shown as amber warnings next to the exact directive that blocked them — each with a one-click "Allow" that adds the source. This closes the test-and-refine loop before you enforce.
* Violation collection is part of Report-Only mode: the public report endpoint accepts data only while Report-Only is on, and closes automatically the moment you switch to enforcing.
* Existing installs with a hand-edited CSP keep it untouched — they open in Advanced (raw) mode so nothing changes until you choose the builder.

= 1.5.0 =
* Added: HTTP Strict Transport Security (HSTS) header — the last of the standard security headers a scanner checks for. Configurable max-age (1 day to 2 years, defaulting to 1 year), plus optional includeSubDomains and preload toggles.
* HSTS is only ever sent on an HTTPS request. Detection works behind a reverse proxy that terminates TLS at the edge (checks X-Forwarded-Proto / X-Forwarded-SSL / X-Forwarded-Port in addition to is_ssl()), so the header is sent correctly whether or not the site sits behind QUIC.cloud or a similar proxy.
* Off by default, like every other hardening toggle — enabling it tells browsers to refuse plain HTTP for the configured duration, so confirm HTTPS works reliably first.

= 1.4.0 =
* Added: Content-Security-Policy header (the one remaining security header). Ships a recommended WordPress-friendly default policy that you can edit or replace entirely.
* Because CSP can break front-end rendering, it includes safety controls: a Report-Only mode (on by default) that logs violations in the browser console without blocking anything so you can test first, and an option (on by default) to skip the header for logged-in users so the block editor, customizer, and admin bar are never affected.

= 1.3.0 =
* Fixed the plugins-directory hardening toggle not taking effect: enabling it silently reverted and never wrote the .htaccess, caused by a settings-cache issue when the file-writing hook re-saved during the same request. The toggle now writes and removes the file reliably.
* The Hardening restore action now reports a real error when the server can't write the file (e.g. no direct filesystem access) instead of showing a false success.
* Added: block direct PHP execution in the uploads directory (a second, independent deny-PHP .htaccess with its own status/restore).
* Added: disable the theme/plugin file editor (DISALLOW_FILE_EDIT).
* Added: block author enumeration (anonymous ?author=N and /author/slug/ probes redirect to the home page).
* Added: send conservative security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy). All new hardening options are opt-in.

= 1.2.1 =
* Fixed the self-hosted Google Fonts scan reliably finding no fonts. Discovery now captures the fonts your theme and plugins actually enqueue while your homepage renders, instead of only pattern-matching the page HTML, so it detects fonts loaded over any protocol, via either Google Fonts API version, or imported inside a stylesheet.
* Hardened the scan: it now retries loopback requests that fail TLS verification, follows same-origin stylesheets for imported fonts, and clearly distinguishes "could not load your homepage" from "no Google Fonts found" (the latter no longer discards a previous successful scan).
* The Fonts screen now shows a font/file count, a "no fonts detected" state, and the scan result message.

= 1.1.1 =
* Fixed textarea newline/onChange state handling and styled namespaces list using Toggle component.
* Bumped version to 1.1.1 to trigger automatic migration checks.

= 1.1.0 =
* Added performance quick toggles: hide WP version, remove shortlink and REST API header links, remove feed links, disable self pingbacks, disable Google Maps, disable the front-end password strength meter, disable comments, remove comment author URLs, add a blank favicon, limit post revisions, and set the autosave interval.
* Reworked the Heartbeat controls (disable everywhere / allow only in the editor, plus a separate frequency).
* Added a WooCommerce tab (shown only when WooCommerce is active): disable cart fragments, load scripts/styles only on store pages, disable the status widget, legacy widgets, Marketing hub, and password strength meter.
* Reorganized the admin screens into floating meta-box cards to match the companion Google Security plugin.

= 1.0.0 =
* Initial release: core performance toggles, REST API controls, directory-level security hardening, and a Google Fonts localizer, with a React-based settings screen backed by a single REST endpoint.
