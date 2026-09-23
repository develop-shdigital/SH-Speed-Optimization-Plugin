#!/usr/bin/env bash
#
# SH Speed Optimizer - reproducible E2E WordPress environment.
#
# Builds (or rebuilds) a WordPress site running on SQLite with WooCommerce,
# Elementor and Contact Form 7 plus realistic test content, and symlinks this
# plugin into it (inactive by default).
#
# Usage:
#   bin/e2e-setup.sh [--activate-plugin] [--help]
#
# Environment:
#   SHSO_E2E_DIR       WordPress root         (default /home/user/shso-e2e/wordpress)
#   SHSO_E2E_PORT      Port of the web server (default 8889)
#   SHSO_PLUGIN_SRC    Plugin source to link  (default /home/user/SH-Speed-Optimization-Plugin)
#   SHSO_E2E_WP_VERSION        Pin a WordPress version (default: latest)
#   SHSO_E2E_PLUGIN_VERSIONS   Pin plugin versions, e.g. "woocommerce=11.1.2 elementor=4.3.1"
#   SHSO_E2E_OFFLINE=1         Never hit the network, use cached downloads only
#   SHSO_E2E_CA_BUNDLE         CA bundle WordPress should use for outbound HTTPS
#                              (default: $SSL_CERT_FILE / $CURL_CA_BUNDLE if readable)
#
# The script is idempotent: every run resets the database, uploads and
# wp-content drop-ins and recreates all content, re-using cached downloads.
#
set -euo pipefail

ACTIVATE_PLUGIN=0
for arg in "$@"; do
	case "$arg" in
		--activate-plugin) ACTIVATE_PLUGIN=1 ;;
		-h|--help)
			sed -n '2,23p' "$0" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		*) echo "Unknown argument: $arg" >&2; exit 2 ;;
	esac
done

# ---------------------------------------------------------------------------
# Paths & settings
# ---------------------------------------------------------------------------
SHSO_E2E_DIR="${SHSO_E2E_DIR:-/home/user/shso-e2e/wordpress}"
SHSO_E2E_PORT="${SHSO_E2E_PORT:-8889}"
SHSO_PLUGIN_SRC="${SHSO_PLUGIN_SRC:-/home/user/SH-Speed-Optimization-Plugin}"
SHSO_E2E_OFFLINE="${SHSO_E2E_OFFLINE:-0}"

mkdir -p "$SHSO_E2E_DIR"
SHSO_E2E_DIR="$(cd "$SHSO_E2E_DIR" && pwd -P)"
E2E_ROOT="$(dirname "$SHSO_E2E_DIR")"
DL_DIR="$E2E_ROOT/downloads"
BIN_DIR="$E2E_ROOT/bin"
WORK_DIR="$E2E_ROOT/work"
WP_CLI="$BIN_DIR/wp"
URLS_JSON="$E2E_ROOT/e2e-urls.json"
SITE_URL="http://127.0.0.1:${SHSO_E2E_PORT}"
WPC="$SHSO_E2E_DIR/wp-content"
PLUGIN_SLUG="sh-speed-optimizer"
MANAGED_PLUGINS=(sqlite-database-integration woocommerce elementor contact-form-7)

mkdir -p "$DL_DIR" "$BIN_DIR" "$WORK_DIR"

# Loopback requests to the site must never go through an HTTP(S) proxy.
export no_proxy="127.0.0.1,localhost${no_proxy:+,$no_proxy}"
export NO_PROXY="127.0.0.1,localhost${NO_PROXY:+,$NO_PROXY}"

T0=$(date +%s)
log()  { printf '\033[1;34m[e2e-setup]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[e2e-setup] WARNING:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[e2e-setup] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Prerequisites
# ---------------------------------------------------------------------------
for cmd in php curl unzip; do
	command -v "$cmd" >/dev/null 2>&1 || die "'$cmd' is required but not installed."
done
for ext in pdo_sqlite gd curl mbstring zip; do
	php -r "exit(extension_loaded('$ext') ? 0 : 1);" || die "PHP extension '$ext' is required."
done

# WP-CLI wrapper. Always targets the E2E site.
WP_CMD=(php -d memory_limit=1024M -d display_errors=stderr "$WP_CLI" --allow-root --path="$SHSO_E2E_DIR" --url="$SITE_URL")
wp() { WP_CLI_ALLOW_ROOT=1 "${WP_CMD[@]}" "$@"; }

# download <url> <dest>: cached download with atomic rename.
download() {
	local url="$1" dest="$2"
	if [ -s "$dest" ]; then return 0; fi
	[ "$SHSO_E2E_OFFLINE" = "1" ] && return 1
	log "Downloading $url" >&2
	if curl -fsSL --retry 3 --connect-timeout 20 -o "$dest.part" "$url"; then
		mv "$dest.part" "$dest"
		return 0
	fi
	rm -f "$dest.part"
	return 1
}

# pinned_version <slug>: version pinned via SHSO_E2E_PLUGIN_VERSIONS (or empty).
pinned_version() {
	local slug="$1" pair
	for pair in ${SHSO_E2E_PLUGIN_VERSIONS:-}; do
		if [ "${pair%%=*}" = "$slug" ]; then echo "${pair#*=}"; return 0; fi
	done
	echo ""
}

# plugin_zip <slug>: prints the path of a cached zip for the latest (or pinned) version.
plugin_zip() {
	local slug="$1" ver link="" zip info
	ver="$(pinned_version "$slug")"
	if [ -n "$ver" ]; then
		link="https://downloads.wordpress.org/plugin/${slug}.${ver}.zip"
	elif [ "$SHSO_E2E_OFFLINE" != "1" ]; then
		info="$(curl -fsS --max-time 30 "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=${slug}&request%5Bfields%5D%5Bsections%5D=0&request%5Bfields%5D%5Bversions%5D=0&request%5Bfields%5D%5Bscreenshots%5D=0" 2>/dev/null || true)"
		if [ -n "$info" ]; then
			read -r ver link < <(printf '%s' "$info" | php -r '$d = json_decode(stream_get_contents(STDIN), true); if (!empty($d["version"])) { echo $d["version"], " ", $d["download_link"], "\n"; }') || true
		fi
	fi
	if [ -n "$ver" ] && [ -n "$link" ]; then
		zip="$DL_DIR/plugin-${slug}-${ver}.zip"
		if download "$link" "$zip"; then echo "$zip"; return 0; fi
	fi
	# Offline / API failure: newest cached zip.
	zip="$(ls -1t "$DL_DIR"/plugin-"${slug}"-*.zip 2>/dev/null | head -n1 || true)"
	[ -n "$zip" ] || die "Could not download plugin '$slug' and no cached copy exists."
	warn "Using cached $zip"
	echo "$zip"
}

# ---------------------------------------------------------------------------
# 1. WP-CLI
# ---------------------------------------------------------------------------
wp_cli_ok() { [ -s "$WP_CLI" ] && WP_CLI_ALLOW_ROOT=1 php "$WP_CLI" --allow-root --version >/dev/null 2>&1; }
if ! wp_cli_ok; then
	log "Installing WP-CLI into $WP_CLI"
	rm -f "$WP_CLI"
	WP_CLI_ALLOW_ROOT=1 php "$DL_DIR/wp-cli.phar" --allow-root --version >/dev/null 2>&1 || rm -f "$DL_DIR/wp-cli.phar"
	if download "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" "$DL_DIR/wp-cli.phar" \
		|| download "https://github.com/wp-cli/wp-cli/releases/latest/download/wp-cli.phar" "$DL_DIR/wp-cli.phar"; then
		cp "$DL_DIR/wp-cli.phar" "$WP_CLI"
		chmod +x "$WP_CLI"
	fi
	if ! wp_cli_ok; then
		# Fallback: build WP-CLI from Packagist with composer.
		command -v composer >/dev/null 2>&1 || die "Could not install WP-CLI (phar download failed, composer missing)."
		log "Phar download failed, installing wp-cli/wp-cli-bundle via composer"
		rm -rf "$BIN_DIR/wp-cli-bundle"
		COMPOSER_ALLOW_SUPERUSER=1 composer create-project --no-dev --no-interaction --quiet \
			wp-cli/wp-cli-bundle "$BIN_DIR/wp-cli-bundle"
		cat > "$WP_CLI" <<EOF
#!/usr/bin/env bash
exec php "$BIN_DIR/wp-cli-bundle/vendor/wp-cli/wp-cli/bin/wp" "\$@"
EOF
		chmod +x "$WP_CLI"
		wp_cli_ok || die "WP-CLI installation failed."
	fi
fi
log "$(WP_CLI_ALLOW_ROOT=1 php "$WP_CLI" --allow-root --version)"

# ---------------------------------------------------------------------------
# 2. WordPress core
# ---------------------------------------------------------------------------
WP_VERSION="${SHSO_E2E_WP_VERSION:-}"
if [ -z "$WP_VERSION" ] && [ "$SHSO_E2E_OFFLINE" != "1" ]; then
	WP_VERSION="$(curl -fsS --max-time 30 https://api.wordpress.org/core/version-check/1.7/ 2>/dev/null \
		| php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["offers"][0]["version"] ?? "";' || true)"
fi
CORE_ZIP=""
if [ -n "$WP_VERSION" ]; then
	CORE_ZIP="$DL_DIR/wordpress-${WP_VERSION}.zip"
	download "https://downloads.wordpress.org/release/wordpress-${WP_VERSION}.zip" "$CORE_ZIP" || CORE_ZIP=""
fi
if [ -z "$CORE_ZIP" ]; then
	CORE_ZIP="$(ls -1t "$DL_DIR"/wordpress-*.zip 2>/dev/null | head -n1 || true)"
	[ -n "$CORE_ZIP" ] || die "Could not download WordPress and no cached copy exists."
	warn "Using cached $CORE_ZIP"
fi

CORE_MARKER="$SHSO_E2E_DIR/.shso-e2e-core"
if [ "$(cat "$CORE_MARKER" 2>/dev/null || true)" != "$(basename "$CORE_ZIP")" ] || [ ! -f "$SHSO_E2E_DIR/wp-settings.php" ]; then
	log "Extracting $(basename "$CORE_ZIP") into $SHSO_E2E_DIR"
	TMP_CORE="$(mktemp -d "$WORK_DIR/core.XXXXXX")"
	unzip -q "$CORE_ZIP" -d "$TMP_CORE"
	rm -rf "$SHSO_E2E_DIR/wp-admin" "$SHSO_E2E_DIR/wp-includes"
	cp -a "$TMP_CORE/wordpress/." "$SHSO_E2E_DIR/"
	rm -rf "$TMP_CORE"
	basename "$CORE_ZIP" > "$CORE_MARKER"
fi

# ---------------------------------------------------------------------------
# 3. Reset site state (database, uploads, drop-ins, caches, stray plugins)
# ---------------------------------------------------------------------------
log "Resetting site state"
mkdir -p "$WPC/plugins" "$WPC/themes"
find "$WPC" -mindepth 1 -maxdepth 1 \
	! -name plugins ! -name themes ! -name languages ! -name index.php \
	-exec rm -rf -- {} +
KEEP_ARGS=(! -name index.php)
for slug in "${MANAGED_PLUGINS[@]}"; do KEEP_ARGS+=(! -name "$slug"); done
# Note: rm -rf on the plugin symlink removes the link only, never the target.
find "$WPC/plugins" -mindepth 1 -maxdepth 1 "${KEEP_ARGS[@]}" -exec rm -rf -- {} +
rm -f "$SHSO_E2E_DIR/.htaccess" "$SHSO_E2E_DIR/wp-config.php" "$SHSO_E2E_DIR/.user.ini"
mkdir -p "$WPC/mu-plugins" "$WPC/uploads" "$WPC/database"

# ---------------------------------------------------------------------------
# 4. Plugins from wordpress.org (extracted from cached zips)
# ---------------------------------------------------------------------------
for slug in "${MANAGED_PLUGINS[@]}"; do
	zip="$(plugin_zip "$slug")"
	marker="$WPC/plugins/$slug/.shso-e2e-zip"
	if [ "$(cat "$marker" 2>/dev/null || true)" != "$(basename "$zip")" ]; then
		log "Installing $(basename "$zip")"
		rm -rf "${WPC:?}/plugins/$slug"
		unzip -q "$zip" -d "$WPC/plugins/"
		basename "$zip" > "$marker"
	fi
done

# The plugin under test: symlinked, never copied.
[ -d "$SHSO_PLUGIN_SRC" ] || die "Plugin source $SHSO_PLUGIN_SRC does not exist."
ln -sfn "$(cd "$SHSO_PLUGIN_SRC" && pwd -P)" "$WPC/plugins/$PLUGIN_SLUG"

# ---------------------------------------------------------------------------
# 5. SQLite db.php drop-in (same replacements as sqlite_plugin_copy_db_file())
# ---------------------------------------------------------------------------
SQLITE_DIR="$WPC/plugins/sqlite-database-integration"
[ -f "$SQLITE_DIR/db.copy" ] || die "db.copy not found in $SQLITE_DIR"
SQLITE_DIR="$SQLITE_DIR" php -r '
	$dir = getenv("SQLITE_DIR");
	$contents = str_replace(
		array("{SQLITE_IMPLEMENTATION_FOLDER_PATH}", "{SQLITE_PLUGIN}"),
		array($dir, "sqlite-database-integration/load.php"),
		file_get_contents($dir . "/db.copy")
	);
	file_put_contents(dirname(dirname($dir)) . "/db.php", $contents);
'

# ---------------------------------------------------------------------------
# 6. wp-config.php
# ---------------------------------------------------------------------------
CA_BUNDLE=""
for candidate in "${SHSO_E2E_CA_BUNDLE:-}" "${SSL_CERT_FILE:-}" "${CURL_CA_BUNDLE:-}"; do
	if [ -n "$candidate" ] && [ -r "$candidate" ]; then CA_BUNDLE="$candidate"; break; fi
done

SALTS="$(php -r 'foreach (array("AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT") as $k) { printf("define( %s, %s );\n", var_export($k, true), var_export(bin2hex(random_bytes(32)), true)); }')"

cat > "$SHSO_E2E_DIR/wp-config.php" <<PHP
<?php
/**
 * wp-config.php for the SH Speed Optimizer E2E site.
 * Generated by bin/e2e-setup.sh - regenerated on every run, do not edit.
 */

// The database is SQLite (wp-content/db.php drop-in), these are dummies.
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'e2e' );
define( 'DB_PASSWORD', 'e2e' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );

${SALTS}

\$table_prefix = 'wp_';

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', '0' );

// The plugin treats production specially - we want production behaviour.
define( 'WP_ENVIRONMENT_TYPE', 'production' );

define( 'WP_HOME', '${SITE_URL}' );
define( 'WP_SITEURL', '${SITE_URL}' );

// Keep the environment reproducible.
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'FS_METHOD', 'direct' );
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );

// Read by wp-content/mu-plugins/shso-e2e-environment.php.
define( 'SHSO_E2E', true );
define( 'SHSO_E2E_CA_BUNDLE', '${CA_BUNDLE}' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP

# ---------------------------------------------------------------------------
# 7. Environment mu-plugin (site-only glue, not part of the plugin under test)
# ---------------------------------------------------------------------------
cat > "$WPC/mu-plugins/shso-e2e-environment.php" <<'PHP'
<?php
/**
 * Plugin Name: SH E2E environment glue
 * Description: Test-environment tweaks generated by bin/e2e-setup.sh (not part of SH Speed Optimizer).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Outbound HTTPS: use the host CA bundle (needed behind TLS-intercepting
 * proxies; WordPress otherwise only trusts its bundled certificates).
 */
if ( defined( 'SHSO_E2E_CA_BUNDLE' ) && SHSO_E2E_CA_BUNDLE && is_readable( SHSO_E2E_CA_BUNDLE ) ) {
	add_filter(
		'http_request_args',
		static function ( $args ) {
			$args['sslcertificates'] = SHSO_E2E_CA_BUNDLE;
			return $args;
		},
		1
	);
}

/*
 * Deterministic YouTube oEmbed: return the exact markup YouTube's oEmbed
 * endpoint produces, so pages render identically with or without network.
 */
add_filter(
	'pre_oembed_result',
	static function ( $result, $url, $args ) {
		if ( null !== $result || ! preg_match( '~(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
			return $result;
		}
		$width  = ! empty( $args['width'] ) ? min( 500, (int) $args['width'] ) : 500;
		$height = (int) round( $width * 9 / 16 );
		return sprintf(
			'<iframe title="Rick Astley - Never Gonna Give You Up (Official Video) (4K Remaster)" width="%1$d" height="%2$d" src="https://www.youtube.com/embed/%3$s?feature=oembed" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>',
			$width,
			$height,
			$m[1]
		);
	},
	10,
	3
);

/* No MTA in the container: log mails instead of calling sendmail. */
add_filter(
	'pre_wp_mail',
	static function ( $return, $atts ) {
		$line = sprintf( "[%s] to=%s subject=%s\n", gmdate( 'c' ), implode( ',', (array) $atts['to'] ), $atts['subject'] );
		@file_put_contents( WP_CONTENT_DIR . '/e2e-mail.log', $line, FILE_APPEND ); // phpcs:ignore
		return true;
	},
	10,
	2
);

/*
 * Elementor's WP-CLI logger echoes captured PHP notices to STDOUT on shutdown,
 * which corrupts machine-readable WP-CLI output. Log to the DB instead.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'elementor/loggers/register',
		static function ( $logger ) {
			$logger->set_default_logger( 'db' );
		},
		100
	);
}

/* No onboarding wizards / activation redirects. */
add_filter( 'woocommerce_enable_setup_wizard', '__return_false' );
add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );
add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );
PHP

# ---------------------------------------------------------------------------
# 8. Install WordPress
# ---------------------------------------------------------------------------
log "Installing WordPress $(php -r 'include $argv[1]; echo $wp_version;' "$SHSO_E2E_DIR/wp-includes/version.php") (SQLite)"
wp core install --url="$SITE_URL" --title="SH E2E Site" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
wp rewrite structure '/%postname%/' --quiet
wp option update timezone_string 'Europe/Zurich' --quiet
wp option update blogdescription 'End-to-end test site for SH Speed Optimizer' --quiet
wp option update admin_email_lifespan 4102444800 --quiet   # no "confirm admin email" screen
wp option update default_pingback_flag 0 --quiet
wp option update default_ping_status closed --quiet
# Remove sample content ("Hello world!", "Sample Page", privacy policy draft).
SAMPLE_IDS="$(wp post list --post_type=post,page --post_status=any --format=ids)"
if [ -n "$SAMPLE_IDS" ]; then
	# shellcheck disable=SC2086
	wp post delete $SAMPLE_IDS --force --quiet
fi

DEFAULT_THEME="$(wp eval 'echo WP_DEFAULT_THEME;')"
wp theme activate "$DEFAULT_THEME" --quiet
log "Active theme: $DEFAULT_THEME"

# ---------------------------------------------------------------------------
# 9. Plugins (the plugin under test stays inactive)
# ---------------------------------------------------------------------------
log "Activating sqlite-database-integration, woocommerce, elementor, contact-form-7"
wp plugin activate sqlite-database-integration woocommerce elementor contact-form-7 --quiet

log "Configuring WooCommerce / Elementor / Contact Form 7 options"
wp eval '
	$options = array(
		// WooCommerce: no "coming soon" mode, no onboarding, no nags.
		"woocommerce_coming_soon"                    => "no",
		"woocommerce_store_pages_only"               => "no",
		"woocommerce_onboarding_profile"             => array( "skipped" => true, "completed" => true ),
		"woocommerce_task_list_hidden"               => "yes",
		"woocommerce_extended_task_list_hidden"      => "yes",
		"woocommerce_task_list_complete"             => "yes",
		"woocommerce_task_list_welcome_modal_dismissed" => "yes",
		"woocommerce_show_marketplace_suggestions"   => "no",
		"woocommerce_allow_tracking"                 => "no",
		"woocommerce_admin_customize_store_completed" => "yes",
		// Store settings.
		"woocommerce_store_address"                  => "Bahnhofstrasse 1",
		"woocommerce_store_city"                     => "Zurich",
		"woocommerce_store_postcode"                 => "8001",
		"woocommerce_default_country"                => "CH:ZH",
		"woocommerce_allowed_countries"              => "all",
		"woocommerce_ship_to_countries"              => "",
		"woocommerce_default_customer_address"       => "base",
		"woocommerce_currency"                       => "CHF",
		"woocommerce_currency_pos"                   => "left_space",
		"woocommerce_price_thousand_sep"             => "'"'"'",
		"woocommerce_price_decimal_sep"              => ".",
		"woocommerce_price_num_decimals"             => "2",
		"woocommerce_weight_unit"                    => "kg",
		"woocommerce_dimension_unit"                 => "cm",
		"woocommerce_calc_taxes"                     => "no",
		"woocommerce_enable_guest_checkout"          => "yes",
		"woocommerce_enable_checkout_login_reminder" => "yes",
		"woocommerce_enable_ajax_add_to_cart"        => "yes",
		"woocommerce_cart_redirect_after_add"        => "no",
		"woocommerce_enable_reviews"                 => "yes",
		// Cash on delivery.
		"woocommerce_cod_settings"                   => array(
			"enabled"            => "yes",
			"title"              => "Cash on delivery",
			"description"        => "Pay with cash upon delivery.",
			"instructions"       => "Pay with cash upon delivery.",
			"enable_for_methods" => array(),
			"enable_for_virtual" => "yes",
		),
		// Elementor: no onboarding / tracking prompts.
		"elementor_onboarded"                        => "1",
		"elementor_tracker_notice"                   => "1",
		"elementor_allow_tracking"                   => "no",
	);
	foreach ( $options as $name => $value ) {
		update_option( $name, $value );
	}
	delete_transient( "_wc_activation_redirect" );
	delete_transient( "elementor_activation_redirect" );
'

# ---------------------------------------------------------------------------
# 10. Test images (PHP GD) + media library import
# ---------------------------------------------------------------------------
IMG_DIR="$WORK_DIR/images"
mkdir -p "$IMG_DIR"
cat > "$WORK_DIR/generate-images.php" <<'PHP'
<?php
// Generates deterministic, photo-like JPEGs (gradient + shapes + texture + label).
$out  = $argv[1];
$font = null;
foreach ( array( '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf', '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf' ) as $f ) {
	if ( is_readable( $f ) ) { $font = $f; break; }
}
$images = array(
	// name => [w, h, seed, label, top color, bottom color]
	'hero'      => array( 3000, 2000, 101, 'SH E2E HERO', array( 18, 52, 110 ), array( 240, 140, 60 ) ),
	'gallery-1' => array( 1200, 800, 201, 'Gallery 1', array( 30, 120, 90 ), array( 200, 230, 160 ) ),
	'gallery-2' => array( 1200, 800, 202, 'Gallery 2', array( 90, 30, 120 ), array( 230, 160, 200 ) ),
	'gallery-3' => array( 1200, 800, 203, 'Gallery 3', array( 120, 60, 20 ), array( 250, 220, 120 ) ),
	'gallery-4' => array( 1200, 800, 204, 'Gallery 4', array( 20, 80, 140 ), array( 160, 220, 250 ) ),
	'gallery-5' => array( 1200, 800, 205, 'Gallery 5', array( 60, 60, 60 ), array( 220, 220, 210 ) ),
	'gallery-6' => array( 1200, 800, 206, 'Gallery 6', array( 140, 20, 40 ), array( 250, 190, 150 ) ),
	'product-1' => array( 1000, 1000, 301, 'T-Shirt', array( 240, 240, 245 ), array( 180, 200, 230 ) ),
	'product-2' => array( 1000, 1000, 302, 'Mug', array( 245, 240, 230 ), array( 210, 180, 150 ) ),
	'product-3' => array( 1000, 1000, 303, 'Cap', array( 235, 245, 235 ), array( 150, 200, 160 ) ),
	'product-4' => array( 1000, 1000, 304, 'Poster', array( 250, 235, 235 ), array( 220, 150, 150 ) ),
	'product-5' => array( 1000, 1000, 305, 'Hoodie', array( 235, 235, 250 ), array( 140, 140, 200 ) ),
);
foreach ( $images as $name => list( $w, $h, $seed, $label, $c1, $c2 ) ) {
	$path = "$out/$name.jpg";
	if ( is_file( $path ) ) { continue; }
	mt_srand( $seed );
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		$t = $y / ( $h - 1 );
		$c = imagecolorallocate( $im, (int) ( $c1[0] + ( $c2[0] - $c1[0] ) * $t ), (int) ( $c1[1] + ( $c2[1] - $c1[1] ) * $t ), (int) ( $c1[2] + ( $c2[2] - $c1[2] ) * $t ) );
		imageline( $im, 0, $y, $w - 1, $y, $c );
	}
	imagealphablending( $im, true );
	// "Hills" silhouette for a landscape look.
	for ( $layer = 0; $layer < 3; $layer++ ) {
		$pts  = array( 0, $h );
		$base = $h * ( 0.55 + 0.12 * $layer );
		for ( $x = 0; $x <= $w; $x += (int) ( $w / 12 ) ) {
			$pts[] = $x;
			$pts[] = (int) ( $base + mt_rand( -1 * (int) ( $h * 0.08 ), (int) ( $h * 0.08 ) ) );
		}
		$pts[] = $w; $pts[] = $h;
		$col   = imagecolorallocatealpha( $im, 20 + 30 * $layer, 60 + 25 * $layer, 40 + 10 * $layer, 30 + 20 * $layer );
		imagefilledpolygon( $im, $pts, $col );
	}
	$n = (int) ( ( $w * $h ) / 25000 );
	for ( $i = 0; $i < $n; $i++ ) {
		$col = imagecolorallocatealpha( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 70, 115 ) );
		$d   = mt_rand( (int) ( $w / 50 ), (int) ( $w / 7 ) );
		imagefilledellipse( $im, mt_rand( 0, $w ), mt_rand( 0, $h ), $d, $d, $col );
	}
	if ( defined( 'IMG_FILTER_SCATTER' ) ) {
		imagefilter( $im, IMG_FILTER_SCATTER, 1, 3 );
	}
	$white  = imagecolorallocate( $im, 255, 255, 255 );
	$shadow = imagecolorallocatealpha( $im, 0, 0, 0, 60 );
	if ( $font ) {
		$size = (int) ( $h / 11 );
		$box  = imagettfbbox( $size, 0, $font, $label );
		$x    = (int) ( ( $w - ( $box[2] - $box[0] ) ) / 2 );
		$y    = (int) ( $h / 2 + $size / 2 );
		imagettftext( $im, $size, 0, $x + 4, $y + 4, $shadow, $font, $label );
		imagettftext( $im, $size, 0, $x, $y, $white, $font, $label );
		imagettftext( $im, (int) ( $size / 3 ), 0, $x, $y + (int) ( $size * 0.8 ), $white, $font, "{$w}x{$h}" );
	} else {
		imagestring( $im, 5, 20, 20, "$label {$w}x{$h}", $white );
	}
	imageinterlace( $im, false );
	imagejpeg( $im, $path, 90 );
	imagedestroy( $im );
	fwrite( STDERR, sprintf( "  generated %s (%dx%d, %d KB)\n", basename( $path ), $w, $h, filesize( $path ) / 1024 ) );
}
PHP
log "Generating test images in $IMG_DIR"
php -d memory_limit=512M "$WORK_DIR/generate-images.php" "$IMG_DIR"

IMAGE_NAMES=(hero gallery-1 gallery-2 gallery-3 gallery-4 gallery-5 gallery-6 product-1 product-2 product-3 product-4 product-5)
IMAGE_FILES=()
for n in "${IMAGE_NAMES[@]}"; do IMAGE_FILES+=("$IMG_DIR/$n.jpg"); done
log "Importing ${#IMAGE_FILES[@]} images into the media library (generating sub-sizes)"
mapfile -t IMAGE_IDS < <(wp media import "${IMAGE_FILES[@]}" --porcelain --user=admin | grep -E '^[0-9]+$')
[ "${#IMAGE_IDS[@]}" -eq "${#IMAGE_NAMES[@]}" ] || die "Media import returned ${#IMAGE_IDS[@]} IDs, expected ${#IMAGE_NAMES[@]}."

CONTEXT_JSON="$WORK_DIR/context.json"
{
	printf '{"urls_json":"%s","images":{' "$URLS_JSON"
	for i in "${!IMAGE_NAMES[@]}"; do
		[ "$i" -gt 0 ] && printf ','
		printf '"%s":%d' "${IMAGE_NAMES[$i]}" "${IMAGE_IDS[$i]}"
	done
	printf '}}\n'
} > "$CONTEXT_JSON"

# ---------------------------------------------------------------------------
# 11. Content (pages, posts, comments, CF7, Elementor, WooCommerce, navigation)
# ---------------------------------------------------------------------------
cat > "$WORK_DIR/content.php" <<'PHP'
<?php
/**
 * Creates the E2E content. Run with: wp eval-file content.php --user=admin
 * Context (image IDs, output path) is read from $SHSO_E2E_CONTEXT.
 */
$ctx = json_decode( file_get_contents( getenv( 'SHSO_E2E_CONTEXT' ) ), true );
$img = $ctx['images'];

$alts = array(
	'hero'      => 'Sunset over the hills - hero image',
	'gallery-1' => 'Green valley with morning light',
	'gallery-2' => 'Purple dusk above the lake',
	'gallery-3' => 'Golden fields in late summer',
	'gallery-4' => 'Blue mountains under a clear sky',
	'gallery-5' => 'Grey rocky landscape',
	'gallery-6' => 'Red evening sky over the ridge',
	'product-1' => 'SH Classic T-Shirt',
	'product-2' => 'SH Coffee Mug',
	'product-3' => 'SH Baseball Cap',
	'product-4' => 'SH Speed Poster',
	'product-5' => 'SH Hoodie',
);
foreach ( $alts as $name => $alt ) {
	update_post_meta( $img[ $name ], '_wp_attachment_image_alt', $alt );
	wp_update_post( array( 'ID' => $img[ $name ], 'post_title' => ucwords( str_replace( '-', ' ', $name ) ) ) );
}

function shso_json( $data ) {
	return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}
function shso_insert( array $args ) {
	$id = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id );
	}
	return $id;
}
function b_para( $html, $attrs = null ) {
	$a = $attrs ? ' ' . shso_json( $attrs ) : '';
	$class = '';
	if ( ! empty( $attrs['align'] ) ) {
		$class = ' class="has-text-align-' . $attrs['align'] . '"';
	}
	return "<!-- wp:paragraph$a -->\n<p$class>$html</p>\n<!-- /wp:paragraph -->\n\n";
}
function b_heading( $text, $level = 2, $align = '' ) {
	$attrs = array();
	if ( 2 !== $level ) { $attrs['level'] = $level; }
	if ( $align ) { $attrs['textAlign'] = $align; }
	$a     = $attrs ? ' ' . shso_json( $attrs ) : '';
	$class = 'wp-block-heading' . ( $align ? " has-text-align-$align" : '' );
	return "<!-- wp:heading$a -->\n<h$level class=\"$class\">$text</h$level>\n<!-- /wp:heading -->\n\n";
}
function b_image_tag( $id, $size ) {
	$url = wp_get_attachment_image_url( $id, $size );
	$alt = esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	return "<img src=\"$url\" alt=\"$alt\" class=\"wp-image-$id\"/>";
}
function b_image( $id, $size = 'large', $caption = '', $align = '' ) {
	$attrs = array( 'id' => $id, 'sizeSlug' => $size, 'linkDestination' => 'none' );
	if ( $align ) { $attrs['align'] = $align; }
	$cap   = $caption ? "<figcaption class=\"wp-element-caption\">$caption</figcaption>" : '';
	$cls   = 'wp-block-image' . ( $align ? " align$align" : '' ) . " size-$size";
	return '<!-- wp:image ' . shso_json( $attrs ) . " -->\n<figure class=\"$cls\">" . b_image_tag( $id, $size ) . "$cap</figure>\n<!-- /wp:image -->\n\n";
}
function b_list( array $items ) {
	$out = "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
	foreach ( $items as $item ) {
		$out .= "<!-- wp:list-item -->\n<li>$item</li>\n<!-- /wp:list-item -->";
	}
	return $out . "</ul>\n<!-- /wp:list -->\n\n";
}
function b_quote( $text, $cite ) {
	return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . b_para( $text ) . "<cite>$cite</cite></blockquote>\n<!-- /wp:quote -->\n\n";
}
function b_buttons( array $buttons ) {
	$out = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">";
	foreach ( $buttons as $label => $url ) {
		$out .= "<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"$url\">$label</a></div>\n<!-- /wp:button -->";
	}
	return $out . "</div>\n<!-- /wp:buttons -->\n\n";
}
function b_columns( array $columns ) {
	$out = "<!-- wp:columns -->\n<div class=\"wp-block-columns\">";
	foreach ( $columns as $inner ) {
		$out .= "<!-- wp:column -->\n<div class=\"wp-block-column\">$inner</div>\n<!-- /wp:column -->";
	}
	return $out . "</div>\n<!-- /wp:columns -->\n\n";
}
function b_group( $inner, $class = '' ) {
	$attrs = array( 'layout' => array( 'type' => 'constrained' ) );
	if ( $class ) { $attrs['className'] = $class; }
	return '<!-- wp:group ' . shso_json( $attrs ) . " -->\n<div class=\"wp-block-group" . ( $class ? " $class" : '' ) . "\">$inner</div>\n<!-- /wp:group -->\n\n";
}
function b_shortcode( $sc ) {
	return "<!-- wp:shortcode -->\n$sc\n<!-- /wp:shortcode -->\n\n";
}
function b_separator() {
	return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->\n\n";
}
function lorem( $i ) {
	$p = array(
		'Fast websites keep visitors engaged. Every additional second of loading time measurably reduces conversions, so performance is not a technical detail but a business decision.',
		'Images are usually the heaviest part of a page. Serving them in modern formats, in the right dimensions and only when they are about to enter the viewport saves bandwidth for everyone.',
		'Third-party embeds such as videos and maps are convenient, but each one pulls in dozens of requests. Loading them on interaction keeps the initial page lean and responsive.',
		'Render-blocking CSS and JavaScript delay the first paint. Inlining what is critical and deferring the rest lets the browser show meaningful content much earlier.',
		'Caching turns expensive page generation into a cheap file lookup. Combined with a CDN, pages can be delivered in milliseconds from a location close to the visitor.',
		'Core Web Vitals - Largest Contentful Paint, Interaction to Next Paint and Cumulative Layout Shift - summarise how a page feels to real users on real devices.',
		'Web fonts should be preloaded when they are used above the fold and should always declare a font-display strategy to avoid invisible text while loading.',
		'Database bloat from revisions, transients and orphaned metadata slows down the admin area as well as uncached front-end requests over time.',
	);
	return $p[ $i % count( $p ) ];
}

// ---------------------------------------------------------------- CF7 form
$forms = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
if ( $forms ) {
	$form = WPCF7_ContactForm::get_instance( $forms[0]->ID );
} else {
	$form = WPCF7_ContactForm::get_template( array( 'title' => 'Contact form 1' ) );
	$form->save();
}
$cf7_shortcode = $form->shortcode();

// ------------------------------------------------------------ WooCommerce
WC_Install::create_pages();
$shop_id      = wc_get_page_id( 'shop' );
$cart_id      = wc_get_page_id( 'cart' );
$checkout_id  = wc_get_page_id( 'checkout' );
$myaccount_id = wc_get_page_id( 'myaccount' );

$cat_apparel = wp_insert_term( 'Apparel', 'product_cat' );
$cat_merch   = wp_insert_term( 'Merchandise', 'product_cat' );
$cat_apparel = is_wp_error( $cat_apparel ) ? (int) $cat_apparel->get_error_data( 'term_exists' ) : (int) $cat_apparel['term_id'];
$cat_merch   = is_wp_error( $cat_merch ) ? (int) $cat_merch->get_error_data( 'term_exists' ) : (int) $cat_merch['term_id'];

$simple = array(
	array( 'SH Classic T-Shirt', 'SH-TSHIRT', '29.00', '', 'product-1', $cat_apparel, 'A soft organic cotton t-shirt with the SH logo.' ),
	array( 'SH Coffee Mug', 'SH-MUG', '15.00', '12.00', 'product-2', $cat_merch, 'A ceramic mug for your fastest coffee break.' ),
	array( 'SH Baseball Cap', 'SH-CAP', '24.00', '', 'product-3', $cat_apparel, 'Keeps the sun out while you optimize.' ),
	array( 'SH Speed Poster', 'SH-POSTER', '19.00', '', 'product-4', $cat_merch, 'A 50x70 cm print celebrating fast websites.' ),
);
$product_ids = array();
foreach ( $simple as $i => list( $name, $sku, $price, $sale, $image, $cat, $short ) ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_slug( sanitize_title( $name ) );
	$p->set_status( 'publish' );
	$p->set_sku( $sku );
	$p->set_regular_price( $price );
	if ( $sale ) { $p->set_sale_price( $sale ); }
	$p->set_short_description( $short );
	$p->set_description( '<p>' . $short . '</p><p>' . lorem( $i ) . '</p><p>' . lorem( $i + 3 ) . '</p>' );
	$p->set_image_id( $img[ $image ] );
	$p->set_gallery_image_ids( array( $img[ 'gallery-' . ( $i + 1 ) ] ) );
	$p->set_category_ids( array( $cat ) );
	$p->set_manage_stock( false );
	$p->set_stock_status( 'instock' );
	$p->set_weight( '0.3' );
	$p->set_menu_order( $i );
	$product_ids[] = $p->save();
}

// Variable product with two variations.
$size = new WC_Product_Attribute();
$size->set_name( 'Size' );
$size->set_options( array( 'Small', 'Large' ) );
$size->set_position( 0 );
$size->set_visible( true );
$size->set_variation( true );
$vp = new WC_Product_Variable();
$vp->set_name( 'SH Hoodie' );
$vp->set_slug( 'sh-hoodie' );
$vp->set_status( 'publish' );
$vp->set_sku( 'SH-HOODIE' );
$vp->set_short_description( 'A cosy hoodie, available in two sizes.' );
$vp->set_description( '<p>A cosy hoodie, available in two sizes.</p><p>' . lorem( 5 ) . '</p>' );
$vp->set_image_id( $img['product-5'] );
$vp->set_category_ids( array( $cat_apparel ) );
$vp->set_attributes( array( $size ) );
$vp->set_menu_order( 10 );
$variable_id = $vp->save();
foreach ( array( 'Small' => '49.00', 'Large' => '54.00' ) as $opt => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $variable_id );
	$v->set_attributes( array( 'size' => $opt ) );
	$v->set_regular_price( $price );
	$v->set_sku( 'SH-HOODIE-' . strtoupper( substr( $opt, 0, 1 ) ) );
	$v->set_manage_stock( false );
	$v->set_stock_status( 'instock' );
	$v->set_status( 'publish' );
	$v->save();
}
WC_Product_Variable::sync( $variable_id );
wc_delete_product_transients( $variable_id );

// Shipping: Switzerland flat rate + free shipping everywhere else.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Switzerland' );
$zone->set_zone_order( 1 );
$zone->add_location( 'CH', 'country' );
$zone->save();
$flat = $zone->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$flat}_settings", array( 'title' => 'Flat rate', 'tax_status' => 'none', 'cost' => '7.00' ) );
$free = $zone->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_free_shipping_{$free}_settings", array( 'title' => 'Free shipping', 'requires' => '', 'min_amount' => '0' ) );
$rest = new WC_Shipping_Zone( 0 );
$rest_free = $rest->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_free_shipping_{$rest_free}_settings", array( 'title' => 'Free shipping', 'requires' => '', 'min_amount' => '0' ) );
WC_Cache_Helper::get_transient_version( 'shipping', true );

// ------------------------------------------------------------------ Pages
$hero_id  = $img['hero'];
$hero_url = wp_get_attachment_image_url( $hero_id, 'full' );
$hero_alt = esc_attr( $alts['hero'] );

$cover_attrs = array(
	'url'           => $hero_url,
	'id'            => $hero_id,
	'dimRatio'      => 40,
	'overlayColor'  => 'contrast',
	'isUserOverlayColor' => true,
	'minHeight'     => 80,
	'minHeightUnit' => 'vh',
	'isDark'        => true,
	'sizeSlug'      => 'full',
	'align'         => 'full',
	'className'     => 'shso-hero',
);
$home  = '<!-- wp:cover ' . shso_json( $cover_attrs ) . " -->\n";
$home .= '<div class="wp-block-cover alignfull is-dark shso-hero" style="min-height:80vh">';
$home .= "<img class=\"wp-block-cover__image-background wp-image-$hero_id size-full\" alt=\"$hero_alt\" src=\"$hero_url\" data-object-fit=\"cover\"/>";
$home .= '<span aria-hidden="true" class="wp-block-cover__background has-contrast-background-color has-background-dim-40 has-background-dim"></span>';
$home .= '<div class="wp-block-cover__inner-container">';
$home .= b_heading( 'Websites that load in a blink', 1, 'center' );
$home .= b_para( 'The SH E2E Site is a realistic playground for testing page speed optimizations.', array( 'align' => 'center' ) );
$home .= "</div></div>\n<!-- /wp:cover -->\n\n";

$intro  = b_heading( 'Welcome to the SH E2E Site' );
$intro .= b_para( lorem( 0 ) . ' ' . lorem( 1 ) );
$intro .= b_para( lorem( 2 ) );
$intro .= b_buttons( array( 'Visit the shop' => get_permalink( $shop_id ), 'Read the blog' => home_url( '/blog/' ) ) );
$home  .= b_group( $intro, 'shso-intro' );

$home .= b_columns(
	array(
		b_heading( 'Images', 3 ) . b_para( lorem( 1 ) ),
		b_heading( 'Embeds', 3 ) . b_para( lorem( 2 ) ),
		b_heading( 'Scripts', 3 ) . b_para( lorem( 3 ) ),
	)
);
$home .= b_heading( 'Why performance matters' );
for ( $i = 3; $i < 8; $i++ ) {
	$home .= b_para( lorem( $i ) );
}
$home .= b_image( $img['gallery-1'], 'large', 'An inline content image below the fold.' );
$home .= b_list( array( 'Optimized images', 'Deferred JavaScript', 'Critical CSS', 'Lazy-loaded embeds', 'Page caching' ) );
$home .= b_quote( 'Speed is a feature. The fastest request is the one that never happens.', 'Performance proverb' );
$home .= b_separator();

// Gallery (below the fold).
$home .= b_heading( 'Gallery' );
$gallery_inner = '';
foreach ( array( 'gallery-1', 'gallery-2', 'gallery-3', 'gallery-4', 'gallery-5', 'gallery-6' ) as $g ) {
	$gallery_inner .= b_image( $img[ $g ], 'large' );
}
$home .= '<!-- wp:gallery ' . shso_json( array( 'columns' => 3, 'linkTo' => 'none', 'sizeSlug' => 'large', 'className' => 'shso-gallery' ) ) . " -->\n";
$home .= '<figure class="wp-block-gallery has-nested-images columns-3 is-cropped shso-gallery">' . $gallery_inner . "</figure>\n<!-- /wp:gallery -->\n\n";

for ( $i = 0; $i < 4; $i++ ) {
	$home .= b_para( lorem( $i + 2 ) );
}

// YouTube embed (far down).
$home .= b_heading( 'Watch our video' );
$yt    = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
$home .= '<!-- wp:embed ' . shso_json( array( 'url' => $yt, 'type' => 'video', 'providerNameSlug' => 'youtube', 'responsive' => true, 'className' => 'wp-embed-aspect-16-9 wp-has-aspect-ratio' ) ) . " -->\n";
$home .= "<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\n$yt\n</div></figure>\n<!-- /wp:embed -->\n\n";

for ( $i = 0; $i < 3; $i++ ) {
	$home .= b_para( lorem( $i + 5 ) );
}

// Google Maps (near the bottom).
$home .= b_heading( 'Find us' );
$home .= "<!-- wp:html -->\n<div class=\"shso-map\"><iframe src=\"https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2701.8!2d8.54!3d47.37!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNDfCsDIyJzEyLjAiTiA4wrAzMicyNC4wIkU!5e0!3m2!1sen!2sch!4v1600000000000\" width=\"600\" height=\"450\" style=\"border:0;\" allowfullscreen=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer-when-downgrade\" title=\"Google Maps\"></iframe></div>\n<!-- /wp:html -->\n\n";

// Contact form.
$home .= b_heading( 'Get in touch' );
$home .= b_para( 'Questions about speed? Send us a message.' );
$home .= b_shortcode( $cf7_shortcode );

$home_id = shso_insert( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_name' => 'home', 'post_content' => $home, 'menu_order' => 1 ) );
$blog_id = shso_insert( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog', 'post_name' => 'blog', 'post_content' => '', 'menu_order' => 2 ) );

$contact  = b_para( 'We would love to hear from you. Fill in the form below and we will get back to you within one business day.' );
$contact .= b_columns(
	array(
		b_heading( 'Office', 3 ) . b_para( 'SH Digital<br>Bahnhofstrasse 1<br>8001 Zurich' ),
		b_heading( 'Hours', 3 ) . b_para( 'Monday - Friday<br>08:00 - 17:00' ),
	)
);
$contact .= b_shortcode( $cf7_shortcode );
$contact_id = shso_insert( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact', 'post_name' => 'contact', 'post_content' => $contact, 'menu_order' => 3 ) );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );
update_option( 'page_for_posts', $blog_id );
update_option( 'posts_per_page', 10 );

// ------------------------------------------------------------------ Posts
$cat_news   = wp_create_category( 'News' );
$cat_guides = wp_create_category( 'Guides' );
$posts = array(
	array( 'Why Core Web Vitals matter for your business', 'gallery-1', $cat_news ),
	array( 'A practical guide to lazy loading images', 'gallery-2', $cat_guides ),
	array( 'Taming third-party scripts', 'gallery-3', $cat_guides ),
	array( 'Critical CSS explained', 'gallery-4', $cat_guides ),
	array( 'Our new performance roadmap', 'gallery-5', $cat_news ),
);
$post_ids = array();
foreach ( $posts as $i => list( $title, $image, $cat ) ) {
	$content  = b_para( lorem( $i ) );
	$content .= b_para( lorem( $i + 1 ) );
	$content .= b_heading( 'Key takeaways' );
	$content .= b_list( array( 'Measure first', 'Optimize the biggest wins', 'Automate and monitor' ) );
	$content .= b_image( $img[ 'gallery-' . ( ( $i + 1 ) % 6 + 1 ) ], 'large', 'Illustration for "' . $title . '"' );
	$content .= b_para( lorem( $i + 2 ) );
	$content .= b_para( lorem( $i + 3 ) );
	$pid = shso_insert(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => $title,
			'post_content'  => $content,
			'post_excerpt'  => lorem( $i ),
			'post_category' => array( $cat ),
			'tags_input'    => array( 'performance', 'wordpress' ),
			'post_date'     => wp_date( 'Y-m-d H:i:s', time() - ( 5 - $i ) * DAY_IN_SECONDS - HOUR_IN_SECONDS ),
		)
	);
	set_post_thumbnail( $pid, $img[ $image ] );
	$post_ids[] = $pid;
}
$commenters = array( array( 'Anna Muster', 'anna@example.test' ), array( 'Beat Beispiel', 'beat@example.test' ), array( 'Chiara Rossi', 'chiara@example.test' ) );
foreach ( array_slice( $post_ids, 2 ) as $n => $pid ) {
	for ( $c = 0; $c <= $n; $c++ ) {
		wp_insert_comment(
			array(
				'comment_post_ID'      => $pid,
				'comment_author'       => $commenters[ $c ][0],
				'comment_author_email' => $commenters[ $c ][1],
				'comment_content'      => 'Great article! ' . lorem( $c + $n ),
				'comment_approved'     => 1,
				'comment_date'         => wp_date( 'Y-m-d H:i:s', time() - ( 3 - $c ) * HOUR_IN_SECONDS ),
			)
		);
	}
}

// -------------------------------------------------------------- Elementor
$el_image_id = $img['gallery-4'];
$el_data     = array(
	array(
		'id'       => 'a1b2c3d',
		'elType'   => 'section',
		'isInner'  => false,
		'settings' => array( 'layout' => 'boxed', 'content_width' => array( 'unit' => 'px', 'size' => 1140, 'sizes' => array() ), 'css_classes' => 'shso-el-hero' ),
		'elements' => array(
			array(
				'id'       => 'b2c3d4e',
				'elType'   => 'column',
				'isInner'  => false,
				'settings' => array( '_column_size' => 100, '_inline_size' => null ),
				'elements' => array(
					array( 'id' => 'c3d4e5f', 'elType' => 'widget', 'widgetType' => 'heading', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'title' => 'Elementor Landing Page', 'header_size' => 'h1', 'align' => 'center' ) ),
					array( 'id' => 'd4e5f6a', 'elType' => 'widget', 'widgetType' => 'image', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'image' => array( 'url' => wp_get_attachment_url( $el_image_id ), 'id' => $el_image_id, 'size' => '', 'alt' => $alts['gallery-4'], 'source' => 'library' ), 'image_size' => 'large', 'align' => 'center' ) ),
					array( 'id' => 'e5f6a7b', 'elType' => 'widget', 'widgetType' => 'text-editor', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'editor' => '<p>' . lorem( 0 ) . '</p><p>' . lorem( 1 ) . '</p>' ) ),
					array( 'id' => 'f6a7b8c', 'elType' => 'widget', 'widgetType' => 'button', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'text' => 'Shop now', 'link' => array( 'url' => get_permalink( $shop_id ), 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ), 'align' => 'center', 'size' => 'md' ) ),
				),
			),
		),
	),
	array(
		'id'       => 'a7b8c9d',
		'elType'   => 'section',
		'isInner'  => false,
		'settings' => array( 'css_classes' => 'shso-el-content' ),
		'elements' => array(
			array(
				'id'       => 'b8c9d0e',
				'elType'   => 'column',
				'isInner'  => false,
				'settings' => array( '_column_size' => 50, '_inline_size' => null ),
				'elements' => array(
					array( 'id' => 'c9d0e1f', 'elType' => 'widget', 'widgetType' => 'heading', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'title' => 'Built with Elementor', 'header_size' => 'h2' ) ),
					array( 'id' => 'd0e1f2a', 'elType' => 'widget', 'widgetType' => 'text-editor', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'editor' => '<p>' . lorem( 2 ) . '</p><p>' . lorem( 3 ) . '</p><p>' . lorem( 4 ) . '</p>' ) ),
				),
			),
			array(
				'id'       => 'e1f2a3b',
				'elType'   => 'column',
				'isInner'  => false,
				'settings' => array( '_column_size' => 50, '_inline_size' => null ),
				'elements' => array(
					array( 'id' => 'f2a3b4c', 'elType' => 'widget', 'widgetType' => 'image', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'image' => array( 'url' => wp_get_attachment_url( $img['gallery-6'] ), 'id' => $img['gallery-6'], 'size' => '', 'alt' => $alts['gallery-6'], 'source' => 'library' ), 'image_size' => 'medium_large' ) ),
				),
			),
		),
	),
	array(
		'id'       => 'a3b4c5d',
		'elType'   => 'section',
		'isInner'  => false,
		'settings' => array( 'css_classes' => 'shso-el-video' ),
		'elements' => array(
			array(
				'id'       => 'b4c5d6e',
				'elType'   => 'column',
				'isInner'  => false,
				'settings' => array( '_column_size' => 100, '_inline_size' => null ),
				'elements' => array(
					array( 'id' => 'c5d6e7f', 'elType' => 'widget', 'widgetType' => 'heading', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'title' => 'See it in action', 'header_size' => 'h2', 'align' => 'center' ) ),
					array( 'id' => 'd6e7f8a', 'elType' => 'widget', 'widgetType' => 'video', 'isInner' => false, 'elements' => array(),
						'settings' => array( 'video_type' => 'youtube', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) ),
				),
			),
		),
	),
);
$elementor_id = shso_insert(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Elementor Landing',
		'post_name'    => 'elementor-landing',
		'post_content' => '<h1>Elementor Landing Page</h1><p>' . lorem( 0 ) . '</p>',
		'menu_order'   => 5,
	)
);
update_post_meta( $elementor_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $elementor_id, '_elementor_template_type', 'wp-page' );
update_post_meta( $elementor_id, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $elementor_id, '_wp_page_template', 'default' );
update_post_meta( $elementor_id, '_elementor_data', wp_slash( shso_json( $el_data ) ) );
update_post_meta( $elementor_id, '_elementor_page_settings', array() );

// ------------------------------------------------------------- Navigation
$nav_items = array(
	'Home'              => $home_id,
	'Blog'              => $blog_id,
	'Contact'           => $contact_id,
	'Shop'              => $shop_id,
	'Elementor Landing' => $elementor_id,
	'Cart'              => $cart_id,
);
// Classic menu (for completeness / classic themes).
$menu_id = wp_create_nav_menu( 'Primary Menu' );
if ( ! is_wp_error( $menu_id ) ) {
	foreach ( $nav_items as $label => $id ) {
		wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => $label, 'menu-item-object' => 'page', 'menu-item-object-id' => $id, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
	}
}
// Block theme: the header's Navigation block falls back to the most recent wp_navigation post.
$nav_blocks = '';
foreach ( $nav_items as $label => $id ) {
	$nav_blocks .= '<!-- wp:navigation-link ' . shso_json( array( 'label' => $label, 'type' => 'page', 'id' => $id, 'url' => get_permalink( $id ), 'kind' => 'post-type' ) ) . " /-->\n";
}
shso_insert( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Primary Navigation', 'post_content' => $nav_blocks ) );

// ------------------------------------------------------------------- URLs
flush_rewrite_rules( false );
$urls = array(
	'home'      => home_url( '/' ),
	'blog'      => get_permalink( $blog_id ),
	'post'      => get_permalink( $post_ids[ count( $post_ids ) - 1 ] ),
	'contact'   => get_permalink( $contact_id ),
	'elementor' => get_permalink( $elementor_id ),
	'shop'      => get_permalink( $shop_id ),
	'product'   => get_permalink( $product_ids[0] ),
	'cart'      => get_permalink( $cart_id ),
	'checkout'  => get_permalink( $checkout_id ),
	'myaccount' => get_permalink( $myaccount_id ),
);
file_put_contents( $ctx['urls_json'], json_encode( $urls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

// Extra metadata for test authors (IDs of the generated content).
$meta = array(
	'pages'    => array( 'home' => $home_id, 'blog' => $blog_id, 'contact' => $contact_id, 'elementor' => $elementor_id, 'shop' => $shop_id, 'cart' => $cart_id, 'checkout' => $checkout_id, 'myaccount' => $myaccount_id ),
	'posts'    => $post_ids,
	'products' => array( 'simple' => $product_ids, 'variable' => $variable_id ),
	'images'   => $img,
	'cf7_form' => $form->id(),
);
file_put_contents( dirname( $ctx['urls_json'] ) . '/e2e-content.json', json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
WP_CLI::success( 'Content created.' );
PHP

log "Creating content"
SHSO_E2E_CONTEXT="$CONTEXT_JSON" wp eval-file "$WORK_DIR/content.php" --user=admin

# ---------------------------------------------------------------------------
# 12. Housekeeping: rewrite rules, Elementor CSS, background queues
# ---------------------------------------------------------------------------
log "Flushing rewrite rules, Elementor CSS and background queues"
wp rewrite flush --quiet
wp elementor flush-css >/dev/null 2>&1 || warn "wp elementor flush-css failed"
# Drain queued Action Scheduler jobs and due cron events now so that they do
# not fire (via loopback) in the middle of the first test run.
timeout 300 env WP_CLI_ALLOW_ROOT=1 "${WP_CMD[@]}" action-scheduler run --batch-size=50 --batches=20 --quiet >/dev/null 2>&1 \
	|| warn "Action Scheduler run did not finish cleanly (non-fatal)"
timeout 300 env WP_CLI_ALLOW_ROOT=1 "${WP_CMD[@]}" cron event run --due-now --quiet >/dev/null 2>&1 \
	|| warn "Running due cron events did not finish cleanly (non-fatal)"
wp cache flush --quiet >/dev/null 2>&1 || true
# Start with an empty debug.log so tests can assert on new notices.
: > "$WPC/debug.log"

# ---------------------------------------------------------------------------
# 13. Optionally activate the plugin under test
# ---------------------------------------------------------------------------
if [ "$ACTIVATE_PLUGIN" = "1" ]; then
	if [ -f "$WPC/plugins/$PLUGIN_SLUG/$PLUGIN_SLUG.php" ]; then
		log "Activating $PLUGIN_SLUG"
		wp plugin activate "$PLUGIN_SLUG" \
			|| die "Activating $PLUGIN_SLUG failed (see above). The site itself is ready; recover with: php $WP_CLI --allow-root --path=$SHSO_E2E_DIR plugin deactivate $PLUGIN_SLUG --skip-plugins=$PLUGIN_SLUG"
	else
		die "--activate-plugin: $SHSO_PLUGIN_SRC/$PLUGIN_SLUG.php does not exist (yet)."
	fi
fi

# ---------------------------------------------------------------------------
# 14. Summary + optional live verification
# ---------------------------------------------------------------------------
versions="$(wp eval '
	echo "WordPress " . get_bloginfo( "version" );
	echo " | WooCommerce " . WC()->version;
	echo " | Elementor " . ELEMENTOR_VERSION;
	echo " | Contact Form 7 " . WPCF7_VERSION;
	echo " | SQLite integration " . SQLITE_DRIVER_VERSION;
	echo " | theme " . get_stylesheet();
')"

VERIFY_STATUS="skipped (server not running - start it with bin/e2e-server.sh start)"
if curl -fsS -o /dev/null --max-time 20 "$SITE_URL/" 2>/dev/null; then
	log "Server is running - verifying pages"
	fail=0
	check() { # <key> <expected HTTP status> <needle>...
		local key="$1" expect="$2"; shift 2
		local url body code
		url="$(php -r '$u = json_decode(file_get_contents($argv[1]), true); echo $u[$argv[2]];' "$URLS_JSON" "$key")"
		body="$(curl -sS --max-time 60 -w '\n%{http_code}' "$url" || true)"
		code="${body##*$'\n'}"
		if [ "$code" != "$expect" ]; then warn "$key ($url) returned HTTP $code, expected $expect"; fail=1; return; fi
		for needle in "$@"; do
			if ! grep -qF -- "$needle" <<<"$body"; then warn "$key ($url) lacks '$needle'"; fail=1; fi
		done
	}
	check home 200 'shso-hero' 'youtube.com/embed/dQw4w9WgXcQ' 'google.com/maps/embed' 'wpcf7-form' 'shso-gallery' \
		"href=\"$SITE_URL/elementor-landing/\"" "href=\"$SITE_URL/shop/\"" "href=\"$SITE_URL/cart/\""
	check blog 200 'Our new performance roadmap' 'wp-post-image'
	check post 200 'wp-post-image' 'wp-block-comment-template'
	check contact 200 'wpcf7-form'
	check elementor 200 'elementor-widget-heading' 'elementor-widget-image' 'elementor-widget-button' 'elementor-widget-video' 'elementor-frontend'
	check shop 200 'SH Classic T-Shirt' 'SH Hoodie' 'add_to_cart_button'
	check product 200 'SH Classic T-Shirt' 'single_add_to_cart_button'
	check cart 200 'wp-block-woocommerce-cart'
	check checkout 302   # redirects to the cart while the cart is empty
	check myaccount 200 'woocommerce'
	if [ "$fail" = "0" ]; then VERIFY_STATUS="all pages OK"; else VERIFY_STATUS="FAILED (see warnings above)"; fi
fi

echo
log "Done in $(( $(date +%s) - T0 ))s"
cat <<EOF

  Site:        $SITE_URL   (admin / admin, $SITE_URL/wp-admin/)
  WordPress:   $SHSO_E2E_DIR
  Versions:    $versions
  Plugin:      $WPC/plugins/$PLUGIN_SLUG -> $(readlink "$WPC/plugins/$PLUGIN_SLUG") ($( [ "$ACTIVATE_PLUGIN" = "1" ] && echo active || echo inactive))
  WP-CLI:      $WP_CLI  (e.g. php $WP_CLI --allow-root --path=$SHSO_E2E_DIR plugin list)
  URLs JSON:   $URLS_JSON
  Content IDs: $E2E_ROOT/e2e-content.json
  Debug log:   $WPC/debug.log
  Verify:      $VERIFY_STATUS

  Test pages:
EOF
php -r '$u = json_decode(file_get_contents($argv[1]), true); foreach ($u as $k => $v) { printf("    %-10s %s\n", $k, $v); }' "$URLS_JSON"
echo
[ "${fail:-0}" = "0" ] || exit 1
