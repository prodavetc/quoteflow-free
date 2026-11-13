<?php
/**
 * Plugin Name: QuoteFlow Free
 * Description: Create an offer and convert its acceptance into a WooCommerce order.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Text Domain: quoteflow-free
 */

namespace Latse\QuoteFlow;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-offers.php';
require_once __DIR__ . '/includes/class-pages.php';

register_activation_hook( __FILE__, array( Offers::class, 'install' ) );
add_action(
	'before_woocommerce_init',
	static function () {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
);
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			Pages::register();
		}
	}
);
