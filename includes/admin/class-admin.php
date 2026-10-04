<?php
/**
 * Admin: menu, pages, form handlers, AJAX (builder preview, test sends, audience count).
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Admin;

use CheckoutFlow\DB;
use CheckoutFlow\Settings;
use CheckoutFlow\Thank_You;
use CheckoutFlow\Mail\SMTP;
use CheckoutFlow\Mail\Renderer;
use CheckoutFlow\Mail\Merge_Tags;
use CheckoutFlow\Mail\Coupons;
use CheckoutFlow\Mail\Templates;
use CheckoutFlow\Marketing\Contacts;
use CheckoutFlow\Marketing\Campaigns;
use CheckoutFlow\Marketing\Automations;

defined( 'ABSPATH' ) || exit;

class Admin {

	const CAP = 'manage_woocommerce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		$posts = array( 'save_discount', 'discount_action', 'save_settings', 'test_email', 'save_campaign', 'campaign_action', 'new_automation', 'save_automation', 'automation_action', 'save_email', 'reco_rebuild', 'reco_add_rule', 'contact_action', 'import_contacts', 'export_contacts', 'cart_action' );
		foreach ( $posts as $action ) {
			add_action( 'admin_post_cf_' . $action, array( $this, 'post_' . $action ) );
		}
		foreach ( array( 'preview', 'send_test', 'audience_count', 'ty_preview', 'autosave_email', 'ty_autosave' ) as $action ) {
			add_action( 'wp_ajax_cf_' . $action, array( $this, 'ajax_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( CHECKOUTFLOW_FILE ), array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'checkoutflow' ) . '</a>' );
		return $links;
	}

	public static function url( $page = '', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'checkoutflow' . ( $page ? '-' . $page : '' ) ), $args ), admin_url( 'admin.php' ) );
	}

	public function menu() {
		add_menu_page( 'CheckoutFlow', 'CheckoutFlow', self::CAP, 'checkoutflow', array( $this, 'page_dashboard' ), 'dashicons-email-alt', 56 );
		add_submenu_page( 'checkoutflow', __( 'Dashboard', 'checkoutflow' ), __( 'Dashboard', 'checkoutflow' ), self::CAP, 'checkoutflow', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'checkoutflow', __( 'Campaigns', 'checkoutflow' ), __( 'Campaigns', 'checkoutflow' ), self::CAP, 'checkoutflow-campaigns', array( $this, 'page_campaigns' ) );
		add_submenu_page( 'checkoutflow', __( 'Automations', 'checkoutflow' ), __( 'Automations', 'checkoutflow' ), self::CAP, 'checkoutflow-automations', array( $this, 'page_automations' ) );
		add_submenu_page( 'checkoutflow', __( 'Contacts', 'checkoutflow' ), __( 'Contacts', 'checkoutflow' ), self::CAP, 'checkoutflow-contacts', array( $this, 'page_contacts' ) );
		add_submenu_page( 'checkoutflow', __( 'Discounts', 'checkoutflow' ), __( 'Discounts', 'checkoutflow' ), self::CAP, 'checkoutflow-discounts', array( $this, 'page_discounts' ) );
		add_submenu_page( 'checkoutflow', __( 'Upsells', 'checkoutflow' ), __( 'Upsells', 'checkoutflow' ), self::CAP, 'checkoutflow-upsells', array( $this, 'page_upsells' ) );
		add_submenu_page( 'checkoutflow', __( 'Abandoned Carts', 'checkoutflow' ), __( 'Abandoned Carts', 'checkoutflow' ), self::CAP, 'checkoutflow-carts', array( $this, 'page_carts' ) );
		add_submenu_page( 'checkoutflow', __( 'Thank You Page', 'checkoutflow' ), __( 'Thank You Page', 'checkoutflow' ), self::CAP, 'checkoutflow-thankyou', array( $this, 'page_thankyou' ) );
		add_submenu_page( 'checkoutflow', __( 'Settings', 'checkoutflow' ), __( 'Settings', 'checkoutflow' ), self::CAP, 'checkoutflow-settings', array( $this, 'page_settings' ) );
		// Hidden: email editor.
		$hook = add_submenu_page( '', __( 'Edit email', 'checkoutflow' ), '', self::CAP, 'checkoutflow-email', array( $this, 'page_email' ) );
		// Hidden pages have no menu title for admin-header.php to find; set one.
		add_action(
			'load-' . $hook,
			static function () {
				$GLOBALS['title'] = __( 'Edit email', 'checkoutflow' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'checkoutflow' ) ) {
			return;
		}
		list( $css, $ver ) = \CheckoutFlow\asset( 'css/admin.css' );
		wp_enqueue_style( 'checkoutflow-admin', $css, array(), $ver );
		list( $js, $ver ) = \CheckoutFlow\asset( 'js/admin.js' );
		wp_enqueue_script( 'checkoutflow-admin', $js, array(), $ver, true );
		wp_localize_script(
			'checkoutflow-admin',
			'checkoutflowAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'checkoutflow-admin' ),
			)
		);

		if ( false !== strpos( $hook, 'checkoutflow-thankyou' ) ) {
			$this->editor_assets( $this->thankyou_editor_config() );
		} elseif ( false !== strpos( $hook, 'checkoutflow-email' ) ) {
			$target = self::email_target( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $target ) {
				$this->editor_assets( $this->email_editor_config( $target ) );
			}
		}
	}

	/**
	 * Labels shared by both editors.
	 */
	private static function editor_i18n() {
		return array(
			'back'           => __( 'Back', 'checkoutflow' ),
			'preview'        => __( 'Preview', 'checkoutflow' ),
			'subject'        => __( 'Add a subject', 'checkoutflow' ),
			'preheader'      => __( 'Add preview text (shown after the subject in the inbox)', 'checkoutflow' ),
			'undo'           => __( 'Undo', 'checkoutflow' ),
			'redo'           => __( 'Redo', 'checkoutflow' ),
			'desktop'        => __( 'Desktop', 'checkoutflow' ),
			'mobile'         => __( 'Mobile', 'checkoutflow' ),
			'templates'      => __( 'Templates', 'checkoutflow' ),
			'sendTest'       => __( 'Send test', 'checkoutflow' ),
			'sendTo'         => __( 'Send a test to', 'checkoutflow' ),
			'send'           => __( 'Send', 'checkoutflow' ),
			'testNote'       => __( 'Tests use sample cart/order data and a sample coupon code.', 'checkoutflow' ),
			'failed'         => __( 'Request failed', 'checkoutflow' ),
			'view'           => __( 'View live', 'checkoutflow' ),
			'save'           => __( 'Save', 'checkoutflow' ),
			'saving'         => __( 'Saving…', 'checkoutflow' ),
			'unsaved'        => __( 'Unsaved changes', 'checkoutflow' ),
			'savedNow'       => __( 'Saved just now', 'checkoutflow' ),
			/* translators: %d: seconds */
			'savedSecs'      => __( 'Last saved %d secs ago', 'checkoutflow' ),
			/* translators: %d: minutes */
			'savedMins'      => __( 'Last saved %d min ago', 'checkoutflow' ),
			'saveFailed'     => __( 'Not saved', 'checkoutflow' ),
			'replaceConfirm' => __( 'Replace the current content with this template?', 'checkoutflow' ),
			'close'          => __( 'Close', 'checkoutflow' ),
			'blocks'         => __( 'Blocks', 'checkoutflow' ),
			'structure'      => __( 'Structure', 'checkoutflow' ),
			'layouts'        => __( 'Layouts', 'checkoutflow' ),
			'design'         => __( 'Design', 'checkoutflow' ),
			'general'        => __( 'General', 'checkoutflow' ),
			'dragTip'        => __( 'Drag a block onto the preview, or click it to add it after the selected block.', 'checkoutflow' ),
			'structureTip'   => __( 'Add a row with columns, then drag blocks into each column. Columns stack on phones.', 'checkoutflow' ),
			'layoutsTip'     => __( 'Ready-made sections. Drag one onto the preview or click to add it.', 'checkoutflow' ),
			'mergeTags'      => __( 'Merge tags', 'checkoutflow' ),
			'chooseImage'    => __( 'Choose image', 'checkoutflow' ),
			'colorDefault'   => __( 'Default', 'checkoutflow' ),
			'blockStyle'     => __( 'Spacing & background', 'checkoutflow' ),
			'duplicate'      => __( 'Duplicate', 'checkoutflow' ),
			'remove'         => __( 'Delete', 'checkoutflow' ),
			'moveUp'         => __( 'Move up', 'checkoutflow' ),
			'moveDown'       => __( 'Move down', 'checkoutflow' ),
			'dragToMove'     => __( 'Drag to move', 'checkoutflow' ),
			'linkPrompt'     => __( 'Link URL (tags like {shop_url} work too):', 'checkoutflow' ),
			'insertTag'      => __( '{ } Insert tag', 'checkoutflow' ),
			'empty'          => __( 'Drag blocks here from the left, or pick a template.', 'checkoutflow' ),
			'lockedNote'     => __( 'Sent – read only', 'checkoutflow' ),
		);
	}

	/**
	 * Field labels shared by both editors.
	 */
	private static function editor_labels() {
		return array(
			'text'          => __( 'Text', 'checkoutflow' ),
			'html'          => __( 'Content', 'checkoutflow' ),
			'size'          => __( 'Font size (px)', 'checkoutflow' ),
			'align'         => __( 'Alignment', 'checkoutflow' ),
			'url'           => __( 'Link URL', 'checkoutflow' ),
			'color'         => __( 'Color', 'checkoutflow' ),
			'src'           => __( 'Image URL', 'checkoutflow' ),
			'src_help'      => __( 'Site logo: leave empty to use the logo from the Design tab or your theme.', 'checkoutflow' ),
			'alt'           => __( 'Alt text', 'checkoutflow' ),
			'width'         => __( 'Width', 'checkoutflow' ),
			'height'        => __( 'Height (px)', 'checkoutflow' ),
			'items'         => __( 'Items (one per line)', 'checkoutflow' ),
			'style'         => __( 'Style', 'checkoutflow' ),
			'links'         => __( 'Links (one per line: Label|URL)', 'checkoutflow' ),
			'facebook'      => 'Facebook',
			'instagram'     => 'Instagram',
			'x'             => 'X (Twitter)',
			'youtube'       => 'YouTube',
			'tiktok'        => 'TikTok',
			'linkedin'      => 'LinkedIn',
			'ids'           => __( 'Product IDs (comma separated; empty = best sellers)', 'checkoutflow' ),
			'columns'       => __( 'Columns', 'checkoutflow' ),
			'layout'        => __( 'Columns', 'checkoutflow' ),
			'button_text'   => __( 'Button text (empty = no button)', 'checkoutflow' ),
			'discount_type' => __( 'Discount type', 'checkoutflow' ),
			'amount'        => __( 'Amount', 'checkoutflow' ),
			'days'          => __( 'Valid for (days)', 'checkoutflow' ),
			'free_shipping' => __( 'Also grant free shipping', 'checkoutflow' ),
			'title'         => __( 'Title', 'checkoutflow' ),
			'email'         => __( 'Support email', 'checkoutflow' ),
			'phone'         => __( 'Support phone', 'checkoutflow' ),
			'billing'       => __( 'Billing address', 'checkoutflow' ),
			'show_contact'  => __( 'Show email and phone', 'checkoutflow' ),
			'show_images'   => __( 'Show product images', 'checkoutflow' ),
			'show_totals'   => __( 'Show totals', 'checkoutflow' ),
			'show_date'     => __( 'Show date', 'checkoutflow' ),
			'show_total'    => __( 'Show total', 'checkoutflow' ),
			'show_payment'  => __( 'Show payment method', 'checkoutflow' ),
			'show_email'    => __( 'Show email', 'checkoutflow' ),
			'_bg'           => __( 'Background', 'checkoutflow' ),
			'_pt'           => __( 'Space above (px)', 'checkoutflow' ),
			'_pb'           => __( 'Space below (px)', 'checkoutflow' ),
			'bg'            => __( 'Background', 'checkoutflow' ),
			'content_bg'    => __( 'Content background', 'checkoutflow' ),
			'accent'        => __( 'Accent / buttons', 'checkoutflow' ),
			'text_color'    => __( 'Text color', 'checkoutflow' ),
			'font'          => __( 'Font', 'checkoutflow' ),
			'logo'          => __( 'Logo URL', 'checkoutflow' ),
			'page_bg'       => __( 'Page background', 'checkoutflow' ),
			'card_bg'       => __( 'Card background', 'checkoutflow' ),
		);
	}

	private static function editor_types( $types ) {
		$out = array();
		foreach ( $types as $key => $t ) {
			$out[ $key ] = array(
				'label'   => $t['label'],
				'props'   => $t['props'],
				'group'   => isset( $t['group'] ) ? $t['group'] : 'general',
				'dynamic' => ! empty( $t['dynamic'] ),
				'options' => isset( $t['options'] ) ? $t['options'] : new \stdClass(),
			);
		}
		return $out;
	}

	private static function editor_sections( $sections ) {
		$out = array();
		foreach ( $sections as $key => $sec ) {
			$out[] = array( 'key' => $key, 'label' => $sec[0], 'blocks' => $sec[1] );
		}
		return $out;
	}

	private function email_editor_config( $target ) {
		$email     = array_merge( array( 'subject' => '', 'preheader' => '', 'design' => array() ), (array) $target['email'] );
		$templates = array();
		foreach ( Templates::templates() as $key => $t ) {
			$templates[] = array( 'key' => $key, 'label' => $t[0], 'desc' => $t[1], 'blocks' => $t[2] );
		}
		return array(
			'mode'          => 'email',
			'design'        => Renderer::sanitize( $email['design'] ),
			'subject'       => $email['subject'],
			'preheader'     => $email['preheader'],
			'locked'        => (bool) $target['locked'],
			'backUrl'       => $target['back'],
			'testTo'        => wp_get_current_user()->user_email,
			'previewAction' => 'cf_preview',
			'saveAction'    => 'cf_autosave_email',
			'saveData'      => array( 'type' => $target['type'], 'id' => $target['id'], 'step' => $target['step'] ),
			'types'         => self::editor_types( Renderer::block_types() ),
			'groups'        => array( 'general' => __( 'General', 'checkoutflow' ), 'woo' => __( 'WooCommerce', 'checkoutflow' ) ),
			'layouts'       => Renderer::layouts(),
			'sections'      => self::editor_sections( Templates::sections() ),
			'templates'     => $templates,
			'mergeTags'     => Merge_Tags::reference(),
			'canHtml'       => current_user_can( 'unfiltered_html' ),
			'globals'       => array( array( 'bg', 'color' ), array( 'content_bg', 'color' ), array( 'accent', 'color' ), array( 'text_color', 'color' ), array( 'font', 'font' ), array( 'logo', 'image' ) ),
			'fonts'         => array( 'Helvetica, Arial, sans-serif', 'Georgia, "Times New Roman", serif', '"Trebuchet MS", Tahoma, sans-serif', 'Verdana, Geneva, sans-serif', '"Courier New", monospace' ),
			'align'         => array( 'left' => __( 'Left', 'checkoutflow' ), 'center' => __( 'Center', 'checkoutflow' ), 'right' => __( 'Right', 'checkoutflow' ) ),
			'i18n'          => array_merge( self::editor_i18n(), array( 'labels' => self::editor_labels(), 'dynamic' => __( 'Filled automatically when the email is sent.', 'checkoutflow' ) ) ),
		);
	}

	private function thankyou_editor_config() {
		$orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
		return array(
			'mode'          => 'page',
			'title'         => __( 'Thank You Page', 'checkoutflow' ),
			'design'        => Thank_You::design(),
			'locked'        => false,
			'backUrl'       => self::url(),
			'viewUrl'       => $orders ? $orders[0]->get_checkout_order_received_url() : '',
			'previewAction' => 'cf_ty_preview',
			'saveAction'    => 'cf_ty_autosave',
			'saveData'      => array(),
			'toggle'        => array( 'name' => 'ty_enabled', 'label' => __( 'Show after checkout', 'checkoutflow' ), 'checked' => (bool) Settings::get( 'ty_enabled' ) ),
			'types'         => self::editor_types( Thank_You::types() ),
			'groups'        => array( 'general' => __( 'General', 'checkoutflow' ), 'order' => __( 'Order', 'checkoutflow' ) ),
			'layouts'       => Thank_You::layouts(),
			'sections'      => self::editor_sections( Thank_You::sections() ),
			'templates'     => array( array( 'key' => 'default', 'label' => __( 'Default layout', 'checkoutflow' ), 'desc' => __( 'Heading, order summary, payment instructions, items, customer information, support and a button.', 'checkoutflow' ), 'blocks' => Thank_You::default_design()['blocks'] ) ),
			'mergeTags'     => Thank_You::merge_tags(),
			'canHtml'       => current_user_can( 'unfiltered_html' ),
			'globals'       => array( array( 'page_bg', 'color' ), array( 'card_bg', 'color' ), array( 'accent', 'color' ), array( 'text_color', 'color' ), array( 'width', 'number' ) ),
			'align'         => Thank_You::block_types()['_align'],
			'i18n'          => array_merge( self::editor_i18n(), array( 'labels' => self::editor_labels(), 'dynamic' => __( 'Filled in from the customer\'s order.', 'checkoutflow' ) ) ),
		);
	}

	private function editor_assets( $config ) {
		wp_enqueue_media();
		list( $css, $ver ) = \CheckoutFlow\asset( 'css/editor.css' );
		wp_enqueue_style( 'checkoutflow-editor', $css, array(), $ver );
		list( $js, $ver ) = \CheckoutFlow\asset( 'js/editor.js' );
		wp_enqueue_script( 'checkoutflow-editor', $js, array( 'checkoutflow-admin' ), $ver, true );
		wp_localize_script( 'checkoutflow-editor', 'checkoutflowEditor', $config );
	}

	public function page_upsells() {
		$this->view( 'upsells' );
	}

	public function post_reco_rebuild() {
		self::check( 'cf_reco_rebuild' );
		\CheckoutFlow\Recommendations::rebuild();
		$meta = get_option( \CheckoutFlow\Recommendations::META_OPTION, array() );
		/* translators: %s: number of orders */
		self::redirect( self::url( 'upsells' ), sprintf( __( 'Analyzed %s orders.', 'checkoutflow' ), number_format_i18n( isset( $meta['orders'] ) ? $meta['orders'] : 0 ) ) );
	}

	public function post_reco_add_rule() {
		self::check( 'cf_reco_add_rule' );
		$a = isset( $_POST['a'] ) ? wc_get_product( absint( $_POST['a'] ) ) : null;
		$b = isset( $_POST['b'] ) ? wc_get_product( absint( $_POST['b'] ) ) : null;
		if ( ! $a || ! $b ) {
			self::redirect( self::url( 'upsells' ), '!' . __( 'Product not found.', 'checkoutflow' ) );
		}
		$ka     = \CheckoutFlow\Recommendations::keyword( wp_specialchars_decode( $a->get_name(), ENT_QUOTES ) );
		$kb     = \CheckoutFlow\Recommendations::keyword( wp_specialchars_decode( $b->get_name(), ENT_QUOTES ) );
		$values = Settings::all();
		$lines  = array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $values['cart_pairings'] ) ), 'strlen' );
		foreach ( array( $ka . ' => ' . $kb, $kb . ' => ' . $ka ) as $rule ) {
			if ( ! in_array( $rule, $lines, true ) ) {
				array_unshift( $lines, $rule );
			}
		}
		$values['cart_pairings'] = sanitize_textarea_field( implode( "\n", $lines ) );
		Settings::save( $values );
		/* translators: 1: product, 2: product */
		self::redirect( self::url( 'upsells' ), sprintf( __( 'Added rules: %1$s ⇄ %2$s.', 'checkoutflow' ), $ka, $kb ) );
	}

	public function page_thankyou() {
		$this->view( 'thankyou-builder', array( 'design' => Thank_You::design() ) );
	}

	public function ajax_ty_preview() {
		self::ajax_check();
		$design = Thank_You::sanitize( json_decode( isset( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : '', true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_send_json_success( array( 'html' => Thank_You::preview_document( $design ) ) );
	}

	private static function check( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'checkoutflow' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( $url, $notice = '' ) {
		if ( $notice ) {
			$url = add_query_arg( 'cf_notice', rawurlencode( $notice ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function notices() {
		if ( ! empty( $_GET['cf_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg   = sanitize_text_field( wp_unslash( $_GET['cf_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$error = 0 === strpos( $msg, '!' );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $error ? 'error' : 'success', esc_html( ltrim( $msg, '!' ) ) );
		}
	}

	private function view( $name, $vars = array() ) {
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		echo '<div class="wrap cf-wrap">';
		self::notices();
		include CHECKOUTFLOW_DIR . 'includes/admin/views/' . $name . '.php';
		echo '</div>';
	}

	/* ---------- pages ---------- */

	public function page_dashboard() {
		$this->view( 'dashboard' );
	}

	public function page_settings() {
		$tabs = Settings::schema();
		if ( ! current_user_can( 'manage_options' ) ) {
			unset( $tabs['email'] );
		}
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'checkout'; // phpcs:ignore WordPress.Security.NonceVerification
		$this->view( 'settings', array( 'tabs' => $tabs, 'tab' => $tab ) );
	}

	public function page_campaigns() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && ( $campaign = DB::get( 'campaigns', $id ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$this->view( 'campaign-edit', array( 'campaign' => $campaign ) );
			return;
		}
		$this->view( 'campaigns' );
	}

	public function page_automations() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && ( $automation = Automations::get( $id ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$this->view( 'automation-edit', array( 'automation' => $automation ) );
			return;
		}
		$this->view( 'automations' );
	}

	public function page_contacts() {
		$this->view( 'contacts' );
	}

	public function page_discounts() {
		$rules = \CheckoutFlow\Discounts::rules();
		$id    = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $id ) {
			$rule = 'new' === $id ? \CheckoutFlow\Discounts::blank_rule() : null;
			foreach ( $rules as $r ) {
				if ( $r['id'] === $id ) {
					$rule = $r;
				}
			}
			if ( $rule ) {
				$this->view( 'discount-edit', array( 'rule' => $rule, 'is_new' => 'new' === $id ) );
				return;
			}
		}
		$this->view( 'discounts', array( 'rules' => $rules ) );
	}

	public function post_save_discount() {
		self::check( 'cf_save_discount' );
		$raw           = isset( $_POST['rule'] ) ? (array) wp_unslash( $_POST['rule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by sanitize_rule().
		$raw['tiers']  = isset( $raw['tiers'] ) ? array_values( (array) $raw['tiers'] ) : array();
		$raw['enabled'] = ! empty( $raw['enabled'] );
		$rule          = \CheckoutFlow\Discounts::sanitize_rule( $raw );
		$rules         = \CheckoutFlow\Discounts::rules();
		$found         = false;
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] === $rule['id'] ) {
				$rule['note'] = $r['note'];
				$rules[ $i ]  = $rule;
				$found        = true;
			}
		}
		if ( ! $found ) {
			$rules[] = $rule;
		}
		\CheckoutFlow\Discounts::save( $rules );
		self::redirect( self::url( 'discounts' ), $rule['tiers'] ? __( 'Discount saved.', 'checkoutflow' ) : '!' . __( 'Saved, but the rule has no tiers yet so it will not apply.', 'checkoutflow' ) );
	}

	public function post_discount_action() {
		self::check( 'cf_discount_action' );
		$id    = isset( $_POST['rule'] ) ? sanitize_text_field( wp_unslash( $_POST['rule'] ) ) : '';
		$do    = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$rules = \CheckoutFlow\Discounts::rules();
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] !== $id ) {
				continue;
			}
			if ( 'toggle' === $do ) {
				$rules[ $i ]['enabled'] = empty( $r['enabled'] );
			} elseif ( 'delete' === $do ) {
				unset( $rules[ $i ] );
			} elseif ( 'duplicate' === $do ) {
				$copy            = $r;
				$copy['id']      = wp_generate_uuid4();
				$copy['enabled'] = false;
				/* translators: %s: rule title */
				$copy['title']   = sprintf( __( '%s (copy)', 'checkoutflow' ), $r['title'] );
				array_splice( $rules, $i + 1, 0, array( $copy ) );
			} elseif ( in_array( $do, array( 'up', 'down' ), true ) ) {
				$j = 'up' === $do ? $i - 1 : $i + 1;
				if ( isset( $rules[ $j ] ) ) {
					$tmp         = $rules[ $j ];
					$rules[ $j ] = $rules[ $i ];
					$rules[ $i ] = $tmp;
				}
			}
			break;
		}
		\CheckoutFlow\Discounts::save( $rules );
		self::redirect( self::url( 'discounts' ), __( 'Discounts updated.', 'checkoutflow' ) );
	}

	public function page_carts() {
		$this->view( 'carts' );
	}

	/**
	 * Email editor for a campaign or an automation step.
	 */
	public function page_email() {
		$target = self::email_target( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $target ) {
			wp_die( esc_html__( 'Email not found.', 'checkoutflow' ) );
		}
		$this->view( 'email-editor', array( 'target' => $target ) );
	}

	/**
	 * Resolve which stored email a request points at.
	 *
	 * @param array $req Request vars (type, id, step).
	 * @return array|null { type, id, step, email, back, title, context }
	 */
	private static function email_target( $req ) {
		$type = isset( $req['type'] ) ? sanitize_key( $req['type'] ) : '';
		$id   = isset( $req['id'] ) ? absint( $req['id'] ) : 0;
		$step = isset( $req['step'] ) ? absint( $req['step'] ) : 0;

		if ( 'campaign' === $type ) {
			$c = DB::get( 'campaigns', $id );
			if ( ! $c ) {
				return null;
			}
			return array(
				'type'    => 'campaign',
				'id'      => $id,
				'step'    => 0,
				'email'   => DB::json( $c['email'] ),
				'back'    => self::url( 'campaigns', array( 'id' => $id ) ),
				'title'   => $c['name'],
				'trigger' => '',
				'locked'  => ! in_array( $c['status'], array( 'draft', 'scheduled' ), true ),
			);
		}
		if ( 'automation' === $type ) {
			$a = Automations::get( $id );
			if ( ! $a || ! isset( $a['steps'][ $step ] ) ) {
				return null;
			}
			return array(
				'type'    => 'automation',
				'id'      => $id,
				'step'    => $step,
				'email'   => $a['steps'][ $step ]['email'],
				'back'    => self::url( 'automations', array( 'id' => $id ) ),
				/* translators: 1: automation name, 2: step number */
				'title'   => sprintf( __( '%1$s – email %2$d', 'checkoutflow' ), $a['name'], $step + 1 ),
				'trigger' => $a['trigger_type'],
				'locked'  => false,
			);
		}
		return null;
	}

	/* ---------- form handlers ---------- */

	public function post_save_settings() {
		self::check( 'cf_save_settings' );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'checkout';
		if ( 'email' === $tab && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can change email delivery settings.', 'checkoutflow' ), 403 );
		}
		Settings::save( Settings::sanitize( isset( $_POST['cf'] ) ? (array) $_POST['cf'] : array(), $tab ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field by schema.
		self::redirect( self::url( 'settings', array( 'tab' => $tab ) ), __( 'Settings saved.', 'checkoutflow' ) );
	}

	public function post_test_email() {
		self::check( 'cf_test_email' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can change email delivery settings.', 'checkoutflow' ), 403 );
		}
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			self::redirect( self::url( 'settings', array( 'tab' => 'email' ) ), '!' . __( 'Enter a valid email address.', 'checkoutflow' ) );
		}
		$html = Renderer::render(
			Renderer::design(
				array(
					array( 'heading', array( 'text' => __( 'SMTP is working!', 'checkoutflow' ), 'align' => 'center' ) ),
					array( 'text', array( 'html' => '<p>' . __( 'This test email was sent by CheckoutFlow from {site_name}.', 'checkoutflow' ) . '</p>', 'align' => 'center' ) ),
				)
			),
			array( 'preview' => true )
		);
		$ok = SMTP::send( $to, __( 'CheckoutFlow SMTP test', 'checkoutflow' ), $html );
		/* translators: %s: error message */
		$msg = $ok ? __( 'Test email sent. Check your inbox (and spam folder).', 'checkoutflow' ) : '!' . sprintf( __( 'Sending failed: %s', 'checkoutflow' ), SMTP::last_error() ? SMTP::last_error() : __( 'unknown error', 'checkoutflow' ) );
		self::redirect( self::url( 'settings', array( 'tab' => 'email' ) ), $msg );
	}

	public function post_save_email() {
		self::check( 'cf_save_email' );
		$target = self::email_target( $_POST );
		if ( ! $target || $target['locked'] ) {
			wp_die( esc_html__( 'This email can no longer be edited.', 'checkoutflow' ) );
		}
		self::store_email( $target, $_POST );
		$back = ! empty( $_POST['stay'] ) ? add_query_arg( array( 'page' => 'checkoutflow-email', 'type' => $target['type'], 'id' => $target['id'], 'step' => $target['step'] ), admin_url( 'admin.php' ) ) : $target['back'];
		self::redirect( $back, __( 'Email saved.', 'checkoutflow' ) );
	}

	/**
	 * Save subject, preview text and design from a request onto a campaign or automation step.
	 */
	private static function store_email( $target, $req ) {
		$email = array(
			'subject'   => isset( $req['subject'] ) ? sanitize_text_field( wp_unslash( $req['subject'] ) ) : '',
			'preheader' => isset( $req['preheader'] ) ? sanitize_text_field( wp_unslash( $req['preheader'] ) ) : '',
			'design'    => Renderer::sanitize( json_decode( isset( $req['design'] ) ? wp_unslash( $req['design'] ) : '', true ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		if ( 'campaign' === $target['type'] ) {
			DB::update( 'campaigns', $target['id'], array( 'email' => wp_json_encode( $email ), 'updated_at' => DB::now() ) );
		} else {
			$a = Automations::get( $target['id'] );
			$a['steps'][ $target['step'] ]['email'] = $email;
			DB::update( 'automations', $target['id'], array( 'steps' => wp_json_encode( $a['steps'] ), 'updated_at' => DB::now() ) );
		}
	}

	public function ajax_autosave_email() {
		self::ajax_check();
		$target = self::email_target( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in ajax_check()
		if ( ! $target || $target['locked'] ) {
			wp_send_json_error( array( 'message' => __( 'This email can no longer be edited.', 'checkoutflow' ) ), 409 );
		}
		self::store_email( $target, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_send_json_success();
	}

	public function ajax_ty_autosave() {
		self::ajax_check();
		Thank_You::save( json_decode( isset( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : '', true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
		$values               = Settings::all();
		$values['ty_enabled'] = ! empty( $_POST['ty_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification
		Settings::save( $values );
		wp_send_json_success();
	}

	public function post_save_campaign() {
		self::check( 'cf_save_campaign' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			$id = Campaigns::create();
			self::redirect( self::url( 'campaigns', array( 'id' => $id ) ) );
		}
		$campaign = DB::get( 'campaigns', $id );
		if ( ! $campaign || ! in_array( $campaign['status'], array( 'draft', 'scheduled' ), true ) ) {
			self::redirect( self::url( 'campaigns' ), '!' . __( 'This campaign is already sending and cannot be changed.', 'checkoutflow' ) );
		}

		$data = array(
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'audience'   => wp_json_encode( Campaigns::sanitize_audience( isset( $_POST['audience'] ) ? $_POST['audience'] : array() ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'updated_at' => DB::now(),
		);

		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : 'save';
		if ( in_array( $do, array( 'send_now', 'schedule' ), true ) ) {
			$email = DB::json( $campaign['email'] );
			if ( empty( $email['subject'] ) ) {
				DB::update( 'campaigns', $id, $data );
				self::redirect( self::url( 'campaigns', array( 'id' => $id ) ), '!' . __( 'Add a subject line in the email editor before sending.', 'checkoutflow' ) );
			}
			if ( 'schedule' === $do ) {
				$local = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
				$ts    = $local ? strtotime( get_gmt_from_date( str_replace( 'T', ' ', $local ) ) . ' UTC' ) : 0;
				if ( ! $ts || $ts < time() ) {
					DB::update( 'campaigns', $id, $data );
					self::redirect( self::url( 'campaigns', array( 'id' => $id ) ), '!' . __( 'Choose a date and time in the future.', 'checkoutflow' ) );
				}
				$data['scheduled_at'] = gmdate( 'Y-m-d H:i:s', $ts );
			} else {
				$data['scheduled_at'] = DB::now();
			}
			$data['status'] = 'scheduled';
		} elseif ( 'unschedule' === $do ) {
			$data['status']       = 'draft';
			$data['scheduled_at'] = null;
		}
		DB::update( 'campaigns', $id, $data );

		if ( 'send_now' === $do ) {
			// Enqueue immediately rather than waiting for the next cron tick.
			Campaigns::dispatch_due();
			self::redirect( self::url( 'campaigns', array( 'id' => $id ) ), __( 'Campaign is sending.', 'checkoutflow' ) );
		}
		self::redirect( self::url( 'campaigns', array( 'id' => $id ) ), __( 'Campaign saved.', 'checkoutflow' ) );
	}

	public function post_campaign_action() {
		self::check( 'cf_campaign_action' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		global $wpdb;
		if ( 'delete' === $do ) {
			DB::delete( 'campaigns', $id );
			$wpdb->delete( DB::t( 'queue' ), array( 'source' => 'campaign', 'source_id' => $id, 'status' => 'pending' ) );
			self::redirect( self::url( 'campaigns' ), __( 'Campaign deleted.', 'checkoutflow' ) );
		}
		if ( 'cancel' === $do ) {
			DB::update( 'campaigns', $id, array( 'status' => 'cancelled' ) );
			$wpdb->update( DB::t( 'queue' ), array( 'status' => 'cancelled', 'error' => 'campaign cancelled' ), array( 'source' => 'campaign', 'source_id' => $id, 'status' => 'pending' ) );
			self::redirect( self::url( 'campaigns', array( 'id' => $id ) ), __( 'Campaign cancelled. Emails already sent could not be recalled.', 'checkoutflow' ) );
		}
		if ( 'duplicate' === $do ) {
			$c = DB::get( 'campaigns', $id );
			if ( $c ) {
				$new = DB::insert(
					'campaigns',
					array(
						/* translators: %s: campaign name */
						'name'       => sprintf( __( '%s (copy)', 'checkoutflow' ), $c['name'] ),
						'email'      => $c['email'],
						'audience'   => $c['audience'],
						'status'     => 'draft',
						'created_at' => DB::now(),
						'updated_at' => DB::now(),
					)
				);
				self::redirect( self::url( 'campaigns', array( 'id' => $new ) ), __( 'Campaign duplicated.', 'checkoutflow' ) );
			}
		}
		self::redirect( self::url( 'campaigns' ) );
	}

	public function post_new_automation() {
		self::check( 'cf_new_automation' );
		$recipe = isset( $_POST['recipe'] ) ? sanitize_key( $_POST['recipe'] ) : 'blank';
		$id     = Automations::create_from_recipe( $recipe );
		self::redirect( self::url( 'automations', array( 'id' => $id ) ), __( 'Automation created. Review the emails, then activate it.', 'checkoutflow' ) );
	}

	public function post_save_automation() {
		self::check( 'cf_save_automation' );
		$a = Automations::get( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		if ( ! $a ) {
			self::redirect( self::url( 'automations' ) );
		}
		$triggers = Automations::triggers();
		$trigger  = isset( $_POST['trigger_type'], $triggers[ $_POST['trigger_type'] ] ) ? sanitize_key( $_POST['trigger_type'] ) : $a['trigger_type'];

		$settings = array();
		$raw      = isset( $_POST['trigger_settings'] ) ? (array) wp_unslash( $_POST['trigger_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $triggers[ $trigger ]['settings'] as $key => $field ) {
			$v = isset( $raw[ $key ] ) ? $raw[ $key ] : '';
			if ( 'checkbox' === $field['type'] ) {
				$settings[ $key ] = ! empty( $v );
			} elseif ( 'number' === $field['type'] ) {
				$settings[ $key ] = is_numeric( $v ) ? max( 0, $v + 0 ) : $field['default'];
			} else {
				$settings[ $key ] = sanitize_text_field( $v );
			}
		}

		// Steps: timing edits + add/remove.
		$steps     = $a['steps'];
		$posted    = isset( $_POST['steps'] ) ? (array) wp_unslash( $_POST['steps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $steps as $i => $step ) {
			if ( isset( $posted[ $i ] ) ) {
				$steps[ $i ]['delay'] = max( 0, (float) $posted[ $i ]['delay'] );
				$steps[ $i ]['unit']  = in_array( $posted[ $i ]['unit'], array( 'minutes', 'hours', 'days' ), true ) ? $posted[ $i ]['unit'] : 'hours';
			}
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : 'save';
		if ( 0 === strpos( $do, 'remove_step_' ) && count( $steps ) > 1 ) {
			array_splice( $steps, (int) substr( $do, 12 ), 1 );
			// Pending emails reference steps by index; cancel them to avoid sending the wrong step.
			global $wpdb;
			$wpdb->update( DB::t( 'queue' ), array( 'status' => 'cancelled', 'error' => 'automation edited' ), array( 'source' => 'automation', 'source_id' => $a['id'], 'status' => 'pending' ) );
		}
		if ( 'add_step' === $do ) {
			$last    = end( $steps );
			$steps[] = array(
				'delay' => $last ? (float) $last['delay'] + 24 : 1,
				'unit'  => $last ? $last['unit'] : 'hours',
				'email' => array(
					'subject'   => $last ? $last['email']['subject'] : '',
					'preheader' => '',
					'design'    => $last ? $last['email']['design'] : Renderer::design( array( array( 'heading' ), array( 'text' ) ) ),
				),
			);
		}

		$status = isset( $_POST['status'] ) && 'active' === $_POST['status'] ? 'active' : 'paused';
		DB::update(
			'automations',
			$a['id'],
			array(
				'name'             => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : $a['name'],
				'trigger_type'     => $trigger,
				'trigger_settings' => wp_json_encode( (object) $settings ),
				'steps'            => wp_json_encode( array_values( $steps ) ),
				'status'           => $status,
				'updated_at'       => DB::now(),
			)
		);

		$notice = __( 'Automation saved.', 'checkoutflow' );
		if ( 'active' === $status && ! Settings::get( 'smtp_enabled' ) ) {
			$notice = __( 'Automation saved. Tip: configure SMTP under Settings > Email & SMTP for reliable delivery.', 'checkoutflow' );
		}
		self::redirect( self::url( 'automations', array( 'id' => $a['id'] ) ), $notice );
	}

	public function post_automation_action() {
		self::check( 'cf_automation_action' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'delete' === $do ) {
			global $wpdb;
			DB::delete( 'automations', $id );
			$wpdb->update( DB::t( 'queue' ), array( 'status' => 'cancelled', 'error' => 'automation deleted' ), array( 'source' => 'automation', 'source_id' => $id, 'status' => 'pending' ) );
			self::redirect( self::url( 'automations' ), __( 'Automation deleted.', 'checkoutflow' ) );
		}
		if ( in_array( $do, array( 'activate', 'pause' ), true ) ) {
			DB::update( 'automations', $id, array( 'status' => 'activate' === $do ? 'active' : 'paused', 'updated_at' => DB::now() ) );
		}
		self::redirect( self::url( 'automations' ) );
	}

	public function post_contact_action() {
		self::check( 'cf_contact_action' );
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';

		if ( 'add' === $do ) {
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$c     = Contacts::upsert(
				$email,
				array(
					'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
					'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
				),
				'manual'
			);
			if ( ! $c ) {
				self::redirect( self::url( 'contacts' ), '!' . __( 'Invalid email address.', 'checkoutflow' ) );
			}
			if ( ! empty( $_POST['subscribed'] ) ) {
				Contacts::subscribe( $email, true );
			}
			self::redirect( self::url( 'contacts' ), __( 'Contact saved.', 'checkoutflow' ) );
		}

		if ( 'sync_orders' === $do ) {
			Contacts::start_import();
			self::redirect( self::url( 'contacts' ), __( 'Importing customers from past orders in the background. Refresh in a minute.', 'checkoutflow' ) );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		foreach ( $ids as $id ) {
			$c = DB::get( 'contacts', $id );
			if ( ! $c ) {
				continue;
			}
			if ( 'subscribe' === $do ) {
				Contacts::subscribe( $c['email'], true );
			} elseif ( 'unsubscribe' === $do ) {
				Contacts::unsubscribe( $c['email'] );
			} elseif ( 'delete' === $do && 'unsubscribed' !== $c['status'] ) {
				// Unsubscribed contacts are kept as a suppression list so they're never emailed again.
				DB::delete( 'contacts', $id );
			}
		}
		self::redirect( wp_get_referer() ? wp_get_referer() : self::url( 'contacts' ), __( 'Contacts updated.', 'checkoutflow' ) );
	}

	public function post_import_contacts() {
		self::check( 'cf_import_contacts' );
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::redirect( self::url( 'contacts' ), '!' . __( 'Choose a CSV file.', 'checkoutflow' ) );
		}
		$count = Contacts::import_csv( $_FILES['csv']['tmp_name'], ! empty( $_POST['consent'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		/* translators: %d: count */
		self::redirect( self::url( 'contacts' ), sprintf( _n( '%d contact imported.', '%d contacts imported.', $count, 'checkoutflow' ), $count ) );
	}

	public function post_export_contacts() {
		self::check( 'cf_export_contacts' );
		global $wpdb;
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=checkoutflow-contacts-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'email', 'first_name', 'last_name', 'status', 'orders', 'spent', 'last_order_at', 'created_at' ), ',', '"', '\\' );
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT email, first_name, last_name, status, orders, spent, last_order_at, created_at FROM ' . DB::t( 'contacts' ) . ' ORDER BY id LIMIT %d, 1000', $offset ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $rows as $r ) {
				// Neutralize spreadsheet formula injection.
				$r = array_map(
					static function ( $v ) {
						return preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v;
					},
					$r
				);
				fputcsv( $out, $r, ',', '"', '\\' );
			}
			$offset += 1000;
		} while ( count( $rows ) === 1000 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public function post_cart_action() {
		self::check( 'cf_cart_action' );
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		if ( isset( $_POST['do'] ) && 'delete' === $_POST['do'] ) {
			foreach ( $ids as $id ) {
				DB::delete( 'carts', $id );
				\CheckoutFlow\Mail\Queue::cancel_pending( 'cart:' . $id, null, 'cart deleted' );
			}
		}
		self::redirect( wp_get_referer() ? wp_get_referer() : self::url( 'carts' ), __( 'Carts updated.', 'checkoutflow' ) );
	}

	/* ---------- AJAX ---------- */

	private static function ajax_check() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'checkoutflow-admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'checkoutflow' ) ), 403 );
		}
	}

	/**
	 * Preview context with sample data so dynamic blocks show something realistic.
	 */
	private static function preview_ctx( $design, $preheader ) {
		$user = wp_get_current_user();
		$ctx  = array(
			'preview'   => true,
			'preheader' => $preheader,
			'contact'   => array( 'email' => $user->user_email, 'first_name' => $user->first_name, 'last_name' => $user->last_name ),
		);
		$coupon = Renderer::coupon_block( $design );
		if ( $coupon ) {
			$ctx['coupon'] = Coupons::sample( $coupon );
		}
		return $ctx;
	}

	public function ajax_preview() {
		self::ajax_check();
		$design    = Renderer::sanitize( json_decode( isset( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : '', true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$preheader = isset( $_POST['preheader'] ) ? sanitize_text_field( wp_unslash( $_POST['preheader'] ) ) : '';
		wp_send_json_success( array( 'html' => Renderer::render( $design, array_merge( self::preview_ctx( $design, $preheader ), array( 'canvas' => true ) ) ) ) );
	}

	public function ajax_send_test() {
		self::ajax_check();
		$to        = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$design    = Renderer::sanitize( json_decode( isset( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : '', true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$subject   = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$preheader = isset( $_POST['preheader'] ) ? sanitize_text_field( wp_unslash( $_POST['preheader'] ) ) : '';
		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address.', 'checkoutflow' ) ) );
		}
		$ctx  = self::preview_ctx( $design, $preheader );
		$tags = Merge_Tags::values( $ctx );
		$ok   = SMTP::send( $to, '[TEST] ' . wp_specialchars_decode( Merge_Tags::apply( $subject, $tags, 'text' ), ENT_QUOTES ), Renderer::render( $design, $ctx ) );
		if ( $ok ) {
			/* translators: %s: email */
			wp_send_json_success( array( 'message' => sprintf( __( 'Test sent to %s.', 'checkoutflow' ), $to ) ) );
		}
		/* translators: %s: error */
		wp_send_json_error( array( 'message' => sprintf( __( 'Sending failed: %s', 'checkoutflow' ), SMTP::last_error() ) ) );
	}

	public function ajax_audience_count() {
		self::ajax_check();
		wp_send_json_success( array( 'count' => Campaigns::count( isset( $_POST['audience'] ) ? $_POST['audience'] : array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
