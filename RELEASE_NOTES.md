# SH Speed Optimizer 1.0.0

First release of SH Speed Optimizer, a WordPress performance plugin that puts safety first. It never applies a change it hasn't checked. Every optimization goes through the same steps: scan, analyze, classify, apply, verify, then keep it or roll it back automatically.

**Requirements:** WordPress 6.2+ and PHP 8.1+. Tested up to WordPress 7.1.

## Highlights

- **One-click, verified optimization.** "Analyze My Site" reports how many safe optimizations your site is ready for; "Apply Safe Optimizations" applies them. Each change is checked on the server and in your browser (JavaScript errors, failed files, layout shifts, distorted images) before it reaches visitors.
- **Automatic rollback.** A snapshot is taken before every change. Anything that breaks the check is undone automatically. You can also use "Undo Last Optimization", restore points, a daily health check, Safe Mode in the admin toolbar, and the emergency constant `define( 'SHSO_SAFE_MODE', true );`.
- **Honest reporting.** The score is labelled "SH Performance Health", not a PageSpeed score. Core Web Vitals come only from real field data. When there is none, the dashboard says "Field data unavailable".

## What's included

- **Page cache:** delivery through the `advanced-cache.php` drop-in or a fallback mode. Cache keys account for cookies, query strings and devices. Logged-in users and WooCommerce/EDD carts and checkouts are never cached. Includes automatic purging, preloading, gzip and 304 responses.
- **Browser caching:** Apache/LiteSpeed rules, only with your explicit permission, plus an Nginx snippet.
- **CSS and JavaScript:**
  - Minified copies are served; your original files are never modified.
  - Defer respects script dependencies.
  - Third-party scripts wait until the visitor interacts with the page.
  - Experimental (never applied automatically): delaying all scripts, and critical CSS.
- **Images:** native lazy loading, missing width/height attributes, WebP copies (originals untouched), priority loading for the largest image on the page.
- **Fonts:** `font-display: swap`, preloading of the fonts the first screen needs, optional local hosting of Google Fonts.
- **Third-party embeds:** click-to-load YouTube, Vimeo and Google Maps, and preconnect hints.
- **WordPress cleanup:** emojis, head cleanup, oEmbed and embed scripts, self pingbacks, Heartbeat reduction.
- **Database:** cleanup that backs up every row it removes, with restore. Includes query and options analysis.
- **Diagnostics:** plain-language findings, per-page asset analysis, media analysis. Optional: PageSpeed Insights and anonymous real-user Core Web Vitals (off by default).
- **Compatibility:**
  - Built-in profiles for Elementor, WooCommerce, EDD, Divi, Bricks, WPBakery, Oxygen/Breakdance, Beaver Builder, Gutenberg, ACF, WPML, Polylang, TranslatePress, and form, booking, membership, LMS, slider and filter plugins.
  - Overlap with WP Rocket, LiteSpeed Cache, W3 Total Cache, Autoptimize, FlyingPress, NitroPack and others is detected. Duplicate features are skipped; no plugin is ever deactivated.
- **Admin, multisite and developers:** a minimal "SH Speed" menu (Overview, Optimization, Diagnostics, Cache, Settings), network defaults and locks on multisite, WP-CLI commands (`wp shso`), and documented hooks.

## Security

This release includes the fixes from a pre-release security audit, which found no critical or high-severity issues:

- Pages requested with tracking parameters (`utm_*`, `fbclid`, …) are never stored in the cache.
- `wp-config.php`, `advanced-cache.php` and `.htaccess` are:
  - never changed when `DISALLOW_FILE_MODS` is set;
  - on multisite, changed only at a network administrator's request.
  `wp-config.php` is validated and then replaced atomically.
- Loopback requests follow redirects only within the site.
- Real-user monitoring is rate-limited before anything is stored.
- Suspended or deleted network sites stop being served from the cache immediately.
- Click-to-load embeds are limited to YouTube, Vimeo and Google Maps, with an allowlist of iframe attributes.

## Good to know

- **Browser checks:** these load your pages inside the dashboard, so scripts on those pages run next to it while the check runs. Run them only when you trust the scripts on your site.
- **PHP sessions:** visitors with a PHP session cookie (`PHPSESSID`) are not served from the page cache.

Full details are in [CHANGELOG.md](https://github.com/develop-shdigital/SH-Speed-Optimization-Plugin/blob/main/CHANGELOG.md).
