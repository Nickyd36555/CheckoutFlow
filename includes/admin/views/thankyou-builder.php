<?php
/**
 * Thank-you page builder (same drag-and-drop editor as emails).
 *
 * @package CheckoutFlow
 * @var array $design
 */

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

$cf_orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
$cf_live   = $cf_orders ? $cf_orders[0]->get_checkout_order_received_url() : '';
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cf-ty-form">
	<input type="hidden" name="action" value="cf_save_thankyou">
	<input type="hidden" name="design" id="cf-design" value="">
	<?php wp_nonce_field( 'cf_save_thankyou' ); ?>

	<div class="cfe-top">
		<h1><?php esc_html_e( 'Thank You Page', 'checkoutflow' ); ?></h1>
		<label class="cf-ty-toggle">
			<input type="checkbox" name="ty_enabled" value="1" <?php checked( (bool) Settings::get( 'ty_enabled' ) ); ?>>
			<?php esc_html_e( 'Show this page after checkout', 'checkoutflow' ); ?>
		</label>
		<?php if ( $cf_live ) : ?>
			<a class="button" href="<?php echo esc_url( $cf_live ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View with latest order', 'checkoutflow' ); ?></a>
		<?php endif; ?>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'checkoutflow' ); ?></button>
	</div>

	<?php if ( class_exists( 'WFFN_Core' ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'FunnelKit is active. While "Show this page after checkout" is on, customers see this page instead of FunnelKit\'s thank-you page.', 'checkoutflow' ); ?></p></div>
	<?php endif; ?>
	<p class="cf-hint"><?php esc_html_e( 'Drag blocks onto the preview, drag them to reorder, and click one to edit it. The preview uses your most recent order. Tags like {first_name} and {order_number} are filled in for each customer.', 'checkoutflow' ); ?></p>

	<div id="cf-builder" class="cfb cfb-page" data-design="<?php echo esc_attr( wp_json_encode( $design ) ); ?>"></div>

	<p class="cf-ty-reset">
		<button type="submit" name="reset" value="1" class="button-link button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Replace your layout with the default one?', 'checkoutflow' ) ); ?>');"><?php esc_html_e( 'Reset to default layout', 'checkoutflow' ); ?></button>
	</p>
</form>
