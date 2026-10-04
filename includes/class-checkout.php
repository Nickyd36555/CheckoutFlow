<?php
/**
 * Checkout optimizations: focused layout, field cleanup, coupon placement, trust text.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Checkout {

	/** Address parts shared by billing_* and shipping_* fields. */
	const ADDRESS_KEYS = array( 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode' );

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_skip_cart' ) );
		add_action( 'wp', array( $this, 'setup' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'fields' ), 20 );
		add_filter( 'woocommerce_enable_order_notes_field', array( $this, 'order_notes_enabled' ) );
		add_filter( 'woocommerce_order_button_text', array( $this, 'button_text' ), 20 );

		// Drive WooCommerce's own field switches (Customizer > WooCommerce > Checkout), so a
		// field hidden there can still be shown/required here, and address forms stay consistent.
		$options = array(
			'woocommerce_checkout_company_field'   => 'field_billing_company',
			'woocommerce_checkout_address_2_field' => 'field_billing_address_2',
			'woocommerce_checkout_phone_field'     => 'field_billing_phone',
		);
		foreach ( $options as $option => $setting ) {
			add_filter(
				'pre_option_' . $option,
				static function () use ( $setting ) {
					return Settings::get( $setting );
				}
			);
		}

		if ( self::is_modern() ) {
			// Registered globally: WooCommerce re-renders these templates in wc-ajax requests.
			add_filter( 'woocommerce_locate_template', array( $this, 'locate_template' ), 20, 2 );
			add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'review_fragments' ) );
			add_filter( 'woocommerce_checkout_posted_data', array( $this, 'copy_shipping_to_billing' ) );
			add_action( 'wc_ajax_cf_checkout_qty', array( $this, 'ajax_qty' ) );
			add_filter( 'woocommerce_default_address_fields', array( $this, 'address_order' ), 20 );
			$this->relocate_route_widget();
		}
	}

	/**
	 * Route (package protection) prints its widget on a configurable WooCommerce hook
	 * (default: before the order review). In the modern layout it gets its own section
	 * above "Payment Information" instead, fired as `checkoutflow_route_widget`.
	 */
	private function relocate_route_widget() {
		global $wp_filter;
		if ( ! class_exists( 'Routeapp_Public' ) || ! method_exists( 'Routeapp_Public', 'routeapp_get_checkout_hook' ) ) {
			return;
		}
		$hook = \Routeapp_Public::routeapp_get_checkout_hook();
		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && $fn[0] instanceof \Routeapp_Public && 'checkout_route_insurance' === $fn[1] ) {
					remove_action( $hook, $fn, $priority );
					add_action( 'checkoutflow_route_widget', $fn );
				}
			}
		}
	}

	public static function show_login_toggle() {
		return ! is_user_logged_in() && 'no' !== get_option( 'woocommerce_enable_checkout_login_reminder' );
	}

	public function login_form_only() {
		if ( self::show_login_toggle() ) {
			woocommerce_login_form(
				array(
					'message'  => esc_html__( 'If you have shopped with us before, please enter your details below. If you are a new customer, please proceed to the Billing section.', 'woocommerce' ),
					'redirect' => wc_get_checkout_url(),
					'hidden'   => true,
				)
			);
		}
	}

	/**
	 * Address order used by the modern form: street, then town + postcode, then country + state.
	 */
	public function address_order( $fields ) {
		$order = array(
			'first_name' => array( 10, 'form-row-first' ),
			'last_name'  => array( 20, 'form-row-last' ),
			'company'    => array( 30, 'form-row-wide' ),
			'address_1'  => array( 40, 'form-row-wide' ),
			'address_2'  => array( 50, 'form-row-wide' ),
			'city'       => array( 60, 'form-row-first' ),
			'postcode'   => array( 65, 'form-row-last' ),
			'country'    => array( 70, 'form-row-first' ),
			'state'      => array( 80, 'form-row-last' ),
		);
		foreach ( $order as $key => $o ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}
			$fields[ $key ]['priority'] = $o[0];
			$classes                    = array_diff( (array) ( isset( $fields[ $key ]['class'] ) ? $fields[ $key ]['class'] : array() ), array( 'form-row-wide', 'form-row-first', 'form-row-last' ) );
			array_unshift( $classes, $o[1] );
			$fields[ $key ]['class'] = array_values( $classes );
		}
		return $fields;
	}

	public static function is_modern() {
		return 'modern' === Settings::boot( 'checkout_style' );
	}

	/**
	 * Shipping-address-first form for this cart?
	 */
	public static function shipping_first() {
		return self::is_modern() && Settings::get( 'checkout_shipping_first' ) && WC()->cart && WC()->cart->needs_shipping_address() && ! wc_ship_to_billing_address_only();
	}

	public function button_text( $text ) {
		$custom = (string) Settings::get( 'checkout_button_text' );
		$text   = '' !== $custom ? $custom : $text;
		// Checkout only (not order-pay, where the cart is unrelated to the order).
		if ( Settings::get( 'checkout_button_total' ) && WC()->cart && ! WC()->cart->is_empty() && ! is_wc_endpoint_url( 'order-pay' ) ) {
			$text .= ' ' . html_entity_decode( wp_strip_all_tags( WC()->cart->get_total() ), ENT_QUOTES, 'UTF-8' );
		}
		return $text;
	}

	/**
	 * Use our checkout templates (a theme can still override them in /checkoutflow/checkout/).
	 */
	public function locate_template( $template, $name ) {
		if ( in_array( $name, array( 'checkout/form-checkout.php', 'checkout/review-order.php', 'checkout/payment.php' ), true ) ) {
			$theme = locate_template( 'checkoutflow/' . $name );
			return $theme ? $theme : CHECKOUTFLOW_DIR . 'templates/' . $name;
		}
		return $template;
	}

	/**
	 * Shipping method list for the left column (radio names match WooCommerce's, so its JS
	 * recalculates totals on change).
	 *
	 * @return string
	 */
	public static function shipping_methods_html() {
		$packages = WC()->shipping()->get_packages();
		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods' );
		ob_start();
		echo '<div class="cf-shipping-methods">';
		if ( ! $packages || ! array_filter( wp_list_pluck( $packages, 'rates' ) ) ) {
			$has_address = WC()->customer && WC()->customer->has_calculated_shipping();
			echo '<p class="cf-shipping-empty">' . esc_html( $has_address ? __( 'No shipping options are available for this address.', 'checkoutflow' ) : __( 'Enter your shipping address to see shipping options.', 'checkoutflow' ) ) . '</p>';
		}
		foreach ( $packages as $i => $package ) {
			$rates = $package['rates'];
			if ( count( $packages ) > 1 ) {
				/* translators: %d: package number */
				echo '<p class="cf-package-name">' . esc_html( apply_filters( 'woocommerce_shipping_package_name', sprintf( __( 'Shipment %d', 'checkoutflow' ), $i + 1 ), $i, $package ) ) . '</p>';
			}
			$current = isset( $chosen[ $i ] ) ? $chosen[ $i ] : '';
			echo '<ul class="woocommerce-shipping-methods cf-rates">';
			foreach ( $rates as $rate ) {
				$id    = 'shipping_method_' . $i . '_' . sanitize_title( $rate->id );
				$cost  = (float) $rate->get_cost();
				$tax   = WC()->cart->display_prices_including_tax() ? array_sum( $rate->get_taxes() ) : 0;
				$price = $cost + $tax > 0 ? wc_price( $cost + $tax ) : __( 'Free', 'checkoutflow' );
				echo '<li class="cf-rate' . ( $rate->id === $current ? ' is-selected' : '' ) . '"><label for="' . esc_attr( $id ) . '">';
				if ( 1 < count( $rates ) ) {
					printf( '<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="%2$s" value="%3$s" class="shipping_method" %4$s />', (int) $i, esc_attr( $id ), esc_attr( $rate->id ), checked( $rate->id, $current, false ) );
				} else {
					printf( '<input type="hidden" name="shipping_method[%1$d]" data-index="%1$d" id="%2$s" value="%3$s" class="shipping_method" />', (int) $i, esc_attr( $id ), esc_attr( $rate->id ) );
				}
				echo '<span class="cf-rate-label">' . esc_html( $rate->get_label() ) . '</span><span class="cf-rate-price">' . wp_kses_post( $price ) . '</span></label>';
				do_action( 'woocommerce_after_shipping_rate', $rate, $i );
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	public function review_fragments( $fragments ) {
		$fragments['.cf-shipping-methods']       = self::shipping_methods_html();
		ob_start();
		wc_cart_totals_order_total_html();
		$fragments['.cf-summary-toggle-total'] = '<span class="cf-summary-toggle-total">' . ob_get_clean() . '</span>';
		// Keep the header cart icon in sync when quantities change on the checkout.
		$count                           = WC()->cart->get_cart_contents_count();
		$fragments['.cf-cart-link .cf-cart-count'] = '<span class="cf-cart-count" data-count="' . esc_attr( $count ) . '">' . esc_html( $count ) . '</span>';
		$fragments['.cf-cart-link .cf-cart-total'] = '<span class="cf-cart-total">' . wp_kses_post( wc_price( cart_display_total() ) ) . '</span>';
		return $fragments;
	}

	/**
	 * Shipping-first form: billing = shipping unless "Use a different billing address" is ticked.
	 */
	public function copy_shipping_to_billing( $data ) {
		// phpcs:disable WordPress.Security.NonceVerification -- runs inside WooCommerce's nonce-checked checkout.
		if ( empty( $_POST['cf_shipping_first'] ) || ! empty( $_POST['cf_different_billing'] ) ) {
			return $data;
		}
		// phpcs:enable
		foreach ( self::ADDRESS_KEYS as $k ) {
			if ( array_key_exists( 'shipping_' . $k, $data ) ) {
				$data[ 'billing_' . $k ] = $data[ 'shipping_' . $k ];
			}
		}
		if ( isset( $data['shipping_phone'] ) && '' !== $data['shipping_phone'] && empty( $data['billing_phone'] ) ) {
			$data['billing_phone'] = $data['shipping_phone'];
		}
		$data['ship_to_different_address'] = true;
		return $data;
	}

	/**
	 * Quantity change / remove from the checkout summary. The page then refreshes totals
	 * through WooCommerce's normal update_checkout.
	 */
	public function ajax_qty() {
		if ( ! check_ajax_referer( 'cf-checkout', 'nonce', false ) ) {
			wp_send_json_error( array( 'reload' => true ), 403 );
		}
		$key  = isset( $_POST['key'] ) ? wc_clean( wp_unslash( $_POST['key'] ) ) : '';
		$qty  = isset( $_POST['qty'] ) ? wc_stock_amount( wp_unslash( $_POST['qty'] ) ) : 0;
		$item = WC()->cart->get_cart_item( $key );
		if ( $item ) {
			if ( $qty <= 0 ) {
				WC()->cart->remove_cart_item( $key );
			} elseif ( apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty ) ) {
				$product = $item['data'];
				if ( $product->managing_stock() && ! $product->backorders_allowed() && $qty > $product->get_stock_quantity() ) {
					$qty = $product->get_stock_quantity();
				}
				WC()->cart->set_quantity( $key, $product->is_sold_individually() ? 1 : $qty, true );
			}
		}
		wp_send_json_success( array( 'empty' => WC()->cart->is_empty() ) );
	}

	/**
	 * Trust badges under the order summary.
	 *
	 * @return string
	 */
	public static function badges_html() {
		$icons = array(
			'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
			'truck'   => '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
			'lock'    => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
			'award'   => '<circle cx="12" cy="10" r="6"/><path d="m9 10 2 2 4-4"/><path d="M8.5 15 7 22l5-3 5 3-1.5-7"/>',
			'clipboard' => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4V3h6v1"/><path d="M8 10h8M8 14h8M8 18h5"/>',
			'box'     => '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
			'star'    => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
			'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
			'chat'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
			'check'   => '<circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/>',
		);
		$out = '';
		foreach ( preg_split( '/\r?\n/', (string) Settings::get( 'checkout_badges' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$icon = isset( $parts[2], $icons[ $parts[2] ] ) ? $icons[ $parts[2] ] : $icons['check'];
			$out .= '<li class="cf-badge-item"><svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $icon . '</svg><div><strong>' . esc_html( $parts[0] ) . '</strong>' . ( ! empty( $parts[1] ) ? '<span>' . esc_html( $parts[1] ) . '</span>' : '' ) . '</div></li>';
		}
		return $out ? '<ul class="cf-badges">' . $out . '</ul>' : '';
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
		if ( is_checkout() && is_wc_endpoint_url( 'order-received' ) && Thank_You::enabled() ) {
			Thank_You::setup();
			return;
		}
		if ( ! $this->is_checkout_form() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		if ( 'focused' === Settings::get( 'checkout_template' ) ) {
			add_filter( 'template_include', array( $this, 'template' ), 99 );
		}

		if ( self::is_modern() ) {
			// The "Returning customer?" link sits in the form column (see form-checkout.php);
			// only the hidden login <form> stays above, since forms can't be nested.
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'login_form_only' ), 10 );
		}

		$coupon = Settings::get( 'checkout_coupon' );
		if ( 'default' !== $coupon ) {
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
		}
		// The modern summary template renders its own coupon box.
		if ( in_array( $coupon, array( 'summary', 'inline' ), true ) && wc_coupons_enabled() && ! self::is_modern() ) {
			add_action( 'woocommerce_review_order_before_order_total', array( $this, 'coupon_row' ) );
		}

		if ( '' !== trim( (string) Settings::get( 'checkout_trust_text' ) ) ) {
			add_action( 'woocommerce_review_order_after_submit', array( $this, 'trust_text' ) );
		}
	}

	public function assets() {
		list( $css, $ver ) = asset( 'css/checkout.css' );
		wp_enqueue_style( 'checkoutflow-checkout', $css, array(), $ver );
		wp_add_inline_style( 'checkoutflow-checkout', 'body.cf-checkout{--cf-accent:' . Settings::get( 'checkout_accent_color' ) . ';}' . design_css() );

		list( $js, $ver ) = asset( 'js/checkout.js' );
		wp_enqueue_script( 'checkoutflow-checkout', $js, array( 'jquery', 'wc-checkout' ), $ver, true );
		wp_localize_script(
			'checkoutflow-checkout',
			'checkoutflowCheckout',
			array(
				'addAddress2' => __( '+ Add apartment, suite, unit, etc.', 'checkoutflow' ),
				'showSummary' => __( 'Show order summary', 'checkoutflow' ),
				'hideSummary' => __( 'Hide order summary', 'checkoutflow' ),
				'couponApplied' => __( 'Coupon code applied successfully.', 'checkoutflow' ),
				'couponRemoved' => __( 'Coupon removed.', 'checkoutflow' ),
				'couponError'   => __( 'That coupon code could not be applied.', 'checkoutflow' ),
				'removeCoupon'  => __( 'Remove', 'checkoutflow' ),
			)
		);
	}

	public function body_class( $classes ) {
		$classes[] = 'cf-checkout';
		if ( self::is_modern() ) {
			$classes[] = 'cf-checkout-modern';
		}
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
		// Labels, visibility and custom fields are handled by Checkout_Fields (priority 30).
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
		// The "order" group also carries custom fields, so it renders when either is in use;
		// a hidden notes field is removed by Checkout_Fields.
		$notes = null;
		foreach ( Checkout_Fields::config()['fields'] as $f ) {
			if ( 'order_comments' === $f['key'] ) {
				$notes = $f['enabled'];
			}
		}
		return ( null === $notes ? $enabled : $notes ) || (bool) Checkout_Fields::custom_inputs() || self::has_custom_text();
	}

	private static function has_custom_text() {
		foreach ( Checkout_Fields::config()['fields'] as $f ) {
			if ( $f['custom'] && $f['enabled'] && 'paragraph' === $f['type'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collapsed coupon input inside the order summary table.
	 * It can't be a nested <form>, so checkout.js submits it via wc-ajax=apply_coupon.
	 */
	public function coupon_row() {
		?>
		<tr class="cf-coupon-row">
			<td colspan="2">
				<?php $cf_inline = 'inline' === Settings::get( 'checkout_coupon' ); ?>
				<?php if ( ! $cf_inline ) : ?>
					<a href="#" class="cf-coupon-toggle"><?php esc_html_e( 'Have a coupon code?', 'checkoutflow' ); ?></a>
				<?php endif; ?>
				<div class="cf-coupon-box" <?php echo $cf_inline ? '' : 'hidden'; ?>>
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
