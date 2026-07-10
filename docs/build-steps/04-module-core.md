# Step 4 — Module 1: Core Performance Toggles

**Goal:** Implement all bloat-removal toggles. Every feature is gated on its setting;
disabled features attach **zero** hooks.

## Shared context
- Prefix `SPFW_`/`spfw_`. `ABSPATH` guard. WPCS.
- Depends on Step 2 (`SPFW_Settings`) and Step 3 (interface + loader).
- Settings live under the `core` group (see `02-settings.md`).

## Deliverable
`includes/modules/class-spfw-module-core.php` → `class SPFW_Module_Core implements
SPFW_Module`.

`register()` reads `$c = SPFW_Settings::group('core')` and conditionally wires each
feature below.

### Feature → hook map
1. **Emojis** (`disable_emojis`): on `init` —
   `remove_action('wp_head','print_emoji_detection_script',7)`,
   `remove_action('admin_print_scripts','print_emoji_detection_script')`,
   `remove_action('wp_print_styles','print_emoji_styles')`,
   `remove_action('admin_print_styles','print_emoji_styles')`,
   `remove_filter('the_content_feed','wp_staticize_emoji')`,
   `remove_filter('comment_text_rss','wp_staticize_emoji')`,
   `remove_filter('wp_mail','wp_staticize_emoji_for_email')`;
   `add_filter('tiny_mce_plugins', remove 'wpemoji')`;
   `add_filter('wp_resource_hints', drop the s.w.org emoji DNS-prefetch, 10, 2)`.
2. **Embeds** (`disable_embeds`): on `init` —
   `remove_action('wp_head','wp_oembed_add_discovery_links')`,
   `remove_action('wp_head','wp_oembed_add_host_js')` (WP<5.9),
   `remove_action('rest_api_init','wp_oembed_register_route')`,
   `add_filter('embed_oembed_discover','__return_false')`,
   `remove_filter('oembed_dataparse','wp_filter_oembed_result',10)`;
   on `wp_footer`/`enqueue`: `wp_deregister_script('wp-embed')`;
   remove the `rewrite` endpoint via `add_filter('rewrite_rules_array', drop
   embed rule)` (optional; note it needs a flush — prefer leaving rewrite alone
   and just killing the script/discovery to avoid flush cost).
3. **Dashicons** (`disable_dashicons`): on `wp_enqueue_scripts` priority 100 —
   `if ( ! is_user_logged_in() ) wp_deregister_style('dashicons');`
4. **XML-RPC** (`disable_xmlrpc`): `add_filter('xmlrpc_enabled','__return_false')`;
   `add_filter('xmlrpc_methods', unset 'pingback.ping'/'pingback.extensions...')`;
   `add_filter('wp_headers', strip 'X-Pingback')`;
   `add_filter('bloginfo_url', blank pingback_url)` (optional).
5. **RSD** (`remove_rsd`): `remove_action('wp_head','rsd_link')`.
6. **WLWManifest** (`remove_wlwmanifest`):
   `remove_action('wp_head','wlwmanifest_link')`.
7. **Feeds** (`disable_feeds`): remove `feed_links`/`feed_links_extra` from
   `wp_head`; hook the feed actions to one callback:
   `do_feed, do_feed_rdf, do_feed_rss, do_feed_rss2, do_feed_atom,
   do_feed_rss2_comments, do_feed_atom_comments` (priority 1). Callback:
   - if `feed_redirect_home` → `wp_safe_redirect(home_url('/'),301); exit;`
   - else → `wp_die(esc_html__('Feeds are disabled.', ...), '', ['response'=>403]);`
8. **Query strings** (`remove_query_strings`): filter `script_loader_src` and
   `style_loader_src` → `remove_query_arg('ver', $src)`. (Document: low value under
   LSCache.) Only for non-admin requests.
9. **Heartbeat** (`heartbeat_mode`):
   - `modify`: `add_filter('heartbeat_settings', set 'interval' =
     $c['heartbeat_interval'])` (clamped 15..300).
   - `disable`: on `init`, `wp_deregister_script('heartbeat')`. UI copy should warn
     this affects autosave/post-lock. Consider allowing heartbeat on `post.php`/
     `post-new.php` even when disabled elsewhere (optional refinement).
10. **jQuery Migrate** (`disable_jquery_migrate`): `add_action('wp_default_scripts',
    remove 'jquery-migrate' from the 'jquery' script's deps)` — only on frontend
    (`! is_admin()`).

## Design constraints
- Attach each `add_action`/`add_filter` **only** when its toggle is truthy.
- Guard frontend-only features with `! is_admin()` where noted.
- No output, no DB writes.

## Acceptance criteria
- With defaults on: `wp_head` output contains no emoji script, RSD, WLW, or feed
  links; `?ver=` behavior matches the toggle; heartbeat POST interval reflects the
  setting.
- Turning every toggle off attaches no related hooks (spot-check via
  `has_action`/`has_filter`).
- Admin-side editing (autosave, TinyMCE) is unaffected unless heartbeat=disable.
- `php -l` clean, WPCS clean.

## Final step (required)
Before ending the session, update `../../STATE.md` per its "Update protocol":
flip this step's row to ✅ (or 🟡 if paused), set the commit hash, refresh Overall
status / Last updated / Next action, and log any deviations. Commit STATE.md **in
the same commit** as this step's code, then push to the branch.
