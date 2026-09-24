<?php
/**
 * Tests for the advanced-cache.php drop-in and the WP_CACHE constant helpers.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\Dropin;

final class DropinTest extends TestCase {

	private const WP_CONFIG = "<?php\n/**\n * The base configuration for WordPress.\n */\ndefine( 'DB_NAME', 'wp' );\n\$table_prefix = 'wp_';\n\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n\nrequire_once ABSPATH . 'wp-settings.php';\n";

	/**
	 * Whether the test created wp-config.php (and must remove it).
	 *
	 * @var bool
	 */
	private bool $created_config = false;

	protected function setUp(): void {
		shso_test_reset();
		@unlink( Dropin::path() );
	}

	protected function tearDown(): void {
		@unlink( Dropin::path() );
		if ( $this->created_config ) {
			@unlink( ABSPATH . 'wp-config.php' );
		}
		shso_test_reset();
	}

	public function test_template_contents(): void {
		$code = Dropin::template( '/srv/www/wp-content/plugins/sh-speed-optimizer/includes/Cache/Delivery.php', WP_CONTENT_DIR . '/cache/sh-speed-optimizer/' );

		$this->assertTrue( Dropin::is_valid_php( $code ), 'The generated drop-in parses.' );
		$this->assertStringStartsWith( '<?php', $code );
		$this->assertStringContainsString( Dropin::SIGNATURE, $code );
		$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $code );
		$this->assertStringContainsString( "defined( 'SHSO_SAFE_MODE' ) && SHSO_SAFE_MODE", $code );
		$this->assertStringContainsString( "defined( 'SHSO_DISABLE_CACHE' ) && SHSO_DISABLE_CACHE", $code );
		$this->assertStringContainsString( "\$shso_delivery_file = '/srv/www/wp-content/plugins/sh-speed-optimizer/includes/Cache/Delivery.php';", $code );
		$this->assertStringContainsString( 'if ( is_readable( $shso_delivery_file ) ) {', $code, 'Fails open when the plugin was deleted.' );
		$this->assertStringContainsString( "\\SH\\SpeedOptimizer\\Cache\\Delivery::serve( WP_CONTENT_DIR . '/cache/sh-speed-optimizer/', 'dropin' );", $code );
		$this->assertStringContainsString( 'catch ( \\Throwable', $code );
		$this->assertStringContainsString( "version_compare( PHP_VERSION, '8.1', '<' )", $code );
		$this->assertSame( Dropin::OWNER_SELF, Dropin::detect_owner( $code ) );

		// A cache root outside wp-content is embedded literally.
		$this->assertStringContainsString( "serve( '/var/cache/shso/', 'dropin' )", Dropin::template( '/x/Delivery.php', '/var/cache/shso' ) );
	}

	public function test_owner_detection(): void {
		$samples = array(
			'WP Rocket'            => "<?php\ndefined( 'ABSPATH' ) || exit;\ndefine( 'WP_ROCKET_ADVANCED_CACHE', true );",
			'W3 Total Cache'       => "<?php\n/** W3 Total Cache advanced cache module */",
			'WP Super Cache'       => "<?php\n# WP SUPER CACHE 1.2\n\$wp_cache_phase1 = 1;",
			'LiteSpeed Cache'      => "<?php\n// LiteSpeed Cache advanced-cache",
			'WP Fastest Cache'     => "<?php\n// WP Fastest Cache",
			'Cache Enabler'        => "<?php\n/** Cache Enabler advanced cache */",
			'Breeze'               => "<?php\n/** Breeze advanced cache */",
			'SiteGround Optimizer' => "<?php\n// SG Optimizer",
			'Hummingbird'          => "<?php\n// Hummingbird page cache",
			'Comet Cache'          => "<?php\n// Comet Cache",
			'Swift Performance'    => "<?php\n// Swift Performance",
			'FlyingPress'          => "<?php\n// FlyingPress",
			'NitroPack'            => "<?php\n// NitroPack",
			'WP-Optimize'          => "<?php\n// WP-Optimize page cache",
			'unknown'              => "<?php\n// some host specific cache",
		);
		foreach ( $samples as $owner => $contents ) {
			$this->assertSame( $owner, Dropin::detect_owner( $contents ), $owner );
		}
	}

	public function test_install_and_uninstall(): void {
		$this->assertNull( Dropin::owner() );
		$this->assertTrue( Dropin::uninstall(), 'Nothing to remove.' );

		$this->assertTrue( Dropin::install() );
		$this->assertFileExists( Dropin::path() );
		$this->assertTrue( Dropin::is_ours() );
		$this->assertTrue( Dropin::install(), 'Reinstalling is idempotent.' );

		$this->assertTrue( Dropin::uninstall() );
		$this->assertFileDoesNotExist( Dropin::path() );
	}

	public function test_foreign_dropin_is_never_touched(): void {
		$foreign = "<?php\n// WP Rocket\ndefine( 'WP_ROCKET_ADVANCED_CACHE', true );\n";
		file_put_contents( Dropin::path(), $foreign );

		$this->assertSame( 'WP Rocket', Dropin::owner() );
		$this->assertFalse( Dropin::is_ours() );

		$result = Dropin::install();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'shso_dropin_foreign', $result->get_error_code() );
		$this->assertFalse( Dropin::uninstall() );
		$this->assertSame( $foreign, file_get_contents( Dropin::path() ) );
	}

	public function test_insert_and_remove_wp_cache_line(): void {
		$error   = null;
		$updated = Dropin::insert_wp_cache_line( self::WP_CONFIG, $error );

		$this->assertSame( '', $error );
		$this->assertIsString( $updated );
		$this->assertStringStartsWith( "<?php\ndefine( 'WP_CACHE', true ); // " . Dropin::WP_CACHE_MARKER . "\n/**", $updated );
		$this->assertTrue( Dropin::is_valid_php( $updated ) );
		$this->assertStringContainsString( 'wp-settings.php', $updated );

		$this->assertSame( self::WP_CONFIG, Dropin::remove_wp_cache_line( $updated ), 'Removal restores the original exactly.' );
		$this->assertNull( Dropin::remove_wp_cache_line( self::WP_CONFIG ), 'Nothing of ours to remove.' );

		// Refuse when WP_CACHE is mentioned anywhere (even commented out or false).
		foreach ( array( "define( 'WP_CACHE', false );", "// define('WP_CACHE', true);", 'define("WP_CACHE",true);' ) as $line ) {
			$this->assertNull( Dropin::insert_wp_cache_line( str_replace( "\$table_prefix", $line . "\n\$table_prefix", self::WP_CONFIG ), $error ) );
			$this->assertSame( 'defined', $error );
		}

		// Unusual structure.
		$this->assertNull( Dropin::insert_wp_cache_line( "<?php\nrequire __DIR__ . '/config/application.php';\n", $error ) );
		$this->assertSame( 'structure', $error );

		// Opening tag followed by code on the same line.
		$inline = Dropin::insert_wp_cache_line( "<?php define( 'DB_NAME', 'wp' ); require_once ABSPATH . 'wp-settings.php';\n", $error );
		$this->assertTrue( Dropin::is_valid_php( (string) $inline ) );
		$this->assertStringContainsString( "\ndefine( 'WP_CACHE', true );", (string) $inline );

		// Windows line endings.
		$crlf = str_replace( "\n", "\r\n", self::WP_CONFIG );
		$this->assertTrue( Dropin::is_valid_php( (string) Dropin::insert_wp_cache_line( $crlf, $error ) ) );
	}

	public function test_invalid_php_is_detected(): void {
		$this->assertTrue( Dropin::is_valid_php( "<?php\n\$a = 1;\n" ) );
		$this->assertFalse( Dropin::is_valid_php( "<?php\n\$a = ;\n" ) );
	}

	public function test_enable_and_disable_wp_cache_constant(): void {
		$path = ABSPATH . 'wp-config.php';
		if ( file_exists( $path ) ) {
			$this->markTestSkipped( 'A wp-config.php already exists in the test ABSPATH.' );
		}
		file_put_contents( $path, self::WP_CONFIG );
		chmod( $path, 0640 );
		$this->created_config = true;

		$this->assertSame( $path, Dropin::wp_config_path() );
		$this->assertTrue( Dropin::enable_wp_cache_constant() );
		clearstatcache();
		$this->assertSame( 0640, fileperms( $path ) & 0777, 'Permissions of wp-config.php are kept.' );
		$this->assertSame( array(), glob( ABSPATH . 'wp-config-shso-*.php' ), 'No temporary file is left behind.' );

		$contents = (string) file_get_contents( $path );
		$this->assertStringContainsString( "define( 'WP_CACHE', true ); // " . Dropin::WP_CACHE_MARKER, $contents );
		$record = get_option( Dropin::BACKUP_OPTION );
		$this->assertIsArray( $record );
		$this->assertSame( hash( 'sha256', self::WP_CONFIG ), $record['sha256'], 'Only a fingerprint of the original is recorded.' );
		$this->assertStringNotContainsString( 'DB_PASSWORD', (string) wp_json_encode( $record ), 'No copy of wp-config.php (credentials) is stored.' );

		$again = Dropin::enable_wp_cache_constant();
		$this->assertInstanceOf( \WP_Error::class, $again, 'Refuses when WP_CACHE is already defined in the file.' );
		$this->assertSame( $contents, file_get_contents( $path ) );

		$this->assertTrue( Dropin::disable_wp_cache_constant() );
		$this->assertSame( self::WP_CONFIG, file_get_contents( $path ) );
		$this->assertTrue( Dropin::disable_wp_cache_constant(), 'Nothing to remove is fine.' );
	}
}
