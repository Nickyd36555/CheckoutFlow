<?php
/**
 * Automations: a trigger plus an ordered list of email steps.
 *
 * Step delays are measured from the moment the trigger fired, so step 2 at
 * "24 hours" goes out 24h after the trigger, not 24h after step 1.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Marketing;

use CheckoutFlow\DB;
use CheckoutFlow\Mail\Queue;
use CheckoutFlow\Mail\Renderer;

defined( 'ABSPATH' ) || exit;

class Automations {

	/** @var array|null Active automations grouped by trigger (per request). */
	private static $active = null;

	public static function init() {
		add_action( 'checkoutflow_cart_abandoned', array( __CLASS__, 'on_cart_abandoned' ) );
		add_action( 'checkoutflow_order_recorded', array( __CLASS__, 'on_order_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ), 10, 2 );
		add_action( 'checkoutflow_contact_subscribed', array( __CLASS__, 'on_subscribed' ) );
	}

	/**
	 * Trigger definitions.
	 *
	 * @return array
	 */
	public static function triggers() {
		return array(
			'cart_abandoned'  => array(
				'label'    => __( 'Cart abandoned', 'checkoutflow' ),
				'desc'     => __( 'A shopper entered their email at checkout but did not complete the order. Stops as soon as they order.', 'checkoutflow' ),
				'settings' => array(
					'min_total' => array( 'label' => __( 'Only if cart total is at least', 'checkoutflow' ), 'type' => 'number', 'default' => 0 ),
				),
			),
			'order_paid'      => array(
				'label'    => __( 'Order paid', 'checkoutflow' ),
				'desc'     => __( 'Payment received (order is Processing or Completed). Great for thank-you and cross-sell emails.', 'checkoutflow' ),
				'settings' => array(
					'first_order' => array( 'label' => __( 'Only the customer\'s first order', 'checkoutflow' ), 'type' => 'checkbox', 'default' => false ),
					'product_ids' => array( 'label' => __( 'Only if the order contains product IDs (comma separated, optional)', 'checkoutflow' ), 'type' => 'text', 'default' => '' ),
				),
			),
			'order_completed' => array(
				'label'    => __( 'Order completed', 'checkoutflow' ),
				'desc'     => __( 'Order marked Completed (shipped/fulfilled). Ideal for review requests.', 'checkoutflow' ),
				'settings' => array(
					'product_ids' => array( 'label' => __( 'Only if the order contains product IDs (comma separated, optional)', 'checkoutflow' ), 'type' => 'text', 'default' => '' ),
				),
			),
			'winback'         => array(
				'label'    => __( 'Customer inactive (win-back)', 'checkoutflow' ),
				'desc'     => __( 'A customer has not ordered for a number of days. Stops if they order again.', 'checkoutflow' ),
				'settings' => array(
					'days' => array( 'label' => __( 'Days since last order', 'checkoutflow' ), 'type' => 'number', 'default' => 90 ),
				),
			),
			'subscribed'      => array(
				'label'    => __( 'Contact subscribed', 'checkoutflow' ),
				'desc'     => __( 'Someone opted in to marketing emails. Use for a welcome email.', 'checkoutflow' ),
				'settings' => array(),
			),
		);
	}

	/**
	 * Starting points for new automations.
	 *
	 * @return array key => [ label, name, trigger, steps ]
	 */
	public static function recipes() {
		$step = static function ( $delay, $unit, $subject, $preheader, $blocks ) {
			return array(
				'delay' => $delay,
				'unit'  => $unit,
				'email' => array(
					'subject'   => $subject,
					'preheader' => $preheader,
					'design'    => Renderer::design( $blocks ),
				),
			);
		};

		return array(
			'cart'    => array(
				'label'   => __( 'Abandoned cart recovery (3 emails)', 'checkoutflow' ),
				'trigger' => 'cart_abandoned',
				'steps'   => array(
					$step(
						1,
						'hours',
						__( '{first_name|Hey}, you left something behind', 'checkoutflow' ),
						__( 'Your cart is saved and ready when you are.', 'checkoutflow' ),
						array(
							array( 'heading', array( 'text' => __( 'Your cart is waiting', 'checkoutflow' ), 'align' => 'center' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there},', 'checkoutflow' ) . '</p><p>' . __( 'Looks like you didn\'t finish checking out. We saved your cart so you can pick up right where you left off.', 'checkoutflow' ) . '</p>' ) ),
							array( 'cart_items' ),
							array( 'button', array( 'text' => __( 'Complete your order', 'checkoutflow' ), 'url' => '{recovery_url}' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Questions? Just reply to this email, we\'re happy to help.', 'checkoutflow' ) . '</p>', 'align' => 'center' ) ),
						)
					),
					$step(
						24,
						'hours',
						__( 'Still thinking it over?', 'checkoutflow' ),
						__( 'Your items are still available, but we can\'t hold them forever.', 'checkoutflow' ),
						array(
							array( 'heading', array( 'text' => __( 'Still thinking it over?', 'checkoutflow' ), 'align' => 'center' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there}, your cart at {site_name} is still saved. Items sell out, so grab them while you can.', 'checkoutflow' ) . '</p>' ) ),
							array( 'cart_items' ),
							array( 'button', array( 'text' => __( 'Return to your cart', 'checkoutflow' ), 'url' => '{recovery_url}' ) ),
						)
					),
					$step(
						72,
						'hours',
						__( 'Here\'s {coupon_amount} off your cart', 'checkoutflow' ),
						__( 'A little something to help you decide.', 'checkoutflow' ),
						array(
							array( 'heading', array( 'text' => __( 'A little something to help you decide', 'checkoutflow' ), 'align' => 'center' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there}, here\'s an exclusive discount on the items in your cart.', 'checkoutflow' ) . '</p>' ) ),
							array( 'coupon', array( 'amount' => 10, 'days' => 3 ) ),
							array( 'cart_items' ),
							array( 'button', array( 'text' => __( 'Claim my discount', 'checkoutflow' ), 'url' => '{recovery_url}' ) ),
						)
					),
				),
			),
			'thanks'  => array(
				'label'   => __( 'Post-purchase thank you + cross-sell', 'checkoutflow' ),
				'trigger' => 'order_paid',
				'steps'   => array(
					$step(
						2,
						'days',
						__( 'Thanks for your order, {first_name|friend}!', 'checkoutflow' ),
						__( 'A few picks we think you\'ll love.', 'checkoutflow' ),
						array(
							array( 'heading', array( 'text' => __( 'Thank you!', 'checkoutflow' ), 'align' => 'center' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there}, thanks again for order #{order_number}. We hope you love it! Here are a few other things customers enjoy:', 'checkoutflow' ) . '</p>' ) ),
							array( 'products', array( 'columns' => 3 ) ),
						)
					),
				),
			),
			'review'  => array(
				'label'   => __( 'Review request', 'checkoutflow' ),
				'trigger' => 'order_completed',
				'steps'   => array(
					$step(
						7,
						'days',
						__( 'How are you liking your order?', 'checkoutflow' ),
						__( 'It takes 30 seconds and helps a lot.', 'checkoutflow' ),
						array(
							array( 'heading', array( 'text' => __( 'How did we do?', 'checkoutflow' ), 'align' => 'center' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there}, we\'d love to hear what you think about your recent purchase. Your review helps other shoppers and helps us improve.', 'checkoutflow' ) . '</p>' ) ),
							array( 'order_items' ),
							array( 'button', array( 'text' => __( 'Leave a review', 'checkoutflow' ), 'url' => '{review_url}' ) ),
						)
					),
				),
			),
			'winback' => array(
				'label'   => __( 'Win-back inactive customers', 'checkoutflow' ),
				'trigger' => 'winback',
				'steps'   => array(
					$step(
						0,
						'hours',
						__( 'We miss you, {first_name|friend}', 'checkoutflow' ),
						__( 'Come back and save on your next order.', 'checkoutflow' ),
						array(
							array( 'heading', array( 'text' => __( 'It\'s been a while!', 'checkoutflow' ), 'align' => 'center' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there}, here\'s a thank-you for being a customer. Use this code on your next order:', 'checkoutflow' ) . '</p>' ) ),
							array( 'coupon', array( 'amount' => 15, 'days' => 14 ) ),
							array( 'products', array( 'columns' => 3 ) ),
							array( 'button', array( 'text' => __( 'Shop now', 'checkoutflow' ), 'url' => '{shop_url}' ) ),
						)
					),
				),
			),
			'welcome' => array(
				'label'   => __( 'Welcome new subscribers', 'checkoutflow' ),
				'trigger' => 'subscribed',
				'steps'   => array(
					$step(
						5,
						'minutes',
						__( 'Welcome to {site_name}!', 'checkoutflow' ),
						__( 'Thanks for joining us.', 'checkoutflow' ),
						array(
							array( 'heading', array( 'text' => __( 'Welcome aboard!', 'checkoutflow' ), 'align' => 'center' ) ),
							array( 'text', array( 'html' => '<p>' . __( 'Hi {first_name|there}, thanks for subscribing. You\'ll be the first to hear about new products and exclusive offers.', 'checkoutflow' ) . '</p>' ) ),
							array( 'button', array( 'text' => __( 'Visit the shop', 'checkoutflow' ), 'url' => '{shop_url}' ) ),
						)
					),
				),
			),
			'blank'   => array(
				'label'   => __( 'Start from scratch', 'checkoutflow' ),
				'trigger' => 'order_paid',
				'steps'   => array(
					$step( 1, 'hours', __( 'Subject', 'checkoutflow' ), '', array( array( 'heading' ), array( 'text' ), array( 'button' ) ) ),
				),
			),
		);
	}

	/**
	 * Create an automation from a recipe.
	 *
	 * @return int ID.
	 */
	public static function create_from_recipe( $key, $status = 'paused' ) {
		$recipes = self::recipes();
		$r       = isset( $recipes[ $key ] ) ? $recipes[ $key ] : $recipes['blank'];
		$now     = DB::now();
		return DB::insert(
			'automations',
			array(
				'name'             => 'blank' === $key ? __( 'New automation', 'checkoutflow' ) : $r['label'],
				'trigger_type'     => $r['trigger'],
				'trigger_settings' => wp_json_encode( self::default_trigger_settings( $r['trigger'] ) ),
				'steps'            => wp_json_encode( $r['steps'] ),
				'status'           => $status,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
	}

	public static function default_trigger_settings( $trigger ) {
		$t   = self::triggers();
		$out = array();
		foreach ( isset( $t[ $trigger ] ) ? $t[ $trigger ]['settings'] : array() as $k => $f ) {
			$out[ $k ] = $f['default'];
		}
		return (object) $out;
	}

	/**
	 * @return array|null Automation with decoded steps/settings.
	 */
	public static function get( $id ) {
		$row = DB::get( 'automations', $id );
		if ( ! $row ) {
			return null;
		}
		$row['steps']            = DB::json( $row['steps'] );
		$row['trigger_settings'] = DB::json( $row['trigger_settings'] );
		return $row;
	}

	private static function active_for( $trigger ) {
		if ( null === self::$active ) {
			global $wpdb;
			self::$active = array();
			$rows         = $wpdb->get_col( "SELECT id FROM " . DB::t( 'automations' ) . " WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $rows as $id ) {
				$a = self::get( $id );
				self::$active[ $a['trigger_type'] ][] = $a;
			}
		}
		return isset( self::$active[ $trigger ] ) ? self::$active[ $trigger ] : array();
	}

	public static function flush_cache() {
		self::$active = null;
	}

	public static function step_delay_seconds( $step ) {
		$units = array( 'minutes' => MINUTE_IN_SECONDS, 'hours' => HOUR_IN_SECONDS, 'days' => DAY_IN_SECONDS );
		$unit  = isset( $step['unit'], $units[ $step['unit'] ] ) ? $units[ $step['unit'] ] : HOUR_IN_SECONDS;
		return (int) round( max( 0, (float) $step['delay'] ) * $unit );
	}

	/**
	 * Enroll an email into an automation (once per ref).
	 */
	public static function enroll( $automation, $email, $ref, $ctx = array() ) {
		if ( empty( $automation['steps'] ) || Queue::exists( 'automation', $automation['id'], $ref, $email ) ) {
			return;
		}
		$c = Contacts::get_by_email( $email );
		if ( $c && 'unsubscribed' === $c['status'] ) {
			return;
		}
		$ctx['t0'] = time();
		Queue::add( $email, 'automation', $automation['id'], 0, $ctx['t0'] + self::step_delay_seconds( $automation['steps'][0] ), $ref, $ctx );
	}

	/**
	 * After a step is sent, queue the next one.
	 */
	public static function schedule_next( $automation, $row, $ctx ) {
		$next = (int) $row['step'] + 1;
		if ( empty( $automation['steps'][ $next ] ) ) {
			return;
		}
		unset( $ctx['coupon'] );
		$t0 = isset( $ctx['t0'] ) ? (int) $ctx['t0'] : time();
		Queue::add( $row['email'], 'automation', $automation['id'], $next, $t0 + self::step_delay_seconds( $automation['steps'][ $next ] ), $row['ref'], $ctx );
	}

	/**
	 * Should this queued email be skipped? Returns a reason, or '' to send.
	 */
	public static function exit_reason( $automation, $row, $ctx ) {
		switch ( $automation['trigger_type'] ) {
			case 'cart_abandoned':
				$cart = ! empty( $ctx['cart_id'] ) ? DB::get( 'carts', $ctx['cart_id'] ) : null;
				if ( ! $cart ) {
					return 'cart deleted';
				}
				// "active" means they came back to checkout (e.g. via our link) but haven't paid yet – keep going.
				if ( 'recovered' === $cart['status'] ) {
					return 'cart recovered';
				}
				if ( \CheckoutFlow\Recovery\Recovery::cart_ordered( $cart ) ) {
					return 'ordered';
				}
				break;
			case 'order_paid':
			case 'order_completed':
				$order = ! empty( $ctx['order_id'] ) ? wc_get_order( $ctx['order_id'] ) : null;
				if ( ! $order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
					return 'order cancelled or refunded';
				}
				break;
			case 'winback':
				$c = Contacts::get_by_email( $row['email'] );
				if ( $c && isset( $ctx['last_order_at'] ) && $c['last_order_at'] !== $ctx['last_order_at'] ) {
					return 'ordered again';
				}
				break;
		}
		return '';
	}

	/* ---------- trigger handlers ---------- */

	public static function on_cart_abandoned( $cart ) {
		foreach ( self::active_for( 'cart_abandoned' ) as $a ) {
			$min = isset( $a['trigger_settings']['min_total'] ) ? (float) $a['trigger_settings']['min_total'] : 0;
			// One recovery sequence per shopper per week, however many carts/devices/captures.
			$recent = Queue::enrolled_since( $a['id'], $cart['email'], 'cart:', gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) );
			if ( (float) $cart['total'] >= $min && ! $recent ) {
				self::enroll( $a, $cart['email'], 'cart:' . $cart['id'], array( 'cart_id' => (int) $cart['id'] ) );
			}
		}
	}

	private static function order_matches( $order, $settings ) {
		$ids = array_filter( array_map( 'absint', explode( ',', isset( $settings['product_ids'] ) ? (string) $settings['product_ids'] : '' ) ) );
		if ( ! $ids ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( in_array( (int) $item->get_product_id(), $ids, true ) || in_array( (int) $item->get_variation_id(), $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param \WC_Order $order   Order.
	 * @param array     $contact Contact row before this order was counted.
	 */
	public static function on_order_paid( $order, $contact ) {
		foreach ( self::active_for( 'order_paid' ) as $a ) {
			if ( ! empty( $a['trigger_settings']['first_order'] ) && (int) $contact['orders'] > 0 ) {
				continue;
			}
			if ( self::order_matches( $order, $a['trigger_settings'] ) ) {
				self::enroll( $a, $order->get_billing_email(), 'order:' . $order->get_id(), array( 'order_id' => $order->get_id() ) );
			}
		}
	}

	public static function on_order_completed( $order_id, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! $order->get_billing_email() ) {
			return;
		}
		foreach ( self::active_for( 'order_completed' ) as $a ) {
			if ( self::order_matches( $order, $a['trigger_settings'] ) ) {
				self::enroll( $a, $order->get_billing_email(), 'order:' . $order->get_id(), array( 'order_id' => $order->get_id() ) );
			}
		}
	}

	public static function on_subscribed( $email ) {
		foreach ( self::active_for( 'subscribed' ) as $a ) {
			self::enroll( $a, $email, 'sub' );
		}
	}

	/**
	 * Daily: find customers who crossed the inactivity threshold.
	 */
	public static function run_daily() {
		global $wpdb;
		foreach ( self::active_for( 'winback' ) as $a ) {
			$days = max( 1, (int) ( isset( $a['trigger_settings']['days'] ) ? $a['trigger_settings']['days'] : 90 ) );
			// A one-week window lets missed cron days catch up without emailing ancient customers on first activation.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT email, last_order_at FROM ' . DB::t( 'contacts' ) . " WHERE status <> 'unsubscribed' AND orders > 0 AND last_order_at <= %s AND last_order_at > %s LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
					gmdate( 'Y-m-d H:i:s', time() - ( $days + 7 ) * DAY_IN_SECONDS )
				),
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				self::enroll( $a, $r['email'], 'winback:' . substr( $r['last_order_at'], 0, 10 ), array( 'last_order_at' => $r['last_order_at'] ) );
			}
		}
	}
}
