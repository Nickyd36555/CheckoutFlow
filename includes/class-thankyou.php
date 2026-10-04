<?php
/**
 * Thank-you (order received) page, built from blocks in CheckoutFlow → Thank You Page
 * (same drag-and-drop editor as emails). Front-end hooks load only on the order-received
 * endpoint; WooCommerce and payment-gateway thank-you hooks keep working.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Thank_You {

	const OPTION = 'checkoutflow_thankyou_design';

	/** @var bool The layout's summary cards already show the payment method. */
	private static $cards_show_payment = false;

	public static function enabled() {
		return (bool) Settings::get( 'ty_enabled' );
	}

	/**
	 * Other funnel plugins (e.g. FunnelKit) send customers to their own thank-you page;
	 * point the order-received link back to WooCommerce's, which this class renders.
	 */
	public static function own_received_url( $url, $order ) {
		if ( ! $order instanceof \WC_Order || false !== strpos( (string) $url, 'order-received' ) ) {
			return $url;
		}
		return add_query_arg( 'key', $order->get_order_key(), wc_get_endpoint_url( 'order-received', $order->get_id(), wc_get_checkout_url() ) );
	}

	/**
	 * Called on `wp` once we know this is the order-received page.
	 */
	public static function setup() {
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_template' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		// The Order items / Customer blocks replace WooCommerce's table; other woocommerce_thankyou callbacks still run.
		remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
	}

	public static function locate_template( $template, $name ) {
		if ( 'checkout/thankyou.php' === $name ) {
			$theme = locate_template( 'checkoutflow/' . $name );
			return $theme ? $theme : CHECKOUTFLOW_DIR . 'templates/' . $name;
		}
		return $template;
	}

	public static function assets() {
		list( $css, $ver ) = asset( 'css/thankyou.css' );
		wp_enqueue_style( 'checkoutflow-thankyou', $css, array(), $ver );
		wp_add_inline_style( 'checkoutflow-thankyou', self::inline_css( self::design() ) );
	}

	public static function body_class( $classes ) {
		$classes[] = 'cf-thankyou-page';
		return $classes;
	}

	/* ---------- design storage ---------- */

	/**
	 * Block types for the builder: label, default props, and select options.
	 */
	public static function block_types() {
		$align = array(
			'left'   => __( 'Left', 'checkoutflow' ),
			'center' => __( 'Center', 'checkoutflow' ),
			'right'  => __( 'Right', 'checkoutflow' ),
		);
		return array(
			'heading'  => array(
				'group' => 'general',
				'label' => __( 'Heading', 'checkoutflow' ),
				'props' => array( 'text' => __( 'Thank you for your order, {first_name}!', 'checkoutflow' ), 'size' => 30, 'align' => 'center', 'color' => '' ),
			),
			'text'     => array(
				'group' => 'general',
				'label' => __( 'Text', 'checkoutflow' ),
				'props' => array( 'html' => '<p>' . __( 'Your text here.', 'checkoutflow' ) . '</p>', 'align' => 'left' ),
			),
			'overview' => array(
				'group'   => 'order',
				'label'   => __( 'Order summary cards', 'checkoutflow' ),
				'dynamic' => true,
				'props'   => array( 'show_date' => true, 'show_total' => true, 'show_payment' => true, 'show_email' => false ),
			),
			'items'    => array(
				'group'   => 'order',
				'label'   => __( 'Order items', 'checkoutflow' ),
				'dynamic' => true,
				'props'   => array( 'title' => __( 'Items', 'checkoutflow' ), 'show_images' => true, 'show_totals' => true ),
			),
			'payment'  => array(
				'group'   => 'order',
				'label'   => __( 'Payment instructions', 'checkoutflow' ),
				'dynamic' => true,
				'props'   => array( 'title' => '' ),
			),
			'customer' => array(
				'group'   => 'order',
				'label'   => __( 'Customer information', 'checkoutflow' ),
				'dynamic' => true,
				'props'   => array( 'title' => __( 'Information', 'checkoutflow' ), 'align' => 'center', 'billing' => 'different', 'show_contact' => true ),
				'options' => array(
					'billing' => array(
						'different' => __( 'Billing address only when different', 'checkoutflow' ),
						'always'    => __( 'Always show billing address', 'checkoutflow' ),
						'never'     => __( 'Never show billing address', 'checkoutflow' ),
					),
				),
			),
			'support'  => array(
				'group' => 'general',
				'label' => __( 'Support bar', 'checkoutflow' ),
				'props' => array( 'title' => __( 'For Support', 'checkoutflow' ), 'email' => get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ), 'phone' => '' ),
			),
			'button'   => array(
				'group' => 'general',
				'label' => __( 'Button', 'checkoutflow' ),
				'props' => array( 'text' => __( 'Continue shopping', 'checkoutflow' ), 'url' => '{shop_url}', 'align' => 'center', 'color' => '' ),
			),
			'image'    => array(
				'group' => 'general',
				'label' => __( 'Image', 'checkoutflow' ),
				'props' => array( 'src' => '', 'alt' => '', 'url' => '', 'width' => 100, 'align' => 'center' ),
			),
			'divider'  => array(
				'group' => 'general',
				'label' => __( 'Divider', 'checkoutflow' ),
				'props' => array( 'color' => '' ),
			),
			'spacer'   => array(
				'group' => 'general',
				'label' => __( 'Spacer', 'checkoutflow' ),
				'props' => array( 'height' => 24 ),
			),
			'html'     => array(
				'group' => 'general',
				'label' => __( 'Custom HTML', 'checkoutflow' ),
				'props' => array( 'html' => '' ),
			),
			'columns'  => array(
				'group' => 'structure',
				'label' => __( 'Columns', 'checkoutflow' ),
				'props' => array( 'layout' => '50-50', 'cols' => array() ),
			),
		) + array( '_align' => $align ); // Shared select options (not a block).
	}

	/**
	 * @return array Block types without the shared-options entry.
	 */
	public static function types() {
		$t = self::block_types();
		unset( $t['_align'] );
		return $t;
	}

	/**
	 * Column layouts (percent widths); same keys as the email editor.
	 */
	public static function layouts() {
		return array(
			'100'         => array( 100 ),
			'50-50'       => array( 50, 50 ),
			'33-67'       => array( 33.33, 66.67 ),
			'67-33'       => array( 66.67, 33.33 ),
			'33-33-33'    => array( 33.33, 33.33, 33.34 ),
			'25-25-25-25' => array( 25, 25, 25, 25 ),
		);
	}

	private static function b( $type, $props = array() ) {
		$t = self::types();
		return array_merge( array( 'type' => $type ), $t[ $type ]['props'], $props );
	}

	/**
	 * Ready-made sections for the editor's Layouts tab: key => [ label, blocks ].
	 */
	public static function sections() {
		return array(
			'hero'        => array( __( 'Thank-you heading + text', 'checkoutflow' ), array( self::b( 'heading', array( 'text' => __( 'Thank you, {first_name}!', 'checkoutflow' ) ) ), self::b( 'text', array( 'html' => '<p>' . __( 'Your order <strong>#{order_number}</strong> is confirmed. A confirmation email is on its way to <strong>{email}</strong>.', 'checkoutflow' ) . '</p>', 'align' => 'center' ) ) ) ),
			'order_side'  => array( __( 'Items + customer side by side', 'checkoutflow' ), array( array( 'type' => 'columns', 'layout' => '50-50', 'cols' => array( array( self::b( 'items' ) ), array( self::b( 'customer' ) ) ) ) ) ),
			'support_cta' => array( __( 'Support bar + button', 'checkoutflow' ), array( self::b( 'support' ), self::b( 'button' ) ) ),
			'two_buttons' => array( __( 'Two buttons', 'checkoutflow' ), array( array( 'type' => 'columns', 'layout' => '50-50', 'cols' => array( array( self::b( 'button', array( 'text' => __( 'Track your order', 'checkoutflow' ), 'url' => '{track_url}', 'align' => 'right' ) ) ), array( self::b( 'button', array( 'align' => 'left' ) ) ) ) ) ) ),
			'image_text'  => array( __( 'Image + text', 'checkoutflow' ), array( array( 'type' => 'columns', 'layout' => '33-67', 'cols' => array( array( self::b( 'image' ) ), array( self::b( 'text' ) ) ) ) ) ),
		);
	}

	public static function default_settings() {
		return array(
			'page_bg'    => '#f4f6f7',
			'card_bg'    => '#ffffff',
			'accent'     => (string) Settings::get( 'checkout_accent_color' ),
			'text_color' => '#111111',
			'width'      => 760,
		);
	}

	/**
	 * Default layout, modeled on a typical FunnelKit thank-you page.
	 */
	public static function default_design() {
		$t      = self::types();
		$blocks = array();
		$add    = static function ( $type, $props = array() ) use ( &$blocks, $t ) {
			$blocks[] = array_merge( array( 'type' => $type ), $t[ $type ]['props'], $props );
		};
		$add( 'heading', array( 'text' => __( 'Thank You For Your Order, {first_name}!', 'checkoutflow' ) ) );
		$add( 'text', array( 'html' => '<p>' . __( 'We are pleased to confirm your order no. <strong>#{order_number}</strong>. A confirmation email has been sent to <strong>{email}</strong>.', 'checkoutflow' ) . '</p>', 'align' => 'center' ) );
		$add( 'overview' );
		$add( 'payment' );
		$add( 'items' );
		$add( 'customer' );
		$add( 'support' );
		$add( 'button' );
		return array( 'settings' => self::default_settings(), 'blocks' => $blocks );
	}

	/**
	 * Saved design (or the default).
	 */
	public static function design() {
		$saved = get_option( self::OPTION );
		return is_array( $saved ) && ! empty( $saved['blocks'] ) ? self::sanitize( $saved, true ) : self::default_design();
	}

	/**
	 * @param array $design  Raw design.
	 * @param bool  $trusted Stored by a user who could post unfiltered HTML.
	 */
	public static function sanitize( $design, $trusted = false ) {
		$design = is_array( $design ) ? $design : array();
		$raw_s  = array_merge( self::default_settings(), isset( $design['settings'] ) && is_array( $design['settings'] ) ? $design['settings'] : array() );
		$hex    = static function ( $v, $fallback ) {
			$c = sanitize_hex_color( (string) $v );
			return $c ? $c : $fallback;
		};
		$settings = array(
			'page_bg'    => $hex( $raw_s['page_bg'], '#f4f6f7' ),
			'card_bg'    => $hex( $raw_s['card_bg'], '#ffffff' ),
			'accent'     => $hex( $raw_s['accent'], '#1f6feb' ),
			'text_color' => $hex( $raw_s['text_color'], '#111111' ),
			'width'      => max( 480, min( 1400, (int) $raw_s['width'] ) ),
		);

		$blocks = self::sanitize_blocks( isset( $design['blocks'] ) && is_array( $design['blocks'] ) ? $design['blocks'] : array(), $trusted, true );
		return array( 'settings' => $settings, 'blocks' => $blocks );
	}

	private static function sanitize_blocks( $list, $trusted, $allow_cols ) {
		$types   = self::types();
		$options = self::block_types();
		$blocks  = array();
		foreach ( $list as $block ) {
			$type = is_array( $block ) && isset( $block['type'] ) ? $block['type'] : '';
			if ( ! isset( $types[ $type ] ) || ( 'columns' === $type && ! $allow_cols ) ) {
				continue;
			}
			if ( 'columns' === $type ) {
				$layouts = self::layouts();
				$layout  = isset( $block['layout'], $layouts[ $block['layout'] ] ) ? $block['layout'] : '50-50';
				$cols    = array();
				foreach ( array_keys( $layouts[ $layout ] ) as $c ) {
					$cols[] = self::sanitize_blocks( isset( $block['cols'][ $c ] ) && is_array( $block['cols'][ $c ] ) ? $block['cols'][ $c ] : array(), $trusted, false );
				}
				$blocks[] = array_merge( array( 'type' => 'columns', 'layout' => $layout, 'cols' => $cols ), \CheckoutFlow\Mail\Renderer::sanitize_style( $block ) );
				continue;
			}
			$b = array( 'type' => $type );
			foreach ( $types[ $type ]['props'] as $key => $default ) {
				$v = isset( $block[ $key ] ) ? $block[ $key ] : $default;
				if ( is_bool( $default ) ) {
					$b[ $key ] = ! empty( $v ) && 'false' !== $v;
				} elseif ( is_int( $default ) ) {
					$b[ $key ] = (int) $v;
				} elseif ( 'html' === $key ) {
					$b[ $key ] = ( 'html' === $type && ( $trusted || current_user_can( 'unfiltered_html' ) ) ) ? (string) $v : wp_kses_post( (string) $v );
				} elseif ( 'url' === $key || 'src' === $key ) {
					$b[ $key ] = preg_match( '/^\{[a-z_]+\}$/', (string) $v ) ? (string) $v : esc_url_raw( (string) $v );
				} elseif ( 'color' === $key ) {
					$b[ $key ] = sanitize_hex_color( (string) $v ) ? sanitize_hex_color( (string) $v ) : '';
				} elseif ( 'align' === $key ) {
					$b[ $key ] = isset( $options['_align'][ $v ] ) ? $v : $default;
				} elseif ( isset( $types[ $type ]['options'][ $key ] ) ) {
					$b[ $key ] = isset( $types[ $type ]['options'][ $key ][ $v ] ) ? $v : $default;
				} elseif ( 'email' === $key ) {
					$b[ $key ] = sanitize_email( (string) $v );
				} else {
					$b[ $key ] = sanitize_text_field( (string) $v );
				}
			}
			$blocks[] = array_merge( $b, \CheckoutFlow\Mail\Renderer::sanitize_style( $block ) );
		}
		return $blocks;
	}

	public static function save( $design ) {
		update_option( self::OPTION, self::sanitize( $design ), false );
	}

	/* ---------- rendering ---------- */

	/**
	 * Merge tags available in headings, text and links.
	 *
	 * @return array tag => description
	 */
	public static function merge_tags() {
		return array(
			'first_name'      => __( 'Customer first name', 'checkoutflow' ),
			'last_name'       => __( 'Customer last name', 'checkoutflow' ),
			'email'           => __( 'Customer email', 'checkoutflow' ),
			'phone'           => __( 'Customer phone', 'checkoutflow' ),
			'order_number'    => __( 'Order number', 'checkoutflow' ),
			'order_date'      => __( 'Order date', 'checkoutflow' ),
			'order_total'     => __( 'Order total', 'checkoutflow' ),
			'payment_method'  => __( 'Payment method', 'checkoutflow' ),
			'shipping_method' => __( 'Shipping method', 'checkoutflow' ),
			'site_name'       => __( 'Store name', 'checkoutflow' ),
			'shop_url'        => __( 'Continue-shopping link', 'checkoutflow' ),
			'account_url'     => __( 'My account link', 'checkoutflow' ),
			'track_url'       => __( 'Order tracking page link', 'checkoutflow' ),
		);
	}

	private static function tag_values( $order ) {
		$first = $order->get_billing_first_name() ? $order->get_billing_first_name() : $order->get_shipping_first_name();
		$date  = $order->get_date_created();
		return array(
			'first_name'      => $first,
			'last_name'       => $order->get_billing_last_name() ? $order->get_billing_last_name() : $order->get_shipping_last_name(),
			'email'           => $order->get_billing_email(),
			'phone'           => $order->get_billing_phone(),
			'order_number'    => $order->get_order_number(),
			'order_date'      => $date ? wc_format_datetime( $date ) : '',
			'order_total'     => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			'payment_method'  => $order->get_payment_method_title(),
			'shipping_method' => $order->get_shipping_method(),
			'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'shop_url'        => continue_shopping_url(),
			'account_url'     => wc_get_page_permalink( 'myaccount' ),
			'track_url'       => self::track_url(),
		);
	}

	/**
	 * Replace {tags} in already-escaped HTML/text. Values are escaped for the context.
	 */
	private static function merge( $html, $values, $context = 'html' ) {
		return preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) use ( $values, $context ) {
				if ( ! isset( $values[ $m[1] ] ) ) {
					return $m[0];
				}
				return 'url' === $context ? $values[ $m[1] ] : esc_html( $values[ $m[1] ] );
			},
			$html
		);
	}

	/**
	 * Render the design for an order.
	 *
	 * @param array     $design Sanitized design.
	 * @param \WC_Order $order  Order.
	 * @param bool      $canvas Builder preview: tag blocks and use placeholders for gateway output.
	 * @return string
	 */
	public static function render( $design, $order, $canvas = false ) {
		$values = self::tag_values( $order );
		$flat   = self::flatten( $design['blocks'] );
		$types  = wp_list_pluck( $flat, 'type' );

		self::$cards_show_payment = (bool) array_filter(
			$flat,
			static function ( $b ) {
				return 'overview' === $b['type'] && ! empty( $b['show_payment'] );
			}
		);
		$out = self::render_blocks( $design['blocks'], $order, $values, $canvas, '' );
		// Payment instructions must never be lost (e.g. a crypto payment box): if the
		// layout has no Payment block, show them first.
		if ( ! in_array( 'payment', $types, true ) && ! $canvas ) {
			$pay = self::payment_html( $order, '' );
			if ( '' !== $pay ) {
				$out = '<div class="cf-tyb cf-tyb--payment">' . $pay . '</div>' . $out;
			}
		}
		return '<div class="cf-tyb-page">' . $out . '</div>';
	}

	/**
	 * All blocks, including those inside columns.
	 */
	private static function flatten( $blocks ) {
		$out = array();
		foreach ( $blocks as $b ) {
			$out[] = $b;
			if ( 'columns' === $b['type'] ) {
				foreach ( (array) $b['cols'] as $col ) {
					$out = array_merge( $out, self::flatten( $col ) );
				}
			}
		}
		return $out;
	}

	private static function render_blocks( $blocks, $order, $values, $canvas, $prefix ) {
		$types = self::types();
		$out   = '';
		foreach ( $blocks as $i => $b ) {
			$path = $prefix . $i;
			if ( 'columns' === $b['type'] ) {
				$html = self::columns_html( $b, $order, $values, $canvas, $path );
			} else {
				$html = self::block( $b, $order, $values, $canvas );
			}
			if ( '' === $html ) {
				if ( ! $canvas ) {
					continue;
				}
				$html = '<div class="cf-tyb-empty">' . esc_html( $types[ $b['type'] ]['label'] ) . '</div>';
			}
			$style = ( ! empty( $b['_bg'] ) ? 'background:' . $b['_bg'] . ';' : '' )
				. ( isset( $b['_pt'] ) ? 'padding-top:' . (int) $b['_pt'] . 'px;' : '' )
				. ( isset( $b['_pb'] ) ? 'padding-bottom:' . (int) $b['_pb'] . 'px;' : '' );
			$out .= '<div class="cf-tyb cf-tyb--' . esc_attr( $b['type'] ) . '"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . ( $canvas ? ' data-cfb="' . esc_attr( $path ) . '"' : '' ) . '>' . $html . '</div>';
		}
		return $out;
	}

	private static function columns_html( $b, $order, $values, $canvas, $path ) {
		$layouts = self::layouts();
		$widths  = isset( $layouts[ $b['layout'] ] ) ? $layouts[ $b['layout'] ] : $layouts['50-50'];
		$html    = '';
		foreach ( $widths as $c => $w ) {
			$inner = self::render_blocks( isset( $b['cols'][ $c ] ) ? $b['cols'][ $c ] : array(), $order, $values, $canvas, $path . '.' . $c . '.' );
			if ( '' === $inner && $canvas ) {
				$inner = '<div class="cf-tyb-empty">' . esc_html__( 'Drop blocks here', 'checkoutflow' ) . '</div>';
			}
			$html .= '<div class="cf-tyb-col" style="flex:' . esc_attr( $w ) . ' 1 0"' . ( $canvas ? ' data-cfb-col="' . esc_attr( $path . '.' . $c ) . '"' : '' ) . '>' . $inner . '</div>';
		}
		return '<div class="cf-tyb-cols">' . $html . '</div>';
	}

	public static function inline_css( $design ) {
		$s = $design['settings'];
		return 'body.cf-thankyou-page,.cf-tyb-preview{--cf-ty-page-bg:' . $s['page_bg'] . ';--cf-ty-card-bg:' . $s['card_bg'] . ';--cf-ty-accent:' . $s['accent'] . ';--cf-ty-text:' . $s['text_color'] . ';--cf-ty-width:' . (int) $s['width'] . 'px;}' . design_css();
	}

	private static function block( $b, $order, $values, $canvas ) {
		switch ( $b['type'] ) {
			case 'heading':
				$style = 'font-size:' . max( 14, min( 64, (int) $b['size'] ) ) . 'px;text-align:' . $b['align'] . ';' . ( $b['color'] ? 'color:' . $b['color'] . ';' : '' );
				if ( $canvas ) {
					return '<h1 class="cf-tyb-heading" data-cfb-edit="text" style="' . esc_attr( $style ) . '">' . esc_html( $b['text'] ) . '</h1>';
				}
				return '<h1 class="cf-tyb-heading" style="' . esc_attr( $style ) . '">' . self::merge( esc_html( $b['text'] ), $values ) . '</h1>';

			case 'text':
				if ( $canvas ) {
					return '<div class="cf-tyb-text" data-cfb-edit="html" style="text-align:' . esc_attr( $b['align'] ) . '">' . wp_kses_post( $b['html'] ) . '</div>';
				}
				return '<div class="cf-tyb-text" style="text-align:' . esc_attr( $b['align'] ) . '">' . self::merge( wp_kses_post( $b['html'] ), $values ) . '</div>';

			case 'overview':
				return self::overview_html( $order, $b );

			case 'items':
				return self::items_html( $order, $b );

			case 'payment':
				if ( $canvas ) {
					return '<div class="cf-tyb-card cf-tyb-placeholder">' . esc_html__( 'Payment instructions from the customer\'s payment method appear here (e.g. the crypto payment box or bank details).', 'checkoutflow' ) . '</div>';
				}
				return self::payment_html( $order, $b['title'] );

			case 'customer':
				return self::customer_html( $order, $b );

			case 'support':
				if ( ! $b['email'] && ! $b['phone'] ) {
					return '';
				}
				$html = '<div class="cf-tyb-card cf-tyb-support-bar">';
				if ( $b['title'] ) {
					$html .= '<strong class="cf-tyb-support-title">' . esc_html( $b['title'] ) . '</strong>';
				}
				if ( $b['email'] ) {
					$html .= '<a class="cf-tyb-support-item" href="mailto:' . esc_attr( $b['email'] ) . '"><svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/></svg>' . esc_html( $b['email'] ) . '</a>';
				}
				if ( $b['phone'] ) {
					$html .= '<a class="cf-tyb-support-item" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $b['phone'] ) ) . '"><svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1Z"/></svg>' . esc_html( $b['phone'] ) . '</a>';
				}
				return $html . '</div>';

			case 'button':
				$url = self::merge( $b['url'], $values, 'url' );
				if ( ! $b['text'] || ! $url ) {
					return '';
				}
				$style = $b['color'] ? 'background:' . $b['color'] . ';border-color:' . $b['color'] . ';' : '';
				return '<p class="cf-tyb-button-wrap" style="text-align:' . esc_attr( $b['align'] ) . '"><a class="cf-tyb-button" style="' . esc_attr( $style ) . '" href="' . esc_url( $url ) . '">' . self::merge( esc_html( $b['text'] ), $values ) . '</a></p>';

			case 'image':
				if ( ! $b['src'] ) {
					return $canvas ? '<div class="cf-tyb-empty">' . esc_html__( 'Image – set a URL in the block settings', 'checkoutflow' ) . '</div>' : '';
				}
				$img = '<img src="' . esc_url( $b['src'] ) . '" alt="' . esc_attr( $b['alt'] ) . '" style="width:' . max( 10, min( 100, (int) $b['width'] ) ) . '%">';
				if ( $b['url'] ) {
					$img = '<a href="' . esc_url( self::merge( $b['url'], $values, 'url' ) ) . '">' . $img . '</a>';
				}
				return '<div class="cf-tyb-image" style="text-align:' . esc_attr( $b['align'] ) . '">' . $img . '</div>';

			case 'divider':
				return '<hr class="cf-tyb-divider"' . ( $b['color'] ? ' style="border-color:' . esc_attr( $b['color'] ) . '"' : '' ) . '>';

			case 'spacer':
				return '<div style="height:' . max( 4, min( 200, (int) $b['height'] ) ) . 'px" aria-hidden="true"></div>';

			case 'html':
				return self::merge( (string) $b['html'], $values );
		}
		return '';
	}

	private static function payment_html( $order, $title ) {
		ob_start();
		do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
		$html = trim( (string) ob_get_clean() );
		if ( '' === $html ) {
			return '';
		}
		return '<div class="cf-tyb-card cf-tyb-gateway">' . ( $title ? '<h2 class="cf-tyb-h">' . esc_html( $title ) . '</h2>' : '' ) . $html . '</div>';
	}

	private static function overview_html( $order, $b ) {
		$cards = array( array( __( 'Order number', 'checkoutflow' ), esc_html( $order->get_order_number() ) ) );
		if ( $b['show_date'] && $order->get_date_created() ) {
			$cards[] = array( __( 'Date', 'checkoutflow' ), esc_html( wc_format_datetime( $order->get_date_created() ) ) );
		}
		if ( $b['show_email'] && $order->get_billing_email() ) {
			$cards[] = array( __( 'Email', 'checkoutflow' ), esc_html( $order->get_billing_email() ) );
		}
		if ( $b['show_total'] ) {
			$cards[] = array( __( 'Total', 'checkoutflow' ), wp_kses_post( $order->get_formatted_order_total() ) );
		}
		if ( $b['show_payment'] && $order->get_payment_method_title() ) {
			$cards[] = array( __( 'Payment method', 'checkoutflow' ), wp_kses_post( $order->get_payment_method_title() ) );
		}
		$html = '<ul class="cf-tyb-overview">';
		foreach ( $cards as $c ) {
			$html .= '<li><span>' . esc_html( $c[0] ) . '</span><strong>' . $c[1] . '</strong></li>';
		}
		return $html . '</ul>';
	}

	private static function items_html( $order, $b ) {
		ob_start();
		?>
		<div class="cf-tyb-card cf-tyb-items-card">
			<?php if ( $b['title'] ) : ?>
				<h2 class="cf-tyb-h"><?php echo esc_html( $b['title'] ); ?></h2>
			<?php endif; ?>
			<ul class="cf-tyb-items">
				<?php
				foreach ( $order->get_items() as $item_id => $item ) :
					if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
						continue;
					}
					$product = $item->get_product();
					$qty     = $item->get_quantity();
					?>
					<li class="cf-tyb-item">
						<?php if ( $b['show_images'] ) : ?>
							<span class="cf-tyb-thumb">
								<?php echo $product ? wp_kses_post( $product->get_image( 'woocommerce_gallery_thumbnail' ) ) : ''; ?>
								<span class="cf-tyb-qty"><?php echo esc_html( $qty ); ?></span>
							</span>
						<?php endif; ?>
						<span class="cf-tyb-name">
							<?php echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) ); ?>
							<?php if ( ! $b['show_images'] ) : ?>
								<span class="cf-tyb-times">&times; <?php echo esc_html( $qty ); ?></span>
							<?php endif; ?>
							<?php
							do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );
							wc_display_item_meta( $item );
							do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
							?>
						</span>
						<span class="cf-tyb-price"><?php echo wp_kses_post( self::line_total( $order, $item, $product ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			if ( $b['show_totals'] ) :
				$totals = $order->get_order_item_totals();
				if ( self::$cards_show_payment ) {
					unset( $totals['payment_method'] );
				}
				?>
				<dl class="cf-tyb-totals">
					<?php foreach ( $totals as $key => $total ) : ?>
						<div class="cf-tyb-total-row is-<?php echo esc_attr( sanitize_html_class( $key ) ); ?>">
							<dt><?php echo esc_html( rtrim( wp_strip_all_tags( $total['label'] ), ':' ) ); ?></dt>
							<dd><?php echo wp_kses_post( $total['value'] ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php if ( $order->get_customer_note() ) : ?>
				<p class="cf-tyb-note"><strong><?php esc_html_e( 'Note:', 'woocommerce' ); ?></strong> <?php echo wp_kses( nl2br( wptexturize( $order->get_customer_note() ) ), array( 'br' => array() ) ); ?></p>
			<?php endif; ?>
			<?php do_action( 'woocommerce_order_details_after_order_table', $order ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Line total, with the list price struck through when the line was discounted.
	 */
	private static function line_total( $order, $item, $product ) {
		$html = $order->get_formatted_line_subtotal( $item );
		if ( ! $product ) {
			return $html;
		}
		$list = (float) $product->get_regular_price() * $item->get_quantity();
		if ( $list > 0 && $list - (float) $item->get_subtotal() > 0.009 ) {
			$was = 'incl' === get_option( 'woocommerce_tax_display_cart' ) ? wc_get_price_including_tax( $product, array( 'qty' => $item->get_quantity(), 'price' => $product->get_regular_price() ) ) : $list;
			return '<del aria-hidden="true">' . wc_price( $was, array( 'currency' => $order->get_currency() ) ) . '</del> <ins>' . $html . '</ins>';
		}
		return $html;
	}

	private static function customer_html( $order, $b ) {
		$shipping = $order->needs_shipping_address() ? $order->get_formatted_shipping_address() : '';
		$billing  = $order->get_formatted_billing_address();
		$same     = $shipping && wp_strip_all_tags( $shipping ) === wp_strip_all_tags( $billing );
		$show_bil = $billing && ( 'always' === $b['billing'] || ( 'different' === $b['billing'] && ( ! $same || ! $shipping ) ) );
		$fields   = array();
		if ( $b['show_contact'] ) {
			if ( $order->get_billing_email() ) {
				$fields[] = array( __( 'Email', 'checkoutflow' ), esc_html( $order->get_billing_email() ), 'mail' );
			}
			$phone = $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone();
			if ( $phone ) {
				$fields[] = array( __( 'Phone', 'checkoutflow' ), esc_html( $phone ), 'phone' );
			}
		}
		if ( $shipping ) {
			$fields[] = array( __( 'Shipping address', 'checkoutflow' ), '<address>' . wp_kses_post( $shipping ) . '</address>', 'pin' );
		}
		if ( $show_bil ) {
			$fields[] = array( __( 'Billing address', 'checkoutflow' ), '<address>' . wp_kses_post( $billing ) . '</address>', 'card' );
		}
		if ( $order->get_shipping_method() ) {
			$fields[] = array( __( 'Shipping method', 'checkoutflow' ), esc_html( $order->get_shipping_method() ), 'truck' );
		}
		if ( ! $fields ) {
			return '';
		}
		$icons = array(
			'mail' => '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/></svg>',
			'phone' => '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2Z"/></svg>',
			'pin' => '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>',
			'card' => '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19"/></svg>',
			'truck' => '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M2 6h11v10H2zM13 10h4l4 4v2h-8z"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>',
		);
		ob_start();
		?>
		<div class="cf-tyb-card cf-tyb-customer is-<?php echo esc_attr( isset( $b['align'] ) ? $b['align'] : 'center' ); ?>">
			<?php if ( $b['title'] ) : ?>
				<h2 class="cf-tyb-h"><?php echo esc_html( $b['title'] ); ?></h2>
			<?php endif; ?>
			<dl class="cf-tyb-fields">
				<?php foreach ( $fields as $f ) : ?>
					<div class="cf-tyb-field">
						<span class="cf-tyb-field-icon"><?php echo $icons[ $f[2] ]; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></span>
						<dt><?php echo esc_html( $f[0] ); ?></dt>
						<dd><?php echo $f[1]; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<?php do_action( 'woocommerce_order_details_after_customer_details', $order ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The store's order-tracking page, else the customer's account orders.
	 */
	public static function track_url() {
		foreach ( array( 'track-order', 'track-your-order', 'order-tracking' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				return get_permalink( $page );
			}
		}
		return wc_get_account_endpoint_url( 'orders' );
	}

	/* ---------- builder preview ---------- */

	/**
	 * An order to preview with: the newest real order, else an unsaved sample.
	 *
	 * @return \WC_Order
	 */
	public static function preview_order() {
		$orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) );
		if ( $orders ) {
			return $orders[0];
		}
		$order = new \WC_Order();
		$order->set_billing_first_name( 'Jordan' );
		$order->set_billing_last_name( 'Copeland' );
		$order->set_billing_email( 'jordan@example.com' );
		$order->set_billing_phone( '555-0100' );
		$order->set_billing_address_1( '100 Main St' );
		$order->set_billing_city( 'Marietta' );
		$order->set_billing_state( 'GA' );
		$order->set_billing_postcode( '30064' );
		$order->set_billing_country( 'US' );
		$products = wc_get_products( array( 'limit' => 2, 'status' => 'publish' ) );
		foreach ( $products as $p ) {
			$order->add_product( $p, 1 );
		}
		$order->set_date_created( time() );
		$order->calculate_totals( false );
		return $order;
	}

	/**
	 * Full HTML document for the builder's preview iframe.
	 */
	public static function preview_document( $design ) {
		$order = self::preview_order();
		$css   = file_get_contents( CHECKOUTFLOW_DIR . 'assets/css/thankyou.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--cf-ty-page-bg)}'
			. $css . self::inline_css( $design ) . '</style></head><body class="cf-thankyou-page cf-tyb-preview"><div class="cf-ty">'
			. self::render( $design, $order, true ) . '</div></body></html>';
	}
}
