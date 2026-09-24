<?php
/**
 * Decision engine tests.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Optimization;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Core\State;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Decision;
use SH\SpeedOptimizer\Optimization\DecisionEngine;
use SH\SpeedOptimizer\Optimization\OptimizationInterface;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

final class DecisionEngineTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
	}

	private function optimization( string $id, string $risk, string $level, array $requirements = array(), array $deps = array(), array $conflicts = array() ): OptimizationInterface {
		return new class( $id, $risk, $level, $requirements, $deps, $conflicts ) implements OptimizationInterface {
			public function __construct( private string $oid, private string $orisk, private string $olevel, private array $oreq, private array $odeps, private array $oconf ) {}
			public function id(): string { return $this->oid; }
			public function name(): string { return $this->oid; }
			public function description(): string { return ''; }
			public function category(): string { return 'css'; }
			public function risk(): string { return $this->orisk; }
			public function level(): string { return $this->olevel; }
			public function is_reversible(): bool { return true; }
			public function dependencies(): array { return $this->odeps; }
			public function conflicts(): array { return $this->oconf; }
			public function requirements(): array { return $this->oreq; }
			public function default_enabled(): bool { return false; }
			public function safe_mode_compatible(): bool { return false; }
			public function expected_changes(): array { return array(); }
			public function assess( AssessmentContext $context ): Assessment { return Assessment::make(); }
			public function apply() { return true; }
			public function verify( array $baseline, array $candidate ): ?string { return null; }
			public function rollback(): void {}
			public function register_runtime( Runtime $runtime ): void {}
			public function details(): array { return array(); }
			public function to_array(): array { return array(); }
		};
	}

	private function engine( array $settings = array() ): DecisionEngine {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), $settings ) );
		return new DecisionEngine( new Settings(), new State() );
	}

	private const CAN = array(
		'loopback' => true,
		'browser'  => true,
	);

	public function test_safe_high_confidence_is_applied_automatically(): void {
		$decision = $this->engine()->decide( $this->optimization( 'a', Risk::SAFE, Risk::LEVEL_SAFE ), Assessment::make( true, 99, Assessment::BENEFIT_LOW ), self::CAN );
		$this->assertSame( Decision::AUTO_APPLY, $decision->action );
	}

	public function test_moderate_risk_is_tested_first(): void {
		$decision = $this->engine()->decide( $this->optimization( 'b', Risk::MODERATE, Risk::LEVEL_SMART ), Assessment::make( true, 80, Assessment::BENEFIT_HIGH ), self::CAN );
		$this->assertSame( Decision::TEST_THEN_APPLY, $decision->action );
	}

	public function test_low_confidence_is_only_recommended(): void {
		$decision = $this->engine()->decide( $this->optimization( 'c', Risk::MODERATE, Risk::LEVEL_SMART ), Assessment::make( true, 40, Assessment::BENEFIT_HIGH ), self::CAN );
		$this->assertSame( Decision::RECOMMEND, $decision->action );
	}

	public function test_high_risk_and_experimental_are_never_automatic(): void {
		$engine = $this->engine( array( 'advanced_optimizations' => true ) );
		$this->assertSame( Decision::RECOMMEND, $engine->decide( $this->optimization( 'd', Risk::HIGH, Risk::LEVEL_SMART ), Assessment::make( true, 100, Assessment::BENEFIT_HIGH ), self::CAN )->action );
		$this->assertSame( Decision::RECOMMEND, $engine->decide( $this->optimization( 'e', Risk::LOW, Risk::LEVEL_EXPERIMENTAL ), Assessment::make( true, 100, Assessment::BENEFIT_HIGH ), self::CAN )->action );

		$hidden = $this->engine()->decide( $this->optimization( 'e', Risk::LOW, Risk::LEVEL_EXPERIMENTAL ), Assessment::make( true, 100, Assessment::BENEFIT_HIGH ), self::CAN );
		$this->assertSame( Decision::SKIP, $hidden->action );
	}

	public function test_handled_by_other_plugin_is_skipped(): void {
		$assessment             = Assessment::make( true, 99, Assessment::BENEFIT_HIGH );
		$assessment->handled_by = 'WP Rocket';
		$decision               = $this->engine()->decide( $this->optimization( 'f', Risk::SAFE, Risk::LEVEL_SAFE ), $assessment, self::CAN );
		$this->assertSame( Decision::SKIP, $decision->action );
		$this->assertStringContainsString( 'WP Rocket', $decision->summary );
	}

	public function test_browser_requirement_without_browser_is_recommend(): void {
		$decision = $this->engine()->decide(
			$this->optimization( 'g', Risk::LOW, Risk::LEVEL_SAFE, array( OptimizationInterface::REQ_BROWSER ) ),
			Assessment::make( true, 95, Assessment::BENEFIT_MEDIUM ),
			array(
				'loopback' => true,
				'browser'  => false,
			)
		);
		$this->assertSame( Decision::RECOMMEND, $decision->action );
	}

	public function test_server_config_requires_permission(): void {
		$opt = $this->optimization( 'h', Risk::LOW, Risk::LEVEL_SAFE, array( OptimizationInterface::REQ_SERVER_CONFIG ) );
		$this->assertSame( Decision::RECOMMEND, $this->engine()->decide( $opt, Assessment::make( true, 95, Assessment::BENEFIT_MEDIUM ), self::CAN )->action );
		$this->assertSame( Decision::AUTO_APPLY, $this->engine( array( 'allow_server_config' => true ) )->decide( $opt, Assessment::make( true, 95, Assessment::BENEFIT_MEDIUM ), self::CAN )->action );
	}

	public function test_non_safe_needs_some_verification_path(): void {
		$decision = $this->engine()->decide(
			$this->optimization( 'i', Risk::LOW, Risk::LEVEL_SAFE ),
			Assessment::make( true, 95, Assessment::BENEFIT_MEDIUM ),
			array(
				'loopback' => false,
				'browser'  => false,
			)
		);
		$this->assertSame( Decision::RECOMMEND, $decision->action );
	}

	public function test_manual_off_override_and_auto_off(): void {
		$opt = $this->optimization( 'j', Risk::SAFE, Risk::LEVEL_SAFE );
		$this->assertSame( Decision::SKIP, $this->engine( array( 'overrides' => array( 'j' => 'off' ) ) )->decide( $opt, Assessment::make( true, 99 ), self::CAN )->action );
		$this->assertSame( Decision::RECOMMEND, $this->engine( array( 'auto_optimize' => false ) )->decide( $opt, Assessment::make( true, 99 ), self::CAN )->action );
	}

	public function test_previously_rolled_back_is_not_reapplied(): void {
		$engine = $this->engine();
		$state  = new State();
		$state->deactivate( 'k', 'Checkout broke', 'verification_failed' );
		$engine   = new DecisionEngine( new Settings(), $state );
		$decision = $engine->decide( $this->optimization( 'k', Risk::SAFE, Risk::LEVEL_SAFE ), Assessment::make( true, 99 ), self::CAN );
		$this->assertSame( Decision::RECOMMEND, $decision->action );
		$this->assertStringContainsString( 'Checkout broke', $decision->summary );
	}

	public function test_order_respects_dependencies_and_conflicts(): void {
		$engine = $this->engine();
		$a      = $this->optimization( 'a', Risk::LOW, Risk::LEVEL_SAFE, array(), array( 'b' ) );
		$b      = $this->optimization( 'b', Risk::MODERATE, Risk::LEVEL_SAFE );
		$c      = $this->optimization( 'c', Risk::SAFE, Risk::LEVEL_SAFE, array(), array(), array( 'x' ) );
		$d      = $this->optimization( 'd', Risk::SAFE, Risk::LEVEL_SAFE, array(), array( 'missing' ) );

		$ordered = $engine->order(
			array(
				'a' => $a,
				'b' => $b,
				'c' => $c,
				'd' => $d,
			),
			array( 'x' )
		);

		$this->assertSame( array( 'b', 'a' ), array_keys( $ordered ) );
	}
}
