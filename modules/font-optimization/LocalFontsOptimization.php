<?php
/**
 * Host Google Fonts on this server (only with the administrator's permission).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\FontOptimization;

use SH\SpeedOptimizer\Assets\Fonts\FontLocalizer;
use SH\SpeedOptimizer\Assets\Fonts\GoogleFonts;
use SH\SpeedOptimizer\Assets\Fonts\LocalFontsRewriter;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Core\Scheduler;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Local Google Fonts.
 */
final class LocalFontsOptimization extends AbstractOptimization {

	public const CRON_HOOK = 'shso_localize_fonts';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'localize_google_fonts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Host Google Fonts on your server', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Copies the Google Fonts your site uses to your own server, so visitors no longer connect to Google to load them. This is usually faster and better for privacy. Only happens after you allow font downloads.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::FONTS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::LOW;
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
		return array( self::REQ_EXTERNAL_DOWNLOAD );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$urls = self::urls_from_pages( $context->pages() );
		if ( empty( $urls ) ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'Your pages do not use Google Fonts.', 'sh-speed-optimizer' ) ),
				$context,
				'font_optimization'
			);
		}

		$assessment       = Assessment::make( true, 85, Assessment::BENEFIT_MEDIUM );
		$assessment->data = array(
			'stylesheets' => count( $urls ),
			'duplicates'  => GoogleFonts::duplicates( $urls ),
		);
		$assessment->note( __( 'Visitors\' browsers currently connect to Google to load your fonts.', 'sh-speed-optimizer' ) );
		if ( ! $context->settings->get( 'localize_fonts' ) ) {
			$assessment->note( __( 'Needs your permission: allow "Localize Fonts" in the settings.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'font_optimization' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply() {
		$scan = get_option( 'shso_scan', array() );
		if ( is_array( $scan ) && is_array( $scan['pages'] ?? null ) ) {
			FontLocalizer::remember( self::urls_from_pages( $scan['pages'] ) );
		}
		Scheduler::async( self::CRON_HOOK, array(), 5 );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, array(), Scheduler::GROUP );
		}
		$this->plugin->filesystem()->delete_tree( Filesystem::cache_root() . 'fonts' );
		delete_option( FontLocalizer::OPTION );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		add_filter( 'shso_cron_hooks', array( self::class, 'cron_hooks' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_job' ) );

		$runtime->add_html_transform(
			$this->id(),
			function ( HtmlDocument $doc ) {
				if ( ! $this->allowed() || ! $doc->contains( 'fonts.googleapis.com' ) ) {
					return;
				}
				$root     = Filesystem::cache_root() . 'fonts/';
				$rewriter = new LocalFontsRewriter(
					FontLocalizer::mapping(),
					Filesystem::cache_url() . 'fonts/',
					static function ( string $relative ) use ( $root ): bool {
						return is_file( $root . $relative );
					}
				);
				$rewriter->transform( $doc );

				$schedule = false;
				if ( ! empty( $rewriter->missing ) ) {
					FontLocalizer::reset( $rewriter->missing );
					$schedule = true;
				}
				if ( ! empty( $rewriter->unknown ) && FontLocalizer::remember( $rewriter->unknown ) > 0 ) {
					$schedule = true;
				}
				if ( $schedule ) {
					Scheduler::async( self::CRON_HOOK, array(), 10 );
				}
			},
			30
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
	 * Cron: download pending stylesheets.
	 */
	public function run_job(): void {
		if ( ! $this->allowed() ) {
			return;
		}
		$before = self::ok_count( FontLocalizer::mapping() );

		try {
			$remaining = ( new FontLocalizer( $this->plugin->filesystem() ) )->run( 3 );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'Font localization failed.', array( 'error' => $e->getMessage() ), 'fonts' );
			return;
		}

		if ( $remaining > 0 ) {
			Scheduler::async( self::CRON_HOOK, array(), 60 );
		} else {
			$retry = FontLocalizer::next_retry( FontLocalizer::mapping() );
			if ( null !== $retry ) {
				Scheduler::async( self::CRON_HOOK, array(), max( 60, $retry - time() ) );
			}
		}

		if ( self::ok_count( FontLocalizer::mapping() ) > $before ) {
			/**
			 * Fires after Google Fonts were downloaded and pages can use the local copies.
			 */
			do_action( 'shso_local_fonts_updated' );
			// Cached pages still reference Google: regenerate them with the local copies.
			try {
				$this->plugin->cache()->purge_all( 'local_fonts' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		$map     = FontLocalizer::mapping();
		$details = array(
			'stylesheets' => count( $map ),
			'localized'   => 0,
			'pending'     => 0,
			'failed'      => 0,
			'files'       => 0,
			'bytes'       => 0,
			'errors'      => array(),
			'permission'  => $this->allowed(),
		);
		foreach ( $map as $entry ) {
			switch ( $entry['status'] ?? '' ) {
				case FontLocalizer::STATUS_OK:
					++$details['localized'];
					$details['files'] += (int) ( $entry['files'] ?? 0 );
					$details['bytes'] += (int) ( $entry['bytes'] ?? 0 );
					break;
				case FontLocalizer::STATUS_FAILED:
					++$details['failed'];
					if ( count( $details['errors'] ) < 3 && ! empty( $entry['error'] ) ) {
						$details['errors'][] = (string) $entry['error'];
					}
					break;
				default:
					++$details['pending'];
			}
		}
		return $details;
	}

	/**
	 * Whether the administrator allowed font downloads.
	 */
	private function allowed(): bool {
		return (bool) $this->plugin->settings()->get( 'localize_fonts' );
	}

	/**
	 * Google Fonts stylesheet URLs from page analyses.
	 *
	 * @param array<mixed> $pages Page analyses.
	 * @return string[]
	 */
	private static function urls_from_pages( array $pages ): array {
		$urls = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( is_array( $page ) ? ( $page['fonts']['google'] ?? array() ) : array() ) as $font ) {
				$url = is_array( $font ) ? (string) ( $font['url'] ?? '' ) : (string) $font;
				if ( GoogleFonts::is_css_url( $url ) ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Number of localized stylesheets.
	 *
	 * @param array<string,array<string,mixed>> $map Mapping.
	 */
	private static function ok_count( array $map ): int {
		$count = 0;
		foreach ( $map as $entry ) {
			if ( FontLocalizer::STATUS_OK === ( $entry['status'] ?? '' ) ) {
				++$count;
			}
		}
		return $count;
	}
}
