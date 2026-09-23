<?php
/**
 * Network defaults page (multisite). Classic form, works without JavaScript.
 *
 * @package SH\SpeedOptimizer
 *
 * @var array<string,mixed> $view View data (fields, labels, values, locked, lockable, action_url, action, nonce, updated).
 */

defined( 'ABSPATH' ) || exit;

$shso_locked   = (array) $view['locked'];
$shso_lockable = (array) $view['lockable'];
$shso_values   = (array) $view['values'];
$shso_labels   = (array) $view['labels'];
?>
<div class="wrap shso-network">
	<h1><?php esc_html_e( 'SH Speed Network Defaults', 'sh-speed-optimizer' ); ?></h1>

	<?php if ( ! empty( $view['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Network defaults saved.', 'sh-speed-optimizer' ); ?></p></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'These defaults apply to every site in the network. Site administrators can change them on their own site unless you lock them.', 'sh-speed-optimizer' ); ?></p>

	<form method="post" action="<?php echo esc_url( (string) $view['action_url'] ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( (string) $view['action'] ); ?>">
		<?php wp_nonce_field( (string) $view['nonce'] ); ?>

		<table class="form-table" role="presentation">
			<tbody>
			<?php foreach ( (array) $view['fields'] as $shso_key => $shso_type ) : ?>
				<?php
				$shso_id    = 'shso-network-' . $shso_key;
				$shso_label = $shso_labels[ $shso_key ]['label'] ?? $shso_key;
				$shso_help  = $shso_labels[ $shso_key ]['help'] ?? '';
				$shso_value = $shso_values[ $shso_key ] ?? null;
				?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $shso_id ); ?>"><?php echo esc_html( $shso_label ); ?></label>
					</th>
					<td>
						<?php if ( 'int' === $shso_type ) : ?>
							<input type="number" class="small-text" min="1" max="720" step="1" id="<?php echo esc_attr( $shso_id ); ?>" name="shso_defaults[<?php echo esc_attr( $shso_key ); ?>]" value="<?php echo esc_attr( (string) (int) $shso_value ); ?>" aria-describedby="<?php echo esc_attr( $shso_id ); ?>-help">
						<?php else : ?>
							<input type="hidden" name="shso_defaults[<?php echo esc_attr( $shso_key ); ?>]" value="0">
							<input type="checkbox" id="<?php echo esc_attr( $shso_id ); ?>" name="shso_defaults[<?php echo esc_attr( $shso_key ); ?>]" value="1" <?php checked( (bool) $shso_value ); ?> aria-describedby="<?php echo esc_attr( $shso_id ); ?>-help">
						<?php endif; ?>
						<p class="description" id="<?php echo esc_attr( $shso_id ); ?>-help"><?php echo esc_html( $shso_help ); ?></p>
						<?php if ( in_array( $shso_key, $shso_lockable, true ) ) : ?>
							<p>
								<label>
									<input type="checkbox" name="shso_locked[<?php echo esc_attr( $shso_key ); ?>]" value="1" <?php checked( in_array( $shso_key, $shso_locked, true ) ); ?>>
									<?php esc_html_e( 'Lock for all sites', 'sh-speed-optimizer' ); ?>
								</label>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php submit_button( __( 'Save network defaults', 'sh-speed-optimizer' ) ); ?>
	</form>
</div>
