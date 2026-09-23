<?php
/**
 * Performance scanner (background job "scan").
 *
 * SCAN → ANALYZE → CLASSIFY: detects the environment, samples important URLs,
 * analyzes them (assets, images, fonts, third-party scripts, queries),
 * inspects the database and media library, optionally measures pages in the
 * administrator's browser, runs every optimization's detection logic and
 * turns everything into findings and decisions.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Core\Jobs\Job;
use SH\SpeedOptimizer\Core\Jobs\JobHandlerInterface;
use SH\SpeedOptimizer\Core\Jobs\StepResult;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\State;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Decision;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 */
final class Scanner implements JobHandlerInterface {

	public const OPTION    = 'shso_scan';
	public const MAX_PAGES = 6;
	public const DESKTOP   = array(
		'w' => 1350,
		'h' => 900,
	);
	public const MOBILE    = array(
		'w' => 390,
		'h' => 844,
	);

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Step ids of a full scan (also embedded into the optimize job).
	 *
	 * @param bool $browser Whether browser measurements are possible.
	 * @return string[]
	 */
	public static function scan_steps( bool $browser ): array {
		$steps = array( 'scan:environment', 'scan:urls', 'scan:pages', 'scan:database', 'scan:media' );
		if ( $browser ) {
			$steps[] = 'scan:browser';
			$steps[] = 'scan:browser_collect';
		}
		$steps[] = 'scan:assess';
		$steps[] = 'scan:finalize';
		return $steps;
	}

	/**
	 * {@inheritDoc}
	 */
	public function steps( array $args ): array {
		return self::scan_steps( ! empty( $args['browser'] ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label( string $step ): string {
		$labels = array(
			'scan:environment'     => __( 'Detecting your server, theme, plugins and caching…', 'sh-speed-optimizer' ),
			'scan:urls'            => __( 'Choosing important pages to analyze…', 'sh-speed-optimizer' ),
			'scan:pages'           => __( 'Analyzing pages (scripts, styles, images, fonts)…', 'sh-speed-optimizer' ),
			'scan:database'        => __( 'Checking the database…', 'sh-speed-optimizer' ),
			'scan:media'           => __( 'Checking the media library…', 'sh-speed-optimizer' ),
			'scan:browser'         => __( 'Measuring your pages in this browser…', 'sh-speed-optimizer' ),
			'scan:browser_collect' => __( 'Processing browser measurements…', 'sh-speed-optimizer' ),
			'scan:assess'          => __( 'Deciding which optimizations are safe for your site…', 'sh-speed-optimizer' ),
			'scan:finalize'        => __( 'Preparing your report…', 'sh-speed-optimizer' ),
		);
		return $labels[ $step ] ?? __( 'Working…', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_step( string $step, Job $job ): StepResult {
		switch ( $step ) {
			case 'scan:environment':
				$profile = $this->plugin->detector()->detect( true );
				$job->set( 'profile', $profile->to_array() );
				return StepResult::done();

			case 'scan:urls':
				$job->set( 'urls', $this->sample_urls() );
				$job->set( 'pages', array() );
				return StepResult::done();

			case 'scan:pages':
				return $this->step_pages( $job );

			case 'scan:database':
				try {
					$analysis = $this->plugin->database()->analyze();
					$job->set( 'database', $analysis );
					$job->set( 'database_findings', $this->plugin->database()->findings( $analysis ) );
				} catch ( \Throwable $e ) {
					$this->plugin->logger()->error( 'Database analysis failed.', array( 'error' => $e->getMessage() ), 'scan' );
				}
				return StepResult::done();

			case 'scan:media':
				if ( class_exists( '\SH\SpeedOptimizer\Assets\Images\ImageInspector' ) ) {
					try {
						$job->set( 'media', \SH\SpeedOptimizer\Assets\Images\ImageInspector::media_library_stats() );
					} catch ( \Throwable $e ) {
						$this->plugin->logger()->error( 'Media analysis failed.', array( 'error' => $e->getMessage() ), 'scan' );
					}
				}
				return StepResult::done();

			case 'scan:browser':
				return StepResult::await_browser( $this->browser_plan( $job, 'analyze' ) );

			case 'scan:browser_collect':
				$this->collect_browser( $job );
				return StepResult::done();

			case 'scan:assess':
				$job->set( 'decisions', $this->assess_all( $job ) );
				return StepResult::done();

			case 'scan:finalize':
				$this->finalize( $job );
				return StepResult::done();
		}

		return StepResult::done();
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( Job $job ): void {
		$summary = $job->get( 'summary', array() );
		$job->message(
			sprintf(
				/* translators: %d: number of optimizations */
				_n( 'Analysis complete. %d safe optimization is ready.', 'Analysis complete. %d safe optimizations are ready.', (int) ( $summary['ready'] ?? 0 ), 'sh-speed-optimizer' ),
				(int) ( $summary['ready'] ?? 0 )
			),
			'success'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function abort( Job $job, string $reason ): void {
		// A scan changes nothing on the site; nothing to undo.
		unset( $job, $reason );
	}

	/**
	 * Analyze the next sample URL.
	 *
	 * @param Job $job Job.
	 */
	private function step_pages( Job $job ): StepResult {
		$urls  = (array) $job->get( 'urls', array() );
		$pages = (array) $job->get( 'pages', array() );

		foreach ( $urls as $url ) {
			if ( isset( $pages[ $url ] ) ) {
				continue;
			}
			$pages[ $url ] = $this->analyze_url( (string) $url, $job->id() );
			$job->set( 'pages', $pages );
			return count( $pages ) >= count( $urls ) ? StepResult::done() : StepResult::repeat();
		}

		return StepResult::done();
	}

	/**
	 * Analyze one URL via a signed loopback request.
	 *
	 * @param string $url    URL.
	 * @param string $job_id Job id.
	 * @return array<string,mixed>
	 */
	public function analyze_url( string $url, string $job_id = '' ): array {
		$response = Loopback::get(
			$url,
			array(
				'token' => array(
					'm' => 'baseline',
					'a' => 1,
					'j' => $job_id,
				),
			)
		);

		if ( ! $response['ok'] ) {
			return array(
				'url'    => $url,
				'status' => (int) $response['status'],
				'error'  => $response['error'],
			);
		}

		$captured             = PageAnalyzer::captured( $response['token_nonce'] );
		$analysis             = PageAnalyzer::analyze( (string) $response['body'], $url, $captured, $response );
		$analysis['snapshot'] = Verifier::snapshot( $response );

		return $analysis;
	}

	/**
	 * Choose representative URLs: home, a page, a post, shop + product.
	 *
	 * @return string[]
	 */
	public function sample_urls(): array {
		$urls    = array( home_url( '/' ) );
		$exclude = array();

		foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
			$exclude[] = (int) get_option( $option );
		}
		$exclude[] = (int) get_option( 'page_on_front' );

		// A page from the primary menu (or the most recently modified page).
		$page_id   = 0;
		$locations = get_nav_menu_locations();
		foreach ( $locations as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
			foreach ( (array) $items as $item ) {
				if ( 'page' === $item->object && ! in_array( (int) $item->object_id, $exclude, true ) ) {
					$page_id = (int) $item->object_id;
					break 2;
				}
			}
		}
		if ( ! $page_id ) {
			$pages   = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'modified',
					'post__not_in'   => array_filter( $exclude ),
					'fields'         => 'ids',
					'has_password'   => false,
				)
			);
			$page_id = (int) ( $pages[0] ?? 0 );
		}
		if ( $page_id ) {
			$urls[] = (string) get_permalink( $page_id );
		}

		// Latest post.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'has_password'   => false,
			)
		);
		if ( ! empty( $posts ) ) {
			$urls[] = (string) get_permalink( (int) $posts[0] );
		}

		// Pages built with Elementor are worth a separate look.
		$builder = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'post__not_in'   => array_filter( array_merge( $exclude, array( $page_id ) ) ),
				'has_password'   => false,
			)
		);
		if ( ! empty( $builder ) ) {
			$urls[] = (string) get_permalink( (int) $builder[0] );
		}

		// WooCommerce shop and a product.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop = (int) wc_get_page_id( 'shop' );
			if ( $shop > 0 ) {
				$urls[] = (string) get_permalink( $shop );
			}
			$products = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( ! empty( $products ) ) {
				$urls[] = (string) get_permalink( (int) $products[0] );
			}
		}

		/**
		 * Filters the URLs analyzed by scans and used for verification.
		 *
		 * @param string[] $urls URLs of this site.
		 */
		$urls = (array) apply_filters( 'shso_sample_urls', $urls );

		$clean = array();
		foreach ( $urls as $url ) {
			$url = (string) $url;
			if ( '' !== $url && Loopback::is_own_url( $url ) && ! in_array( $url, $clean, true ) ) {
				$clean[] = $url;
			}
		}

		return array_slice( $clean, 0, self::MAX_PAGES );
	}

	/**
	 * Build a browser test plan for sample URLs.
	 *
	 * @param Job                 $job     Job.
	 * @param string              $purpose analyze|verify.
	 * @param array<string,mixed> $token   Extra token fields (mode/ids).
	 * @param string              $prefix  Key prefix.
	 * @param int                 $max     Max URLs.
	 * @return array<int,array<string,mixed>>
	 */
	public function browser_plan( Job $job, string $purpose, array $token = array( 'm' => 'baseline' ), string $prefix = 'a', int $max = 4 ): array {
		$plan = array();
		$urls = array_slice( (array) $job->get( 'urls', array() ), 0, $max );

		foreach ( $urls as $index => $url ) {
			$viewports = array( 'd' => self::DESKTOP );
			if ( 0 === $index ) {
				$viewports['m'] = self::MOBILE;
			}
			foreach ( $viewports as $vp_key => $viewport ) {
				$payload = array_merge(
					$token,
					array(
						'p' => 1,
						'u' => $purpose,
						'j' => $job->id(),
					)
				);
				$signed  = \SH\SpeedOptimizer\Security\Signer::sign( $payload, 1800 );
				$data    = \SH\SpeedOptimizer\Security\Signer::verify( $signed );
				$plan[]  = array(
					'key'        => $prefix . ':' . $index . ':' . $vp_key,
					'url'        => add_query_arg(
						array(
							\SH\SpeedOptimizer\Core\Context::VERIFY_PARAM => $signed,
							'shso_nc' => wp_generate_password( 6, false ),
						),
						(string) $url
					),
					'page'       => (string) $url,
					'purpose'    => $purpose,
					'viewport'   => $viewport,
					'timeout_ms' => 30000,
					'nonce'      => (string) ( $data['n'] ?? '' ),
				);
			}
		}

		return $plan;
	}

	/**
	 * Store browser measurements per template and derive page data for optimizations.
	 *
	 * @param Job $job Job.
	 */
	private function collect_browser( Job $job ): void {
		$results = $job->browser_results();
		if ( ! empty( $results['_unavailable'] ) || empty( $results ) ) {
			$job->set( 'browser_available', false );
			return;
		}

		$by_template = array();
		$page_data   = get_option( Runtime::PAGE_DATA_OPTION, array() );
		$page_data   = is_array( $page_data ) ? $page_data : array();
		$usable      = 0;

		foreach ( $results as $key => $result ) {
			if ( ! BrowserEvaluator::usable( $result ) ) {
				continue;
			}
			++$usable;
			$template = sanitize_key( (string) ( $result['template'] ?? '' ) );
			if ( '' === $template ) {
				continue;
			}
			$is_mobile = str_ends_with( (string) $key, ':m' );

			if ( ! $is_mobile || ! isset( $by_template[ $template ] ) ) {
				$by_template[ $template ] = self::compact_result( $result );
			}

			$entry = $page_data[ $template ] ?? array();
			$lcp   = (array) ( $result['lcp'] ?? array() );
			if ( ! empty( $lcp['type'] ) ) {
				$candidate = array(
					'url'        => esc_url_raw( (string) ( $lcp['url'] ?? '' ) ),
					'type'       => sanitize_key( (string) $lcp['type'] ),
					'selector'   => sanitize_text_field( (string) ( $lcp['selector'] ?? '' ) ),
					'confidence' => 70,
				);
				$previous  = (array) ( $entry['lcp'] ?? array() );
				// Same LCP element on desktop and mobile → high confidence.
				if ( ! empty( $previous['url'] ) && $previous['url'] === $candidate['url'] && ! empty( $entry['_scan'] ) && $entry['_scan'] === $job->id() ) {
					$candidate['confidence'] = 95;
				} elseif ( ! empty( $previous ) && ( $entry['_scan'] ?? '' ) === $job->id() ) {
					// Different element per viewport: keep the desktop one but mark low confidence.
					$candidate               = $previous;
					$candidate['confidence'] = min( 60, (int) ( $previous['confidence'] ?? 60 ) );
				} elseif ( 'img' === $candidate['type'] && ! empty( $lcp['in_viewport'] ) ) {
					$candidate['confidence'] = 85;
				}
				$entry['lcp'] = $candidate;
			}

			$above = array_map( 'esc_url_raw', array_slice( (array) ( $result['images']['above_fold'] ?? array() ), 0, 12 ) );
			if ( ( $entry['_scan'] ?? '' ) === $job->id() ) {
				$above = array_values( array_unique( array_merge( (array) ( $entry['above_fold_images'] ?? array() ), $above ) ) );
			}
			$entry['above_fold_images'] = array_slice( $above, 0, 16 );

			$fonts = array();
			foreach ( array_slice( (array) ( $result['fonts']['preload_candidates'] ?? array() ), 0, 2 ) as $font ) {
				if ( ! empty( $font['url'] ) ) {
					$fonts[] = array(
						'url'    => esc_url_raw( (string) $font['url'] ),
						'type'   => 'font/woff2',
						'family' => sanitize_text_field( (string) ( $font['family'] ?? '' ) ),
						'weight' => sanitize_text_field( (string) ( $font['weight'] ?? '' ) ),
						'style'  => sanitize_text_field( (string) ( $font['style'] ?? '' ) ),
					);
				}
			}
			$entry['fonts_preload'] = $fonts;

			$media_in_view = (array) ( $result['dom']['media_in_view'] ?? array() );
			$same_scan     = ( $entry['_scan'] ?? '' ) === $job->id();
			// A map/video visible without scrolling on any viewport is treated as primary content.
			$entry['map_in_viewport']   = ! empty( $media_in_view['map'] ) || ( $same_scan && ! empty( $entry['map_in_viewport'] ) );
			$entry['video_in_viewport'] = ! empty( $media_in_view['video'] ) || ( $same_scan && ! empty( $entry['video_in_viewport'] ) );
			$entry['measured_at']       = time();
			$entry['_scan']             = $job->id();

			$page_data[ $template ] = $entry;
		}

		update_option( Runtime::PAGE_DATA_OPTION, array_slice( $page_data, -50, null, true ), false );
		$job->set( 'browser', $by_template );
		$job->set( 'browser_available', $usable > 0 );

		foreach ( $by_template as $template => $result ) {
			if ( ! empty( $result['lcp']['ms'] ) ) {
				$this->plugin->metrics()->record( 'browser', 'lcp', (float) $result['lcp']['ms'], $template );
			}
			$this->plugin->metrics()->record( 'browser', 'cls', (float) ( $result['cls'] ?? 0 ), $template );
			if ( ! empty( $result['fcp_ms'] ) ) {
				$this->plugin->metrics()->record( 'browser', 'fcp', (float) $result['fcp_ms'], $template );
			}
		}
	}

	/**
	 * Keep only what diagnostics need from a probe result.
	 *
	 * @param array<string,mixed> $result Probe result.
	 * @return array<string,mixed>
	 */
	public static function compact_result( array $result ): array {
		return array(
			'template'  => (string) ( $result['template'] ?? '' ),
			'url'       => (string) ( $result['url'] ?? '' ),
			'lcp'       => $result['lcp'] ?? null,
			'cls'       => (float) ( $result['cls'] ?? 0 ),
			'fcp_ms'    => $result['fcp_ms'] ?? null,
			'errors'    => array_slice( (array) ( $result['errors'] ?? array() ), 0, 10 ),
			'dom'       => $result['dom'] ?? null,
			'images'    => $result['images'] ?? null,
			'fonts'     => $result['fonts'] ?? null,
			'css'       => $result['css'] ?? null,
			'resources' => $result['resources'] ?? null,
			'longtasks' => $result['longtasks'] ?? null,
		);
	}

	/**
	 * Run detection + decision for every optimization.
	 *
	 * @param Job $job Job.
	 * @return array<string,array<string,mixed>>
	 */
	public function assess_all( Job $job ): array {
		$context      = $this->assessment_context( $job );
		$capabilities = array(
			'loopback' => (bool) ( $context->profile->get( 'loopback.ok' ) ?? false ),
			'browser'  => (bool) $job->get( 'browser_available', false ) || (bool) $job->arg( 'browser', false ),
		);
		$job->set( 'capabilities', $capabilities );

		$decisions = array();
		foreach ( $this->plugin->registry()->all() as $id => $optimization ) {
			try {
				$assessment = $optimization->assess( $context );
			} catch ( \Throwable $e ) {
				$assessment          = Assessment::make( false, 0, Assessment::BENEFIT_NONE );
				$assessment->blocked = __( 'Detection failed, so this optimization is skipped for safety.', 'sh-speed-optimizer' );
				$this->plugin->logger()->error(
					'Optimization detection failed.',
					array(
						'optimization' => $id,
						'error'        => $e->getMessage(),
					),
					'scan'
				);
			}

			$decision = $this->plugin->decisions()->decide( $optimization, $assessment, $capabilities );

			$decisions[ $id ] = array(
				'id'          => $id,
				'name'        => $optimization->name(),
				'description' => $optimization->description(),
				'category'    => $optimization->category(),
				'risk'        => $optimization->risk(),
				'level'       => $optimization->level(),
				'assessment'  => $assessment->to_array(),
				'decision'    => $decision->to_array(),
			);

			$this->plugin->logger()->debug(
				'Decision: ' . $id . ' → ' . $decision->action,
				array(
					'confidence' => $decision->confidence,
					'benefit'    => $decision->benefit,
					'summary'    => $decision->summary,
				),
				'engine'
			);
		}

		return $decisions;
	}

	/**
	 * Build the assessment context from job data.
	 *
	 * @param Job $job Job.
	 */
	public function assessment_context( Job $job ): AssessmentContext {
		$profile = new SiteProfile( (array) $job->get( 'profile', array() ) );
		$pages   = (array) $job->get( 'pages', array() );
		$browser = (array) $job->get( 'browser', array() );

		if ( empty( $browser ) ) {
			// Reuse measurements from an earlier scan.
			$previous = get_option( self::OPTION, array() );
			$browser  = is_array( $previous ) ? (array) ( $previous['browser'] ?? array() ) : array();
		}

		return new AssessmentContext( $profile, $this->plugin->compatibility()->rules(), $this->plugin->settings(), $pages, $browser );
	}

	/**
	 * Build findings, health and summary; persist the scan.
	 *
	 * @param Job $job Job.
	 */
	public function finalize( Job $job ): void {
		$decisions = (array) $job->get( 'decisions', array() );
		$pages     = (array) $job->get( 'pages', array() );
		$browser   = (array) $job->get( 'browser', array() );

		if ( empty( $browser ) ) {
			$previous = get_option( self::OPTION, array() );
			$browser  = is_array( $previous ) ? (array) ( $previous['browser'] ?? array() ) : array();
		}

		$query_findings = array();
		if ( class_exists( '\SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector' ) ) {
			$reports = array();
			foreach ( $pages as $url => $page ) {
				if ( ! empty( $page['queries'] ) ) {
					$reports[ $url ] = $page['queries'];
				}
			}
			try {
				$query_findings = \SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector::findings( $reports );
			} catch ( \Throwable $e ) {
				$query_findings = array();
			}
		}

		$cache_status = array();
		try {
			$cache_status = $this->plugin->cache()->status();
		} catch ( \Throwable $e ) {
			$cache_status = array();
		}

		$findings = ( new FindingsBuilder() )->build(
			array(
				'profile'           => (array) $job->get( 'profile', array() ),
				'pages'             => $pages,
				'browser'           => $browser,
				'database_findings' => (array) $job->get( 'database_findings', array() ),
				'query_findings'    => $query_findings,
				'media'             => (array) $job->get( 'media', array() ),
				'decisions'         => $decisions,
				'cache'             => $cache_status,
				'active'            => $this->plugin->state()->active_ids(),
			)
		);

		$health = HealthScore::calculate( $findings );
		$ready  = array();
		foreach ( $decisions as $id => $entry ) {
			if ( in_array( $entry['decision']['action'] ?? '', array( Decision::AUTO_APPLY, Decision::TEST_THEN_APPLY ), true ) ) {
				$ready[] = array(
					'id'       => $id,
					'name'     => $entry['name'],
					'category' => $entry['category'],
					'risk'     => $entry['risk'],
					'action'   => $entry['decision']['action'],
				);
			}
		}

		// Strip heavy per-page data before persisting (assets lists are kept for developer diagnostics).
		$stored_pages = array();
		foreach ( $pages as $url => $page ) {
			unset( $page['snapshot'] );
			$stored_pages[ $url ] = $page;
		}

		$scan = array(
			'at'           => time(),
			'job'          => $job->id(),
			'profile'      => (array) $job->get( 'profile', array() ),
			'urls'         => (array) $job->get( 'urls', array() ),
			'pages'        => $stored_pages,
			'browser'      => $browser,
			'database'     => (array) $job->get( 'database', array() ),
			'media'        => (array) $job->get( 'media', array() ),
			'decisions'    => $decisions,
			'findings'     => $findings,
			'health'       => $health,
			'ready'        => $ready,
			'capabilities' => (array) $job->get( 'capabilities', array() ),
		);
		update_option( self::OPTION, $scan, false );

		foreach ( $pages as $page ) {
			if ( ! empty( $page['generation_ms'] ) ) {
				$this->plugin->metrics()->record( 'scan', 'generation_ms', (float) $page['generation_ms'], (string) ( $page['template'] ?? '' ) );
			}
		}
		$ttfb = (int) ( $scan['profile']['loopback']['ttfb_ms'] ?? 0 );
		if ( $ttfb > 0 ) {
			$this->plugin->metrics()->record( 'scan', 'ttfb', (float) $ttfb, 'front_page' );
		}

		$state = $this->plugin->state();
		$state->set( 'last_scan_at', time() );
		if ( State::ONBOARDING_NEW === $state->get( 'onboarding' ) ) {
			$state->set( 'onboarding', State::ONBOARDING_SCANNED );
		}

		$job->set(
			'summary',
			array(
				'ready'        => count( $ready ),
				'ready_items'  => $ready,
				'issues'       => (int) $health['improvements'],
				'health_after' => $health['score'],
				'applied'      => array(),
				'rolled_back'  => array(),
				'recommended'  => array(),
			)
		);
	}

	/**
	 * Last persisted scan.
	 *
	 * @return array<string,mixed>
	 */
	public static function last(): array {
		$scan = get_option( self::OPTION, array() );
		return is_array( $scan ) ? $scan : array();
	}
}
