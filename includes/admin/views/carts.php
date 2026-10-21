<?php
/**
 * Carts: recoverable (abandoned), recovered, lost, and carts in checkout right now.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\DB;
use CheckoutFlow\Admin\Admin;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$table    = DB::t( 'carts' );
$qt       = DB::t( 'queue' );
$per_page = 50;
// phpcs:disable WordPress.Security.NonceVerification
$tab    = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'abandoned';
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
// phpcs:enable
$tabs = array(
	'abandoned' => __( 'Recoverable', 'checkoutflow' ),
	'recovered' => __( 'Recovered', 'checkoutflow' ),
	'lost'      => __( 'Lost', 'checkoutflow' ),
	'active'    => __( 'In checkout now', 'checkoutflow' ),
);
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'abandoned';
}

$where = array( $wpdb->prepare( 'status = %s', $tab ) );
if ( '' !== $search ) {
	$like    = '%' . $wpdb->esc_like( $search ) . '%';
	$where[] = $wpdb->prepare( '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR CONCAT(first_name, " ", last_name) LIKE %s)', $like, $like, $like, $like, $like );
}
$where_sql = implode( ' AND ', $where );
$order_by  = 'recovered' === $tab ? 'updated_at' : ( 'active' === $tab ? 'updated_at' : 'COALESCE(abandoned_at, updated_at)' );

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_by} DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ), ARRAY_A );
$counts = $wpdb->get_results( "SELECT status, COUNT(*) n, SUM(total) t FROM {$table} GROUP BY status", OBJECT_K );

// Recovery emails for the carts on this page.
$mails = array();
if ( $rows ) {
	$refs = array();
	foreach ( $rows as $r ) {
		$refs[] = $wpdb->prepare( '%s', 'cart:' . $r['id'] );
	}
	foreach ( $wpdb->get_results( "SELECT ref, status, subject, send_at, sent_at, opened_at, clicked_at, error FROM {$qt} WHERE ref IN (" . implode( ',', $refs ) . ') ORDER BY send_at ASC', ARRAY_A ) as $m ) {
		$mails[ $m['ref'] ][] = $m;
	}
}
// phpcs:enable

$n = static function ( $status ) use ( $counts ) {
	return isset( $counts[ $status ] ) ? (int) $counts[ $status ]->n : 0;
};
$v = static function ( $status ) use ( $counts ) {
	return isset( $counts[ $status ] ) ? (float) $counts[ $status ]->t : 0;
};
$closed = $n( 'recovered' ) + $n( 'lost' );
$rate   = $closed ? round( $n( 'recovered' ) / $closed * 100, 1 ) . '%' : '–';

$local = static function ( $gmt ) {
	return $gmt ? get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
};
$ago = static function ( $gmt ) {
	/* translators: %s: time difference, e.g. "5 mins" */
	return $gmt ? sprintf( __( '%s ago', 'checkoutflow' ), human_time_diff( strtotime( $gmt . ' UTC' ) ) ) : '';
};
$order_url = static function ( $id ) {
	return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && method_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'get_order_admin_edit_url' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $id ) : admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
};

/**
 * Recovery-email status for a cart: [label, css class, detail].
 */
$email_status = static function ( $r, $list ) use ( $ago ) {
	$pending = array_filter(
		$list,
		function ( $m ) {
			return in_array( $m['status'], array( 'pending', 'sending', 'paused' ), true );
		}
	);
	$sent    = array_filter(
		$list,
		function ( $m ) {
			return 'sent' === $m['status'];
		}
	);
	$stopped = array_filter(
		$list,
		function ( $m ) {
			return 'cancelled' === $m['status'];
		}
	);
	if ( 'recovered' === $r['status'] ) {
		/* translators: %d: number of emails */
		return array( __( 'Recovered', 'checkoutflow' ), 'recovered', $sent ? sprintf( _n( 'after %d email', 'after %d emails', count( $sent ), 'checkoutflow' ), count( $sent ) ) : __( 'customer came back', 'checkoutflow' ) );
	}
	if ( 'active' === $r['status'] ) {
		return array( __( 'In checkout', 'checkoutflow' ), 'active', '' );
	}
	if ( $pending ) {
		$next = reset( $pending );
		$when = strtotime( $next['send_at'] . ' UTC' );
		/* translators: 1: emails sent, 2: time until next email */
		$detail = sprintf( __( '%1$d sent · next %2$s', 'checkoutflow' ), count( $sent ), $when > time() ? sprintf( /* translators: %s: time */ __( 'in %s', 'checkoutflow' ), human_time_diff( $when ) ) : __( 'now', 'checkoutflow' ) );
		return array( __( 'In progress', 'checkoutflow' ), 'sending', $detail );
	}
	if ( $sent ) {
		$opened = count(
			array_filter(
				$sent,
				function ( $m ) {
					return ! empty( $m['opened_at'] );
				}
			)
		);
		/* translators: 1: emails sent, 2: opened */
		return array( 'lost' === $r['status'] ? __( 'Lost', 'checkoutflow' ) : __( 'Emails sent', 'checkoutflow' ), 'lost' === $r['status'] ? 'lost' : 'sent', sprintf( __( '%1$d sent · %2$d opened', 'checkoutflow' ), count( $sent ), $opened ) );
	}
	if ( $stopped ) {
		$first = reset( $stopped );
		return array( __( 'Skipped', 'checkoutflow' ), 'skipped', $first['error'] ? $first['error'] : '' );
	}
	return array( 'lost' === $r['status'] ? __( 'Lost', 'checkoutflow' ) : __( 'Not emailed', 'checkoutflow' ), 'lost' === $r['status'] ? 'lost' : 'none', __( 'no recovery email', 'checkoutflow' ) );
};
?>
<div class="cf-dash-head">
	<h1><?php esc_html_e( 'Carts', 'checkoutflow' ); ?></h1>
</div>

<div class="cf-kpis cf-sales-kpis">
	<div class="cf-kpi"><span><?php esc_html_e( 'Recoverable', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( $n( 'abandoned' ) ) ); ?></strong><small><?php echo wp_kses_post( wc_price( $v( 'abandoned' ) ) ); ?></small></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Recovered', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( $n( 'recovered' ) ) ); ?></strong><small><?php echo wp_kses_post( wc_price( $v( 'recovered' ) ) ); ?></small></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Lost', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( $n( 'lost' ) ) ); ?></strong><small><?php echo wp_kses_post( wc_price( $v( 'lost' ) ) ); ?></small></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Recovery rate', 'checkoutflow' ); ?></span><strong><?php echo esc_html( $rate ); ?></strong><small><?php esc_html_e( 'of carts that closed', 'checkoutflow' ); ?></small></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'In checkout now', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( $n( 'active' ) ) ); ?></strong></div>
</div>

<div class="cf-carts-layout">
	<nav class="cf-carts-nav" aria-label="<?php esc_attr_e( 'Cart status', 'checkoutflow' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a href="<?php echo esc_url( Admin::url( 'carts', array( 'status' => $key ) ) ); ?>" class="<?php echo $key === $tab ? 'is-current' : ''; ?>"><?php echo esc_html( $label ); ?> <span><?php echo esc_html( number_format_i18n( $n( $key ) ) ); ?></span></a>
		<?php endforeach; ?>
	</nav>

	<div class="cf-carts-main">
		<div class="cf-carts-head">
			<h2>
				<?php echo esc_html( 'abandoned' === $tab ? __( 'Recoverable Carts', 'checkoutflow' ) : ( 'active' === $tab ? __( 'In checkout now', 'checkoutflow' ) : $tabs[ $tab ] . ' ' . __( 'Carts', 'checkoutflow' ) ) ); ?>
				<span class="cf-muted">
					<?php
					/* translators: %s: number of carts */
					echo esc_html( sprintf( _n( '(%s result)', '(%s results)', $total, 'checkoutflow' ), number_format_i18n( $total ) ) );
					?>
				</span>
			</h2>
			<form method="get" class="cf-carts-search">
				<input type="hidden" name="page" value="checkoutflow-carts">
				<input type="hidden" name="status" value="<?php echo esc_attr( $tab ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email, name or phone…', 'checkoutflow' ); ?>">
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cf_export_carts">
				<input type="hidden" name="status" value="<?php echo esc_attr( $tab ); ?>">
				<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
				<?php wp_nonce_field( 'cf_export_carts' ); ?>
				<button class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export', 'checkoutflow' ); ?></button>
			</form>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cf_cart_action">
			<?php wp_nonce_field( 'cf_cart_action' ); ?>
			<div class="tablenav top">
				<select name="do" aria-label="<?php esc_attr_e( 'Bulk action', 'checkoutflow' ); ?>">
					<option value=""><?php esc_html_e( 'Bulk actions', 'checkoutflow' ); ?></option>
					<?php if ( 'abandoned' === $tab ) : ?>
						<option value="lost"><?php esc_html_e( 'Mark as lost (stop emails)', 'checkoutflow' ); ?></option>
					<?php endif; ?>
					<option value="delete"><?php esc_html_e( 'Delete', 'checkoutflow' ); ?></option>
				</select>
				<button class="button" data-confirm="<?php esc_attr_e( 'Apply to the selected carts?', 'checkoutflow' ); ?>"><?php esc_html_e( 'Apply', 'checkoutflow' ); ?></button>
				<?php
				$pages = (int) ceil( $total / $per_page );
				if ( $pages > 1 ) {
					echo '<div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div>';
				}
				?>
			</div>
			<table class="widefat cf-table cf-carts-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" class="cf-check-all" aria-label="<?php esc_attr_e( 'Select all', 'checkoutflow' ); ?>"></td>
						<th><?php esc_html_e( 'Contact', 'checkoutflow' ); ?></th>
						<th><?php esc_html_e( 'Details', 'checkoutflow' ); ?></th>
						<th><?php echo esc_html( 'recovered' === $tab ? __( 'Recovered on', 'checkoutflow' ) : __( 'Created on', 'checkoutflow' ) ); ?></th>
						<th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th>
						<th><?php esc_html_e( 'Items', 'checkoutflow' ); ?></th>
						<th class="num"><?php esc_html_e( 'Total', 'checkoutflow' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Action', 'checkoutflow' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8" class="cf-empty"><?php echo esc_html( '' !== $search ? __( 'No carts match your search.', 'checkoutflow' ) : __( 'No carts here yet.', 'checkoutflow' ) ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $r ) :
					$items    = DB::json( $r['items'] );
					$list     = isset( $mails[ 'cart:' . $r['id'] ] ) ? $mails[ 'cart:' . $r['id'] ] : array();
					$state    = $email_status( $r, $list );
					$name     = trim( $r['first_name'] . ' ' . $r['last_name'] );
					$initials = strtoupper( substr( $r['first_name'] ? $r['first_name'] : $r['email'], 0, 1 ) . substr( $r['last_name'], 0, 1 ) );
					$products = array();
					foreach ( $items as $item ) {
						$pid        = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
						$p          = wc_get_product( $pid );
						$products[] = array(
							'name'  => $p ? $p->get_name() : '#' . $item['product_id'],
							'url'   => $p ? get_permalink( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() ) : '',
							'qty'   => wc_stock_amount( $item['quantity'] ),
							'price' => $p ? (float) $p->get_price() : 0,
						);
					}
					?>
					<tr class="cf-cart-row">
						<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $r['id'] ); ?>"></th>
						<td>
							<div class="cf-contact-cell">
								<span class="cf-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
								<div>
									<strong><?php echo esc_html( '' !== $name ? $name : $r['email'] ); ?></strong>
									<small class="cf-muted">
										<?php
										/* translators: %s: e.g. "15 mins ago" */
										echo esc_html( sprintf( __( 'Last active: %s', 'checkoutflow' ), $ago( $r['updated_at'] ) ) );
										?>
									</small>
								</div>
							</div>
						</td>
						<td><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a><?php echo $r['phone'] ? '<br><a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $r['phone'] ) ) . '">' . esc_html( $r['phone'] ) . '</a>' : ''; ?></td>
						<td><?php echo esc_html( $local( 'recovered' === $tab ? $r['updated_at'] : $r['created_at'] ) ); ?></td>
						<td>
							<span class="cf-pill cf-pill-<?php echo esc_attr( $state[1] ); ?>"><?php echo esc_html( $state[0] ); ?></span>
							<?php if ( $state[2] ) : ?>
								<small class="cf-muted cf-pill-note"><?php echo esc_html( $state[2] ); ?></small>
							<?php endif; ?>
						</td>
						<td class="cf-items-cell">
							<?php
							$links = array();
							foreach ( $products as $pr ) {
								$links[] = ( $pr['url'] ? '<a href="' . esc_url( $pr['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $pr['name'] ) . '</a>' : esc_html( $pr['name'] ) ) . ( $pr['qty'] > 1 ? ' ×' . esc_html( $pr['qty'] ) : '' );
							}
							echo wp_kses_post( implode( ', ', $links ) );
							?>
						</td>
						<td class="num"><span class="cf-money-pill"><?php echo wp_kses_post( wc_price( $r['total'], array( 'currency' => $r['currency'] ) ) ); ?></span></td>
						<td><button type="button" class="button cf-cart-view" aria-expanded="false" aria-controls="cf-cart-<?php echo esc_attr( $r['id'] ); ?>"><?php esc_html_e( 'View', 'checkoutflow' ); ?></button></td>
					</tr>
					<tr class="cf-cart-detail" id="cf-cart-<?php echo esc_attr( $r['id'] ); ?>" hidden>
						<td></td>
						<td colspan="7">
							<div class="cf-cart-panel">
								<div>
									<h3><?php esc_html_e( 'Cart', 'checkoutflow' ); ?></h3>
									<table class="cf-mini">
										<?php foreach ( $products as $pr ) : ?>
											<tr><td><?php echo esc_html( $pr['name'] ); ?></td><td class="num">× <?php echo esc_html( $pr['qty'] ); ?></td><td class="num"><?php echo wp_kses_post( wc_price( $pr['price'] * $pr['qty'] ) ); ?></td></tr>
										<?php endforeach; ?>
										<?php $coupons = array_filter( (array) DB::json( $r['coupons'] ) ); ?>
										<?php if ( $coupons ) : ?>
											<tr><td colspan="3" class="cf-muted"><?php echo esc_html( __( 'Coupons:', 'checkoutflow' ) . ' ' . implode( ', ', array_map( 'strval', $coupons ) ) ); ?></td></tr>
										<?php endif; ?>
										<tr class="cf-mini-total"><td><?php esc_html_e( 'Total', 'checkoutflow' ); ?></td><td></td><td class="num"><?php echo wp_kses_post( wc_price( $r['total'], array( 'currency' => $r['currency'] ) ) ); ?></td></tr>
									</table>
									<?php if ( in_array( $r['status'], array( 'abandoned', 'lost', 'active' ), true ) ) : ?>
										<?php $link = add_query_arg( 'cf-recover', $r['token'], home_url( '/' ) ); ?>
										<p class="cf-recover-link">
											<label><?php esc_html_e( 'Recovery link (restores this cart and opens checkout)', 'checkoutflow' ); ?><br>
												<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $link ); ?>" onclick="this.select()">
											</label>
										</p>
									<?php endif; ?>
									<?php if ( $r['order_id'] ) : ?>
										<p><a class="button" href="<?php echo esc_url( $order_url( $r['order_id'] ) ); ?>">
											<?php
											/* translators: %d: order number */
											echo esc_html( sprintf( __( 'View order #%d', 'checkoutflow' ), $r['order_id'] ) );
											?>
										</a></p>
									<?php endif; ?>
								</div>
								<div>
									<h3><?php esc_html_e( 'Timeline', 'checkoutflow' ); ?></h3>
									<ul class="cf-timeline">
										<li><span><?php echo esc_html( $local( $r['created_at'] ) ); ?></span> <?php esc_html_e( 'Entered email at checkout', 'checkoutflow' ); ?></li>
										<?php if ( $r['abandoned_at'] ) : ?>
											<li><span><?php echo esc_html( $local( $r['abandoned_at'] ) ); ?></span> <?php esc_html_e( 'Cart abandoned', 'checkoutflow' ); ?></li>
										<?php endif; ?>
										<?php foreach ( $list as $m ) : ?>
											<li>
												<span><?php echo esc_html( $local( $m['sent_at'] ? $m['sent_at'] : $m['send_at'] ) ); ?></span>
												<?php
												$subject = '' !== $m['subject'] ? '“' . $m['subject'] . '”' : __( 'Recovery email', 'checkoutflow' );
												switch ( $m['status'] ) {
													case 'sent':
														/* translators: %s: email subject */
														echo esc_html( sprintf( __( 'Sent %s', 'checkoutflow' ), $subject ) );
														echo $m['opened_at'] ? ' <em class="cf-ok">' . esc_html__( 'opened', 'checkoutflow' ) . '</em>' : '';
														echo $m['clicked_at'] ? ' <em class="cf-ok">' . esc_html__( 'clicked', 'checkoutflow' ) . '</em>' : '';
														break;
													case 'pending':
													case 'sending':
													case 'paused':
														/* translators: %s: email subject */
														echo esc_html( sprintf( __( 'Scheduled: %s', 'checkoutflow' ), $subject ) );
														break;
													case 'failed':
														echo esc_html( __( 'Failed to send', 'checkoutflow' ) . ( $m['error'] ? ': ' . $m['error'] : '' ) );
														break;
													default:
														echo esc_html( __( 'Not sent', 'checkoutflow' ) . ( $m['error'] ? ' (' . $m['error'] . ')' : '' ) );
												}
												?>
											</li>
										<?php endforeach; ?>
										<?php if ( 'recovered' === $r['status'] ) : ?>
											<li><span><?php echo esc_html( $local( $r['updated_at'] ) ); ?></span> <strong class="cf-ok"><?php esc_html_e( 'Recovered: order placed', 'checkoutflow' ); ?></strong></li>
										<?php elseif ( 'lost' === $r['status'] ) : ?>
											<li><span></span> <?php esc_html_e( 'Marked as lost', 'checkoutflow' ); ?></li>
										<?php endif; ?>
									</ul>
								</div>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<p class="description"><?php esc_html_e( 'A cart is captured once a shopper enters their email at checkout, becomes recoverable when they leave without ordering, and moves to Lost when it is not recovered in time (Settings → Cart Recovery). Recovery emails come from the abandoned cart automation.', 'checkoutflow' ); ?></p>
	</div>
</div>
