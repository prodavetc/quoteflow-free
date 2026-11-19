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

	private static function fields( string $country, string $type = 'shipping' ): array {
		$prefix = $type . '_';
		return array_intersect_key(
			WC()->countries->get_address_fields( $country, $prefix ),
			array_flip( array_map( static fn( $key ) => $prefix . $key, array(
				'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode',
			) ) )
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
		return self::validate_address( $country, $input, 'shipping' );
	}

	public static function validate_billing( object $offer, array $input ): array {
		$country = $input['country'] ?? '';
		if ( ! self::required( $offer ) || ! is_string( $country ) || ! isset( WC()->countries->get_allowed_countries()[ $country ] ) ) {
			throw new RuntimeException( __( 'Choose a billing country supported by the store.', 'quoteflow-free' ) );
		}
		return self::validate_address( $country, $input, 'billing' );
	}

	private static function validate_address( string $country, array $input, string $type ): array {
		$address = array();
		foreach ( self::fields( $country, $type ) as $field => $settings ) {
			$key   = substr( $field, strlen( $type ) + 1 );
			$value = $input[ $key ] ?? '';
			if ( ! is_string( $value ) || mb_strlen( $value, 'UTF-8' ) > ( 'postcode' === $key ? 20 : 200 ) ) {
				throw new RuntimeException( __( 'Enter a valid address.', 'quoteflow-free' ) );
			}
			$value = sanitize_text_field( $value );
			if ( ! empty( $settings['required'] ) && empty( $settings['hidden'] ) && '' === $value ) {
				/* translators: 1: Address type, 2: Required field label. */
				throw new RuntimeException( sprintf( __( 'Complete the %1$s field %2$s.', 'quoteflow-free' ), 'billing' === $type ? __( 'billing address', 'quoteflow-free' ) : __( 'delivery address', 'quoteflow-free' ), $settings['label'] ) );
			}
			$address[ $key ] = $value;
		}
		$states = WC()->countries->get_states( $country );
		if ( is_array( $states ) && $states && ! isset( $states[ $address['state'] ] ) ) {
			throw new RuntimeException( __( 'Choose a valid state or province for this address country.', 'quoteflow-free' ) );
		}
		if ( '' !== ( $address['postcode'] ?? '' ) && ! \WC_Validation::is_postcode( $address['postcode'], $country ) ) {
			throw new RuntimeException( __( 'Enter a valid postcode for this address country.', 'quoteflow-free' ) );
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

	public static function accepted_billing( object $offer ): array {
		// Offers accepted before separate billing addresses used the delivery address for both.
		if ( null === ( $offer->accepted_billing_json ?? null ) ) {
			return self::accepted( $offer );
		}
		$address = json_decode( $offer->accepted_billing_json, true );
		if ( ! is_array( $address ) ) {
			throw new RuntimeException( __( 'The billing address needs review.', 'quoteflow-free' ) );
		}
		return self::validate_billing( $offer, $address );
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
		foreach ( array( 'billing' => self::accepted_billing( $offer ), 'shipping' => self::accepted( $offer ) ) as $type => $address ) {
			$stored = $order->get_address( $type );
			foreach ( $address as $key => $value ) {
				if ( ( $stored[ $key ] ?? '' ) !== $value ) {
					throw new RuntimeException( __( 'An accepted address changed. Contact the store before paying.', 'quoteflow-free' ) );
				}
			}
		}
	}

	public static function address_options( object $offer, string $token ): void {
		if ( ! self::required( $offer ) || 'offered' !== $offer->state ) {
			return;
		}
		$country = self::form_country( $offer );
		?>
		<form method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="hidden" name="qf_offer" value="<?php echo esc_attr( $token ); ?>">
			<p><?php esc_html_e( 'Choose your billing address option before filling in the addresses. Updating this form clears any address fields you have entered.', 'quoteflow-free' ); ?></p>
			<p><label><?php esc_html_e( 'Billing address option', 'quoteflow-free' ); ?>
				<select name="qf_billing_mode">
					<option value="same" <?php selected( self::separate_form(), false ); ?>><?php esc_html_e( 'Same as delivery', 'quoteflow-free' ); ?></option>
					<option value="separate" <?php selected( self::separate_form(), true ); ?>><?php esc_html_e( 'Use a different billing address', 'quoteflow-free' ); ?></option>
				</select>
			</label></p>
			<p><label><?php esc_html_e( 'Country for a different billing address', 'quoteflow-free' ); ?>
				<select name="qf_billing_country">
					<?php foreach ( WC()->countries->get_allowed_countries() as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $country ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label></p>
			<button type="submit"><?php esc_html_e( 'Update address form', 'quoteflow-free' ); ?></button>
		</form>
		<?php
	}

	private static function separate_form(): bool {
		return 'separate' === filter_input( INPUT_GET, 'qf_billing_mode', FILTER_UNSAFE_RAW );
	}

	private static function form_country( object $offer ): string {
		$country = filter_input( INPUT_GET, 'qf_billing_country', FILTER_UNSAFE_RAW );
		return is_string( $country ) && isset( WC()->countries->get_allowed_countries()[ $country ] ) ? $country : $offer->shipping_country;
	}

	public static function form( object $offer ): void {
		if ( ! self::required( $offer ) || 'offered' !== $offer->state ) {
			return;
		}
		$separate = self::separate_form();
		?>
		<input type="hidden" name="billing_mode" value="<?php echo $separate ? 'separate' : 'same'; ?>">
		<?php
		self::address_form( $offer->shipping_country, 'delivery', $separate ? __( 'Delivery address', 'quoteflow-free' ) : __( 'Delivery and billing address', 'quoteflow-free' ) );
		if ( $separate ) {
			self::address_form( self::form_country( $offer ), 'billing', __( 'Billing address', 'quoteflow-free' ) );
		}
	}

	private static function address_form( string $country, string $name, string $label ): void {
		$type = 'billing' === $name ? 'billing' : 'shipping';
		?>
		<fieldset>
			<legend><?php echo esc_html( $label ); ?></legend>
			<p><?php echo esc_html( WC()->countries->get_allowed_countries()[ $country ] ?? $country ); ?></p>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[country]" value="<?php echo esc_attr( $country ); ?>">
			<?php foreach ( self::fields( $country, $type ) as $field => $settings ) : ?>
				<?php
				if ( $type . '_country' === $field || ! empty( $settings['hidden'] ) ) {
					continue;
				}
				$key = substr( $field, strlen( $type ) + 1 );
				$settings['return'] = true;
				$settings['id'] = 'qf-' . $name . '-' . $key;
				$settings['country'] = $country;
				$settings['custom_attributes']['maxlength'] = 'postcode' === $key ? 20 : 200;
				echo woocommerce_form_field( $name . '[' . $key . ']', $settings, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes its fields.
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
