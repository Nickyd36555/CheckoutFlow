<?php
/**
 * Dashboard.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\DB;
use CheckoutFlow\Settings;
use CheckoutFlow\Plugin;
use CheckoutFlow\Admin\Admin;
use CheckoutFlow\Admin\Analytics;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
$q     = DB::t( 'queue' );
$mail  = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status='sent') sent, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked, COALESCE(SUM(revenue),0) revenue FROM {$q} WHERE sent_at >= %s", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$carts = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(abandoned_at IS NOT NULL) abandoned, SUM(status=\'recovered\') recovered, COALESCE(SUM(CASE WHEN status=\'recovered\' THEN total ELSE 0 END),0) recovered_total FROM ' . DB::t( 'carts' ) . ' WHERE created_at >= %s', $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ct    = $wpdb->get_row( 'SELECT COUNT(*) total, SUM(status=\'subscribed\') subscribed FROM ' . DB::t( 'contacts' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$active_automations = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::t( 'automations' ) . " WHERE status='active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$pending            = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$q} WHERE status='pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$failed             = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE status='failed' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$sent     = (int) $mail['sent'];
$pct      = static function ( $n, $d ) {
	return $d > 0 ? round( $n / $d * 100, 1 ) . '%' : '–';
};
$next     = wp_next_scheduled( Plugin::TICK_HOOK );
$cron_bad = ! $next || $next < time() - 10 * MINUTE_IN_SECONDS;

$recent = $wpdb->get_results( "SELECT * FROM {$q} WHERE status IN ('sent','failed') ORDER BY id DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
?>
<?php
$cf_period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '30'; // phpcs:ignore WordPress.Security.NonceVerification
$cf_period = isset( Analytics::periods()[ $cf_period ] ) ? $cf_period : '30';
$sales     = Analytics::report( $cf_period );
$st        = $sales['totals'];
$pv        = $sales['prev'];
$change    = static function ( $now, $before ) {
	if ( $before <= 0 ) {
		return '';
	}
	$d = ( $now - $before ) / $before * 100;
	return sprintf( '<em class="cf-delta %s">%s%s%%</em>', $d >= 0 ? 'is-up' : 'is-down', $d >= 0 ? '▲ ' : '▼ ', esc_html( number_format_i18n( abs( $d ), abs( $d ) < 10 ? 1 : 0 ) ) );
};
$money     = static function ( $n ) {
	return wp_strip_all_tags( html_entity_decode( wc_price( $n ) ) );
};
// Bar chart (one series at a time; hover shows both numbers for the bucket).
// Shapes are an SVG stretched to the box; labels are HTML placed in % so text never distorts.
$chart = static function ( $series, $metric ) use ( $money ) {
	$w    = 1000;
	$h    = 200;
	$n    = max( 1, count( $series ) );
	$max  = max( 1, max( wp_list_pluck( $series, $metric ) ) );
	$mag  = pow( 10, floor( log10( $max ) ) );
	$max  = ceil( $max / $mag * 2 ) / 2 * $mag;
	$slot = $w / $n;
	$bw   = max( 2, min( 36, $slot * 0.7 ) );
	$svg  = '<svg class="cf-chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">';
	$yax  = '';
	$xax  = '';
	for ( $i = 0; $i <= 4; $i++ ) {
		$y    = $h * ( 1 - $i / 4 );
		$v    = $max * $i / 4;
		$svg .= '<line class="cf-gridline" x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '"/>';
		$yax .= '<span style="top:' . ( $y / $h * 100 ) . '%">' . esc_html( 'revenue' === $metric ? $money( $v ) : number_format_i18n( $v ) ) . '</span>';
	}
	$every = (int) ceil( $n / 8 );
	foreach ( array_values( $series ) as $i => $pt ) {
		$x    = $slot * $i + ( $slot - $bw ) / 2;
		$bh   = $h * $pt[ $metric ] / $max;
		$tip  = $pt['label'] . ' · ' . $money( $pt['revenue'] ) . ' · ' . sprintf( _n( '%s order', '%s orders', $pt['orders'], 'checkoutflow' ), number_format_i18n( $pt['orders'] ) );
		$svg .= '<g class="cf-bar" data-tip="' . esc_attr( $tip ) . '"><rect class="cf-hit" x="' . ( $slot * $i ) . '" y="0" width="' . $slot . '" height="' . $h . '"/>';
		if ( $bh > 0 ) {
			$svg .= '<rect class="cf-fill" x="' . $x . '" y="' . ( $h - $bh ) . '" width="' . $bw . '" height="' . $bh . '"/>';
		}
		$svg .= '</g>';
		if ( 0 === $i % $every ) {
			$xax .= '<span style="left:' . ( ( $slot * $i + $slot / 2 ) / $w * 100 ) . '%">' . esc_html( $pt['label'] ) . '</span>';
		}
	}
	$svg .= '</svg>';
	return '<div class="cf-plot"><div class="cf-yaxis">' . $yax . '</div><div class="cf-plot-area">' . $svg . '<div class="cf-xaxis">' . $xax . '</div></div></div>';
};
?>
<div class="cf-dash-head">
	<h1><?php esc_html_e( 'Dashboard', 'checkoutflow' ); ?></h1>
	<form method="get" class="cf-period">
		<input type="hidden" name="page" value="checkoutflow">
		<label class="screen-reader-text" for="cf-period"><?php esc_html_e( 'Period', 'checkoutflow' ); ?></label>
		<select id="cf-period" name="period" onchange="this.form.submit()">
			<?php foreach ( Analytics::periods() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $cf_period ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<noscript><button class="button"><?php esc_html_e( 'Apply', 'checkoutflow' ); ?></button></noscript>
	</form>
</div>

<div class="cf-kpis cf-sales-kpis">
	<div class="cf-kpi"><span><?php esc_html_e( 'Revenue', 'checkoutflow' ); ?></span><strong><?php echo esc_html( $money( $st['revenue'] ) ); ?></strong><?php echo $change( $st['revenue'], $pv['revenue'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Orders', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( $st['orders'] ) ); ?></strong><?php echo $change( $st['orders'], $pv['orders'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Average order', 'checkoutflow' ); ?></span><strong><?php echo esc_html( $money( $st['aov'] ) ); ?></strong><?php echo $change( $st['aov'], $pv['aov'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Customers', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( $st['customers'] ) ); ?></strong><?php echo $change( $st['customers'], $pv['customers'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Email revenue', 'checkoutflow' ); ?></span><strong><?php echo esc_html( $money( $sales['email']['email'] ) ); ?></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Recovered carts', 'checkoutflow' ); ?></span><strong><?php echo esc_html( $money( $sales['email']['recovered'] ) ); ?></strong></div>
</div>
<p class="cf-muted cf-kpi-note"><?php esc_html_e( 'Processing, completed and on-hold orders. Arrows compare with the previous period of the same length.', 'checkoutflow' ); ?></p>

<div class="cf-card cf-chart" data-metric="revenue">
	<div class="cf-chart-head">
		<h2><?php echo esc_html( 'month' === $sales['bucket'] ? __( 'Sales by month', 'checkoutflow' ) : __( 'Sales by day', 'checkoutflow' ) ); ?></h2>
		<div class="cf-seg" role="group">
			<button type="button" class="is-active" data-show="revenue"><?php esc_html_e( 'Revenue', 'checkoutflow' ); ?></button>
			<button type="button" data-show="orders"><?php esc_html_e( 'Orders', 'checkoutflow' ); ?></button>
		</div>
	</div>
	<div class="cf-chart-plot" data-plot="revenue"><?php echo $chart( $sales['series'], 'revenue' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="cf-chart-plot" data-plot="orders" hidden><?php echo $chart( $sales['series'], 'orders' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="cf-tip" hidden></div>
	<details class="cf-chart-table">
		<summary><?php esc_html_e( 'Show as table', 'checkoutflow' ); ?></summary>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Orders', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Revenue', 'checkoutflow' ); ?></th></tr></thead><tbody>
		<?php foreach ( $sales['series'] as $pt ) : ?>
			<tr><td><?php echo esc_html( $pt['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( $pt['orders'] ) ); ?></td><td><?php echo esc_html( $money( $pt['revenue'] ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	</details>
</div>

<div class="cf-dash-grid">
	<div class="cf-card">
		<h2><?php esc_html_e( 'Recent conversions', 'checkoutflow' ); ?></h2>
		<table class="widefat striped cf-conv">
			<thead><tr><th><?php esc_html_e( 'Order', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Customer', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Source', 'checkoutflow' ); ?></th><th class="num"><?php esc_html_e( 'Total', 'checkoutflow' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $sales['recent'] ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No orders in this period.', 'checkoutflow' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $sales['recent'] as $o ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $o['id'] ) : admin_url( 'post.php?post=' . $o['id'] . '&action=edit' ) ); ?>">#<?php echo esc_html( $o['id'] ); ?></a><br><small class="cf-muted"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $o['ts'] ) ); ?></small></td>
					<td><?php echo esc_html( $o['name'] ? $o['name'] : $o['email'] ); ?><br><small class="cf-muted"><?php echo esc_html( $o['name'] ? $o['email'] : '' ); ?></small></td>
					<td><?php echo esc_html( Analytics::source_label( $o ) ); ?><?php if ( $o['utm_campaign'] ) : ?><br><small class="cf-muted"><?php echo esc_html( $o['utm_campaign'] ); ?></small><?php endif; ?></td>
					<td class="num"><strong><?php echo esc_html( $money( $o['total'] ) ); ?></strong><?php if ( 'on-hold' === $o['status'] ) : ?><br><small class="cf-muted"><?php esc_html_e( 'on hold', 'checkoutflow' ); ?></small><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="cf-dash-side">
		<?php
		$cf_tables = array(
			array( __( 'Traffic sources', 'checkoutflow' ), $sales['sources'], __( 'Source', 'checkoutflow' ) ),
			array( __( 'UTM campaigns', 'checkoutflow' ), $sales['campaigns'], __( 'Campaign', 'checkoutflow' ) ),
			array( __( 'Referrers', 'checkoutflow' ), $sales['referrers'], __( 'Site', 'checkoutflow' ) ),
		);
		foreach ( $cf_tables as $tbl ) :
			?>
			<div class="cf-card">
				<h2><?php echo esc_html( $tbl[0] ); ?></h2>
				<?php if ( ! $tbl[1] ) : ?>
					<p class="cf-muted"><?php echo esc_html( $sales['has_attr'] || ! $st['orders'] ? __( 'Nothing in this period.', 'checkoutflow' ) : __( 'No source data on these orders. WooCommerce records it from version 8.5 when "Order Attribution" is on (WooCommerce → Settings → Advanced → Features).', 'checkoutflow' ) ); ?></p>
				<?php else : ?>
					<table class="widefat cf-src">
						<thead><tr><th><?php echo esc_html( $tbl[2] ); ?></th><th class="num"><?php esc_html_e( 'Orders', 'checkoutflow' ); ?></th><th class="num"><?php esc_html_e( 'Revenue', 'checkoutflow' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $tbl[1] as $g ) : ?>
							<tr>
								<td><?php echo esc_html( $g['label'] ); ?><?php if ( ! empty( $g['via'] ) ) : ?><br><small class="cf-muted"><?php echo esc_html( $g['via'] ); ?></small><?php endif; ?>
									<span class="cf-share" style="--w:<?php echo esc_attr( round( $g['share'] * 100, 1 ) ); ?>%"></span></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $g['orders'] ) ); ?></td>
								<td class="num"><?php echo esc_html( $money( $g['revenue'] ) ); ?><br><small class="cf-muted"><?php echo esc_html( round( $g['share'] * 100 ) . '%' ); ?></small></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<h2 class="cf-section"><?php esc_html_e( 'Email marketing', 'checkoutflow' ); ?> <span class="cf-sub"><?php esc_html_e( 'Last 30 days', 'checkoutflow' ); ?></span></h2>

<?php
$todo = array();
if ( ! Settings::get( 'smtp_enabled' ) ) {
	$todo[] = sprintf( '<a href="%s">%s</a>', esc_url( Admin::url( 'settings', array( 'tab' => 'email' ) ) ), esc_html__( 'Connect your SMTP server', 'checkoutflow' ) );
}
if ( ! $active_automations ) {
	$todo[] = sprintf( '<a href="%s">%s</a>', esc_url( Admin::url( 'automations' ) ), esc_html__( 'Activate the abandoned cart automation', 'checkoutflow' ) );
}
if ( ! (int) $ct['total'] ) {
	$todo[] = sprintf( '<a href="%s">%s</a>', esc_url( Admin::url( 'contacts' ) ), esc_html__( 'Import your existing customers as contacts', 'checkoutflow' ) );
}
if ( $todo ) :
	?>
	<div class="cf-card cf-setup">
		<h2><?php esc_html_e( 'Finish setting up', 'checkoutflow' ); ?></h2>
		<ol><li><?php echo implode( '</li><li>', $todo ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li></ol>
	</div>
<?php endif; ?>

<?php if ( $cron_bad ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Background tasks look delayed. WP-Cron may be disabled or your site gets little traffic. For reliable sending, add a real cron job that requests wp-cron.php every minute.', 'checkoutflow' ); ?></p></div>
<?php endif; ?>

<div class="cf-kpis">
	<div class="cf-kpi"><span><?php esc_html_e( 'Emails sent', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( $sent ) ); ?></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Open rate', 'checkoutflow' ); ?></span><strong><?php echo esc_html( $pct( (int) $mail['opened'], $sent ) ); ?></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Click rate', 'checkoutflow' ); ?></span><strong><?php echo esc_html( $pct( (int) $mail['clicked'], $sent ) ); ?></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Email revenue', 'checkoutflow' ); ?></span><strong><?php echo wp_kses_post( wc_price( $mail['revenue'] ) ); ?></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Carts abandoned', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $carts['abandoned'] ) ); ?></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Carts recovered', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $carts['recovered'] ) ); ?> <small>(<?php echo esc_html( $pct( (int) $carts['recovered'], (int) $carts['abandoned'] ) ); ?>)</small></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Recovered revenue', 'checkoutflow' ); ?></span><strong><?php echo wp_kses_post( wc_price( $carts['recovered_total'] ) ); ?></strong></div>
	<div class="cf-kpi"><span><?php esc_html_e( 'Subscribed contacts', 'checkoutflow' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $ct['subscribed'] ) ); ?> <small>/ <?php echo esc_html( number_format_i18n( (int) $ct['total'] ) ); ?></small></strong></div>
</div>

<p class="cf-muted">
	<?php
	/* translators: 1: queued count, 2: failed count */
	echo esc_html( sprintf( __( '%1$d emails queued · %2$d failed in the last 30 days', 'checkoutflow' ), $pending, $failed ) );
	?>
</p>

<h2><?php esc_html_e( 'Recent emails', 'checkoutflow' ); ?></h2>
<table class="widefat striped">
	<thead><tr><th><?php esc_html_e( 'To', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Subject', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'From', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Status', 'checkoutflow' ); ?></th><th><?php esc_html_e( 'Sent', 'checkoutflow' ); ?></th></tr></thead>
	<tbody>
	<?php if ( ! $recent ) : ?>
		<tr><td colspan="5"><?php esc_html_e( 'No emails sent yet.', 'checkoutflow' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $recent as $r ) : ?>
		<tr>
			<td><?php echo esc_html( $r['email'] ); ?></td>
			<td><?php echo esc_html( $r['subject'] ); ?></td>
			<td><?php echo esc_html( ucfirst( $r['source'] ) . ' #' . $r['source_id'] ); ?></td>
			<td>
				<span class="cf-badge cf-badge-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span>
				<?php if ( $r['opened_at'] ) : ?><span class="cf-badge"><?php esc_html_e( 'opened', 'checkoutflow' ); ?></span><?php endif; ?>
				<?php if ( $r['clicked_at'] ) : ?><span class="cf-badge"><?php esc_html_e( 'clicked', 'checkoutflow' ); ?></span><?php endif; ?>
				<?php if ( 'failed' === $r['status'] && $r['error'] ) : ?><br><small class="cf-error"><?php echo esc_html( $r['error'] ); ?></small><?php endif; ?>
			</td>
			<td><?php echo esc_html( $r['sent_at'] ? get_date_from_gmt( $r['sent_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '' ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
