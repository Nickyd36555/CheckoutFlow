<?php
/**
 * Starter email templates (whole designs) and layout sections (groups of blocks) for the
 * editor's Templates button and Layouts tab.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow\Mail;

defined( 'ABSPATH' ) || exit;

class Templates {

	/**
	 * Build a block with defaults.
	 *
	 * @param string $type  Type.
	 * @param array  $props Overrides.
	 */
	private static function b( $type, $props = array() ) {
		$types = Renderer::block_types();
		return array_merge( array( 'type' => $type ), $types[ $type ]['props'], $props );
	}

	private static function cols( $layout, $cols ) {
		return array( 'type' => 'columns', 'layout' => $layout, 'cols' => $cols );
	}

	/**
	 * Sections for the Layouts tab: key => [ label, blocks ].
	 */
	public static function sections() {
		$img = array( 'src' => '', 'alt' => '' );
		return array(
			'header_logo'  => array( __( 'Header: logo', 'checkoutflow' ), array( self::b( 'logo' ) ) ),
			'header_menu'  => array( __( 'Header: logo + menu', 'checkoutflow' ), array( self::b( 'logo' ), self::b( 'menu' ), self::b( 'divider' ) ) ),
			'hero'         => array(
				__( 'Hero: heading, text, button', 'checkoutflow' ),
				array(
					self::b( 'heading', array( 'text' => __( 'Big news from {site_name}', 'checkoutflow' ), 'size' => 30, 'align' => 'center' ) ),
					self::b( 'text', array( 'html' => '<p>' . __( 'Tell your customers what is new and why it matters to them.', 'checkoutflow' ) . '</p>', 'align' => 'center' ) ),
					self::b( 'button' ),
				),
			),
			'image_text'   => array(
				__( 'Image + text (2 columns)', 'checkoutflow' ),
				array( self::cols( '50-50', array( array( self::b( 'image', $img ) ), array( self::b( 'heading', array( 'text' => __( 'Feature title', 'checkoutflow' ), 'size' => 20 ) ), self::b( 'text' ), self::b( 'button', array( 'align' => 'left' ) ) ) ) ) ),
			),
			'text_image'   => array(
				__( 'Text + image (2 columns)', 'checkoutflow' ),
				array( self::cols( '50-50', array( array( self::b( 'heading', array( 'text' => __( 'Feature title', 'checkoutflow' ), 'size' => 20 ) ), self::b( 'text' ) ), array( self::b( 'image', $img ) ) ) ) ),
			),
			'grid3'        => array(
				__( 'Image grid (3 across)', 'checkoutflow' ),
				array( self::cols( '33-33-33', array( array( self::b( 'image', $img ) ), array( self::b( 'image', $img ) ), array( self::b( 'image', $img ) ) ) ) ),
			),
			'grid2'        => array(
				__( 'Image grid (2 across)', 'checkoutflow' ),
				array( self::cols( '50-50', array( array( self::b( 'image', $img ) ), array( self::b( 'image', $img ) ) ) ) ),
			),
			'products'     => array( __( 'Product showcase', 'checkoutflow' ), array( self::b( 'heading', array( 'text' => __( 'Customer favorites', 'checkoutflow' ), 'align' => 'center', 'size' => 22 ) ), self::b( 'products', array( 'columns' => 3 ) ) ) ),
			'coupon'       => array( __( 'Coupon offer', 'checkoutflow' ), array( self::b( 'heading', array( 'text' => __( 'A little something for you', 'checkoutflow' ), 'align' => 'center', 'size' => 22 ) ), self::b( 'coupon' ), self::b( 'button', array( 'text' => __( 'Shop now', 'checkoutflow' ) ) ) ) ),
			'footer'       => array( __( 'Footer: social + address', 'checkoutflow' ), array( self::b( 'divider' ), self::b( 'social' ), self::b( 'footer' ) ) ),
		);
	}

	/**
	 * Whole-email templates: key => [ label, description, blocks ].
	 */
	public static function templates() {
		$img = array( 'src' => '', 'alt' => '' );
		return array(
			'blank'      => array( __( 'Blank', 'checkoutflow' ), __( 'Start from scratch.', 'checkoutflow' ), array( self::b( 'logo' ), self::b( 'text' ), self::b( 'footer' ) ) ),
			'sale'       => array(
				__( 'Sale announcement', 'checkoutflow' ),
				__( 'Logo, headline, offer details, products and a button.', 'checkoutflow' ),
				array(
					self::b( 'logo' ),
					self::b( 'heading', array( 'text' => __( 'Our biggest sale is here', 'checkoutflow' ), 'size' => 30, 'align' => 'center' ) ),
					self::b( 'text', array( 'html' => '<p>' . __( 'For a limited time, enjoy 15% off sitewide. No code needed: savings apply automatically at checkout.', 'checkoutflow' ) . '</p>', 'align' => 'center' ) ),
					self::b( 'list', array( 'items' => __( "15% off the entire store\nStackable bulk discounts\nFast shipping", 'checkoutflow' ), 'style' => 'check', 'align' => 'center' ) ),
					self::b( 'button', array( 'text' => __( 'Shop the sale', 'checkoutflow' ) ) ),
					self::b( 'products', array( 'columns' => 3 ) ),
					self::b( 'divider' ),
					self::b( 'social' ),
					self::b( 'footer' ),
				),
			),
			'newsletter' => array(
				__( 'Newsletter', 'checkoutflow' ),
				__( 'Header menu, feature story, two columns and a footer.', 'checkoutflow' ),
				array(
					self::b( 'logo' ),
					self::b( 'menu' ),
					self::b( 'image', $img ),
					self::b( 'heading', array( 'text' => __( 'This month at {site_name}', 'checkoutflow' ), 'size' => 26 ) ),
					self::b( 'text' ),
					self::cols( '50-50', array( array( self::b( 'image', $img ), self::b( 'text' ) ), array( self::b( 'image', $img ), self::b( 'text' ) ) ) ),
					self::b( 'button' ),
					self::b( 'divider' ),
					self::b( 'social' ),
					self::b( 'footer' ),
				),
			),
			'lab_results' => array(
				__( 'Announcement + image grid', 'checkoutflow' ),
				__( 'Headline, text and a 3-across image grid (e.g. lab reports).', 'checkoutflow' ),
				array(
					self::b( 'logo' ),
					self::b( 'heading', array( 'text' => __( 'Latest test results', 'checkoutflow' ), 'size' => 24, 'align' => 'center' ) ),
					self::b( 'text', array( 'align' => 'center' ) ),
					self::cols( '33-33-33', array( array( self::b( 'image', $img ) ), array( self::b( 'image', $img ) ), array( self::b( 'image', $img ) ) ) ),
					self::cols( '33-33-33', array( array( self::b( 'image', $img ) ), array( self::b( 'image', $img ) ), array( self::b( 'image', $img ) ) ) ),
					self::b( 'button' ),
					self::b( 'footer' ),
				),
			),
			'product'    => array(
				__( 'New product', 'checkoutflow' ),
				__( 'Big image, description and a buy button.', 'checkoutflow' ),
				array(
					self::b( 'logo' ),
					self::b( 'image', $img ),
					self::b( 'heading', array( 'text' => __( 'Meet our newest product', 'checkoutflow' ), 'size' => 28, 'align' => 'center' ) ),
					self::b( 'text', array( 'align' => 'center' ) ),
					self::b( 'button', array( 'text' => __( 'Buy now', 'checkoutflow' ) ) ),
					self::b( 'footer' ),
				),
			),
			'abandoned'  => array(
				__( 'Abandoned cart', 'checkoutflow' ),
				__( 'Cart items and a button that restores the cart.', 'checkoutflow' ),
				array(
					self::b( 'logo' ),
					self::b( 'heading', array( 'text' => __( '{first_name|Hey}, you left something behind', 'checkoutflow' ), 'size' => 26 ) ),
					self::b( 'text', array( 'html' => '<p>' . __( 'Your cart is saved. Complete your order in one click.', 'checkoutflow' ) . '</p>' ) ),
					self::b( 'cart_items' ),
					self::b( 'button', array( 'text' => __( 'Complete my order', 'checkoutflow' ), 'url' => '{recovery_url}' ) ),
					self::b( 'footer' ),
				),
			),
			'review'     => array(
				__( 'Order follow-up / review', 'checkoutflow' ),
				__( 'Thanks the customer and asks for a review.', 'checkoutflow' ),
				array(
					self::b( 'logo' ),
					self::b( 'heading', array( 'text' => __( 'How did we do, {first_name|there}?', 'checkoutflow' ), 'size' => 26 ) ),
					self::b( 'text', array( 'html' => '<p>' . __( 'Thanks for your order! We would love to hear what you think.', 'checkoutflow' ) . '</p>' ) ),
					self::b( 'order_items' ),
					self::b( 'button', array( 'text' => __( 'Leave a review', 'checkoutflow' ), 'url' => '{review_url}' ) ),
					self::b( 'footer' ),
				),
			),
		);
	}
}
