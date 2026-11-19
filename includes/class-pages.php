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
						throw new RuntimeException( __( 'Offer not found.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
					}
					check_admin_referer( 'qf_email_' . $offer->id . '_' . $attempt );
					Offer_Email::send( self::$token, $attempt, '1' === self::input( INPUT_POST, 'confirm_retry' ) );
					self::$notice = __( 'The mail service accepted the offer email. Receipt depends on the recipient mail server.', 'quoteflow-free' );
				} elseif ( 'cancel' === self::input( INPUT_POST, 'qf_action' ) ) {
					self::$token = self::input( INPUT_POST, 'offer_token' );
					$offer = Offers::find( self::$token );
					if ( ! $offer ) {
						throw new RuntimeException( __( 'Offer not found.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
					}
					check_admin_referer( 'qf_cancel_' . $offer->id );
					if ( '1' !== self::input( INPUT_POST, 'confirm_cancel' ) ) {
						throw new RuntimeException( __( 'Confirm that you want to cancel this offer.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
					}
					Offers::close( self::$token, 'canceled', get_current_user_id() );
					self::$notice = __( 'The offer was canceled.', 'quoteflow-free' );
				} elseif ( 'view_manual' === self::input( INPUT_POST, 'qf_action' ) ) {
					$offer_id = (int) self::input( INPUT_POST, 'offer_id' );
					check_admin_referer( 'qf_view_manual_' . $offer_id );
					self::$token = Offers::recover_manual_token( $offer_id );
				} else {
					$request_id = (int) self::input( INPUT_POST, 'request_id' );
					if ( $request_id > 0 ) {
						check_admin_referer( 'qf_create_offer' );
						$totals = filter_input( INPUT_POST, 'totals', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
						self::$token = Requests::respond(
							$request_id,
							is_array( $totals ) ? $totals : self::input( INPUT_POST, 'total' ),
							self::input( INPUT_POST, 'shipping_total' ) ?: '0',
							self::input( INPUT_POST, 'shipping_country' )
						);
					} else {
						$creation = self::input( INPUT_POST, 'creation_id' );
						if ( ! wp_is_uuid( $creation ) ) {
							throw new RuntimeException( __( 'Reload the form before creating an offer.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
						}
						check_admin_referer( 'qf_create_manual_' . $creation );
						$product_id = filter_var( self::input( INPUT_POST, 'product_id' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
						$quantity = filter_var( self::input( INPUT_POST, 'quantity' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 100 ) ) );
						if ( false === $product_id || false === $quantity ) {
							throw new RuntimeException( __( 'Enter a valid product ID and a whole quantity from 1 to 100.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
						}
						self::$token = Offers::create_manual(
							$creation,
							$product_id,
							$quantity,
							self::input( INPUT_POST, 'total' ),
							self::input( INPUT_POST, 'email' ),
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
			<p><?php esc_html_e( 'Use simple products or selected variations. Negotiated amounts are final totals including applicable taxes. Quote shipping and a destination country for physical products. Offers expire in 24 hours.', 'quoteflow-free' ); ?></p>
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
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'QuoteFlow lists', 'quoteflow-free' ); ?>">
				<a class="nav-tab <?php echo 'manual' !== self::input( INPUT_GET, 'qf_view' ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'page', 'quoteflow', admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Quote requests', 'quoteflow-free' ); ?></a>
				<a class="nav-tab <?php echo 'manual' === self::input( INPUT_GET, 'qf_view' ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'quoteflow', 'qf_view' => 'manual' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Manual offers', 'quoteflow-free' ); ?></a>
			</nav>
			<?php 'manual' === self::input( INPUT_GET, 'qf_view' ) ? self::manual_offers() : self::inbox(); ?>
			<h2><?php esc_html_e( 'Create an offer without a request', 'quoteflow-free' ); ?></h2>
			<?php $creation = wp_generate_uuid4(); ?>
			<form method="post">
				<?php wp_nonce_field( 'qf_create_manual_' . $creation ); ?>
				<input type="hidden" name="creation_id" value="<?php echo esc_attr( $creation ); ?>">
				<p><label><?php esc_html_e( 'Product or variation ID', 'quoteflow-free' ); ?> <input name="product_id" type="number" min="1" required></label></p>
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
		$filters = Requests::inbox_filters( self::input( INPUT_GET, 'qf_search' ), self::input( INPUT_GET, 'qf_status' ) );
		$total = Requests::inbox_count( $filters['search'], $filters['state'] );
		$pages = max( 1, (int) ceil( $total / 20 ) );
		$page = min( $pages, max( 1, (int) self::input( INPUT_GET, 'qf_page' ) ) );
		$requests = Requests::inbox( $page, $filters['search'], $filters['state'] );
		$args = array( 'page' => 'quoteflow', 'qf_search' => $filters['search'], 'qf_status' => $filters['state'] );
		?>
		<h2><?php esc_html_e( 'Quote requests', 'quoteflow-free' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="quoteflow">
			<p><label for="qf-search"><?php esc_html_e( 'Buyer email or request number', 'quoteflow-free' ); ?></label>
				<input id="qf-search" name="qf_search" type="search" maxlength="254" value="<?php echo esc_attr( $filters['search'] ); ?>">
				<label for="qf-status"><?php esc_html_e( 'Request status', 'quoteflow-free' ); ?></label>
				<select id="qf-status" name="qf_status">
					<?php foreach ( Requests::inbox_states() as $state => $label ) : ?>
						<option value="<?php echo esc_attr( $state ); ?>" <?php selected( $state, $filters['state'] ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				<button type="submit" class="button"><?php esc_html_e( 'Filter requests', 'quoteflow-free' ); ?></button>
				<a href="<?php echo esc_url( add_query_arg( 'page', 'quoteflow', admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear filters', 'quoteflow-free' ); ?></a>
			</p>
		</form>
		<p id="qf-inbox-summary"><?php
			/* translators: 1: Matching requests, 2: Current page, 3: Total pages. */
			echo esc_html( sprintf( _n( '%1$d request. Page %2$d of %3$d.', '%1$d requests. Page %2$d of %3$d.', $total, 'quoteflow-free' ), $total, $page, $pages ) );
		?></p>
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
								<?php self::order_link( (int) $request->order_id ); ?>
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
				<a class="button" href="<?php echo esc_url( add_query_arg( $args + array( 'qf_page' => $page - 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous requests', 'quoteflow-free' ); ?></a>
			<?php endif; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $args + array( 'qf_page' => $page + 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Older requests', 'quoteflow-free' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function manual_offers(): void {
		$total = Offers::manual_count();
		$pages = max( 1, (int) ceil( $total / 20 ) );
		$page = min( $pages, max( 1, (int) self::input( INPUT_GET, 'qf_manual_page' ) ) );
		$offers = Offers::manual_list( $page );
		?>
		<h2><?php esc_html_e( 'Manual offers', 'quoteflow-free' ); ?></h2>
		<p><?php esc_html_e( 'Open a manual offer to recover its private link, check its email status or cancel it. Older offers may require the original link.', 'quoteflow-free' ); ?></p>
		<p id="qf-manual-summary"><?php
			/* translators: 1: Manual offers, 2: Current page, 3: Total pages. */
			echo esc_html( sprintf( _n( '%1$d manual offer. Page %2$d of %3$d.', '%1$d manual offers. Page %2$d of %3$d.', $total, 'quoteflow-free' ), $total, $page, $pages ) );
		?></p>
		<?php if ( ! $offers ) : ?>
			<p><?php esc_html_e( 'No manual offers yet.', 'quoteflow-free' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Offer', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Buyer', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'quoteflow-free' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status and actions', 'quoteflow-free' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $offers as $offer ) : ?>
					<tr>
						<td>#<?php echo (int) $offer->id; ?></td>
						<td><?php echo esc_html( $offer->email ); ?></td>
						<td>
							<?php foreach ( Offers::items( $offer ) as $item ) : ?>
								<p><?php echo esc_html( $item['product_label'] ); ?> × <?php echo (int) $item['quantity']; ?></p>
							<?php endforeach; ?>
							<p><?php echo wp_kses_post( wc_price( $offer->total, array( 'currency' => $offer->currency ) ) ); ?></p>
						</td>
						<td>
							<p><?php echo esc_html( self::offer_status( $offer ) ); ?></p>
							<p><?php echo esc_html( self::email_status( $offer->email_state ) ); ?></p>
							<?php self::order_link( (int) $offer->order_id ); ?>
							<form method="post">
								<?php wp_nonce_field( 'qf_view_manual_' . $offer->id ); ?>
								<input type="hidden" name="qf_action" value="view_manual">
								<input type="hidden" name="offer_id" value="<?php echo (int) $offer->id; ?>">
								<button type="submit" class="button"><?php esc_html_e( 'View offer', 'quoteflow-free' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p>
			<?php foreach ( array( $page - 1 => __( 'Newer offers', 'quoteflow-free' ), $page + 1 => __( 'Older offers', 'quoteflow-free' ) ) as $target => $label ) : ?>
				<?php if ( $target >= 1 && $target <= $pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'quoteflow', 'qf_view' => 'manual', 'qf_manual_page' => $target ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</p>
		<?php
	}

	private static function order_link( int $order_id ): void {
		if ( $order_id < 1 || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		?>
		<p><a class="button qf-order-link" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php
			/* translators: %s: WooCommerce order number. */
			echo esc_html( sprintf( __( 'Open order #%s', 'quoteflow-free' ), $order->get_order_number() ) );
		?></a></p>
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
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );

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
					$mode = filter_input( INPUT_POST, 'billing_mode', FILTER_UNSAFE_RAW );
					if ( null !== $mode && ! in_array( $mode, array( 'same', 'separate' ), true ) ) {
						throw new RuntimeException( __( 'Choose a valid billing address option.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
					}
					$billing = 'separate' === $mode ? filter_input( INPUT_POST, 'billing', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) : null;
					if ( 'separate' === $mode && ! is_array( $billing ) ) {
						throw new RuntimeException( __( 'Complete the billing address.', 'quoteflow-free' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the UI boundary.
					}
					$order = Offers::accept( $token, is_array( $address ) ? $address : array(), $billing );
					if ( ! $order->needs_payment() ) {
						wp_die( esc_html__( 'This order no longer needs payment.', 'quoteflow-free' ), '', array( 'response' => 200 ) );
					}
					// Native checkout context keeps this order's own hold out of cart stock checks.
					if ( WC()->session ) {
						WC()->session->set( 'order_awaiting_payment', $order->get_id() );
						if ( method_exists( WC()->session, 'set_customer_session_cookie' ) ) { WC()->session->set_customer_session_cookie( true ); }
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
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php esc_html_e( 'Your offer', 'quoteflow-free' ); ?></title>
		<?php wp_register_style( 'quoteflow-offer', plugins_url( 'assets/offer.css', dirname( __DIR__ ) . '/quoteflow-free.php' ), array(), QUOTEFLOW_FREE_VERSION ); wp_print_styles( 'quoteflow-offer' ); ?>
		</head>
		<body class="qf-offer">
		<main>
			<h1><?php esc_html_e( 'Your offer', 'quoteflow-free' ); ?></h1>
			<p><?php esc_html_e( 'The negotiated total includes applicable taxes. The tax breakdown is calculated from your address when you accept.', 'quoteflow-free' ); ?></p>
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
				<?php Delivery::address_options( $offer, $token ); ?>
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
