<?php
/**
 * Plugin Name:       CheckoutFlow
 * Description:       Lightweight WooCommerce checkout optimizer, side cart, abandoned cart recovery, email marketing (campaigns, automations, drag-and-drop email builder) over SMTP.
 * Version:           1.5.3
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   10.8
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       checkoutflow
 */

defined( 'ABSPATH' ) || exit;

define( 'CHECKOUTFLOW_VERSION', '1.5.3' );
define( 'CHECKOUTFLOW_FILE', __FILE__ );
define( 'CHECKOUTFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHECKOUTFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once CHECKOUTFLOW_DIR . 'includes/class-settings.php';
require_once CHECKOUTFLOW_DIR . 'includes/class-plugin.php';
require_once CHECKOUTFLOW_DIR . 'includes/class-db.php';

register_activation_hook( __FILE__, array( 'CheckoutFlow\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CheckoutFlow\\Plugin', 'deactivate' ) );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', array( 'CheckoutFlow\\Plugin', 'instance' ), 20 );
