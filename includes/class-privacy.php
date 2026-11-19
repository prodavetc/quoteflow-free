<?php

namespace Latse\QuoteFlow;

use RuntimeException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	private const BATCH = 20;

	public static function register(): void {
		add_filter( 'woocommerce_privacy_erase_order_personal_data', static function ( $erase, $order ) {
			return $order->get_meta( '_qf_conversion_id' ) && ! self::terminal( $order ) ? false : $erase;
		}, 10, 2 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', static function ( $order ) {
			global $wpdb;
			if ( ! self::terminal( $order ) || ! $order->get_meta( '_qf_conversion_id' ) ) { return; }
			$table = $wpdb->prefix . 'qf_offers';
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d AND conversion_id = %s", $order->get_id(), $order->get_meta( '_qf_conversion_id' ) ) );
			if ( $id ) { self::cleanup_order( $id ); }
		} );
		add_action( 'qf_privacy_cleanup', array( self::class, 'cleanup_order' ) );
		add_filter( 'wp_privacy_personal_data_exporters', static function ( array $exporters ): array {
			$exporters['quoteflow'] = array(
				'exporter_friendly_name' => __( 'QuoteFlow requests and offers', 'quoteflow-free' ),
				'callback' => array( self::class, 'export' ),
			);
			return $exporters;
		} );
		add_filter( 'wp_privacy_personal_data_erasers', static function ( array $erasers ): array {
			$erasers['quoteflow'] = array(
				'eraser_friendly_name' => __( 'QuoteFlow requests and offers', 'quoteflow-free' ),
				'callback' => array( self::class, 'erase' ),
			);
			return $erasers;
		}, 100 );
	}

	private static function terminal( $order ): bool {
		return $order instanceof \WC_Order && $order->has_status( array( 'completed', 'cancelled', 'refunded' ) ) && ! $order->needs_payment();
	}

	public static function cleanup_order( int $offer_id ): void {
		global $wpdb;
		$offer = null;
		try {
			$rows = self::rows( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qf_offers WHERE id = %d", $offer_id ) );
			$offer = $rows[0] ?? null;
			if ( ! $offer ) { wp_clear_scheduled_hook( 'qf_privacy_cleanup', array( $offer_id ) ); return; }
			$order = wc_get_order( (int) $offer->order_id );
			if ( ! self::terminal( $order ) || 'yes' !== $order->get_meta( '_anonymized' ) || $offer->conversion_id !== $order->get_meta( '_qf_conversion_id' ) ) { return; }
			if ( ! self::erase_offer( $offer, $offer->email, true ) ) { throw new RuntimeException(); }
			wp_clear_scheduled_hook( 'qf_privacy_cleanup', array( $offer_id ) );
			$order->delete_meta_data( '_qf_privacy_cleanup_pending' );
			$order->save();
		} catch ( Throwable $e ) {
			// Keep only the record ID in the retry event; no email or address enters cron.
			if ( ! wp_next_scheduled( 'qf_privacy_cleanup', array( $offer_id ) ) ) {
				wp_schedule_single_event( time() + 300, 'qf_privacy_cleanup', array( $offer_id ) );
			}
			if ( $offer && $offer->order_id ) {
				$order = wc_get_order( (int) $offer->order_id );
				if ( $order && ! $order->get_meta( '_qf_privacy_cleanup_pending' ) ) {
					$order->update_meta_data( '_qf_privacy_cleanup_pending', 'yes' ); $order->save();
					$order->add_order_note( __( 'QuoteFlow privacy cleanup is pending. It will retry through WordPress cron.', 'quoteflow-free' ) );
				}
			}
		}
	}

	public static function export( string $email, int $page = 1 ): array|WP_Error {
		global $wpdb;
		if ( ! is_email( $email ) || $page < 1 ) {
			return new WP_Error( 'qf_privacy_input', __( 'Enter a valid email and page.', 'quoteflow-free' ) );
		}
		$data = array();
		$done = true;
		$labels = array(
			'id' => __( 'Record ID', 'quoteflow-free' ),
			'email' => __( 'Email', 'quoteflow-free' ),
			'note' => __( 'Buyer note', 'quoteflow-free' ),
			'created_at' => __( 'Requested at (UTC)', 'quoteflow-free' ),
			'product_label' => __( 'First product', 'quoteflow-free' ),
			'quantity' => __( 'First product quantity', 'quoteflow-free' ),
			'items_json' => __( 'Quoted or requested items (JSON)', 'quoteflow-free' ),
			'request_id' => __( 'Related request ID', 'quoteflow-free' ),
			'state' => __( 'Offer state', 'quoteflow-free' ),
			'total' => __( 'Total', 'quoteflow-free' ),
			'currency' => __( 'Currency', 'quoteflow-free' ),
			'shipping_total' => __( 'Quoted shipping total', 'quoteflow-free' ),
			'shipping_country' => __( 'Quoted shipping country', 'quoteflow-free' ),
			'accepted_address_json' => __( 'Accepted address (JSON)', 'quoteflow-free' ),
			'accepted_billing_json' => __( 'Accepted billing address (JSON)', 'quoteflow-free' ),
			'expires_at' => __( 'Expires at (UTC)', 'quoteflow-free' ),
			'accepted_at' => __( 'Accepted at (UTC)', 'quoteflow-free' ),
			'closed_at' => __( 'Closed at (UTC)', 'quoteflow-free' ),
			'email_state' => __( 'Offer email state', 'quoteflow-free' ),
			'email_attempts' => __( 'Offer email attempts', 'quoteflow-free' ),
			'email_attempted_at' => __( 'Email attempted at (UTC)', 'quoteflow-free' ),
			'email_accepted_at' => __( 'Email accepted by transport at (UTC)', 'quoteflow-free' ),
			'order_id' => __( 'WooCommerce order ID', 'quoteflow-free' ),
		);
		try {
			foreach ( array( 'requests', 'offers' ) as $kind ) {
				$table = $wpdb->prefix . 'qf_' . $kind;
				$rows = self::rows( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id LIMIT %d OFFSET %d", $email, self::BATCH, ( $page - 1 ) * self::BATCH ) );
				$done = $done && count( $rows ) < self::BATCH;
				foreach ( $rows as $row ) {
					$fields = array();
					foreach ( $labels as $key => $label ) {
						if ( isset( $row->$key ) ) {
							$fields[] = array( 'name' => $label, 'value' => (string) $row->$key );
						}
					}
					$data[] = array(
						'group_id' => 'quoteflow-' . $kind,
						'group_label' => 'requests' === $kind ? __( 'QuoteFlow requests', 'quoteflow-free' ) : __( 'QuoteFlow offers', 'quoteflow-free' ),
						'item_id' => 'quoteflow-' . $kind . '-' . $row->id,
						'data' => $fields,
					);
				}
			}
		} catch ( Throwable $e ) {
			return self::error();
		}
		return array( 'data' => $data, 'done' => $done );
	}

	public static function erase( string $email, int $page = 1 ): array|WP_Error {
		global $wpdb;
		if ( ! is_email( $email ) || $page < 1 ) {
			return new WP_Error( 'qf_privacy_input', __( 'Enter a valid email and page.', 'quoteflow-free' ) );
		}
		$requests = $wpdb->prefix . 'qf_requests';
		$offers = $wpdb->prefix . 'qf_offers';
		$removed = false;
		try {
			// Always consume the first remaining eligible batch: OFFSET would skip deleted rows.
			$eligible = $wpdb->prepare(
				"FROM {$offers} o LEFT JOIN {$requests} r ON r.id = o.request_id
				WHERE o.email = %s AND o.order_id IS NULL AND o.accepted_at IS NULL
				AND o.email_state <> 'sending'
				AND (o.state IN ('rejected', 'canceled') OR (o.state = 'offered' AND o.expires_at <= %s))
				AND (r.id IS NULL OR r.email = o.email)",
				$email, gmdate( 'Y-m-d H:i:s' )
			);
			foreach ( self::rows( "SELECT o.* {$eligible} ORDER BY o.id LIMIT " . self::BATCH ) as $offer ) {
				$removed = self::erase_offer( $offer, $email ) || $removed;
			}
			$unanswered = $wpdb->prepare(
				"FROM {$requests} r WHERE r.email = %s AND NOT EXISTS (SELECT 1 FROM {$offers} o WHERE o.request_id = r.id)", $email
			);
			foreach ( self::rows( "SELECT r.id {$unanswered} ORDER BY r.id LIMIT " . self::BATCH ) as $request ) {
				$lock = 'qf_reply_' . md5( $requests . ':' . $request->id );
				self::lock( $lock );
				try {
					// Recheck after waiting for any reply or another privacy worker.
					$row = self::rows( "SELECT r.id {$unanswered} AND r.id = " . (int) $request->id );
					if ( $row ) {
						if ( 1 !== $wpdb->delete( $requests, array( 'id' => $request->id ) ) ) {
							throw new RuntimeException();
						}
						$removed = true;
					}
				} finally {
					self::unlock( $lock );
				}
			}
			$done = ! self::rows( "SELECT o.id {$eligible} LIMIT 1" ) && ! self::rows( "SELECT r.id {$unanswered} LIMIT 1" );
			$retained = $done && ( self::rows( $wpdb->prepare( "SELECT id FROM {$offers} WHERE email = %s LIMIT 1", $email ) )
				|| self::rows( $wpdb->prepare( "SELECT id FROM {$requests} WHERE email = %s LIMIT 1", $email ) ) );
		} catch ( Throwable $e ) {
			return self::error();
		}
		return array(
			'items_removed' => $removed,
			'items_retained' => (bool) $retained,
			'messages' => $retained ? array( __( 'QuoteFlow retained active offers, records linked to orders or conversions, and records requiring review (including an unknown email result). Close active offers and review the related WooCommerce orders or mail logs before retrying erasure. WooCommerce manages order data separately.', 'quoteflow-free' ) ) : array(),
			'done' => $done,
		);
	}

	private static function erase_offer( object $candidate, string $email, bool $anonymized_order = false ): bool {
		global $wpdb;
		$requests = $wpdb->prefix . 'qf_requests';
		$offers = $wpdb->prefix . 'qf_offers';
		$locks = array();
		$acquired = array();
		try {
			// Reply -> email -> decision. These are the same locks used by the live flows.
			if ( $candidate->request_id ) {
				$locks[] = 'qf_reply_' . md5( $requests . ':' . $candidate->request_id );
			}
			$locks[] = 'qf_email_' . md5( $offers . ':' . $candidate->id );
			$locks[] = 'qf_' . md5( $offers . ':' . $candidate->id );
			foreach ( $locks as $lock ) {
				self::lock( $lock );
				$acquired[] = $lock;
			}
			$rows = self::rows( $wpdb->prepare( "SELECT * FROM {$offers} WHERE id = %d AND email = %s", $candidate->id, $email ) );
			$offer = $rows[0] ?? null;
			if ( ! $offer || ( ! $anonymized_order && 'sending' === $offer->email_state ) ) { return false; }
			if ( $anonymized_order ) {
				$order = wc_get_order( (int) $offer->order_id );
				if ( 'converted' !== $offer->state || ! self::terminal( $order ) || 'yes' !== $order->get_meta( '_anonymized' ) || $offer->conversion_id !== $order->get_meta( '_qf_conversion_id' ) ) { return false; }
			} elseif ( null !== $offer->order_id || null !== $offer->accepted_at
				|| ! ( in_array( $offer->state, array( 'rejected', 'canceled' ), true ) || ( 'offered' === $offer->state && Offers::expired( $offer ) ) ) ) { return false; }

			$request = $offer->request_id ? ( self::rows( $wpdb->prepare( "SELECT * FROM {$requests} WHERE id = %d", $offer->request_id ) )[0] ?? null ) : null;
			if ( $request && 0 !== strcasecmp( $request->email, $email ) ) {
				return false;
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException();
			}
			try {
				if ( 1 !== $wpdb->delete( $offers, array( 'id' => $offer->id ) )
					|| ( $request && 1 !== $wpdb->delete( $requests, array( 'id' => $request->id ) ) )
					|| false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException();
				}
			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			return true;
		} finally {
			foreach ( array_reverse( $acquired ) as $lock ) {
				self::unlock( $lock );
			}
		}
	}

	private static function rows( string $query ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $query ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Private helper receives only prepared SQL and trusted table identifiers from this class.
		if ( $wpdb->last_error ) {
			throw new RuntimeException();
		}
		return $rows;
	}

	private static function lock( string $name ): void {
		global $wpdb;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
			throw new RuntimeException();
		}
	}

	private static function unlock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	private static function error(): WP_Error {
		return new WP_Error( 'qf_privacy_retry', __( 'QuoteFlow privacy processing could not finish. A record may be in use. Wait for current operations to finish and retry; completed deletions are preserved.', 'quoteflow-free' ) );
	}
}
