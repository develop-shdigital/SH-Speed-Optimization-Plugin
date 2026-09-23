# Changelog

All notable changes to SH Speed Optimizer are documented here. The project
follows [Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-09-23

First release.

### Added

* **Optimization Engine**: scan → analyze → classify → optimize → verify → keep or roll back.
  Every optimization has metadata (risk, level, dependencies, conflicts,
  requirements), detection logic, apply logic, verification and rollback.
* **Decision engine** that combines compatibility confidence, expected benefit
  and risk. Safe optimizations are applied automatically; smart ones only after
  verification; experimental ones are never automatic.
* **Verification**: server-side loopback comparison of baseline and optimized
  renders (errors, incomplete pages, missing forms/buttons/navigation, missing
  generated files) and browser verification in the administrator's browser
  (JavaScript errors, failed files, layout changes, layout shift, distorted
  images). Failing groups are rolled back and retried one optimization at a time.
* **Rollback**: configuration snapshots before every change, "Undo Last
  Optimization", restore points, optimization history, daily health check.
* **Safe Mode** (settings and admin toolbar) and **emergency safe mode**
  (`SHSO_SAFE_MODE` in wp-config.php).
* **Page cache** with advanced-cache.php drop-in or fallback delivery,
  cookie/query/device/language-aware keys, WooCommerce/EDD/membership
  protection, automatic purging, gzip, 304 revalidation, cache preloading.
* **Browser caching** rules for Apache/LiteSpeed (explicit permission only) and
  an Nginx snippet.
* **CSS/JS**: minified copies (originals never modified), dependency-aware
  defer, third-party script delay until interaction, experimental delay of all
  scripts and critical CSS.
* **Images**: native lazy loading, missing dimensions, WebP derivatives
  (originals untouched), LCP image priority from browser measurements.
* **Fonts**: `font-display: swap`, critical font preloading, optional Google
  Fonts localization.
* **Third-party**: YouTube/Vimeo and Google Maps click-to-load facades,
  preconnect hints.
* **WordPress cleanup**: emojis, head cleanup, oEmbed discovery, embed script,
  self pingbacks, Heartbeat reduction on the frontend.
* **Diagnostics**: SH Performance Health score, plain-language findings,
  asset analysis per page, database and query analysis, media library analysis,
  optional PageSpeed Insights, optional anonymous real-user Core Web Vitals.
* **Database cleanup** with a backup of every removed row and restore.
* **Compatibility profiles** for Elementor (Pro), WooCommerce, EDD, Divi, Bricks,
  WPBakery, Oxygen/Breakdance, Beaver Builder, Gutenberg, ACF, WPML, Polylang,
  TranslatePress, form, booking, membership, LMS, slider and filter plugins.
* **Conflict detection** for WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super
  Cache, Autoptimize, FlyingPress, NitroPack, SG Optimizer, 10Web Booster and
  many more — overlapping features are skipped, nothing is deactivated.
* Minimal admin: Overview, Optimization, Diagnostics, Cache, Settings; network
  defaults on multisite; WP-CLI commands (`wp shso`).
