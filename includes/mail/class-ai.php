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
	 * @return string|\WP_Error Job ID.
	 */
	public static function start_job( $args ) {
		$id    = wp_generate_uuid4();
		$token = wp_generate_password( 32, false );
		self::cleanup_jobs();
		$saved = self::put_job(
			$id,
			array(
				'status'  => 'pending',
				'user'    => get_current_user_id(),
				'token'   => wp_hash( $token ),
				'args'    => $args,
				'created' => time(),
			)
		);
		if ( ! $saved ) {
			global $wpdb;
			/* translators: %s: database error */
			return new \WP_Error( 'cf_ai_store', sprintf( __( 'Could not start the AI request (database error: %s).', 'checkoutflow' ), $wpdb->last_error ? $wpdb->last_error : __( 'unknown', 'checkoutflow' ) ) );
		}
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

	private static function put_job( $id, $job ) {
		global $wpdb;
		// Direct writes: update_option() may skip the write when a stale cached copy matches.
		$name   = self::JOB . sanitize_key( $id );
		// JSON with \u escapes is plain ASCII, so emoji in the brief or the email can't make
		// the write fail on databases that don't store 4-byte characters.
		$value  = wp_json_encode( $job );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists ) {
			$ok = false !== $wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$ok = false !== $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'off' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_cache_delete( $name, 'options' );
		return $ok && false !== $value;
	}

	/**
	 * Remove finished or abandoned jobs older than an hour.
	 */
	private static function cleanup_jobs() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200", $wpdb->esc_like( self::JOB ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$job = json_decode( $row->option_value, true );
			if ( ! is_array( $job ) || empty( $job['created'] ) || $job['created'] < time() - HOUR_IN_SECONDS ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $row->option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wp_cache_delete( $row->option_name, 'options' );
			}
		}
	}

	public static function job( $id ) {
		global $wpdb;
		// Read straight from the database: some hosts' object caches drop or serve stale
		// copies of these short-lived rows between the editor's requests.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::JOB . sanitize_key( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$job = null === $raw ? null : json_decode( $raw, true );
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
		self::put_job( $id, $job );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$out = self::generate( $job['args']['brief'], $job['args']['current'], $job['args']['mode'] );
		$job['status'] = is_wp_error( $out ) ? 'error' : 'done';
		$job['result'] = is_wp_error( $out ) ? array( 'message' => $out->get_error_message() ) : $out;
		unset( $job['args'] );
		self::put_job( $id, $job );
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
				'width'         => array( 'type' => 'integer' ),
				'ids'           => array( 'type' => 'string' ),
				'columns'       => array( 'type' => 'integer' ),
				'button_text'   => array( 'type' => 'string' ),
				'discount_type' => array( 'type' => 'string', 'enum' => array( 'percent', 'fixed_cart' ) ),
				'amount'        => array( 'type' => 'number' ),
				'days'          => array( 'type' => 'integer' ),
				'free_shipping' => array( 'type' => 'boolean' ),
				'_bg'           => array( 'type' => 'string' ),
				'_pt'           => array( 'type' => 'integer' ),
				'_pb'           => array( 'type' => 'integer' ),
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
				'settings'  => array(
					'type'       => 'object',
					'properties' => array(
						'bg'         => array( 'type' => 'string', 'description' => 'Page background hex' ),
						'content_bg' => array( 'type' => 'string', 'description' => 'Email body background hex' ),
						'accent'     => array( 'type' => 'string', 'description' => 'Buttons and links hex' ),
						'text_color' => array( 'type' => 'string', 'description' => 'Body text hex' ),
						'font'       => array( 'type' => 'string', 'enum' => array( 'Helvetica, Arial, sans-serif', 'Georgia, "Times New Roman", serif', '"Trebuchet MS", Tahoma, sans-serif', 'Verdana, Geneva, sans-serif' ) ),
					),
				),
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
			$image   = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'large' ) : '';
			$lines[] = $p->get_id() . ' | ' . $p->get_name() . ' | ' . $price . ' | ' . ( $p->is_in_stock() ? 'in stock' : 'out of stock' ) . ' | ' . ( $image ? $image : 'no image' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Recent Media Library images (banners, lifestyle shots) the AI may use.
	 */
	private static function media() {
		$lines = array();
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ),
				'post_status'    => 'inherit',
				'posts_per_page' => 40,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $posts as $att ) {
			$meta = wp_get_attachment_metadata( $att->ID );
			$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( ( $w && $w < 400 ) || false !== strpos( (string) get_attached_file( $att->ID ), 'woocommerce-placeholder' ) ) {
				continue; // Icons, thumbnails and the "no image" placeholder.
			}
			$alt     = trim( (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true ) );
			$lines[] = wp_get_attachment_image_url( $att->ID, 'large' ) . ' | ' . ( $w && $h ? $w . 'x' . $h : '' ) . ' | ' . ( '' !== $alt ? $alt : $att->post_title );
		}
		return implode( "\n", array_slice( $lines, 0, 25 ) );
	}

	private static function system_prompt() {
		$tags = array();
		foreach ( Merge_Tags::reference() as $tag => $label ) {
			$tags[] = '{' . $tag . '} - ' . $label;
		}
		$brand = trim( (string) Settings::get( 'ai_brand' ) );

		$d      = Renderer::default_settings();
		$prompt = 'You are the creative director and copywriter for the WooCommerce store "' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '" (' . home_url( '/' ) . '). You design marketing emails in the store\'s drag-and-drop email builder: '
			. "eye-catching, on-brand, the kind of email people actually stop and read. Respond with the subject line, the preview text (shown after the subject in the inbox, under 110 characters), an optional color scheme, and the email as an ordered list of builder blocks.\n\n"
			. "Block types and the fields they use:\n"
			. "- heading: text, size (px, 18-48), align, color (hex)\n"
			. "- text: html, align. Allowed tags: <p>, <strong>, <em>, <a href>, <br>, <ul>/<ol>/<li>, <span style> and <div style>; inline styles may use color, background-color, font-size, font-weight, letter-spacing, text-transform, text-align, padding, border, border-radius (good for badges, ribbons, callout boxes and price tags)\n"
			. "- logo: align (the store logo)\n"
			. "- list: items (one per line), style (bullet|number|check), align\n"
			. "- button: text, url, align, color (hex background)\n"
			. "- image: src (an image URL from the catalog or image library below, or one given in the request; never invent URLs), alt, url (link when clicked), width (percent of the email width, 30-100)\n"
			. "- divider: color; spacer: height (px)\n"
			. "- products: ids (comma-separated catalog IDs), columns (1-3), button_text (renders each product's photo, name, price and a buy button)\n"
			. "- coupon: discount_type (percent|fixed_cart), amount, days (valid for), free_shipping, text (line above the code). A unique code is generated per recipient; refer to it with {coupon_code}, {coupon_amount}, {coupon_expiry}.\n"
			. "- footer: html (usually omit; an address and unsubscribe link are added automatically)\n"
			. "- columns: layout (50-50|33-67|67-33|33-33-33), cols (one block list per column; no columns inside columns)\n"
			. "Every block may also set _bg (hex background for its full-width band) and _pt/_pb (top/bottom padding in px, 0-120). Consecutive blocks with the same _bg read as one colored section.\n\n"
			. "Merge tags you can put in text, headings and URLs:\n" . implode( "\n", $tags ) . "\n\n"
			. "Design it like a professional email designer would:\n"
			. "- Open with a bold hero band: the logo, then a big headline (36-44px) in a light color on a rich colored _bg with generous padding, a one-line subhead and a button, all in the same band. Follow it with a striking image (a product photo or library image) when one fits.\n"
			. "- Add personality with design details made from the blocks above: an urgency ribbon (e.g. a narrow full-width band with small, bold, uppercase, letter-spaced text), a callout box for the offer, price or badge spans, a check-mark list of benefits, two-column sections pairing a product photo with copy.\n"
			. "- Feature 2-4 relevant products with a products block or image + text columns whenever the email is about products or a sale. Add a coupon block when the request mentions a discount code.\n"
			. "- Write 150-250 words of vivid, specific, benefit-led copy across the sections, a single clear call to action repeated at most twice (url {shop_url} unless a specific link is given), and a warm one-line sign-off.\n"
			. "- Pick a cohesive palette that suits the occasion (e.g. greens for St. Patrick's Day, deep blue and gold for a premium launch) and return it in settings; keep text contrast readable (dark text on light backgrounds, white on dark). The store's current colors are accent " . $d['accent'] . ', page background ' . $d['bg'] . ", which you may keep or refine.\n"
			. "- One or two fitting emoji in the subject or headline are fine; never in every line.\n"
			. "- Personalise with {first_name} only where it reads naturally with an empty name too. Only feature products from the catalog, by ID, and only use image URLs listed below. Never invent prices, discounts, dates, testimonials or claims the request does not give.";

		if ( '' !== $brand ) {
			$prompt .= "\n\nStore rules (always follow):\n" . $brand;
		}
		$catalog = self::catalog();
		if ( '' !== $catalog ) {
			$prompt .= "\n\nCatalog (ID | name | price | stock | photo URL):\n" . $catalog;
		}
		$media = self::media();
		if ( '' !== $media ) {
			$prompt .= "\n\nImage library (URL | size | description):\n" . $media;
		}
		// The block shape is given as instructions: as an enforced response format it is
		// too large for the API's schema limits (every optional field multiplies it).
		$prompt .= "\n\nReply with only a JSON object, no other text or code fences, matching this JSON Schema:\n" . wp_json_encode( self::schema() )
			. "\nOmit fields a block doesn't use. Example: {\"subject\":\"…\",\"preheader\":\"…\",\"blocks\":[{\"type\":\"logo\"},{\"type\":\"heading\",\"text\":\"…\",\"size\":28,\"align\":\"center\"},{\"type\":\"text\",\"html\":\"<p>…</p>\"},{\"type\":\"button\",\"text\":\"Shop now\",\"url\":\"{shop_url}\",\"align\":\"center\"}]}";
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
			'max_tokens'    => 8000,
			'system'        => self::system_prompt(),
			'messages'      => array( array( 'role' => 'user', 'content' => $user ) ),
			'output_config' => array(
				'effort' => 'medium',
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
		$out = self::parse_json( $text );
		if ( ! is_array( $out ) || ! isset( $out['blocks'] ) ) {
			return new \WP_Error( 'cf_ai_parse', __( 'The AI reply could not be read. Please try again.', 'checkoutflow' ) );
		}
		return self::clean( $out );
	}

	/**
	 * The JSON object in a reply, tolerating code fences or a stray sentence around it.
	 *
	 * @param string $text Reply text.
	 * @return array|null
	 */
	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$out  = json_decode( $text, true );
		if ( is_array( $out ) ) {
			return $out;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$out = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $out ) ? $out : null;
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
		$given  = isset( $out['settings'] ) && is_array( $out['settings'] ) ? array_intersect_key( $out['settings'], array_flip( array( 'bg', 'content_bg', 'accent', 'text_color', 'font' ) ) ) : array();
		$design = Renderer::sanitize( array( 'blocks' => $fill( $out['blocks'], true ), 'settings' => $given ) );

		return array(
			'subject'   => sanitize_text_field( isset( $out['subject'] ) ? (string) $out['subject'] : '' ),
			'preheader' => sanitize_text_field( isset( $out['preheader'] ) ? (string) $out['preheader'] : '' ),
			'blocks'    => $design['blocks'],
			// Only the colors the AI chose, after sanitizing (invalid ones fall back and are dropped).
			'settings'  => array_intersect_key( $design['settings'], array_filter( $given ) ),
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
