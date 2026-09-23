=== SH Speed Optimizer ===
Contributors: shdigital
Tags: performance, cache, core web vitals, lazy load, optimization
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safety-first automatic performance optimization. Analyzes your site, applies only safe optimizations, verifies them and rolls back problems.

== Description ==

SH Speed Optimizer is an optimization engine, not a collection of switches.

1. **Analyze** — detects your server, theme, page builder, shop, caching layers, CDN and plugins, and analyzes important pages (scripts, styles, images, fonts, third-party scripts, database queries).
2. **Decide** — every optimization has a risk level and its own detection logic. Only optimizations that are safe for *your* site are applied automatically.
3. **Verify** — after each group of changes your pages are compared on the server and in your browser (errors, missing forms and buttons, layout changes, JavaScript errors).
4. **Keep or roll back** — anything that causes a problem is rolled back automatically.

Features:

* Page cache with automatic purging, WooCommerce/EDD/membership protection and cache preloading
* Browser caching rules (with your permission)
* CSS and JavaScript minification (copies — originals are never modified)
* Dependency-aware JavaScript defer and third-party script delay (tracking is delayed, never removed)
* Native lazy loading, missing image dimensions, WebP images (originals untouched), main image prioritization
* Font display swap, critical font preloading, optional local Google Fonts
* Click-to-load YouTube/Vimeo videos and Google Maps
* WordPress cleanup (emojis, head links, Heartbeat on the frontend)
* Database cleanup with a backup of everything removed
* SH Performance Health report in plain language
* Optional PageSpeed Insights and anonymous real-user Core Web Vitals
* Safe Mode, emergency safe mode (`define( 'SHSO_SAFE_MODE', true );`), undo and restore points
* Works with Elementor, WooCommerce, Divi, Bricks, WPBakery, Oxygen, Beaver Builder, Gutenberg, multilingual, membership, form, booking and LMS plugins
* Detects other optimization plugins and hosting caches and avoids double optimization

SH Speed Optimizer never promises a PageSpeed score. It optimizes for real visitors and keeps your site working.

== Installation ==

1. Install and activate the plugin. Activation changes nothing.
2. Go to **SH Speed → Overview** and click **Analyze My Site**.
3. Click **Apply Safe Optimizations** and keep the page open until it finishes.

== Frequently Asked Questions ==

= Something broke. What do I do? =

Use **Optimization → Undo Last Optimization**, or turn on **Safe Mode** in the admin toolbar (SH Speed → Safe Mode). If you cannot reach the dashboard, add `define( 'SHSO_SAFE_MODE', true );` to wp-config.php.

= Can I use it together with another cache plugin? =

Yes. SH Speed Optimizer detects it and skips the features the other plugin already provides. It never deactivates plugins.

= Does it change my files or images? =

No. Optimized CSS/JS and WebP images are separate copies. Changes to wp-config.php or .htaccess only happen when you explicitly allow them and are removed on deactivation.

= Is the health score a PageSpeed score? =

No. The "SH Performance Health" score is calculated from the plugin's own diagnostics.

== Privacy ==

No data is sent to third parties unless you add a PageSpeed Insights API key (the tested URL is sent to Google) or allow local Google Fonts (font files are downloaded once). Optional real-user measurement stores only anonymous metric values — no cookies, no IP addresses.

== Changelog ==

= 1.0.0 =
* First release.
