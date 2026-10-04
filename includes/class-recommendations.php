<?php
/**
 * Smart cart recommendations:
 *  - Pairing rules ("cart has BPC-157 but no TB-500 → suggest TB-500"), editable in
 *    Settings → Side Cart. Matched against product names, so they follow the catalog.
 *  - "Bought together" data learned from the store's own orders (rebuilt daily from
 *    WooCommerce's order lookup table – one grouped SQL query).
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Recommendations {

	const PAIRS_OPTION = 'checkoutflow_copurchase';

	/**
	 * Default pairing rules (trigger => suggestion), for products commonly bought together.
	 */
	public static function default_rules() {
		return implode(
			"\n",
			array(
				'BPC => TB-500|TB4|TB-4',
				'TB-500|TB4 => BPC',
				'CJC => Ipamorelin',
				'Ipamorelin => CJC',
				'Sermorelin => Ipamorelin',
				'GHRP-2 => CJC',
				'Hexarelin => CJC',
				'Tesa => Ipamorelin',
				'GHK-Cu => BPC',
				'KPV => BPC',
				'Cartalax => BPC',
				'Selank => Semax',
				'Semax => Selank',
				'Epitalon => Pinealon',
				'Pinealon => Epitalon',
				'DSIP => Epitalon',
				'MOTS-C => 5-Amino-1MQ',
				'5-Amino-1MQ => NAD+',
				'NAD+ => MOTS-C',
				'Glutathione => NAD+',
				'AOD-9604 => MOTS-C',
				'Frag 176-191 => AOD-9604',
				'Cagrilintide => S',
				'T => MOTS-C',
				'R => MOTS-C',
				'S => Cagrilintide',
				'PT-141 => Kisspeptin',
				'Kisspeptin => PT-141',
				'Melanotan => PT-141',
				'Oxytocin => PT-141',
				'Thymosin Alpha-1 => Thymulin',
				'LL-37 => Thymosin Alpha-1',
			)
		);
	}

	/**
	 * Parsed rules: list of [ trigger, suggestion ].
	 */
	public static function rules() {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) Settings::get( 'cart_pairings' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '=>', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$out[] = $parts;
			}
		}
		return $out;
	}

	/**
	 * Does a product name contain the keyword as a whole token? ("T" matches "T 60MG",
	 * not "TB-500" or "Tesa"; "BPC" matches "BPC-157" and "Blend – … BPC (5MG)".)
	 */
	public static function name_has( $name, $keyword ) {
		foreach ( array_filter( array_map( 'trim', explode( '|', $keyword ) ), 'strlen' ) as $kw ) {
			if ( preg_match( '/(?<![A-Za-z0-9])' . preg_quote( $kw, '/' ) . '(?![A-Za-z0-9])/i', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Strength in a product name ("BPC-157 10MG" → "10mg"), for suggesting a matching size.
	 */
	private static function strength( $name ) {
		return preg_match_all( '/(\d+(?:\.\d+)?)\s*(mg|mcg|g)\b/i', $name, $m ) ? strtolower( end( $m[1] ) . end( $m[2] ) ) : '';
	}

	/**
	 * Published, purchasable products: id => [ name, sales ] (cached).
	 */
	private static function catalog() {
		$cat = get_transient( 'cf_reco_catalog' );
		if ( false === $cat ) {
			$cat = array();
			foreach ( wc_get_products( array( 'limit' => 500, 'status' => 'publish', 'visibility' => 'catalog', 'orderby' => 'popularity', 'order' => 'DESC' ) ) as $p ) {
				if ( $p->is_purchasable() && $p->is_in_stock() ) {
					$cat[ $p->get_id() ] = array( wp_specialchars_decode( $p->get_name(), ENT_QUOTES ), (int) $p->get_total_sales() );
				}
			}
			set_transient( 'cf_reco_catalog', $cat, 6 * HOUR_IN_SECONDS );
		}
		return $cat;
	}

	public static function flush_catalog() {
		delete_transient( 'cf_reco_catalog' );
	}

	/**
	 * Product IDs suggested by the pairing rules for this cart, best match first.
	 *
	 * @param string[] $cart_names Names of the products in the cart.
	 * @return int[]
	 */
	public static function pairing_ids( $cart_names ) {
		$catalog = self::catalog();
		$out     = array();
		foreach ( self::rules() as $rule ) {
			list( $trigger, $suggest ) = $rule;
			$trigger_item = null;
			$has_suggest  = false;
			foreach ( $cart_names as $n ) {
				if ( null === $trigger_item && self::name_has( $n, $trigger ) ) {
					$trigger_item = $n;
				}
				$has_suggest = $has_suggest || self::name_has( $n, $suggest );
			}
			if ( null === $trigger_item || $has_suggest ) {
				continue;
			}
			// The single product (not a blend/combo unless the rule asks for one), same strength
			// as the cart item if there is one, otherwise the best seller.
			$wants_blend = (bool) preg_match( '/blend|frag|\/|&/i', $suggest );
			$size        = self::strength( $trigger_item );
			$first       = 0;
			$same_size   = 0;
			foreach ( $catalog as $id => $p ) {
				if ( ! self::name_has( $p[0], $suggest ) || self::name_has( $p[0], $trigger ) ) {
					continue;
				}
				if ( ! $wants_blend && preg_match( '/blend|fragment|\/|&/i', $p[0] ) ) {
					continue;
				}
				$first = $first ? $first : (int) $id;
				if ( $size && self::strength( $p[0] ) === $size ) {
					$same_size = (int) $id;
					break;
				}
			}
			if ( $same_size || $first ) {
				$out[] = $same_size ? $same_size : $first;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Products customers bought together with the cart's products (from past orders).
	 *
	 * @param int[] $cart_ids Parent product IDs in the cart.
	 * @return int[]
	 */
	public static function copurchase_ids( $cart_ids ) {
		$pairs  = get_option( self::PAIRS_OPTION, array() );
		$scores = array();
		foreach ( $cart_ids as $id ) {
			if ( empty( $pairs[ $id ] ) ) {
				continue;
			}
			foreach ( $pairs[ $id ] as $other => $count ) {
				$scores[ $other ] = ( isset( $scores[ $other ] ) ? $scores[ $other ] : 0 ) + $count;
			}
		}
		arsort( $scores );
		return array_map( 'intval', array_keys( $scores ) );
	}

	/**
	 * Rebuild the "bought together" table from the last 12 months of paid orders (daily cron).
	 */
	public static function rebuild() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT a.product_id AS a, b.product_id AS b, COUNT(DISTINCT a.order_id) AS n FROM {$table} a INNER JOIN {$table} b ON b.order_id = a.order_id AND b.product_id <> a.product_id INNER JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = a.order_id AND s.status IN ('wc-processing','wc-completed','wc-on-hold') WHERE a.date_created >= %s GROUP BY a.product_id, b.product_id HAVING n >= 2 ORDER BY n DESC LIMIT 20000", $since ), ARRAY_A );
		if ( ! $rows && ! $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = self::pairs_from_orders( $since ); // Analytics tables not filled (Analytics off / not synced yet).
		}
		$pairs = array();
		foreach ( (array) $rows as $r ) {
			$a = (int) $r['a'];
			if ( ! isset( $pairs[ $a ] ) || count( $pairs[ $a ] ) < 8 ) {
				$pairs[ $a ][ (int) $r['b'] ] = (int) $r['n'];
			}
		}
		update_option( self::PAIRS_OPTION, $pairs, false );
		self::flush_catalog();
	}

	/**
	 * Same pairs, read from orders directly (up to 3,000 recent paid orders, in pages).
	 *
	 * @param string $since GMT date.
	 * @return array Rows like the SQL: a, b, n.
	 */
	private static function pairs_from_orders( $since ) {
		$counts = array();
		for ( $page = 1; $page <= 15; $page++ ) {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'processing', 'completed', 'on-hold' ),
					'date_created' => '>=' . strtotime( $since ),
					'limit'        => 200,
					'paged'        => $page,
					'orderby'      => 'date',
					'order'        => 'DESC',
					'type'         => 'shop_order',
				)
			);
			foreach ( $orders as $order ) {
				$ids = array();
				foreach ( $order->get_items() as $item ) {
					$ids[] = (int) $item->get_product_id();
				}
				$ids = array_values( array_unique( array_filter( $ids ) ) );
				foreach ( $ids as $a ) {
					foreach ( $ids as $b ) {
						if ( $a !== $b ) {
							$counts[ $a . ':' . $b ] = ( isset( $counts[ $a . ':' . $b ] ) ? $counts[ $a . ':' . $b ] : 0 ) + 1;
						}
					}
				}
			}
			if ( count( $orders ) < 200 ) {
				break;
			}
		}
		arsort( $counts );
		$rows = array();
		foreach ( $counts as $key => $n ) {
			if ( $n < 2 ) {
				break;
			}
			list( $a, $b ) = explode( ':', $key );
			$rows[]        = array( 'a' => $a, 'b' => $b, 'n' => $n );
		}
		return $rows;
	}
}
