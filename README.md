# CheckoutFlow

A lean replacement for FunnelKit (Funnel Builder, Cart for WooCommerce, and Automations) that keeps only the features that make money:

| Feature | What you get |
|---|---|
| **Checkout** | Sectioned checkout (Contact → Shipping Address → Shipping Method → Payment), shipping-address-first with optional different billing address, labels inside fields, rich order summary (images, quantity +/−, remove, inline coupon, trust badges, your own notes), total on the Place Order button, rename/hide/require fields, optional distraction-free header, optional "skip cart page" |
| **Side cart** | Slide-out cart with quantity controls, coupons, free-shipping progress bar, cross-sell recommendations, AJAX add-to-cart on product pages; opened from a floating button (left or right), a header menu cart icon with count badge and total, the `[checkoutflow_cart_icon]` shortcode, or any `.cf-open-cart` element |
| **Abandoned cart recovery** | Captures the email as it's typed at checkout, marks carts abandoned after N minutes, sends a timed email sequence with a one-click "restore cart" link, stops as soon as they order, reports recovered revenue |
| **Discounts** | Bulk (quantity) and spend tiers across categories/products, product price rules (e.g. store-wide % off with strike-through prices), per-role tiers, schedules. Imports Addify "Product Dynamic Pricing and Discounts" rules automatically |
| **Email marketing** | Contacts (synced from orders + checkout opt-in), campaigns with audience segments, automations (cart abandoned, order paid, order completed, win-back, welcome), unique coupon codes, open/click/revenue tracking |
| **Email builder** | Drag-and-drop blocks (heading, text, button, image, products, cart items, order items, coupon, divider, spacer, HTML) with a live preview rendered by the same code that sends |
| **SMTP** | Send through any SMTP provider (SES, Brevo, Mailgun, Postmark, SendGrid, Google Workspace, M365…), optionally for all WordPress/WooCommerce mail too |

## Why it's faster than FunnelKit

The FunnelKit plugins you were running add up to about 3,500 PHP files. CheckoutFlow has about 30.

- **Nothing loads where it isn't needed.** Checkout CSS/JS only loads on the checkout page. The side cart adds one small CSS file and one ~9 KB script (vanilla JS, `defer`). Admin code only loads in wp-admin.
- **No extra requests for empty carts.** The side cart only calls the server if the WooCommerce cart cookie says there's something in the cart. When WooCommerce's cart fragments are already running, it piggybacks on them. It's also safe to page-cache: the drawer markup contains no cart data.
- **Optional: turn off WooCommerce cart fragments** (Settings → Side Cart), a common cause of slow uncached `admin-ajax` requests.
- **One settings row** (autoloaded), five small indexed tables, and one lightweight background job. No React bundles, no CRM dashboards, no bundled page builders.

## Install

1. Zip this folder (or clone it) into `wp-content/plugins/checkoutflow` and activate **CheckoutFlow**. WooCommerce 7.0+ and PHP 7.4+ are required.
2. **Deactivate the FunnelKit plugins** (Funnel Builder, FunnelKit Cart, FunnelKit Automations + Pro + Connectors). Running both will double up the side cart and the recovery emails.
3. Go to **CheckoutFlow → Settings → Email & SMTP** (administrators only): enter your SMTP host/port/username/password, save, and click **Send test**.
4. Go to **CheckoutFlow → Automations**. The **Abandoned cart recovery** automation is created for you but **paused**. Review its three emails, then switch it to **Active**.
5. Optionally: **Contacts → Import customers from orders**, and import your FunnelKit contacts from CSV (FunnelKit → Contacts → Export, then import here; tick the consent box only for people who opted in).

### Automatic updates

CheckoutFlow updates itself from this repository's GitHub releases through WordPress's normal update system:

- Updates appear under **Dashboard → Updates** and on the **Plugins** screen, and auto-updates are switched on for CheckoutFlow the first time it runs (switch them off on the Plugins screen with "Disable auto-updates").
- WordPress checks twice a day. Use the **Check for updates** link under CheckoutFlow on the Plugins screen to check right away.
- WordPress's own safety net applies: if an update would break the site, it is rolled back automatically.
- Releasing: bump `Version:` and `CHECKOUTFLOW_VERSION` in `checkoutflow.php` and push. The **Release** GitHub Action builds `checkoutflow.zip` and publishes release `vX.Y.Z`.
- On shared hosting that hits GitHub's anonymous API limit, add a read-only token to `wp-config.php`: `define( 'CHECKOUTFLOW_GITHUB_TOKEN', '...' );`

### Cron (important for email timing)

Emails go out from a job that runs every minute through WP-Cron. On low-traffic sites WP-Cron only runs when someone visits, so add a real cron job and disable the built-in trigger:

```
# wp-config.php
define( 'DISABLE_WP_CRON', true );

# server crontab
* * * * * curl -s https://yourstore.com/wp-cron.php?doing_wp_cron > /dev/null
```

The dashboard warns you if background jobs look delayed.

### Keeping the SMTP password out of the database

The password is stored encrypted with your site's salts. To keep it out of the database entirely, add this to `wp-config.php` (it takes priority over the saved value):

```php
define( 'CHECKOUTFLOW_SMTP_PASSWORD', 'your-password' );
```

## How recovery works

1. On the checkout page, the email, name, and phone are saved as soon as they're entered. The cart shows under **Abandoned Carts → In checkout now**.
2. After the "abandoned after" time (default 15 minutes) with no activity, the cart becomes **Abandoned** and active *Cart abandoned* automations enroll it.
3. Step delays are counted from the moment of abandonment (for example 1 h, 24 h, 72 h). Each email can include the cart contents, a `{recovery_url}` button that rebuilds the cart and pre-fills checkout, and a unique single-use coupon locked to that customer's email.
4. When the customer pays, the rest of the sequence is cancelled. The cart is marked **Recovered** if an email was sent or the recovery link was used; otherwise it's simply removed. Each shopper gets at most one recovery sequence per week, so people who check out on two devices don't get two sequences. Orders on hold (bank transfer, cheque) count as placed.

## Email tracking & deliverability

- Every email is sent as HTML with a plain-text alternative, plus `List-Unsubscribe` and one-click unsubscribe headers (required by Gmail/Yahoo for bulk senders).
- Opens (pixel) and clicks (signed redirect links) can be switched off under Settings → Email & SMTP.
- An order placed within 7 days of clicking an email is credited to that email (revenue appears in campaign and automation stats).
- Unsubscribed contacts never receive campaigns or automations. The unsubscribe page asks for confirmation so link-scanners can't unsubscribe people.
- Campaigns default to **opted-in contacts only**. You can include customers without explicit consent, but make sure you have a lawful basis where you sell.

## Discounts (replaces Addify Product Dynamic Pricing)

**CheckoutFlow → Discounts.** Three rule types:

- **Bulk discount:** add up the quantity of all matching items in the cart. The tier's discount (e.g. 10+ items → 10% off) comes off those items' total as a line in the cart and checkout.
- **Spend discount:** same, but tiers are based on the amount spent on matching items.
- **Product price:** changes each matching item's unit price based on that line's quantity. Tiers that start at 1 also show the new price, struck through, in the shop.

Rules are checked top to bottom. The first product rule that fits a line sets its price, and the first bulk or spend rule that fits the cart adds its discount, calculated on the already-adjusted prices.

**Moving from Addify:** your Addify rules are imported the first time an admin opens wp-admin after updating. Published rules come over switched on, drafts come over switched off, and free-gift rules and tiers for individual customers are not supported. While Addify is still active, CheckoutFlow's rules stay paused so nobody gets discounted twice. Deactivate Addify and they take over, calculating the same amounts: this was verified cart-by-cart against Addify. The one visible difference is that the discount line shows the rule name (e.g. "Bulk Discount") instead of "Discount". Set **Label in cart** to "Discount" to keep the old wording.

**Why it's lighter:** rules are one small setting instead of a custom post type, so nothing is queried per page. Hooks are only added for rule types that are switched on, and they only run while WooCommerce calculates cart totals or renders a price. Product matching reads the product's own categories, rather than loading every product in a category.

## Merge tags

`{first_name}` `{last_name}` `{email}` `{site_name}` `{site_url}` `{shop_url}` `{store_address}` `{unsubscribe_url}` `{cart_items}` `{cart_total}` `{recovery_url}` `{recovery_button}` `{order_number}` `{order_total}` `{order_date}` `{order_items}` `{order_url}` `{review_url}` `{coupon_code}` `{coupon_amount}` `{coupon_expiry}`

Any tag accepts a fallback: `{first_name|there}`.

## Header cart icon

Settings → Side Cart → **Add cart to menu**: pick your header menu location (on block themes, "Header navigation block"). It shows the cart icon, a red count badge and the cart total, and opens the side cart. On page builders (Elementor, Divi…) place the shortcode `[checkoutflow_cart_icon]` in the header instead (`total="no"` hides the amount).

## Thank-you page & design settings

**CheckoutFlow → Thank You Page** is a drag-and-drop builder for WooCommerce's "Order received" page, using the same editor as emails. Blocks: heading, text, order summary cards, order items, payment instructions (whatever the payment gateway prints, e.g. a crypto payment box), customer information, support bar (email and phone), button, image, divider, spacer and custom HTML. Text can use `{first_name}`, `{order_number}`, `{email}`, `{order_total}`, `{shop_url}`, `{track_url}` and more. The preview uses your latest order. While "Show this page after checkout" is on, it also replaces FunnelKit's thank-you page. Anything else hooked to `woocommerce_thankyou` (tracking pixels, etc.) still runs. If you remove the Payment instructions block, the gateway's output is still shown at the top.

**Settings → Design** sets the font, text size, text, heading and label colors, field border color, order summary background and corner rounding. These apply to the checkout, the thank-you page and the side cart. The **Custom CSS** box is loaded only on those pages; HTML tags are stripped when you save.

## Editor (emails and thank-you page)

Emails and the thank-you page are designed in the same full-screen editor:

- **Blocks**: heading, text, site logo, list, button, image, divider, spacer, menu, social, custom HTML, footer, plus WooCommerce blocks for emails (products, coupon, cart items, order items) and order blocks for the thank-you page.
- **Structure**: rows with 1–4 columns (50/50, 33/67, 67/33, 3 or 4 across). Drag blocks into each column. Columns stack on phones.
- **Layouts**: ready-made sections (header with logo and menu, hero, image + text, image grids, product showcase, coupon offer, footer).
- **Templates**: starter emails (sale, newsletter, announcement with image grid, new product, abandoned cart, review request).
- Drag blocks onto the preview, drag a selected block by its handle to move it (also into or out of columns), and click a heading or text block to type in place. Every block has background and spacing settings.
- Undo/redo (Ctrl+Z / Ctrl+Shift+Z), Delete to remove the selected block, Ctrl+S to save, desktop/mobile preview, send test. Changes autosave a few seconds after you stop editing.

## Theme overrides

Copy any file from `templates/` to `yourtheme/checkoutflow/` to override it (`checkout-focused.php`, `side-cart.php`, `side-cart-content.php`, `checkout/form-checkout.php`, `checkout/review-order.php`, `checkout/payment.php`, `checkout/thankyou.php`).

## Developer hooks

| Hook | Type | Purpose |
|---|---|---|
| `checkoutflow_free_shipping_threshold` | filter | Change the free shipping bar target |
| `checkoutflow_cart_recommendation_ids` | filter | Change the side cart recommendations |
| `checkoutflow_merge_tags` | filter | Add or modify merge tag values |
| `checkoutflow_cart_abandoned` | action | A cart was marked abandoned (row array) |
| `checkoutflow_order_recorded` | action | A paid order was counted for a contact |
| `checkoutflow_contact_subscribed` | action | A contact opted in |

## Notes & limits

- Checkout layout and field options apply to the **classic** checkout (`[woocommerce_checkout]`, which FunnelKit also uses). The modern layout keeps every standard WooCommerce checkout hook, so gateways, package protection (Route) and store-credit plugins keep working, but test your payment methods on a staging copy before going live. On the block-based Checkout, cart capture, recovery, and the marketing opt-in still work (the opt-in uses WooCommerce's Additional Checkout Fields API, WooCommerce 8.9+).
- Order bumps and one-click post-purchase upsells are intentionally not included.
- Uninstalling (deleting the plugin) removes its tables and settings. Define `CHECKOUTFLOW_KEEP_DATA` in `wp-config.php` to keep them.
