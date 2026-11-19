<?php

namespace Latse\QuoteFlow;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Variations {
	public static function select( int $product_id, array $attributes ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			throw new RuntimeException( __( 'Product not found.', 'quoteflow-free' ) );
		}
		if ( $product->is_type( 'simple' ) ) {
			if ( $attributes ) {
				throw new RuntimeException( __( 'This product does not have selectable options.', 'quoteflow-free' ) );
			}
			return array( 'product_id' => $product_id, 'parent_id' => 0, 'attributes' => array() );
		}
		$parent = $product->is_type( 'variable' ) ? $product : wc_get_product( $product->get_parent_id() );
		if ( ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== $parent->get_status() ) {
			throw new RuntimeException( __( 'Choose an available product variation.', 'quoteflow-free' ) );
		}
		$fixed    = $product->is_type( 'variation' ) ? $product->get_variation_attributes() : array();
		$selected = array();
		foreach ( $parent->get_variation_attributes() as $name => $options ) {
			$key   = wc_variation_attribute_name( $name );
			$value = $attributes[ $key ] ?? $fixed[ $key ] ?? '';
			if ( ! is_string( $value ) || '' === $value || ! in_array( $value, $options, true )
				|| ( isset( $fixed[ $key ] ) && '' !== $fixed[ $key ] && $fixed[ $key ] !== $value ) ) {
				throw new RuntimeException( __( 'Choose valid options for every variation attribute.', 'quoteflow-free' ) );
			}
			$selected[ $key ] = $value;
		}
		if ( array_diff_key( $attributes, $selected ) ) {
			throw new RuntimeException( __( 'The selected product options are invalid.', 'quoteflow-free' ) );
		}
		ksort( $selected );
		if ( $product->is_type( 'variable' ) ) {
			$store      = \WC_Data_Store::load( 'product' );
			$product_id = $store->find_matching_product_variation( $parent, $selected );
			if ( ! $product_id ) {
				throw new RuntimeException( __( 'This combination of product options is unavailable.', 'quoteflow-free' ) );
			}
		}
		return array( 'product_id' => (int) $product_id, 'parent_id' => $parent->get_id(), 'attributes' => $selected );
	}

	public static function fields( \WC_Product $parent, string $prefix ): void {
		foreach ( $parent->get_variation_attributes() as $name => $values ) {
			$key     = wc_variation_attribute_name( $name );
			$options = array( '' => __( 'Choose an option', 'quoteflow-free' ) );
			foreach ( $values as $value ) {
				$term = taxonomy_exists( $name ) ? get_term_by( 'slug', $value, $name ) : false;
				$options[ $value ] = $term && ! is_wp_error( $term ) ? $term->name : $value;
			}
			echo woocommerce_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes its fields.
				'attributes[' . $key . ']',
				array(
					'type' => 'select', 'label' => wc_attribute_label( $name, $parent ),
					'options' => $options, 'required' => true, 'return' => true, 'id' => $prefix . '-' . $key,
				),
				''
			);
		}
	}
}
