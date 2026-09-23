<?php
/**
 * Google Fonts URL parser and rewriter (pure).
 *
 * Understands the CSS API v1 (`/css?family=Open+Sans:400,700i|Lato`) and v2
 * (`/css2?family=Roboto:ital,wght@0,400;1,700&family=Lato`). Used by the
 * font optimizations and by the scanner for diagnostics.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * Google Fonts helper.
 */
final class GoogleFonts {

	public const CSS_HOST  = 'fonts.googleapis.com';
	public const FONT_HOST = 'fonts.gstatic.com';

	/**
	 * Whether a URL is a Google Fonts stylesheet (css, css2 or icon API).
	 *
	 * @param string $url URL (decoded attribute value).
	 */
	public static function is_css_url( string $url ): bool {
		return '' !== self::api( $url );
	}

	/**
	 * API of a Google Fonts stylesheet URL: css|css2|icon, or '' for anything else.
	 *
	 * @param string $url URL.
	 */
	public static function api( string $url ): string {
		if ( ! preg_match( '#^(?:https?:)?//fonts\.googleapis\.com(/[^?\#]*)?#i', trim( $url ), $m ) ) {
			return '';
		}
		$path = strtolower( rtrim( $m[1] ?? '', '/' ) );
		return in_array( $path, array( '/css', '/css2', '/icon' ), true ) ? ltrim( $path, '/' ) : '';
	}

	/**
	 * Parse a stylesheet URL.
	 *
	 * @param string $url URL.
	 * @return array{valid:bool,api:string,families:array<string,array{weights:string[],styles:string[]}>,display:string|null,subset:string|null,text:string|null}
	 */
	public static function parse( string $url ): array {
		$result = array(
			'valid'    => false,
			'api'      => self::api( $url ),
			'families' => array(),
			'display'  => null,
			'subset'   => null,
			'text'     => null,
		);
		if ( '' === $result['api'] ) {
			return $result;
		}

		$query = (string) ( parse_url( 'https:' . preg_replace( '#^https?:#i', '', trim( $url ) ), PHP_URL_QUERY ) ?? '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.

		foreach ( self::query_pairs( $query ) as $pair ) {
			list( $name, $value ) = $pair;
			switch ( $name ) {
				case 'family':
					$families = 'css2' === $result['api'] ? array( self::parse_css2_family( $value ) ) : self::parse_css1_families( $value );
					foreach ( $families as $family ) {
						if ( null === $family ) {
							continue;
						}
						$name_key = $family['family'];
						if ( isset( $result['families'][ $name_key ] ) ) {
							$result['families'][ $name_key ]['weights'] = self::unique( array_merge( $result['families'][ $name_key ]['weights'], $family['weights'] ) );
							$result['families'][ $name_key ]['styles']  = self::unique( array_merge( $result['families'][ $name_key ]['styles'], $family['styles'] ) );
						} else {
							$result['families'][ $name_key ] = array(
								'weights' => $family['weights'],
								'styles'  => $family['styles'],
							);
						}
					}
					break;
				case 'display':
				case 'subset':
				case 'text':
					$result[ $name ] = $value;
					break;
			}
		}

		$result['valid'] = ! empty( $result['families'] );
		return $result;
	}

	/**
	 * Add display=swap when the URL has no display parameter. Icon fonts are left alone
	 * (swapping them shows raw ligature text such as "menu").
	 *
	 * @param string $url URL.
	 */
	public static function add_display_swap( string $url ): string {
		$parsed = self::parse( $url );
		if ( ! $parsed['valid'] || 'icon' === $parsed['api'] || null !== $parsed['display'] ) {
			return $url;
		}
		foreach ( array_keys( $parsed['families'] ) as $family ) {
			if ( FontFaceTransformer::is_icon_font( $family ) ) {
				return $url;
			}
		}

		$fragment = '';
		$pos      = strpos( $url, '#' );
		if ( false !== $pos ) {
			$fragment = substr( $url, $pos );
			$url      = substr( $url, 0, $pos );
		}
		$separator = false === strpos( $url, '?' ) ? '?' : ( in_array( substr( $url, -1 ), array( '?', '&' ), true ) ? '' : '&' );
		return $url . $separator . 'display=swap' . $fragment;
	}

	/**
	 * Families requested by more than one stylesheet.
	 *
	 * @param string[] $urls Stylesheet URLs.
	 * @return array<string,int> family => number of stylesheets requesting it.
	 */
	public static function duplicates( array $urls ): array {
		$counts = array();
		foreach ( array_unique( $urls ) as $url ) {
			foreach ( array_keys( self::parse( (string) $url )['families'] ) as $family ) {
				$counts[ $family ] = ( $counts[ $family ] ?? 0 ) + 1;
			}
		}
		return array_filter(
			$counts,
			static function ( $count ) {
				return $count > 1;
			}
		);
	}

	/**
	 * Canonical key of a stylesheet URL: scheme-less, without the display parameter.
	 *
	 * @param string $url URL.
	 */
	public static function key( string $url ): string {
		$url   = (string) preg_replace( '#^https?:#i', '', trim( $url ) );
		$url   = (string) preg_replace( '/#.*$/s', '', $url );
		$parts = explode( '?', $url, 2 );
		$base  = strtolower( $parts[0] );
		if ( ! isset( $parts[1] ) ) {
			return $base;
		}
		$keep = array();
		foreach ( explode( '&', $parts[1] ) as $pair ) {
			if ( '' !== $pair && 'display' !== strtolower( explode( '=', $pair, 2 )[0] ) ) {
				$keep[] = $pair;
			}
		}
		return $base . ( empty( $keep ) ? '' : '?' . implode( '&', $keep ) );
	}

	/**
	 * Query pairs in order (repeated names are kept, unlike parse_str()).
	 *
	 * @param string $query Raw query string.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function query_pairs( string $query ): array {
		$pairs = array();
		foreach ( explode( '&', $query ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$bits    = explode( '=', $part, 2 );
			$pairs[] = array( strtolower( urldecode( $bits[0] ) ), urldecode( $bits[1] ?? '' ) );
		}
		return $pairs;
	}

	/**
	 * CSS API v1 family parameter: "Open Sans:400,700italic|Lato".
	 *
	 * @param string $value Decoded value.
	 * @return array<int,array{family:string,weights:string[],styles:string[]}|null>
	 */
	private static function parse_css1_families( string $value ): array {
		$out = array();
		foreach ( explode( '|', $value ) as $spec ) {
			$bits   = explode( ':', $spec, 3 );
			$family = self::family_name( $bits[0] );
			if ( '' === $family ) {
				continue;
			}
			$weights = array();
			$styles  = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $bits[1] ?? '' ) ) ) as $variant ) {
				$variant = strtolower( $variant );
				$map     = array(
					'regular'    => array( '400', 'normal' ),
					'italic'     => array( '400', 'italic' ),
					'i'          => array( '400', 'italic' ),
					'bold'       => array( '700', 'normal' ),
					'b'          => array( '700', 'normal' ),
					'bolditalic' => array( '700', 'italic' ),
					'bi'         => array( '700', 'italic' ),
				);
				if ( isset( $map[ $variant ] ) ) {
					$weights[] = $map[ $variant ][0];
					$styles[]  = $map[ $variant ][1];
				} elseif ( preg_match( '/^(\d{3})(i|italic)?$/', $variant, $m ) ) {
					$weights[] = $m[1];
					$styles[]  = empty( $m[2] ) ? 'normal' : 'italic';
				}
			}
			$out[] = array(
				'family'  => $family,
				'weights' => empty( $weights ) ? array( '400' ) : self::unique( $weights ),
				'styles'  => empty( $styles ) ? array( 'normal' ) : self::unique( $styles ),
			);
		}
		return $out;
	}

	/**
	 * CSS API v2 family parameter: "Roboto:ital,wght@0,400;1,700" or "Inter:wght@100..900".
	 *
	 * @param string $value Decoded value.
	 * @return array{family:string,weights:string[],styles:string[]}|null
	 */
	private static function parse_css2_family( string $value ): ?array {
		$bits   = explode( ':', $value, 2 );
		$family = self::family_name( $bits[0] );
		if ( '' === $family ) {
			return null;
		}
		$weights = array();
		$styles  = array();

		if ( isset( $bits[1] ) && false !== strpos( $bits[1], '@' ) ) {
			list( $axes, $tuples ) = explode( '@', $bits[1], 2 );
			$axes                  = array_map( 'trim', explode( ',', $axes ) );
			$ital                  = array_search( 'ital', $axes, true );
			$wght                  = array_search( 'wght', $axes, true );
			foreach ( explode( ';', $tuples ) as $tuple ) {
				$values = array_map( 'trim', explode( ',', $tuple ) );
				if ( false !== $wght && isset( $values[ $wght ] ) && '' !== $values[ $wght ] ) {
					$weights[] = $values[ $wght ];
				}
				if ( false !== $ital && isset( $values[ $ital ] ) ) {
					$styles[] = '1' === $values[ $ital ] ? 'italic' : 'normal';
				}
			}
		}

		return array(
			'family'  => $family,
			'weights' => empty( $weights ) ? array( '400' ) : self::unique( $weights ),
			'styles'  => empty( $styles ) ? array( 'normal' ) : self::unique( $styles ),
		);
	}

	/**
	 * Clean a family name.
	 *
	 * @param string $name Raw name.
	 */
	private static function family_name( string $name ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( '+', ' ', $name ) ) );
	}

	/**
	 * Unique, sorted list.
	 *
	 * @param string[] $values Values.
	 * @return string[]
	 */
	private static function unique( array $values ): array {
		$values = array_values( array_unique( array_map( 'strval', $values ) ) );
		sort( $values, SORT_NATURAL );
		return $values;
	}
}
