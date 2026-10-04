<?php
/**
 * Bootstrap: loads only what's needed for the current request.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Runs every minute via WP-Cron: abandonment, campaigns, queue. */
	const TICK_HOOK = 'checkoutflow_tick';

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Updates keep flowing even while WooCommerce is inactive.
		require_once CHECKOUTFLOW_DIR . 'includes/class-updater.php';
		Updater::init();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		DB::maybe_upgrade();

		require_once CHECKOUTFLOW_DIR . 'includes/helpers.php';
		require_once CHECKOUTFLOW_DIR . 'includes/class-thankyou.php';

		if ( Settings::flag( 'checkout_enabled' ) ) {
			require_once CHECKOUTFLOW_DIR . 'includes/class-checkout.php';
			new Checkout();
			if ( Settings::flag( 'ty_enabled' ) ) {
				// Funnel plugins (FunnelKit) redirect to their own thank-you page; use ours instead.
				add_filter( 'woocommerce_get_checkout_order_received_url', array( 'CheckoutFlow\\Thank_You', 'own_received_url' ), 1000, 2 );
			}
		}
		if ( Settings::flag( 'cart_enabled' ) ) {
			require_once CHECKOUTFLOW_DIR . 'includes/class-side-cart.php';
			new Side_Cart();
		}

		// Mail + marketing: cheap to load (hooks only); the heavy work runs in the cron tick.
		foreach ( array( 'mail/class-smtp', 'mail/class-merge-tags', 'mail/class-renderer', 'mail/class-coupons', 'mail/class-queue', 'marketing/class-contacts', 'marketing/class-automations', 'marketing/class-campaigns', 'recovery/class-recovery' ) as $file ) {
			require_once CHECKOUTFLOW_DIR . 'includes/' . $file . '.php';
		}
		Mail\SMTP::init();
		Mail\Queue::init();
		Marketing\Contacts::init();
		Marketing\Automations::init();

		require_once CHECKOUTFLOW_DIR . 'includes/class-discounts.php';
		Discounts::init();
		new Recovery\Recovery( (bool) Settings::flag( 'recovery_enabled' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );

		if ( is_admin() ) {
			require_once CHECKOUTFLOW_DIR . 'includes/admin/class-admin.php';
			new Admin\Admin();
			add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ) );
			add_action( 'admin_init', array( 'CheckoutFlow\\Settings', 'import_funnelkit' ) );
		}
	}

	public static function cron_schedules( $schedules ) {
		$schedules['checkoutflow_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (CheckoutFlow)', 'checkoutflow' ),
		);
		return $schedules;
	}

	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 30, 'checkoutflow_minute', self::TICK_HOOK );
		}
	}

	/**
	 * The one background job. Each step is a cheap indexed query when there's nothing to do.
	 */
	public static function tick() {
		if ( get_transient( 'checkoutflow_tick_lock' ) ) {
			return;
		}
		set_transient( 'checkoutflow_tick_lock', 1, 2 * MINUTE_IN_SECONDS );

		Recovery\Recovery::mark_abandoned();
		Marketing\Campaigns::dispatch_due();

		if ( get_option( 'checkoutflow_daily' ) !== gmdate( 'Y-m-d' ) ) {
			update_option( 'checkoutflow_daily', gmdate( 'Y-m-d' ), false );
			Marketing\Automations::run_daily();
			Recovery\Recovery::cleanup();
			Mail\Coupons::cleanup();
		}

		Mail\Queue::process();
		delete_transient( 'checkoutflow_tick_lock' );
	}

	public static function activate() {
		DB::install();

		// Seed the abandoned cart automation (paused until SMTP is configured and it's switched on).
		global $wpdb;
		$has = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::t( 'automations' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $has && class_exists( 'WooCommerce' ) ) {
			foreach ( array( 'mail/class-renderer', 'mail/class-queue', 'marketing/class-contacts', 'marketing/class-automations' ) as $file ) {
				require_once CHECKOUTFLOW_DIR . 'includes/' . $file . '.php';
			}
			Marketing\Automations::create_from_recipe( 'cart', 'paused' );
		}
		self::ensure_schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		// Legacy Action Scheduler hook from early builds.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'checkoutflow_recovery_tick' );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'checkoutflow', false, dirname( plugin_basename( CHECKOUTFLOW_FILE ) ) . '/languages' );
	}

	public function missing_wc_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'CheckoutFlow requires WooCommerce to be installed and active.', 'checkoutflow' ) . '</p></div>';
	}
}
