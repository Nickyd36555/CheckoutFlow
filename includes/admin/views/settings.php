<?php
/**
 * Settings page.
 *
 * @package CheckoutFlow
 * @var array  $tabs
 * @var string $tab
 */

use CheckoutFlow\Settings;
use CheckoutFlow\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$values = Settings::all();
?>
<h1><?php esc_html_e( 'CheckoutFlow Settings', 'checkoutflow' ); ?></h1>

<nav class="nav-tab-wrapper">
	<?php foreach ( $tabs as $key => $t ) : ?>
		<a href="<?php echo esc_url( Admin::url( 'settings', array( 'tab' => $key ) ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $t['label'] ); ?></a>
	<?php endforeach; ?>
</nav>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-settings">
	<input type="hidden" name="action" value="cf_save_settings">
	<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
	<?php wp_nonce_field( 'cf_save_settings' ); ?>

	<table class="form-table" role="presentation">
		<?php
		foreach ( $tabs[ $tab ]['fields'] as $key => $f ) :
			if ( 'heading' === $f['type'] ) :
				?>
				</table>
				<h2 class="cf-section"><?php echo esc_html( $f['label'] ); ?></h2>
				<?php if ( ! empty( $f['desc'] ) ) : ?>
					<p class="description"><?php echo esc_html( $f['desc'] ); ?></p>
				<?php endif; ?>
				<table class="form-table" role="presentation">
				<?php
				continue;
			endif;
			$id    = 'cf-' . $key;
			$name  = 'cf[' . $key . ']';
			$value = isset( $values[ $key ] ) ? $values[ $key ] : '';
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $f['label'] ); ?></label></th>
				<td>
					<?php
					switch ( $f['type'] ) {
						case 'checkbox':
							printf( '<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ), esc_html__( 'Enabled', 'checkoutflow' ) );
							break;
						case 'select':
							printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
							foreach ( $f['options'] as $ok => $ol ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $ok ), selected( $value, $ok, false ), esc_html( $ol ) );
							}
							echo '</select>';
							break;
						case 'number':
							printf(
								'<input type="number" class="small-text" id="%s" name="%s" value="%s" step="%s" %s %s>',
								esc_attr( $id ),
								esc_attr( $name ),
								esc_attr( $value ),
								esc_attr( isset( $f['step'] ) ? $f['step'] : '1' ),
								isset( $f['min'] ) ? 'min="' . esc_attr( $f['min'] ) . '"' : '',
								isset( $f['max'] ) ? 'max="' . esc_attr( $f['max'] ) . '"' : ''
							);
							break;
						case 'color':
							printf( '<input type="color" id="%s" name="%s" value="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
							break;
						case 'textarea':
						case 'html':
							printf( '<textarea class="large-text" rows="%d" id="%s" name="%s">%s</textarea>', isset( $f['rows'] ) ? (int) $f['rows'] : 3, esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
							break;
						case 'password':
							$has = '' !== (string) $value || defined( 'CHECKOUTFLOW_SMTP_PASSWORD' );
							printf( '<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" placeholder="%s">', esc_attr( $id ), esc_attr( $name ), $has ? esc_attr__( '•••••••• (saved – leave empty to keep)', 'checkoutflow' ) : '' );
							break;
						default:
							printf( '<input type="%s" class="regular-text" id="%s" name="%s" value="%s" placeholder="%s">', 'url' === $f['type'] ? 'url' : 'text', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( isset( $f['placeholder'] ) ? $f['placeholder'] : '' ) );
					}
					if ( ! empty( $f['desc'] ) ) {
						echo '<p class="description">' . esc_html( $f['desc'] ) . '</p>';
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
	<?php submit_button(); ?>
</form>

<?php if ( 'email' === $tab ) : ?>
	<hr>
	<h2><?php esc_html_e( 'Send a test email', 'checkoutflow' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Save your settings first, then send a test to confirm SMTP works.', 'checkoutflow' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cf-inline-form">
		<input type="hidden" name="action" value="cf_test_email">
		<?php wp_nonce_field( 'cf_test_email' ); ?>
		<input type="email" name="to" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
		<?php submit_button( __( 'Send test', 'checkoutflow' ), 'secondary', 'submit', false ); ?>
	</form>
<?php endif; ?>
