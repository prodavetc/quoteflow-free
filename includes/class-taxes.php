<?php
namespace Latse\QuoteFlow;

use RuntimeException;
use WC_Order;
use WC_Tax;

defined( 'ABSPATH' ) || exit;

/** Gross negotiated amounts remain fixed; WooCommerce supplies rates and rounding. */
final class Taxes {
	public static function enabled( object $offer ): bool {
		return ! empty( $offer->taxes_enabled );
	}

	public static function fingerprint(): string {
		global $wpdb;
		$rates = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_id", ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Tax settings could not be checked. Try again.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
		}
		$locations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rate_locations ORDER BY location_id", ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Tax settings could not be checked. Try again.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
		}
		$options = array_map( 'get_option', array( 'woocommerce_tax_based_on', 'woocommerce_shipping_tax_class', 'woocommerce_tax_round_at_subtotal', 'woocommerce_price_num_decimals', 'woocommerce_tax_classes', 'woocommerce_default_country', 'woocommerce_store_postcode', 'woocommerce_store_city', 'woocommerce_prices_include_tax' ) );
		return hash( 'sha256', wp_json_encode( array( $rates, $locations, $options ) ) );
	}

	public static function validate( object $offer ): void {
		if ( self::enabled( $offer ) !== wc_tax_enabled() || ( self::enabled( $offer ) && ! hash_equals( $offer->tax_config_hash ?? '', self::fingerprint() ) ) ) {
			throw new RuntimeException( __( 'Tax settings changed. Ask the store for a new offer.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
		}
		foreach ( Offers::items( $offer ) as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( self::enabled( $offer ) && ( ! $product || $product->get_tax_class() !== $item['tax_class'] || $product->get_tax_status() !== $item['tax_status'] ) ) {
				throw new RuntimeException( __( 'Product tax settings changed. Ask for a new offer.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
			}
		}
	}

	private static function decimal( $amount ): string {
		return wc_format_decimal( $amount, 6 );
	}

	private static function split( string $gross, array $rates, bool $shipping = false ): array {
		$taxes = array_map( array( self::class, 'decimal' ), WC_Tax::calc_tax( (float) $gross, $rates, true ) );
		if ( $shipping ) {
			// Shipping is one monetary charge; normalize each tax to WooCommerce currency precision.
			$taxes = array_map( static fn( $tax ) => self::decimal( wc_round_tax_total( $tax ) ), $taxes );
		}
		$effective = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? array_sum( $taxes ) : array_sum( array_map( 'wc_round_tax_total', $taxes ) );
		$net = self::decimal( (float) $gross - $effective );
		if ( (float) $net < 0 ) { throw new RuntimeException( __( 'The quoted amount is too small for the configured tax rounding. Ask for a new offer.', 'quoteflow-free' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped at UI boundary.
		return array( 'net' => 0.0 === (float) $net ? self::decimal( 0 ) : $net, 'taxes' => $taxes );
	}

	public static function plan( object $offer ): array {
		self::validate( $offer );
		$plan = array( 'items' => array(), 'shipping' => array( 'net' => $offer->shipping_total, 'taxes' => array() ) );
		$address = array();
		if ( self::enabled( $offer ) ) {
			$based_on = get_option( 'woocommerce_tax_based_on', 'shipping' );
			$address = 'base' === $based_on ? array( 'country' => WC()->countries->get_base_country(), 'state' => WC()->countries->get_base_state(), 'postcode' => WC()->countries->get_base_postcode(), 'city' => WC()->countries->get_base_city() )
				: ( 'billing' === $based_on || ! Delivery::required( $offer ) ? Delivery::accepted_billing( $offer ) : Delivery::accepted( $offer ) );
		}
		$classes = array();
		foreach ( Offers::items( $offer ) as $item ) {
			$rates = self::enabled( $offer ) && 'taxable' === $item['tax_status'] ? WC_Tax::find_rates( $address + array( 'tax_class' => $item['tax_class'] ) ) : array();
			$plan['items'][] = self::split( $item['total'], $rates );
			if ( self::enabled( $offer ) && 'none' !== $item['tax_status'] ) {
				$classes[] = $item['tax_class'];
			}
		}
		if ( self::enabled( $offer ) && Delivery::required( $offer ) ) {
			$class = get_option( 'woocommerce_shipping_tax_class', 'inherit' );
			if ( 'inherit' === $class ) {
				// WooCommerce prioritizes standard, then configured tax classes present in the items.
				$ordered = array_merge( array( '' ), array_map( 'sanitize_title', WC_Tax::get_tax_classes() ) );
				$class = null;
				foreach ( $ordered as $candidate ) {
					if ( in_array( $candidate, $classes, true ) ) { $class = $candidate; break; }
				}
			}
			$rates = null === $class ? array() : WC_Tax::find_shipping_rates( $address + array( 'tax_class' => $class ) );
			$plan['shipping'] = self::split( $offer->shipping_total, $rates, true );
		}
		return $plan;
	}

	public static function accepted( object $offer ): array {
		$plan = json_decode( $offer->accepted_tax_json ?? 'null', true );
		if ( ! is_array( $plan ) ) {
			if ( self::enabled( $offer ) ) {
				throw new RuntimeException( __( 'The accepted tax calculation needs review.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
			}
			return array( 'items' => array_map( static fn( $item ) => array( 'net' => $item['total'], 'taxes' => array() ), Offers::items( $offer ) ), 'shipping' => array( 'net' => $offer->shipping_total ?? '0', 'taxes' => array() ) );
		}
		return $plan;
	}

	private static function map( array $taxes ): array {
		$taxes = array_map( array( self::class, 'decimal' ), $taxes );
		ksort( $taxes );
		return $taxes;
	}

	public static function verify( object $offer, WC_Order $order ): void {
		$plan = self::accepted( $offer );
		$lines = Offers::items( $offer );
		if ( $order->get_prices_include_tax() !== self::enabled( $offer ) ) { throw new RuntimeException( __( 'The order tax mode changed.', 'quoteflow-free' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped at UI boundary.
		$expected_rates = array();
		foreach ( array_values( $order->get_items() ) as $i => $item ) {
			$line = $plan['items'][ $i ] ?? null;
			if ( ! $line || ( self::enabled( $offer ) && $item->get_tax_class() !== $lines[ $i ]['tax_class'] ) || self::decimal( $item->get_total() ) !== self::decimal( $line['net'] ) || self::decimal( $item->get_subtotal() ) !== self::decimal( $line['net'] )
				|| self::map( $item->get_taxes()['total'] ) !== self::map( $line['taxes'] ) || self::map( $item->get_taxes()['subtotal'] ) !== self::map( $line['taxes'] ) ) {
				throw new RuntimeException( __( 'The accepted price or tax calculation changed. Contact the store before paying.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
			}
			foreach ( $line['taxes'] as $id => $amount ) {
				$expected_rates[ $id ]['cart'] = ( $expected_rates[ $id ]['cart'] ?? 0 ) + ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? (float) $amount : wc_round_tax_total( $amount ) );
			}
		}
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			if ( self::map( $shipping->get_taxes()['total'] ) !== self::map( $plan['shipping']['taxes'] ) ) {
				throw new RuntimeException( __( 'The accepted shipping taxes changed.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
			}
			foreach ( $plan['shipping']['taxes'] as $id => $amount ) {
				$expected_rates[ $id ]['shipping'] = ( $expected_rates[ $id ]['shipping'] ?? 0 ) + ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? (float) $amount : wc_round_tax_total( $amount ) );
			}
		}
		$actual = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$id = $tax->get_rate_id();
			if ( isset( $actual[ $id ] ) ) { throw new RuntimeException( __( 'Duplicate tax lines need review.', 'quoteflow-free' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
			$actual[ $id ] = array( 'cart' => self::decimal( $tax->get_tax_total() ), 'shipping' => self::decimal( $tax->get_shipping_tax_total() ) );
		}
		foreach ( $expected_rates as &$rate ) { $rate = array( 'cart' => self::decimal( $rate['cart'] ?? 0 ), 'shipping' => self::decimal( $rate['shipping'] ?? 0 ) ); }
		unset( $rate );
		ksort( $actual ); ksort( $expected_rates );
		$cart_total = array_sum( array_column( $expected_rates, 'cart' ) );
		$shipping_total = array_sum( array_column( $expected_rates, 'shipping' ) );
		if ( self::decimal( $order->get_cart_tax() ) !== self::decimal( $cart_total ) || self::decimal( $order->get_shipping_tax() ) !== self::decimal( $shipping_total ) || $actual !== $expected_rates || $order->get_items( 'fee' ) || $order->get_items( 'coupon' ) || (float) $order->get_discount_total() !== 0.0 ) {
			throw new RuntimeException( __( 'The accepted order totals changed. Contact the store before paying.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
		}
	}
}
