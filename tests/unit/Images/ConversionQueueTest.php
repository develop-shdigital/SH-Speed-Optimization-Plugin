<?php
/**
 * Tests for the WebP conversion queue and converter helpers.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Images;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Images\ConversionQueue;
use SH\SpeedOptimizer\Assets\Images\ImageConverter;
use SH\SpeedOptimizer\Assets\Images\ImageInspector;

final class ConversionQueueTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
	}

	private static function record( string $status, int $original = 1000, int $webp = 600 ): array {
		return array(
			'sizes'   => array(
				'a.jpg' => array(
					'bytes_original' => $original,
					'bytes_webp'     => ImageConverter::STATUS_CONVERTED === $status ? $webp : 0,
					'status'         => $status,
				),
			),
			'updated' => 1,
		);
	}

	public function test_walks_library_with_cursor_and_processes_uploads_first(): void {
		$library = array( 3, 7, 9, 12 );
		$queries = array();
		$queue   = new ConversionQueue(
			static function ( int $after, int $limit ) use ( $library, &$queries ) {
				$queries[] = $after;
				return array_slice( array_values( array_filter( $library, static fn( $id ) => $id > $after ) ), 0, $limit );
			}
		);
		$queue->start();
		$queue->enqueue( array( 50, 50, 51 ) );

		$seen    = array();
		$convert = static function ( int $id ) use ( &$seen ) {
			$seen[] = $id;
			return self::record( ImageConverter::STATUS_CONVERTED );
		};
		$none    = static fn( int $id ) => null;

		$this->assertSame( 5, $queue->run( $convert, $none, 5, 20.0 ) );
		$this->assertSame( array( 50, 51, 3, 7, 9 ), $seen );
		$this->assertSame( 9, $queue->state()['cursor'] );
		$this->assertTrue( $queue->has_work() );

		$this->assertSame( 1, $queue->run( $convert, $none, 5, 20.0 ) );
		$this->assertFalse( $queue->has_work() );
		$this->assertNotEmpty( $queue->state()['finished'] );

		$stats = $queue->state()['stats'];
		$this->assertSame( 6, $stats['attachments'] );
		$this->assertSame( 6, $stats['converted'] );
		$this->assertSame( 6000, $stats['bytes_original'] );
		$this->assertSame( 3600, $stats['bytes_webp'] );
		$this->assertSame( array( 0, 3, 7, 9, 12 ), $queries, 'The library is paged with an ID cursor.' );
	}

	public function test_reconversion_replaces_statistics_and_crash_is_skipped(): void {
		$queue = new ConversionQueue( static fn( int $after, int $limit ) => $after < 5 ? array( 5 ) : array() );
		$queue->start();

		$queue->run( static fn( int $id ) => self::record( ImageConverter::STATUS_LARGER ), static fn( int $id ) => null, 1 );
		$this->assertSame( 1, $queue->state()['stats']['skipped'] );

		// Regenerated metadata: the attachment is converted again and its old numbers are replaced.
		$queue->enqueue( array( 5 ) );
		$queue->run( static fn( int $id ) => self::record( ImageConverter::STATUS_CONVERTED ), static fn( int $id ) => self::record( ImageConverter::STATUS_LARGER ), 1 );
		$stats = $queue->state()['stats'];
		$this->assertSame( 0, $stats['skipped'] );
		$this->assertSame( 1, $stats['converted'] );
		$this->assertSame( 1, $stats['attachments'] );

		// A run that died while converting attachment 8 leaves "current" set: it is skipped, not retried forever.
		$state            = $queue->state();
		$state['current'] = 8;
		$state['pending'] = array( 8, 9 );
		$queue->save( $state );
		$seen = array();
		$queue->run(
			static function ( int $id ) use ( &$seen ) {
				$seen[] = $id;
				return self::record( ImageConverter::STATUS_CONVERTED );
			},
			static fn( int $id ) => null,
			5
		);
		$this->assertSame( array( 9 ), $seen );
		$this->assertSame( 1, $queue->state()['stats']['errors'] );
	}

	public function test_should_stop_guard_and_subtract(): void {
		$queue = new ConversionQueue( static fn( int $after, int $limit ) => array( $after + 1 ) );
		$queue->start();
		$this->assertSame( 0, $queue->run( static fn( int $id ) => array(), static fn( int $id ) => null, 5, 20.0, static fn() => true ) );

		$queue->run( static fn( int $id ) => self::record( ImageConverter::STATUS_CONVERTED ), static fn( int $id ) => null, 2 );
		$queue->subtract( self::record( ImageConverter::STATUS_CONVERTED ) );
		$stats = $queue->state()['stats'];
		$this->assertSame( 1, $stats['converted'] );
		$this->assertSame( 1, $stats['attachments'] );
	}

	public function test_converter_and_inspector_pure_helpers(): void {
		$files = ImageConverter::files_for(
			'/srv/uploads/2024/05/photo-scaled.jpg',
			array(
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
					'medium'    => array( 'file' => '../../evil-300x200.jpg' ),
					'svg'       => array( 'file' => 'icon.svg' ),
					'dup'       => array( 'file' => 'photo-150x150.jpg' ),
				),
			)
		);
		$this->assertSame( array( '/srv/uploads/2024/05/photo-scaled.jpg', '/srv/uploads/2024/05/photo-150x150.jpg', '/srv/uploads/2024/05/evil-300x200.jpg' ), $files );

		$totals = ImageConverter::totals( self::record( ImageConverter::STATUS_CONVERTED, 1000, 700 ) );
		$this->assertSame( 1, $totals['converted'] );
		$this->assertSame( 300, $totals['bytes_original'] - $totals['bytes_webp'] );

		$counts = ImageInspector::mime_counts(
			array(
				array( 'mime' => 'image/jpeg', 'total' => '10' ),
				array( 'mime' => 'image/png', 'total' => 3 ),
				array( 'mime' => 'image/svg+xml', 'total' => 1 ),
			)
		);
		$this->assertSame( 14, $counts['total'] );
		$this->assertSame( 10, $counts['jpeg'] );
		$this->assertSame( 1, $counts['other'] );

		$this->assertNull( ImageInspector::oversized_entry( 1, array( 'width' => 1200, 'height' => 800, 'filesize' => 200000 ) ) );
		$entry = ImageInspector::oversized_entry( 2, array( 'file' => '2024/big.jpg', 'width' => 4000, 'height' => 3000, 'filesize' => 5000000 ) );
		$this->assertSame( '2024/big.jpg', $entry['file'] );
		$this->assertNotNull( ImageInspector::oversized_entry( 3, array( 'width' => 800, 'height' => 600, 'filesize' => 2000000 ) ) );

		$top = ImageInspector::top( array_map( static fn( $i ) => array( 'id' => $i, 'file' => '', 'width' => 1, 'height' => 1, 'bytes' => $i ), range( 1, 15 ) ) );
		$this->assertCount( 10, $top );
		$this->assertSame( 15, $top[0]['id'] );
	}
}
