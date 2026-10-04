<?php
/**
 * Upsells: what customers buy together (from the store's own orders) and the pairing rules.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\Admin\Admin;
use CheckoutFlow\Recommendations;
use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

$cf_meta  = get_option( Recommendations::META_OPTION, array() );
$cf_pairs = Recommendations::top_pairs( 50 );
$cf_rules = Recommendations::rules();
$cf_name  = static function ( $id ) {
	$p = wc_get_product( $id );
	return $p ? wp_specialchars_decode( $p->get_name(), ENT_QUOTES ) : '#' . $id;
};
?>
<h1 class="wp-heading-inline"><?php esc_html_e( 'Upsells', 'checkoutflow' ); ?></h1>
<hr class="wp-header-end">

<p class="cf-hint"><?php esc_html_e( 'The side cart\'s "Frequently Bought Together" slider suggests, in order: products linked on each product (Upsells / Cross-sells), your pairing rules, what customers bought together in your orders, then best sellers.', 'checkoutflow' ); ?></p>

<h2><?php esc_html_e( 'Bought together in your orders', 'checkoutflow' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-inline">
	<input type="hidden" name="action" value="cf_reco_rebuild">
	<?php wp_nonce_field( 'cf_reco_rebuild' ); ?>
	<button class="button button-primary"><?php esc_html_e( 'Analyze my orders now', 'checkoutflow' ); ?></button>
	<span class="description">
		<?php
		if ( ! empty( $cf_meta['time'] ) ) {
			/* translators: 1: number of orders, 2: time ago */
			echo esc_html( sprintf( __( 'Last analyzed %2$s ago (%1$s paid orders from the last 12 months). Updates daily.', 'checkoutflow' ), number_format_i18n( (int) $cf_meta['orders'] ), human_time_diff( (int) $cf_meta['time'] ) ) );
		} else {
			esc_html_e( 'Not analyzed yet. It runs daily, or click the button.', 'checkoutflow' );
		}
		?>
	</span>
</form>

<table class="widefat striped cf-table" style="margin-top:12px;max-width:1100px">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Product', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Bought together with', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Orders', 'checkoutflow' ); ?></th>
			<th><?php esc_html_e( 'Rule', 'checkoutflow' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $cf_pairs ) : ?>
		<tr><td colspan="4"><?php esc_html_e( 'No products bought together at least twice yet.', 'checkoutflow' ); ?></td></tr>
	<?php endif; ?>
	<?php
	foreach ( $cf_pairs as $cf_pair ) :
		$cf_a  = $cf_name( $cf_pair[0] );
		$cf_b  = $cf_name( $cf_pair[1] );
		$cf_ka = Recommendations::keyword( $cf_a );
		$cf_kb = Recommendations::keyword( $cf_b );
		$cf_has = false;
		foreach ( $cf_rules as $cf_r ) {
			if ( ( Recommendations::name_has( $cf_a, $cf_r[0] ) && Recommendations::name_has( $cf_b, $cf_r[1] ) ) || ( Recommendations::name_has( $cf_b, $cf_r[0] ) && Recommendations::name_has( $cf_a, $cf_r[1] ) ) ) {
				$cf_has = true;
				break;
			}
		}
		?>
		<tr>
			<td><?php echo esc_html( $cf_a ); ?></td>
			<td><?php echo esc_html( $cf_b ); ?></td>
			<td><strong><?php echo esc_html( number_format_i18n( $cf_pair[2] ) ); ?></strong></td>
			<td>
				<?php if ( $cf_has ) : ?>
					<span class="cf-badge cf-badge-sent"><?php esc_html_e( 'Covered by a rule', 'checkoutflow' ); ?></span>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-inline">
						<input type="hidden" name="action" value="cf_reco_add_rule">
						<input type="hidden" name="a" value="<?php echo esc_attr( $cf_pair[0] ); ?>">
						<input type="hidden" name="b" value="<?php echo esc_attr( $cf_pair[1] ); ?>">
						<?php wp_nonce_field( 'cf_reco_add_rule' ); ?>
						<button class="button button-small"><?php echo esc_html( sprintf( '%s ⇄ %s', $cf_ka, $cf_kb ) ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<p class="description"><?php esc_html_e( 'Learned pairs are suggested automatically even without a rule; a rule also covers the other sizes of the same products.', 'checkoutflow' ); ?></p>

<h2><?php esc_html_e( 'Pairing rules', 'checkoutflow' ); ?></h2>
<p>
	<?php
	/* translators: %d: number of rules */
	echo esc_html( sprintf( _n( '%d rule is active.', '%d rules are active.', count( $cf_rules ), 'checkoutflow' ), count( $cf_rules ) ) );
	?>
	<a href="<?php echo esc_url( Admin::url( 'settings', array( 'tab' => 'cart' ) ) . '#cf-cart_pairings' ); ?>"><?php esc_html_e( 'Edit rules and upsell settings', 'checkoutflow' ); ?></a>
</p>
<?php if ( ! Settings::get( 'cart_upsells' ) ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Recommendations are turned off in Settings → Side Cart.', 'checkoutflow' ); ?></p></div>
<?php endif; ?>
