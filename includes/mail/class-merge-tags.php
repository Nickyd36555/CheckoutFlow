<?php
/**
 * Merge tags: {first_name}, {cart_items}, {coupon_code}, ... with optional fallback: {first_name|there}.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Mail;

defined( 'ABSPATH' ) || exit;

class Merge_Tags {

	/** Tags whose values are HTML (inserted unescaped in HTML context). */
	const HTML_TAGS = array( 'cart_items', 'order_items', 'recovery_button' );

	/**
	 * Tag reference for the builder UI.
	 *
	 * @return array tag => description
	 */
	public static function reference() {
		return array(
			'first_name'      => __( 'First name', 'checkoutflow' ),
			'last_name'       => __( 'Last name', 'checkoutflow' ),
			'email'           => __( 'Email address', 'checkoutflow' ),
			'site_name'       => __( 'Store name', 'checkoutflow' ),
			'site_url'        => __( 'Home page URL', 'checkoutflow' ),
			'shop_url'        => __( 'Shop page URL', 'checkoutflow' ),
			'store_address'   => __( 'Store address', 'checkoutflow' ),
			'unsubscribe_url' => __( 'Unsubscribe URL', 'checkoutflow' ),
			'cart_items'      => __( 'Abandoned cart items table', 'checkoutflow' ),
			'cart_total'      => __( 'Abandoned cart total', 'checkoutflow' ),
			'recovery_url'    => __( 'Link that restores the cart and opens checkout', 'checkoutflow' ),
			'recovery_button' => __( '"Complete your order" button', 'checkoutflow' ),
			'order_number'    => __( 'Order number', 'checkoutflow' ),
			'order_total'     => __( 'Order total', 'checkoutflow' ),
			'order_date'      => __( 'Order date', 'checkoutflow' ),
			'order_items'     => __( 'Order items table', 'checkoutflow' ),
			'order_url'       => __( 'View order URL (My Account)', 'checkoutflow' ),
			'review_url'      => __( 'Review link for the first ordered product', 'checkoutflow' ),
			'coupon_code'     => __( 'Generated coupon code (needs a Coupon block)', 'checkoutflow' ),
			'coupon_amount'   => __( 'Coupon discount, e.g. 10%', 'checkoutflow' ),
			'coupon_expiry'   => __( 'Coupon expiry date', 'checkoutflow' ),
		);
	}

	/**
	 * Replace tags in a string.
	 *
	 * @param string $str  Input.
	 * @param array  $tags Values from values().
	 * @param string $mode html|text|url.
	 * @return string
	 */
	public static function apply( $str, $tags, $mode = 'html' ) {
		return preg_replace_callback(
			'/\{([a-z_]+)(?:\|([^{}]*))?\}/',
			static function ( $m ) use ( $tags, $mode ) {
				$name = $m[1];
				if ( ! array_key_exists( $name, $tags ) ) {
					return $m[0];
				}
				$value = (string) $tags[ $name ];
				if ( '' === $value && isset( $m[2] ) ) {
					$value = $m[2];
				}
				$is_html = in_array( $name, self::HTML_TAGS, true );
				if ( 'html' === $mode ) {
					return $is_html ? $value : esc_html( $value );
				}
				return $is_html ? '' : $value;
			},
			(string) $str
		);
	}

	/**
	 * Compute tag values for a send context.
	 *
	 * @param array $ctx Context (contact, cart, order, coupon, unsubscribe_url, recovery_url, preview).
	 * @return array
	 */
	public static function values( $ctx ) {
		$contact = isset( $ctx['contact'] ) ? (array) $ctx['contact'] : array();
		$cart    = isset( $ctx['cart'] ) ? $ctx['cart'] : null;
		$order   = isset( $ctx['order'] ) && $ctx['order'] instanceof \WC_Order ? $ctx['order'] : null;
		$coupon  = isset( $ctx['coupon'] ) ? $ctx['coupon'] : array();
		$preview = ! empty( $ctx['preview'] );

		$first = isset( $contact['first_name'] ) ? $contact['first_name'] : '';
		$last  = isset( $contact['last_name'] ) ? $contact['last_name'] : '';
		$email = isset( $contact['email'] ) ? $contact['email'] : '';
		if ( '' === $first && $cart ) {
			$first = $cart['first_name'];
			$last  = $cart['last_name'];
		}
		if ( '' === $first && $order ) {
			$first = $order->get_billing_first_name();
			$last  = $order->get_billing_last_name();
		}

		$accent = isset( $ctx['accent'] ) ? $ctx['accent'] : \CheckoutFlow\Settings::get( 'email_accent' );

		$v = array(
			'first_name'      => $first,
			'last_name'       => $last,
			'email'           => $email,
			'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'        => home_url( '/' ),
			'shop_url'        => wc_get_page_permalink( 'shop' ),
			'store_address'   => self::store_address(),
			'unsubscribe_url' => isset( $ctx['unsubscribe_url'] ) ? $ctx['unsubscribe_url'] : '#',
			'cart_items'      => '',
			'cart_total'      => '',
			'recovery_url'    => isset( $ctx['recovery_url'] ) ? $ctx['recovery_url'] : wc_get_checkout_url(),
			'recovery_button' => '',
			'order_number'    => '',
			'order_total'     => '',
			'order_date'      => '',
			'order_items'     => '',
			'order_url'       => wc_get_page_permalink( 'myaccount' ),
			'review_url'      => '',
			'coupon_code'     => isset( $coupon['code'] ) ? strtoupper( $coupon['code'] ) : '',
			'coupon_amount'   => isset( $coupon['amount'] ) ? $coupon['amount'] : '',
			'coupon_expiry'   => isset( $coupon['expiry'] ) ? $coupon['expiry'] : '',
		);

		// Cart.
		$items = array();
		if ( $cart ) {
			foreach ( \CheckoutFlow\DB::json( $cart['items'] ) as $item ) {
				$product = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
				if ( ! $product ) {
					continue;
				}
				$items[] = array(
					'name'  => $product->get_name(),
					'qty'   => (int) $item['quantity'],
					'total' => self::price( (float) $item['line_total'] + ( wc_prices_include_tax() || 'incl' === get_option( 'woocommerce_tax_display_cart' ) ? (float) $item['line_tax'] : 0 ), $cart['currency'] ),
					'image' => self::image( $product ),
				);
			}
			$v['cart_total'] = self::price( $cart['total'], $cart['currency'] );
		} elseif ( $preview ) {
			$items           = self::sample_items();
			$v['cart_total'] = self::price( 59.98 );
		}
		$v['cart_items']      = Renderer::items_table( $items );
		$v['recovery_button'] = Renderer::button( $v['recovery_url'], esc_html__( 'Complete your order', 'checkoutflow' ), $accent );

		// Order.
		if ( $order ) {
			$order_items = array();
			foreach ( $order->get_items() as $item ) {
				$product       = $item->get_product();
				$order_items[] = array(
					'name'  => $item->get_name(),
					'qty'   => $item->get_quantity(),
					'total' => wp_strip_all_tags( html_entity_decode( $order->get_formatted_line_subtotal( $item ), ENT_QUOTES ) ),
					'image' => $product ? self::image( $product ) : wc_placeholder_img_src(),
				);
				if ( '' === $v['review_url'] && $product ) {
					$parent          = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
					$v['review_url'] = $parent ? $parent->get_permalink() . '#reviews' : '';
				}
			}
			$v['order_number'] = $order->get_order_number();
			$v['order_total']  = wp_strip_all_tags( html_entity_decode( $order->get_formatted_order_total(), ENT_QUOTES ) );
			$v['order_date']   = wc_format_datetime( $order->get_date_created() );
			$v['order_items']  = Renderer::items_table( $order_items );
			$v['order_url']    = $order->get_view_order_url();
		} elseif ( $preview ) {
			$v['order_number'] = '1001';
			$v['order_total']  = self::price( 59.98 );
			$v['order_date']   = wc_format_datetime( new \WC_DateTime() );
			$v['order_items']  = Renderer::items_table( self::sample_items() );
			$v['review_url']   = wc_get_page_permalink( 'shop' );
		}

		if ( $preview && '' === $v['first_name'] ) {
			$v['first_name'] = __( 'Alex', 'checkoutflow' );
		}

		return apply_filters( 'checkoutflow_merge_tags', $v, $ctx );
	}

	private static function price( $amount, $currency = '' ) {
		$args = $currency ? array( 'currency' => $currency ) : array();
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function image( $product ) {
		$id = $product->get_image_id();
		if ( ! $id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$id     = $parent ? $parent->get_image_id() : 0;
		}
		return $id ? wp_get_attachment_image_url( $id, 'woocommerce_gallery_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_gallery_thumbnail' );
	}

	private static function sample_items() {
		$items = array();
		foreach ( wc_get_products( array( 'limit' => 2, 'status' => 'publish' ) ) as $p ) {
			$items[] = array(
				'name'  => $p->get_name(),
				'qty'   => 1,
				'total' => self::price( (float) $p->get_price() ),
				'image' => self::image( $p ),
			);
		}
		if ( ! $items ) {
			$items[] = array( 'name' => __( 'Sample product', 'checkoutflow' ), 'qty' => 2, 'total' => self::price( 59.98 ), 'image' => wc_placeholder_img_src() );
		}
		return $items;
	}

	public static function store_address() {
		$countries = WC()->countries;
		$parts     = array_filter(
			array(
				$countries->get_base_address(),
				$countries->get_base_address_2(),
				trim( $countries->get_base_city() . ' ' . $countries->get_base_postcode() ),
				$countries->get_base_country() ? ( isset( $countries->countries[ $countries->get_base_country() ] ) ? $countries->countries[ $countries->get_base_country() ] : $countries->get_base_country() ) : '',
			)
		);
		return implode( ', ', $parts );
	}
}
