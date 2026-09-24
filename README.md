# SH Speed Optimizer

Safety-first, automatic performance optimization for WordPress.

SH Speed Optimizer is not just a caching plugin. It is an optimization engine
that **scans** your site, **decides** which optimizations are safe for *this*
site (theme, page builder, shop, plugins, server), **applies** them one group at
a time, **verifies** the result on the server and in a real browser, and
**rolls back** anything that causes a problem.

> Install it → analyze → optimize → leave it alone.

Priorities, in this order: **Safety → Compatibility → Stability → Real-world
performance → Core Web Vitals → aggressive optimization.** It never trades a
working site for a higher PageSpeed score.

---

## Contents

1. [Installation](#installation)
2. [How it works](#how-it-works)
3. [Architecture](#architecture)
4. [Optimization modules](#optimization-modules)
5. [Safety system](#safety-system)
6. [Compatibility system](#compatibility-system)
7. [Rollback](#rollback)
8. [Safe Mode and emergency safe mode](#safe-mode-and-emergency-safe-mode)
9. [Developer mode](#developer-mode)
10. [Hooks and filters](#hooks-and-filters)
11. [WP-CLI](#wp-cli)
12. [Privacy](#privacy)
13. [Troubleshooting](#troubleshooting)

---

## Installation

Requirements: WordPress 6.2+, PHP 8.1+.

1. Upload the `sh-speed-optimizer` folder to `wp-content/plugins/` (or install the ZIP via *Plugins → Add New → Upload*).
2. Activate the plugin. Activation changes **nothing** on your site.
3. Open **SH Speed → Overview** and click **Analyze My Site**.
4. Review the result ("Your website is ready for N safe optimizations") and click **Apply Safe Optimizations**.

Keep the dashboard open during the first optimization: some checks run in your
browser (it loads your pages in small hidden frames and looks for JavaScript
errors and layout problems). If you close it, the rest continues in the
background and optimizations that need a browser test are simply not applied.

### Optional

* **Faster cache delivery** — *Cache → Enable faster cache delivery* adds
  one marked line, `define( 'WP_CACHE', true );`, to `wp-config.php` (verified, reverted on any problem, removed on deactivation) so
  cached pages are served before WordPress loads. Without it, the page cache
  still works ("standard delivery").
* **Browser caching rules** — on Apache/LiteSpeed, allow SH Speed to add
  long-lived cache headers for static files to `.htaccess` (explicit permission,
  removable anytime). On Nginx the dashboard shows the configuration to add.
* **PageSpeed Insights** — add an API key in *Settings → Measurement* to see
  Google's lab and field data (before/after comparisons are labelled with their source).
* **Real-user Core Web Vitals** — opt-in anonymous measurement from a small
  sample of visitors.

---

## How it works

```
SCAN → ANALYZE → CLASSIFY → OPTIMIZE → VERIFY → KEEP OR ROLLBACK
```

1. **Scan** — environment (WordPress, PHP, server, OPcache, object cache),
   plugins, theme, page builder, WooCommerce, caching layers, CDN, hosting,
   loopback availability, browser cache headers.
2. **Analyze** — up to six representative pages (home, a page, a post, a page
   builder page, shop, product) are requested with a signed token. The plugin
   records WordPress' own script/style registry (handles, dependencies, inline
   code), generation time and database queries, and parses the HTML (images,
   iframes, fonts, third-party scripts). The database and media library are
   inspected. With the dashboard open, pages are also measured in the browser
   (LCP element, above-the-fold images, fonts in use, oversized images, unused
   CSS candidates, existing JavaScript errors).
3. **Classify** — every optimization's detection logic produces an
   *assessment* (applicable? compatibility confidence, expected benefit,
   handled by another plugin?). The decision engine combines it with the risk
   level, your settings and the available verification methods.
4. **Optimize** — a configuration snapshot is taken, then groups are applied in
   order: cleanup → cache → images → fonts → CSS → JavaScript → third-party.
5. **Verify** — after each group, baseline and optimized renders are compared
   on the server (HTTP status, PHP errors, complete HTML, forms/buttons/
   navigation still present, generated files exist) and — for groups that
   change CSS/JS/images — in the browser (new JavaScript errors, failed files,
   hidden page areas, layout changes, layout shift, stretched images).
6. **Keep or roll back** — a failing group is rolled back and its members are
   re-tested individually, so the good ones are kept. Rolled-back
   optimizations are not re-applied automatically.

A daily health check repeats the server-side comparison and rolls back
optimizations that start causing problems (e.g. after a theme update).

### Optimization levels

| Level | When it is enabled | Examples |
|---|---|---|
| **Safe** | Automatically, verified | Emoji cleanup, page cache, native lazy loading, minification, font-display swap, LCP priority |
| **Smart** | Automatically only after compatibility analysis *and* verification | JS defer, third-party delay, iframe lazy loading, WebP delivery, video/map facades, font preloading |
| **Experimental** | Never automatically ("disabled by default"); requires *Advanced Optimizations* | Delay all JavaScript, critical CSS, remove jQuery Migrate, speculative prefetch |

Risk (safe / low / moderate / high) is separate from level: high-risk
optimizations are never enabled automatically on a live site.

---

## Architecture

```
sh-speed-optimizer.php       Bootstrap (version check, autoloader, activation hooks)
uninstall.php                Complete data removal
includes/
  Core/                      Plugin container, Settings, State, Context, Installer,
                             Scheduler, Jobs (step-based background tasks), Filesystem, CLI
  Optimization/              OptimizationInterface, AbstractOptimization, Catalog, Registry,
                             DecisionEngine, Runtime (per-request activation), Engine
  Detection/                 Environment/server/plugin/theme/hosting/CDN detection → SiteProfile
  Compatibility/             Rules, profiles, CompatibilityManager
  Cache/                     Page cache storage, delivery (drop-in + fallback), purging, preloading
  Assets/                    Tag, HtmlDocument (masked regex-safe editing), HtmlPipeline,
                             minifiers, optimized copies, script dependency graph, delay engine,
                             image and font helpers
  Database/                  Analysis, cleanup job with backups, restore
  Diagnostics/               Logger, Scanner, PageAnalyzer, Loopback, Verifier, BrowserProbe,
                             BrowserEvaluator, FindingsBuilder, HealthScore, Metrics,
                             PageSpeed, Rum
  Rollback/                  Snapshots, undo
  Security/                  Signer (HMAC tokens), Capabilities
  API/                       REST controller (shso/v1)
  Admin/                     Admin pages, toolbar menu, network defaults, harness assets
modules/<feature>/           One directory per feature area (page-cache, css-optimization, …)
templates/admin/             Admin templates
assets/                      Admin UI, browser probe/harness, delay loader, facades, RUM
tests/unit/                  PHPUnit
tests/e2e/                   Playwright end-to-end tests on a real WordPress site
```

Key design points:

* **Lightweight at runtime.** A visitor request loads the settings/state
  options, instantiates only the *active* optimizations and runs one output
  buffer. Scanner, diagnostics, admin and REST code never load on the frontend.
* **Isolation.** Each optimization is a small class; each HTML transformer runs
  in isolation — an exception or an incomplete result discards only that
  transformer's change.
* **Lossless HTML edits.** Transformers work tag by tag (`Assets\Tag`) on a
  document whose comments, scripts, styles, `<noscript>`, `<textarea>` and
  `<template>` regions are masked (`Assets\HtmlDocument`).
* **Originals are never modified.** CSS/JS minification writes copies to
  `wp-content/cache/sh-speed-optimizer/assets/`; WebP derivatives go to
  `wp-content/uploads/sh-speed-optimizer/webp/`. If a copy is missing the
  original is served.
* **Heavy work in the background.** Scans, verification, asset generation, WebP
  conversion, cache preloading, font localization and database cleanup run as
  step-based jobs driven by the dashboard, WP-Cron or Action Scheduler.

More in [DEVELOPMENT.md](DEVELOPMENT.md).

---

## Optimization modules

| Id | Name | Category | Risk / level | Notes |
|---|---|---|---|---|
| `disable_emojis` | Remove emoji scripts | Cleanup | safe / safe | |
| `head_cleanup` | Clean up the page head | Cleanup | safe / safe | Generator, RSD, shortlink; comment feed link only when comments are closed |
| `disable_oembed_discovery` | Remove oEmbed discovery links | Cleanup | safe / safe | Embedding other sites keeps working |
| `disable_embeds_script` | Load the embed script only when needed | Cleanup | safe / safe | Not needed on WordPress 6.4+ |
| `disable_self_pingbacks` | Stop self pingbacks | Cleanup | safe / safe | XML-RPC stays available |
| `heartbeat_frontend` | Reduce Heartbeat on the frontend | Cleanup | safe / safe | Dashboard, editor, autosave untouched |
| `remove_jquery_migrate` | Remove jQuery Migrate | Cleanup | high / experimental | Browser-verified |
| `page_cache` | Page cache | Cache | low / safe | Drop-in or fallback delivery |
| `browser_cache` | Browser caching rules | Cache | low / safe | Needs permission for `.htaccess` |
| `lazy_load_images` | Lazy-load images below the fold | Images | low / safe | LCP image and logos excluded |
| `lazy_load_iframes` | Lazy-load embedded frames | Images | low / smart | |
| `image_dimensions` | Add missing image dimensions | Images | low / safe | Browser-verified (distortion check) |
| `webp_images` | WebP images | Images | low / smart | Background conversion, originals untouched |
| `lcp_priority` | Prioritize the main image | Images | low / safe | Only with high-confidence browser measurement |
| `font_display_swap` | Show text while fonts load | Fonts | safe / safe | Icon fonts excluded |
| `font_preload` | Preload critical fonts | Fonts | low / smart | Max 2, measured |
| `localize_google_fonts` | Host Google Fonts locally | Fonts | low / smart | Explicit permission |
| `css_minify` | Minify CSS | CSS | low / safe | Copies only |
| `critical_css` | Critical CSS | CSS | high / experimental | Generated in the browser, per template |
| `js_minify` | Minify JavaScript | JavaScript | low / safe | Conservative, browser-verified |
| `js_defer` | Defer non-critical JavaScript | JavaScript | moderate / smart | Dependency-aware |
| `js_delay_all` | Delay all JavaScript until interaction | JavaScript | high / experimental | |
| `preconnect` | Preconnect to critical origins | Third-party | safe / safe | Max 3 |
| `js_delay_third_party` | Delay third-party scripts until interaction | Third-party | moderate / smart | Tracking is delayed, never removed |
| `video_facade` | Click-to-load videos | Third-party | moderate / smart | YouTube, Vimeo; accessible |
| `map_facade` | Click-to-load Google Maps | Third-party | moderate / smart | Not applied when the map is hero content |
| `speculative_prefetch` | Prefetch likely next pages | Third-party | moderate / experimental | Skipped when WordPress core does it |

Report-only analyses (never automatic changes): database bloat and autoload
size, slow/repeated/expensive queries per plugin, plugins loading assets on
every page, unused CSS candidates, oversized images, missing alt text, font
weights, render-blocking resources, third-party scripts, server configuration.

---

## Safety system

* **Decision engine** — confidence thresholds per risk (safe 80, low 75,
  moderate 70), benefit must be real, high-risk and experimental never
  automatic, permissions required for server configuration and external downloads.
* **Signed verification requests** — `?shso_verify=<token>` (HMAC-SHA256 with a
  per-site secret, 15 minute lifetime) renders a page as an anonymous visitor
  with exactly the requested optimizations, bypassing every cache. Visitors
  cannot use it.
* **Server verification** — see *How it works*.
* **Browser verification** — the dashboard loads baseline and optimized pages in
  sandboxed same-origin frames (no top navigation, no popups) and a probe
  reports errors and layout metrics. Same-origin access is needed to generate
  critical CSS, so scripts on the tested pages (including third-party scripts
  a site may hide from logged-in users) run next to the dashboard while the
  checks run. Only run browser checks when you trust the scripts on your site.
* **Server files** — `wp-config.php`, `advanced-cache.php` and `.htaccess` are
  only changed with explicit permission, never when `DISALLOW_FILE_MODS` is set
  and, on multisite, only at a network administrator's request.
  `wp-config.php` is validated and then replaced atomically.
* **Isolation of failures** — failing groups are split and retried.
* **Per-page exclusions** — optimizations can be excluded for a single template
  or URL instead of globally.
* **Never**: modifies original theme/plugin files or original images, deletes
  database records automatically, deactivates plugins, removes tracking,
  caches logged-in users, carts, checkouts, POST/REST/AJAX responses or
  responses that set cookies.

---

## Compatibility system

`Compatibility\CompatibilityManager` merges profiles for the software found on
the site plus your own exclusions into one set of rules: scripts that must never
be deferred/delayed/minified, styles never optimized, images never lazy-loaded,
URLs and cookies that bypass the page cache, cookies that create cache
variants (currency, language), optimizations disabled with a reason, and
confidence penalties.

Profiles: Elementor, Elementor Pro, WooCommerce, Easy Digital Downloads, Divi,
Bricks, WPBakery, Oxygen/Breakdance, Beaver Builder, Gutenberg/core, ACF,
WPML/Polylang/TranslatePress, form plugins (CF7, Gravity Forms, WPForms, Ninja
Forms, Fluent Forms, Formidable, Forminator), membership and LMS plugins,
booking plugins, sliders, cookie consent tools, AJAX filter plugins,
BuddyPress/bbPress and generic builders.

Other optimization plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super
Cache, Autoptimize, FlyingPress, NitroPack, SG Optimizer, 10Web Booster,
Perfmatters, Hummingbird, Breeze, WP-Optimize, image optimizers …) and
managed-hosting caches are detected. SH Speed shows "Another optimization system
is active" and skips only the overlapping features. It never deactivates them.

---

## Rollback

* **Snapshots** of settings and engine state are taken before every automatic
  run and every manual change (last 20 kept).
* **Undo Last Optimization** (*Optimization → History*) restores the latest
  snapshot; pressing it again walks further back.
* **Restore points** — any listed snapshot can be restored.
* **Optimization history** — what was applied, when, and why something was
  rolled back ("JS defer rolled back because 1 new JavaScript error: …").
* Rolling back an optimization also removes its side effects (drop-in,
  `.htaccess` block, generated files) and purges the page cache.

Deactivating the plugin removes all side effects but keeps settings, so
reactivation restores the previous configuration. Deleting the plugin removes
everything (see `uninstall.php`).

---

## Safe Mode and emergency safe mode

**Safe Mode** (*Settings* or the admin toolbar: *SH Speed → Safe Mode*) keeps
only functionality-neutral optimizations (page cache, browser caching, cleanup)
and bypasses all CSS/JS/HTML transformations and experimental features.
The page cache is purged when it changes.

**Emergency safe mode** works even when the dashboard cannot load. Add to
`wp-config.php`:

```php
define( 'SHSO_SAFE_MODE', true );
```

With this constant every transformation and the page cache (including the
`advanced-cache.php` drop-in) are bypassed. Remove the line to return to normal.

To disable only the page cache: `define( 'SHSO_DISABLE_CACHE', true );`.
To skip optimization on a request (e.g. in a custom template): `define( 'SHSO_DONOTOPTIMIZE', true );`.

---

## Developer mode

```php
define( 'SHSO_DEBUG', true );
```

or *Settings → Developer → Debug logging*. Records optimization decisions,
compatibility exclusions, cache events, transformer errors and verification
details in the `{prefix}shso_log` table (debug entries are kept for 7 days,
secrets and personal data are scrubbed). Debug information is never shown to
visitors. *Diagnostics → Developer Diagnostics* shows the environment, active
compatibility rules, asset lists per page, query analysis, cache status, raw
decisions and the debug log, and can download everything as JSON.

---

## Hooks and filters

### Actions

| Hook | Arguments | When |
|---|---|---|
| `shso_before_optimization` | `$id, $source` | Before an optimization is applied (`auto`/`manual`) |
| `shso_after_optimization` | `$id, $source` | After it was applied (before verification) |
| `shso_optimization_failed` | `$id, $reason` | Apply or verification failed |
| `shso_optimization_rollback` | `$id, $reason, $code` | After a rollback |
| `shso_cache_cleared` | `$scope, $urls` | Page cache purged (`all`, `url`, `post`, `term`) |
| `shso_cache_generated` | `$url, $file` | A page was stored in the cache |
| `shso_asset_excluded` | `$asset, $rule_list, $source` | An asset was left untouched because of an exclusion (`compatibility` profile or `user` setting) |
| `shso_url_excluded` | `$url, $reason` | A URL was excluded from caching |
| `shso_settings_updated` | `$current, $previous` | Settings changed |
| `shso_configuration_changed` | `$reason` | Optimizations/settings changed (cache purged) |
| `shso_snapshot_restored` | `$id, $snapshot` | A restore point was restored |
| `shso_register_job_handlers` | `$jobs, $plugin` | Register custom background job types |
| `shso_purge_all` | — | Fire to purge the page cache |
| `shso_db_cleaned` | `$summary` | A database cleanup finished (rows per item, backup id) |
| `shso_db_restored` | `$result, $backup_id` | A database backup was restored |
| `shso_critical_css_stored` | `$template, $entry` | Critical CSS was generated for a template |
| `shso_asset_copy_generated` | `$path, $source, $ids` | An optimized CSS/JS copy was written |
| `shso_local_fonts_updated` | — | Locally hosted Google Fonts changed |

### Filters

| Filter | Purpose |
|---|---|
| `shso_optimization_catalog` | Add/replace optimization classes (`id => class`) |
| `shso_active_optimizations` | Active optimization ids for the current request |
| `shso_optimization_active_on_page` | Whether an optimization runs on the current page |
| `shso_should_transform` | Whether HTML transformations run on the current request |
| `shso_optimized_html` | Final optimized HTML (and ids that changed it) |
| `shso_compatibility_rules` | Modify the merged compatibility `Rules` object |
| `shso_is_editor_preview` | Mark custom builder/preview requests |
| `shso_manage_capability` | Capability required to manage the plugin (default `manage_options`) |
| `shso_cache_dir` | Cache root (must stay inside `wp-content`) |
| `shso_sample_urls` | URLs used for scans and verification |
| `shso_markup_signatures` | Plugin markup used for "loaded but unused" hints |
| `shso_cron_hooks` | Cron hooks cleared on deactivation |
| `shso_loopback_use_curl` | Use cURL (default) or the WordPress HTTP API for loopbacks |
| `shso_rum_sample_rate` | Share of page views measured (default 0.1) |
| `shso_rum_daily_cap` | Max stored real-user beacons per day |
| `shso_cache_excluded_urls` | URL patterns never cached |
| `shso_cache_bypass_cookies` | Cookie prefixes that bypass the page cache |
| `shso_cache_ignored_query_params` | Tracking parameters stripped from the cache key |
| `shso_cache_lifespan` | Cache lifespan in seconds (`$seconds, $url`) |
| `shso_cache_should_store` | Whether a page may be stored (`$store, $url`) |
| `shso_cache_post_urls` | URLs purged when a post changes (`$urls, $post`) |
| `shso_cache_mobile_variant` | Whether to keep a separate mobile cache |
| `shso_preload_urls` | URLs the cache preloader visits |
| `shso_db_clean_excluded_post_types` | Post types database cleanup never touches |
| `shso_db_option_source_map` | Option prefix → plugin/theme name for the options report |
| `shso_db_backup_retention_days` | Days database backups are kept (default 30) |
| `shso_compatibility_profiles` | Add/remove compatibility profiles |
| `shso_site_profile_quick` / `shso_site_profile` | Adjust the detected site profile |
| `shso_webp_quality` | WebP/AVIF quality (`$quality, $format, $source`) |
| `shso_avif_delivery` | Serve AVIF when available (default false) |
| `shso_webp_max_bytes` | Disk budget for converted images |
| `shso_prefetch_excluded_paths` | Paths never prefetched (cart, checkout, …) |
| `shso_delay_timeout` | Seconds before delayed third-party scripts load anyway (default 8) |
| `shso_delay_all_timeout` | Fallback timeout for all delayed scripts (default 0 = interaction only) |

Module-specific hooks are listed in the class docblocks next to each
`do_action()` / `apply_filters()` call.

### Adding an optimization

```php
add_filter( 'shso_optimization_catalog', function ( $catalog ) {
	$catalog['my_optimization'] = My\Optimization::class; // extends SH\SpeedOptimizer\Optimization\AbstractOptimization
	return $catalog;
} );
```

Implement `id()`, `name()`, `description()`, `category()`, `risk()`,
`level()`, `assess()` and `register_runtime()`; override `apply()`,
`verify()`, `rollback()`, `requirements()` and `expected_changes()` as needed.

---

## WP-CLI

```
wp shso status              Health, active optimizations, safe mode, cache
wp shso scan                Run a scan (server-side checks only)
wp shso optimize            Scan and apply what passes server-side verification
wp shso purge [--url=<url>] Purge the page cache
wp shso safe-mode on|off    Toggle Safe Mode
wp shso undo                Undo the last optimization change
wp shso reset [--yes]       Turn off all optimizations
```

---

## Privacy

* No data leaves your server unless you configure a PageSpeed Insights API key
  (the tested URL is sent to Google) or enable Google Fonts localization (font
  files are downloaded from Google once).
* Real-user Core Web Vitals (opt-in) store only metric values and the page
  template type — no cookies, no IP addresses, no identifiers.
* Logs never contain passwords, tokens, payment data or e-mail addresses.

---

## Troubleshooting

| Symptom | What to do |
|---|---|
| Something looks broken after optimization | *Optimization → Undo Last Optimization*, or turn on Safe Mode from the admin toolbar. Report the page URL. |
| The dashboard does not load | Add `define( 'SHSO_SAFE_MODE', true );` to `wp-config.php`, then deactivate/reactivate or fix the cause. |
| "Your server blocks requests to itself" | Loopback requests are blocked (firewall, basic auth, hosts file). Automatic verification is limited; ask your host to allow loopbacks (Site Health shows the same). |
| Browser tests are skipped | Keep the dashboard open during optimization. Pages that send `X-Frame-Options: DENY` or `frame-ancestors 'none'` cannot be tested; optimizations that need a browser test stay off. |
| "Another optimization system is active" | Expected. SH Speed skips the overlapping features. Keep one caching plugin only. |
| Page cache shows "Standard delivery" | Optional: *Cache → Enable faster cache delivery* (adds `WP_CACHE` to wp-config.php). |
| Cached page shows outdated content | Pages are purged automatically on changes; use *SH Speed → Purge this page* in the toolbar. Check for another cache layer (CDN, host). |
| A specific page misbehaves | Add its URL to *Settings → Exclusions → URL exclusions* (no caching, no optimization for that URL). |
| A script breaks when deferred/delayed | Add its handle or file name to *JS exclusions*. |
| Enable detailed logging | `define( 'SHSO_DEBUG', true );` then *Diagnostics → Developer Diagnostics*. |

HTTP response header `X-SHSO-Cache: HIT|MISS|BYPASS` shows the page cache
decision for a request.

---

## License

GPL-2.0-or-later.
