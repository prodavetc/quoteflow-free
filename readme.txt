=== QuoteFlow Free ===
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Requires Plugins: woocommerce
Stable tag: 1.0.0-rc.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: woocommerce, quote, quotation

Receive quote requests, prepare negotiated offers and convert accepted offers into native WooCommerce orders.

== Description ==

QuoteFlow adds a quote request form to compatible WooCommerce product and cart pages. Merchants prepare an offer, explicitly send its private link using their configured WordPress mail service, and let guests accept and pay through WooCommerce.

Supports simple and variable products, multiple items, physical and virtual goods, quoted shipping, separate billing addresses, offer rejection, merchant cancellation, manual offers and HPOS. Negotiated amounts include applicable taxes. WooCommerce rates and rounding are used when the customer supplies an address. Changes to tax settings require a new offer.

Offers expire after 24 hours. Shipping is quoted for one destination country. Quantities must be whole numbers from 1 to 100, with up to 20 item combinations. Automatic carrier rates, customer tax exemptions and third-party VAT verification are outside this release. Guest orders do not inherit a logged-in customer's exemption.

The plugin stores quote data on your site and does not contact a licensing service. Email uses your existing WordPress mail transport. Privacy export and erasure integrate with WordPress. Converted records are removed after WooCommerce anonymizes a completed, cancelled or refunded order; active orders remain protected. A busy cleanup retries through WordPress cron.

This is a release candidate for staging validation. Live email delivery, gateway sandbox certification, external hosting compatibility and pilot-store approval must be completed before production.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate QuoteFlow Free.
3. Configure taxes, countries, currency and payment methods in WooCommerce.
4. Open a compatible product or use [quoteflow_request product_id="123"]. Use [quoteflow_request cart="yes"] for a cart form.
5. Manage requests and manual offers under WooCommerce > QuoteFlow.

== Frequently Asked Questions ==

= Are negotiated prices tax inclusive? =
Yes. The negotiated line totals and shipping amount are final gross amounts. WooCommerce splits applicable taxes from them when the offer is accepted. The customer's final quoted total stays fixed.

= Does sending an offer guarantee email delivery? =
No. QuoteFlow records whether the configured transport accepted the message. Delivery must be verified with your mail provider and recipient mailbox.

= Does deactivation remove data? =
No. Deactivation and uninstall preserve quote tables. Use WordPress privacy tools for supported erasure and review your store retention policy and backups separately.

= Where can I report a problem? =
Use https://github.com/prodavetc/quoteflow-free/issues and remove personal data and private offer links from reports.

== Changelog ==

= 1.0.0-rc.1 =
* Release candidate: tax-inclusive negotiated totals, privacy coordination, distribution tools and release validation.
* Includes request and manual offer management, variation snapshots, fixed shipping, separate addresses and durable retries.

== Upgrade Notice ==

= 1.0.0-rc.1 =
Back up the database before upgrading. Reload open offer creation forms. Existing offers retain their original tax mode; changing that mode requires new offers. Do not install a release candidate on production stores.
