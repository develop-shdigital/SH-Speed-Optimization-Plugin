<?php
/**
 * Settings page shell. admin.js builds the form from GET /shso/v1/settings.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
?>
<div id="shso-app" class="shso-app" data-shso-view="settings">
	<form id="shso-settings-form" class="shso-settings" novalidate>
		<div id="shso-settings" class="shso-slot" aria-busy="true"><?php require __DIR__ . '/partials/skeleton.php'; ?></div>
	</form>
</div>
<?php
require __DIR__ . '/partials/footer.php';
