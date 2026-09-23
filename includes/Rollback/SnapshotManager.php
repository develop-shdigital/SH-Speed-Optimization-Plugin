<?php
/**
 * Lightweight configuration snapshots.
 *
 * Before the engine or the administrator changes which optimizations are
 * active, the current settings and engine state are stored. Restoring a
 * snapshot re-applies or rolls back optimizations so the site returns to
 * exactly that configuration. No full-site backups — configuration only.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Rollback;

use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot manager.
 */
final class SnapshotManager {

	public const OPTION = 'shso_snapshots';
	public const MAX    = 20;

	/**
	 * Plugin container.
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
	 * Create a snapshot of the current configuration.
	 *
	 * @param string $label Plain-language label ("Before automatic optimization").
	 * @param string $kind  optimize|manual|restore.
	 * @return int Snapshot id.
	 */
	public function create( string $label, string $kind = 'optimize' ): int {
		$snapshots = $this->all();
		$id        = (int) ( empty( $snapshots ) ? 1 : max( array_keys( $snapshots ) ) + 1 );
		$state     = $this->plugin->state()->all();

		$snapshots[ $id ] = array(
			'id'       => $id,
			'time'     => time(),
			'kind'     => $kind,
			'label'    => mb_substr( $label, 0, 200 ),
			'user'     => get_current_user_id(),
			'settings' => get_option( \SH\SpeedOptimizer\Core\Settings::OPTION, array() ),
			'state'    => array(
				'active'          => $state['active'],
				'disabled'        => $state['disabled'],
				'page_exclusions' => $state['page_exclusions'],
			),
		);

		if ( count( $snapshots ) > self::MAX ) {
			ksort( $snapshots );
			$snapshots = array_slice( $snapshots, -self::MAX, null, true );
		}

		update_option( self::OPTION, $snapshots, false );
		return $id;
	}

	/**
	 * All snapshots keyed by id (oldest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		ksort( $raw );
		return $raw;
	}

	/**
	 * One snapshot.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Latest snapshot.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest(): ?array {
		$all = $this->all();
		return empty( $all ) ? null : end( $all );
	}

	/**
	 * The snapshot "Undo Last Optimization" restores: the newest one that was
	 * not itself created by a restore.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest_undoable(): ?array {
		foreach ( array_reverse( $this->all(), true ) as $snapshot ) {
			if ( 'restore' !== ( $snapshot['kind'] ?? '' ) ) {
				return $snapshot;
			}
		}
		return null;
	}

	/**
	 * Delete a snapshot.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		$snapshots = $this->all();
		unset( $snapshots[ $id ] );
		update_option( self::OPTION, $snapshots, false );
	}

	/**
	 * Undo the last change: restore the latest undoable snapshot and consume it,
	 * so pressing Undo again walks further back in history.
	 *
	 * @return true|\WP_Error
	 */
	public function undo() {
		$snapshot = $this->latest_undoable();
		if ( null === $snapshot ) {
			return new \WP_Error( 'shso_nothing_to_undo', __( 'There is nothing to undo.', 'sh-speed-optimizer' ) );
		}
		$result = $this->restore( (int) $snapshot['id'] );
		if ( true === $result ) {
			$this->delete( (int) $snapshot['id'] );
		}
		return $result;
	}

	/**
	 * Public list (newest first) without internal data.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function public_list(): array {
		$list = array();
		foreach ( array_reverse( $this->all(), true ) as $snapshot ) {
			$list[] = array(
				'id'         => (int) $snapshot['id'],
				'time'       => (int) $snapshot['time'],
				'time_label' => self::human_time( (int) $snapshot['time'] ),
				'label'      => (string) $snapshot['label'],
				'active'     => count( (array) ( $snapshot['state']['active'] ?? array() ) ),
			);
		}
		return $list;
	}

	/**
	 * Restore a snapshot: roll back optimizations that were not active then,
	 * re-apply those that were, restore settings and state.
	 *
	 * @param int $id Snapshot id.
	 * @return true|\WP_Error
	 */
	public function restore( int $id ) {
		$snapshot = $this->get( $id );
		if ( null === $snapshot ) {
			return new \WP_Error( 'shso_snapshot_missing', __( 'That restore point no longer exists.', 'sh-speed-optimizer' ) );
		}

		$registry = $this->plugin->registry();
		$current  = $this->plugin->state()->active_ids();
		$target   = array_keys( (array) ( $snapshot['state']['active'] ?? array() ) );

		// Remember the present so the restore itself can be undone.
		$this->create( __( 'Before restoring an earlier configuration', 'sh-speed-optimizer' ), 'restore' );

		foreach ( array_diff( $current, $target ) as $optimization_id ) {
			$optimization = $registry->get( $optimization_id );
			if ( null !== $optimization ) {
				try {
					$optimization->rollback();
				} catch ( \Throwable $e ) {
					$this->plugin->logger()->error( 'Rollback during restore failed.', array( 'optimization' => $optimization_id, 'error' => $e->getMessage() ), 'rollback' );
				}
			}
		}

		$failed = array();
		foreach ( array_diff( $target, $current ) as $optimization_id ) {
			$optimization = $registry->get( $optimization_id );
			if ( null === $optimization ) {
				$failed[] = $optimization_id;
				continue;
			}
			try {
				$result = $optimization->apply();
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'shso_apply_exception', $e->getMessage() );
			}
			if ( is_wp_error( $result ) ) {
				$failed[] = $optimization_id;
			}
		}

		$state = (array) $snapshot['state'];
		foreach ( $failed as $optimization_id ) {
			unset( $state['active'][ $optimization_id ] );
		}

		$this->plugin->settings()->replace( (array) $snapshot['settings'] );
		$merged = array_merge( $this->plugin->state()->all(), $state );
		$this->plugin->state()->replace( $merged );

		$this->plugin->logger()->event(
			'restored',
			/* translators: %s: restore point label */
			sprintf( __( 'Restored configuration: %s', 'sh-speed-optimizer' ), (string) $snapshot['label'] ),
			'',
			array(
				'snapshot' => $id,
				'failed'   => $failed,
			)
		);

		/**
		 * Fires after a configuration snapshot was restored.
		 *
		 * @param int   $id       Snapshot id.
		 * @param array $snapshot Snapshot data.
		 */
		do_action( 'shso_snapshot_restored', $id, $snapshot );

		$this->plugin->engine()->on_configuration_changed( 'snapshot_restored' );

		return true;
	}

	/**
	 * Human readable time ("Today, 8:42 PM").
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function human_time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}
		$format = (string) get_option( 'time_format', 'g:i a' );
		$day    = wp_date( 'Y-m-d', $timestamp );
		if ( wp_date( 'Y-m-d' ) === $day ) {
			/* translators: %s: time of day */
			return sprintf( __( 'Today, %s', 'sh-speed-optimizer' ), wp_date( $format, $timestamp ) );
		}
		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			/* translators: %s: time of day */
			return sprintf( __( 'Yesterday, %s', 'sh-speed-optimizer' ), wp_date( $format, $timestamp ) );
		}
		return wp_date( get_option( 'date_format', 'F j, Y' ) . ', ' . $format, $timestamp );
	}

	/**
	 * Day label for grouping history ("Today", "Yesterday", date).
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function day_label( int $timestamp ): string {
		$day = wp_date( 'Y-m-d', $timestamp );
		if ( wp_date( 'Y-m-d' ) === $day ) {
			return __( 'Today', 'sh-speed-optimizer' );
		}
		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			return __( 'Yesterday', 'sh-speed-optimizer' );
		}
		return (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $timestamp );
	}
}
