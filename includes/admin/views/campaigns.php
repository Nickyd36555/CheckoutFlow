<?php
/**
 * Campaign list.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\DB;
use CheckoutFlow\Admin\Admin;
use CheckoutFlow\Marketing\Campaigns;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$rows = $wpdb->get_results( 'SELECT * FROM ' . DB::t( 'campaigns' ) . ' ORDER BY id DESC LIMIT 200', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
?>
<h1 class="wp-heading-inline"><?php esc_html_e( 'Campaigns', 'checkoutflow' ); ?></h1>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-inline">
	<input type="hidden" name="action" value="cf_save_campaign">
	<?php wp_nonce_field( 'cf_save_campaign' ); ?>
	<button class="page-title-action"><?php esc_html_e( 'New campaign', 'checkoutflow' ); ?></button>
</form>
<hr class="wp-header-end">

<table class="widefat striped cf-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Name', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Recipients', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Opens', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Clicks', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Revenue', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Date', 'checkoutflow' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $rows ) : ?>
		<tr><td colspan="7"><?php esc_html_e( 'No campaigns yet. Create one to email your customers about a sale, new product or announcement.', 'checkoutflow' ); ?></td></tr>
	<?php endif; ?>
	<?php
	foreach ( $rows as $c ) :
		$s    = Campaigns::stats( 'campaign', $c['id'] );
		$sent = (int) $s['sent'];
		$date = $c['sent_at'] ? $c['sent_at'] : ( $c['scheduled_at'] ? $c['scheduled_at'] : $c['created_at'] );
		?>
		<tr>
			<td><strong><a href="<?php echo esc_url( Admin::url( 'campaigns', array( 'id' => $c['id'] ) ) ); ?>"><?php echo esc_html( $c['name'] ); ?></a></strong></td>
			<td><span class="cf-badge cf-badge-<?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( $c['status'] ); ?></span></td>
			<td><?php echo esc_html( number_format_i18n( (int) $c['recipients'] ) ); ?></td>
			<td><?php echo esc_html( $sent ? round( $s['opened'] / $sent * 100, 1 ) . '%' : '–' ); ?></td>
			<td><?php echo esc_html( $sent ? round( $s['clicked'] / $sent * 100, 1 ) . '%' : '–' ); ?></td>
			<td><?php echo wp_kses_post( wc_price( $s['revenue'] ) ); ?></td>
			<td><?php echo esc_html( get_date_from_gmt( $date, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
