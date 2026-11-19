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
				items_json longtext DEFAULT NULL,
				quantity int unsigned NOT NULL,
				total decimal(20,6) NOT NULL,
				shipping_total decimal(20,6) NOT NULL DEFAULT 0,
				shipping_country char(2) NOT NULL DEFAULT '',
				accepted_address_json longtext DEFAULT NULL,
				accepted_billing_json longtext DEFAULT NULL,
				currency char(3) NOT NULL,
				email varchar(254) NOT NULL,
				expires_at datetime NOT NULL,
				accepted_at datetime DEFAULT NULL,
				closed_at datetime DEFAULT NULL,
				closed_by bigint unsigned DEFAULT NULL,
				email_state varchar(16) NOT NULL DEFAULT 'idle',
				email_attempts int unsigned NOT NULL DEFAULT 0,
				email_attempted_at datetime DEFAULT NULL,
				email_accepted_at datetime DEFAULT NULL,
				order_id bigint unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				UNIQUE KEY request_id (request_id),
				UNIQUE KEY conversion_id (conversion_id),
				UNIQUE KEY order_id (order_id),
				KEY email (email)
			) ENGINE=InnoDB {$collate};"
		);
	}

	public static function create(
		int $product_id,
		int $quantity,
		string $total,
		string $email,
		int $request_id = 0,
		?string $token = null,
		string $shipping_total = '0',
		string $shipping_country = ''
	): string {
		return self::create_items( array( array( 'product_id' => $product_id, 'quantity' => $quantity, 'total' => $total ) ), $email, $request_id, $token, $shipping_total, $shipping_country );
	}

	public static function snapshot( array $items ): array {
		if ( ! $items || count( $items ) > 20 || ! array_is_list( $items ) ) {
			throw new RuntimeException( __( 'Include between 1 and 20 different products.', 'quoteflow-free' ) );
		}
		$snapshot = array();
		$seen     = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['product_id'], $item['quantity'] )
				|| ! is_int( $item['product_id'] ) || ! is_int( $item['quantity'] )
				|| ! is_array( $item['attributes'] ?? array() ) ) {
				throw new RuntimeException( __( 'Each product must appear once with a whole quantity.', 'quoteflow-free' ) );
			}
			$selection = Variations::select( $item['product_id'], $item['attributes'] ?? array() );
			$product   = self::product( $selection['product_id'], $item['quantity'] );
			$identity  = $product->get_id() . ':' . wp_json_encode( $selection['attributes'] );
			if ( isset( $seen[ $identity ] ) ) {
				throw new RuntimeException( __( 'Each product and option combination must appear once.', 'quoteflow-free' ) );
			}
			$label = $product->get_name();
			if ( $selection['parent_id'] ) {
				$label = $product->get_title() . ' - ' . wp_strip_all_tags( wc_get_formatted_variation( $selection['attributes'], true, true ) );
			}
			$snapshot[] = array(
				'product_id'     => $product->get_id(),
				'parent_id'      => $selection['parent_id'],
				'attributes'     => $selection['attributes'],
				'product_label'  => $label,
				'quantity'       => $item['quantity'],
				'needs_shipping' => $product->needs_shipping(),
			);
			$seen[ $identity ] = true;
		}
		self::validate_stock( $snapshot );
		return $snapshot;
	}

	public static function items( object $record ): array {
		if ( null !== ( $record->items_json ?? null ) ) {
			$items = json_decode( $record->items_json, true );
			if ( ! is_array( $items ) || ! $items || ! array_is_list( $items ) || count( $items ) > 20 ) {
				throw self::reconciliation_required();
			}
			return $items;
		}
		// Records created before multiline support keep their original snapshot.
		$item = array(
			'product_id'    => (int) $record->product_id,
			'product_label' => $record->product_label,
			'quantity'      => (int) $record->quantity,
		);
		if ( isset( $record->total ) ) {
			$item['total'] = $record->total;
		}
		return array( $item );
	}

	private static function amount( string $value, bool $allow_zero = false ): int {
		$parts    = explode( '.', $value );
		$decimals = wc_get_price_decimals();
		if ( $decimals > 6 || ! preg_match( '/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D', $value )
			|| strlen( $parts[1] ?? '' ) > $decimals || ( ! $allow_zero && '' === trim( $value, '0.' ) ) ) {
			throw new RuntimeException(
				$allow_zero
					? __( 'Enter a shipping total of zero or more, using the store currency precision.', 'quoteflow-free' )
					: __( 'Enter a valid positive total for each product.', 'quoteflow-free' )
			);
		}
		return (int) ( $parts[0] . str_pad( $parts[1] ?? '', 6, '0' ) );
	}

	private static function decimal( int $amount ): string {
		return sprintf( '%d.%06d', intdiv( $amount, 1000000 ), $amount % 1000000 );
	}

	public static function create_items(
		array $items,
		string $email,
		int $request_id = 0,
		?string $token = null,
		string $shipping_total = '0',
		string $shipping_country = ''
	): string {
		global $wpdb;
		$snapshot = self::snapshot( $items );
		if ( ! is_email( $email ) || strlen( $email ) > 254 ) {
			throw new RuntimeException( __( 'Enter a valid billing email.', 'quoteflow-free' ) );
		}
		$sum = 0;
		foreach ( $snapshot as $position => &$item ) {
			if ( ! isset( $items[ $position ]['total'] ) || ! is_string( $items[ $position ]['total'] ) ) {
				throw new RuntimeException( __( 'Enter a total for each product.', 'quoteflow-free' ) );
			}
			$amount        = self::amount( $items[ $position ]['total'] );
			$sum          += $amount;
			$item['total'] = self::decimal( $amount );
			if ( $sum > 999999999999999999 ) {
				throw new RuntimeException( __( 'The offer total is too large.', 'quoteflow-free' ) );
			}
		}
		unset( $item );
		$delivery = in_array( true, array_column( $snapshot, 'needs_shipping' ), true );
		$shipping = self::amount( $shipping_total, true );
		if ( ! $delivery && $shipping > 0 ) {
			throw new RuntimeException( __( 'Shipping charges require a physical product.', 'quoteflow-free' ) );
		}
		if ( $delivery && ! isset( Delivery::countries()[ $shipping_country ] ) ) {
			throw new RuntimeException( __( 'Choose a shipping country supported by the store.', 'quoteflow-free' ) );
		}
		$sum += $shipping;
		if ( $sum > 999999999999999999 ) {
			throw new RuntimeException( __( 'The offer total is too large.', 'quoteflow-free' ) );
		}
		$first  = $snapshot[0];
		$token  = $token ?? bin2hex( random_bytes( 32 ) );
		$result = $wpdb->insert(
			self::table(),
			array(
				'request_id'    => $request_id > 0 ? $request_id : null,
				'token_hash'    => hash( 'sha256', $token ),
				'conversion_id' => wp_generate_uuid4(),
				'state'         => 'offered',
				'product_id'    => $first['product_id'],
				'product_label' => $first['product_label'],
				'quantity'      => $first['quantity'],
				'items_json'    => wp_json_encode( $snapshot ),
				'total'         => self::decimal( $sum ),
				'shipping_total' => self::decimal( $shipping ),
				'shipping_country' => $delivery ? $shipping_country : '',
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
			|| ! $product->is_type( array( 'simple', 'variation' ) )
			|| 'publish' !== $product->get_status() || ! $product->is_purchasable()
			|| ( $product->is_sold_individually() && $quantity > 1 )
			|| ! $product->is_in_stock() || ! $product->has_enough_stock( $quantity ) ) {
			throw new RuntimeException( __( 'Use an available simple product or variation in a store with taxes disabled.', 'quoteflow-free' ) );
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || 'publish' !== $parent->get_status() || ! $product->variation_is_visible() ) {
				throw new RuntimeException( __( 'Choose an available product variation.', 'quoteflow-free' ) );
			}
		}
		return $product;
	}

	public static function close( string $token, string $state, int $actor_id = 0 ): void {
		global $wpdb;
		if ( ! in_array( $state, array( 'rejected', 'canceled' ), true ) ) {
			throw new RuntimeException( __( 'Invalid offer decision.', 'quoteflow-free' ) );
		}
		$offer = self::find( $token );
		if ( ! $offer ) {
			throw new RuntimeException( __( 'Offer not found.', 'quoteflow-free' ) );
		}
		$lock = 'qf_' . md5( self::table() . ':' . $offer->id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			throw new RuntimeException( __( 'The offer is being processed. Try again.', 'quoteflow-free' ) );
		}
		try {
			$offer = self::find( $token );
			if ( ! $offer ) {
				throw new RuntimeException( __( 'Offer not found.', 'quoteflow-free' ) );
			}
			if ( $state === $offer->state ) {
				return;
			}
			if ( 'converted' === $offer->state || 'creating' === $offer->state ) {
				throw new RuntimeException( __( 'This offer already has an order or a conversion in progress. Manage the order in WooCommerce.', 'quoteflow-free' ) );
			}
			if ( 'offered' !== $offer->state || ( 'rejected' === $state && self::expired( $offer ) ) ) {
				throw new RuntimeException( __( 'This offer is closed or expired.', 'quoteflow-free' ) );
			}
			$updated = $wpdb->update(
				self::table(),
				array( 'state' => $state, 'closed_at' => gmdate( 'Y-m-d H:i:s' ), 'closed_by' => $actor_id ),
				array( 'id' => $offer->id, 'state' => 'offered' )
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( __( 'The offer decision could not be saved. Please reload.', 'quoteflow-free' ) );
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function accept( string $token, array $address = array(), ?array $billing = null ): WC_Order {
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
			if ( in_array( $offer->state, array( 'rejected', 'canceled' ), true ) ) {
				throw new RuntimeException( __( 'This offer was declined or canceled. Contact the store for a new quote.', 'quoteflow-free' ) );
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
				self::validate_items( $offer );
				$address = Delivery::validate( $offer, $address );
				$offer->accepted_address_json = wp_json_encode( $address );
				$offer->accepted_billing_json = Delivery::required( $offer )
					? wp_json_encode( Delivery::validate_billing( $offer, $billing ?? $address ) ) : null;
				if ( get_woocommerce_currency() !== $offer->currency ) {
					throw new RuntimeException( __( 'The store currency changed. Ask for a new offer.', 'quoteflow-free' ) );
				}
				$claimed = $wpdb->update(
					self::table(),
					array(
						'state' => 'creating',
						'accepted_at' => gmdate( 'Y-m-d H:i:s' ),
						'accepted_address_json' => $offer->accepted_address_json,
						'accepted_billing_json' => $offer->accepted_billing_json,
					),
					array( 'id' => $offer->id, 'state' => 'offered' )
				);
				if ( 1 !== $claimed ) {
					throw self::reconciliation_required();
				}
				try {
					$order = self::create_order( $offer );
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

	private static function create_order( object $offer ): WC_Order {
		$order = new WC_Order();
		$order->set_status( 'pending' );
		$order->set_created_via( 'quoteflow' );
		$order->set_customer_id( 0 );
		$order->set_billing_email( $offer->email );
		$order->set_currency( $offer->currency );
		$order->set_prices_include_tax( false );
		if ( Delivery::required( $offer ) ) {
			$address = Delivery::accepted( $offer );
			$order->set_address( $address, 'shipping' );
			$order->set_address( Delivery::accepted_billing( $offer ), 'billing' );
			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_method_id( 'quoteflow' );
			$shipping->set_method_title( __( 'Quoted shipping', 'quoteflow-free' ) );
			$shipping->set_total( $offer->shipping_total );
			$order->add_item( $shipping );
		}
		foreach ( self::items( $offer ) as $item ) {
			$product = self::product( (int) $item['product_id'], (int) $item['quantity'] );
			$order->add_product(
				$product,
				(int) $item['quantity'],
				array(
					'name' => $item['product_label'], 'subtotal' => $item['total'], 'total' => $item['total'],
					'variation' => $item['attributes'] ?? array(),
				)
			);
		}
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
		$items    = array_values( $order->get_items() );
		$expected = self::items( $offer );
		if ( ! $order->get_id() || count( $expected ) !== count( $items )
			|| $offer->conversion_id !== $order->get_meta( '_qf_conversion_id' )
			|| $offer->currency !== $order->get_currency()
			|| $offer->email !== $order->get_billing_email()
			|| 0 !== $order->get_customer_id()
			|| wc_format_decimal( $offer->total, 6 ) !== wc_format_decimal( $order->get_total(), 6 ) ) {
			throw self::reconciliation_required();
		}
		Delivery::verify_order( $offer, $order );
		foreach ( $expected as $position => $line ) {
			$variation_id = empty( $line['parent_id'] ) ? 0 : (int) $line['product_id'];
			if ( (int) ( ( $line['parent_id'] ?? 0 ) ?: $line['product_id'] ) !== $items[ $position ]->get_product_id()
				|| $variation_id !== $items[ $position ]->get_variation_id()
				|| (int) $line['quantity'] !== $items[ $position ]->get_quantity()
				|| wc_format_decimal( $line['total'], 6 ) !== wc_format_decimal( $items[ $position ]->get_total(), 6 ) ) {
				throw self::reconciliation_required();
			}
			foreach ( $line['attributes'] ?? array() as $name => $value ) {
				if ( $items[ $position ]->get_meta( str_replace( 'attribute_', '', $name ), true ) !== $value ) {
					throw self::reconciliation_required();
				}
			}
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
			self::validate_items( $offer );
		} catch ( RuntimeException $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}

	private static function validate_items( object $offer ): void {
		foreach ( self::items( $offer ) as $item ) {
			$product = self::product( (int) $item['product_id'], (int) $item['quantity'] );
			$selection = Variations::select( $product->get_id(), $item['attributes'] ?? array() );
			if ( $selection['parent_id'] !== (int) ( $item['parent_id'] ?? 0 )
				|| $selection['attributes'] !== ( $item['attributes'] ?? array() ) ) {
				throw new RuntimeException( __( 'Product options changed. Ask the store for a new offer.', 'quoteflow-free' ) );
			}
			if ( $product->needs_shipping() !== (bool) ( $item['needs_shipping'] ?? false ) ) {
				throw new RuntimeException( __( 'Product delivery requirements changed. Ask the store for a new offer.', 'quoteflow-free' ) );
			}
		}
		self::validate_stock( self::items( $offer ) );
	}

	private static function validate_stock( array $items ): void {
		$quantities = array();
		foreach ( $items as $item ) {
			$product = self::product( (int) $item['product_id'], (int) $item['quantity'] );
			$stock_id = $product->get_stock_managed_by_id();
			$quantities[ $stock_id ] = ( $quantities[ $stock_id ] ?? 0 ) + (int) $item['quantity'];
		}
		foreach ( $quantities as $stock_id => $quantity ) {
			$stock = wc_get_product( $stock_id );
			if ( ! $stock || ! $stock->has_enough_stock( $quantity ) ) {
				throw new RuntimeException( __( 'The combined quantity exceeds the available product stock.', 'quoteflow-free' ) );
			}
		}
	}

	private static function reconciliation_required(): RuntimeException {
		return new RuntimeException( __( 'The order needs review. Contact the store before trying to pay.', 'quoteflow-free' ) );
	}
}
