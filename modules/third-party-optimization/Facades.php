<?php
/**
 * Shared helpers for video and map facades (pure).
 *
 * A facade is a lightweight placeholder with the same size as the embedded
 * frame it replaces. It only contains phrasing content (a display:block
 * <span> wrapper), so it stays valid where the iframe sat inside a <p>. The original frame's attributes are stored as JSON in
 * `data-shso-iframe` and restored by assets/js/facades.js on click. All
 * attribute values are escaped through {@see Tag::set()} and all text
 * through esc_html().
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ThirdPartyOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Facade helpers.
 */
final class Facades {

	public const VIDEO_RATIO = array( 16, 9 );
	public const MAP_RATIO   = array( 4, 3 );

	/**
	 * Classes of JavaScript lazy loaders that must not be copied onto a facade.
	 */
	private const SKIP_CLASSES = array( 'lazyload', 'lazy', 'lazyloaded', 'lazyloading', 'rocket-lazyload' );

	/**
	 * YouTube video id of an embed URL, or null (playlists without a video id, other URLs).
	 *
	 * @param string $src Embed URL.
	 */
	public static function youtube_id( string $src ): ?string {
		if ( ! preg_match( '#^(?:https?:)?//(?:www\.|m\.)?(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_\-]{6,20})(?:[?&\#/]|$)#i', trim( $src ), $m ) ) {
			return null;
		}
		return 'videoseries' === strtolower( $m[1] ) ? null : $m[1];
	}

	/**
	 * Vimeo video id of a player URL, or null.
	 *
	 * @param string $src Player URL.
	 */
	public static function vimeo_id( string $src ): ?string {
		return preg_match( '#^(?:https?:)?//player\.vimeo\.com/video/(\d{1,12})(?:[?&\#/]|$)#i', trim( $src ), $m ) ? $m[1] : null;
	}

	/**
	 * Privacy hash of an unlisted Vimeo video ("h" parameter), or ''.
	 *
	 * @param string $src Player URL.
	 */
	public static function vimeo_hash( string $src ): string {
		$hash = (string) ( self::query( $src )['h'] ?? '' );
		return preg_match( '/^[a-f0-9]{6,20}$/i', $hash ) ? $hash : '';
	}

	/**
	 * Decoded query parameters (lower-case names).
	 *
	 * @param string $src URL.
	 * @return array<string,string>
	 */
	public static function query( string $src ): array {
		$query = (string) parse_url( 'https:' . preg_replace( '#^https?:#i', '', trim( $src ) ), PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.
		$out   = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$bits = explode( '=', $pair, 2 );
			$name = strtolower( urldecode( $bits[0] ) );
			if ( ! isset( $out[ $name ] ) ) {
				$out[ $name ] = urldecode( $bits[1] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * Background/ambient video (autoplay, background mode, muted loop): never replaced.
	 *
	 * @param string $src Embed URL.
	 */
	public static function is_background_video( string $src ): bool {
		$q    = self::query( $src );
		$true = static function ( ?string $value ): bool {
			return null !== $value && in_array( strtolower( $value ), array( '1', 'true' ), true );
		};
		if ( $true( $q['autoplay'] ?? null ) || $true( $q['background'] ?? null ) ) {
			return true;
		}
		return ( $true( $q['mute'] ?? null ) || $true( $q['muted'] ?? null ) ) && $true( $q['loop'] ?? null );
	}

	/**
	 * Video controlled by JavaScript on the page (YouTube IFrame API, Vimeo API).
	 *
	 * @param string $src Embed URL.
	 */
	public static function uses_js_api( string $src ): bool {
		$q = self::query( $src );
		return '1' === ( $q['enablejsapi'] ?? '' ) || '1' === ( $q['api'] ?? '' );
	}

	/**
	 * URL with autoplay=1 (replaces an existing autoplay value, keeps everything else).
	 *
	 * @param string $src URL.
	 */
	public static function autoplay_src( string $src ): string {
		$fragment = '';
		$pos      = strpos( $src, '#' );
		if ( false !== $pos ) {
			$fragment = substr( $src, $pos );
			$src      = substr( $src, 0, $pos );
		}
		if ( preg_match( '/([?&])autoplay=[^&]*/i', $src ) ) {
			return (string) preg_replace( '/([?&])autoplay=[^&]*/i', '$1autoplay=1', $src ) . $fragment;
		}
		return $src . ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'autoplay=1' . $fragment;
	}

	/**
	 * Start time in seconds from "start" or "t" (e.g. 90, 90s, 1m30s).
	 *
	 * @param string $src URL.
	 */
	public static function start_seconds( string $src ): int {
		$q     = self::query( $src );
		$value = strtolower( (string) ( $q['start'] ?? ( $q['t'] ?? '' ) ) );
		if ( preg_match( '/^\d+$/', $value ) ) {
			return (int) $value;
		}
		if ( preg_match( '/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $value, $m ) && '' !== $value ) {
			return (int) ( $m[1] ?? 0 ) * 3600 + (int) ( $m[2] ?? 0 ) * 60 + (int) ( $m[3] ?? 0 );
		}
		return 0;
	}

	/**
	 * YouTube watch URL for the no-JavaScript fallback link.
	 *
	 * @param string $id  Video id.
	 * @param string $src Embed URL (start time and playlist are kept).
	 */
	public static function youtube_watch_url( string $id, string $src ): string {
		$url   = 'https://www.youtube.com/watch?v=' . rawurlencode( $id );
		$start = self::start_seconds( $src );
		if ( $start > 0 ) {
			$url .= '&t=' . $start . 's';
		}
		$list = (string) ( self::query( $src )['list'] ?? '' );
		if ( preg_match( '/^[A-Za-z0-9_\-]{2,64}$/', $list ) ) {
			$url .= '&list=' . $list;
		}
		return $url;
	}

	/**
	 * Vimeo page URL for the no-JavaScript fallback link.
	 *
	 * @param string $id   Video id.
	 * @param string $hash Privacy hash.
	 */
	public static function vimeo_watch_url( string $id, string $hash = '' ): string {
		return 'https://vimeo.com/' . rawurlencode( $id ) . ( '' !== $hash ? '/' . rawurlencode( $hash ) : '' );
	}

	/**
	 * Google Maps embed iframe URL.
	 *
	 * @param string $src URL.
	 */
	public static function is_google_maps_embed( string $src ): bool {
		$src = trim( $src );
		if ( preg_match( '#^(?:https?:)?//(?:www\.)?google\.com/maps/embed(?:/v1/[a-z]+)?(?:[?/]|$)#i', $src ) ) {
			return true;
		}
		if ( preg_match( '#^(?:https?:)?//(?:www\.|maps\.)?google\.[a-z]{2,3}(?:\.[a-z]{2})?/maps(?:/[^?]*)?\?#i', $src ) ) {
			return 'embed' === strtolower( (string) ( self::query( $src )['output'] ?? '' ) );
		}
		return false;
	}

	/**
	 * Place name of a map when derivable: the "q" parameter, or the place name inside the
	 * "pb" parameter of Google's share embed code.
	 *
	 * @param string $src Embed URL.
	 */
	public static function map_query( string $src ): ?string {
		$q     = self::query( $src );
		$place = trim( (string) ( $q['q'] ?? '' ) );
		if ( '' === $place && ! empty( $q['pb'] ) && preg_match( '/!1s0x[0-9a-f]+(?::|%3A)0x[0-9a-f]+!2s([^!]+)/i', (string) $q['pb'], $m ) ) {
			$place = trim( rawurldecode( str_replace( '+', ' ', $m[1] ) ) );
		}
		$place = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $place ) );
		if ( '' === $place || strlen( $place ) > 200 ) {
			return null;
		}
		return $place;
	}

	/**
	 * Link to open the map on Google Maps.
	 *
	 * @param string|null $place Place name.
	 * @param string      $src   Embed URL (fallback).
	 */
	public static function maps_link( ?string $place, string $src ): string {
		if ( null !== $place && '' !== $place ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $place );
		}
		return 'https:' . preg_replace( '#^https?:#i', '', trim( $src ) );
	}

	/**
	 * Aspect information from width/height attributes.
	 *
	 * @param string|null    $width   Width attribute.
	 * @param string|null    $height  Height attribute.
	 * @param array<int,int> $fallback Default ratio [ w, h ].
	 * @return array{ratio:array{0:int,1:int},width:int,height:int,fluid:bool}
	 */
	public static function aspect( ?string $width, ?string $height, array $fallback ): array {
		$w = self::pixels( $width );
		$h = self::pixels( $height );
		return array(
			'ratio'  => $w > 0 && $h > 0 ? array( $w, $h ) : array( (int) $fallback[0], (int) $fallback[1] ),
			'width'  => $w,
			'height' => $h,
			'fluid'  => null !== $width && (bool) preg_match( '/^\s*\d+(?:\.\d+)?%\s*$/', $width ),
		);
	}

	/**
	 * Inline sizing so the facade occupies exactly the frame's box (no layout shift).
	 *
	 * @param array{ratio:array{0:int,1:int},width:int,height:int,fluid:bool} $aspect Aspect.
	 */
	public static function sizing_style( array $aspect ): string {
		if ( $aspect['width'] > 0 && $aspect['height'] > 0 ) {
			return sprintf( 'width:%1$dpx;max-width:100%%;aspect-ratio:%1$d/%2$d;', $aspect['width'], $aspect['height'] );
		}
		if ( $aspect['height'] > 0 ) {
			return sprintf( 'width:100%%;height:%dpx;', $aspect['height'] );
		}
		return sprintf( 'width:100%%;aspect-ratio:%d/%d;', $aspect['ratio'][0], $aspect['ratio'][1] );
	}

	/**
	 * Wrapper element shared by all facades (a <span> styled as a block).
	 *
	 * @param string $kind  video|map.
	 * @param Tag    $frame Original iframe.
	 * @param string $src   URL loaded on activation.
	 * @param string $title Accessible title.
	 * @param string $style Sizing style.
	 * @param string $provider Provider id.
	 */
	public static function wrapper( string $kind, Tag $frame, string $src, string $title, string $style, string $provider ): Tag {
		$classes = array( 'shso-facade', 'shso-facade--' . $kind );
		foreach ( $frame->classes() as $class ) {
			if ( ! in_array( strtolower( $class ), self::SKIP_CLASSES, true ) && preg_match( '/^[A-Za-z0-9_\-:]+$/', $class ) ) {
				$classes[] = $class;
			}
		}

		$frame_style = trim( (string) $frame->get( 'style' ) );
		if ( '' !== $frame_style && ';' !== substr( $frame_style, -1 ) ) {
			$frame_style .= ';';
		}

		$attributes = array();
		foreach ( $frame->attributes() as $name => $value ) {
			$attributes[ $name ] = null === $value ? '' : $value;
		}

		return Tag::create(
			'span',
			array(
				'class'              => implode( ' ', array_unique( $classes ) ),
				'data-shso-provider' => $provider,
				'data-shso-src'      => $src,
				'data-shso-title'    => $title,
				'data-shso-iframe'   => (string) wp_json_encode( $attributes, JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_APOS ),
				'style'              => $style . $frame_style,
			)
		);
	}

	/**
	 * Video facade markup.
	 *
	 * @param Tag    $frame     Original iframe.
	 * @param string $provider  youtube|vimeo.
	 * @param string $src       Autoplay URL.
	 * @param string $thumbnail Thumbnail URL.
	 * @param string $watch_url Fallback link.
	 */
	public static function video_markup( Tag $frame, string $provider, string $src, string $thumbnail, string $watch_url ): string {
		$title  = self::clean_text( (string) $frame->get( 'title' ) );
		$aspect = self::aspect( $frame->get( 'width' ), $frame->get( 'height' ), self::VIDEO_RATIO );
		$div    = self::wrapper( 'video', $frame, $src, $title, self::sizing_style( $aspect ), $provider );

		/* translators: %s: video title */
		$alt = '' !== $title ? sprintf( __( 'Video: %s', 'sh-speed-optimizer' ), $title ) : __( 'Video', 'sh-speed-optimizer' );
		/* translators: %s: video title */
		$label = '' !== $title ? sprintf( __( 'Play video: %s', 'sh-speed-optimizer' ), $title ) : __( 'Play video', 'sh-speed-optimizer' );
		/* translators: %s: video title */
		$link = '' !== $title ? sprintf( __( 'Watch the video: %s', 'sh-speed-optimizer' ), $title ) : __( 'Watch the video', 'sh-speed-optimizer' );

		$img = Tag::create(
			'img',
			array(
				'class'    => 'shso-facade__thumb',
				'src'      => $thumbnail,
				'alt'      => $alt,
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		);

		$button = Tag::create(
			'button',
			array(
				'type'       => 'button',
				'class'      => 'shso-facade__play',
				'aria-label' => $label,
			)
		);

		$anchor = Tag::create(
			'a',
			array(
				'class' => 'shso-facade__link',
				'href'  => $watch_url,
				'rel'   => 'noopener',
			)
		);

		return $div->to_html()
			. $img->to_html()
			. $button->to_html() . self::play_icon() . '</button>'
			. '<noscript>' . $anchor->to_html() . esc_html( $link ) . '</a></noscript>'
			. '</span>';
	}

	/**
	 * Map facade markup.
	 *
	 * @param Tag         $frame Original iframe.
	 * @param string|null $place Place name.
	 */
	public static function map_markup( Tag $frame, ?string $place ): string {
		$src    = trim( (string) $frame->get( 'src' ) );
		$title  = self::clean_text( (string) $frame->get( 'title' ) );
		$title  = '' !== $title ? $title : (string) $place;
		$aspect = self::aspect( $frame->get( 'width' ), $frame->get( 'height' ), self::MAP_RATIO );
		$div    = self::wrapper( 'map', $frame, $src, $title, self::sizing_style( $aspect ), 'google-maps' );

		$button = Tag::create(
			'button',
			array(
				'type'       => 'button',
				'class'      => 'shso-facade__load',
				'aria-label' => __( 'Load interactive map', 'sh-speed-optimizer' ),
			)
		);
		$anchor = Tag::create(
			'a',
			array(
				'class'  => 'shso-facade__link',
				'href'   => self::maps_link( $place, $src ),
				'target' => '_blank',
				'rel'    => 'noopener noreferrer',
			)
		);

		$label = null !== $place && '' !== $place ? '<span class="shso-facade__label">' . esc_html( $place ) . '</span>' : '';

		return $div->to_html()
			. '<span class="shso-facade__inner">'
			. self::pin_icon()
			. $label
			. $button->to_html() . esc_html__( 'Load map', 'sh-speed-optimizer' ) . '</button>'
			. $anchor->to_html() . esc_html__( 'Open in Google Maps', 'sh-speed-optimizer' ) . '</a>'
			. '</span></span>';
	}

	/**
	 * Whether a frame is excluded (data-no-facade or a facade_exclude fragment in its URL/markup).
	 *
	 * @param Tag      $frame    Iframe tag.
	 * @param string   $outer    Original markup.
	 * @param string[] $patterns Exclusion fragments.
	 */
	public static function excluded( Tag $frame, string $outer, array $patterns ): bool {
		if ( $frame->has( 'data-no-facade' ) || $frame->has( 'data-no-optimize' ) || $frame->has_class( 'no-facade' ) ) {
			return true;
		}
		$src = (string) $frame->get( 'src' );
		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' !== $pattern && ( false !== stripos( $src, $pattern ) || false !== stripos( $outer, $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add the facade stylesheet (head) and script (end of body) once.
	 *
	 * @param HtmlDocument $doc     Document.
	 * @param string       $css_url Stylesheet URL.
	 * @param string       $js_url  Script URL.
	 */
	public static function inject_assets( HtmlDocument $doc, string $css_url, string $js_url ): void {
		if ( ! $doc->contains( 'id="shso-facades-css"' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Injected into the final HTML only on pages that got a facade.
			$doc->insert_in_head( '<link rel="stylesheet" id="shso-facades-css" href="' . esc_url( $css_url ) . '" media="all">' );
		}
		if ( ! $doc->contains( 'id="shso-facades-js"' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Injected into the final HTML only on pages that got a facade.
			$doc->insert_before_body_end( '<script id="shso-facades-js" src="' . esc_url( $js_url ) . '" defer></script>' );
		}
	}

	/**
	 * Numeric pixel value of a width/height attribute (0 when not plain pixels).
	 *
	 * @param string|null $value Attribute value.
	 */
	private static function pixels( ?string $value ): int {
		if ( null === $value || ! preg_match( '/^\s*(\d{1,5})(?:\.\d+)?(?:px)?\s*$/i', $value, $m ) ) {
			return 0;
		}
		return (int) $m[1];
	}

	/**
	 * Single-line, length-limited text.
	 *
	 * @param string $text Text.
	 */
	private static function clean_text( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 150 ) : substr( $text, 0, 150 );
	}

	/**
	 * Play button icon (decorative).
	 */
	private static function play_icon(): string {
		return '<svg aria-hidden="true" focusable="false" viewBox="0 0 68 48" width="68" height="48"><path class="shso-facade__play-bg" d="M66.5 7.7c-.8-2.9-2.5-5.4-5.4-6.2C55.8.1 34 0 34 0S12.2.1 6.9 1.6C4 2.3 2.3 4.8 1.5 7.7 0 13 0 24 0 24s0 11 1.5 16.3c.8 2.9 2.5 5.4 5.4 6.2C12.2 47.9 34 48 34 48s21.8-.1 27.1-1.6c2.9-.8 4.6-3.3 5.4-6.2C68 35 68 24 68 24s0-11-1.5-16.3z"/><path d="M45 24 27 14v20z" fill="#fff"/></svg>';
	}

	/**
	 * Map pin icon (decorative).
	 */
	private static function pin_icon(): string {
		return '<svg class="shso-facade__pin" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="40" height="40"><path d="M12 2C8.1 2 5 5.1 5 9c0 5.3 7 13 7 13s7-7.7 7-13c0-3.9-3.1-7-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>';
	}
}
