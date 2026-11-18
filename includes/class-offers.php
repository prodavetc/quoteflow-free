<?php

namespace Latse\QuoteFlow;

use Automattic\WooCommerce\Utilities\OrderUtil;
use RuntimeException;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class Offers {
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qf_offers';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				request_id bigint unsigned DEFAULT NULL,
				token_hash char(64) NOT NULL,
				conversion_id char(36) NOT NULL,
				state varchar(16) NOT NULL,
				product_id bigint unsigned NOT NULL,
				product_label text NOT NULL,
				quantity int unsigned NOT NULL,
				total decimal(20,6) NOT NULL,
				currency char(3) NOT NULL,
				email varchar(254) NOT NULL,
				expires_at datetime NOT NULL,
				accepted_at datetime DEFAULT NULL,
				order_id bigint unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				UNIQUE KEY request_id (request_id),
				UNIQUE KEY conversion_id (conversion_id),
				UNIQUE KEY order_id (order_id)
			) ENGINE=InnoDB {$collate};"
		);
	}

	public static function create( int $product_id, int $quantity, string $total, string $email, int $request_id = 0, ?string $token = null ): string {
		global $wpdb;
		$product = self::product( $product_id, $quantity );
		$decimals = wc_get_price_decimals();
		if ( $decimals > 6 || ! preg_match( '/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D', $total )
			|| ( str_contains( $total, '.' ) && strlen( explode( '.', $total )[1] ) > $decimals )
			|| '' === trim( $total, '0.' ) || ! is_email( $email ) || strlen( $email ) > 254 ) {
			throw new RuntimeException( __( 'Enter a valid positive total and billing email.', 'quoteflow-free' ) );
		}

		$token   = $token ?? bin2hex( random_bytes( 32 ) );
		$result  = $wpdb->insert(
			self::table(),
			array(
				'request_id'    => $request_id > 0 ? $request_id : null,
				'token_hash'    => hash( 'sha256', $token ),
				'conversion_id' => wp_generate_uuid4(),
				'state'         => 'offered',
				'product_id'    => $product_id,
				'product_label' => $product->get_name(),
				'quantity'      => $quantity,
				'total'         => $total,
				'currency'      => get_woocommerce_currency(),
				'email'         => $email,
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		if ( 1 !== $result ) {
			throw new RuntimeException( __( 'The offer could not be saved.', 'quoteflow-free' ) );
		}
		return $token;
	}

	public static function find( string $token ): ?object {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return null;
		}
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", hash( 'sha256', $token ) ) );
	}

	public static function expired( object $offer ): bool {
		return strtotime( $offer->expires_at . ' UTC' ) <= time();
	}

	public static function product( int $product_id, int $quantity ): \WC_Product {
		$product = wc_get_product( $product_id );
		if ( $quantity < 1 || $quantity > 100 || wc_tax_enabled() || ! $product
			|| ! $product->is_type( 'simple' ) || ! $product->is_virtual()
			|| 'publish' !== $product->get_status() || ! $product->is_purchasable()
			|| ( $product->is_sold_individually() && $quantity > 1 )
			|| ! $product->is_in_stock() || ! $product->has_enough_stock( $quantity ) ) {
			throw new RuntimeException( __( 'Use an available simple virtual product in a store with taxes disabled.', 'quoteflow-free' ) );
		}
		return $product;
	}

	public static function accept( string $token ): WC_Order {
		global $wpdb;
		$offer = self::find( $token );
		if ( ! $offer || self::expired( $offer ) ) {
			throw new RuntimeException( __( 'This offer is unavailable or expired.', 'quoteflow-free' ) );
		}

		$lock = 'qf_' . md5( self::table() . ':' . $offer->id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			throw new RuntimeException( __( 'The offer is being processed. Try again.', 'quoteflow-free' ) );
		}
		try {
			$offer = self::find( $token );
			if ( ! $offer || self::expired( $offer ) ) {
				throw new RuntimeException( __( 'This offer is unavailable or expired.', 'quoteflow-free' ) );
			}
			if ( 'converted' === $offer->state ) {
				$order = wc_get_order( (int) $offer->order_id );
				if ( $order instanceof WC_Order && $offer->conversion_id === $order->get_meta( '_qf_conversion_id' ) ) {
					return $order;
				}
				throw self::reconciliation_required();
			}
			if ( 'creating' === $offer->state ) {
				$order = self::recover( $offer );
			} elseif ( 'offered' === $offer->state ) {
				$product = self::product( (int) $offer->product_id, (int) $offer->quantity );
				if ( get_woocommerce_currency() !== $offer->currency ) {
					throw new RuntimeException( __( 'The store currency changed. Ask for a new offer.', 'quoteflow-free' ) );
				}
				$claimed = $wpdb->update(
					self::table(),
					array( 'state' => 'creating', 'accepted_at' => gmdate( 'Y-m-d H:i:s' ) ),
					array( 'id' => $offer->id, 'state' => 'offered' )
				);
				if ( 1 !== $claimed ) {
					throw self::reconciliation_required();
				}
				try {
					$order = self::create_order( $offer, $product );
				} catch ( Throwable $e ) {
					throw self::reconciliation_required();
				}
			} else {
				throw self::reconciliation_required();
			}
			$updated = $wpdb->update(
				self::table(),
				array( 'state' => 'converted', 'order_id' => $order->get_id() ),
				array( 'id' => $offer->id, 'state' => 'creating' )
			);
			if ( 1 !== $updated ) {
				throw self::reconciliation_required();
			}
			return $order;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function create_order( object $offer, \WC_Product $product ): WC_Order {
		$order = new WC_Order();
		$order->set_status( 'pending' );
		$order->set_created_via( 'quoteflow' );
		$order->set_customer_id( 0 );
		$order->set_billing_email( $offer->email );
		$order->set_currency( $offer->currency );
		$order->set_prices_include_tax( false );
		$order->add_product(
			$product,
			(int) $offer->quantity,
			array( 'name' => $offer->product_label, 'subtotal' => $offer->total, 'total' => $offer->total )
		);
		$order->update_meta_data( '_qf_conversion_id', $offer->conversion_id );
		$order->calculate_totals( false );
		self::verify_order( $offer, $order );
		return $order;
	}

	private static function recover( object $offer ): WC_Order {
		$meta  = array( 'key' => '_qf_conversion_id', 'value' => $offer->conversion_id );
		$query = array( 'type' => 'shop_order', 'limit' => 2, 'qf_conversion_id' => $offer->conversion_id );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$query['meta_query'] = array( $meta );
			$orders = wc_get_orders( $query );
		} else {
			$filter = static function ( array $args, array $vars ) use ( $meta ): array {
				if ( ( $vars['qf_conversion_id'] ?? null ) === $meta['value'] ) {
					$args['meta_query'][] = $meta;
				}
				return $args;
			};
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $filter, 10, 2 );
			try {
				$orders = wc_get_orders( $query );
			} finally {
				remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $filter, 10 );
			}
		}
		if ( 1 !== count( $orders ) || ! $orders[0] instanceof WC_Order ) {
			throw self::reconciliation_required();
		}
		self::verify_order( $offer, $orders[0] );
		return $orders[0];
	}

	private static function verify_order( object $offer, WC_Order $order ): void {
		$items = array_values( $order->get_items() );
		if ( ! $order->get_id() || 1 !== count( $items )
			|| (int) $offer->product_id !== $items[0]->get_product_id()
			|| (int) $offer->quantity !== $items[0]->get_quantity()
			|| $offer->conversion_id !== $order->get_meta( '_qf_conversion_id' )
			|| $offer->currency !== $order->get_currency()
			|| $offer->email !== $order->get_billing_email()
			|| 0 !== $order->get_customer_id()
			|| wc_format_decimal( $offer->total, 6 ) !== wc_format_decimal( $order->get_total(), 6 ) ) {
			throw self::reconciliation_required();
		}
	}

	public static function validate_payment( WC_Order $order ): void {
		global $wpdb;
		$marker = $order->get_meta( '_qf_conversion_id' );
		if ( ! $marker ) {
			return;
		}
		$table = self::table();
		$offer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE conversion_id = %s", $marker ) );
		try {
			if ( ! $offer || 'converted' !== $offer->state || (int) $offer->order_id !== $order->get_id()
				|| get_woocommerce_currency() !== $offer->currency ) {
				throw self::reconciliation_required();
			}
			self::verify_order( $offer, $order );
			self::product( (int) $offer->product_id, (int) $offer->quantity );
		} catch ( RuntimeException $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}

	private static function reconciliation_required(): RuntimeException {
		return new RuntimeException( __( 'The order needs review. Contact the store before trying to pay.', 'quoteflow-free' ) );
	}
}
