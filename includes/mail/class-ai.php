<?php
/**
 * AI email writer: asks Claude (Anthropic Messages API) for a subject line,
 * preview text and builder blocks, then runs the result through the normal
 * builder sanitizer so it is exactly as safe as a hand-built email.
 *
 * Plain HTTP via wp_remote_post() keeps the plugin free of Composer dependencies.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Mail;

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

class AI {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const MODEL    = 'claude-opus-5-5';

	/** Block types the AI may use (dynamic, structural and raw-HTML blocks are left to people). */
	const TYPES = array( 'heading', 'text', 'logo', 'list', 'button', 'image', 'divider', 'spacer', 'products', 'coupon', 'footer' );

	/* ---------- background jobs ----------
	 * A draft can take longer than a host's ~60s request limit, so the editor starts a
	 * job, a loopback request runs it, and the editor polls for the result. */

	const JOB = 'checkoutflow_ai_job_';

	/**
	 * @param array $args brief, current, mode.
	 * @return string Job ID.
	 */
	public static function start_job( $args ) {
		$id    = wp_generate_uuid4();
		$token = wp_generate_password( 32, false );
		set_transient(
			self::JOB . $id,
			array(
				'status'  => 'pending',
				'user'    => get_current_user_id(),
				'token'   => wp_hash( $token ),
				'args'    => $args,
				'created' => time(),
			),
			HOUR_IN_SECONDS
		);
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking' => false,
				'timeout'  => 1,
				'body'     => array( 'action' => 'cf_ai_work', 'job' => $id, 'token' => $token ),
				'cookies'  => array(),
			)
		);
		return $id;
	}

	public static function job( $id ) {
		$job = get_transient( self::JOB . sanitize_key( $id ) );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Run a pending job (from the loopback request, or the poller as a fallback).
	 *
	 * @param string $id Job ID.
	 */
	public static function run_job( $id ) {
		$id  = sanitize_key( $id );
		$job = self::job( $id );
		if ( ! $job || 'pending' !== $job['status'] ) {
			return;
		}
		$job['status'] = 'running';
		set_transient( self::JOB . $id, $job, HOUR_IN_SECONDS );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$out = self::generate( $job['args']['brief'], $job['args']['current'], $job['args']['mode'] );
		$job['status'] = is_wp_error( $out ) ? 'error' : 'done';
		$job['result'] = is_wp_error( $out ) ? array( 'message' => $out->get_error_message() ) : $out;
		unset( $job['args'] );
		set_transient( self::JOB . $id, $job, HOUR_IN_SECONDS );
	}

	/**
	 * Loopback worker: no login cookie, so it is authorised by the job's one-time token.
	 */
	public static function ajax_work() {
		// phpcs:disable WordPress.Security.NonceVerification -- authorised by the job token.
		$id    = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$job = self::job( $id );
		if ( ! $job || '' === $token || ! hash_equals( $job['token'], wp_hash( $token ) ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::run_job( $id );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	public static function api_key() {
		if ( defined( 'CHECKOUTFLOW_ANTHROPIC_API_KEY' ) ) {
			return (string) CHECKOUTFLOW_ANTHROPIC_API_KEY;
		}
		return (string) Settings::decrypt( Settings::get( 'ai_api_key' ) );
	}

	public static function enabled() {
		return '' !== self::api_key();
	}

	/**
	 * JSON schema for the structured response. One flat block shape: each type
	 * uses only the fields it needs and the sanitizer drops the rest.
	 */
	private static function schema() {
		$block = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'type' ),
			'properties'           => array(
				'type'          => array( 'type' => 'string', 'enum' => array_merge( self::TYPES, array( 'columns' ) ) ),
				'text'          => array( 'type' => 'string' ),
				'html'          => array( 'type' => 'string' ),
				'size'          => array( 'type' => 'integer' ),
				'align'         => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
				'color'         => array( 'type' => 'string' ),
				'items'         => array( 'type' => 'string' ),
				'style'         => array( 'type' => 'string', 'enum' => array( 'bullet', 'number', 'check' ) ),
				'url'           => array( 'type' => 'string' ),
				'src'           => array( 'type' => 'string' ),
				'alt'           => array( 'type' => 'string' ),
				'height'        => array( 'type' => 'integer' ),
				'ids'           => array( 'type' => 'string' ),
				'columns'       => array( 'type' => 'integer' ),
				'button_text'   => array( 'type' => 'string' ),
				'discount_type' => array( 'type' => 'string', 'enum' => array( 'percent', 'fixed_cart' ) ),
				'amount'        => array( 'type' => 'number' ),
				'days'          => array( 'type' => 'integer' ),
				'free_shipping' => array( 'type' => 'boolean' ),
				'_bg'           => array( 'type' => 'string' ),
				'layout'        => array( 'type' => 'string', 'enum' => array( '50-50', '33-67', '67-33', '33-33-33' ) ),
				'cols'          => array(
					'type'  => 'array',
					'items' => array(
						'type'  => 'array',
						'items' => array( '$ref' => '#/$defs/inner' ),
					),
				),
			),
		);
		$inner = $block;
		unset( $inner['properties']['layout'], $inner['properties']['cols'] );
		$inner['properties']['type']['enum'] = self::TYPES;

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'subject', 'preheader', 'blocks' ),
			'properties'           => array(
				'subject'   => array( 'type' => 'string' ),
				'preheader' => array( 'type' => 'string' ),
				'blocks'    => array( 'type' => 'array', 'items' => $block ),
			),
			'$defs'                => array( 'inner' => $inner ),
		);
	}

	/**
	 * Up to 150 published products so the AI can feature real items by ID.
	 */
	private static function catalog() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return '';
		}
		$lines = array();
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 150, 'orderby' => 'popularity', 'return' => 'objects' ) ) as $p ) {
			$price   = wp_strip_all_tags( html_entity_decode( wc_price( $p->get_price() ) ) );
			$lines[] = $p->get_id() . ' | ' . $p->get_name() . ' | ' . $price . ( $p->is_in_stock() ? '' : ' | out of stock' );
		}
		return implode( "\n", $lines );
	}

	private static function system_prompt() {
		$tags = array();
		foreach ( Merge_Tags::reference() as $tag => $label ) {
			$tags[] = '{' . $tag . '} - ' . $label;
		}
		$brand = trim( (string) Settings::get( 'ai_brand' ) );

		$prompt = 'You write marketing emails for the WooCommerce store "' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '" (' . home_url( '/' ) . '), and you lay them out in the store\'s drag-and-drop email builder. '
			. "Respond with the subject line, the preview text (shown after the subject in the inbox, under 110 characters), and the email body as an ordered list of builder blocks.\n\n"
			. "Block types and the fields they use:\n"
			. "- heading: text, size (px, 20-36), align\n"
			. "- text: html (only <p>, <strong>, <em>, <a href>, <br>, <ul>/<ol>/<li>), align\n"
			. "- logo: align (shows the store logo)\n"
			. "- list: items (one per line), style (bullet|number|check), align\n"
			. "- button: text, url, align\n"
			. "- image: src, alt, url (only when the request gives an image URL)\n"
			. "- divider; spacer: height (px)\n"
			. "- products: ids (comma-separated product IDs from the catalog), columns (1-3), button_text\n"
			. "- coupon: discount_type (percent|fixed_cart), amount, days (valid for), free_shipping, text (line shown above the code). A unique code is generated per recipient; refer to it with {coupon_code}, {coupon_amount}, {coupon_expiry}.\n"
			. "- footer: html (replaces the default footer; usually omit it - an address and unsubscribe link are added automatically)\n"
			. "- columns: layout (50-50|33-67|67-33|33-33-33), cols (one block list per column; no columns inside columns)\n"
			. "Any block may set _bg (hex background color).\n\n"
			. "Merge tags you can put in text, headings and URLs:\n" . implode( "\n", $tags ) . "\n\n"
			. "Guidelines: start with a logo block unless asked otherwise. Keep it scannable: one clear message, short paragraphs, a single main call to action (button url {shop_url} unless a specific link is given). "
			. "Personalise with {first_name} only where it reads naturally with an empty name too. Only feature products from the catalog, by ID, and never invent prices, discounts, dates or claims the request does not give. "
			. 'Write plain, confident copy, not hype.';

		if ( '' !== $brand ) {
			$prompt .= "\n\nStore rules (always follow):\n" . $brand;
		}
		$catalog = self::catalog();
		if ( '' !== $catalog ) {
			$prompt .= "\n\nCatalog (ID | name | price):\n" . $catalog;
		}
		return $prompt;
	}

	/**
	 * Generate an email.
	 *
	 * @param string $brief   What the email should say.
	 * @param array  $current Current email: subject, preheader, blocks (for "rewrite" and context).
	 * @param string $mode    replace|append.
	 * @return array|\WP_Error { subject, preheader, blocks }
	 */
	public static function generate( $brief, $current, $mode ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'cf_ai_key', __( 'Add your Anthropic API key in CheckoutFlow → Settings → Email & SMTP to use the AI writer.', 'checkoutflow' ) );
		}

		$user = 'Request: ' . $brief;
		if ( ! empty( $current['blocks'] ) ) {
			$user .= "\n\nThe email currently contains these blocks (JSON):\n" . wp_json_encode( $current['blocks'] );
			$user .= 'append' === $mode
				? "\n\nReturn only the NEW blocks to add after the existing content (subject and preheader may stay as they are)."
				: "\n\nReturn the complete new email; it replaces the current one.";
		}
		if ( ! empty( $current['subject'] ) ) {
			$user .= "\nCurrent subject: " . $current['subject'];
		}

		$body = array(
			'model'         => self::model(),
			'max_tokens'    => 16000,
			'system'        => self::system_prompt(),
			'messages'      => array( array( 'role' => 'user', 'content' => $user ) ),
			'output_config' => array(
				'effort' => 'low',
				'format' => array( 'type' => 'json_schema', 'schema' => self::schema() ),
			),
			// If the request is declined on policy grounds, the API retries it on a fallback model.
			'fallbacks'     => 'default',
		);

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 330 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$data = self::request( $body, 300 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$stop = isset( $data['stop_reason'] ) ? $data['stop_reason'] : '';
		if ( 'refusal' === $stop ) {
			return new \WP_Error( 'cf_ai_refusal', __( 'The AI declined this request. Try rewording the brief.', 'checkoutflow' ) );
		}
		if ( 'max_tokens' === $stop ) {
			return new \WP_Error( 'cf_ai_long', __( 'The email came out too long. Ask for a shorter email.', 'checkoutflow' ) );
		}

		$text = '';
		foreach ( (array) ( isset( $data['content'] ) ? $data['content'] : array() ) as $part ) {
			if ( isset( $part['type'], $part['text'] ) && 'text' === $part['type'] ) {
				$text .= $part['text'];
			}
		}
		$out = json_decode( $text, true );
		if ( ! is_array( $out ) || ! isset( $out['blocks'] ) ) {
			return new \WP_Error( 'cf_ai_parse', __( 'The AI reply could not be read. Please try again.', 'checkoutflow' ) );
		}
		return self::clean( $out );
	}

	/**
	 * Fill defaults for each block and run the builder sanitizer.
	 *
	 * @param array $out Decoded reply.
	 * @return array
	 */
	public static function clean( $out ) {
		$types  = Renderer::block_types();
		$fill   = function ( $list, $allow_cols ) use ( &$fill, $types ) {
			$blocks = array();
			foreach ( (array) $list as $b ) {
				$type = is_array( $b ) && isset( $b['type'] ) ? $b['type'] : '';
				if ( ! in_array( $type, self::TYPES, true ) && ! ( $allow_cols && 'columns' === $type ) ) {
					continue;
				}
				if ( 'columns' === $type ) {
					$b['cols'] = array_map(
						function ( $col ) use ( $fill ) {
							return $fill( $col, false );
						},
						isset( $b['cols'] ) && is_array( $b['cols'] ) ? $b['cols'] : array()
					);
					$blocks[]  = $b;
					continue;
				}
				$blocks[] = array_merge( $types[ $type ]['props'], $b );
			}
			return $blocks;
		};
		$design = Renderer::sanitize( array( 'blocks' => $fill( $out['blocks'], true ) ) );

		return array(
			'subject'   => sanitize_text_field( isset( $out['subject'] ) ? (string) $out['subject'] : '' ),
			'preheader' => sanitize_text_field( isset( $out['preheader'] ) ? (string) $out['preheader'] : '' ),
			'blocks'    => $design['blocks'],
		);
	}

	public static function model() {
		$m = (string) Settings::get( 'ai_model' );
		return isset( self::models()[ $m ] ) ? $m : self::MODEL;
	}

	public static function models() {
		return array(
			'claude-opus-5-5'   => __( 'Claude Opus 5.5 (best writing)', 'checkoutflow' ),
			'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5 (faster)', 'checkoutflow' ),
		);
	}

	/**
	 * Send a Messages API request as a stream (data starts flowing at once, so slow
	 * drafts don't look like a dead connection) and assemble the final message.
	 *
	 * @param array $body    Request body (without "stream").
	 * @param int   $timeout Seconds.
	 * @return array|\WP_Error { stop_reason, content: [ { type: text, text } ], model }
	 */
	private static function request( $body, $timeout ) {
		$body['stream'] = true;
		$headers        = array(
			'content-type'      => 'application/json',
			'accept'            => 'text/event-stream',
			'x-api-key'         => self::api_key(),
			'anthropic-version' => '2023-06-01',
		);
		if ( isset( $body['fallbacks'] ) ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		$res = wp_remote_post(
			apply_filters( 'checkoutflow_ai_endpoint', self::ENDPOINT ),
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( false !== stripos( $msg, 'timed out' ) && false !== stripos( $msg, '0 bytes' ) ) {
				/* translators: %d: seconds */
				$msg = sprintf( __( 'No reply from Anthropic within %d seconds. Your server may be blocking or slowing connections to api.anthropic.com; use "Test AI connection" in Settings → Email & SMTP to check.', 'checkoutflow' ), $timeout );
			}
			/* translators: %s: error message */
			return new \WP_Error( 'cf_ai_http', sprintf( __( 'Could not reach the AI service: %s', 'checkoutflow' ), $msg ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			$data = json_decode( $raw, true );
			// Older API versions or other models may not accept the fallback option: retry without it once.
			if ( 400 === $code && isset( $body['fallbacks'] ) && false !== stripos( isset( $data['error']['message'] ) ? $data['error']['message'] : '', 'fallback' ) ) {
				unset( $body['fallbacks'], $body['stream'] );
				return self::request( $body, $timeout );
			}
			return new \WP_Error( 'cf_ai_api', self::api_error( $code, $data ) );
		}

		// Server-sent events: collect text deltas, the stop reason, and any error event.
		$msg  = array( 'stop_reason' => '', 'content' => array(), 'model' => '' );
		$text = '';
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			if ( 0 !== strpos( $line, 'data:' ) ) {
				continue;
			}
			$ev = json_decode( trim( substr( $line, 5 ) ), true );
			if ( ! is_array( $ev ) || ! isset( $ev['type'] ) ) {
				continue;
			}
			switch ( $ev['type'] ) {
				case 'message_start':
					$msg['model'] = isset( $ev['message']['model'] ) ? $ev['message']['model'] : '';
					break;
				case 'content_block_delta':
					if ( isset( $ev['delta']['type'], $ev['delta']['text'] ) && 'text_delta' === $ev['delta']['type'] ) {
						$text .= $ev['delta']['text'];
					}
					break;
				case 'message_delta':
					if ( ! empty( $ev['delta']['stop_reason'] ) ) {
						$msg['stop_reason'] = $ev['delta']['stop_reason'];
					}
					break;
				case 'error':
					$etype = isset( $ev['error']['type'] ) ? $ev['error']['type'] : '';
					return new \WP_Error( 'cf_ai_api', self::api_error( 'overloaded_error' === $etype ? 529 : 500, $ev ) );
			}
		}
		$msg['content'][] = array( 'type' => 'text', 'text' => $text );
		return $msg;
	}

	/**
	 * Small round trip for the settings page: is the key valid and the API reachable?
	 *
	 * @return array|\WP_Error { seconds, model }
	 */
	public static function ping() {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'cf_ai_key', __( 'Add your Anthropic API key first.', 'checkoutflow' ) );
		}
		$start = microtime( true );
		$res   = self::request(
			array(
				'model'         => self::model(),
				'max_tokens'    => 64,
				'messages'      => array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ),
				'output_config' => array( 'effort' => 'low' ),
			),
			60
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'seconds' => round( microtime( true ) - $start, 1 ), 'model' => $res['model'] );
	}

	private static function api_error( $code, $data ) {
		$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
		if ( 401 === $code ) {
			return __( 'The Anthropic API key was rejected. Check it in Settings → Email & SMTP.', 'checkoutflow' );
		}
		if ( 429 === $code || 529 === $code ) {
			return __( 'The AI service is busy right now. Please try again in a minute.', 'checkoutflow' );
		}
		/* translators: 1: HTTP status, 2: error message */
		return sprintf( __( 'AI request failed (%1$d): %2$s', 'checkoutflow' ), $code, '' !== $msg ? $msg : __( 'unknown error', 'checkoutflow' ) );
	}
}
