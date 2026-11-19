<?php
/**
 * Plugin Name: QuoteFlow Free
 * Description: Receive quote requests, prepare offers and convert acceptance into WooCommerce orders.
 * Version: 1.0.0-rc.1
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Author: Latse
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 * Text Domain: quoteflow-free
 */

namespace Latse\QuoteFlow;

defined( 'ABSPATH' ) || exit;

const QUOTEFLOW_FREE_VERSION = '1.0.0-rc.1';
add_action( 'init', static function () { load_plugin_textdomain( 'quoteflow-free', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); } );

require_once __DIR__ . '/includes/class-taxes.php';
require_once __DIR__ . '/includes/class-offers.php';
require_once __DIR__ . '/includes/class-variations.php';
require_once __DIR__ . '/includes/class-delivery.php';
require_once __DIR__ . '/includes/class-requests.php';
require_once __DIR__ . '/includes/class-request-form.php';
require_once __DIR__ . '/includes/class-offer-email.php';
require_once __DIR__ . '/includes/class-pages.php';
require_once __DIR__ . '/includes/class-privacy.php';

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
	update_option( 'qf_schema_version', '1.0.0-rc.1', false );
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
			if ( '1.0.0-rc.1' !== get_option( 'qf_schema_version' ) ) {
				install();
			}
			Pages::register();
			Request_Form::register();
			Privacy::register();
		}
	}
);
