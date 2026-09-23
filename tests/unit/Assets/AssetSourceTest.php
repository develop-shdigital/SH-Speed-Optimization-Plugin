<?php
/**
 * Tests for URL → local file resolution and source attribution.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\AssetSource;

final class AssetSourceTest extends TestCase {

	protected function setUp(): void {
		AssetSource::reset();
		shso_test_asset_file( 'wp-content/plugins/demo-plugin/css/style.css', "a {\n  color: red;\n}\n" );
		shso_test_asset_file( 'wp-content/plugins/demo-plugin/js/app.min.js', 'var a=1;' );
		shso_test_asset_file( 'wp-content/plugins/demo-plugin/js/packed.js', str_repeat( 'var a=function(){return 1};', 60 ) );
		shso_test_asset_file( 'wp-content/plugins/demo-plugin/readme.txt', 'text' );
		shso_test_asset_file( 'wp-content/plugins/demo-plugin/evil.css.php', '<?php' );
		shso_test_asset_file( 'wp-content/plugins/single-file.js', 'var x = 1;' );
		shso_test_asset_file( 'wp-content/mu-plugins/tools/tools.js', 'var x = 1;' );
		shso_test_asset_file( 'wp-content/themes/twentytest/style.css', 'body { margin: 0 }' );
		shso_test_asset_file( 'wp-content/uploads/elementor/css/post-12.css', '.elementor-12 { color: red }' );
		shso_test_asset_file( 'wp-includes/js/jquery/jquery.js', 'var jQuery = 1;' );
		shso_test_asset_file( 'wp-content/cache/sh-speed-optimizer/assets/demo-style-abc.min.css', 'a{b:c}' );
		shso_test_asset_file( 'wp-content/other/file.js', 'var o;' );
	}

	protected function tearDown(): void {
		AssetSource::reset();
	}

	public function test_resolves_plugin_stylesheet_with_query_string(): void {
		$source = AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/css/style.css?ver=1.2.3' );

		$this->assertNotNull( $source );
		$this->assertSame( realpath( ABSPATH . 'wp-content/plugins/demo-plugin/css/style.css' ), $source['path'] );
		$this->assertSame( 'css', $source['ext'] );
		$this->assertSame( 'plugin', $source['source_type'] );
		$this->assertSame( 'demo-plugin', $source['source_slug'] );
		$this->assertSame( 'Demo Plugin', $source['source_name'] );
		$this->assertSame( 'https://example.test/wp-content/plugins/demo-plugin/css/style.css', $source['url'] );
		$this->assertFalse( $source['minified'] );
		$this->assertGreaterThan( 0, $source['bytes'] );
		$this->assertGreaterThan( 0, $source['mtime'] );
	}

	public function test_protocol_relative_and_root_relative_urls(): void {
		$this->assertNotNull( AssetSource::resolve( '//example.test/wp-content/plugins/demo-plugin/css/style.css' ) );
		$this->assertNotNull( AssetSource::resolve( '/wp-content/plugins/demo-plugin/css/style.css?ver=2#x' ) );
	}

	public function test_rejects_other_hosts_and_schemes(): void {
		$this->assertNull( AssetSource::resolve( 'https://evil.test/wp-content/plugins/demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( '//evil.test/wp-content/plugins/demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test.evil.test/wp-content/plugins/demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://user:pass@example.test/wp-content/plugins/demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test:8080/wp-content/plugins/demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( 'file:///etc/passwd.css' ) );
		$this->assertNull( AssetSource::resolve( 'data:text/css,a{}' ) );
		$this->assertNull( AssetSource::resolve( 'wp-content/plugins/demo-plugin/css/style.css' ), 'Document-relative URLs are ambiguous.' );
		$this->assertNull( AssetSource::resolve( '' ) );
	}

	public function test_rejects_traversal(): void {
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/../demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/%2e%2e/demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/css/..%2F..%2F..%2F..%2F..%2F..%2Fetc/x.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/./plugins/demo-plugin/css/style.css' ) );
		$this->assertNull( AssetSource::resolve( "https://example.test/wp-content/plugins/demo-plugin/css/style.css%00.js" ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin\\css\\style.css' ) );
	}

	public function test_rejects_non_asset_extensions_and_missing_files(): void {
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/readme.txt' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/evil.css.php' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/missing.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/css/' ) );
	}

	public function test_rejects_symlink_escaping_the_roots(): void {
		$outside = sys_get_temp_dir() . '/shso-outside-' . getmypid();
		@mkdir( $outside );
		file_put_contents( $outside . '/secret.css', 'secret{}' );
		$link = ABSPATH . 'wp-content/plugins/demo-plugin/linked.css';
		@unlink( $link );
		if ( ! @symlink( $outside . '/secret.css', $link ) ) {
			$this->markTestSkipped( 'Symlinks are not supported here.' );
		}
		$dir_link = ABSPATH . 'wp-content/plugins/linked-dir';
		@unlink( $dir_link );
		symlink( $outside, $dir_link );

		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/linked.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/linked-dir/secret.css' ) );

		@unlink( $link );
		@unlink( $dir_link );
	}

	public function test_symlink_inside_roots_is_allowed_but_extension_of_target_counts(): void {
		$target = ABSPATH . 'wp-content/plugins/demo-plugin/css/style.css';
		$link   = ABSPATH . 'wp-content/plugins/demo-plugin/alias.css';
		$bad    = ABSPATH . 'wp-content/plugins/demo-plugin/alias2.css';
		@unlink( $link );
		@unlink( $bad );
		if ( ! @symlink( $target, $link ) ) {
			$this->markTestSkipped( 'Symlinks are not supported here.' );
		}
		symlink( ABSPATH . 'wp-content/plugins/demo-plugin/evil.css.php', $bad );

		$this->assertNotNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/alias.css' ) );
		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/alias2.css' ) );

		@unlink( $link );
		@unlink( $bad );
	}

	public function test_rejects_files_over_the_size_limit(): void {
		$path = shso_test_asset_file( 'wp-content/plugins/demo-plugin/huge.js', '' );
		$fh   = fopen( $path, 'r+' );
		ftruncate( $fh, AssetSource::MAX_BYTES + 1 );
		fclose( $fh );

		$this->assertNull( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/huge.js' ) );
		unlink( $path );
	}

	public function test_minified_detection(): void {
		$this->assertTrue( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/js/app.min.js' )['minified'] );
		$this->assertTrue( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/js/packed.js' )['minified'], 'Long lines mean minified.' );
		$this->assertFalse( AssetSource::resolve( 'https://example.test/wp-content/plugins/demo-plugin/js/packed.js', false )['minified'], 'Without inspection only the name counts.' );
	}

	public function test_attribution(): void {
		$cases = array(
			'wp-content/plugins/single-file.js'                              => array( 'plugin', 'single-file' ),
			'wp-content/mu-plugins/tools/tools.js'                           => array( 'plugin', 'tools' ),
			'wp-content/themes/twentytest/style.css'                         => array( 'theme', 'twentytest' ),
			'wp-content/uploads/elementor/css/post-12.css'                   => array( 'uploads', 'elementor' ),
			'wp-includes/js/jquery/jquery.js'                                => array( 'core', 'wordpress' ),
			'wp-content/cache/sh-speed-optimizer/assets/demo-style-abc.min.css' => array( 'generated', 'sh-speed-optimizer' ),
			'wp-content/other/file.js'                                       => array( 'other', 'other' ),
		);
		foreach ( $cases as $relative => $expected ) {
			$source = AssetSource::resolve( 'https://example.test/' . $relative );
			$this->assertNotNull( $source, $relative );
			$this->assertSame( $expected[0], $source['source_type'], $relative );
			$this->assertSame( $expected[1], $source['source_slug'], $relative );
		}
	}

	public function test_is_local_url(): void {
		$this->assertTrue( AssetSource::is_local_url( 'https://example.test/anything.js' ) );
		$this->assertTrue( AssetSource::is_local_url( '/relative/path.css' ) );
		$this->assertTrue( AssetSource::is_local_url( '//example.test/x' ) );
		$this->assertFalse( AssetSource::is_local_url( 'https://www.googletagmanager.com/gtag/js?id=G-1' ) );
		$this->assertFalse( AssetSource::is_local_url( 'relative.js' ) );
	}

	public function test_resolve_in_with_custom_environment(): void {
		$env          = AssetSource::environment();
		$env['hosts'] = array( 'static.example.test' );
		$this->assertNull( AssetSource::resolve_in( 'https://example.test/wp-content/plugins/demo-plugin/css/style.css', $env ) );
		$this->assertNotNull( AssetSource::resolve_in( 'https://static.example.test/wp-content/plugins/demo-plugin/css/style.css', $env ) );
	}
}
