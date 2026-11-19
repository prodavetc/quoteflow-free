<?php

namespace Latse\QuoteFlow;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Offer_Email {
	public static function send( string $token, int $expected_attempt, bool $confirm_retry = false ): void {
		global $wpdb;
		$offer = Offers::find( $token );
		if ( ! $offer ) {
			throw new RuntimeException( __( 'Offer not found.', 'quoteflow-free' ) );
		}
		$table = $wpdb->prefix . 'qf_offers';
		$lock  = 'qf_email_' . md5( $table . ':' . $offer->id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			throw new RuntimeException( __( 'An email is being processed. Please wait and reload.', 'quoteflow-free' ) );
		}
		try {
			$offer = Offers::find( $token );
			if ( ! $offer || 'offered' !== $offer->state || Offers::expired( $offer ) ) {
				throw new RuntimeException( __( 'Only an active offer awaiting acceptance can be emailed.', 'quoteflow-free' ) );
			}
			if ( 'accepted' === $offer->email_state ) {
				return;
			}
			if ( (int) $offer->email_attempts !== $expected_attempt ) {
				throw new RuntimeException( __( 'This email attempt was already processed. Reload before trying again.', 'quoteflow-free' ) );
			}
			if ( 'sending' === $offer->email_state && ! $confirm_retry ) {
				throw new RuntimeException( __( 'The previous email result is unknown. Check the mail logs before retrying.', 'quoteflow-free' ) );
			}
			$subject = sanitize_text_field(
				sprintf(
					/* translators: %s: Store name. */
					__( 'Your quote from %s', 'quoteflow-free' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				)
			);
			$mailer  = WC()->mailer();
			$message = $mailer->wrap_message( __( 'Your quote is ready', 'quoteflow-free' ), self::body( $offer, $token ) );
			$attempt = $expected_attempt + 1;
			$claimed = $wpdb->update(
				$table,
				array( 'email_state' => 'sending', 'email_attempts' => $attempt, 'email_attempted_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => $offer->id, 'email_attempts' => $expected_attempt )
			);
			if ( 1 !== $claimed ) {
				throw new RuntimeException( __( 'The email attempt could not be saved. Please reload.', 'quoteflow-free' ) );
			}
			try {
				$accepted = $mailer->send( $offer->email, $subject, $message );
			} catch ( Throwable $e ) {
				throw new RuntimeException( __( 'The email result is unknown. Check the mail logs before retrying.', 'quoteflow-free' ) );
			}
			$updated = $wpdb->update(
				$table,
				array(
					'email_state'       => $accepted ? 'accepted' : 'failed',
					'email_accepted_at' => $accepted ? gmdate( 'Y-m-d H:i:s' ) : null,
				),
				array( 'id' => $offer->id, 'email_state' => 'sending', 'email_attempts' => $attempt )
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( __( 'The email result could not be saved. Check the mail logs before retrying.', 'quoteflow-free' ) );
			}
			if ( ! $accepted ) {
				throw new RuntimeException( __( 'The mail service did not accept the email. Check the store mail configuration and try again.', 'quoteflow-free' ) );
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function body( object $offer, string $token ): string {
		$link = add_query_arg( 'qf_offer', $token, home_url( '/' ) );
		$date = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $offer->expires_at . ' UTC' )
		);
		ob_start();
		?>
		<p><?php esc_html_e( 'Review your quote below. Open the private link to accept it and continue to payment.', 'quoteflow-free' ); ?></p>
		<table cellspacing="0" cellpadding="6" border="1" width="100%">
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
		<p><strong><?php esc_html_e( 'Total', 'quoteflow-free' ); ?></strong> <?php echo wp_kses_post( wc_price( $offer->total, array( 'currency' => $offer->currency ) ) ); ?></p>
		<p>
			<?php
			/* translators: 1: Offer expiration date, 2: Store timezone. */
			echo esc_html( sprintf( __( 'Valid until %1$s (%2$s).', 'quoteflow-free' ), $date, wp_timezone_string() ) );
			?>
		</p>
		<p><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'View and accept your quote', 'quoteflow-free' ); ?></a></p>
		<p><?php esc_html_e( 'This link is private. Share it only with the person authorized to accept the quote.', 'quoteflow-free' ); ?></p>
		<?php
		return ob_get_clean();
	}
}
