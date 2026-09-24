# SH Speed Optimizer — Development Guide

This document describes the architecture, conventions and extension points
for developers working on the plugin itself.

## Requirements

* PHP 8.1+ (code must stay compatible with 8.1 — no 8.2+ only syntax such as
  readonly classes, DNF types or `true` stand-alone types).
* WordPress 6.2+.
* Composer (dev tools only; the plugin has **no runtime Composer dependency**).
* Node 18+ for the Playwright end-to-end suite.

```bash
composer install          # PHPUnit, PHPCS + WPCS, PHPCompatibility
composer test             # unit tests
composer lint             # php -l on every file
vendor/bin/phpcs          # coding standards
```

## Directory layout

```
sh-speed-optimizer.php      Bootstrap (keep PHP 5.6 parseable: version check runs first)
uninstall.php               Full data removal
includes/                   Namespace SH\SpeedOptimizer\ (PSR-4, includes/Foo/Bar.php)
  Core/                     Plugin container, Settings, State, Context, Installer, Scheduler, Jobs, Filesystem, CLI
  Optimization/             Contracts, Catalog, Registry, DecisionEngine, Runtime, Engine
  Detection/                Environment/plugin/theme/hosting/CDN detection → SiteProfile
  Compatibility/            Rules, profiles (Elementor, WooCommerce …), CompatibilityManager
  Cache/                    Page cache storage, delivery, purging, preloading
  Assets/                   Tag, HtmlDocument, HtmlPipeline, minifiers, asset copies
  Database/                 Database analysis, cleanup with backups
  Diagnostics/              Logger, Scanner, PageAnalyzer, BrowserProbe, findings, health score, metrics
  Rollback/                 Snapshots and history
  Security/                 Signer (HMAC tokens), Capabilities
  API/                      REST controller
  Admin/                    Admin pages, admin bar, network admin
modules/<kebab-name>/       Namespace SH\SpeedOptimizer\Modules\<StudlyName>\ — one directory per feature area
templates/admin/            Admin page templates
assets/                     Admin + frontend CSS/JS (no build step, plain ES2017)
tests/unit/                 PHPUnit (WordPress functions stubbed in tests/unit/stubs/)
tests/e2e/                  Playwright end-to-end tests against a real WordPress site
bin/                        E2E environment scripts
```

Autoloading: `SH\SpeedOptimizer\Modules\PageCache\Foo` → `modules/page-cache/Foo.php`,
everything else → `includes/<Namespace path>.php`.

## Core concepts

### Optimization

Every optimization implements `Optimization\OptimizationInterface` (usually by
extending `AbstractOptimization`) and is listed in `Optimization\Catalog`.
It carries metadata (id, name, description, category, risk, level,
reversibility, dependencies, conflicts, requirements, default) and four pieces
of logic:

| Method               | Purpose |
|----------------------|---------|
| `assess()`           | Detection logic. Reads scan data (`AssessmentContext`), returns an `Assessment` (applicable? confidence 0–100, benefit, reasons, handled by another plugin?). Never changes anything. |
| `apply()`            | One-time side effects when activated (e.g. write the `advanced-cache.php` drop-in). Most optimizations return `true`. |
| `verify()`           | Extra server-side checks comparing baseline and candidate loopback snapshots. |
| `rollback()`         | Undo side effects and delete generated files. |
| `register_runtime()` | Called on every request while active: add hooks, HTML transformers (`$runtime->add_html_transform()`), CSS/JS content transforms (`add_css_transform()`, `add_js_transform()`). |

Risk (`safe`, `low`, `moderate`, `high`) says how likely it is to break
something. Level says when it may be enabled automatically:

* `safe` — applied automatically.
* `smart` — applied automatically only after compatibility analysis and verification.
* `experimental` — never applied automatically ("disabled by default").

### Decision engine

`DecisionEngine::decide()` turns an assessment into a `Decision`:
`auto_apply`, `test_then_apply`, `recommend` or `skip`. Rules (simplified):
handled elsewhere → skip; experimental or high risk → recommend only;
requires server-config/external-download permission not granted → recommend;
requires browser verification but no browser available → recommend;
confidence ≥ threshold (safe 80, low 75, moderate 70) → apply (+verify);
slightly below → test first; otherwise recommend.

### Runtime

`Optimization\Runtime` computes the effective active set for the request:

* `SHSO_SAFE_MODE` constant → nothing runs (emergency).
* Signed verification request (`?shso_verify=<token>`) → exactly the token's set.
* Safe Mode setting → only optimizations with `safe_mode_compatible() === true`.
* otherwise → `State::active_ids()`.

HTML transformations run in a single output buffer (`Assets\HtmlPipeline`)
started at `template_redirect`. Each transformer is isolated: an exception or
an incomplete document discards only that transformer's change. Transformers
never run for editors (`edit_posts`), in page builder previews, feeds, AMP,
REST, AJAX or admin requests.

Transformers operate on `Assets\HtmlDocument`, which masks comments,
`<script>`, `<style>`, `<noscript>`, `<textarea>` and `<template>` regions so
regex-based edits cannot corrupt them. Use `replace_tags()`,
`replace_elements()`, `replace_scripts()`, `replace_styles()`,
`insert_in_head()` and `insert_before_body_end()`; parse/modify tags with
`Assets\Tag` (lossless: untouched tags keep their exact markup).

Suggested transformer priorities: 10 cleanup, 20 images, 30 fonts, 40 CSS,
50 JS, 60 third-party, 90 resource hints/preloads.

### Compatibility

`Compatibility\CompatibilityManager::rules()` merges all applicable
`ProfileInterface` implementations plus the user's exclusions into a
`Compatibility\Rules` object: script/style exclusions, lazy-load exclusions,
cache URL/cookie exclusions, vary cookies, disabled optimizations and
confidence penalties. Optimizations must consult these rules.

### Site profile

`Detection\Detector::detect()` produces a `Detection\SiteProfile`
(environment, server, plugins, theme, builders, features, conflicting
optimization plugins, hosting/CDN, cache layers, loopback status). Shape is
documented in the class docblock.

### Jobs

Heavy work runs as step-based jobs (`Core\Jobs`): the dashboard advances a job
by calling the REST step endpoint; WP-Cron / Action Scheduler continue it when
nobody watches. A step may request measurements from the administrator's
browser (`StepResult::await_browser()`), which loads pages in hidden iframes
with a signed token and a probe script.

### Verification & rollback

Before optimizations are applied the engine snapshots settings + state
(`Rollback\SnapshotManager`). After each optimization group it compares a
baseline and a candidate render of sample URLs (server-side loopback, and in
the administrator's browser when available). Regressions roll the group back;
the engine then retries members individually to keep the good ones.

### State and settings

* `shso_settings` (autoloaded): small user settings (`Core\Settings`).
* `shso_state` (autoloaded): active/disabled optimizations, page exclusions, onboarding state (`Core\State`).
* `shso_scan` (not autoloaded): last scan (profile, page analyses, findings, decisions).
* `shso_page_data` (not autoloaded): per-template browser measurements (LCP, fonts…).
* `shso_snapshots`, `shso_job`, `shso_secret`: engine internals.
* Tables: `{prefix}shso_log` (history + debug log), `{prefix}shso_metrics`.

## Conventions

* WordPress Coding Standards (tabs, `snake_case`, Yoda conditions, `array()`),
  except PSR-4 file names.
* Every file starts with `defined( 'ABSPATH' ) || exit;`.
* Escape late (`esc_html`, `esc_attr`, `esc_url`), sanitize early, prepare SQL.
* Every admin action: capability check (`Security\Capabilities`) + nonce.
  REST routes use `permission_callback` and the `wp_rest` nonce.
* File writes/deletes only through `Core\Filesystem` (restricted to the
  plugin's cache and uploads directories; never executable extensions).
* Never modify original plugin/theme assets or original images.
* Never auto-deactivate plugins, never auto-delete database records.
* No fake data: every number shown must come from a real measurement or count;
  missing data is shown as "unavailable".
* Frontend code paths must be cheap: no scanning, no remote requests, no heavy
  queries. Use background jobs.
* Strings are translatable with the `sh-speed-optimizer` text domain.

## Hooks

See README.md → "Hooks and filters" for the public list.
