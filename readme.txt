=== Simple Performance for WordPress ===
Contributors: One Dog Solutions
Tags: performance, security, rest-api, litespeed, fonts
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

An ultra-lightweight performance, REST API hardening, and self-hosted-fonts toolkit built for OpenLiteSpeed and LiteSpeed Cache.

== Description ==

Simple Performance for WordPress consolidates the highest-value features of Perfmatters, Disable Bloat, and REST API Toolbox into one focused plugin — with no overlapping bloat and no heavy database footprint. It's purpose-built for OpenLiteSpeed running LiteSpeed Cache, but every feature works on any host.

= Core Performance Toggles =
* Disable emojis, embeds, and Dashicons (for logged-out visitors)
* Disable XML-RPC, RSD, and Windows Live Writer manifest links
* Hide the WordPress version, remove the shortlink and REST API header links
* Disable RSS feeds (with optional homepage redirect) or just remove the feed links
* Disable self pingbacks
* Remove query strings (?ver=) from static assets
* Disable Google Maps embeds and the front-end password strength meter
* Disable comments site-wide and remove the comment author URL field
* Limit post revisions and set the autosave interval (Perfmatters-style)
* Control the WordPress Heartbeat API (disable, or allow only in the editor) and its frequency
* Add a blank favicon to stop /favicon.ico 404s
* Disable jQuery Migrate on the frontend

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

= Directory-Level Security Hardening =
* Drops a deny-PHP .htaccess into /wp-content/plugins/ to block direct execution of plugin PHP files
* Verifies the file's integrity on every admin page load and flags it if it's missing or has been altered, with a one-click restore
* Never overwrites or deletes a pre-existing, unrecognized .htaccess

= Google Fonts Localizer & Discovery =
* Scans your homepage for Google Fonts references and downloads the .woff2 files to your own server
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

== Frequently Asked Questions ==

= Does this work on hosts other than OpenLiteSpeed? =
Yes. Every feature works on any WordPress host. The directory-hardening `.htaccess` rule and the LiteSpeed Cache purge integration are the only two features with OpenLiteSpeed/LiteSpeed-specific behavior, and both degrade gracefully elsewhere.

= I enabled the plugins-directory hardening but it doesn't seem to do anything =
On OpenLiteSpeed, `.htaccess` rules are only honored when "Allow Override" is enabled for your site in the LiteSpeed WebAdmin console (Rewrite → Auto Load from .htaccess). If it's off, the file is written but has no effect — it's fail-safe, never fail-open.

= Will disabling REST API namespaces break other plugins? =
The whitelist is checked before any restriction, so add any route prefix your other plugins rely on (a few common ones, like WooCommerce and Contact Form 7, are pre-seeded). The plugin's own settings API is always exempt, so you can never lock yourself out of this settings screen.

= What happens if a font scan fails or finds nothing? =
Nothing changes. The "self-host Google Fonts" feature only takes effect once a scan successfully discovers and downloads at least one font; until then (or if a scan fails), your site keeps loading fonts exactly as it did before.

= Does this require Node.js or a build step to use? =
No — the compiled admin interface ships in the plugin ZIP. Node.js and npm are only needed if you're developing the plugin itself from source.

== Changelog ==

= 1.1.0 =
* Added Perfmatters-parity quick toggles: hide WP version, remove shortlink and REST API header links, remove feed links, disable self pingbacks, disable Google Maps, disable the front-end password strength meter, disable comments, remove comment author URLs, add a blank favicon, limit post revisions, and set the autosave interval.
* Reworked the Heartbeat controls to match Perfmatters (disable everywhere / allow only in the editor, plus a separate frequency).
* Added a WooCommerce tab (shown only when WooCommerce is active): disable cart fragments, load scripts/styles only on store pages, disable the status widget, legacy widgets, Marketing hub, and password strength meter.
* Reorganized the admin screens into floating meta-box cards to match the companion Google Security plugin.

= 1.0.0 =
* Initial release: core performance toggles, REST API controls, directory-level security hardening, and a Google Fonts localizer, with a React-based settings screen backed by a single REST endpoint.
