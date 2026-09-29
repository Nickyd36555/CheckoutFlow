<?php
/**
 * Shared helpers.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Render a template from /templates, allowing themes to override it at
 * yourtheme/checkoutflow/{name}.php.
 *
 * @param string $name Template name without extension.
 * @param array  $args Variables for the template.
 * @return string
 */
function render( $name, $args = array() ) {
	$file = locate_template( 'checkoutflow/' . $name . '.php' );
	if ( ! $file ) {
		$file = CHECKOUTFLOW_DIR . 'templates/' . $name . '.php';
	}
	ob_start();
	extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	include $file;
	return ob_get_clean();
}

/**
 * Versioned asset URL. Uses file mtime in development so edits bust caches.
 *
 * @param string $path Relative path under /assets.
 * @return array{0:string,1:string} URL and version.
 */
function asset( $path ) {
	$file = CHECKOUTFLOW_DIR . 'assets/' . $path;
	$ver  = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $file ) ) ? (string) filemtime( $file ) : CHECKOUTFLOW_VERSION;
	return array( CHECKOUTFLOW_URL . 'assets/' . $path, $ver );
}

/**
 * Minimum order amount that unlocks free shipping for the current customer.
 *
 * Uses the manual setting when set, otherwise reads the "Free shipping" method of
 * the customer's shipping zone (falls back to the lowest threshold across zones).
 *
 * @return float 0 when there is no threshold.
 */
function free_shipping_threshold() {
	$manual = (float) Settings::get( 'cart_free_shipping_amount' );
	if ( $manual > 0 ) {
		return (float) apply_filters( 'checkoutflow_free_shipping_threshold', $manual );
	}

	$zones = array();
	if ( WC()->cart ) {
		$packages = WC()->cart->get_shipping_packages();
		if ( ! empty( $packages[0] ) ) {
			$zones[] = \WC_Shipping_Zones::get_zone_matching_package( $packages[0] );
		}
	}
	if ( ! $zones ) {
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			$zones[] = new \WC_Shipping_Zone( $zone['id'] );
		}
		$zones[] = new \WC_Shipping_Zone( 0 );
	}

	$min = 0.0;
	foreach ( $zones as $zone ) {
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( 'free_shipping' !== $method->id ) {
				continue;
			}
			$requires = $method->get_option( 'requires' );
			$amount   = (float) $method->get_option( 'min_amount' );
			if ( in_array( $requires, array( 'min_amount', 'either', 'both' ), true ) && $amount > 0 ) {
				$min = $min > 0 ? min( $min, $amount ) : $amount;
			}
		}
	}

	return (float) apply_filters( 'checkoutflow_free_shipping_threshold', $min );
}

/**
 * Cart amount compared against the free shipping threshold, matching how
 * WooCommerce's free shipping method evaluates it (display subtotal minus discounts).
 *
 * @return float
 */
function cart_total_after_discounts() {
	$cart  = WC()->cart;
	$total = (float) $cart->get_displayed_subtotal();
	if ( $cart->display_prices_including_tax() ) {
		$total -= (float) $cart->get_discount_tax();
	}
	$total -= (float) $cart->get_discount_total();
	return max( 0, $total );
}

/**
 * Discount lines added as negative cart fees (e.g. bulk discounts).
 *
 * @return object[] Fee objects with ->name and ->amount (< 0).
 */
function cart_discount_fees() {
	$out = array();
	foreach ( WC()->cart ? WC()->cart->get_fees() : array() as $fee ) {
		if ( (float) $fee->amount < 0 ) {
			$out[] = $fee;
		}
	}
	return $out;
}

/**
 * What the shopper pays for the items: subtotal after coupons and discount fees
 * (shipping, taxes and surcharges like package protection come at checkout).
 *
 * @return float
 */
function cart_display_total() {
	$total = cart_total_after_discounts();
	foreach ( cart_discount_fees() as $fee ) {
		$total += (float) $fee->amount;
	}
	return max( 0, $total );
}
