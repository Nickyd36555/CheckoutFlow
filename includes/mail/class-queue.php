<?php
/**
 * Email queue: scheduling, rate-limited sending, open/click tracking, unsubscribe,
 * and revenue attribution.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Mail;

use CheckoutFlow\DB;
use CheckoutFlow\Settings;
use CheckoutFlow\Marketing\Contacts;
use CheckoutFlow\Marketing\Automations;

defined( 'ABSPATH' ) || exit;

class Queue {

	const CLICK_COOKIE = 'cf_ref';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_request' ), 5 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'attach_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attach_order' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'credit_revenue' ), 10, 4 );
	}

	/**
	 * Schedule one email.
	 *
	 * @return int Queue ID.
	 */
	public static function add( $email, $source, $source_id, $step, $send_at, $ref = '', $context = array() ) {
		$contact = Contacts::get_by_email( $email );
		return DB::insert(
			'queue',
			array(
				'email'      => strtolower( $email ),
				'contact_id' => $contact ? (int) $contact['id'] : 0,
				'source'     => $source,
				'source_id'  => (int) $source_id,
				'step'       => (int) $step,
				'ref'        => (string) $ref,
				'context'    => wp_json_encode( (object) $context ),
				'status'     => 'pending',
				'send_at'    => gmdate( 'Y-m-d H:i:s', max( time(), (int) $send_at ) ),
				'created_at' => DB::now(),
			)
		);
	}

	public static function exists( $source, $source_id, $ref ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . DB::t( 'queue' ) . ' WHERE source = %s AND source_id = %d AND ref = %s LIMIT 1', $source, $source_id, $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Cancel pending emails by ref (e.g. "cart:12") and/or email address, for automations.
	 */
	public static function cancel_pending( $ref = null, $email = null, $reason = '' ) {
		global $wpdb;
		$where = array( "status = 'pending'", "source = 'automation'" );
		$args  = array();
		if ( null !== $ref ) {
			$where[] = 'ref = %s';
			$args[]  = $ref;
		}
		if ( null !== $email ) {
			$where[] = 'email = %s';
			$args[]  = strtolower( $email );
		}
		if ( ! $args ) {
			return;
		}
		$sql = 'UPDATE ' . DB::t( 'queue' ) . " SET status = 'cancelled', error = %s WHERE " . implode( ' AND ', $where );
		$wpdb->query( $wpdb->prepare( $sql, array_merge( array( $reason ), $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Send due emails, up to the per-minute rate.
	 */
	public static function process() {
		global $wpdb;
		$table = DB::t( 'queue' );
		$limit = max( 1, (int) Settings::get( 'email_rate' ) );
		$start = time();

		// Release rows stuck in "sending" by a crashed run.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending' WHERE status = 'sending' AND sent_at < %s", gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND send_at <= %s ORDER BY send_at ASC LIMIT %d", DB::now(), $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $ids as $id ) {
			if ( time() - $start > 50 ) {
				break;
			}
			// Atomic claim: only one runner can move a row out of "pending".
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sending', sent_at = %s WHERE id = %d AND status = 'pending'", DB::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $claimed ) {
				self::send( DB::get( 'queue', $id ) );
			}
		}
	}

	/**
	 * Build the email for a queue row and send it.
	 *
	 * @param array $row Queue row.
	 */
	public static function send( $row ) {
		$fail = static function ( $status, $error ) use ( $row ) {
			DB::update( 'queue', $row['id'], array( 'status' => $status, 'error' => substr( $error, 0, 250 ) ) );
		};

		$contact = Contacts::get_by_email( $row['email'] );
		if ( $contact && 'unsubscribed' === $contact['status'] ) {
			return $fail( 'cancelled', 'unsubscribed' );
		}

		$ctx     = DB::json( $row['context'] );
		$message = null;

		if ( 'campaign' === $row['source'] ) {
			$campaign = DB::get( 'campaigns', $row['source_id'] );
			if ( ! $campaign || 'cancelled' === $campaign['status'] ) {
				return $fail( 'cancelled', 'campaign cancelled' );
			}
			$message = DB::json( $campaign['email'] );
		} elseif ( 'automation' === $row['source'] ) {
			$automation = Automations::get( $row['source_id'] );
			if ( ! $automation || 'active' !== $automation['status'] ) {
				return $fail( 'cancelled', 'automation inactive' );
			}
			$exit = Automations::exit_reason( $automation, $row, $ctx );
			if ( $exit ) {
				return $fail( 'cancelled', $exit );
			}
			$message = isset( $automation['steps'][ $row['step'] ]['email'] ) ? $automation['steps'][ $row['step'] ]['email'] : null;
		}

		if ( ! $message || empty( $message['subject'] ) ) {
			return $fail( 'failed', 'email content missing' );
		}

		$render_ctx = self::context( $row, $ctx, $contact, $message );
		if ( ! empty( $render_ctx['_skip'] ) ) {
			return $fail( 'cancelled', $render_ctx['_skip'] );
		}

		$tags    = Merge_Tags::values( $render_ctx );
		$subject = wp_specialchars_decode( Merge_Tags::apply( $message['subject'], $tags, 'text' ), ENT_QUOTES );
		$html    = Renderer::render( isset( $message['design'] ) ? $message['design'] : array(), $render_ctx );
		if ( Settings::get( 'email_tracking' ) ) {
			$html = self::add_tracking( $html, (int) $row['id'] );
		}

		$unsub   = self::url( 'u', $row['id'] );
		$headers = array(
			'List-Unsubscribe: <' . $unsub . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);

		if ( SMTP::send( $row['email'], $subject, $html, $headers ) ) {
			DB::update( 'queue', $row['id'], array( 'status' => 'sent', 'sent_at' => DB::now(), 'subject' => substr( $subject, 0, 250 ), 'context' => wp_json_encode( (object) $ctx ), 'error' => '' ) );
			if ( 'automation' === $row['source'] ) {
				Automations::schedule_next( $automation, $row, $ctx );
			}
		} else {
			$fail( 'failed', SMTP::last_error() ? SMTP::last_error() : 'wp_mail returned false' );
		}
	}

	/**
	 * Render context for a queue row. Generates the coupon (once) when the design has a coupon block.
	 *
	 * @param array      $row     Queue row.
	 * @param array      $ctx     Stored row context (modified by reference: coupon is saved back).
	 * @param array|null $contact Contact row.
	 * @param array      $message { subject, preheader, design }.
	 * @return array
	 */
	private static function context( $row, &$ctx, $contact, $message ) {
		$render = array(
			'contact'         => $contact ? $contact : array( 'email' => $row['email'] ),
			'preheader'       => isset( $message['preheader'] ) ? $message['preheader'] : '',
			'unsubscribe_url' => self::url( 'u', $row['id'] ),
		);

		if ( ! empty( $ctx['cart_id'] ) ) {
			$cart = DB::get( 'carts', $ctx['cart_id'] );
			if ( ! $cart ) {
				$render['_skip'] = 'cart deleted';
				return $render;
			}
			$render['cart']         = $cart;
			$render['recovery_url'] = add_query_arg( array( 'cf-recover' => $cart['token'], 'cfq' => $row['id'] ), home_url( '/' ) );
		}
		if ( ! empty( $ctx['order_id'] ) ) {
			$render['order'] = wc_get_order( $ctx['order_id'] );
		}

		$coupon_block = Renderer::coupon_block( isset( $message['design'] ) ? $message['design'] : array() );
		if ( $coupon_block ) {
			if ( empty( $ctx['coupon'] ) ) {
				$ctx['coupon'] = Coupons::create( $row['email'], $coupon_block, (int) $row['id'] );
			}
			$render['coupon'] = $ctx['coupon'];
		}
		return $render;
	}

	/* ---------- tracking ---------- */

	private static function sign( $data ) {
		return substr( hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) . 'cf-track' ), 0, 16 );
	}

	/**
	 * @param string $type o (open) | c (click) | u (unsubscribe).
	 * @param int    $id   Queue ID.
	 * @param string $target Click destination.
	 * @return string
	 */
	public static function url( $type, $id, $target = '' ) {
		$args = array( 'cf_' . $type => $id . '.' . self::sign( $type . $id . $target ) );
		if ( 'c' === $type ) {
			$args['u'] = rawurlencode( $target );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	private static function verify( $type, $raw, $target = '' ) {
		$parts = explode( '.', (string) $raw );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 0;
		}
		return hash_equals( self::sign( $type . $parts[0] . $target ), $parts[1] ) ? (int) $parts[0] : 0;
	}

	/**
	 * Rewrite links for click tracking and add the open pixel.
	 */
	public static function add_tracking( $html, $id ) {
		$html = preg_replace_callback(
			'#(<a\s[^>]*href=")([^"]+)(")#i',
			static function ( $m ) use ( $id ) {
				$href = html_entity_decode( $m[2], ENT_QUOTES );
				if ( ! preg_match( '#^https?://#i', $href ) || false !== strpos( $href, 'cf_u=' ) ) {
					return $m[0];
				}
				return $m[1] . esc_url( self::url( 'c', $id, $href ) ) . $m[3];
			},
			$html
		);
		$pixel = '<img src="' . esc_url( self::url( 'o', $id ) ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
		return str_replace( '</body>', $pixel . '</body>', $html );
	}

	/**
	 * Tracking / unsubscribe endpoints (?cf_o=, ?cf_c=, ?cf_u=).
	 */
	public static function handle_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- signed URLs.
		if ( isset( $_GET['cf_o'] ) ) {
			$id = self::verify( 'o', wp_unslash( $_GET['cf_o'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( $id ) {
				self::mark( $id, 'opened_at' );
			}
			nocache_headers();
			header( 'Content-Type: image/gif' );
			echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore
			exit;
		}

		if ( isset( $_GET['cf_c'], $_GET['u'] ) ) {
			$target = rawurldecode( wp_unslash( $_GET['u'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$id     = self::verify( 'c', wp_unslash( $_GET['cf_c'] ), $target ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! $id ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
			self::mark( $id, 'clicked_at' );
			self::mark( $id, 'opened_at' ); // A click implies an open (images may be blocked).
			wc_setcookie( self::CLICK_COOKIE, (string) $id, time() + 7 * DAY_IN_SECONDS );
			// The URL is signed, so redirecting to an external domain the email linked to is safe.
			wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		if ( isset( $_GET['cf_u'] ) ) {
			$id  = self::verify( 'u', wp_unslash( $_GET['cf_u'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$row = $id ? DB::get( 'queue', $id ) : null;
			if ( ! $row ) {
				wp_die( esc_html__( 'This unsubscribe link is invalid.', 'checkoutflow' ), '', array( 'response' => 400 ) );
			}
			// RFC 8058 one-click (POST from the mail client) and GET confirmation page.
			$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
			if ( $is_post || isset( $_GET['confirm'] ) ) {
				Contacts::unsubscribe( $row['email'] );
				if ( $is_post && ! isset( $_POST['cf_confirm'] ) ) {
					status_header( 200 );
					exit;
				}
				wp_die(
					'<h1>' . esc_html__( 'You have been unsubscribed', 'checkoutflow' ) . '</h1><p>' . esc_html( sprintf( /* translators: %s: email */ __( '%s will no longer receive marketing emails from us.', 'checkoutflow' ), $row['email'] ) ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the store', 'checkoutflow' ) . '</a></p>',
					esc_html__( 'Unsubscribed', 'checkoutflow' ),
					array( 'response' => 200 )
				);
			}
			// Show a confirm button so link scanners/prefetchers can't unsubscribe people.
			wp_die(
				'<h1>' . esc_html__( 'Unsubscribe', 'checkoutflow' ) . '</h1><p>' . esc_html( sprintf( /* translators: %s: email */ __( 'Stop sending emails to %s?', 'checkoutflow' ), $row['email'] ) ) . '</p><form method="post" action="' . esc_url( add_query_arg( 'confirm', 1 ) ) . '"><input type="hidden" name="cf_confirm" value="1"><button type="submit" class="button">' . esc_html__( 'Unsubscribe', 'checkoutflow' ) . '</button></form>',
				esc_html__( 'Unsubscribe', 'checkoutflow' ),
				array( 'response' => 200 )
			);
		}
		// phpcs:enable
	}

	private static function mark( $id, $column ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . DB::t( 'queue' ) . " SET {$column} = %s WHERE id = %d AND {$column} IS NULL", DB::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ---------- attribution ---------- */

	/**
	 * Remember which email click led to this order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function attach_order( $order ) {
		$id = isset( $_COOKIE[ self::CLICK_COOKIE ] ) ? absint( $_COOKIE[ self::CLICK_COOKIE ] ) : 0;
		if ( ! $id || ! $order instanceof \WC_Order ) {
			return;
		}
		$order->update_meta_data( '_cf_queue_id', $id );
		$order->save_meta_data();
		wc_setcookie( self::CLICK_COOKIE, '', time() - HOUR_IN_SECONDS );
	}

	/**
	 * Credit revenue to the email once the order is paid.
	 */
	public static function credit_revenue( $order_id, $from, $to, $order ) {
		if ( ! in_array( $to, wc_get_is_paid_statuses(), true ) || ! $order instanceof \WC_Order ) {
			return;
		}
		$id = (int) $order->get_meta( '_cf_queue_id' );
		if ( ! $id || $order->get_meta( '_cf_credited' ) ) {
			return;
		}
		DB::update( 'queue', $id, array( 'order_id' => $order->get_id(), 'revenue' => (float) $order->get_total() ) );
		$order->update_meta_data( '_cf_credited', 1 );
		$order->save_meta_data();
	}
}
