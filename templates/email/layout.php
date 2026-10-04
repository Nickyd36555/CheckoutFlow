<?php
/**
 * Email document layout.
 *
 * @package CheckoutFlow
 * @var array  $s         Design settings.
 * @var string $body      Rendered block rows (<tr>...</tr>).
 * @var string $preheader Preview text.
 * @var string $footer    Footer HTML.
 * @var string $unsub     Unsubscribe URL.
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<style>
	body { margin: 0; padding: 0; }
	img { -ms-interpolation-mode: bicubic; }
	a { color: <?php echo esc_html( $s['accent'] ); ?>; }
	.cf-text a { color: <?php echo esc_html( $s['accent'] ); ?>; }
	@media (max-width: 620px) {
		.cf-container { width: 100% !important; }
		.cf-container td { padding-left: 18px !important; padding-right: 18px !important; }
		.cf-container td.cf-col { display: block !important; width: 100% !important; padding-left: 0 !important; padding-right: 0 !important; }
	}
</style>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $s['bg'] ); ?>;">
<?php if ( '' !== $preheader ) : ?>
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:<?php echo esc_attr( $s['bg'] ); ?>;"><?php echo esc_html( $preheader ); ?>&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr( $s['bg'] ); ?>;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" class="cf-container" width="<?php echo (int) $s['width']; ?>" cellpadding="0" cellspacing="0" border="0" style="width:<?php echo (int) $s['width']; ?>px;max-width:100%;background:<?php echo esc_attr( $s['content_bg'] ); ?>;border-radius:8px;font-family:<?php echo esc_attr( $s['font'] ); ?>;color:<?php echo esc_attr( $s['text_color'] ); ?>;">
				<?php if ( $s['logo'] ) : ?>
				<tr>
					<td style="padding:28px 32px 8px;text-align:center;">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( $s['logo'] ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" style="max-height:56px;max-width:220px;height:auto;border:0;"></a>
					</td>
				</tr>
				<?php endif; ?>
				<tr><td style="height:16px;font-size:0;line-height:0;">&nbsp;</td></tr>
				<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in Renderer. ?>
				<tr><td style="height:24px;font-size:0;line-height:0;">&nbsp;</td></tr>
			</table>
			<table role="presentation" class="cf-container" width="<?php echo (int) $s['width']; ?>" cellpadding="0" cellspacing="0" border="0" style="width:<?php echo (int) $s['width']; ?>px;max-width:100%;font-family:<?php echo esc_attr( $s['font'] ); ?>;">
				<tr>
					<td style="padding:20px 32px;text-align:center;font-size:12px;line-height:1.6;color:#6b7280;">
						<?php if ( '' !== $footer ) : ?>
							<?php echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput -- kses'd + merge-tag escaped. ?>
							<br>
						<?php endif; ?>
						<a href="<?php echo esc_url( $unsub ); ?>" style="color:#6b7280;text-decoration:underline;"><?php esc_html_e( 'Unsubscribe', 'checkoutflow' ); ?></a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
