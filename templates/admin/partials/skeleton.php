<?php
/**
 * Loading placeholder shown until admin.js has loaded the data.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="shso-skeleton" aria-hidden="true">
	<div class="shso-skeleton__line shso-skeleton__line--wide"></div>
	<div class="shso-skeleton__line"></div>
	<div class="shso-skeleton__line shso-skeleton__line--short"></div>
</div>
<p class="screen-reader-text"><?php esc_html_e( 'Loading…', 'sh-speed-optimizer' ); ?></p>
