<?php
/**
 * Optimization Engine.
 *
 * SCAN → ANALYZE → CLASSIFY → OPTIMIZE → VERIFY → KEEP OR ROLLBACK
 *
 * Runs as background job "optimize": snapshot the configuration, apply one
 * optimization group at a time, verify every group on the server (loopback)
 * and in the administrator's browser, and roll back what causes a
 * regression — retrying group members individually so good ones are kept.
 * Job "verify" re-checks active optimizations periodically.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

use SH\SpeedOptimizer\Core\Jobs\Job;
use SH\SpeedOptimizer\Core\Jobs\JobHandlerInterface;
use SH\SpeedOptimizer\Core\Jobs\StepResult;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\State;
use SH\SpeedOptimizer\Diagnostics\BrowserEvaluator;
use SH\SpeedOptimizer\Diagnostics\FindingsBuilder;
use SH\SpeedOptimizer\Diagnostics\HealthScore;
use SH\SpeedOptimizer\Diagnostics\Loopback;
use SH\SpeedOptimizer\Diagnostics\Scanner;
use SH\SpeedOptimizer\Diagnostics\Verifier;
use SH\SpeedOptimizer\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Engine.
 */
final class Engine implements JobHandlerInterface {

	/**
	 * Categories verified in the browser when possible.
	 */
	private const BROWSER_CATEGORIES = array( Category::IMAGES, Category::FONTS, Category::CSS, Category::JAVASCRIPT, Category::THIRD_PARTY );

	/**
	 * Number of URLs used for verification.
	 */
	private const VERIFY_URLS = 3;

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

	// ------------------------------------------------------------------
	// Job handler.
	// ------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Job arguments.
	 */
	public function steps( array $args ): array {
		if ( ! empty( $args['health'] ) ) {
			return array( 'health:prepare', 'health:check', 'health:finish' );
		}
		if ( ! empty( $args['only'] ) ) {
			return array( 'opt:prepare', 'opt:plan' );
		}
		return array_merge( Scanner::scan_steps( ! empty( $args['browser'] ) ), array( 'opt:plan' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $step Step id.
	 */
	public function label( string $step ): string {
		if ( str_starts_with( $step, 'scan:' ) ) {
			return $this->plugin->scanner()->label( $step );
		}

		$parts    = explode( ':', $step );
		$category = isset( $parts[2] ) ? Category::label( $parts[2] ) : '';

		switch ( $parts[0] . ':' . ( $parts[1] ?? '' ) ) {
			case 'opt:prepare':
				return __( 'Preparing…', 'sh-speed-optimizer' );
			case 'opt:plan':
				return __( 'Planning safe optimizations and saving a restore point…', 'sh-speed-optimizer' );
			case 'opt:baseline':
				return __( 'Recording how your pages look before any change…', 'sh-speed-optimizer' );
			case 'opt:apply':
				/* translators: %s: optimization group */
				return sprintf( __( 'Applying %s optimizations…', 'sh-speed-optimizer' ), $category );
			case 'opt:assets':
				return __( 'Generating optimized copies of CSS and JavaScript files…', 'sh-speed-optimizer' );
			case 'opt:critical':
			case 'opt:criticalsave':
				return __( 'Generating critical CSS in this browser…', 'sh-speed-optimizer' );
			case 'opt:verify':
			case 'opt:isolate':
				/* translators: %s: optimization group */
				return sprintf( __( 'Checking your pages after %s changes…', 'sh-speed-optimizer' ), $category );
			case 'opt:browser':
			case 'opt:beval':
			case 'opt:bisolate':
			case 'opt:bisoeval':
				/* translators: %s: optimization group */
				return sprintf( __( 'Testing %s changes in this browser…', 'sh-speed-optimizer' ), $category );
			case 'opt:finish':
				return __( 'Finishing up and warming the cache…', 'sh-speed-optimizer' );
			case 'health:prepare':
			case 'health:check':
			case 'health:finish':
				return __( 'Checking that all optimizations still work…', 'sh-speed-optimizer' );
		}

		return __( 'Working…', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $step Step id.
	 * @param Job    $job  Job.
	 */
	public function run_step( string $step, Job $job ): StepResult {
		if ( str_starts_with( $step, 'scan:' ) ) {
			return $this->plugin->scanner()->run_step( $step, $job );
		}

		$parts    = explode( ':', $step );
		$action   = $parts[0] . ':' . ( $parts[1] ?? '' );
		$category = (string) ( $parts[2] ?? '' );
		$extra    = (string) ( $parts[3] ?? '' );

		switch ( $action ) {
			case 'opt:prepare':
				return $this->step_prepare( $job );
			case 'opt:plan':
				return $this->step_plan( $job );
			case 'opt:baseline':
				return $this->step_baseline( $job );
			case 'opt:critical':
				return $this->step_critical( $job, $category );
			case 'opt:criticalsave':
				return $this->step_critical_save( $job, $category );
			case 'opt:apply':
				return $this->step_apply( $job, $category );
			case 'opt:assets':
				return $this->step_assets( $job );
			case 'opt:verify':
				return $this->step_verify( $job, $category );
			case 'opt:isolate':
				return $this->step_isolate( $job, $category );
			case 'opt:browser':
				return $this->step_browser( $job, $category );
			case 'opt:beval':
				return $this->step_browser_eval( $job, $category );
			case 'opt:bisolate':
				return $this->step_browser_isolate( $job, $category, $extra );
			case 'opt:bisoeval':
				return $this->step_browser_isolate_eval( $job, $category, $extra );
			case 'opt:finish':
				return $this->step_finish( $job );
			case 'health:prepare':
				return $this->step_health_prepare( $job );
			case 'health:check':
				return $this->step_health_check( $job );
			case 'health:finish':
				return $this->step_health_finish( $job );
		}

		return StepResult::done();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job Job.
	 */
	public function complete( Job $job ): void {
		if ( $job->arg( 'health' ) ) {
			return;
		}
		$summary = (array) $job->get( 'summary', array() );
		$applied = count( (array) ( $summary['applied'] ?? array() ) );
		$job->message(
			$applied > 0
				/* translators: %d: number of optimizations */
				? sprintf( _n( 'Optimization complete. %d optimization is now active.', 'Optimization complete. %d optimizations are now active.', $applied, 'sh-speed-optimizer' ), $applied )
				: __( 'Optimization complete. No new optimizations were needed.', 'sh-speed-optimizer' ),
			'success'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job    $job    Job.
	 * @param string $reason Reason.
	 */
	public function abort( Job $job, string $reason ): void {
		$snapshot = (int) $job->get( 'snapshot', 0 );
		if ( $snapshot > 0 && ! $job->get( 'finished' ) ) {
			$result = $this->plugin->snapshots()->restore( $snapshot );
			$this->plugin->logger()->event(
				'rolled_back',
				__( 'The optimization run was interrupted; the previous configuration was restored.', 'sh-speed-optimizer' ),
				'',
				array(
					'reason'   => $reason,
					'restored' => true === $result,
				),
				'warning'
			);
		}
	}

	// ------------------------------------------------------------------
	// Optimize steps.
	// ------------------------------------------------------------------

	/**
	 * Manual run: reuse the last scan and assess only the requested optimizations.
	 *
	 * @param Job $job Job.
	 */
	private function step_prepare( Job $job ): StepResult {
		$scan = Scanner::last();

		$job->set( 'urls', ! empty( $scan['urls'] ) ? (array) $scan['urls'] : $this->plugin->scanner()->sample_urls() );
		$job->set( 'pages', (array) ( $scan['pages'] ?? array() ) );
		$job->set( 'browser', (array) ( $scan['browser'] ?? array() ) );
		$job->set( 'health_before', $scan['health']['score'] ?? null );

		$profile = ! empty( $scan['profile'] ) ? (array) $scan['profile'] : $this->plugin->detector()->detect( true )->to_array();
		$job->set( 'profile', $profile );

		$context      = $this->plugin->scanner()->assessment_context( $job );
		$capabilities = array(
			'loopback' => (bool) ( $profile['loopback']['ok'] ?? false ),
			'browser'  => (bool) $job->arg( 'browser', false ),
		);
		$job->set( 'capabilities', $capabilities );

		$decisions = array();
		foreach ( (array) $job->arg( 'only', array() ) as $id ) {
			$optimization = $this->plugin->registry()->get( (string) $id );
			if ( null === $optimization ) {
				continue;
			}
			try {
				$assessment = $optimization->assess( $context );
			} catch ( \Throwable $e ) {
				$assessment          = Assessment::make( false, 0, Assessment::BENEFIT_NONE );
				$assessment->blocked = __( 'Detection failed.', 'sh-speed-optimizer' );
			}

			$decision = $this->plugin->decisions()->decide( $optimization, $assessment, $capabilities );

			// A manual request may override confidence thresholds, never hard blocks.
			if ( $job->arg( 'manual' ) && Decision::RECOMMEND === $decision->action && null === $assessment->handled_by && null === $assessment->blocked && $assessment->applicable ) {
				$allowed = Risk::LEVEL_EXPERIMENTAL !== $optimization->level() || $this->plugin->settings()->get( 'advanced_optimizations' );
				$needs   = $optimization->requirements();
				if ( in_array( OptimizationInterface::REQ_SERVER_CONFIG, $needs, true ) && ( ! $this->plugin->settings()->get( 'allow_server_config' ) || null !== Capabilities::server_files_blocked_reason( false ) ) ) {
					$allowed = false;
				}
				if ( in_array( OptimizationInterface::REQ_EXTERNAL_DOWNLOAD, $needs, true ) && ! $this->plugin->settings()->get( 'localize_fonts' ) ) {
					$allowed = false;
				}
				if ( in_array( OptimizationInterface::REQ_BROWSER, $needs, true ) && ! $capabilities['browser'] ) {
					$allowed = false;
				}
				if ( $allowed ) {
					$decision = new Decision( Decision::TEST_THEN_APPLY, __( 'Requested manually — tested before it is kept.', 'sh-speed-optimizer' ), $decision->confidence, $decision->risk, $decision->benefit, $decision->reasons );
				}
			}

			if ( ! $decision->is_automatic() ) {
				$job->message( $optimization->name() . ': ' . $decision->summary, 'warning' );
			}

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
		}

		$job->set( 'decisions', $decisions );
		return StepResult::done();
	}

	/**
	 * Choose what to apply, snapshot, and schedule the group steps.
	 *
	 * @param Job $job Job.
	 */
	private function step_plan( Job $job ): StepResult {
		if ( null === $job->get( 'health_before' ) ) {
			$scan = Scanner::last();
			$job->set( 'health_before', $scan['health']['score'] ?? null );
		}

		$registry = $this->plugin->registry();

		// Avoid double optimization: turn off active optimizations another system now provides.
		foreach ( (array) $job->get( 'decisions', array() ) as $id => $entry ) {
			$handled_by = $entry['assessment']['handled_by'] ?? null;
			if ( null !== $handled_by && '' !== (string) $handled_by && $this->plugin->state()->is_active( (string) $id ) ) {
				$this->rollback(
					(string) $id,
					/* translators: %s: name of another plugin or service */
					sprintf( __( 'Now handled by %s.', 'sh-speed-optimizer' ), (string) $handled_by ),
					'handled_elsewhere',
					false,
					$job
				);
			}
		}

		$candidates = array();
		foreach ( (array) $job->get( 'decisions', array() ) as $id => $entry ) {
			$action = (string) ( $entry['decision']['action'] ?? '' );
			if ( in_array( $action, array( Decision::AUTO_APPLY, Decision::TEST_THEN_APPLY ), true ) && ! $this->plugin->state()->is_active( (string) $id ) ) {
				$optimization = $registry->get( (string) $id );
				if ( null !== $optimization ) {
					$candidates[ $id ] = $optimization;
				}
			}
		}

		$ordered = $this->plugin->decisions()->order( $candidates, $this->plugin->state()->active_ids() );
		$groups  = array();
		foreach ( Category::apply_order() as $category ) {
			foreach ( $ordered as $id => $optimization ) {
				if ( $optimization->category() === $category ) {
					$groups[ $category ][] = $id;
				}
			}
		}

		$job->set( 'groups', $groups );
		$job->set( 'applied', array() );
		$job->set( 'rolled_back', array() );

		if ( empty( $groups ) ) {
			$job->add_steps( array( 'opt:finish' ) );
			return StepResult::done();
		}

		$job->set(
			'snapshot',
			$this->plugin->snapshots()->create(
				$job->arg( 'manual' ) ? __( 'Before a manual change', 'sh-speed-optimizer' ) : __( 'Before automatic optimization', 'sh-speed-optimizer' ),
				$job->arg( 'manual' ) ? 'manual' : 'optimize'
			)
		);

		$steps = array( 'opt:baseline' );
		foreach ( $groups as $category => $ids ) {
			if ( in_array( 'critical_css', $ids, true ) ) {
				// Critical CSS is generated in the browser before it can be applied.
				$steps[] = 'opt:critical:' . $category;
				$steps[] = 'opt:criticalsave:' . $category;
			}
			$steps[] = 'opt:apply:' . $category;
			if ( in_array( $category, array( Category::CSS, Category::JAVASCRIPT ), true ) ) {
				$steps[] = 'opt:assets:' . $category;
			}
			$steps[] = 'opt:verify:' . $category;
			if ( $this->group_wants_browser( $ids ) ) {
				$steps[] = 'opt:browser:' . $category;
				$steps[] = 'opt:beval:' . $category;
			}
		}
		$steps[] = 'opt:finish';
		$job->add_steps( $steps );

		return StepResult::done();
	}

	/**
	 * Record baseline snapshots of the verification URLs.
	 *
	 * @param Job $job Job.
	 */
	private function step_baseline( Job $job ): StepResult {
		$baselines = (array) $job->get( 'baselines', array() );
		foreach ( $this->verify_urls( $job ) as $url ) {
			if ( isset( $baselines[ $url ] ) ) {
				continue;
			}
			$baselines[ $url ] = Verifier::snapshot( Loopback::get( $url, array( 'token' => array( 'm' => 'baseline' ) ) ) );
			$job->set( 'baselines', $baselines );
			return StepResult::repeat();
		}

		$usable = array_filter( $baselines, static fn( $b ) => ! empty( $b['ok'] ) && (int) $b['status'] < 500 );
		$job->set( 'server_verification', ! empty( $usable ) );
		if ( empty( $usable ) ) {
			$job->message( __( 'Your server blocks requests to itself, so pages can only be checked in this browser.', 'sh-speed-optimizer' ), 'warning' );
		}

		return StepResult::done();
	}

	/**
	 * Apply a group.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 */
	private function step_apply( Job $job, string $category ): StepResult {
		$ids     = (array) ( $job->get( 'groups', array() )[ $category ] ?? array() );
		$applied = array();
		$source  = $job->arg( 'manual' ) ? 'manual' : 'auto';

		// Pre-group active set: the baseline for isolating individual members later.
		$job->set( 'pre_group_' . $category, $this->plugin->state()->active_ids() );

		foreach ( $ids as $id ) {
			if ( $this->activate( (string) $id, $source, $job ) ) {
				$applied[] = $id;
			}
		}

		$job->set( 'group_active_' . $category, $applied );
		$this->plugin->runtime()->refresh();
		$this->on_configuration_changed( 'group:' . $category );

		return StepResult::done();
	}

	/**
	 * Ask the browser to generate critical CSS for the sample pages.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 */
	private function step_critical( Job $job, string $category ): StepResult {
		if ( ! $job->arg( 'browser' ) || false === $job->get( 'browser_available', null ) ) {
			$this->drop_from_group( $job, $category, 'critical_css', __( 'Critical CSS: needs your browser to generate it. Run the optimization from the dashboard.', 'sh-speed-optimizer' ) );
			$job->set( 'skip_critical_' . $category, true );
			return StepResult::done();
		}
		return StepResult::await_browser( $this->plugin->scanner()->browser_plan( $job, 'critical_css', array( 'm' => 'baseline' ), 'k', self::VERIFY_URLS ) );
	}

	/**
	 * Store critical CSS generated in the browser (per template).
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 */
	private function step_critical_save( Job $job, string $category ): StepResult {
		if ( $job->get( 'skip_critical_' . $category ) ) {
			return StepResult::done();
		}
		if ( ! class_exists( '\SH\SpeedOptimizer\Modules\CssOptimization\CriticalCssOptimization' ) ) {
			$this->drop_from_group( $job, $category, 'critical_css', __( 'Critical CSS is not available.', 'sh-speed-optimizer' ) );
			return StepResult::done();
		}

		$by_template = array();
		foreach ( $job->browser_results() as $key => $result ) {
			if ( ! is_array( $result ) || empty( $result['critical_css']['css'] ) || ! empty( $result['critical_css']['error'] ) ) {
				continue;
			}
			$template = sanitize_key( (string) ( $result['template'] ?? '' ) );
			if ( '' === $template ) {
				continue;
			}
			$by_template[ $template ][ (string) $key ] = (array) $result['critical_css'];
		}

		$stored = 0;
		foreach ( $by_template as $template => $results ) {
			ksort( $results ); // Desktop (":d") before mobile (":m").
			$css        = '';
			$widths     = array();
			$sheets     = array();
			$confidence = 92;
			foreach ( $results as $critical ) {
				$part = trim( (string) $critical['css'] );
				if ( '' !== $part && false === strpos( $css, $part ) ) {
					$css .= ( '' === $css ? '' : "\n" ) . $part;
				}
				$widths[] = (int) ( $critical['width'] ?? 0 );
				$sheets   = array_merge( $sheets, (array) ( $critical['sheets'] ?? array() ) );
				if ( ! empty( $critical['sheets_skipped'] ) || ! empty( $critical['truncated'] ) ) {
					$confidence = 60; // Cross-origin or truncated styles: not complete enough to be applied.
				}
			}
			\SH\SpeedOptimizer\Modules\CssOptimization\CriticalCssOptimization::store(
				$template,
				$css,
				$confidence,
				array(
					'viewport_widths' => array_filter( $widths ),
					'source_hash'     => \SH\SpeedOptimizer\Modules\CssOptimization\CriticalCssOptimization::source_hash( array_map( 'strval', $sheets ) ),
				)
			);
			if ( $confidence >= 90 ) {
				++$stored;
			}
		}

		if ( 0 === $stored ) {
			$this->drop_from_group( $job, $category, 'critical_css', __( 'Critical CSS: no page produced reliable critical CSS (for example because styles come from other domains).', 'sh-speed-optimizer' ) );
		}

		return StepResult::done();
	}

	/**
	 * Remove an optimization from a planned group before it is applied.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 * @param string $id       Optimization id.
	 * @param string $message  Message.
	 */
	private function drop_from_group( Job $job, string $category, string $id, string $message ): void {
		$groups = (array) $job->get( 'groups', array() );
		if ( isset( $groups[ $category ] ) ) {
			$groups[ $category ] = array_values( array_diff( (array) $groups[ $category ], array( $id ) ) );
			$job->set( 'groups', $groups );
		}
		$job->message( $message, 'warning' );
	}

	/**
	 * Pre-generate optimized CSS/JS copies for the analyzed pages so they can be verified.
	 *
	 * @param Job $job Job.
	 */
	private function step_assets( Job $job ): StepResult {
		if ( ! class_exists( '\SH\SpeedOptimizer\Assets\AssetCopies' ) || ! method_exists( '\SH\SpeedOptimizer\Assets\AssetCopies', 'generate' ) ) {
			return StepResult::done();
		}

		$done  = (array) $job->get( 'assets_done', array() );
		$start = microtime( true );

		foreach ( (array) $job->get( 'pages', array() ) as $page ) {
			foreach ( array(
				'styles'  => 'css',
				'scripts' => 'js',
			) as $key => $type ) {
				foreach ( (array) ( $page[ $key ] ?? array() ) as $asset ) {
					$url = (string) ( $asset['href'] ?? $asset['src'] ?? '' );
					if ( '' === $url || empty( $asset['local'] ) || ! empty( $asset['minified'] ) || isset( $done[ $url ] ) ) {
						continue;
					}
					$done[ $url ] = true;
					try {
						\SH\SpeedOptimizer\Assets\AssetCopies::generate( $url, $type );
					} catch ( \Throwable $e ) {
						$this->plugin->logger()->error(
							'Asset generation failed.',
							array(
								'url'   => $url,
								'error' => $e->getMessage(),
							),
							'assets'
						);
					}
					if ( count( $done ) > 80 || microtime( true ) - $start > 6 ) {
						$job->set( 'assets_done', $done );
						return count( $done ) > 80 ? StepResult::done() : StepResult::repeat();
					}
				}
			}
		}

		$job->set( 'assets_done', $done );
		return StepResult::done();
	}

	/**
	 * Server-side verification of a group.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 */
	private function step_verify( Job $job, string $category ): StepResult {
		$active_in_group = (array) $job->get( 'group_active_' . $category, array() );
		if ( empty( $active_in_group ) ) {
			return StepResult::done();
		}

		if ( ! $job->get( 'server_verification' ) ) {
			// Only safe-risk optimizations may stay without any verification; others rely on the browser step.
			if ( ! $this->group_wants_browser( $active_in_group ) || ! $job->arg( 'browser' ) ) {
				foreach ( $active_in_group as $id ) {
					$optimization = $this->plugin->registry()->get( (string) $id );
					if ( null !== $optimization && Risk::SAFE !== $optimization->risk() ) {
						$this->rollback( (string) $id, __( 'Could not be verified because the server blocks requests to itself.', 'sh-speed-optimizer' ), 'unverifiable', false, $job );
					}
				}
				$job->set( 'group_active_' . $category, $this->still_active( $active_in_group ) );
			}
			return StepResult::done();
		}

		$failures = $this->verify_set( $job, $this->plugin->state()->active_ids(), $active_in_group );

		if ( empty( $failures ) ) {
			foreach ( $active_in_group as $id ) {
				$this->mark_verified( (string) $id, 'server' );
			}
			return StepResult::done();
		}

		if ( 1 === count( $active_in_group ) ) {
			$this->rollback( (string) $active_in_group[0], $failures[0], 'verification_failed', true, $job );
			$job->set( 'group_active_' . $category, array() );
			return StepResult::done();
		}

		// Several members: roll them all back, then retry one by one to keep the good ones.
		foreach ( $active_in_group as $id ) {
			$this->rollback( (string) $id, $failures[0], 'isolating', false, null );
		}
		$job->set( 'isolate_' . $category, array_values( $active_in_group ) );
		$job->set( 'group_active_' . $category, array() );
		$job->add_steps( array( 'opt:isolate:' . $category ) );
		$job->message( __( 'A problem was detected. Testing each change individually to keep the ones that work…', 'sh-speed-optimizer' ), 'warning' );

		return StepResult::done();
	}

	/**
	 * Re-apply group members one at a time (server verification).
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 */
	private function step_isolate( Job $job, string $category ): StepResult {
		$queue = (array) $job->get( 'isolate_' . $category, array() );
		if ( empty( $queue ) ) {
			return StepResult::done();
		}

		$id = (string) array_shift( $queue );
		$job->set( 'isolate_' . $category, $queue );

		if ( $this->activate( $id, $job->arg( 'manual' ) ? 'manual' : 'auto', $job ) ) {
			$this->plugin->runtime()->refresh();
			$this->on_configuration_changed( 'isolate:' . $id );
			$failures = $this->verify_set( $job, $this->plugin->state()->active_ids(), array( $id ) );
			if ( empty( $failures ) ) {
				$this->mark_verified( $id, 'server' );
				$kept   = (array) $job->get( 'group_active_' . $category, array() );
				$kept[] = $id;
				$job->set( 'group_active_' . $category, $kept );
			} else {
				$this->rollback( $id, $failures[0], 'verification_failed', true, $job );
			}
		}

		return empty( $queue ) ? StepResult::done() : StepResult::repeat();
	}

	/**
	 * Ask the browser to test the group.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 */
	private function step_browser( Job $job, string $category ): StepResult {
		$active_in_group = (array) $job->get( 'group_active_' . $category, array() );
		if ( empty( $active_in_group ) ) {
			$job->set( 'skip_beval_' . $category, true );
			return StepResult::done();
		}

		if ( ! $job->arg( 'browser' ) || false === $job->get( 'browser_available', null ) ) {
			$job->set( 'skip_beval_' . $category, true );
			$this->drop_browser_required( $job, $category, __( 'Needs a test in your browser. Run “Optimize My Site” from the dashboard.', 'sh-speed-optimizer' ) );
			return StepResult::done();
		}

		$plan = array();
		if ( ! $job->get( 'browser_baseline' ) ) {
			$plan = $this->plugin->scanner()->browser_plan( $job, 'verify', array( 'm' => 'baseline' ), 'b', self::VERIFY_URLS );
		}
		$plan = array_merge(
			$plan,
			$this->plugin->scanner()->browser_plan(
				$job,
				'verify',
				array(
					'm' => 'candidate',
					'o' => $this->plugin->state()->active_ids(),
				),
				'c',
				self::VERIFY_URLS
			)
		);

		return StepResult::await_browser( $plan );
	}

	/**
	 * Evaluate browser results for a group.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 */
	private function step_browser_eval( Job $job, string $category ): StepResult {
		if ( $job->get( 'skip_beval_' . $category ) ) {
			return StepResult::done();
		}

		$results         = $job->browser_results();
		$active_in_group = (array) $job->get( 'group_active_' . $category, array() );

		if ( ! empty( $results['_unavailable'] ) ) {
			$job->set( 'browser_available', false );
			$this->drop_browser_required( $job, $category, __( 'The browser test could not run.', 'sh-speed-optimizer' ) );
			return StepResult::done();
		}

		$this->store_browser_baseline( $job, $results );
		$evaluation = $this->evaluate_browser( $job, $results, $active_in_group );

		if ( $evaluation['unavailable'] ) {
			$this->drop_browser_required( $job, $category, __( 'Your pages could not be displayed for the browser test (they may block being shown in a frame).', 'sh-speed-optimizer' ) );
			return StepResult::done();
		}

		if ( empty( $evaluation['failures'] ) ) {
			foreach ( $active_in_group as $id ) {
				$this->mark_verified( (string) $id, 'browser' );
			}
			return StepResult::done();
		}

		$testable = array_values( array_filter( $active_in_group, fn( $id ) => $this->wants_browser( (string) $id ) ) );

		if ( count( $testable ) <= 1 ) {
			$culprit = $testable[0] ?? (string) ( $active_in_group[0] ?? '' );
			if ( '' !== $culprit ) {
				$this->rollback( $culprit, $evaluation['failures'][0], 'browser_failed', true, $job );
			}
			return StepResult::done();
		}

		foreach ( $testable as $id ) {
			$this->rollback( (string) $id, $evaluation['failures'][0], 'isolating', false, null );
		}
		$job->set( 'group_active_' . $category, array_values( array_diff( $active_in_group, $testable ) ) );

		$steps = array();
		foreach ( $testable as $id ) {
			$steps[] = 'opt:bisolate:' . $category . ':' . $id;
			$steps[] = 'opt:bisoeval:' . $category . ':' . $id;
		}
		$job->add_steps( $steps );
		$job->message( __( 'A problem was detected in the browser test. Testing each change individually…', 'sh-speed-optimizer' ), 'warning' );

		return StepResult::done();
	}

	/**
	 * Re-apply one member and ask the browser to test it.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 * @param string $id       Optimization id.
	 */
	private function step_browser_isolate( Job $job, string $category, string $id ): StepResult {
		if ( ! $this->activate( $id, $job->arg( 'manual' ) ? 'manual' : 'auto', $job ) ) {
			$job->set( 'skip_bisoeval_' . $id, true );
			return StepResult::done();
		}
		$this->plugin->runtime()->refresh();
		$this->on_configuration_changed( 'isolate:' . $id );

		return StepResult::await_browser(
			$this->plugin->scanner()->browser_plan(
				$job,
				'verify',
				array(
					'm' => 'candidate',
					'o' => $this->plugin->state()->active_ids(),
				),
				'c',
				self::VERIFY_URLS
			)
		);
	}

	/**
	 * Evaluate one isolated member.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 * @param string $id       Optimization id.
	 */
	private function step_browser_isolate_eval( Job $job, string $category, string $id ): StepResult {
		if ( $job->get( 'skip_bisoeval_' . $id ) ) {
			return StepResult::done();
		}

		$results = $job->browser_results();
		if ( ! empty( $results['_unavailable'] ) ) {
			$this->rollback( $id, __( 'The browser test could not run.', 'sh-speed-optimizer' ), 'unverifiable', false, $job );
			return StepResult::done();
		}

		$evaluation = $this->evaluate_browser( $job, $results, array( $id ) );
		if ( empty( $evaluation['failures'] ) && ! $evaluation['unavailable'] ) {
			$this->mark_verified( $id, 'browser' );
			$kept   = (array) $job->get( 'group_active_' . $category, array() );
			$kept[] = $id;
			$job->set( 'group_active_' . $category, $kept );
		} else {
			$reason = $evaluation['failures'][0] ?? __( 'The browser test could not run.', 'sh-speed-optimizer' );
			$this->rollback( $id, $reason, $evaluation['unavailable'] ? 'unverifiable' : 'browser_failed', ! $evaluation['unavailable'], $job );
		}

		return StepResult::done();
	}

	/**
	 * Finish: purge, preload, update the report, onboarding state and summary.
	 *
	 * @param Job $job Job.
	 */
	private function step_finish( Job $job ): StepResult {
		$job->set( 'finished', true );

		$registry = $this->plugin->registry();
		$applied  = array();
		foreach ( (array) $job->get( 'applied', array() ) as $id ) {
			if ( $this->plugin->state()->is_active( (string) $id ) ) {
				$optimization = $registry->get( (string) $id );
				$applied[]    = array(
					'id'   => (string) $id,
					'name' => null !== $optimization ? $optimization->name() : (string) $id,
				);
			}
		}

		$recommended = array();
		foreach ( (array) $job->get( 'decisions', array() ) as $id => $entry ) {
			if ( Decision::RECOMMEND === ( $entry['decision']['action'] ?? '' ) && in_array( $entry['decision']['benefit'] ?? '', array( 'medium', 'high' ), true ) ) {
				$recommended[] = array(
					'id'      => (string) $id,
					'name'    => (string) $entry['name'],
					'summary' => (string) $entry['decision']['summary'],
				);
			}
		}

		$health = $this->refresh_report();

		$state = $this->plugin->state();
		$state->set( 'last_optimize_at', time() );
		$state->set( 'onboarding', State::ONBOARDING_DONE );

		$this->on_configuration_changed( 'optimize_finished' );

		$summary = array(
			'applied'       => $applied,
			'rolled_back'   => array_values( (array) $job->get( 'rolled_back', array() ) ),
			'recommended'   => $recommended,
			'ready'         => 0,
			'issues'        => (int) ( $health['improvements'] ?? 0 ),
			'health_before' => $job->get( 'health_before' ),
			'health_after'  => $health['score'] ?? null,
		);
		$job->set( 'summary', $summary );

		if ( ! empty( $applied ) || ! empty( $summary['rolled_back'] ) ) {
			$this->plugin->logger()->event(
				'optimization_run',
				sprintf(
					/* translators: 1: number applied, 2: number rolled back */
					__( 'Optimization run finished: %1$d applied, %2$d rolled back.', 'sh-speed-optimizer' ),
					count( $applied ),
					count( $summary['rolled_back'] )
				),
				'',
				array(
					'applied'     => wp_list_pluck( $applied, 'id' ),
					'rolled_back' => wp_list_pluck( $summary['rolled_back'], 'id' ),
				)
			);
		}

		return StepResult::done();
	}

	// ------------------------------------------------------------------
	// Health check (daily, server-side only).
	// ------------------------------------------------------------------

	/**
	 * Start a health check job when appropriate (daily cron).
	 */
	public function schedule_health_check(): void {
		$active = $this->plugin->state()->active_ids();
		$job    = $this->plugin->jobs()->current();
		if ( empty( $active ) || ( null !== $job && $job->is_active() ) ) {
			return;
		}
		$this->plugin->jobs()->start( 'verify', array( 'health' => true ) );
	}

	/**
	 * Prepare the health check.
	 *
	 * @param Job $job Job.
	 */
	private function step_health_prepare( Job $job ): StepResult {
		$scan = Scanner::last();
		$job->set( 'urls', array_slice( ! empty( $scan['urls'] ) ? (array) $scan['urls'] : array( home_url( '/' ) ), 0, self::VERIFY_URLS ) );
		$job->set( 'checked', array() );
		$job->set( 'failures', array() );
		return StepResult::done();
	}

	/**
	 * Compare baseline and current configuration for each URL.
	 *
	 * @param Job $job Job.
	 */
	private function step_health_check( Job $job ): StepResult {
		$checked  = (array) $job->get( 'checked', array() );
		$failures = (array) $job->get( 'failures', array() );

		foreach ( (array) $job->get( 'urls', array() ) as $url ) {
			if ( in_array( $url, $checked, true ) ) {
				continue;
			}
			$checked[] = $url;
			$job->set( 'checked', $checked );

			$baseline = Verifier::snapshot( Loopback::get( (string) $url, array( 'token' => array( 'm' => 'baseline' ) ) ) );
			if ( empty( $baseline['ok'] ) || (int) $baseline['status'] >= 500 ) {
				continue; // The site itself has a problem; not caused by optimizations.
			}
			$candidate = Verifier::snapshot(
				Loopback::get(
					(string) $url,
					array(
						'token' => array(
							'm' => 'candidate',
							'o' => $this->plugin->state()->active_ids(),
						),
					)
				)
			);
			$result    = Verifier::compare( $baseline, $candidate, $this->expected_changes( $this->plugin->state()->active_ids() ) );
			if ( ! $result['ok'] ) {
				$failures[ $url ] = $result['failures'];
				$job->set( 'failures', $failures );
			}
			return StepResult::repeat();
		}

		return StepResult::done();
	}

	/**
	 * Roll back culprits found by the health check.
	 *
	 * @param Job $job Job.
	 */
	private function step_health_finish( Job $job ): StepResult {
		$this->plugin->state()->set( 'last_verify_at', time() );
		$failures = (array) $job->get( 'failures', array() );
		if ( empty( $failures ) ) {
			return StepResult::done();
		}

		$registry = $this->plugin->registry();
		$active   = $this->plugin->state()->active_ids();
		$found    = false;

		foreach ( array_keys( $failures ) as $url ) {
			$baseline = Verifier::snapshot( Loopback::get( (string) $url, array( 'token' => array( 'm' => 'baseline' ) ) ) );
			foreach ( $active as $id ) {
				$optimization = $registry->get( $id );
				if ( null === $optimization || Category::CLEANUP === $optimization->category() || Category::CACHE === $optimization->category() ) {
					continue;
				}
				$candidate = Verifier::snapshot(
					Loopback::get(
						(string) $url,
						array(
							'token' => array(
								'm' => 'candidate',
								'o' => array( $id ),
							),
						)
					)
				);
				$result    = Verifier::compare( $baseline, $candidate, $optimization->expected_changes() );
				if ( ! $result['ok'] ) {
					$found = true;
					$this->rollback( $id, $result['failures'][0], 'health_check_failed', true, null );
				}
			}
			break; // One URL is enough to identify culprits.
		}

		if ( ! $found ) {
			$first = reset( $failures );
			$this->plugin->logger()->event(
				'verification_failed',
				__( 'The daily check found a problem that no single optimization causes. Recently applied risky optimizations were rolled back as a precaution.', 'sh-speed-optimizer' ),
				'',
				array( 'failures' => $first ),
				'warning'
			);
			foreach ( $this->plugin->state()->all()['active'] as $id => $meta ) {
				$optimization = $registry->get( (string) $id );
				if ( null !== $optimization && Risk::weight( $optimization->risk() ) >= Risk::weight( Risk::MODERATE ) && (int) ( $meta['since'] ?? 0 ) > time() - WEEK_IN_SECONDS ) {
					$this->rollback( (string) $id, (string) ( is_array( $first ) ? reset( $first ) : '' ), 'health_check_failed', true, null );
				}
			}
		}

		$this->refresh_report();
		return StepResult::done();
	}

	// ------------------------------------------------------------------
	// Public operations.
	// ------------------------------------------------------------------

	/**
	 * Manually turn an optimization off (Advanced controls).
	 *
	 * @param string $id Optimization id.
	 * @return true|\WP_Error
	 */
	public function deactivate_manual( string $id ) {
		if ( ! $this->plugin->registry()->has( $id ) ) {
			return new \WP_Error( 'shso_unknown', __( 'Unknown optimization.', 'sh-speed-optimizer' ) );
		}
		$this->plugin->snapshots()->create( __( 'Before a manual change', 'sh-speed-optimizer' ), 'manual' );
		$this->set_override( $id, 'off' );
		if ( $this->plugin->state()->is_active( $id ) ) {
			$this->rollback( $id, __( 'Turned off manually.', 'sh-speed-optimizer' ), 'manual_off', false, null );
		}
		$this->plugin->logger()->event( 'manual_off', __( 'Turned off manually.', 'sh-speed-optimizer' ), $id );
		return true;
	}

	/**
	 * Return an optimization to automatic control.
	 *
	 * @param string $id Optimization id.
	 */
	public function set_auto( string $id ): void {
		$this->set_override( $id, null );
		$this->plugin->state()->clear_disabled( $id );
	}

	/**
	 * Store a manual override.
	 *
	 * @param string      $id    Optimization id.
	 * @param string|null $value on|off|null.
	 */
	public function set_override( string $id, ?string $value ): void {
		$overrides = (array) $this->plugin->settings()->get( 'overrides', array() );
		if ( null === $value ) {
			unset( $overrides[ $id ] );
		} else {
			$overrides[ $id ] = $value;
		}
		$this->plugin->settings()->update( array( 'overrides' => $overrides ) );
	}

	/**
	 * Something that affects generated output changed: purge the page cache
	 * and regenerate its configuration.
	 *
	 * @param string $reason Reason (debug).
	 */
	public function on_configuration_changed( string $reason ): void {
		try {
			$cache = $this->plugin->cache();
			if ( $this->plugin->state()->is_active( 'page_cache' ) ) {
				$cache->write_config();
			}
			$cache->purge_all( $reason );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'Cache purge after a configuration change failed.', array( 'error' => $e->getMessage() ), 'cache' );
		}

		/**
		 * Fires after SH Speed Optimizer's configuration changed (optimizations, settings or restore).
		 *
		 * @param string $reason Reason code.
		 */
		do_action( 'shso_configuration_changed', $reason );
	}

	/**
	 * Undo side effects of active optimizations (plugin deactivation). State is kept.
	 */
	public function remove_side_effects(): void {
		foreach ( $this->plugin->state()->active_ids() as $id ) {
			$optimization = $this->plugin->registry()->get( $id );
			if ( null === $optimization ) {
				continue;
			}
			try {
				$optimization->rollback();
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error(
					'Removing side effects failed.',
					array(
						'optimization' => $id,
						'error'        => $e->getMessage(),
					),
					'engine'
				);
			}
		}
	}

	/**
	 * Re-apply side effects of active optimizations (plugin reactivation).
	 */
	public function reapply_side_effects(): void {
		foreach ( $this->plugin->state()->active_ids() as $id ) {
			$optimization = $this->plugin->registry()->get( $id );
			if ( null === $optimization ) {
				continue;
			}
			try {
				$result = $optimization->apply();
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'shso_apply_exception', $e->getMessage() );
			}
			if ( is_wp_error( $result ) ) {
				$this->plugin->state()->deactivate( $id, $result->get_error_message(), 'reapply_failed' );
			}
		}
	}

	/**
	 * Rebuild findings and health for the last scan with the current active set.
	 *
	 * @return array<string,mixed> Health.
	 */
	public function refresh_report(): array {
		$scan = Scanner::last();
		if ( empty( $scan ) ) {
			return HealthScore::calculate( array() );
		}

		// Decisions reflect the new state (active ones become "active").
		foreach ( (array) ( $scan['decisions'] ?? array() ) as $id => $entry ) {
			if ( $this->plugin->state()->is_active( (string) $id ) ) {
				$scan['decisions'][ $id ]['decision']['action']  = Decision::ACTIVE;
				$scan['decisions'][ $id ]['decision']['summary'] = __( 'Active.', 'sh-speed-optimizer' );
			} elseif ( Decision::ACTIVE === ( $entry['decision']['action'] ?? '' ) ) {
				$scan['decisions'][ $id ]['decision']['action']  = Decision::RECOMMEND;
				$scan['decisions'][ $id ]['decision']['summary'] = __( 'Not active.', 'sh-speed-optimizer' );
			}
		}

		$query_findings = array();
		if ( class_exists( '\SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector' ) ) {
			$reports = array();
			foreach ( (array) ( $scan['pages'] ?? array() ) as $url => $page ) {
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

		$database_findings = array();
		try {
			$database_findings = ! empty( $scan['database'] ) ? $this->plugin->database()->findings( (array) $scan['database'] ) : array();
		} catch ( \Throwable $e ) {
			$database_findings = array();
		}

		$cache_status = array();
		try {
			$cache_status = $this->plugin->cache()->status();
		} catch ( \Throwable $e ) {
			$cache_status = array();
		}

		$findings = ( new FindingsBuilder() )->build(
			array(
				'profile'           => (array) ( $scan['profile'] ?? array() ),
				'pages'             => (array) ( $scan['pages'] ?? array() ),
				'browser'           => (array) ( $scan['browser'] ?? array() ),
				'database_findings' => $database_findings,
				'query_findings'    => $query_findings,
				'media'             => (array) ( $scan['media'] ?? array() ),
				'decisions'         => (array) ( $scan['decisions'] ?? array() ),
				'cache'             => $cache_status,
				'active'            => $this->plugin->state()->active_ids(),
			)
		);

		$scan['findings'] = $findings;
		$scan['health']   = HealthScore::calculate( $findings );
		$scan['ready']    = array_values(
			array_filter(
				(array) ( $scan['ready'] ?? array() ),
				fn( $item ) => ! $this->plugin->state()->is_active( (string) ( $item['id'] ?? '' ) )
			)
		);
		update_option( Scanner::OPTION, $scan, false );

		return $scan['health'];
	}

	// ------------------------------------------------------------------
	// Internals.
	// ------------------------------------------------------------------

	/**
	 * Apply and activate one optimization.
	 *
	 * @param string   $id     Optimization id.
	 * @param string   $source auto|manual.
	 * @param Job|null $job    Job (for messages).
	 */
	private function activate( string $id, string $source, ?Job $job ): bool {
		$optimization = $this->plugin->registry()->get( $id );
		if ( null === $optimization ) {
			return false;
		}

		/**
		 * Fires before an optimization is applied.
		 *
		 * @param string $id     Optimization id.
		 * @param string $source auto|manual.
		 */
		do_action( 'shso_before_optimization', $id, $source );

		try {
			$result = $optimization->apply();
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'shso_apply_exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$reason = $result->get_error_message();
			try {
				$optimization->rollback();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$this->plugin->logger()->event(
				'apply_failed',
				/* translators: 1: optimization name, 2: reason */
				sprintf( __( '%1$s could not be applied: %2$s', 'sh-speed-optimizer' ), $optimization->name(), $reason ),
				$id,
				array(),
				'warning'
			);

			/**
			 * Fires when an optimization failed to apply or failed verification.
			 *
			 * @param string $id     Optimization id.
			 * @param string $reason Plain-language reason.
			 */
			do_action( 'shso_optimization_failed', $id, $reason );

			if ( null !== $job ) {
				$job->message( $optimization->name() . ': ' . $reason, 'warning' );
			}
			return false;
		}

		$this->plugin->state()->activate( $id, $source, 'none' );

		if ( null !== $job ) {
			$applied = (array) $job->get( 'applied', array() );
			if ( ! in_array( $id, $applied, true ) ) {
				$applied[] = $id;
				$job->set( 'applied', $applied );
			}
		}

		$this->plugin->logger()->event(
			'manual' === $source ? 'manual_on' : 'applied',
			$optimization->name(),
			$id,
			array( 'source' => $source )
		);

		/**
		 * Fires after an optimization was applied (before verification).
		 *
		 * @param string $id     Optimization id.
		 * @param string $source auto|manual.
		 */
		do_action( 'shso_after_optimization', $id, $source );

		return true;
	}

	/**
	 * Roll back one optimization.
	 *
	 * @param string   $id       Optimization id.
	 * @param string   $reason   Plain-language reason.
	 * @param string   $code     Reason code.
	 * @param bool     $remember Remember as disabled (not re-applied automatically).
	 * @param Job|null $job      Job (to record the rollback in the summary).
	 */
	public function rollback( string $id, string $reason, string $code, bool $remember, ?Job $job ): void {
		$optimization = $this->plugin->registry()->get( $id );
		if ( null !== $optimization ) {
			try {
				$optimization->rollback();
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error(
					'Rollback failed.',
					array(
						'optimization' => $id,
						'error'        => $e->getMessage(),
					),
					'rollback'
				);
			}
		}

		$this->plugin->state()->deactivate( $id, $remember ? $reason : null, $code );
		$this->plugin->runtime()->refresh();

		if ( 'isolating' === $code ) {
			return; // Temporary; the member is retried right away.
		}

		$name = null !== $optimization ? $optimization->name() : $id;

		$this->plugin->logger()->event(
			'manual_off' === $code ? 'manual_off' : 'rolled_back',
			/* translators: 1: optimization name, 2: reason */
			sprintf( __( '%1$s rolled back: %2$s', 'sh-speed-optimizer' ), $name, $reason ),
			$id,
			array( 'code' => $code ),
			'manual_off' === $code ? 'info' : 'warning'
		);

		if ( in_array( $code, array( 'verification_failed', 'browser_failed', 'health_check_failed' ), true ) ) {
			/** This action is documented in includes/Optimization/Engine.php */
			do_action( 'shso_optimization_failed', $id, $reason );
		}

		/**
		 * Fires after an optimization was rolled back.
		 *
		 * @param string $id     Optimization id.
		 * @param string $reason Plain-language reason.
		 * @param string $code   Reason code.
		 */
		do_action( 'shso_optimization_rollback', $id, $reason, $code );

		// Cached pages may reference files the rollback just removed (optimized copies, local fonts).
		$this->on_configuration_changed( 'rollback:' . $id );

		if ( null !== $job ) {
			$rolled        = (array) $job->get( 'rolled_back', array() );
			$rolled[ $id ] = array(
				'id'     => $id,
				'name'   => $name,
				'reason' => $reason,
			);
			$job->set( 'rolled_back', $rolled );
			$job->message( '↩ ' . $name . ': ' . $reason, 'warning' );
		}
	}

	/**
	 * Server-verify an optimization set against the baselines.
	 *
	 * @param Job      $job        Job.
	 * @param string[] $active_ids Candidate set.
	 * @param string[] $members    Optimizations under test (for extra verify() hooks and expected changes).
	 * @return string[] Failures.
	 */
	private function verify_set( Job $job, array $active_ids, array $members ): array {
		$baselines = (array) $job->get( 'baselines', array() );
		$expected  = $this->expected_changes( $active_ids );
		$failures  = array();
		$first     = null;

		foreach ( $this->verify_urls( $job ) as $url ) {
			$baseline = $baselines[ $url ] ?? null;
			if ( ! is_array( $baseline ) || empty( $baseline['ok'] ) || (int) $baseline['status'] >= 500 ) {
				continue;
			}
			$candidate = Verifier::snapshot(
				Loopback::get(
					$url,
					array(
						'token' => array(
							'm' => 'candidate',
							'o' => $active_ids,
						),
					)
				)
			);
			if ( null === $first ) {
				$first = array( $baseline, $candidate );
			}
			$result = Verifier::compare( $baseline, $candidate, $expected );
			foreach ( $result['failures'] as $failure ) {
				/* translators: 1: problem, 2: URL path */
				$failures[] = sprintf( __( '%1$s (%2$s)', 'sh-speed-optimizer' ), $failure, (string) wp_parse_url( $url, PHP_URL_PATH ) );
			}
			foreach ( $result['warnings'] as $warning ) {
				$this->plugin->logger()->debug( $warning, array( 'url' => $url ), 'verify' );
			}
		}

		foreach ( $members as $id ) {
			$optimization = $this->plugin->registry()->get( (string) $id );
			if ( null === $optimization ) {
				continue;
			}
			try {
				$extra = $optimization->verify( $first[0] ?? array(), $first[1] ?? array() );
			} catch ( \Throwable $e ) {
				$extra = __( 'The verification check itself failed.', 'sh-speed-optimizer' );
			}
			if ( null !== $extra && '' !== $extra ) {
				$failures[] = $extra;
			}
		}

		return array_values( array_unique( $failures ) );
	}

	/**
	 * Evaluate candidate browser results against the stored baseline.
	 *
	 * @param Job                 $job     Job.
	 * @param array<string,mixed> $results Results.
	 * @param string[]            $members Optimizations under test.
	 * @return array{failures:string[],unavailable:bool}
	 */
	private function evaluate_browser( Job $job, array $results, array $members ): array {
		$baseline    = (array) $job->get( 'browser_baseline', array() );
		$expected    = $this->expected_changes( $members );
		$failures    = array();
		$compared    = 0;
		$unavailable = 0;

		foreach ( $results as $key => $result ) {
			if ( ! str_starts_with( (string) $key, 'c:' ) ) {
				continue;
			}
			$base_key = 'b:' . substr( (string) $key, 2 );
			$outcome  = BrowserEvaluator::compare( $baseline[ $base_key ] ?? array(), $result, $expected );
			if ( $outcome['unavailable'] ) {
				++$unavailable;
				continue;
			}
			++$compared;
			foreach ( $outcome['failures'] as $failure ) {
				$path = (string) wp_parse_url( (string) ( $result['url'] ?? '' ), PHP_URL_PATH );
				/* translators: 1: problem, 2: URL path */
				$failures[] = sprintf( __( '%1$s (%2$s)', 'sh-speed-optimizer' ), $failure, '' === $path ? '/' : $path );
			}
		}

		return array(
			'failures'    => array_values( array_unique( $failures ) ),
			'unavailable' => 0 === $compared && $unavailable > 0,
		);
	}

	/**
	 * Keep baseline browser results for later groups.
	 *
	 * @param Job                 $job     Job.
	 * @param array<string,mixed> $results Results.
	 */
	private function store_browser_baseline( Job $job, array $results ): void {
		if ( $job->get( 'browser_baseline' ) ) {
			return;
		}
		$baseline = array();
		foreach ( $results as $key => $result ) {
			if ( str_starts_with( (string) $key, 'b:' ) && is_array( $result ) ) {
				$baseline[ $key ] = Scanner::compact_result( $result ) + array( 'resource_errors' => array_slice( (array) ( $result['resource_errors'] ?? array() ), 0, 20 ) );
			}
		}
		$job->set( 'browser_baseline', $baseline );
	}

	/**
	 * Roll back group members that cannot be kept without a browser test.
	 *
	 * @param Job    $job      Job.
	 * @param string $category Category.
	 * @param string $reason   Reason.
	 */
	private function drop_browser_required( Job $job, string $category, string $reason ): void {
		$active = (array) $job->get( 'group_active_' . $category, array() );
		foreach ( $active as $id ) {
			$optimization = $this->plugin->registry()->get( (string) $id );
			if ( null !== $optimization && in_array( OptimizationInterface::REQ_BROWSER, $optimization->requirements(), true ) ) {
				$this->rollback( (string) $id, $reason, 'unverifiable', false, $job );
			}
		}
		$job->set( 'group_active_' . $category, $this->still_active( $active ) );
	}

	/**
	 * Filter ids to those still active.
	 *
	 * @param string[] $ids Ids.
	 * @return string[]
	 */
	private function still_active( array $ids ): array {
		return array_values( array_filter( $ids, fn( $id ) => $this->plugin->state()->is_active( (string) $id ) ) );
	}

	/**
	 * Mark an optimization as verified.
	 *
	 * @param string $id    Id.
	 * @param string $level server|browser.
	 */
	private function mark_verified( string $id, string $level ): void {
		$state = $this->plugin->state()->all();
		if ( ! isset( $state['active'][ $id ] ) ) {
			return;
		}
		$current = (string) ( $state['active'][ $id ]['verified'] ?? 'none' );
		if ( 'browser' === $current && 'server' === $level ) {
			return;
		}
		$state['active'][ $id ]['verified'] = $level;
		$this->plugin->state()->save( $state );
	}

	/**
	 * URLs used for verification.
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private function verify_urls( Job $job ): array {
		$urls = (array) $job->get( 'urls', array() );
		if ( empty( $urls ) ) {
			$urls = array( home_url( '/' ) );
		}
		return array_slice( array_map( 'strval', $urls ), 0, self::VERIFY_URLS );
	}

	/**
	 * Union of expected marker changes.
	 *
	 * @param string[] $ids Optimization ids.
	 * @return string[]
	 */
	private function expected_changes( array $ids ): array {
		$changes = array();
		foreach ( $ids as $id ) {
			$optimization = $this->plugin->registry()->get( (string) $id );
			if ( null !== $optimization ) {
				$changes = array_merge( $changes, $optimization->expected_changes() );
			}
		}
		return array_values( array_unique( $changes ) );
	}

	/**
	 * Whether a group should be checked in the browser.
	 *
	 * @param string[] $ids Ids.
	 */
	private function group_wants_browser( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( $this->wants_browser( (string) $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether one optimization should be checked in the browser.
	 *
	 * @param string $id Id.
	 */
	private function wants_browser( string $id ): bool {
		$optimization = $this->plugin->registry()->get( $id );
		if ( null === $optimization ) {
			return false;
		}
		return in_array( OptimizationInterface::REQ_BROWSER, $optimization->requirements(), true )
			|| ( in_array( $optimization->category(), self::BROWSER_CATEGORIES, true ) && Risk::SAFE !== $optimization->risk() );
	}
}
