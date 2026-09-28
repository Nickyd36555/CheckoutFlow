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
<h1><?php esc_html_e( 'CheckoutFlow', 'checkoutflow' ); ?> <span class="cf-sub"><?php esc_html_e( 'Last 30 days', 'checkoutflow' ); ?></span></h1>

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
