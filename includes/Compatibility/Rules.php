<?php
/**
 * Aggregated compatibility rules.
 *
 * Built from the active compatibility profiles plus the user's own exclusions.
 * Optimizations must consult these rules before transforming anything.
 *
 * Pattern matching: plain entries are case-insensitive substrings matched against
 * a script/style handle, its URL, or (for inline code) the code itself.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Rules value object.
 */
final class Rules {

	/**
	 * Rule lists.
	 *
	 * @var array<string,array<int|string,mixed>>
	 */
	private array $lists = array(
		'js_no_defer'           => array(), // Handles/URL fragments that must never be deferred.
		'js_no_delay'           => array(), // Handles/URL fragments/inline code fragments never delayed.
		'js_no_minify'          => array(),
		'css_no_optimize'       => array(), // Never minified or loaded asynchronously.
		'lazy_exclude'          => array(), // Image/iframe class, id or URL fragments never lazy loaded.
		'cache_exclude_urls'    => array(), // URL patterns (see Context::url_matches()) never cached.
		'cache_exclude_cookies' => array(), // Cookie name prefixes that bypass the page cache.
		'cache_vary_cookies'    => array(), // Cookie names whose values produce separate cache variants.
		'cache_safe_cookies'    => array(), // Cookies a response may set without making it uncacheable.
		'cache_query_keep'      => array(), // Query parameters that change content (cached as variants).
		'cache_query_ignore'    => array(), // Tracking parameters stripped from the cache key.
		'inline_globals'        => array(), // handle => JS globals that handle defines.
		'facade_exclude'        => array(), // URL/markup fragments never replaced by facades.
	);

	/**
	 * Optimizations disabled by a profile: id => reason.
	 *
	 * @var array<string,string>
	 */
	private array $disabled = array();

	/**
	 * Confidence adjustments: id => list of [points, reason].
	 *
	 * @var array<string,array<int,array{0:int,1:string}>>
	 */
	private array $penalties = array();

	/**
	 * Names of the profiles that contributed.
	 *
	 * @var string[]
	 */
	private array $profiles = array();

	/**
	 * Add entries to a list.
	 *
	 * @param string   $list    List key.
	 * @param string[] $entries Entries.
	 */
	public function add( string $list, array $entries ): self {
		if ( ! isset( $this->lists[ $list ] ) ) {
			$this->lists[ $list ] = array();
		}
		if ( 'inline_globals' === $list ) {
			foreach ( $entries as $handle => $globals ) {
				$this->lists[ $list ][ $handle ] = array_values( array_unique( array_merge( $this->lists[ $list ][ $handle ] ?? array(), (array) $globals ) ) );
			}
			return $this;
		}
		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' !== $entry && ! in_array( $entry, $this->lists[ $list ], true ) ) {
				$this->lists[ $list ][] = $entry;
			}
		}
		return $this;
	}

	/**
	 * Entries of a list.
	 *
	 * @param string $list List key.
	 * @return array<int|string,mixed>
	 */
	public function get( string $list ): array {
		return $this->lists[ $list ] ?? array();
	}

	/**
	 * Whether any entry of a list matches one of the haystacks (case-insensitive substring).
	 *
	 * @param string   $list       List key.
	 * @param string[] $haystacks  Handle, URL, code and similar.
	 */
	public function matches( string $list, array $haystacks ): bool {
		foreach ( $this->get( $list ) as $needle ) {
			if ( ! is_string( $needle ) || '' === $needle ) {
				continue;
			}
			foreach ( $haystacks as $haystack ) {
				if ( '' !== (string) $haystack && false !== stripos( (string) $haystack, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Disable an optimization entirely.
	 *
	 * @param string $optimization_id Optimization id.
	 * @param string $reason          Plain-language reason.
	 */
	public function disable( string $optimization_id, string $reason ): self {
		$this->disabled[ $optimization_id ] = $reason;
		return $this;
	}

	/**
	 * Reason an optimization is disabled, or null.
	 *
	 * @param string $optimization_id Optimization id.
	 */
	public function disabled_reason( string $optimization_id ): ?string {
		return $this->disabled[ $optimization_id ] ?? null;
	}

	/**
	 * Lower the confidence of an optimization.
	 *
	 * @param string $optimization_id Optimization id.
	 * @param int    $points          Points.
	 * @param string $reason          Reason.
	 */
	public function penalize( string $optimization_id, int $points, string $reason ): self {
		$this->penalties[ $optimization_id ][] = array( $points, $reason );
		return $this;
	}

	/**
	 * Penalties for an optimization.
	 *
	 * @param string $optimization_id Optimization id.
	 * @return array<int,array{0:int,1:string}>
	 */
	public function penalties( string $optimization_id ): array {
		return $this->penalties[ $optimization_id ] ?? array();
	}

	/**
	 * Record a contributing profile.
	 *
	 * @param string $name Profile name.
	 */
	public function add_profile( string $name ): void {
		$this->profiles[] = $name;
	}

	/**
	 * Contributing profiles.
	 *
	 * @return string[]
	 */
	public function profiles(): array {
		return $this->profiles;
	}

	/**
	 * Array form (diagnostics).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'profiles'  => $this->profiles,
			'lists'     => $this->lists,
			'disabled'  => $this->disabled,
			'penalties' => $this->penalties,
		);
	}
}
