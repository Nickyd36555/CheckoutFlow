<?php
/**
 * Abandoned carts list.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\DB;
use CheckoutFlow\Admin\Admin;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$table  = DB::t( 'carts' );
$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'abandoned'; // phpcs:ignore WordPress.Security.NonceVerification
$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
if ( ! in_array( $status, array( 'abandoned', 'active', 'recovered', 'all' ), true ) ) {
	$status = 'abandoned';
}
$where  = 'all' === $status ? '1=1' : $wpdb->prepare( 'status = %s', $status );
$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT 50 OFFSET %d", ( $paged - 1 ) * 50 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$counts = $wpdb->get_results( "SELECT status, COUNT(*) n, SUM(total) t FROM {$table} GROUP BY status", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$sent   = array();
if ( $rows ) {
	$refs = array();
	foreach ( $rows as $r ) {
		$refs[] = $wpdb->prepare( '%s', 'cart:' . $r['id'] );
	}
	foreach ( $wpdb->get_results( 'SELECT ref, COUNT(*) n FROM ' . DB::t( 'queue' ) . " WHERE status = 'sent' AND ref IN (" . implode( ',', $refs ) . ') GROUP BY ref', ARRAY_A ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sent[ $s['ref'] ] = (int) $s['n'];
	}
}
$labels = array(
	'abandoned' => __( 'Abandoned', 'checkoutflow' ),
	'active'    => __( 'In checkout now', 'checkoutflow' ),
	'recovered' => __( 'Recovered', 'checkoutflow' ),
	'all'       => __( 'All', 'checkoutflow' ),
);
?>
<h1><?php esc_html_e( 'Abandoned Carts', 'checkoutflow' ); ?></h1>

<div class="cf-kpis">
	<div class="cf-kpi"><span><?php esc_html_e( 'Abandoned', 'checkoutflow' ); ?></span><strong><?php echo esc_html( isset( $counts['abandoned'] ) ? number_format_i18n( $counts['abandoned']->n ) : 0 ); ?></strong><small><?php echo wp_kses_post( wc_price( isset( $counts['abandoned'] ) ? $counts['abandoned']->t : 0 ) ); ?></small></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Recovered', 'checkoutflow' ); ?></span><strong><?php echo esc_html( isset( $counts['recovered'] ) ? number_format_i18n( $counts['recovered']->n ) : 0 ); ?></strong><small><?php echo wp_kses_post( wc_price( isset( $counts['recovered'] ) ? $counts['recovered']->t : 0 ) ); ?></small></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'In checkout now', 'checkoutflow' ); ?></span><strong><?php echo esc_html( isset( $counts['active'] ) ? number_format_i18n( $counts['active']->n ) : 0 ); ?></strong></div>
</div>

<ul class="subsubsub">
	<?php
	$links = array();
	foreach ( $labels as $key => $label ) {
		$links[] = sprintf( '<li><a href="%s" class="%s">%s</a>', esc_url( Admin::url( 'carts', array( 'status' => $key ) ) ), $key === $status ? 'current' : '', esc_html( $label ) );
	}
	echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	?>
</ul>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="cf_cart_action">
	<?php wp_nonce_field( 'cf_cart_action' ); ?>
	<div class="tablenav top">
		<button name="do" value="delete" class="button" data-confirm="<?php esc_attr_e( 'Delete the selected carts?', 'checkoutflow' ); ?>"><?php esc_html_e( 'Delete selected', 'checkoutflow' ); ?></button>
		<?php
		$pages = (int) ceil( $total / 50 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div>';
		}
		?>
	</div>
	<table class="widefat striped cf-table">
		<thead>
			<tr>
				<td class="check-column"><input type="checkbox" class="cf-check-all"></td>
				<th><?php esc_html_e( 'Customer', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Items', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Total', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Emails sent', 'checkoutflow' ); ?></th>
				<th><?php esc_html_e( 'Last activity', 'checkoutflow' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'Nothing here yet.', 'checkoutflow' ); ?></td></tr>
		<?php endif; ?>
		<?php
		foreach ( $rows as $r ) :
			$names = array();
			foreach ( DB::json( $r['items'] ) as $item ) {
				$p       = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
				$names[] = ( $p ? $p->get_name() : '#' . $item['product_id'] ) . ' × ' . wc_stock_amount( $item['quantity'] );
			}
			?>
			<tr>
				<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $r['id'] ); ?>"></th>
				<td><strong><?php echo esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) ); ?></strong><br><?php echo esc_html( $r['email'] ); ?><?php echo $r['phone'] ? '<br>' . esc_html( $r['phone'] ) : ''; ?></td>
				<td><?php echo esc_html( implode( ', ', $names ) ); ?></td>
				<td><?php echo wp_kses_post( wc_price( $r['total'], array( 'currency' => $r['currency'] ) ) ); ?></td>
				<td>
					<span class="cf-badge cf-badge-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : $r['status'] ); ?></span>
					<?php if ( $r['order_id'] && 'recovered' === $r['status'] ) : ?>
						<br><a href="<?php echo esc_url( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && method_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'get_order_admin_edit_url' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $r['order_id'] ) : admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ); ?>">
							<?php
							/* translators: %d: order id */
							echo esc_html( sprintf( __( 'Order #%d', 'checkoutflow' ), $r['order_id'] ) );
							?>
						</a>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( isset( $sent[ 'cart:' . $r['id'] ] ) ? $sent[ 'cart:' . $r['id'] ] : 0 ); ?></td>
				<td><?php echo esc_html( human_time_diff( strtotime( $r['updated_at'] . ' UTC' ) ) . ' ' . __( 'ago', 'checkoutflow' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</form>
