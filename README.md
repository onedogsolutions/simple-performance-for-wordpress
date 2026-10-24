# Simple Performance for WordPress 🚀

[![WordPress](https://img.shields.io/badge/WordPress-5.0+-blue.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![MainWP Compatible](https://img.shields.io/badge/MainWP-Compatible-brightgreen.svg)](https://mainwp.com/)

**Simple Performance for WordPress** by [One Dog Solutions](https://github.com/onedogsolutions) is a lightweight, bloat-free optimization plugin. Designed for developers and agency owners, it strips out unnecessary WordPress overhead and gives you fine-grained control over core performance settings. 

Instead of overwhelming you with complex caching rules, this plugin focuses on the essential core tweaks that make a measurable difference—while integrating seamlessly with **MainWP** for multi-site management.

## ✨ Key Features

* **Core WordPress Optimizations:** Clean up your site's `<head>`, remove unnecessary meta tags, and disable heavy default assets (like WP emojis and dashicons) that slow down front-end rendering.
* **REST API Management:** Take control of your WordPress REST API. Easily manage endpoints, restrict access for unauthenticated users, and stop third-party requests from draining your server resources.
* **MainWP Integration:** Managing multiple websites? Configure *Simple Performance* on all of your connected child sites directly from your centralized MainWP Dashboard. Deploy performance tweaks at scale in just a few clicks.
* **Zero Bloat Codebase:** Built to be exceptionally lightweight. It won't slow down your admin panel or conflict with server-level caching (like Redis or Varnish).

## 📦 Installation

1. Download the latest release from the [Releases](../../releases) page.
2. Log in to your WordPress dashboard.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Upload the downloaded `.zip` file and click **Install Now**.
5. Click **Activate**.

*To install via WP-CLI:*
```bash
wp plugin install [https://github.com/onedogsolutions/simple-performance-for-wordpress/archive/refs/heads/main.zip](https://github.com/onedogsolutions/simple-performance-for-wordpress/archive/refs/heads/main.zip) --activate
