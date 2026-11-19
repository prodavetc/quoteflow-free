<?php
/**
 * Plugin Name: QuoteFlow Free
 * Description: Receive quote requests, prepare offers and convert acceptance into WooCommerce orders.
 * Version: 0.6.0-dev
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Text Domain: quoteflow-free
 */

namespace Latse\QuoteFlow;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-offers.php';
require_once __DIR__ . '/includes/class-delivery.php';
require_once __DIR__ . '/includes/class-requests.php';
require_once __DIR__ . '/includes/class-request-form.php';
require_once __DIR__ . '/includes/class-offer-email.php';
require_once __DIR__ . '/includes/class-pages.php';

function install(): void {
	global $wpdb;
	Offers::install();
	if ( $wpdb->last_error ) {
		return;
	}
	Requests::install();
	if ( $wpdb->last_error ) {
		return;
	}
	update_option( 'qf_schema_version', '0.6.0-dev', false );
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\install' );
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			if ( '0.6.0-dev' !== get_option( 'qf_schema_version' ) ) {
				install();
			}
			Pages::register();
			Request_Form::register();
		}
	}
);
