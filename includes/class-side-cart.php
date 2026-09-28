<?php
/**
 * Slide-out side cart.
 *
 * Page-cache safe: the drawer shell printed in the footer contains no cart data.
 * Contents arrive as a WooCommerce fragment (when cart fragments are running) or
 * from one wc-ajax request on page load, made only when the cart cookie says the
 * cart is not empty.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Side_Cart {

	const NONCE = 'checkoutflow-cart';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 20 );
		add_action( 'wp_footer', array( $this, 'shell' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragments' ) );
		add_shortcode( 'checkoutflow_cart_icon', array( $this, 'shortcode_icon' ) );

		foreach ( array( 'get', 'add', 'qty', 'remove', 'coupon', 'remove_coupon' ) as $action ) {
			add_action( 'wc_ajax_cf_cart_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * The drawer is pointless on the cart and checkout pages themselves.
	 *
	 * @return bool
	 */
	private function is_active_page() {
		return ! is_cart() && ! is_checkout() && ! is_admin();
	}

	public function assets() {
		if ( Settings::get( 'cart_disable_fragments' ) ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
		if ( ! $this->is_active_page() ) {
			return;
		}

		list( $css, $ver ) = asset( 'css/side-cart.css' );
		wp_enqueue_style( 'checkoutflow-cart', $css, array(), $ver );
		wp_add_inline_style(
			'checkoutflow-cart',
			sprintf( ':root{--cfc-accent:%s;--cfc-width:%dpx;}', Settings::get( 'cart_accent_color' ), (int) Settings::get( 'cart_width' ) )
		);

		list( $js, $ver ) = asset( 'js/side-cart.js' );
		wp_enqueue_script( 'checkoutflow-cart', $js, array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'checkoutflow-cart',
			'checkoutflowCart',
			array(
				'ajaxUrl'    => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'autoOpen'   => (bool) Settings::get( 'cart_auto_open' ),
				'ajaxSingle' => (bool) Settings::get( 'cart_ajax_single' ) && 'yes' !== get_option( 'woocommerce_cart_redirect_after_add' ),
				'i18n'       => array(
					'error' => __( 'Something went wrong. Please try again.', 'checkoutflow' ),
				),
			)
		);
	}

	/**
	 * Static drawer markup. No cart data here, so it's safe to page-cache.
	 */
	public function shell() {
		if ( ! $this->is_active_page() ) {
			return;
		}
		echo render( 'side-cart', array( 'content' => $this->empty_content_placeholder() ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function empty_content_placeholder() {
		return '<div class="cf-cart-content" data-count="0"><div class="cfc-loading-state" aria-hidden="true"></div></div>';
	}

	public function shortcode_icon() {
		return '<a href="' . esc_url( wc_get_cart_url() ) . '" class="cf-open-cart cf-cart-link" aria-label="' . esc_attr__( 'Open cart', 'checkoutflow' ) . '">'
			. '<svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>'
			. '<span class="cf-cart-count">0</span></a>';
	}

	/**
	 * Cart body HTML (items, shipping bar, recommendations, totals).
	 *
	 * @return string
	 */
	public function content_html() {
		$cart = WC()->cart;
		$cart->calculate_totals();

		$threshold = Settings::get( 'cart_free_shipping_bar' ) && $cart->needs_shipping() ? free_shipping_threshold() : 0;
		$shipping  = null;
		if ( $threshold > 0 && ! $cart->is_empty() ) {
			$amount    = cart_total_after_discounts();
			$remaining = max( 0, $threshold - $amount );
			$shipping  = array(
				'percent' => min( 100, round( $amount / $threshold * 100 ) ),
				'text'    => $remaining > 0
					? str_replace( '{amount}', wc_price( $remaining ), esc_html( Settings::get( 'cart_free_shipping_text' ) ) )
					: esc_html( Settings::get( 'cart_free_shipping_done' ) ),
			);
		}

		return render(
			'side-cart-content',
			array(
				'cart'     => $cart,
				'shipping' => $shipping,
				'upsells'  => Settings::get( 'cart_upsells' ) ? $this->recommendations() : array(),
				'nonce'    => wp_create_nonce( self::NONCE ),
			)
		);
	}

	/**
	 * Cross-sells of the items in the cart that can be bought right now.
	 *
	 * @return \WC_Product[]
	 */
	private function recommendations() {
		$cart = WC()->cart;
		if ( $cart->is_empty() ) {
			return array();
		}
		$in_cart = array();
		foreach ( $cart->get_cart() as $item ) {
			$in_cart[] = (int) $item['product_id'];
		}

		$ids = array_diff( array_map( 'intval', $cart->get_cross_sells() ), $in_cart );
		$ids = apply_filters( 'checkoutflow_cart_recommendation_ids', array_values( $ids ), $cart );
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->is_purchasable() && $product->is_in_stock() && $product->is_visible() ) {
				$out[] = $product;
			}
			if ( count( $out ) >= (int) Settings::get( 'cart_upsell_limit' ) ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Adds our markup to WooCommerce's fragments, so the standard add-to-cart AJAX
	 * and cart-fragments responses keep the drawer in sync with no extra request.
	 *
	 * @param array $fragments Fragments.
	 * @return array
	 */
	public function fragments( $fragments ) {
		if ( ! WC()->cart ) {
			return $fragments;
		}
		$count                                = WC()->cart->get_cart_contents_count();
		$fragments['div.cf-cart-content']     = $this->content_html();
		$fragments['span.cf-cart-count']      = '<span class="cf-cart-count" data-count="' . esc_attr( $count ) . '">' . esc_html( $count ) . '</span>';
		return $fragments;
	}

	/**
	 * Standard response: WooCommerce fragments (so theme mini-carts update too) + notices.
	 *
	 * @param bool $success Whether the action succeeded.
	 */
	private function respond( $success = true ) {
		ob_start();
		woocommerce_mini_cart();
		$mini = ob_get_clean();

		$fragments = apply_filters(
			'woocommerce_add_to_cart_fragments',
			array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' )
		);

		$notices = wc_get_notices();
		wc_clear_notices();
		$messages = array();
		foreach ( $notices as $type => $list ) {
			foreach ( $list as $notice ) {
				$messages[] = array(
					'type' => $type,
					'text' => html_entity_decode( wp_strip_all_tags( is_array( $notice ) ? $notice['notice'] : $notice ), ENT_QUOTES, 'UTF-8' ),
				);
			}
		}

		wp_send_json(
			array(
				'success'   => $success && empty( $notices['error'] ),
				'fragments' => $fragments,
				'cart_hash' => WC()->cart->get_cart_hash(),
				'notices'   => $messages,
			)
		);
	}

	/**
	 * Cart mutations require the nonce delivered with the last cart render.
	 * (Adding to cart does not, matching WooCommerce's own wc-ajax=add_to_cart.)
	 */
	private function verify() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'checkoutflow' ), 'error' );
			$this->respond( false );
		}
	}

	public function ajax_get() {
		$this->respond();
	}

	/**
	 * AJAX add to cart from a single product form. Delegates to WooCommerce's own
	 * form handler so every product type, validation rule and add-on plugin behaves
	 * exactly like a normal form submit.
	 */
	public function ajax_add() {
		// The JS posts `cf-add-to-cart` (not `add-to-cart`) so WC_Form_Handler doesn't
		// already process this request on wp_loaded before we get here.
		$product_id = isset( $_POST['cf-add-to-cart'] ) ? absint( $_POST['cf-add-to-cart'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $product_id ) {
			wc_add_notice( __( 'Please choose a product.', 'checkoutflow' ), 'error' );
			$this->respond( false );
		}
		$_REQUEST['add-to-cart'] = $product_id;

		add_filter( 'woocommerce_add_to_cart_redirect', '__return_false', 999 );
		add_filter( 'pre_option_woocommerce_cart_redirect_after_add', array( $this, 'option_no' ), 999 );
		\WC_Form_Handler::add_to_cart_action();

		$this->clear_success_notices();
		$this->respond();
	}

	public function option_no() {
		return 'no';
	}

	/**
	 * "Added to cart" style success notices are redundant next to an updating drawer.
	 */
	private function clear_success_notices() {
		$notices = WC()->session ? WC()->session->get( 'wc_notices', array() ) : array();
		if ( isset( $notices['success'] ) ) {
			unset( $notices['success'] );
			WC()->session->set( 'wc_notices', $notices );
		}
	}

	public function ajax_qty() {
		$this->verify();
		$key = isset( $_POST['key'] ) ? wc_clean( wp_unslash( $_POST['key'] ) ) : '';
		$qty = isset( $_POST['qty'] ) ? wc_stock_amount( wp_unslash( $_POST['qty'] ) ) : 0;
		$item = WC()->cart->get_cart_item( $key );

		if ( $item ) {
			if ( $qty <= 0 ) {
				WC()->cart->remove_cart_item( $key );
			} elseif ( apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty ) ) {
				$product = $item['data'];
				if ( $product->is_sold_individually() ) {
					$qty = 1;
				}
				if ( $product->managing_stock() && ! $product->backorders_allowed() && $qty > $product->get_stock_quantity() ) {
					/* translators: %s: stock quantity */
					wc_add_notice( sprintf( __( 'Sorry, only %s available.', 'checkoutflow' ), $product->get_stock_quantity() ), 'error' );
					$qty = $product->get_stock_quantity();
				}
				WC()->cart->set_quantity( $key, $qty, true );
			}
		}
		$this->respond();
	}

	public function ajax_remove() {
		$this->verify();
		$key = isset( $_POST['key'] ) ? wc_clean( wp_unslash( $_POST['key'] ) ) : '';
		if ( WC()->cart->get_cart_item( $key ) ) {
			WC()->cart->remove_cart_item( $key );
		}
		$this->respond();
	}

	public function ajax_coupon() {
		$this->verify();
		$code = isset( $_POST['code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $code ) {
			wc_add_notice( __( 'Please enter a coupon code.', 'checkoutflow' ), 'error' );
		} else {
			WC()->cart->apply_coupon( $code );
			$this->clear_success_notices();
		}
		$this->respond();
	}

	public function ajax_remove_coupon() {
		$this->verify();
		$code = isset( $_POST['code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		WC()->cart->remove_coupon( $code );
		WC()->cart->calculate_totals();
		$this->clear_success_notices();
		$this->respond();
	}
}
