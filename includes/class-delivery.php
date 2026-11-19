<?php

namespace Latse\QuoteFlow;

use RuntimeException;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class Delivery {
	public static function required( object $offer ): bool {
		foreach ( Offers::items( $offer ) as $item ) {
			if ( ! empty( $item['needs_shipping'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function countries(): array {
		return array_intersect_key( WC()->countries->get_shipping_countries(), WC()->countries->get_allowed_countries() );
	}

	private static function fields( string $country ): array {
		return array_intersect_key(
			WC()->countries->get_address_fields( $country, 'shipping_' ),
			array_flip( array(
				'shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_country',
				'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode',
			) )
		);
	}

	public static function validate( object $offer, array $input ): array {
		if ( ! self::required( $offer ) ) {
			return array();
		}
		$country = $offer->shipping_country;
		if ( ! isset( self::countries()[ $country ] ) || ( $input['country'] ?? null ) !== $country ) {
			throw new RuntimeException( __( 'Use the country quoted in this offer. Contact the store for a different destination.', 'quoteflow-free' ) );
		}
		$address = array();
		foreach ( self::fields( $country ) as $field => $settings ) {
			$key   = substr( $field, 9 );
			$value = $input[ $key ] ?? '';
			if ( ! is_string( $value ) || mb_strlen( $value, 'UTF-8' ) > ( 'postcode' === $key ? 20 : 200 ) ) {
				throw new RuntimeException( __( 'Enter a valid delivery address.', 'quoteflow-free' ) );
			}
			$value = sanitize_text_field( $value );
			if ( ! empty( $settings['required'] ) && empty( $settings['hidden'] ) && '' === $value ) {
				/* translators: %s: Required address field label. */
				throw new RuntimeException( sprintf( __( 'Complete the delivery field %s.', 'quoteflow-free' ), $settings['label'] ) );
			}
			$address[ $key ] = $value;
		}
		$states = WC()->countries->get_states( $country );
		if ( is_array( $states ) && $states && ! isset( $states[ $address['state'] ] ) ) {
			throw new RuntimeException( __( 'Choose a valid state or province for the quoted country.', 'quoteflow-free' ) );
		}
		if ( '' !== ( $address['postcode'] ?? '' ) && ! \WC_Validation::is_postcode( $address['postcode'], $country ) ) {
			throw new RuntimeException( __( 'Enter a valid postcode for the quoted country.', 'quoteflow-free' ) );
		}
		$address['postcode'] = wc_format_postcode( $address['postcode'] ?? '', $country );
		return $address;
	}

	public static function accepted( object $offer ): array {
		$address = json_decode( $offer->accepted_address_json ?? '[]', true );
		if ( ! is_array( $address ) ) {
			throw new RuntimeException( __( 'The delivery address needs review.', 'quoteflow-free' ) );
		}
		return self::validate( $offer, $address );
	}

	public static function verify_order( object $offer, WC_Order $order ): void {
		$shipping = array_values( $order->get_items( 'shipping' ) );
		$required = self::required( $offer );
		if ( count( $shipping ) !== ( $required ? 1 : 0 )
			|| wc_format_decimal( $order->get_shipping_total(), 6 ) !== wc_format_decimal( $offer->shipping_total ?? '0', 6 ) ) {
			throw new RuntimeException( __( 'The quoted shipping amount changed. Contact the store before paying.', 'quoteflow-free' ) );
		}
		if ( ! $required ) {
			return;
		}
		if ( 'quoteflow' !== $shipping[0]->get_method_id()
			|| wc_format_decimal( $shipping[0]->get_total(), 6 ) !== wc_format_decimal( $offer->shipping_total, 6 ) ) {
			throw new RuntimeException( __( 'The quoted shipping method changed. Contact the store before paying.', 'quoteflow-free' ) );
		}
		$address = self::accepted( $offer );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$stored = $order->get_address( $type );
			foreach ( $address as $key => $value ) {
				if ( ( $stored[ $key ] ?? '' ) !== $value ) {
					throw new RuntimeException( __( 'The accepted delivery address changed. Contact the store before paying.', 'quoteflow-free' ) );
				}
			}
		}
	}

	public static function form( object $offer ): void {
		if ( ! self::required( $offer ) || 'offered' !== $offer->state ) {
			return;
		}
		$country = $offer->shipping_country;
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Delivery and billing address', 'quoteflow-free' ); ?></legend>
			<p><?php esc_html_e( 'This address will be used for delivery and billing.', 'quoteflow-free' ); ?></p>
			<p><?php echo esc_html( self::countries()[ $country ] ?? $country ); ?></p>
			<input type="hidden" name="delivery[country]" value="<?php echo esc_attr( $country ); ?>">
			<?php foreach ( self::fields( $country ) as $field => $settings ) : ?>
				<?php
				if ( 'shipping_country' === $field || ! empty( $settings['hidden'] ) ) {
					continue;
				}
				$key                = substr( $field, 9 );
				$settings['return'] = true;
				$settings['id']     = 'qf-delivery-' . $key;
				$settings['custom_attributes']['maxlength'] = 'postcode' === $key ? 20 : 200;
				echo woocommerce_form_field( 'delivery[' . $key . ']', $settings, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes its fields.
				?>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	public static function quote_fields(): void {
		$countries = self::countries();
		$base      = WC()->countries->get_base_country();
		?>
		<p><label><?php esc_html_e( 'Quoted shipping total', 'quoteflow-free' ); ?>
			<input name="shipping_total" inputmode="decimal" value="0" required></label></p>
		<p><label><?php esc_html_e( 'Shipping country', 'quoteflow-free' ); ?>
			<select name="shipping_country">
				<?php foreach ( $countries as $code => $name ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $base ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</label></p>
		<?php
	}
}
