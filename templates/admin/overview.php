<?php
/**
 * Overview page shell. admin.js renders the status from GET /shso/v1/status.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
?>
<div id="shso-app" class="shso-app" data-shso-view="overview" aria-busy="true">
	<div class="shso-grid">
		<div class="shso-card"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
		<div class="shso-card" aria-hidden="true"><div class="shso-skeleton"><div class="shso-skeleton__line shso-skeleton__line--wide"></div><div class="shso-skeleton__line"></div></div></div>
		<div class="shso-card" aria-hidden="true"><div class="shso-skeleton"><div class="shso-skeleton__line shso-skeleton__line--wide"></div><div class="shso-skeleton__line"></div></div></div>
	</div>
</div>
<?php
require __DIR__ . '/partials/footer.php';
