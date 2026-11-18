<?php

namespace Latse\QuoteFlow;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Pages {
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'template_redirect', array( self::class, 'offer' ) );
		add_action( 'woocommerce_before_pay_action', array( Offers::class, 'validate_payment' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', 'QuoteFlow', 'QuoteFlow', 'manage_woocommerce', 'quoteflow', array( self::class, 'admin' ) );
	}

	public static function admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'quoteflow-free' ), '', array( 'response' => 403 ) );
		}
		$link  = '';
		$error = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'qf_create_offer' );
			try {
				$request_id = (int) self::input( INPUT_POST, 'request_id' );
				if ( $request_id > 0 ) {
					$token = Requests::respond( $request_id, self::input( INPUT_POST, 'total' ) );
				} else {
					$token = Offers::create(
						(int) self::input( INPUT_POST, 'product_id' ),
						(int) self::input( INPUT_POST, 'quantity' ),
						self::input( INPUT_POST, 'total' ),
						self::input( INPUT_POST, 'email' )
					);
				}
				$link = add_query_arg( 'qf_offer', $token, home_url( '/' ) );
			} catch ( RuntimeException $e ) {
				$error = $e->getMessage();
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'QuoteFlow', 'quoteflow-free' ); ?></h1>
			<p><?php esc_html_e( 'Use a simple virtual product in a store with taxes disabled. The offer expires in 24 hours.', 'quoteflow-free' ); ?></p>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( $link ) : ?>
				<p><label for="qf-offer-link"><?php esc_html_e( 'Private link to share with the buyer', 'quoteflow-free' ); ?></label></p>
				<input id="qf-offer-link" type="url" class="large-text" readonly value="<?php echo esc_attr( $link ); ?>">
			<?php endif; ?>
			<?php self::inbox(); ?>
			<h2><?php esc_html_e( 'Create an offer without a request', 'quoteflow-free' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'qf_create_offer' ); ?>
				<p><label><?php esc_html_e( 'Product ID', 'quoteflow-free' ); ?> <input name="product_id" type="number" min="1" required></label></p>
				<p><label><?php esc_html_e( 'Quantity', 'quoteflow-free' ); ?> <input name="quantity" type="number" min="1" max="100" value="1" required></label></p>
				<p><label><?php esc_html_e( 'Negotiated total', 'quoteflow-free' ); ?> <input name="total" inputmode="decimal" required></label></p>
				<p><label><?php esc_html_e( 'Buyer billing email', 'quoteflow-free' ); ?> <input name="email" type="email" maxlength="254" required></label></p>
				<?php submit_button( __( 'Create offer', 'quoteflow-free' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function inbox(): void {
		$page     = max( 1, (int) self::input( INPUT_GET, 'qf_page' ) );
		$requests = Requests::inbox( $page );
		?>
		<h2><?php esc_html_e( 'Quote requests', 'quoteflow-free' ); ?></h2>
		<?php if ( ! $requests ) : ?>
			<p><?php esc_html_e( 'No requests on this page.', 'quoteflow-free' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Request', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Buyer', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Offer', 'quoteflow-free' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $requests as $request ) : ?>
					<tr>
						<td>#<?php echo (int) $request->id; ?><br><?php echo esc_html( $request->created_at ); ?> UTC</td>
						<td><?php echo esc_html( $request->email ); ?></td>
						<td><?php echo esc_html( $request->product_label ); ?> × <?php echo (int) $request->quantity; ?><br><?php echo nl2br( esc_html( $request->note ) ); ?></td>
						<td>
							<?php if ( $request->offer_id ) : ?>
								<p><?php echo wp_kses_post( wc_price( $request->total, array( 'currency' => $request->currency ) ) ); ?>
								· <?php echo esc_html( self::offer_status( $request ) ); ?></p>
							<?php endif; ?>
							<form method="post">
								<?php wp_nonce_field( 'qf_create_offer' ); ?>
								<input type="hidden" name="request_id" value="<?php echo (int) $request->id; ?>">
								<?php if ( ! $request->offer_id ) : ?>
									<p><label><?php esc_html_e( 'Negotiated total', 'quoteflow-free' ); ?>
										<input name="total" inputmode="decimal" required></label></p>
								<?php endif; ?>
								<button class="button" type="submit"><?php echo $request->offer_id ? esc_html__( 'Recover private link', 'quoteflow-free' ) : esc_html__( 'Create offer', 'quoteflow-free' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'quoteflow', 'qf_page' => $page - 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous requests', 'quoteflow-free' ); ?></a>
			<?php endif; ?>
			<?php if ( count( $requests ) === 20 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'quoteflow', 'qf_page' => $page + 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Older requests', 'quoteflow-free' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function offer_status( object $request ): string {
		if ( 'offered' === $request->offer_state && Offers::expired( $request ) ) {
			return __( 'Expired', 'quoteflow-free' );
		}
		$labels = array(
			'offered'   => __( 'Awaiting acceptance', 'quoteflow-free' ),
			'creating'  => __( 'Order awaiting review', 'quoteflow-free' ),
			'converted' => __( 'Order created', 'quoteflow-free' ),
		);
		return $labels[ $request->offer_state ] ?? __( 'Awaiting review', 'quoteflow-free' );
	}

	public static function offer(): void {
		if ( ! isset( $_GET['qf_offer'] ) ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$token = self::input( INPUT_GET, 'qf_offer' );
		$offer = Offers::find( $token );
		if ( ! $offer || Offers::expired( $offer ) ) {
			wp_die( esc_html__( 'This offer is unavailable or expired.', 'quoteflow-free' ), '', array( 'response' => 404 ) );
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( ! wp_verify_nonce( self::input( INPUT_POST, 'qf_nonce' ), 'qf_accept_' . $token ) ) {
				wp_die( esc_html__( 'Reload the offer to try again.', 'quoteflow-free' ), '', array( 'response' => 403 ) );
			}
			try {
				$order = Offers::accept( $token );
			} catch ( RuntimeException $e ) {
				wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 409 ) );
			} catch ( Throwable $e ) {
				wp_die( esc_html__( 'The order needs review. Contact the store before trying to pay.', 'quoteflow-free' ), '', array( 'response' => 500 ) );
			}
			if ( ! $order->needs_payment() ) {
				wp_die( esc_html__( 'This order no longer needs payment.', 'quoteflow-free' ), '', array( 'response' => 200 ) );
			}
			wp_safe_redirect( $order->get_checkout_payment_url(), 303 );
			exit;
		}
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			header( 'Allow: GET, POST' );
			wp_die( esc_html__( 'Method not allowed.', 'quoteflow-free' ), '', array( 'response' => 405 ) );
		}
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php esc_html_e( 'Your offer', 'quoteflow-free' ); ?></title>
		<body>
		<main>
			<h1><?php esc_html_e( 'Your offer', 'quoteflow-free' ); ?></h1>
			<p><?php echo esc_html( $offer->product_label ); ?> × <?php echo (int) $offer->quantity; ?></p>
			<p><?php echo wp_kses_post( wc_price( $offer->total, array( 'currency' => $offer->currency ) ) ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'qf_accept_' . $token, 'qf_nonce', false ); ?>
				<button type="submit"><?php esc_html_e( 'Accept and continue to payment', 'quoteflow-free' ); ?></button>
			</form>
		</main>
		</body>
		</html>
		<?php
		exit;
	}

	private static function input( int $type, string $key ): string {
		$value = filter_input( $type, $key, FILTER_UNSAFE_RAW );
		return is_string( $value ) ? trim( $value ) : '';
	}
}
