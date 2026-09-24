<?php
/**
 * Page cache statistics (reading side).
 *
 * The delivery records one in Delivery::SAMPLE_RATE decisions per UTC day
 * into cache_root/stats/<site key>-YYYY-MM-DD.json. These are sampled counts
 * of real requests, never estimates: the dashboard shows them as samples and
 * shows "not enough data" below MIN_SAMPLES.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

use SH\SpeedOptimizer\Core\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Statistics reader.
 */
final class Stats {

	/**
	 * Minimum sampled cacheable requests (hit + miss) before a hit rate is reported.
	 */
	public const MIN_SAMPLES = 50;

	/**
	 * Days of statistics kept on disk.
	 */
	public const KEEP_DAYS = 14;

	/**
	 * Read daily counters for the last $days UTC days (oldest first).
	 *
	 * @param string   $root      Cache root (trailing slash).
	 * @param string[] $site_keys Site keys (one per allowed host).
	 * @param int      $days      Number of days.
	 * @param int      $now       Timestamp.
	 * @return array<string,array{hit:int,miss:int,bypass:int}>
	 */
	public static function read( string $root, array $site_keys, int $days, int $now ): array {
		$days = max( 1, min( self::KEEP_DAYS, $days ) );
		$out  = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS );
			$row  = array(
				'hit'    => 0,
				'miss'   => 0,
				'bypass' => 0,
			);
			foreach ( array_unique( $site_keys ) as $key ) {
				$file = $root . 'stats/' . $key . '-' . $date . '.json';
				if ( ! is_file( $file ) ) {
					continue;
				}
				$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local stats file.
				if ( is_array( $data ) ) {
					foreach ( array_keys( $row ) as $type ) {
						$row[ $type ] += max( 0, (int) ( $data[ $type ] ?? 0 ) );
					}
				}
			}
			$out[ $date ] = $row;
		}

		return $out;
	}

	/**
	 * Summarize daily counters.
	 *
	 * @param array<string,array{hit:int,miss:int,bypass:int}> $days Daily counters.
	 * @return array{days:array<string,array{hit:int,miss:int,bypass:int}>,hit_rate:float|null,sampled:bool,sample_rate:int,samples:int}
	 */
	public static function summarize( array $days ): array {
		$hits   = 0;
		$misses = 0;
		foreach ( $days as $row ) {
			$hits   += (int) ( $row['hit'] ?? 0 );
			$misses += (int) ( $row['miss'] ?? 0 );
		}
		$samples = $hits + $misses;

		return array(
			'days'        => $days,
			'hit_rate'    => $samples >= self::MIN_SAMPLES ? round( $hits / $samples, 4 ) : null,
			'sampled'     => true,
			'sample_rate' => Delivery::SAMPLE_RATE,
			'samples'     => $samples,
		);
	}

	/**
	 * Delete statistics files older than KEEP_DAYS.
	 *
	 * @param Filesystem $fs   Filesystem.
	 * @param string     $root Cache root (trailing slash).
	 * @param int        $now  Timestamp.
	 * @return int Deleted files.
	 */
	public static function prune( Filesystem $fs, string $root, int $now ): int {
		$dir = $root . 'stats/';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$cutoff  = gmdate( 'Y-m-d', $now - self::KEEP_DAYS * DAY_IN_SECONDS );
		$deleted = 0;
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_string( $name ) && preg_match( '/-(\d{4}-\d{2}-\d{2})\.json$/', $name, $m ) && $m[1] < $cutoff ) {
				$deleted += (int) $fs->delete( $dir . $name );
			}
		}
		return $deleted;
	}
}
