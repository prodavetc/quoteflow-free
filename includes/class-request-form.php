<?php

namespace Latse\QuoteFlow;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Request_Form {
	public static function register(): void {
		add_shortcode( 'quoteflow_request', array( self::class, 'shortcode' ) );
		add_action( 'woocommerce_after_add_to_cart_form', array( self::class, 'product_form' ) );
		add_action( 'woocommerce_after_cart', array( self::class, 'cart_form' ) );
		add_filter(
			'render_block_woocommerce/cart',
			static function ( string $html ): string {
				return $html . self::shortcode( array( 'cart' => 'yes' ) );
			}
		);
		add_action( 'admin_post_qf_request', array( self::class, 'submit' ) );
		add_action( 'admin_post_nopriv_qf_request', array( self::class, 'submit' ) );
		add_action( 'template_redirect', array( self::class, 'prevent_cache' ) );
	}

	public static function prevent_cache(): void {
		$post = get_queried_object();
		if ( is_product() ) {
			try {
				Offers::product( get_queried_object_id(), 1 );
			} catch ( RuntimeException $e ) {
				return;
			}
		}
		if ( is_product() || is_cart() || ( $post instanceof \WP_Post && has_shortcode( $post->post_content, 'quoteflow_request' ) ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public static function product_form(): void {
		global $product;
		if ( $product instanceof \WC_Product ) {
			echo self::shortcode( array( 'product_id' => $product->get_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by renderer.
		}
	}

	public static function cart_form(): void {
		echo self::shortcode( array( 'cart' => 'yes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by renderer.
	}

	public static function shortcode( $attributes ): string {
		$attributes = shortcode_atts( array( 'product_id' => get_the_ID(), 'cart' => 'no' ), $attributes, 'quoteflow_request' );
		$cart       = 'yes' === $attributes['cart'];
		$items      = array();
		if ( $cart ) {
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return '';
			}
			foreach ( WC()->cart->get_cart() as $line ) {
				if ( (float) (int) $line['quantity'] !== (float) $line['quantity'] ) {
					return '<p>' . esc_html__( 'Quote requests require whole quantities.', 'quoteflow-free' ) . '</p>';
				}
				$items[] = array( 'product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'] );
			}
		} else {
			$items[] = array( 'product_id' => (int) $attributes['product_id'], 'quantity' => 1 );
		}
		try {
			$items = Offers::snapshot( $items );
		} catch ( RuntimeException $e ) {
			return $cart ? '<p>' . esc_html( $e->getMessage() ) . '</p>' : '';
		}
		$submission = wp_generate_uuid4();
		$prefix     = 'qf-' . $submission;
		$scope      = implode( '-', array_column( $items, 'product_id' ) );
		ob_start();
		?>
		<section class="quoteflow-request" aria-labelledby="<?php echo esc_attr( $prefix ); ?>-title">
			<h2 id="<?php echo esc_attr( $prefix ); ?>-title"><?php esc_html_e( 'Request a quote', 'quoteflow-free' ); ?></h2>
			<?php if ( isset( $_GET['qf_received'] ) && '1' === $_GET['qf_received'] ) : ?>
				<p role="status"><?php esc_html_e( 'Your request was received. The store will contact you with an offer.', 'quoteflow-free' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="qf_request">
				<input type="hidden" name="request_cart" value="<?php echo $cart ? '1' : '0'; ?>">
				<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission ); ?>">
				<?php wp_nonce_field( 'qf_request_' . $scope . '_' . $submission, 'qf_request_nonce', false ); ?>
				<?php foreach ( $items as $position => $item ) : ?>
					<?php
					$product        = wc_get_product( $item['product_id'] );
					$product_field  = $cart ? "items[{$position}][product_id]" : 'product_id';
					$quantity_field = $cart ? "items[{$position}][quantity]" : 'quantity';
					?>
					<p><?php echo esc_html( $item['product_label'] ); ?></p>
					<input type="hidden" name="<?php echo esc_attr( $product_field ); ?>" value="<?php echo (int) $item['product_id']; ?>">
					<p><label for="<?php echo esc_attr( $prefix . '-quantity-' . $position ); ?>"><?php esc_html_e( 'Quantity', 'quoteflow-free' ); ?></label>
						<input id="<?php echo esc_attr( $prefix . '-quantity-' . $position ); ?>" name="<?php echo esc_attr( $quantity_field ); ?>" type="number" min="1" max="<?php echo $product->is_sold_individually() ? 1 : 100; ?>" value="<?php echo (int) $item['quantity']; ?>" required></p>
				<?php endforeach; ?>
				<p><label for="<?php echo esc_attr( $prefix ); ?>-email"><?php esc_html_e( 'Billing email', 'quoteflow-free' ); ?></label>
					<input id="<?php echo esc_attr( $prefix ); ?>-email" name="email" type="email" autocomplete="email" maxlength="254" required></p>
				<p><label for="<?php echo esc_attr( $prefix ); ?>-note"><?php esc_html_e( 'What do you need?', 'quoteflow-free' ); ?></label>
					<textarea id="<?php echo esc_attr( $prefix ); ?>-note" name="note" maxlength="2000" rows="3"></textarea></p>
				<div hidden aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'quoteflow-free' ); ?><input name="website" tabindex="-1" autocomplete="off"></label></div>
				<p><?php esc_html_e( 'The store will use your email to respond to this request.', 'quoteflow-free' ); ?></p>
				<button type="submit"><?php esc_html_e( 'Request a quote', 'quoteflow-free' ); ?></button>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function submit(): void {
		nocache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			header( 'Allow: POST' );
			wp_die( esc_html__( 'Method not allowed.', 'quoteflow-free' ), '', array( 'response' => 405 ) );
		}
		$submission = self::input( 'submission_id' );
		$cart = '1' === self::input( 'request_cart' );
		try {
			$items = self::posted_items( $cart );
		} catch ( RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 422 ) );
		}
		$scope = implode( '-', array_column( $items, 'product_id' ) );
		if ( ! wp_verify_nonce( self::input( 'qf_request_nonce' ), 'qf_request_' . $scope . '_' . $submission ) ) {
			wp_die( esc_html__( 'Reload the form to try again.', 'quoteflow-free' ), '', array( 'response' => 403 ) );
		}
		if ( '' !== self::input( 'website' ) ) {
			wp_die( esc_html__( 'The request is invalid.', 'quoteflow-free' ), '', array( 'response' => 422 ) );
		}
		try {
			Requests::submit_items(
				$submission,
				$items,
				self::input( 'email' ),
				sanitize_textarea_field( self::input( 'note' ) ),
				(string) ( $_SERVER['REMOTE_ADDR'] ?? '' )
			);
		} catch ( RuntimeException $e ) {
			$status = 429 === $e->getCode() ? 429 : 422;
			if ( 429 === $status ) {
				header( 'Retry-After: 600' );
			}
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => $status ) );
		}
		$return_url = $cart ? wc_get_cart_url() : get_permalink( $items[0]['product_id'] );
		wp_safe_redirect( add_query_arg( 'qf_received', '1', $return_url ), 303 );
		exit;
	}

	private static function posted_items( bool $cart ): array {
		$raw = $cart ? filter_input( INPUT_POST, 'items', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) : array(
			array( 'product_id' => self::input( 'product_id' ), 'quantity' => self::input( 'quantity' ) ),
		);
		if ( ! is_array( $raw ) || ! $raw || count( $raw ) > 20 || ! array_is_list( $raw ) ) {
			throw new RuntimeException( __( 'Include between 1 and 20 different products.', 'quoteflow-free' ) );
		}
		$items = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['product_id'], $item['quantity'] )
				|| ! is_string( $item['product_id'] ) || ! is_string( $item['quantity'] )
				|| ! ctype_digit( $item['product_id'] ) || ! ctype_digit( $item['quantity'] ) ) {
				throw new RuntimeException( __( 'The request is invalid.', 'quoteflow-free' ) );
			}
			$items[] = array( 'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'] );
		}
		return $items;
	}

	private static function input( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
		return is_string( $value ) ? trim( $value ) : '';
	}
}
