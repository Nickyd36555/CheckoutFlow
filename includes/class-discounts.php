<?php
/**
 * Dynamic pricing & discounts (lean replacement for Addify Product Dynamic Pricing).
 *
 * Rule kinds:
 *  - product      per cart line: the line's own quantity picks a tier and the unit price
 *                 is changed (e.g. "20% off everything", "buy 10+ of a product, pay $X each").
 *  - cart_qty     all matching lines' quantities are added up, the tier's discount is taken
 *                 off those lines' subtotal as a negative fee (e.g. bulk discount).
 *  - cart_amount  same, but the tier is chosen by the matching lines' subtotal.
 *
 * Cost when idle: rules are one autoloaded option. Hooks are only added for rule kinds
 * that are enabled, and only run while WooCommerce calculates cart totals or renders a
 * price. Product matching reads the product's own IDs/terms (no product queries).
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Discounts {

	const OPTION = 'checkoutflow_discount_rules';

	const ADJUSTMENTS = array( 'percent_decrease', 'fixed_decrease', 'fixed_price', 'percent_increase', 'fixed_increase' );

	/** @var array|null */
	private static $rules = null;

	/** @var array Base unit price per cart line product object, so repeated totals don't compound. */
	private static $base = array();

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'import_addify' ) );
			add_action( 'admin_notices', array( __CLASS__, 'addify_notice' ) );
		}
		// Never discount twice: stay dormant while Addify is still active.
		if ( self::addify_active() ) {
			return;
		}
		if ( self::active( 'product' ) ) {
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_product_rules' ), 90 );
			add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 100, 2 );
		}
		if ( self::active( array( 'cart_qty', 'cart_amount' ) ) ) {
			add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_cart_rules' ), 90 );
		}
	}

	public static function addify_active() {
		return class_exists( 'AF_Discount_Main' );
	}

	/* ---------- storage ---------- */

	/**
	 * @return array All rules, in order.
	 */
	public static function rules() {
		if ( null === self::$rules ) {
			$rules       = get_option( self::OPTION, array() );
			self::$rules = is_array( $rules ) ? array_values( $rules ) : array();
		}
		return self::$rules;
	}

	public static function save( $rules ) {
		self::$rules = array_values( $rules );
		update_option( self::OPTION, self::$rules, true );
	}

	public static function blank_rule() {
		return array(
			'id'         => wp_generate_uuid4(),
			'title'      => '',
			'label'      => '',
			'enabled'    => false,
			'kind'       => 'cart_qty',
			'scope'      => 'all',
			'products'   => array(),
			'categories' => array(),
			'brands'     => array(),
			'tiers'      => array(),
			'start'      => '',
			'end'        => '',
			'days'       => array(),
			'note'       => '',
		);
	}

	/**
	 * Sanitize one rule (from the admin form or an import).
	 */
	public static function sanitize_rule( $raw ) {
		$r    = array_merge( self::blank_rule(), is_array( $raw ) ? $raw : array() );
		$ints = static function ( $v ) {
			$v = is_array( $v ) ? $v : preg_split( '/[\s,]+/', (string) $v );
			return array_values( array_unique( array_filter( array_map( 'absint', $v ) ) ) );
		};
		$date = static function ( $v ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '';
		};

		$tiers = array();
		foreach ( (array) $r['tiers'] as $t ) {
			if ( ! is_array( $t ) || ! isset( $t['value'] ) || '' === (string) $t['value'] ) {
				continue;
			}
			$tiers[] = array(
				'role'  => sanitize_key( isset( $t['role'] ) && '' !== $t['role'] ? $t['role'] : 'all' ),
				'min'   => max( 0, (float) ( isset( $t['min'] ) && '' !== (string) $t['min'] ? $t['min'] : 1 ) ),
				'max'   => isset( $t['max'] ) && '' !== (string) $t['max'] ? max( 0, (float) $t['max'] ) : '',
				'type'  => isset( $t['type'] ) && in_array( $t['type'], self::ADJUSTMENTS, true ) ? $t['type'] : 'percent_decrease',
				'value' => max( 0, (float) $t['value'] ),
			);
		}

		$days = array_values( array_intersect( (array) $r['days'], array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) ) );

		return array(
			'id'         => preg_match( '/^[a-f0-9-]{8,40}$/i', (string) $r['id'] ) ? (string) $r['id'] : wp_generate_uuid4(),
			'title'      => sanitize_text_field( (string) $r['title'] ),
			'label'      => sanitize_text_field( (string) $r['label'] ),
			'enabled'    => ! empty( $r['enabled'] ),
			'kind'       => in_array( $r['kind'], array( 'product', 'cart_qty', 'cart_amount' ), true ) ? $r['kind'] : 'cart_qty',
			'scope'      => 'specific' === $r['scope'] ? 'specific' : 'all',
			'products'   => $ints( $r['products'] ),
			'categories' => $ints( $r['categories'] ),
			'brands'     => $ints( $r['brands'] ),
			'tiers'      => $tiers,
			'start'      => $date( $r['start'] ),
			'end'        => $date( $r['end'] ),
			'days'       => $days,
			'note'       => sanitize_text_field( (string) $r['note'] ),
		);
	}

	/* ---------- matching ---------- */

	/**
	 * Enabled rules of the given kind(s) that are live today (site timezone), in order.
	 *
	 * @param string|string[] $kinds Kind or kinds.
	 */
	public static function active( $kinds ) {
		$kinds = (array) $kinds;
		$today = current_time( 'Y-m-d' );
		$day   = wp_date( 'l' );
		$out   = array();
		foreach ( self::rules() as $r ) {
			if ( empty( $r['enabled'] ) || ! in_array( $r['kind'], $kinds, true ) || ! $r['tiers'] ) {
				continue;
			}
			if ( ( $r['start'] && $today < $r['start'] ) || ( $r['end'] && $today > $r['end'] ) ) {
				continue;
			}
			if ( $r['days'] && ! in_array( $day, $r['days'], true ) ) {
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Does a rule cover this product (variations use their parent's terms)?
	 *
	 * @param array       $rule    Rule.
	 * @param \WC_Product $product Product.
	 */
	public static function matches( $rule, $product ) {
		if ( 'all' === $rule['scope'] || ( ! $rule['products'] && ! $rule['categories'] && ! $rule['brands'] ) ) {
			return true;
		}
		$ids = array_filter( array( $product->get_id(), $product->get_parent_id() ) );
		if ( array_intersect( $ids, $rule['products'] ) ) {
			return true;
		}
		$main = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $main ) {
			return false;
		}
		if ( $rule['categories'] && array_intersect( array_map( 'intval', $main->get_category_ids() ), $rule['categories'] ) ) {
			return true;
		}
		if ( $rule['brands'] && taxonomy_exists( 'product_brand' ) ) {
			$brands = wp_get_post_terms( $main->get_id(), 'product_brand', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $brands ) && array_intersect( array_map( 'intval', $brands ), $rule['brands'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function role_matches( $role ) {
		if ( 'all' === $role ) {
			return true;
		}
		if ( 'guest' === $role ) {
			return ! is_user_logged_in();
		}
		return is_user_logged_in() && in_array( $role, (array) wp_get_current_user()->roles, true );
	}

	/**
	 * First tier (in order) for the current shopper that the measure falls into.
	 */
	public static function tier( $rule, $measure ) {
		foreach ( $rule['tiers'] as $t ) {
			if ( self::role_matches( $t['role'] ) && $measure >= $t['min'] && ( '' === $t['max'] || $measure <= $t['max'] ) ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * New unit price for a product tier.
	 */
	public static function adjust( $price, $tier ) {
		$price = (float) $price;
		$v     = (float) $tier['value'];
		switch ( $tier['type'] ) {
			case 'percent_decrease':
				$price -= $price * $v / 100;
				break;
			case 'fixed_decrease':
				$price -= $v;
				break;
			case 'fixed_price':
				$price = $v;
				break;
			case 'percent_increase':
				$price += $price * $v / 100;
				break;
			case 'fixed_increase':
				$price += $v;
				break;
		}
		return max( 0, $price );
	}

	/* ---------- product rules ---------- */

	/**
	 * @param \WC_Cart $cart Cart.
	 */
	public static function apply_product_rules( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$rules = self::active( 'product' );
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'];
			$oid     = spl_object_id( $product );
			if ( ! isset( self::$base[ $oid ] ) ) {
				self::$base[ $oid ] = (float) $product->get_price( 'edit' );
			}
			$price = self::$base[ $oid ];
			foreach ( $rules as $rule ) {
				if ( ! self::matches( $rule, $product ) ) {
					continue;
				}
				$tier = self::tier( $rule, (float) $item['quantity'] );
				if ( $tier ) {
					$price = self::adjust( self::$base[ $oid ], $tier );
					break; // First applicable rule wins.
				}
			}
			$product->set_price( $price );
		}
	}

	/**
	 * Show the discounted price (quantity 1) on shop and product pages.
	 */
	public static function price_html( $html, $product ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || '' === $product->get_price() ) {
			return $html;
		}
		$tier = null;
		foreach ( self::active( 'product' ) as $rule ) {
			if ( self::matches( $rule, $product ) ) {
				$tier = self::tier( $rule, 1 );
				if ( $tier ) {
					break;
				}
			}
		}
		if ( ! $tier ) {
			return $html;
		}
		$strike = in_array( $tier['type'], array( 'percent_decrease', 'fixed_decrease', 'fixed_price' ), true );

		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( true );
			if ( empty( $prices['price'] ) ) {
				return $html;
			}
			$new = array_map(
				static function ( $p ) use ( $tier ) {
					return self::adjust( $p, $tier );
				},
				$prices['price']
			);
			$min = min( $new );
			$max = max( $new );
			$now = $min !== $max ? wc_format_price_range( $min, $max ) : wc_price( $min );
			return $now . $product->get_price_suffix();
		}

		$was = wc_get_price_to_display( $product );
		$new = wc_get_price_to_display( $product, array( 'price' => self::adjust( $product->get_price(), $tier ) ) );
		if ( $strike && $new < $was ) {
			return wc_format_sale_price( $was, $new ) . $product->get_price_suffix();
		}
		return wc_price( $new ) . $product->get_price_suffix();
	}

	/* ---------- cart rules ---------- */

	/**
	 * @param \WC_Cart $cart Cart.
	 */
	public static function apply_cart_rules( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		foreach ( self::active( array( 'cart_qty', 'cart_amount' ) ) as $rule ) {
			$qty      = 0;
			$subtotal = 0.0;
			foreach ( $cart->get_cart() as $item ) {
				if ( self::matches( $rule, $item['data'] ) ) {
					$qty      += (float) $item['quantity'];
					$subtotal += (float) $item['data']->get_price() * (float) $item['quantity'];
				}
			}
			if ( $subtotal <= 0 ) {
				continue;
			}
			$tier = self::tier( $rule, 'cart_amount' === $rule['kind'] ? $subtotal : $qty );
			if ( ! $tier ) {
				continue;
			}
			$amount = self::cart_amount( $tier, $subtotal );
			if ( 0.0 === (float) $amount ) {
				continue;
			}
			$label = '' !== $rule['label'] ? $rule['label'] : ( '' !== $rule['title'] ? $rule['title'] : __( 'Discount', 'checkoutflow' ) );
			$cart->add_fee( $label, wc_format_decimal( $amount, wc_get_price_decimals() ), $amount > 0 && 'yes' === get_option( 'woocommerce_calc_taxes' ) );
			break; // First applicable cart rule wins.
		}
	}

	/**
	 * Signed fee for a cart tier (negative = discount).
	 */
	private static function cart_amount( $tier, $subtotal ) {
		$v = (float) $tier['value'];
		switch ( $tier['type'] ) {
			case 'percent_decrease':
				return -min( $subtotal, $subtotal * $v / 100 );
			case 'fixed_decrease':
				return -min( $subtotal, $v );
			case 'fixed_price':
				return -max( 0, $subtotal - $v );
			case 'percent_increase':
				return $subtotal * $v / 100;
			case 'fixed_increase':
				return $v;
		}
		return 0;
	}

	/* ---------- Addify import ---------- */

	/**
	 * One-time import of Addify rules (kept even after Addify is deleted). Addify tiers
	 * for individual customers and free-gift rules aren't supported; such rules are
	 * imported disabled with a note.
	 */
	public static function import_addify() {
		if ( get_option( 'checkoutflow_addify_imported' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		update_option( 'checkoutflow_addify_imported', 1, false );

		$posts = get_posts(
			array(
				'post_type'      => array( 'af_disc_p_rules', 'af_dis_cart_rule' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);
		if ( ! $posts || self::rules() ) {
			return;
		}

		$map   = array(
			'fixed_percent_decrease' => 'percent_decrease',
			'fixed_price_decrease'   => 'fixed_decrease',
			'fixed_price'            => 'fixed_price',
			'fixed_percent_increase' => 'percent_increase',
			'fixed_price_increase'   => 'fixed_increase',
		);
		$rules = array();
		foreach ( $posts as $post ) {
			$meta = static function ( $key ) use ( $post ) {
				return get_post_meta( $post->ID, $key, true );
			};
			$is_cart = 'af_dis_cart_rule' === $post->post_type;
			$type    = (string) $meta( 'addf_drpc_discount_type_choice' );
			$notes   = array();

			if ( $is_cart ) {
				$kind = 'dynamic_disc_on_amount' === $type ? 'cart_amount' : 'cart_qty';
				$cols = array( 'addf_disc_rpc_cart_select_user_role', 'addf_drpc_user_role_disc_choice', 'addf_disc_rpc_disc_val_tbl_user_role', 'addf_disc_rpc_min_qty_tbl_user_role', 'addf_disc_rpc_max_qty_tbl_user_role' );
				if ( in_array( $type, array( 'gift_on_qty', 'gift_on_price' ), true ) ) {
					$notes[] = __( 'Free-gift rule: not supported, imported disabled.', 'checkoutflow' );
				}
				$has_customer_rows = (bool) array_filter( (array) $meta( 'addf_disc_rpc_cart_select_customer' ) );
			} else {
				$kind = 'product';
				$cols = array( 'addf_disc_rpc_roles_select', 'addf_drpc_discount_amount_choice', 'addf_disc_rpc_disc_val_tbl', 'addf_disc_rpc_min_qty_tbl', 'addf_disc_rpc_max_qty_tbl' );
				if ( 'conditional' === $type ) {
					$notes[] = __( 'Free-gift rule: not supported, imported disabled.', 'checkoutflow' );
				}
				$has_customer_rows = (bool) array_filter( (array) $meta( 'addf_disc_rpc_select_customer' ) );
			}
			if ( $has_customer_rows ) {
				$notes[] = __( 'Tiers for individual customers were not imported.', 'checkoutflow' );
			}

			list( $roles, $types, $values, $mins, $maxs ) = array_map(
				static function ( $k ) use ( $meta ) {
					return (array) $meta( $k );
				},
				$cols
			);
			$tiers = array();
			foreach ( $roles as $k => $role ) {
				if ( ! isset( $values[ $k ] ) || '' === (string) $values[ $k ] || ( ( ! isset( $mins[ $k ] ) || '' === (string) $mins[ $k ] ) && ( ! isset( $maxs[ $k ] ) || '' === (string) $maxs[ $k ] ) ) ) {
					continue;
				}
				$tiers[] = array(
					'role'  => $role ? $role : 'all',
					'type'  => isset( $types[ $k ], $map[ $types[ $k ] ] ) ? $map[ $types[ $k ] ] : 'percent_decrease',
					'value' => $values[ $k ],
					'min'   => isset( $mins[ $k ] ) ? $mins[ $k ] : 1,
					'max'   => isset( $maxs[ $k ] ) ? $maxs[ $k ] : '',
				);
			}

			$rules[] = self::sanitize_rule(
				array(
					'title'      => $post->post_title,
					'enabled'    => 'publish' === $post->post_status && ! array_filter( $notes, static function ( $n ) { return false !== strpos( $n, 'gift' ); } ),
					'kind'       => $kind,
					'scope'      => 'specific' === $meta( 'addf_disc_rpc_product_selection_op' ) ? 'specific' : 'all',
					'products'   => (array) $meta( 'addf_disc_rpc_products' ),
					'categories' => (array) $meta( 'addf_disc_rpc_categories' ),
					'brands'     => (array) $meta( 'addf_disc_rpc_brands' ),
					'tiers'      => $tiers,
					'start'      => $meta( 'addf_disc_rpc_start_time' ),
					'end'        => $meta( 'addf_disc_rpc_end_time' ),
					'days'       => 'specific' === $meta( 'addf_disc_rpc_days_radio' ) ? (array) $meta( 'addf_disc_week_days_arr' ) : array(),
					'note'       => implode( ' ', array_merge( array( __( 'Imported from Addify.', 'checkoutflow' ) ), $notes ) ),
				)
			);
		}
		self::save( $rules );
	}

	public static function addify_notice() {
		if ( ! self::addify_active() || ! self::rules() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( 'plugins' !== $screen->id && false === strpos( $screen->id, 'checkoutflow' ) ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'CheckoutFlow imported your Addify discount rules. They take over automatically once you deactivate "Product Dynamic Pricing and Discounts" (until then Addify keeps applying them, so customers are never discounted twice).', 'checkoutflow' )
		);
	}
}
