<?php
/**
 * Email editor: full-screen drag-and-drop editor (assets/js/editor.js), configured in
 * Admin::email_editor_config(). Autosaves via the cf_autosave_email AJAX action.
 *
 * @package CheckoutFlow
 * @var array $target
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="cf-editor" class="cf-editor-mount"></div>
<noscript><p><?php esc_html_e( 'The email editor needs JavaScript.', 'checkoutflow' ); ?></p></noscript>
