<?php
/**
 * Community plugins with real-time frontend features (BuddyPress, BuddyBoss, bbPress, PeepSo).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Community profile.
 */
final class CommunityProfile extends AbstractProfile {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'community';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Community plugins (BuddyPress, bbPress)', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->env->class_exists( 'BuddyPress' ) || $this->env->class_exists( 'bbPress' )
			|| $this->any_plugin( 'buddypress', 'buddyboss-platform', 'bbpress', 'peepso-core', 'peepso' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, array( 'buddypress', 'buddyboss', 'bbpress', 'peepso' ), false, true );
		$rules->disable( 'heartbeat_frontend', __( 'Community features such as notifications and activity streams use WordPress Heartbeat in real time.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'page_cache', 5, __( 'Community pages change often; logged-in members always bypass the cache.', 'sh-speed-optimizer' ) );
	}
}
