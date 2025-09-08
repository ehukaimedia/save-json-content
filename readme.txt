=== SAVE JSON — Search • Answer • Voice • Engine ===
Contributors: yourname
Tags: seo, json-ld, meta, voice, summary, tldr, export, import, rest api, sitemaps, breadcrumbs, migration
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site-wide SEO with a hardened, lightweight approach. Search Appearance templates, Site representation (Organization/Person), Social defaults, Breadcrumbs JSON-LD, Sitemaps UI, per-post meta (SEO Title/Description, Canonical, Robots), FAQ JSON-LD, voice summary, and a Yoast migration wizard.

== Description ==

**New in 2.0.0**

* Admin Dashboard: quick status & shortcuts.
* Search Appearance: title/meta templates with variables + separator.
* Site Representation: Organization/Person, logo, social profiles (sameAs).
* Social Defaults: global OG/Twitter fallbacks.
* Canonical & Robots: per-post canonical, follow/nofollow, advanced robots (nosnippet, etc.).
* Breadcrumbs: shortcode/block + JSON-LD.
* Sitemaps: UI for core sitemaps (types/taxonomies, include images).
* Tools: bulk editor, robots.txt/.htaccess editor (uses WP_Filesystem), RSS content before/after.
* Yoast Migration: import common per-post and global settings.

**What remains from 1.x**

* Per-post TL;DR, meta description, FAQ, and optional voice button.
* Social overrides and JSON-LD (Article/WebPage + FAQ).

== Installation ==

1. Upload the ZIP and activate the plugin.
2. Open **SAVE JSON → Search Appearance** to set templates.
3. (Optional) Run **SAVE JSON → Yoast Migration**.
4. (Optional) Insert **[savejson_breadcrumbs]** where you want breadcrumbs.

== Shortcodes & Blocks ==

* `[savejson_breadcrumbs]` — outputs breadcrumb HTML + BreadcrumbList JSON-LD.

== Frequently Asked Questions ==

= Will this conflict with Yoast? =
Yes, if both are active they may output duplicate tags. Use the migration tool, then deactivate Yoast.

== Changelog ==

= 2.0.0 - 2025-08-23 =
* First release of the site-wide admin, templates, tools, and Yoast migration.
