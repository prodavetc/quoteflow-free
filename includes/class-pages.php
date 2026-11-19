<?php

namespace Latse\QuoteFlow;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Pages {
	private static string $token = '';
	private static string $notice = '';
	private static string $error = '';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'handle_submission' ) );
		add_action( 'template_redirect', array( self::class, 'offer' ) );
		add_action( 'woocommerce_before_pay_action', array( Offers::class, 'validate_payment' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', 'QuoteFlow', 'QuoteFlow', 'manage_woocommerce', 'quoteflow', array( self::class, 'admin' ) );
	}

	public static function handle_submission(): void {
		global $pagenow;
		if ( 'admin.php' !== $pagenow || 'quoteflow' !== self::input( INPUT_GET, 'page' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'quoteflow-free' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			try {
				if ( 'email' === self::input( INPUT_POST, 'qf_action' ) ) {
					self::$token = self::input( INPUT_POST, 'offer_token' );
					$offer   = Offers::find( self::$token );
					$attempt = (int) self::input( INPUT_POST, 'email_attempt' );
					if ( ! $offer ) {
						throw new RuntimeException( __( 'Offer not found.', 'quoteflow-free' ) );
					}
					check_admin_referer( 'qf_email_' . $offer->id . '_' . $attempt );
					Offer_Email::send( self::$token, $attempt, '1' === self::input( INPUT_POST, 'confirm_retry' ) );
					self::$notice = __( 'The mail service accepted the offer email. Receipt depends on the recipient mail server.', 'quoteflow-free' );
				} elseif ( 'cancel' === self::input( INPUT_POST, 'qf_action' ) ) {
					self::$token = self::input( INPUT_POST, 'offer_token' );
					$offer = Offers::find( self::$token );
					if ( ! $offer ) {
						throw new RuntimeException( __( 'Offer not found.', 'quoteflow-free' ) );
					}
					check_admin_referer( 'qf_cancel_' . $offer->id );
					if ( '1' !== self::input( INPUT_POST, 'confirm_cancel' ) ) {
						throw new RuntimeException( __( 'Confirm that you want to cancel this offer.', 'quoteflow-free' ) );
					}
					Offers::close( self::$token, 'canceled', get_current_user_id() );
					self::$notice = __( 'The offer was canceled.', 'quoteflow-free' );
				} else {
					check_admin_referer( 'qf_create_offer' );
					$request_id = (int) self::input( INPUT_POST, 'request_id' );
					if ( $request_id > 0 ) {
						$totals = filter_input( INPUT_POST, 'totals', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
						self::$token = Requests::respond(
							$request_id,
							is_array( $totals ) ? $totals : self::input( INPUT_POST, 'total' ),
							self::input( INPUT_POST, 'shipping_total' ) ?: '0',
							self::input( INPUT_POST, 'shipping_country' )
						);
					} else {
						self::$token = Offers::create(
							(int) self::input( INPUT_POST, 'product_id' ),
							(int) self::input( INPUT_POST, 'quantity' ),
							self::input( INPUT_POST, 'total' ),
							self::input( INPUT_POST, 'email' ),
							0,
							null,
							self::input( INPUT_POST, 'shipping_total' ) ?: '0',
							self::input( INPUT_POST, 'shipping_country' )
						);
					}
				}
			} catch ( RuntimeException $e ) {
				self::$error = $e->getMessage();
			}
		}
	}

	public static function admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'quoteflow-free' ), '', array( 'response' => 403 ) );
		}
		$token  = self::$token;
		$error  = self::$error;
		$notice = self::$notice;
		$link   = $token && Offers::find( $token ) ? add_query_arg( 'qf_offer', $token, home_url( '/' ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'QuoteFlow', 'quoteflow-free' ); ?></h1>
			<p><?php esc_html_e( 'Use simple products in a store with taxes disabled. Quote shipping and a destination country for physical products. Offers expire in 24 hours.', 'quoteflow-free' ); ?></p>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( $link ) : ?>
				<p><label for="qf-offer-link"><?php esc_html_e( 'Private link to share with the buyer', 'quoteflow-free' ); ?></label></p>
				<input id="qf-offer-link" type="url" class="large-text" readonly value="<?php echo esc_attr( $link ); ?>">
				<p><?php echo esc_html( self::offer_status( Offers::find( $token ) ) ); ?></p>
				<?php self::email_form( $token ); ?>
				<?php self::cancel_form( $token ); ?>
			<?php endif; ?>
			<?php self::inbox(); ?>
			<h2><?php esc_html_e( 'Create an offer without a request', 'quoteflow-free' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'qf_create_offer' ); ?>
				<p><label><?php esc_html_e( 'Product ID', 'quoteflow-free' ); ?> <input name="product_id" type="number" min="1" required></label></p>
				<p><label><?php esc_html_e( 'Quantity', 'quoteflow-free' ); ?> <input name="quantity" type="number" min="1" max="100" value="1" required></label></p>
				<p><label><?php esc_html_e( 'Negotiated total', 'quoteflow-free' ); ?> <input name="total" inputmode="decimal" required></label></p>
				<p><label><?php esc_html_e( 'Buyer billing email', 'quoteflow-free' ); ?> <input name="email" type="email" maxlength="254" required></label></p>
				<?php Delivery::quote_fields(); ?>
				<?php submit_button( __( 'Create offer', 'quoteflow-free' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function cancel_form( string $token ): void {
		$offer = Offers::find( $token );
		if ( ! $offer || 'offered' !== $offer->state ) {
			return;
		}
		?>
		<form method="post">
			<?php wp_nonce_field( 'qf_cancel_' . $offer->id ); ?>
			<input type="hidden" name="qf_action" value="cancel">
			<input type="hidden" name="offer_token" value="<?php echo esc_attr( $token ); ?>">
			<p><label><input type="checkbox" name="confirm_cancel" value="1" required>
				<?php esc_html_e( 'Cancel this offer and prevent its acceptance.', 'quoteflow-free' ); ?></label></p>
			<button class="button" type="submit"><?php esc_html_e( 'Cancel offer', 'quoteflow-free' ); ?></button>
		</form>
		<?php
	}

	private static function email_form( string $token ): void {
		$offer = Offers::find( $token );
		if ( ! $offer ) {
			return;
		}
		?>
		<p><?php echo esc_html( self::email_status( $offer->email_state ) ); ?></p>
		<?php if ( 'accepted' === $offer->email_state || 'offered' !== $offer->state || Offers::expired( $offer ) ) : ?>
			<?php return; ?>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'qf_email_' . $offer->id . '_' . $offer->email_attempts ); ?>
			<input type="hidden" name="qf_action" value="email">
			<input type="hidden" name="offer_token" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="email_attempt" value="<?php echo (int) $offer->email_attempts; ?>">
			<p><?php
				/* translators: %s: Buyer billing email. */
				echo esc_html( sprintf( __( 'Send this offer to %s.', 'quoteflow-free' ), $offer->email ) );
			?></p>
			<?php if ( 'sending' === $offer->email_state ) : ?>
				<p><label><input type="checkbox" name="confirm_retry" value="1" required>
					<?php esc_html_e( 'I checked the mail logs and want to try sending again.', 'quoteflow-free' ); ?></label></p>
			<?php endif; ?>
			<button class="button" type="submit"><?php esc_html_e( 'Send offer by email', 'quoteflow-free' ); ?></button>
		</form>
		<?php
	}

	private static function email_status( string $state ): string {
		$labels = array(
			'idle'     => __( 'Offer email not requested.', 'quoteflow-free' ),
			'sending'  => __( 'Email in progress or result unknown. Check the mail logs before retrying.', 'quoteflow-free' ),
			'accepted' => __( 'Offer email accepted by the mail service.', 'quoteflow-free' ),
			'failed'   => __( 'The mail service did not accept the offer email.', 'quoteflow-free' ),
		);
		return $labels[ $state ] ?? __( 'Email result unknown.', 'quoteflow-free' );
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
						<td>
							<?php foreach ( Offers::items( $request ) as $item ) : ?>
								<p><?php echo esc_html( $item['product_label'] ); ?> × <?php echo (int) $item['quantity']; ?></p>
							<?php endforeach; ?>
							<?php echo nl2br( esc_html( $request->note ) ); ?>
						</td>
						<td>
							<?php if ( $request->offer_id ) : ?>
								<p><?php echo wp_kses_post( wc_price( $request->total, array( 'currency' => $request->currency ) ) ); ?>
								· <?php echo esc_html( self::offer_status( $request ) ); ?></p>
								<p><?php echo esc_html( self::email_status( $request->email_state ) ); ?></p>
							<?php endif; ?>
							<form method="post">
								<?php wp_nonce_field( 'qf_create_offer' ); ?>
								<input type="hidden" name="request_id" value="<?php echo (int) $request->id; ?>">
								<?php if ( ! $request->offer_id ) : ?>
									<?php $items = Offers::items( $request ); ?>
									<?php foreach ( $items as $position => $item ) : ?>
										<p><label>
											<?php echo esc_html( $item['product_label'] ); ?>
											<?php esc_html_e( 'Line total', 'quoteflow-free' ); ?>
											<input name="<?php echo esc_attr( count( $items ) === 1 ? 'total' : "totals[{$position}]" ); ?>" inputmode="decimal" required>
										</label></p>
									<?php endforeach; ?>
									<?php if ( Delivery::required( $request ) ) : ?>
										<?php Delivery::quote_fields(); ?>
									<?php endif; ?>
								<?php endif; ?>
								<button class="button" type="submit"><?php echo $request->offer_id ? esc_html__( 'View offer', 'quoteflow-free' ) : esc_html__( 'Create offer', 'quoteflow-free' ); ?></button>
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
		$state = $request->offer_state ?? $request->state;
		if ( 'offered' === $state && Offers::expired( $request ) ) {
			return __( 'Expired', 'quoteflow-free' );
		}
		$labels = array(
			'offered'   => __( 'Awaiting acceptance', 'quoteflow-free' ),
			'creating'  => __( 'Order awaiting review', 'quoteflow-free' ),
			'converted' => __( 'Order created', 'quoteflow-free' ),
			'rejected'  => __( 'Declined by the buyer', 'quoteflow-free' ),
			'canceled'  => __( 'Canceled by the store', 'quoteflow-free' ),
		);
		return $labels[ $state ] ?? __( 'Awaiting review', 'quoteflow-free' );
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
			$decision = filter_input( INPUT_POST, 'decision', FILTER_UNSAFE_RAW );
			if ( null === $decision ) {
				$decision = ''; // Older forms submitted only the acceptance nonce.
			}
			if ( ! is_string( $decision ) || ! in_array( $decision, array( '', 'accept', 'decline' ), true ) ) {
				wp_die( esc_html__( 'Invalid offer decision.', 'quoteflow-free' ), '', array( 'response' => 422 ) );
			}
			try {
				if ( 'decline' === $decision ) {
					Offers::close( $token, 'rejected' );
					$offer = Offers::find( $token );
				} else {
					$address = filter_input( INPUT_POST, 'delivery', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
					$order = Offers::accept( $token, is_array( $address ) ? $address : array() );
					if ( ! $order->needs_payment() ) {
						wp_die( esc_html__( 'This order no longer needs payment.', 'quoteflow-free' ), '', array( 'response' => 200 ) );
					}
					wp_safe_redirect( $order->get_checkout_payment_url(), 303 );
					exit;
				}
			} catch ( RuntimeException $e ) {
				wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 409 ) );
			} catch ( Throwable $e ) {
				wp_die( esc_html__( 'The order needs review. Contact the store before trying to pay.', 'quoteflow-free' ), '', array( 'response' => 500 ) );
			}
		}
		if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'POST' ), true ) ) {
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
			<table>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Product', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quantity', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Line total', 'quoteflow-free' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( Offers::items( $offer ) as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item['product_label'] ); ?></td>
						<td><?php echo (int) $item['quantity']; ?></td>
						<td><?php echo wp_kses_post( wc_price( $item['total'], array( 'currency' => $offer->currency ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( Delivery::required( $offer ) ) : ?>
				<p><?php esc_html_e( 'Quoted shipping', 'quoteflow-free' ); ?>
					<?php echo wp_kses_post( wc_price( $offer->shipping_total, array( 'currency' => $offer->currency ) ) ); ?>
					· <?php echo esc_html( Delivery::countries()[ $offer->shipping_country ] ?? $offer->shipping_country ); ?></p>
			<?php endif; ?>
			<p><?php esc_html_e( 'Total', 'quoteflow-free' ); ?> <?php echo wp_kses_post( wc_price( $offer->total, array( 'currency' => $offer->currency ) ) ); ?></p>
			<?php if ( in_array( $offer->state, array( 'rejected', 'canceled' ), true ) ) : ?>
				<p role="status"><?php echo 'rejected' === $offer->state
					? esc_html__( 'You declined this offer. Contact the store if you need a new quote.', 'quoteflow-free' )
					: esc_html__( 'The store canceled this offer. Contact the store if you need a new quote.', 'quoteflow-free' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'qf_accept_' . $token, 'qf_nonce', false ); ?>
					<?php Delivery::form( $offer ); ?>
					<button type="submit" name="decision" value="accept"><?php esc_html_e( 'Accept and continue to payment', 'quoteflow-free' ); ?></button>
					<?php if ( 'offered' === $offer->state ) : ?>
						<button type="submit" name="decision" value="decline" formnovalidate><?php esc_html_e( 'Decline offer', 'quoteflow-free' ); ?></button>
					<?php endif; ?>
				</form>
			<?php endif; ?>
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
