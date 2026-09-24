<?php
/**
 * Tests for cache storage: roundtrip, metadata, expiry, purging and GC.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\Config;
use SH\SpeedOptimizer\Cache\Delivery;
use SH\SpeedOptimizer\Cache\Storage;
use SH\SpeedOptimizer\Core\Filesystem;

final class StorageTest extends TestCase {

	private const HOST = 'storage-test.example';

	/**
	 * Filesystem.
	 *
	 * @var Filesystem
	 */
	private Filesystem $fs;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private Storage $storage;

	protected function setUp(): void {
		shso_test_reset();
		$this->fs      = new Filesystem();
		$this->storage = new Storage( $this->fs );
		$this->fs->delete_tree( $this->storage->pages_root() . self::HOST );
	}

	protected function tearDown(): void {
		$this->fs->delete_tree( $this->storage->pages_root() . self::HOST );
		shso_test_reset();
	}

	/**
	 * Decision for a URI.
	 *
	 * @param string              $uri    URI.
	 * @param array<string,mixed> $server Server overrides.
	 * @param array<string,mixed> $site   Site overrides.
	 * @return array<string,mixed>
	 */
	private function decision( string $uri, array $server = array(), array $site = array() ): array {
		$config = array(
			'sites' => array(
				'/' => Config::build_site(
					array_merge(
						array(
							'enabled'    => true,
							'hosts'      => array( self::HOST ),
							'keep_query' => array( 'lang' ),
							'gzip'       => true,
						),
						$site
					)
				),
			),
		);
		return Delivery::decide(
			array_merge(
				array(
					'HTTP_HOST'      => self::HOST,
					'REQUEST_URI'    => $uri,
					'REQUEST_METHOD' => 'GET',
					'HTTPS'          => 'on',
				),
				$server
			),
			array(),
			$config
		);
	}

	/**
	 * A complete HTML page.
	 *
	 * @param string $marker Marker text.
	 */
	private function page( string $marker = 'Hello' ): string {
		return '<!DOCTYPE html><html><head><title>' . $marker . '</title></head><body><p>' . str_repeat( $marker . ' ', 60 ) . "</p>\n<p>second line</p></body></html>";
	}

	public function test_store_and_read_roundtrip_with_metadata_header(): void {
		$decision = $this->decision( '/about/' );
		$html     = $this->page();
		$headers  = array( 'Link: <https://storage-test.example/wp-json/>; rel="https://api.w.org/"' );
		$now      = time();

		$file = $this->storage->store( $decision, $html, $headers, 'text/html; charset=UTF-8', 3600, true, $now );

		$this->assertIsString( $file );
		$this->assertStringEndsWith( self::HOST . '/about/index.html', $file );

		$raw        = (string) file_get_contents( $file );
		$first_line = strstr( $raw, "\n", true );
		$meta       = json_decode( (string) $first_line, true );
		$this->assertSame( Delivery::FORMAT_VERSION, $meta['v'] );
		$this->assertSame( $now, $meta['created'] );
		$this->assertSame( $now + 3600, $meta['expires'] );
		$this->assertSame( 'https://storage-test.example/about/', $meta['url'] );
		$this->assertSame( $headers, $meta['headers'] );
		$this->assertSame( strlen( $html ), $meta['size'] );

		$entry = $this->storage->read( $decision['dir'], $decision['file'] );
		$this->assertSame( $html, $entry['body'], 'Body (with newlines) survives the roundtrip.' );

		$this->assertFileExists( $file . '.gz' );
		$this->assertSame( $meta['gz'], filesize( $file . '.gz' ) );
		$this->assertSame( $html, gzdecode( (string) file_get_contents( $file . '.gz' ) ), 'The .gz sibling holds only the body.' );

		// The delivery finds the same entry.
		$found = Delivery::lookup( Filesystem::cache_root(), $decision, $now + 10 );
		$this->assertSame( $html, $found['body'] );
	}

	public function test_nonce_pages_expire_within_ten_hours(): void {
		$now  = time();
		$html = str_replace( '</body>', '<script>var wpApiSettings = {"nonce":"abc123"};</script></body>', $this->page() );
		$file = $this->storage->store( $this->decision( '/nonce/' ), $html, array(), 'text/html', 7 * 86400, false, $now );

		$meta = json_decode( (string) strstr( (string) file_get_contents( $file ), "\n", true ), true );
		$this->assertSame( $now + Storage::NONCE_TTL, $meta['expires'] );
		$this->assertSame( 0, $meta['gz'] );
		$this->assertFileDoesNotExist( $file . '.gz' );

		$this->assertTrue( Storage::has_nonce( '<input type="hidden" name="_wpnonce" value="x">' ) );
		$this->assertFalse( Storage::has_nonce( '<p>No nonce here</p>' ) );
	}

	public function test_variants_live_side_by_side(): void {
		$now   = time();
		$https = $this->decision( '/news/' );
		$http  = $this->decision( '/news/', array( 'HTTPS' => '' ) );
		$lang  = $this->decision( '/news/?lang=de' );

		$this->storage->store( $https, $this->page( 'secure' ), array(), 'text/html', 3600, false, $now );
		$this->storage->store( $http, $this->page( 'plain' ), array(), 'text/html', 3600, false, $now );
		$this->storage->store( $lang, $this->page( 'deutsch' ), array(), 'text/html', 3600, false, $now );

		$this->assertStringContainsString( 'secure', $this->storage->read( $https['dir'], $https['file'] )['body'] );
		$this->assertStringContainsString( 'plain', $this->storage->read( $http['dir'], $http['file'] )['body'] );
		$this->assertStringContainsString( 'deutsch', $this->storage->read( $lang['dir'], $lang['file'] )['body'] );
	}

	public function test_purge_page_removes_every_variant_and_pagination_only(): void {
		$now = time();
		foreach ( array( '/news/', '/news/?lang=de', '/news', '/news/page/2/', '/news/comment-page-1/', '/news/amp/', '/news/child/' ) as $uri ) {
			$this->storage->store( $this->decision( $uri ), $this->page(), array(), 'text/html', 3600, true, $now );
		}
		$this->storage->store( $this->decision( '/news/', array( 'HTTPS' => '' ) ), $this->page(), array(), 'text/html', 3600, true, $now );

		$deleted = $this->storage->purge_page( self::HOST . '/news/' );

		$this->assertGreaterThanOrEqual( 8, $deleted );
		$dir = $this->storage->pages_root() . self::HOST . '/news/';
		$this->assertSame( array( 'child' ), array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) ) );
		$this->assertNotNull( $this->storage->read( self::HOST . '/news/child/', 'index.html' ), 'Child pages are separate pages.' );
	}

	public function test_purge_tree_keeps_nested_sites(): void {
		$now = time();
		foreach ( array( '/', '/about/', '/blog2/', '/blog2/post/', '/deep/er/page/' ) as $uri ) {
			$this->storage->store( $this->decision( $uri ), $this->page(), array(), 'text/html', 3600, false, $now );
		}

		$deleted = $this->storage->purge_tree( self::HOST . '/', array( self::HOST . '/blog2/' ) );

		$this->assertSame( 3, $deleted );
		$this->assertNull( $this->storage->read( self::HOST . '/', 'index.html' ), 'The home page (index.html at the top level) is purged.' );
		$this->assertNull( $this->storage->read( self::HOST . '/about/', 'index.html' ) );
		$this->assertNotNull( $this->storage->read( self::HOST . '/blog2/', 'index.html' ) );
		$this->assertNotNull( $this->storage->read( self::HOST . '/blog2/post/', 'index.html' ) );
	}

	public function test_gc_deletes_expired_entries_and_orphans(): void {
		$now   = time();
		$old   = $this->decision( '/old/' );
		$fresh = $this->decision( '/fresh/' );

		$this->storage->store( $old, $this->page(), array(), 'text/html', 3600, true, $now - 7200 );
		$this->storage->store( $fresh, $this->page(), array(), 'text/html', 3600, true, $now );
		$orphan = $this->storage->pages_root() . self::HOST . '/orphan/index-http.html.gz';
		$this->fs->write( $orphan, 'x' );

		$deleted = $this->storage->gc( self::HOST . '/', 3600, $now );

		$this->assertSame( 3, $deleted, 'Expired page + its .gz + orphaned .gz.' );
		$this->assertNull( $this->storage->read( $old['dir'], $old['file'] ) );
		$this->assertNotNull( $this->storage->read( $fresh['dir'], $fresh['file'] ) );
		$this->assertFileDoesNotExist( $orphan );

		// Lowering the site lifespan expires older entries.
		$this->assertSame( 2, $this->storage->gc( self::HOST . '/', 60, $now + 120 ) );
	}

	public function test_usage_counts_pages(): void {
		$now = time();
		$this->storage->store( $this->decision( '/a/' ), $this->page(), array(), 'text/html', 3600, true, $now );
		$this->storage->store( $this->decision( '/b/' ), $this->page(), array(), 'text/html', 3600, true, $now );

		$usage = $this->storage->usage( self::HOST . '/' );
		$this->assertSame( 2, $usage['files'] );
		$this->assertGreaterThan( 2 * strlen( $this->page() ), $usage['bytes'] );
	}

	public function test_variant_explosion_is_capped(): void {
		$now = time();
		for ( $i = 0; $i < Storage::MAX_VARIANTS; $i++ ) {
			$this->assertNotNull( $this->storage->store( $this->decision( '/x/?lang=l' . $i ), $this->page(), array(), 'text/html', 3600, false, $now ) );
		}
		$this->assertNull( $this->storage->store( $this->decision( '/x/?lang=one-too-many' ), $this->page(), array(), 'text/html', 3600, false, $now ) );
		$this->assertNotNull( $this->storage->store( $this->decision( '/x/?lang=l1' ), $this->page(), array(), 'text/html', 3600, false, $now ), 'Existing variants may be refreshed.' );
	}

	public function test_bypass_decisions_are_never_stored(): void {
		$this->assertNull( $this->storage->store( $this->decision( '/wp-admin/' ), $this->page(), array(), 'text/html', 3600, false, time() ) );
	}
}
