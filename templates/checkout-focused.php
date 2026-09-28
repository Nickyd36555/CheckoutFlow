<?php
/**
 * Distraction-free checkout page. Override at yourtheme/checkoutflow/checkout-focused.php.
 *
 * @package CheckoutFlow
 */

use CheckoutFlow\Settings;

defined( 'ABSPATH' ) || exit;

$cf_logo  = Settings::get( 'checkout_logo' );
$cf_badge = Settings::get( 'checkout_header_text' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'cf-focused' ); ?>>
<?php wp_body_open(); ?>
<header class="cf-header">
	<div class="cf-wrap cf-header-inner">
		<a class="cf-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( $cf_logo ) : ?>
				<img src="<?php echo esc_url( $cf_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<?php elseif ( has_custom_logo() ) : ?>
				<?php echo wp_get_attachment_image( get_theme_mod( 'custom_logo' ), 'medium', false, array( 'alt' => get_bloginfo( 'name' ) ) ); ?>
			<?php else : ?>
				<span class="cf-brand-name"><?php bloginfo( 'name' ); ?></span>
			<?php endif; ?>
		</a>
		<?php if ( $cf_badge ) : ?>
			<span class="cf-secure-badge">
				<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				<?php echo esc_html( $cf_badge ); ?>
			</span>
		<?php endif; ?>
	</div>
</header>
<main class="cf-wrap cf-main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>
<footer class="cf-footer">
	<div class="cf-wrap">
		<span>&copy; <?php echo esc_html( gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?></span>
		<?php
		$cf_links = array_filter(
			array(
				get_privacy_policy_url() ? array( get_privacy_policy_url(), __( 'Privacy policy', 'checkoutflow' ) ) : null,
				wc_terms_and_conditions_page_id() ? array( get_permalink( wc_terms_and_conditions_page_id() ), __( 'Terms', 'checkoutflow' ) ) : null,
			)
		);
		foreach ( $cf_links as $cf_link ) {
			printf( '<a href="%s">%s</a>', esc_url( $cf_link[0] ), esc_html( $cf_link[1] ) );
		}
		?>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
