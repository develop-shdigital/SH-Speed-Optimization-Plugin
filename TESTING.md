# Testing SH Speed Optimizer

Two automated layers plus a manual checklist.

| Layer | Tooling | What it covers |
|---|---|---|
| Unit | PHPUnit 10 (`tests/unit`) | Pure logic without WordPress: HTML masking/tag editing, minifiers, script dependency graph, delay engine, cache keys/bypass rules/storage, compatibility rules, detection parsers, decision engine, verifier, browser evaluator, findings, health score, database backup validation, query normalization, signer, settings sanitization, filesystem path guard |
| End-to-end | Playwright (`tests/e2e`) on a real WordPress 7.x site with WooCommerce, Elementor and Contact Form 7 running on SQLite | The critical scenarios below, the onboarding/optimize flow in a real browser, rollback of a deliberately broken optimization, Safe Mode and emergency safe mode, cache invalidation |

## Unit tests

```bash
composer install
composer test                     # or: vendor/bin/phpunit
vendor/bin/phpunit --filter Cache # one area
```

WordPress functions are stubbed in `tests/unit/stubs/` (`wordpress.php` is loaded
first; area stubs only add missing functions).

## End-to-end tests

```bash
bin/e2e-setup.sh                  # builds /home/user/shso-e2e/wordpress (WordPress + SQLite + WooCommerce + Elementor + CF7 + content)
bin/e2e-server.sh start           # PHP built-in server with 8 workers on http://127.0.0.1:8889
cd tests/e2e && npm install && npm test
```

The site runs in `WP_ENVIRONMENT_TYPE=production` so production-only rules apply.
The PHP built-in server needs `PHP_CLI_SERVER_WORKERS` > 1 because the plugin
requests its own pages during verification (the script sets 8).

Environment variables: `SHSO_E2E_DIR`, `SHSO_E2E_PORT`, `SHSO_E2E_BASE_URL`,
`SHSO_PLUGIN_SRC`, `SHSO_E2E_OFFLINE=1` (cached downloads only). See
`tests/e2e/README.md`.

Notes:
* Chromium in the container does not trust the outbound TLS proxy, so
  third-party resources (YouTube, Google Fonts, Gravatar) fail to load; tests
  ignore those network errors with `THIRD_PARTY_NETWORK_NOISE` but fail on any
  error from the site itself.
* WooCommerce 11 logs `404 /cart/undefinedwc/store/v1/cart` on cart/checkout
  without the plugin too; it is treated as a known baseline error.

### Critical scenarios

| # | Scenario | Spec |
|---|---|---|
| 1 | Elementor homepage/landing renders its widgets after optimization | `30-frontend.spec.js` |
| 2 | Elementor popup/dynamic widgets: Elementor handles excluded from defer/delay | unit: compatibility profile; E2E: no new JS errors on the Elementor page |
| 3 | Elementor/CF7 form present and submittable | `30-frontend.spec.js` |
| 4 | WooCommerce cart never cached | `30-frontend.spec.js` |
| 5 | WooCommerce checkout never cached | `30-frontend.spec.js` |
| 6 | WooCommerce AJAX add-to-cart works for visitors | `30-frontend.spec.js` |
| 7 | Contact form | `30-frontend.spec.js` |
| 8 | JavaScript menu | `30-frontend.spec.js` (navigation overlay) |
| 9 | Mobile navigation | `30-frontend.spec.js` (390×844 viewport) |
| 10 | Google Maps (facade loads the map on click) | `30-frontend.spec.js` |
| 11 | YouTube embed (facade loads the player on click) | `30-frontend.spec.js` |
| 12 | Slider (Elementor/Swiper handles protected) | unit: compatibility profiles |
| 13 | Custom AJAX (admin-ajax.php never cached, works) | `30-frontend.spec.js` |
| 14 | REST API never cached | `30-frontend.spec.js` |
| 15 | Logged-in admin gets unmodified, uncached pages | `30-frontend.spec.js` |
| 16 | Logged-out visitor gets cached, optimized pages | `30-frontend.spec.js` |

Additional specs: activation changes nothing (`10-activation`), full onboarding
→ analyze → apply flow in the browser (`20-optimize-flow`), automatic rollback
of a breaking optimization (`40-rollback`), Safe Mode / emergency safe mode
(`50-safe-mode`), cache invalidation on content changes (`60-cache-invalidation`).

## Manual checklist (before a release)

* [ ] Fresh install → activation changes nothing (`X-SHSO-Cache` absent, no markup changes).
* [ ] Analyze My Site finishes with a report; numbers match reality (spot-check largest image size, script counts).
* [ ] Apply Safe Optimizations: summary lists what was applied; rolled-back items show a reason.
* [ ] Visitor: second page view returns `X-SHSO-Cache: HIT`; cart/checkout/my-account return `BYPASS`.
* [ ] Logged-in admin: no `HIT`, no facades/delay markup.
* [ ] Toolbar → Safe Mode on: transformations gone; off: back.
* [ ] `define( 'SHSO_SAFE_MODE', true );` → no cache, no transformations, dashboard shows the emergency banner.
* [ ] Undo Last Optimization restores the previous state; restore points work.
* [ ] Database cleanup creates a backup; restore brings rows back.
* [ ] Deactivate: `advanced-cache.php` (if ours) and `.htaccess` block removed, site identical to before. Reactivate: configuration back.
* [ ] Delete plugin: options, tables, cache and uploads directories removed.
* [ ] Multisite: network defaults and locks apply; per-site cache purging only affects that site.
* [ ] With WP Rocket / LiteSpeed Cache active: "Another optimization system is active", no duplicate page cache.
* [ ] Themes/builders: Twenty Twenty-Five, Astra (classic), Elementor, Divi, Bricks — no new console errors after optimization.
* [ ] PHP 8.1 and the newest PHP; WordPress minimum and latest.

## Static checks

```bash
composer lint        # php -l on all files
vendor/bin/phpcs     # WordPress Coding Standards (see phpcs.xml.dist)
node --check assets/js/*.js
```

## Continuous integration

`.github/workflows/ci.yml` runs on every pull request and on pushes to `main`:

* **PHPUnit** on PHP 8.1, 8.2, 8.3 and 8.4 (after `composer lint`).
* **PHPCS** with the WordPress Coding Standards. Errors fail the job; warnings
  are shown as annotations on the pull request.

The E2E suite needs a full WordPress site and is run locally (see above).
