# SH Speed Optimizer – E2E tests

Playwright tests that run against a real WordPress site with WooCommerce,
Elementor and Contact Form 7. The site runs on SQLite and PHP's built-in web
server, so you don't need MySQL, Apache, nginx or Docker.

## Quick start

```bash
# 1. Build (or rebuild) the site. This is idempotent: every run resets the DB and content.
bin/e2e-setup.sh                   # add --activate-plugin to also activate SH Speed Optimizer

# 2. Start the web server (8 workers, waits until / answers 200)
bin/e2e-server.sh start            # also: stop | restart | status

# 3. Run the tests
cd tests/e2e
npm install                        # once; @playwright/test is pinned to 1.56.1
npm test                           # all specs
npx playwright test specs/00-environment.spec.js   # just the environment smoke test
```

Log in at <http://127.0.0.1:8889/wp-admin/> as `admin` / `admin`.

## Where things live

Everything except this repo lives outside the repo, under `$SHSO_E2E_DIR/..`
(by default `/home/user/shso-e2e/`):

| Path | What |
| --- | --- |
| `wordpress/` | WordPress root (`SHSO_E2E_DIR`) |
| `wordpress/wp-content/plugins/sh-speed-optimizer` | symlink to this repo (`SHSO_PLUGIN_SRC`) |
| `wordpress/wp-content/database/.ht.sqlite` | SQLite database |
| `wordpress/wp-content/debug.log` | `WP_DEBUG_LOG`, cleared at the end of every setup |
| `wordpress/wp-content/mu-plugins/shso-e2e-environment.php` | environment tweaks (see below) |
| `bin/wp` | WP-CLI phar |
| `downloads/` | cached WordPress, plugin and WP-CLI downloads |
| `work/` | generated test images and the content script |
| `e2e-urls.json` | URLs of the test pages (`home, blog, post, contact, elementor, shop, product, cart, checkout, myaccount`) |
| `e2e-content.json` | IDs of the generated pages, posts, products, images and CF7 form |
| `router.php`, `server.pid`, `server.log` | built-in server router, pid and log |

## Environment variables

| Variable | Default | Used by |
| --- | --- | --- |
| `SHSO_E2E_DIR` | `/home/user/shso-e2e/wordpress` | setup, server, tests |
| `SHSO_E2E_PORT` | `8889` | setup (baked into `WP_HOME`), server |
| `SHSO_E2E_BASE_URL` | `http://127.0.0.1:8889` | tests (set it if you change the port) |
| `SHSO_PLUGIN_SRC` | `/home/user/SH-Speed-Optimization-Plugin` | setup |
| `SHSO_E2E_WP_CLI` | `$SHSO_E2E_DIR/../bin/wp` | tests |
| `PHP_CLI_SERVER_WORKERS` | `8` | server |
| `SHSO_E2E_WP_VERSION` | latest | setup (pin WordPress, e.g. `7.1.2`) |
| `SHSO_E2E_PLUGIN_VERSIONS` | latest | setup (e.g. `"woocommerce=11.1.2 elementor=4.3.1"`) |
| `SHSO_E2E_OFFLINE` | `0` | setup (`1` = only use `downloads/`) |

## What the site contains

- **Home** (static front page, core blocks): a full-width Cover block hero
  (`.shso-hero`, a 3000×2000 JPEG that WordPress stores as `hero-scaled.jpg`),
  headings, paragraphs, columns and buttons. Further down there's an inline
  image, a 6-image gallery (`.shso-gallery`), a YouTube Embed block, a Google
  Maps iframe (Custom HTML, `.shso-map`) and a Contact Form 7 form.
- **Blog** (posts page) with 5 posts. Each post has a featured image, and the
  last three have comments. `post` in `e2e-urls.json` is the newest post, which
  has 3 comments.
- **Contact** has a CF7 form.
- **Elementor Landing** is built from `_elementor_data`. It has three legacy
  sections with heading, image, text-editor, button and YouTube video widgets.
  It uses the theme's default page template.
- **WooCommerce**: Shop, Cart, Checkout and My Account pages (block versions),
  4 simple products (the mug is on sale) and 1 variable product (SH Hoodie,
  Small/Large). Prices are in CHF. The store address is in Zurich. There's a
  flat rate plus free shipping. Cash on delivery is enabled. Guest checkout and
  AJAX add-to-cart are on. "Coming soon" mode is off.
- **Navigation**: a `wp_navigation` post shows Home, Blog, Contact, Shop,
  Elementor Landing and Cart in the Twenty Twenty-Five header. There's also a
  classic "Primary Menu".
- `wp-config.php`: `WP_ENVIRONMENT_TYPE=production`, `WP_DEBUG` +
  `WP_DEBUG_LOG` on, `WP_DEBUG_DISPLAY` off, `WP_HOME`/`WP_SITEURL` =
  `http://127.0.0.1:8889`, auto-updates off.

The mu-plugin `shso-e2e-environment.php` does four things. It makes WordPress
use the host CA bundle for outbound HTTPS. It returns fixed YouTube oEmbed
markup, so the embed renders the same with or without network. It logs mails
to `wp-content/e2e-mail.log` instead of sending them. It stops the
WooCommerce/Elementor onboarding redirects.

## Helpers (`helpers/wp.js`)

```js
const { login, wpCli, urls, content, collectConsoleErrors, blockExternalRequests,
        THIRD_PARTY_NETWORK_NOISE } = require( '../helpers/wp' );

await login( page );                                  // admin/admin, ends on /wp-admin/
wpCli( [ 'plugin', 'activate', 'sh-speed-optimizer' ] ); // returns trimmed stdout, throws on failure
wpCli( 'option get blogname' );                       // string form is split shell-style
urls().home;                                          // from e2e-urls.json
content().products.simple[0];                         // from e2e-content.json
const errors = collectConsoleErrors( page, { ignore: THIRD_PARTY_NETWORK_NOISE } );
await blockExternalRequests( page );                  // abort all non-127.0.0.1 requests
```

## Notes and gotchas

- **Workers.** The plugin sends loopback HTTP requests to its own site. So do
  WP-Cron and Action Scheduler. That's why the server runs with
  `PHP_CLI_SERVER_WORKERS=8`. With a single worker those requests deadlock.
  The scripts also add `127.0.0.1` to `no_proxy`, so loopbacks never go
  through an HTTP proxy.
- **Shared state.** All tests share one site, so `workers: 1`. Specs that
  activate the plugin or change options should undo their changes, or re-run
  `bin/e2e-setup.sh`.
- **Third-party requests.** In the Claude Code container, outbound TLS is
  intercepted. Chromium doesn't trust the proxy CA, so third-party requests
  (YouTube, Google Fonts, Gravatar, emoji SVGs) fail fast with
  `ERR_CERT_AUTHORITY_INVALID`. Those failures show up as console errors, so
  filter them with `THIRD_PARTY_NETWORK_NOISE`. Where you have normal internet
  access they load for real, and pages with the YouTube embed then take much
  longer to fire `load`. `blockExternalRequests()` gives the same behaviour
  everywhere.
- **Existing baseline noise** (plugin inactive):
  - Cart and Checkout log `404 /cart/undefinedwc/store/v1/cart`. This comes
    from WooCommerce 11.1's interactivity store.
  - Elementor's "Optimized Image Loading" (on by default) duplicates the
    `loading="lazy"` / `fetchpriority="high"` attributes it adds to content
    images.
  - Elementor 4.3 logs a PHP 8.4 `Deprecated` notice
    (`Atomic_Global_Styles::get_cache_root_key()`) to `debug.log`.
- **Broken plugin builds.** If the plugin fatals while it loads, every WP-CLI
  call fails too. You can still recover with `--skip-plugins`:
  `wpCli( [ 'plugin', 'deactivate', 'sh-speed-optimizer', '--skip-plugins=sh-speed-optimizer' ] )`.
- **Checkout redirect.** `/checkout/` redirects to `/cart/` while the cart is
  empty. Add a product first.
- **No .htaccess.** The built-in server ignores `.htaccess`. `router.php` does
  the rewrites. It also returns 403 for dotfiles (the SQLite DB), `wp-config.php`
  and `*.log`.
- **Stale code.** opcache is on in the server with `revalidate_freq=0`, so
  plugin edits apply on the next request.
