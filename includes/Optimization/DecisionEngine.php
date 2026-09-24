<?php
/**
 * Smart decision engine.
 *
 * Environment → compatibility → assets → risk → benefit → decision.
 *
 * Never "if plugin exists then optimize": each decision combines the
 * optimization's own detection result (applicability, confidence, benefit)
 * with its risk level, the user's settings and what the engine can verify.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Core\State;
use SH\SpeedOptimizer\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Decision engine.
 */
final class DecisionEngine {

	/**
	 * Minimum confidence per risk for automatic application.
	 */
	private const AUTO_THRESHOLD = array(
		Risk::SAFE     => 80,
		Risk::LOW      => 75,
		Risk::MODERATE => 70,
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param State    $state    State.
	 */
	public function __construct( Settings $settings, State $state ) {
		$this->settings = $settings;
		$this->state    = $state;
	}

	/**
	 * Decide what to do with one optimization.
	 *
	 * @param OptimizationInterface $optimization Optimization.
	 * @param Assessment            $assessment   Its assessment.
	 * @param array<string,bool>    $capabilities What the engine can do right now:
	 *                                            loopback (server verification works),
	 *                                            browser (an administrator's browser can run checks).
	 */
	public function decide( OptimizationInterface $optimization, Assessment $assessment, array $capabilities ): Decision {
		$id       = $optimization->id();
		$risk     = $optimization->risk();
		$level    = $optimization->level();
		$benefit  = $assessment->benefit;
		$conf     = $assessment->confidence;
		$reasons  = $assessment->reasons;
		$override = $this->settings->get( 'overrides', array() )[ $id ] ?? null;

		$make = static function ( string $action, string $summary ) use ( $conf, $risk, $benefit, &$reasons ) {
			return new Decision( $action, $summary, $conf, $risk, $benefit, $reasons );
		};

		if ( $this->state->is_active( $id ) ) {
			return $make( Decision::ACTIVE, __( 'Active.', 'sh-speed-optimizer' ) );
		}

		if ( 'off' === $override ) {
			return $make( Decision::SKIP, __( 'Turned off in Advanced settings.', 'sh-speed-optimizer' ) );
		}

		if ( null !== $assessment->handled_by ) {
			return $make(
				Decision::SKIP,
				/* translators: %s: name of another plugin or service */
				sprintf( __( 'Already handled by %s — skipped to avoid double optimization.', 'sh-speed-optimizer' ), $assessment->handled_by )
			);
		}

		if ( null !== $assessment->blocked ) {
			return $make( Decision::SKIP, $assessment->blocked );
		}

		if ( ! $assessment->applicable ) {
			return $make( Decision::SKIP, $reasons[0] ?? __( 'Nothing to optimize on this site.', 'sh-speed-optimizer' ) );
		}

		$disabled = $this->state->disabled( $id );
		if ( null !== $disabled && 'on' !== $override ) {
			return $make(
				Decision::RECOMMEND,
				/* translators: %s: reason the optimization was rolled back */
				sprintf( __( 'Rolled back earlier: %s', 'sh-speed-optimizer' ), $disabled['reason'] )
			);
		}

		if ( Risk::LEVEL_EXPERIMENTAL === $level ) {
			if ( ! $this->settings->get( 'advanced_optimizations' ) ) {
				return $make( Decision::SKIP, __( 'Experimental — enable Advanced Optimizations to use it.', 'sh-speed-optimizer' ) );
			}
			return $make( Decision::RECOMMEND, __( 'Experimental — never enabled automatically. You can enable it manually after testing.', 'sh-speed-optimizer' ) );
		}

		if ( Risk::HIGH === $risk ) {
			return $make( Decision::RECOMMEND, __( 'High risk — never enabled automatically on a live site.', 'sh-speed-optimizer' ) );
		}

		$requirements = $optimization->requirements();

		if ( in_array( OptimizationInterface::REQ_SERVER_CONFIG, $requirements, true ) ) {
			$blocked = Capabilities::server_files_blocked_reason( false );
			if ( null !== $blocked ) {
				return $make( Decision::SKIP, $blocked );
			}
			if ( ! $this->settings->get( 'allow_server_config' ) ) {
				return $make( Decision::RECOMMEND, __( 'Needs your permission to add rules to the server configuration (.htaccess).', 'sh-speed-optimizer' ) );
			}
		}

		if ( in_array( OptimizationInterface::REQ_EXTERNAL_DOWNLOAD, $requirements, true ) && ! $this->settings->get( 'localize_fonts' ) ) {
			return $make( Decision::RECOMMEND, __( 'Needs your permission to download files from a third-party service.', 'sh-speed-optimizer' ) );
		}

		if ( ! $this->settings->get( 'safe_optimizations' ) || ! $this->settings->get( 'auto_optimize' ) ) {
			return $make( Decision::RECOMMEND, __( 'Automatic optimization is turned off.', 'sh-speed-optimizer' ) );
		}

		$can_server  = ! empty( $capabilities['loopback'] );
		$can_browser = ! empty( $capabilities['browser'] );

		if ( in_array( OptimizationInterface::REQ_BROWSER, $requirements, true ) && ! $can_browser ) {
			return $make( Decision::RECOMMEND, __( 'Needs a quick test in your browser — run “Optimize My Site” from the dashboard.', 'sh-speed-optimizer' ) );
		}

		if ( ( in_array( OptimizationInterface::REQ_LOOPBACK, $requirements, true ) || Risk::SAFE !== $risk ) && ! $can_server && ! $can_browser ) {
			return $make( Decision::RECOMMEND, __( 'Cannot be verified automatically because your server blocks requests to itself.', 'sh-speed-optimizer' ) );
		}

		if ( Assessment::BENEFIT_NONE === $benefit ) {
			return $make( Decision::SKIP, __( 'No measurable benefit expected on this site.', 'sh-speed-optimizer' ) );
		}

		$threshold = self::AUTO_THRESHOLD[ $risk ] ?? 101;

		if ( 'on' === $override ) {
			$reasons[] = __( 'Requested manually in Advanced settings.', 'sh-speed-optimizer' );
			return $make( Decision::TEST_THEN_APPLY, __( 'Will be tested and kept only if nothing breaks.', 'sh-speed-optimizer' ) );
		}

		if ( $conf >= $threshold ) {
			if ( Risk::SAFE === $risk || Risk::LOW === $risk ) {
				return $make( Decision::AUTO_APPLY, __( 'Safe for this site — applied automatically and verified.', 'sh-speed-optimizer' ) );
			}
			return $make( Decision::TEST_THEN_APPLY, __( 'Compatible with this site — tested before it is kept.', 'sh-speed-optimizer' ) );
		}

		if ( Risk::SAFE !== $risk && $conf >= $threshold - 20 && Assessment::BENEFIT_LOW !== $benefit ) {
			return $make( Decision::TEST_THEN_APPLY, __( 'Promising but uncertain — tested carefully before it is kept.', 'sh-speed-optimizer' ) );
		}

		return $make( Decision::RECOMMEND, __( 'Compatibility confidence is too low to apply it automatically.', 'sh-speed-optimizer' ) );
	}

	/**
	 * Order a set of optimization ids so dependencies come first and conflicts are resolved
	 * (the earlier/lower risk one wins).
	 *
	 * @param array<string,OptimizationInterface> $optimizations Optimizations to apply.
	 * @param string[]                            $active        Currently active ids.
	 * @return array<string,OptimizationInterface>
	 */
	public function order( array $optimizations, array $active ): array {
		$ordered = array();
		$chosen  = array_fill_keys( $active, true );

		$visit = function ( string $id, array $stack ) use ( &$visit, &$ordered, &$chosen, $optimizations ) {
			if ( isset( $ordered[ $id ] ) || ! isset( $optimizations[ $id ] ) || in_array( $id, $stack, true ) ) {
				return;
			}
			$optimization = $optimizations[ $id ];
			foreach ( $optimization->dependencies() as $dependency ) {
				$visit( $dependency, array_merge( $stack, array( $id ) ) );
				if ( ! isset( $chosen[ $dependency ] ) ) {
					return; // Dependency unavailable: skip.
				}
			}
			foreach ( $optimization->conflicts() as $conflict ) {
				if ( isset( $chosen[ $conflict ] ) ) {
					return;
				}
			}
			$ordered[ $id ] = $optimization;
			$chosen[ $id ]  = true;
		};

		uasort(
			$optimizations,
			static function ( OptimizationInterface $a, OptimizationInterface $b ) {
				return Risk::weight( $a->risk() ) <=> Risk::weight( $b->risk() );
			}
		);

		foreach ( array_keys( $optimizations ) as $id ) {
			$visit( $id, array() );
		}

		return $ordered;
	}
}
