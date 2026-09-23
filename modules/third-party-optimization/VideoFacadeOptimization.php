<?php
/**
 * Lightweight previews for YouTube and Vimeo embeds (video facades).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ThirdPartyOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;
use SH\SpeedOptimizer\Core\Scheduler;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Video facades.
 */
final class VideoFacadeOptimization extends AbstractOptimization {

	public const CRON_HOOK = 'shso_facade_thumbs';

	public const OPTION = 'shso_facade_thumbs';

	/**
	 * Maximum cached Vimeo thumbnails.
	 */
	public const MAX_THUMBS = 200;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'video_facade';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Load videos only when played', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Shows a preview image with a play button instead of loading the full YouTube or Vimeo player right away. The real player loads the moment a visitor clicks play.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::THIRD_PARTY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::MODERATE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_SMART;
	}

	/**
	 * {@inheritDoc}
	 */
	public function requirements(): array {
		return array( self::REQ_BROWSER );
	}

	/**
	 * {@inheritDoc}
	 */
	public function expected_changes(): array {
		return array( 'iframes' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$videos = array_filter(
			$context->collect( 'iframes' ),
			static function ( $iframe ) {
				return in_array( (string) ( $iframe['kind'] ?? '' ), array( 'youtube', 'vimeo' ), true );
			}
		);

		if ( empty( $videos ) ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'No embedded YouTube or Vimeo videos were found on the analysed pages.', 'sh-speed-optimizer' ) ),
				$context
			);
		}

		$assessment       = Assessment::make( true, 75, count( $videos ) >= 2 ? Assessment::BENEFIT_HIGH : Assessment::BENEFIT_MEDIUM );
		$assessment->data = array( 'videos' => count( $videos ) );
		$assessment->note(
			sprintf(
				/* translators: %d: number of videos */
				_n( '%d embedded video loads its full player (several hundred kilobytes) even if nobody plays it.', '%d embedded videos load their full players (several hundred kilobytes each) even if nobody plays them.', count( $videos ), 'sh-speed-optimizer' ),
				count( $videos )
			)
		);
		$assessment->note( __( 'Background videos, autoplaying videos and videos controlled by scripts are never replaced.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::OPTION );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		add_filter( 'shso_cron_hooks', array( self::class, 'cron_hooks' ) );
		add_action( self::CRON_HOOK, array( self::class, 'fetch_thumbnails' ) );

		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) use ( $runtime ) {
				if ( ! $doc->contains( '<iframe' ) || ( ! $doc->contains( 'youtube' ) && ! $doc->contains( 'vimeo.com' ) ) ) {
					return;
				}
				// Measured as visible without scrolling on this template: the video is hero content.
				if ( ! empty( $runtime->page_data()['video_in_viewport'] ) ) {
					return;
				}

				$store  = $doc->contains( 'player.vimeo.com' ) ? self::store() : array( 'thumbs' => array() );
				$result = self::transform(
					$doc,
					array(
						'exclude' => array_map( 'strval', (array) $runtime->rules()->get( 'facade_exclude' ) ),
						'thumbs'  => (array) $store['thumbs'],
					)
				);

				if ( $result['count'] > 0 ) {
					$base = plugin_dir_url( SHSO_FILE ) . 'assets/';
					Facades::inject_assets( $doc, $base . 'css/facades.css?ver=' . SHSO_VERSION, $base . 'js/facades.min.js?ver=' . SHSO_VERSION );
				}
				if ( ! empty( $result['vimeo_missing'] ) ) {
					self::queue_thumbnails( $result['vimeo_missing'] );
				}
			},
			60
		);
	}

	/**
	 * Replace eligible video iframes.
	 *
	 * Config: exclude (string[] facade_exclude fragments), thumbs (Vimeo key => [ url ]).
	 *
	 * @param HtmlDocument        $doc    Document.
	 * @param array<string,mixed> $config Config.
	 * @return array{count:int,vimeo_missing:array<int,array{0:string,1:string}>}
	 */
	public static function transform( HtmlDocument $doc, array $config ): array {
		$exclude = array_map( 'strval', (array) ( $config['exclude'] ?? array() ) );
		$thumbs  = (array) ( $config['thumbs'] ?? array() );
		$missing = array();

		$count = $doc->replace_elements(
			'iframe',
			static function ( Tag $frame, string $inner, array $info ) use ( $exclude, $thumbs, &$missing ) {
				if ( $info['in_head'] ) {
					return null;
				}
				$src = trim( (string) $frame->get( 'src' ) );
				if ( '' === $src || Facades::is_background_video( $src ) || Facades::uses_js_api( $src ) ) {
					return null;
				}
				if ( Facades::excluded( $frame, $frame->to_html(), $exclude ) ) {
					return null;
				}

				$youtube = Facades::youtube_id( $src );
				if ( null !== $youtube ) {
					return Facades::video_markup(
						$frame,
						'youtube',
						Facades::autoplay_src( $src ),
						'https://i.ytimg.com/vi/' . rawurlencode( $youtube ) . '/hqdefault.jpg',
						Facades::youtube_watch_url( $youtube, $src )
					);
				}

				$vimeo = Facades::vimeo_id( $src );
				if ( null !== $vimeo ) {
					$hash  = Facades::vimeo_hash( $src );
					$key   = self::vimeo_key( $vimeo, $hash );
					$thumb = is_array( $thumbs[ $key ] ?? null ) ? (string) ( $thumbs[ $key ]['url'] ?? '' ) : '';
					if ( ! self::is_vimeo_thumbnail( $thumb ) ) {
						$missing[ $key ] = array( $vimeo, $hash );
						return null; // Stays untouched until a thumbnail is known.
					}
					return Facades::video_markup( $frame, 'vimeo', Facades::autoplay_src( $src ), $thumb, Facades::vimeo_watch_url( $vimeo, $hash ) );
				}

				return null;
			}
		);

		return array(
			'count'         => $count,
			'vimeo_missing' => array_values( $missing ),
		);
	}

	/**
	 * Register the cron hook for cleanup on deactivation.
	 *
	 * @param string[] $hooks Hooks.
	 * @return string[]
	 */
	public static function cron_hooks( $hooks ): array {
		$hooks   = (array) $hooks;
		$hooks[] = self::CRON_HOOK;
		return $hooks;
	}

	/**
	 * Cache key of a Vimeo video.
	 *
	 * @param string $id   Id.
	 * @param string $hash Privacy hash.
	 */
	public static function vimeo_key( string $id, string $hash = '' ): string {
		return '' === $hash ? $id : $id . ':' . $hash;
	}

	/**
	 * Whether a URL is a Vimeo thumbnail served from i.vimeocdn.com over HTTPS.
	 *
	 * @param string $url URL.
	 */
	public static function is_vimeo_thumbnail( string $url ): bool {
		return (bool) preg_match( '#^https://i\.vimeocdn\.com/[A-Za-z0-9/_\-.~%?=&]+$#', $url );
	}

	/**
	 * Thumbnail store: thumbs (key => [ url, title, at ]), failed (key => timestamp), pending (key => [ id, hash ]).
	 *
	 * @return array{thumbs:array<string,array<string,mixed>>,failed:array<string,int>,pending:array<string,array{0:string,1:string}>}
	 */
	public static function store(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'thumbs'  => is_array( $raw['thumbs'] ?? null ) ? $raw['thumbs'] : array(),
			'failed'  => is_array( $raw['failed'] ?? null ) ? $raw['failed'] : array(),
			'pending' => is_array( $raw['pending'] ?? null ) ? $raw['pending'] : array(),
		);
	}

	/**
	 * Queue Vimeo videos whose thumbnail is unknown (at most one option write per request).
	 *
	 * @param array<int,array{0:string,1:string}> $videos [ id, hash ] pairs.
	 */
	private static function queue_thumbnails( array $videos ): void {
		$store   = self::store();
		$changed = false;
		foreach ( $videos as $video ) {
			$key = self::vimeo_key( (string) $video[0], (string) $video[1] );
			if ( isset( $store['pending'][ $key ] ) || isset( $store['thumbs'][ $key ] ) ) {
				continue;
			}
			if ( isset( $store['failed'][ $key ] ) && (int) $store['failed'][ $key ] > time() - DAY_IN_SECONDS ) {
				continue;
			}
			if ( count( $store['pending'] ) >= 50 ) {
				break;
			}
			$store['pending'][ $key ] = array( (string) $video[0], (string) $video[1] );
			$changed                  = true;
		}
		if ( $changed ) {
			update_option( self::OPTION, $store, false );
			Scheduler::async( self::CRON_HOOK, array(), 10 );
		}
	}

	/**
	 * Cron: fetch Vimeo thumbnails through the oEmbed API.
	 */
	public static function fetch_thumbnails(): void {
		$store = self::store();
		$done  = 0;
		foreach ( $store['pending'] as $key => $video ) {
			if ( $done >= 10 ) {
				break;
			}
			++$done;
			unset( $store['pending'][ $key ] );

			$id   = preg_replace( '/\D/', '', (string) ( $video[0] ?? '' ) );
			$hash = preg_replace( '/[^a-f0-9]/i', '', (string) ( $video[1] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}

			$response = wp_safe_remote_get(
				'https://vimeo.com/api/oembed.json?width=640&url=' . rawurlencode( Facades::vimeo_watch_url( $id, $hash ) ),
				array(
					'timeout'             => 8,
					'redirection'         => 1,
					'limit_response_size' => 65536,
				)
			);
			$data     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$url      = is_array( $data ) ? (string) ( $data['thumbnail_url'] ?? '' ) : '';

			if ( self::is_vimeo_thumbnail( $url ) ) {
				$store['thumbs'][ $key ] = array(
					'url'   => $url,
					'title' => is_array( $data ) ? substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 150 ) : '',
					'at'    => time(),
				);
				unset( $store['failed'][ $key ] );
			} else {
				$store['failed'][ $key ] = time();
			}
		}

		if ( count( $store['thumbs'] ) > self::MAX_THUMBS ) {
			$store['thumbs'] = array_slice( $store['thumbs'], -self::MAX_THUMBS, null, true );
		}
		if ( count( $store['failed'] ) > self::MAX_THUMBS ) {
			$store['failed'] = array_slice( $store['failed'], -self::MAX_THUMBS, null, true );
		}
		update_option( self::OPTION, $store, false );

		if ( ! empty( $store['pending'] ) ) {
			Scheduler::async( self::CRON_HOOK, array(), 60 );
		}
	}
}
