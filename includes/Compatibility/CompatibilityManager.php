<?php
/**
 * Compatibility manager: merges base rules, applicable profiles and the
 * user's exclusions into one Rules object.
 *
 * Called on frontend requests, so it is cheap and memoized: profiles only
 * check constants, loaded classes and autoloaded options. Rules computed
 * before the theme was loaded are rebuilt once afterwards so rules added by
 * a theme through the `shso_compatibility_rules` filter are not missed.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility;

use SH\SpeedOptimizer\Compatibility\Profiles\AcfProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\AjaxHeavyProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\BaseRules;
use SH\SpeedOptimizer\Compatibility\Profiles\BeaverBuilderProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\BookingProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\BreakdanceProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\BricksProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\CommunityProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\ConsentProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\DiviProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\EddProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\ElementorProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\ElementorProProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\Environment;
use SH\SpeedOptimizer\Compatibility\Profiles\FormsProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\GenericBuilderProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\GutenbergProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\JetpackProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\MembershipProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\MultilingualProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\OxygenProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\SlidersProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\WooCommerceProfile;
use SH\SpeedOptimizer\Compatibility\Profiles\WpBakeryProfile;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility manager.
 */
final class CompatibilityManager {

	/**
	 * Built-in profile classes (constructor takes an optional Environment).
	 */
	public const PROFILES = array(
		GutenbergProfile::class,
		ElementorProfile::class,
		ElementorProProfile::class,
		DiviProfile::class,
		BricksProfile::class,
		WpBakeryProfile::class,
		OxygenProfile::class,
		BreakdanceProfile::class,
		BeaverBuilderProfile::class,
		GenericBuilderProfile::class,
		WooCommerceProfile::class,
		EddProfile::class,
		AcfProfile::class,
		MultilingualProfile::class,
		FormsProfile::class,
		MembershipProfile::class,
		BookingProfile::class,
		SlidersProfile::class,
		ConsentProfile::class,
		AjaxHeavyProfile::class,
		CommunityProfile::class,
		JetpackProfile::class,
	);

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Environment shared by all profiles.
	 *
	 * @var Environment
	 */
	private Environment $env;

	/**
	 * Memoized rules.
	 *
	 * @var Rules|null
	 */
	private ?Rules $rules = null;

	/**
	 * Whether the memoized rules were built after the theme was loaded.
	 *
	 * @var bool
	 */
	private bool $final = false;

	/**
	 * All profile instances.
	 *
	 * @var ProfileInterface[]|null
	 */
	private ?array $all = null;

	/**
	 * Applicable profiles.
	 *
	 * @var ProfileInterface[]|null
	 */
	private ?array $applicable = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin           $plugin Plugin container.
	 * @param Environment|null $env    Environment (tests inject a fake).
	 */
	public function __construct( Plugin $plugin, ?Environment $env = null ) {
		$this->plugin = $plugin;
		$this->env    = $env ?? new Environment();
	}

	/**
	 * Effective compatibility rules for this site.
	 */
	public function rules(): Rules {
		$theme_loaded = did_action( 'after_setup_theme' ) > 0;
		if ( null !== $this->rules && ( $this->final || ! $theme_loaded ) ) {
			return $this->rules;
		}

		$rules = new Rules();
		BaseRules::apply( $rules );

		foreach ( $this->profiles() as $profile ) {
			try {
				$profile->register( $rules );
				$rules->add_profile( $profile->name() );
			} catch ( \Throwable $e ) {
				$this->log_failure( $profile, $e );
			}
		}

		self::apply_settings( $rules, $this->plugin->settings()->all() );

		/**
		 * Filters the compatibility rules (add exclusions, disable or penalize optimizations).
		 *
		 * @param Rules $rules Rules built from the base rules, the active profiles and the user's exclusions.
		 */
		$filtered = apply_filters( 'shso_compatibility_rules', $rules );

		$this->rules = $filtered instanceof Rules ? $filtered : $rules;
		$this->final = $theme_loaded;

		return $this->rules;
	}

	/**
	 * Applicable profiles.
	 *
	 * @return ProfileInterface[]
	 */
	public function profiles(): array {
		if ( null !== $this->applicable && ( $this->final || did_action( 'after_setup_theme' ) === 0 ) ) {
			return $this->applicable;
		}

		$this->applicable = array();
		foreach ( $this->all_profiles() as $profile ) {
			if ( $this->safe_applies( $profile ) ) {
				$this->applicable[] = $profile;
			}
		}
		return $this->applicable;
	}

	/**
	 * All known profiles (built-in plus those added through the filter).
	 *
	 * @return ProfileInterface[]
	 */
	public function all_profiles(): array {
		if ( null !== $this->all ) {
			return $this->all;
		}

		$profiles = array();
		foreach ( self::PROFILES as $class ) {
			$profiles[] = new $class( $this->env );
		}

		/**
		 * Filters the compatibility profiles. Add ProfileInterface instances (or class names
		 * with a constructor without required arguments) to protect other plugins or themes.
		 *
		 * @param array<int,ProfileInterface|string> $profiles Profiles.
		 */
		$filtered = (array) apply_filters( 'shso_compatibility_profiles', $profiles );

		$this->all = array();
		$ids       = array();
		foreach ( $filtered as $profile ) {
			if ( is_string( $profile ) && class_exists( $profile ) && is_subclass_of( $profile, ProfileInterface::class ) ) {
				try {
					$profile = new $profile();
				} catch ( \Throwable $e ) {
					continue;
				}
			}
			if ( ! $profile instanceof ProfileInterface || isset( $ids[ $profile->id() ] ) ) {
				continue;
			}
			$ids[ $profile->id() ] = true;
			$this->all[]           = $profile;
		}

		return $this->all;
	}

	/**
	 * Data for the developer diagnostics screen.
	 *
	 * @return array{profiles:array<int,array{id:string,name:string,applies:bool}>,active:string[],rules:array<string,mixed>}
	 */
	public function diagnostics(): array {
		$profiles = array();
		foreach ( $this->all_profiles() as $profile ) {
			$profiles[] = array(
				'id'      => $profile->id(),
				'name'    => $profile->name(),
				'applies' => $this->safe_applies( $profile ),
			);
		}

		$rules = $this->rules();

		return array(
			'profiles' => $profiles,
			'active'   => $rules->profiles(),
			'rules'    => $rules->to_array(),
		);
	}

	/**
	 * Forget memoized rules and profiles (e.g. after settings changed in the same request).
	 */
	public function reset(): void {
		$this->rules      = null;
		$this->final      = false;
		$this->all        = null;
		$this->applicable = null;
	}

	/**
	 * Add the user's exclusions from the settings.
	 *
	 * @param Rules               $rules    Rules.
	 * @param array<string,mixed> $settings Settings (exclude_js, exclude_css, exclude_urls, exclude_cookies).
	 */
	public static function apply_settings( Rules $rules, array $settings ): void {
		$js = self::lines( $settings['exclude_js'] ?? array() );
		$rules->add( 'js_no_defer', $js );
		$rules->add( 'js_no_delay', $js );
		$rules->add( 'js_no_minify', $js );

		$rules->add( 'css_no_optimize', self::lines( $settings['exclude_css'] ?? array() ) );
		$rules->add( 'cache_exclude_urls', self::lines( $settings['exclude_urls'] ?? array() ) );
		$rules->add( 'cache_exclude_cookies', self::lines( $settings['exclude_cookies'] ?? array() ) );
	}

	/**
	 * Normalize a settings list.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function lines( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		return array_values( array_filter( array_map( 'strval', (array) $value ), 'strlen' ) );
	}

	/**
	 * applies() guarded against exceptions.
	 *
	 * @param ProfileInterface $profile Profile.
	 */
	private function safe_applies( ProfileInterface $profile ): bool {
		try {
			return $profile->applies();
		} catch ( \Throwable $e ) {
			$this->log_failure( $profile, $e );
			return false;
		}
	}

	/**
	 * Log a failing profile (never breaks the request).
	 *
	 * @param ProfileInterface $profile Profile.
	 * @param \Throwable       $e       Error.
	 */
	private function log_failure( ProfileInterface $profile, \Throwable $e ): void {
		try {
			$this->plugin->logger()->debug(
				'Compatibility profile failed and was skipped.',
				array(
					'profile' => $profile->id(),
					'error'   => $e->getMessage(),
				),
				'compat'
			);
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
