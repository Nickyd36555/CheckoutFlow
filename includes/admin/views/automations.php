<?php
/**
 * Automation list + create from recipe.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\DB;
use CheckoutFlow\Admin\Admin;
use CheckoutFlow\Marketing\Automations;
use CheckoutFlow\Marketing\Campaigns;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$rows     = $wpdb->get_results( 'SELECT id FROM ' . DB::t( 'automations' ) . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$triggers = Automations::triggers();
?>
<h1><?php esc_html_e( 'Automations', 'checkoutflow' ); ?></h1>
<p class="cf-muted"><?php esc_html_e( 'Emails that send themselves when something happens in your store.', 'checkoutflow' ); ?></p>

<table class="widefat striped cf-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Name', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Trigger', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Emails', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Sent', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Open / click', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Revenue', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $rows ) : ?>
		<tr><td colspan="7"><?php esc_html_e( 'No automations yet. Create one below.', 'checkoutflow' ); ?></td></tr>
	<?php endif; ?>
	<?php
	foreach ( $rows as $r ) :
		$a    = Automations::get( $r['id'] );
		$s    = Campaigns::stats( 'automation', $a['id'] );
		$sent = (int) $s['sent'];
		?>
		<tr>
			<td><strong><a href="<?php echo esc_url( Admin::url( 'automations', array( 'id' => $a['id'] ) ) ); ?>"><?php echo esc_html( $a['name'] ); ?></a></strong></td>
			<td><?php echo esc_html( isset( $triggers[ $a['trigger_type'] ] ) ? $triggers[ $a['trigger_type'] ]['label'] : $a['trigger_type'] ); ?></td>
			<td><?php echo esc_html( count( $a['steps'] ) ); ?></td>
			<td><?php echo esc_html( number_format_i18n( $sent ) ); ?></td>
			<td><?php echo esc_html( $sent ? round( $s['opened'] / $sent * 100 ) . '% / ' . round( $s['clicked'] / $sent * 100 ) . '%' : '–' ); ?></td>
			<td><?php echo wp_kses_post( wc_price( $s['revenue'] ) ); ?></td>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cf_automation_action">
					<input type="hidden" name="id" value="<?php echo esc_attr( $a['id'] ); ?>">
					<?php wp_nonce_field( 'cf_automation_action' ); ?>
					<?php if ( 'active' === $a['status'] ) : ?>
						<span class="cf-badge cf-badge-active"><?php esc_html_e( 'active', 'checkoutflow' ); ?></span>
						<button name="do" value="pause" class="button-link"><?php esc_html_e( 'Pause', 'checkoutflow' ); ?></button>
					<?php else : ?>
						<span class="cf-badge"><?php esc_html_e( 'paused', 'checkoutflow' ); ?></span>
						<button name="do" value="activate" class="button-link"><?php esc_html_e( 'Activate', 'checkoutflow' ); ?></button>
					<?php endif; ?>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<div class="cf-card">
	<h2><?php esc_html_e( 'Create an automation', 'checkoutflow' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-recipes">
		<input type="hidden" name="action" value="cf_new_automation">
		<?php wp_nonce_field( 'cf_new_automation' ); ?>
		<?php foreach ( Automations::recipes() as $key => $recipe ) : ?>
			<button type="submit" name="recipe" value="<?php echo esc_attr( $key ); ?>" class="cf-recipe">
				<strong><?php echo esc_html( $recipe['label'] ); ?></strong>
				<span><?php echo esc_html( $triggers[ $recipe['trigger'] ]['desc'] ); ?></span>
			</button>
		<?php endforeach; ?>
	</form>
</div>
