<?php
/**
 * Automation editor.
 *
 * @package CheckoutFlow
 * @var array $automation
 */

use CheckoutFlow\Admin\Admin;
use CheckoutFlow\Marketing\Automations;
use CheckoutFlow\Marketing\Campaigns;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$triggers = Automations::triggers();
$a        = $automation;
$step_stats = array();
foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT step, SUM(status=\'sent\') sent, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked, SUM(status=\'pending\') pending FROM ' . \CheckoutFlow\DB::t( 'queue' ) . " WHERE source = 'automation' AND source_id = %d GROUP BY step", $a['id'] ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$step_stats[ (int) $row['step'] ] = $row;
}
$totals = Campaigns::stats( 'automation', $a['id'] );
?>
<p><a href="<?php echo esc_url( Admin::url( 'automations' ) ); ?>">&larr; <?php esc_html_e( 'All automations', 'checkoutflow' ); ?></a></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-automation">
	<input type="hidden" name="action" value="cf_save_automation">
	<input type="hidden" name="id" value="<?php echo esc_attr( $a['id'] ); ?>">
	<?php wp_nonce_field( 'cf_save_automation' ); ?>

	<div class="cf-title-row">
		<input type="text" name="name" class="cf-title-input" value="<?php echo esc_attr( $a['name'] ); ?>" aria-label="<?php esc_attr_e( 'Automation name', 'checkoutflow' ); ?>">
		<label class="cf-switch">
			<input type="checkbox" name="status" value="active" <?php checked( $a['status'], 'active' ); ?>>
			<span><?php esc_html_e( 'Active', 'checkoutflow' ); ?></span>
		</label>
		<button type="submit" name="do" value="save" class="button button-primary"><?php esc_html_e( 'Save', 'checkoutflow' ); ?></button>
	</div>

	<p class="cf-muted">
		<?php
		/* translators: 1: sent, 2: revenue */
		echo wp_kses_post( sprintf( __( '%1$s emails sent · %2$s revenue', 'checkoutflow' ), number_format_i18n( (int) $totals['sent'] ), wc_price( $totals['revenue'] ) ) );
		?>
	</p>

	<div class="cf-flow">
		<div class="cf-node cf-node-trigger">
			<div class="cf-node-label"><?php esc_html_e( 'When', 'checkoutflow' ); ?></div>
			<select name="trigger_type" id="cf-trigger">
				<?php foreach ( $triggers as $key => $t ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $a['trigger_type'], $key ); ?>><?php echo esc_html( $t['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php foreach ( $triggers as $key => $t ) : ?>
				<div class="cf-trigger-settings" data-trigger="<?php echo esc_attr( $key ); ?>" <?php echo $key === $a['trigger_type'] ? '' : 'hidden'; ?>>
					<p class="description"><?php echo esc_html( $t['desc'] ); ?></p>
					<?php
					foreach ( $t['settings'] as $sk => $sf ) :
						$v = isset( $a['trigger_settings'][ $sk ] ) && $key === $a['trigger_type'] ? $a['trigger_settings'][ $sk ] : $sf['default'];
						$n = 'trigger_settings[' . $sk . ']';
						?>
						<p>
							<?php if ( 'checkbox' === $sf['type'] ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>" value="1" <?php checked( ! empty( $v ) ); ?> <?php disabled( $key !== $a['trigger_type'] ); ?>> <?php echo esc_html( $sf['label'] ); ?></label>
							<?php else : ?>
								<label><?php echo esc_html( $sf['label'] ); ?><br><input type="<?php echo 'number' === $sf['type'] ? 'number' : 'text'; ?>" name="<?php echo esc_attr( $n ); ?>" value="<?php echo esc_attr( $v ); ?>" class="<?php echo 'number' === $sf['type'] ? 'small-text' : 'regular-text'; ?>" <?php disabled( $key !== $a['trigger_type'] ); ?>></label>
							<?php endif; ?>
						</p>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		foreach ( $a['steps'] as $i => $step ) :
			$st       = isset( $step_stats[ $i ] ) ? $step_stats[ $i ] : array( 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'pending' => 0 );
			$edit_url = add_query_arg( array( 'page' => 'checkoutflow-email', 'type' => 'automation', 'id' => $a['id'], 'step' => $i ), admin_url( 'admin.php' ) );
			?>
			<div class="cf-connector">
				<span><?php esc_html_e( 'wait until', 'checkoutflow' ); ?></span>
				<input type="number" min="0" step="0.25" class="small-text" name="steps[<?php echo (int) $i; ?>][delay]" value="<?php echo esc_attr( $step['delay'] ); ?>">
				<select name="steps[<?php echo (int) $i; ?>][unit]">
					<?php foreach ( array( 'minutes' => __( 'minutes', 'checkoutflow' ), 'hours' => __( 'hours', 'checkoutflow' ), 'days' => __( 'days', 'checkoutflow' ) ) as $u => $ul ) : ?>
						<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $step['unit'], $u ); ?>><?php echo esc_html( $ul ); ?></option>
					<?php endforeach; ?>
				</select>
				<span><?php esc_html_e( 'after the trigger', 'checkoutflow' ); ?></span>
			</div>
			<div class="cf-node">
				<div class="cf-node-label">
					<?php
					/* translators: %d: step number */
					echo esc_html( sprintf( __( 'Send email %d', 'checkoutflow' ), $i + 1 ) );
					?>
				</div>
				<div class="cf-node-subject"><?php echo esc_html( $step['email']['subject'] ? $step['email']['subject'] : __( '(no subject)', 'checkoutflow' ) ); ?></div>
				<div class="cf-node-stats">
					<?php
					$sent = (int) $st['sent'];
					/* translators: 1: sent, 2: open rate, 3: click rate, 4: queued */
					echo esc_html( sprintf( __( '%1$d sent · %2$s opened · %3$s clicked · %4$d queued', 'checkoutflow' ), $sent, $sent ? round( $st['opened'] / $sent * 100 ) . '%' : '–', $sent ? round( $st['clicked'] / $sent * 100 ) . '%' : '–', (int) $st['pending'] ) );
					?>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit email', 'checkoutflow' ); ?></a>
					<?php if ( count( $a['steps'] ) > 1 ) : ?>
						<button type="submit" name="do" value="remove_step_<?php echo (int) $i; ?>" class="button-link cf-delete" data-confirm="<?php esc_attr_e( 'Remove this email from the automation? Queued emails for this automation will be cancelled.', 'checkoutflow' ); ?>"><?php esc_html_e( 'Remove', 'checkoutflow' ); ?></button>
					<?php endif; ?>
				</p>
			</div>
		<?php endforeach; ?>

		<div class="cf-connector"><button type="submit" name="do" value="add_step" class="button"><?php esc_html_e( '+ Add another email', 'checkoutflow' ); ?></button></div>
		<div class="cf-node cf-node-end"><?php esc_html_e( 'End', 'checkoutflow' ); ?></div>
	</div>
	<p class="description"><?php esc_html_e( 'Save changes to timing and trigger before editing an email.', 'checkoutflow' ); ?></p>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-danger">
	<input type="hidden" name="action" value="cf_automation_action">
	<input type="hidden" name="id" value="<?php echo esc_attr( $a['id'] ); ?>">
	<?php wp_nonce_field( 'cf_automation_action' ); ?>
	<button type="submit" name="do" value="delete" class="button-link cf-delete" data-confirm="<?php esc_attr_e( 'Delete this automation and cancel its queued emails?', 'checkoutflow' ); ?>"><?php esc_html_e( 'Delete automation', 'checkoutflow' ); ?></button>
</form>
