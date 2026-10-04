<?php
/**
 * SMTP transport (configures WordPress' bundled PHPMailer) and the low-level send.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Mail;

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

class SMTP {

	/** @var bool True while CheckoutFlow itself is sending. */
	private static $sending = false;

	/** @var string Plain-text alternative for the message being sent. */
	private static $alt_body = '';

	/** @var string Last wp_mail error. */
	private static $last_error = '';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 999 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );

		if ( Settings::flag( 'smtp_enabled' ) && Settings::flag( 'smtp_all_mail' ) ) {
			add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ), 999 );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 999 );
		}
	}

	private static function applies() {
		return Settings::get( 'smtp_enabled' ) && ( self::$sending || Settings::get( 'smtp_all_mail' ) );
	}

	public static function password() {
		if ( defined( 'CHECKOUTFLOW_SMTP_PASSWORD' ) ) {
			return (string) CHECKOUTFLOW_SMTP_PASSWORD;
		}
		return Settings::decrypt( Settings::get( 'smtp_password' ) );
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 */
	public static function configure( $mailer ) {
		if ( self::$sending && '' !== self::$alt_body ) {
			$mailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		if ( ! self::applies() || '' === (string) Settings::get( 'smtp_host' ) ) {
			return;
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName
		$mailer->isSMTP();
		$mailer->Host    = Settings::get( 'smtp_host' );
		$mailer->Port    = (int) Settings::get( 'smtp_port' );
		$mailer->Timeout = 15;

		$enc = Settings::get( 'smtp_encryption' );
		if ( 'none' === $enc ) {
			$mailer->SMTPSecure  = '';
			$mailer->SMTPAutoTLS = false;
		} else {
			$mailer->SMTPSecure = $enc;
		}

		$mailer->SMTPAuth = (bool) Settings::get( 'smtp_auth' );
		if ( $mailer->SMTPAuth ) {
			$mailer->Username = Settings::get( 'smtp_username' );
			$mailer->Password = self::password();
		}

		// Many providers reject a From that doesn't match the account; keep Sender aligned for SPF.
		$mailer->Sender = $mailer->From;
		// phpcs:enable
	}

	/**
	 * Another plugin that sends WordPress email its own way (an API mailer or a
	 * replaced wp_mail()), which bypasses these SMTP settings.
	 *
	 * @return string Plugin name, or '' when none is detected.
	 */
	public static function other_mailer() {
		if ( function_exists( 'wp_mail_smtp' ) ) {
			return 'WP Mail SMTP';
		}
		if ( defined( 'FLUENTMAIL' ) || defined( 'FLUENTMAIL_PLUGIN_FILE' ) ) {
			return 'FluentSMTP';
		}
		if ( class_exists( 'PostmanWpMail' ) || defined( 'POST_SMTP_VER' ) ) {
			return 'Post SMTP';
		}
		if ( class_exists( 'EasyWPSMTP' ) || defined( 'EasyWPSMTP_PLUGIN_VERSION' ) ) {
			return 'Easy WP SMTP';
		}
		if ( function_exists( 'wp_mail' ) ) {
			$file = wp_normalize_path( (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName() );
			if ( false === strpos( $file, wp_normalize_path( ABSPATH . WPINC ) ) && preg_match( '#/(?:mu-)?plugins/([^/]+)#', $file, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	public static function from_email( $email ) {
		$from = Settings::get( 'from_email' );
		return is_email( $from ) ? $from : $email;
	}

	public static function from_name( $name ) {
		$from = Settings::get( 'from_name' );
		return '' !== (string) $from ? $from : $name;
	}

	/**
	 * @param \WP_Error $error Error.
	 */
	public static function capture_error( $error ) {
		self::$last_error = $error->get_error_message();
	}

	public static function last_error() {
		return self::$last_error;
	}

	/**
	 * Send one HTML email.
	 *
	 * @param string $to       Recipient.
	 * @param string $subject  Subject.
	 * @param string $html     Full HTML document.
	 * @param array  $headers  Extra "Name: value" headers.
	 * @return bool
	 */
	public static function send( $to, $subject, $html, $headers = array() ) {
		$from_name  = wp_specialchars_decode( self::from_name( get_bloginfo( 'name' ) ), ENT_QUOTES );
		$from_email = self::from_email( get_option( 'admin_email' ) );

		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$headers[] = sprintf( 'From: %s <%s>', str_replace( array( '"', '<', '>' ), '', $from_name ), $from_email );
		$reply     = Settings::get( 'reply_to' );
		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		self::$sending    = true;
		self::$alt_body   = self::to_text( $html );
		self::$last_error = '';

		$ok = wp_mail( $to, $subject, $html, $headers );

		self::$sending  = false;
		self::$alt_body = '';
		return (bool) $ok;
	}

	/**
	 * Plain-text version (improves deliverability and accessibility).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function to_text( $html ) {
		$html = preg_replace( '#<(head|style|script)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html );
		$html = preg_replace( '#<(br|/p|/h[1-6]|/tr|/li|/div)[^>]*>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $text );
		return trim( $text );
	}
}
