<?php
/**
 * Adds `font-display: swap` to @font-face rules (pure).
 *
 * Text stays visible with a fallback font while web fonts load. Icon fonts
 * are never changed: swapping them would briefly show raw glyph characters
 * or ligature words ("menu", "search") instead of icons. Rules that already
 * declare any font-display value are kept as they are. Comments are masked
 * so commented-out rules are not touched.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * @font-face transformer.
 */
final class FontFaceTransformer {

	/**
	 * Case-insensitive substrings of icon font family names.
	 */
	private const ICON_FAMILY_PARTS = array(
		'icon',
		'awesome',
		'dashicons',
		'eicons',
		'icomoon',
		'etmodules',
		'themify',
		'simple-line',
		'genericons',
		'swiper',
		'slick',
		'revicons',
		'material symbols',
		'glyph',
		'symbol',
		'fontello',
		'flaticon',
		'entypo',
		'typicons',
		'fa-brands',
		'fa-solid',
		'fa-regular',
		'fa-light',
		'fa-thin',
		'fa-duotone',
		'fa-sharp',
		'fa-v4',
		'woocommerce',
		'socicon',
		'elusive',
		'pe-icon',
		'et-line',
		'feather',
		'remixicon',
		'boxicons',
	);

	/**
	 * Exact (case-insensitive) icon font family names too short for substring matching.
	 */
	private const ICON_FAMILY_EXACT = array( 'star', 'fa', 'fas', 'far', 'fab', 'fal', 'fad' );

	/**
	 * Case-insensitive substrings of icon font file URLs.
	 */
	private const ICON_URL_PARTS = array(
		'fontawesome',
		'font-awesome',
		'fa-brands',
		'fa-solid',
		'fa-regular',
		'fa-light',
		'fa-thin',
		'fa-duotone',
		'fa-v4',
		'dashicons',
		'eicons',
		'elementor-icons',
		'icomoon',
		'etmodules',
		'themify',
		'simple-line-icons',
		'genericons',
		'swiper-icons',
		'slick',
		'revicons',
		'material-icons',
		'materialicons',
		'material-symbols',
		'materialsymbols',
		'bootstrap-icons',
		'linearicons',
		'line-awesome',
		'la-solid',
		'la-regular',
		'la-brands',
		'woocommerce/assets/fonts',
		'/star.',
		'icon',
		'glyph',
		'fontello',
		'flaticon',
	);

	/**
	 * Add font-display:swap to @font-face rules lacking font-display, and display=swap to
	 * Google Fonts @import URLs.
	 *
	 * @param string $css CSS.
	 */
	public static function process( string $css ): string {
		if ( false === stripos( $css, '@font-face' ) && false === stripos( $css, 'fonts.googleapis.com' ) ) {
			return $css;
		}
		return self::swap_imports( self::add_swap( $css ) );
	}

	/**
	 * Add font-display:swap to @font-face rules lacking font-display (icon fonts excluded).
	 *
	 * @param string $css CSS.
	 */
	public static function add_swap( string $css ): string {
		if ( false === stripos( $css, '@font-face' ) ) {
			return $css;
		}

		$store  = array();
		$masked = self::mask_comments( $css, $store );

		$result = preg_replace_callback(
			'/(@font-face\s*\{)([^{}]*)(\})/i',
			static function ( $m ) {
				$body = $m[2];
				if ( preg_match( '/(?:^|[;{\s])font-display\s*:/i', $body ) ) {
					return $m[0];
				}
				list( $family, $src ) = self::describe( $body );
				if ( self::is_icon_font( $family, $src ) ) {
					return $m[0];
				}
				return $m[1] . 'font-display:swap;' . $body . $m[3];
			},
			$masked
		);

		if ( null === $result ) {
			return $css;
		}
		return self::unmask( $result, $store );
	}

	/**
	 * Add display=swap to Google Fonts URLs in @import rules.
	 *
	 * @param string $css CSS.
	 */
	public static function swap_imports( string $css ): string {
		if ( false === stripos( $css, 'fonts.googleapis.com' ) || false === stripos( $css, '@import' ) ) {
			return $css;
		}
		$store  = array();
		$masked = self::mask_comments( $css, $store );
		$result = preg_replace_callback(
			'#(@import\s+(?:url\(\s*)?)(["\']?)((?:https?:)?//fonts\.googleapis\.com/[^"\'\s);]+)(\2)#i',
			static function ( $m ) {
				return $m[1] . $m[2] . GoogleFonts::add_display_swap( $m[3] ) . $m[4];
			},
			$masked
		);
		return null === $result ? $css : self::unmask( $result, $store );
	}

	/**
	 * Number of @font-face rules without font-display (icon fonts excluded).
	 *
	 * @param string $css CSS.
	 */
	public static function count_missing( string $css ): int {
		$store  = array();
		$masked = self::mask_comments( $css, $store );
		$count  = 0;
		if ( preg_match_all( '/@font-face\s*\{([^{}]*)\}/i', $masked, $m ) ) {
			foreach ( $m[1] as $body ) {
				list( $family, $src ) = self::describe( $body );
				if ( ! preg_match( '/(?:^|[;{\s])font-display\s*:/i', $body ) && ! self::is_icon_font( $family, $src ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Whether a font family (and/or its source URLs) is an icon font.
	 *
	 * @param string $family Family name (quotes allowed).
	 * @param string $src    Source URL(s), optional.
	 */
	public static function is_icon_font( string $family, string $src = '' ): bool {
		$family = strtolower( trim( $family, " \t\n\r\0\x0B\"'" ) );
		if ( '' !== $family ) {
			if ( in_array( $family, self::ICON_FAMILY_EXACT, true ) || 0 === strpos( $family, 'la-' ) || 0 === strpos( $family, 'fa-' ) ) {
				return true;
			}
			foreach ( self::ICON_FAMILY_PARTS as $part ) {
				if ( false !== strpos( $family, $part ) ) {
					return true;
				}
			}
		}
		if ( '' !== $src ) {
			$src = strtolower( $src );
			foreach ( self::ICON_URL_PARTS as $part ) {
				if ( false !== strpos( $src, $part ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Family name and source list of an @font-face body.
	 *
	 * @param string $body Declarations.
	 * @return array{0:string,1:string}
	 */
	private static function describe( string $body ): array {
		$family = preg_match( '/(?:^|[;\s])font-family\s*:\s*([^;]+)/i', $body, $m ) ? trim( $m[1] ) : '';
		$src    = preg_match_all( '/(?:^|[;\s])src\s*:\s*([^;]+)/i', $body, $n ) ? implode( ' ', $n[1] ) : '';
		// Data URIs can be huge and are never icon hints; keep only URLs.
		$src = (string) preg_replace( '/url\(\s*["\']?data:[^)]*\)/i', 'url(data)', $src );
		return array( $family, $src );
	}

	/**
	 * Replace comments with placeholders.
	 *
	 * @param string   $css   CSS.
	 * @param string[] $store Store (filled).
	 */
	private static function mask_comments( string $css, array &$store ): string {
		if ( false === strpos( $css, '/*' ) ) {
			return $css;
		}
		$masked = preg_replace_callback(
			'#/\*.*?(?:\*/|$)#s',
			static function ( $m ) use ( &$store ) {
				$store[] = $m[0];
				return "\x1A" . ( count( $store ) - 1 ) . "\x1A";
			},
			$css
		);
		return null === $masked ? $css : $masked;
	}

	/**
	 * Restore comments.
	 *
	 * @param string   $css   Masked CSS.
	 * @param string[] $store Store.
	 */
	private static function unmask( string $css, array $store ): string {
		if ( empty( $store ) ) {
			return $css;
		}
		return (string) preg_replace_callback(
			"#\x1A(\d+)\x1A#",
			static function ( $m ) use ( $store ) {
				return $store[ (int) $m[1] ] ?? '';
			},
			$css
		);
	}
}
