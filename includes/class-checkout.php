<?php
/**
 * Checkout optimizations: focused layout, field cleanup, coupon placement, trust text.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Checkout {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_skip_cart' ) );
		add_action( 'wp', array( $this, 'setup' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'fields' ), 20 );
		add_filter( 'woocommerce_enable_order_notes_field', array( $this, 'order_notes_enabled' ) );
		add_filter( 'woocommerce_order_button_text', array( $this, 'button_text' ) );
	}

	public function button_text( $text ) {
		$custom = (string) Settings::get( 'checkout_button_text' );
		return '' !== $custom ? $custom : $text;
	}

	/**
	 * Is this the checkout form (not thank-you / order-pay)?
	 *
	 * @return bool
	 */
	private function is_checkout_form() {
		return is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
	}

	public function maybe_skip_cart() {
		// Keep the cart page reachable when the cart has problems (e.g. stock), or checkout's
		// "return to cart" would loop straight back here.
		if ( Settings::get( 'checkout_skip_cart' ) && is_cart() && WC()->cart && ! WC()->cart->is_empty() && ! wc_notice_count( 'error' ) && true === WC()->cart->check_cart_items() ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Runs once the main query is known, so front-end hooks are only added on checkout.
	 */
	public function setup() {
		if ( ! $this->is_checkout_form() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		if ( 'focused' === Settings::get( 'checkout_template' ) ) {
			add_filter( 'template_include', array( $this, 'template' ), 99 );
		}

		$coupon = Settings::get( 'checkout_coupon' );
		if ( 'default' !== $coupon ) {
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
		}
		if ( 'summary' === $coupon && wc_coupons_enabled() ) {
			add_action( 'woocommerce_review_order_before_order_total', array( $this, 'coupon_row' ) );
		}

		if ( '' !== trim( (string) Settings::get( 'checkout_trust_text' ) ) ) {
			add_action( 'woocommerce_review_order_after_submit', array( $this, 'trust_text' ) );
		}
	}

	public function assets() {
		list( $css, $ver ) = asset( 'css/checkout.css' );
		wp_enqueue_style( 'checkoutflow-checkout', $css, array(), $ver );
		wp_add_inline_style( 'checkoutflow-checkout', 'body.cf-checkout{--cf-accent:' . Settings::get( 'checkout_accent_color' ) . ';}' );

		if ( 'summary' === Settings::get( 'checkout_coupon' ) ) {
			list( $js, $ver ) = asset( 'js/checkout.js' );
			wp_enqueue_script( 'checkoutflow-checkout', $js, array( 'jquery', 'wc-checkout' ), $ver, true );
		}
	}

	public function body_class( $classes ) {
		$classes[] = 'cf-checkout';
		return $classes;
	}

	public function template( $template ) {
		$file = locate_template( 'checkoutflow/checkout-focused.php' );
		return $file ? $file : CHECKOUTFLOW_DIR . 'templates/checkout-focused.php';
	}

	/**
	 * Apply field visibility / required settings and move email to the top.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function fields( $fields ) {
		$map = array(
			'field_billing_company'   => array( 'billing_company', 'shipping_company' ),
			'field_billing_address_2' => array( 'billing_address_2', 'shipping_address_2' ),
			'field_billing_phone'     => array( 'billing_phone' ),
		);

		foreach ( $map as $setting => $keys ) {
			$state = Settings::get( $setting );
			foreach ( $keys as $key ) {
				$group = strtok( $key, '_' );
				if ( ! isset( $fields[ $group ][ $key ] ) ) {
					continue;
				}
				if ( 'hidden' === $state ) {
					unset( $fields[ $group ][ $key ] );
				} else {
					$fields[ $group ][ $key ]['required'] = ( 'required' === $state );
				}
			}
		}

		if ( Settings::get( 'checkout_email_first' ) && isset( $fields['billing']['billing_email'] ) ) {
			$fields['billing']['billing_email']['priority'] = 1;
			$fields['billing']['billing_email']['class']    = array( 'form-row-wide' );
			uasort( $fields['billing'], array( $this, 'sort_by_priority' ) );
		}

		return $fields;
	}

	private function sort_by_priority( $a, $b ) {
		$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 999;
		$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 999;
		return $pa - $pb;
	}

	public function order_notes_enabled( $enabled ) {
		return 'hidden' === Settings::get( 'field_order_comments' ) ? false : $enabled;
	}

	/**
	 * Collapsed coupon input inside the order summary table.
	 * It can't be a nested <form>, so checkout.js submits it via wc-ajax=apply_coupon.
	 */
	public function coupon_row() {
		?>
		<tr class="cf-coupon-row">
			<td colspan="2">
				<a href="#" class="cf-coupon-toggle"><?php esc_html_e( 'Have a coupon code?', 'checkoutflow' ); ?></a>
				<div class="cf-coupon-box" hidden>
					<input type="text" class="input-text cf-coupon-input" placeholder="<?php esc_attr_e( 'Coupon code', 'checkoutflow' ); ?>" aria-label="<?php esc_attr_e( 'Coupon code', 'checkoutflow' ); ?>" autocomplete="off" />
					<button type="button" class="button cf-coupon-apply"><?php esc_html_e( 'Apply', 'checkoutflow' ); ?></button>
				</div>
			</td>
		</tr>
		<?php
	}

	public function trust_text() {
		echo '<div class="cf-trust"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><div>' . wp_kses_post( wpautop( Settings::get( 'checkout_trust_text' ) ) ) . '</div></div>';
	}
}
