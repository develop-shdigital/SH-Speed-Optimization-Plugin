<?php
/**
 * Optimization registry.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily instantiates optimizations from the catalog.
 */
final class Registry {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Id → class map.
	 *
	 * @var array<string,class-string<OptimizationInterface>>
	 */
	private array $map;

	/**
	 * Instances.
	 *
	 * @var array<string,OptimizationInterface>
	 */
	private array $instances = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		/**
		 * Filters the optimization catalog (id => class name). Classes must implement OptimizationInterface.
		 *
		 * @param array<string,string> $map Catalog.
		 */
		$map       = (array) apply_filters( 'shso_optimization_catalog', Catalog::map() );
		$this->map = array();
		foreach ( $map as $id => $class ) {
			if ( is_string( $id ) && is_string( $class ) && preg_match( '/^[a-z0-9_]+$/', $id ) ) {
				$this->map[ $id ] = $class;
			}
		}
	}

	/**
	 * All ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->map );
	}

	/**
	 * Whether an id exists.
	 *
	 * @param string $id Id.
	 */
	public function has( string $id ): bool {
		return isset( $this->map[ $id ] );
	}

	/**
	 * Get an optimization.
	 *
	 * @param string $id Id.
	 */
	public function get( string $id ): ?OptimizationInterface {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->map[ $id ] ) ) {
			return null;
		}

		$class = $this->map[ $id ];
		if ( ! class_exists( $class ) || ! is_subclass_of( $class, OptimizationInterface::class ) ) {
			return null;
		}

		$instance = new $class( $this->plugin );
		if ( $instance->id() !== $id ) {
			return null;
		}

		$this->instances[ $id ] = $instance;
		return $instance;
	}

	/**
	 * All optimizations.
	 *
	 * @return array<string,OptimizationInterface>
	 */
	public function all(): array {
		$all = array();
		foreach ( $this->ids() as $id ) {
			$optimization = $this->get( $id );
			if ( null !== $optimization ) {
				$all[ $id ] = $optimization;
			}
		}
		return $all;
	}

	/**
	 * Optimizations grouped by category, in apply order.
	 *
	 * @return array<string,array<string,OptimizationInterface>>
	 */
	public function by_category(): array {
		$groups = array_fill_keys( Category::apply_order(), array() );
		foreach ( $this->all() as $id => $optimization ) {
			$groups[ $optimization->category() ][ $id ] = $optimization;
		}
		return array_filter( $groups );
	}
}
