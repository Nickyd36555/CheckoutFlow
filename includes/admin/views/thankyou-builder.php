<?php
/**
 * Thank-you page editor: the same full-screen editor as emails, configured in
 * Admin::thankyou_editor_config(). Autosaves via the cf_ty_autosave AJAX action.
 *
 * @package CheckoutFlow
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="cf-editor" class="cf-editor-mount"></div>
<noscript><p><?php esc_html_e( 'The page editor needs JavaScript.', 'checkoutflow' ); ?></p></noscript>
