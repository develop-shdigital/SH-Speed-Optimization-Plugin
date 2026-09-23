<?php
/**
 * Tests for sampled cache statistics.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\Delivery;
use SH\SpeedOptimizer\Cache\Stats;
use SH\SpeedOptimizer\Core\Filesystem;

final class StatsTest extends TestCase {

	/**
	 * Temporary cache root inside wp-content (so Filesystem may delete in it).
	 *
	 * @var string
	 */
	private string $root;

	protected function setUp(): void {
		$this->root = Filesystem::cache_root();
		( new Filesystem() )->cache_dir( 'stats' );
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
	}

	/**
	 * Remove this test's statistics files.
	 */
	private function cleanup(): void {
		foreach ( (array) glob( $this->root . 'stats/stats-test.example*' ) as $file ) {
			@unlink( (string) $file );
		}
	}

	public function test_record_and_read(): void {
		$now = gmmktime( 12, 0, 0, 6, 15, 2026 );
		$key = 'stats-test.example';

		$this->assertTrue( Delivery::record( $this->root, $key, 'hit', $now ) );
		$this->assertTrue( Delivery::record( $this->root, $key, 'hit', $now ) );
		$this->assertTrue( Delivery::record( $this->root, $key, 'miss', $now ) );
		$this->assertTrue( Delivery::record( $this->root, $key, 'bypass', $now - 86400 ) );
		$this->assertFalse( Delivery::record( $this->root, $key, 'bogus', $now ) );
		$this->assertFalse( Delivery::record( $this->root, '../escape', 'hit', $now ) );

		$days = Stats::read( $this->root, array( $key ), 7, $now );
		$this->assertCount( 7, $days );
		$this->assertSame( '2026-06-15', array_key_last( $days ) );
		$this->assertSame(
			array(
				'hit'    => 2,
				'miss'   => 1,
				'bypass' => 0,
			),
			$days['2026-06-15']
		);
		$this->assertSame( 1, $days['2026-06-14']['bypass'] );
		$this->assertSame( 0, $days['2026-06-10']['hit'] );
	}

	public function test_hit_rate_needs_enough_samples(): void {
		$few = Stats::summarize(
			array(
				'2026-06-15' => array(
					'hit'    => 30,
					'miss'   => 10,
					'bypass' => 100,
				),
			)
		);
		$this->assertNull( $few['hit_rate'], 'Fewer than 50 samples: no rate is invented.' );
		$this->assertTrue( $few['sampled'] );
		$this->assertSame( 40, $few['samples'] );

		$enough = Stats::summarize(
			array(
				'2026-06-14' => array(
					'hit'    => 30,
					'miss'   => 10,
					'bypass' => 0,
				),
				'2026-06-15' => array(
					'hit'    => 15,
					'miss'   => 5,
					'bypass' => 3,
				),
			)
		);
		$this->assertSame( 0.75, $enough['hit_rate'] );
		$this->assertSame( Delivery::SAMPLE_RATE, $enough['sample_rate'] );
	}

	public function test_prune_keeps_two_weeks(): void {
		$now = gmmktime( 12, 0, 0, 6, 30, 2026 );
		$key = 'stats-test.example';
		Delivery::record( $this->root, $key, 'hit', $now );
		Delivery::record( $this->root, $key, 'hit', $now - 20 * 86400 );

		$this->assertSame( 1, Stats::prune( new Filesystem(), $this->root, $now ) );
		$this->assertFileExists( $this->root . 'stats/' . $key . '-2026-06-30.json' );
	}
}
