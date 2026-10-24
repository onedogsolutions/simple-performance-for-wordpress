# Simple Performance for WordPress 🚀

[![WordPress](https://img.shields.io/badge/WordPress-5.0+-blue.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![MainWP Compatible](https://img.shields.io/badge/MainWP-Compatible-brightgreen.svg)](https://mainwp.com/)

**Simple Performance for WordPress** by [One Dog Solutions](https://github.com/onedogsolutions) is a lightweight, efficient optimization plugin built for developers, agencies, and site owners who want maximum speed without heavy, bloated optimization suites. 

It targets hidden performance bottlenecks in WordPress core, cleans up unnecessary overhead, and integrates directly with **MainWP** for effortless multi-site management.

---

## ✨ Key Features & Optimizations

### 1. Core & Asset Cleanups
* **Remove Emojis & Dashicons:** Strip out front-end emoji scripts and unneeded dashicons for guest visitors to reduce HTTP requests.
* **Disable Embeds & XML-RPC:** Turn off WordPress auto-embeds and block legacy XML-RPC requests to enhance site performance and security.
* **Meta Tag Cleanup:** Remove unnecessary headers from your site's `<head>` (such as RSD links, Windows Live Writer manifests, shortlinks, and generator versions).

### 2. Resource & Heartbeat Control
* **WordPress Heartbeat API Control:** Modify or restrict the frequency of the WordPress Heartbeat API in both the backend and frontend to save server CPU resources and minimize database writes.
* **Post Revisions Management:** Limit or completely control post revisions to keep your database lean and performant.
* **Database & Autoloaded Options Cleanup:** Streamline transient data and clean up leftover autoloaded options from uninstalled plugins.

### 3. REST API Management
* **Endpoint Control:** Fine-tune access to the WordPress REST API.
* **Authentication & User Protection:** Restrict unauthenticated REST API access or hide user endpoints to protect against user enumeration attacks.

### 4. MainWP Integration
* **Centralized Dashboard Management:** Configure performance toggles across all connected child sites directly from your MainWP dashboard.
* **Bulk Configuration & Syncing:** Push speed and optimization settings to dozens or hundreds of client sites with a single click.

---

## 📦 Installation

1. Download the latest release `.zip` file from the [Releases](../../releases) page.
2. Log in to your WordPress admin dashboard.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Choose the downloaded `.zip` file, click **Install Now**, and then **Activate**.

Alternatively, install and activate via WP-CLI:
```bash
wp plugin install [https://github.com/onedogsolutions/simple-performance-for-wordpress/archive/refs/heads/main.zip](https://github.com/onedogsolutions/simple-performance-for-wordpress/archive/refs/heads/main.zip) --activate
