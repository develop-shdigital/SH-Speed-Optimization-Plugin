<?php
/**
 * Engine state: which optimizations are active, which were rolled back and
 * where they are excluded.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * State repository (option `shso_state`, autoloaded because the frontend needs it on every request).
 */
final class State {

	public const OPTION = 'shso_state';

	public const ONBOARDING_NEW     = 'new';
	public const ONBOARDING_SCANNED = 'scanned';
	public const ONBOARDING_DONE    = 'optimized';

	/**
	 * In-memory copy.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data = null;

	/**
	 * Empty state.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank(): array {
		return array(
			'active'          => array(), // id => {since, source, verified}.
			'disabled'        => array(), // id => {reason, code, at}.
			'page_exclusions' => array(), // id => string[] of "tpl:<key>" / "url:<path>".
			'onboarding'      => self::ONBOARDING_NEW,
			'last_scan_at'    => 0,
			'last_optimize_at' => 0,
			'last_verify_at'  => 0,
			'revision'        => 1, // Bumped on every change; used to version generated assets and cached pages.
		);
	}

	/**
	 * Full state.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->data ) {
			$raw        = get_option( self::OPTION, array() );
			$this->data = array_merge( self::blank(), is_array( $raw ) ? $raw : array() );
		}
		return $this->data;
	}

	/**
	 * Persist a modified state.
	 *
	 * @param array<string,mixed> $data State.
	 */
	public function save( array $data ): void {
		$data['revision'] = (int) ( $data['revision'] ?? 0 ) + 1;
		$this->data       = array_merge( self::blank(), $data );
		update_option( self::OPTION, $this->data, true );
	}

	/**
	 * Active optimization ids.
	 *
	 * @return string[]
	 */
	public function active_ids(): array {
		return array_keys( $this->all()['active'] );
	}

	/**
	 * Whether an optimization is active.
	 *
	 * @param string $id Optimization id.
	 */
	public function is_active( string $id ): bool {
		return isset( $this->all()['active'][ $id ] );
	}

	/**
	 * Mark an optimization active.
	 *
	 * @param string $id       Optimization id.
	 * @param string $source   auto|manual.
	 * @param string $verified none|server|browser.
	 */
	public function activate( string $id, string $source, string $verified ): void {
		$data                  = $this->all();
		$data['active'][ $id ] = array(
			'since'    => time(),
			'source'   => $source,
			'verified' => $verified,
		);
		unset( $data['disabled'][ $id ] );
		$this->save( $data );
	}

	/**
	 * Mark an optimization inactive.
	 *
	 * @param string      $id     Optimization id.
	 * @param string|null $reason When given, the optimization is remembered as disabled and not re-applied automatically.
	 * @param string      $code   Machine readable reason code.
	 */
	public function deactivate( string $id, ?string $reason = null, string $code = '' ): void {
		$data = $this->all();
		unset( $data['active'][ $id ] );
		if ( null !== $reason ) {
			$data['disabled'][ $id ] = array(
				'reason' => $reason,
				'code'   => $code,
				'at'     => time(),
			);
		}
		$this->save( $data );
	}

	/**
	 * Forget that an optimization was disabled (allows it to be applied automatically again).
	 *
	 * @param string $id Optimization id.
	 */
	public function clear_disabled( string $id ): void {
		$data = $this->all();
		if ( isset( $data['disabled'][ $id ] ) ) {
			unset( $data['disabled'][ $id ] );
			$this->save( $data );
		}
	}

	/**
	 * Disabled record for an optimization.
	 *
	 * @param string $id Optimization id.
	 * @return array{reason:string,code:string,at:int}|null
	 */
	public function disabled( string $id ): ?array {
		return $this->all()['disabled'][ $id ] ?? null;
	}

	/**
	 * Page/template exclusions for an optimization.
	 *
	 * @param string $id Optimization id.
	 * @return string[]
	 */
	public function page_exclusions( string $id ): array {
		return $this->all()['page_exclusions'][ $id ] ?? array();
	}

	/**
	 * Exclude an optimization on a page or template only (instead of globally).
	 *
	 * @param string $id  Optimization id.
	 * @param string $key "tpl:<template key>" or "url:<path>".
	 */
	public function add_page_exclusion( string $id, string $key ): void {
		$data = $this->all();
		$list = $data['page_exclusions'][ $id ] ?? array();
		if ( ! in_array( $key, $list, true ) ) {
			$list[]                           = $key;
			$data['page_exclusions'][ $id ] = array_slice( $list, -100 );
			$this->save( $data );
		}
	}

	/**
	 * Remove all page exclusions of an optimization.
	 *
	 * @param string $id Optimization id.
	 */
	public function clear_page_exclusions( string $id ): void {
		$data = $this->all();
		unset( $data['page_exclusions'][ $id ] );
		$this->save( $data );
	}

	/**
	 * Set a scalar field.
	 *
	 * @param string $key   Field.
	 * @param mixed  $value Value.
	 */
	public function set( string $key, $value ): void {
		$data         = $this->all();
		$data[ $key ] = $value;
		$this->save( $data );
	}

	/**
	 * Get a field.
	 *
	 * @param string $key Field.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Replace the state (snapshot restore).
	 *
	 * @param array<string,mixed> $data State.
	 */
	public function replace( array $data ): void {
		$current          = $this->all();
		$data['revision'] = (int) $current['revision'];
		$this->save( $data );
	}

	/**
	 * Drop the in-memory copy.
	 */
	public function flush(): void {
		$this->data = null;
	}
}
