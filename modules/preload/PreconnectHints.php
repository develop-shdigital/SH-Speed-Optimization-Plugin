<?php
/**
 * Plans and inserts preconnect hints.
 *
 * Pure. Adds fonts.gstatic.com when Google Fonts CSS is linked, plus up to two
 * third-party origins serving render-blocking CSS/JS in <head>. Existing
 * preconnect/dns-prefetch hints are respected, delayed scripts
 * (type="shso/delay") never count, and at most three hints are added.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Preload;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Preconnect planner.
 */
final class PreconnectHints {

	/**
	 * Maximum hints added.
	 */
	public const MAX = 3;

	/**
	 * Maximum third-party origins besides fonts.gstatic.com.
	 */
	public const MAX_THIRD_PARTY = 2;

	/**
	 * Site host(s) — never preconnected.
	 *
	 * @var string[]
	 */
	private array $own_hosts;

	/**
	 * Constructor.
	 *
	 * @param string[] $own_hosts Hosts of this site (home, site, content URLs).
	 */
	public function __construct( array $own_hosts ) {
		$this->own_hosts = array_values( array_unique( array_filter( array_map( 'strtolower', $own_hosts ) ) ) );
	}

	/**
	 * Plan hints for a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return array<int,array{origin:string,crossorigin:bool}>
	 */
	public function plan( HtmlDocument $doc ): array {
		$existing     = array();
		$google_fonts = false;
		$candidates   = array(); // origin => crossorigin.

		foreach ( Hints::links( $doc ) as $link ) {
			list( $tag, $info ) = $link;
			$href               = (string) $tag->get( 'href' );
			$host               = Hints::host( $href );

			if ( Hints::rel_has( $tag, 'preconnect' ) || Hints::rel_has( $tag, 'dns-prefetch' ) ) {
				if ( '' !== $host ) {
					$existing[ $host ] = true;
				}
				continue;
			}

			if ( ! Hints::rel_has( $tag, 'stylesheet' ) || '' === $host ) {
				continue;
			}

			if ( 'fonts.googleapis.com' === $host ) {
				$google_fonts = true;
			}

			$media          = strtolower( trim( (string) $tag->get( 'media' ) ) );
			$blocking_media = '' === $media || in_array( $media, array( 'all', 'screen' ), true );
			if ( $info['in_head'] && $blocking_media && ! $this->is_own( $host ) ) {
				$this->candidate( $candidates, (string) Hints::origin( $href ), $tag->has( 'crossorigin' ) );
			}
		}

		// Google Fonts loaded through @import in inline styles.
		if ( ! $google_fonts && $doc->contains( 'fonts.googleapis.com' ) ) {
			$doc->replace_styles(
				static function ( Tag $tag, string $css ) use ( &$google_fonts ) {
					if ( false !== stripos( $css, 'fonts.googleapis.com' ) && preg_match( '/@import\s[^;]*fonts\.googleapis\.com/i', $css ) ) {
						$google_fonts = true;
					}
					return null;
				}
			);
		}

		$doc->replace_scripts(
			function ( Tag $tag, string $code, array $info ) use ( &$candidates ) {
				if ( ! $info['in_head'] || ! $tag->has( 'src' ) || $tag->has( 'async' ) || $tag->has( 'defer' ) ) {
					return null;
				}
				$type = strtolower( trim( (string) $tag->get( 'type' ) ) );
				if ( ! in_array( $type, array( '', 'text/javascript', 'application/javascript', 'module' ), true ) || 'module' === $type ) {
					return null; // Delayed (shso/delay), templates, JSON, modules (deferred by default).
				}
				$src  = (string) $tag->get( 'src' );
				$host = Hints::host( $src );
				if ( '' !== $host && ! $this->is_own( $host ) ) {
					$this->candidate( $candidates, (string) Hints::origin( $src ), $tag->has( 'crossorigin' ) );
				}
				return null;
			}
		);

		$plan = array();
		if ( $google_fonts && ! isset( $existing['fonts.gstatic.com'] ) ) {
			$plan[] = array(
				'origin'      => 'https://fonts.gstatic.com',
				'crossorigin' => true,
			);
		}

		$third_party = 0;
		foreach ( $candidates as $origin => $crossorigin ) {
			if ( count( $plan ) >= self::MAX || $third_party >= self::MAX_THIRD_PARTY ) {
				break;
			}
			$host = Hints::host( $origin );
			if ( isset( $existing[ $host ] ) || 'fonts.gstatic.com' === $host ) {
				continue;
			}
			$plan[] = array(
				'origin'      => $origin,
				'crossorigin' => $crossorigin,
			);
			++$third_party;
		}

		return array_slice( $plan, 0, self::MAX );
	}

	/**
	 * Insert the planned hints.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Hints added.
	 */
	public function transform( HtmlDocument $doc ): int {
		$plan = $this->plan( $doc );
		if ( empty( $plan ) ) {
			return 0;
		}
		$markup = '';
		foreach ( $plan as $hint ) {
			$tag = Tag::create(
				'link',
				array(
					'rel'  => 'preconnect',
					'href' => $hint['origin'],
				)
			);
			if ( $hint['crossorigin'] ) {
				$tag->set( 'crossorigin', null );
			}
			$markup .= $tag->to_html();
		}
		return Hints::insert_early( $doc, $markup ) ? count( $plan ) : 0;
	}

	/**
	 * Remember a candidate origin (first occurrence wins; crossorigin if any use needs it).
	 *
	 * @param array<string,bool> $candidates  Candidates.
	 * @param string             $origin      Origin.
	 * @param bool               $crossorigin Whether the resource uses CORS.
	 */
	private function candidate( array &$candidates, string $origin, bool $crossorigin ): void {
		if ( '' === $origin ) {
			return;
		}
		$candidates[ $origin ] = ( $candidates[ $origin ] ?? false ) || $crossorigin;
	}

	/**
	 * Whether a host belongs to this site.
	 *
	 * @param string $host Host.
	 */
	private function is_own( string $host ): bool {
		return in_array( strtolower( $host ), $this->own_hosts, true );
	}
}
