<?php

namespace Latse\QuoteFlow;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Requests {
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'qf_requests';
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				submission_id char(36) NOT NULL,
				payload_hash char(64) NOT NULL,
				rate_key char(64) NOT NULL,
				product_id bigint unsigned NOT NULL,
				product_label text NOT NULL,
				items_json longtext DEFAULT NULL,
				quantity int unsigned NOT NULL,
				email varchar(254) NOT NULL,
				note text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY submission_id (submission_id),
				KEY rate_key (rate_key),
				KEY email (email)
			) ENGINE=InnoDB {$collate};"
		);
	}

	public static function submit( string $submission, int $product_id, int $quantity, string $email, string $note, string $address ): void {
		self::submit_items( $submission, array( array( 'product_id' => $product_id, 'quantity' => $quantity ) ), $email, $note, $address );
	}

	public static function submit_items( string $submission, array $items, string $email, string $note, string $address ): void {
		global $wpdb;
		if ( ! wp_is_uuid( $submission ) || ! is_email( $email ) || strlen( $email ) > 254 || mb_strlen( $note, 'UTF-8' ) > 2000 ) {
			throw new RuntimeException( __( 'Enter a valid email and a note of up to 2000 characters.', 'quoteflow-free' ) );
		}
		$snapshot = Offers::snapshot( $items );
		$identity = array();
		foreach ( $snapshot as $item ) {
			$line = array( $item['product_id'], $item['quantity'] );
			if ( ! empty( $item['attributes'] ) ) {
				$line[] = $item['attributes'];
			}
			$identity[] = $line;
		}
		// Preserve the hash format of earlier single-product submissions.
		$data    = count( $identity ) === 1 && count( $identity[0] ) === 2 ? array( $identity[0][0], $identity[0][1], $email, $note ) : array( $identity, $email, $note );
		$payload = hash( 'sha256', wp_json_encode( $data ) );
		$rate    = hash_hmac( 'sha256', $address . '|' . (int) floor( time() / 600 ), wp_salt( 'nonce' ) );
		$lock    = 'qf_request_' . substr( $rate, 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			throw new RuntimeException( __( 'The request is being processed. Please try again.', 'quoteflow-free' ) );
		}
		try {
			$table = $wpdb->prefix . 'qf_requests';
			$saved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %s", $submission ) );
			if ( $saved ) {
				if ( ! hash_equals( $saved->payload_hash, $payload ) ) {
					throw new RuntimeException( __( 'This form was already submitted. Reload it to send a different request.', 'quoteflow-free' ) );
				}
				return;
			}
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE rate_key = %s", $rate ) ) >= 5 ) {
				throw new RuntimeException( __( 'Too many requests. Please wait ten minutes and try again.', 'quoteflow-free' ), 429 );
			}
			$inserted = $wpdb->insert(
				$table,
				array(
					'submission_id' => $submission,
					'payload_hash'  => $payload,
					'rate_key'      => $rate,
					'product_id'    => $snapshot[0]['product_id'],
					'product_label' => $snapshot[0]['product_label'],
					'quantity'      => $snapshot[0]['quantity'],
					'items_json'    => wp_json_encode( $snapshot ),
					'email'         => $email,
					'note'          => $note,
					'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( 1 !== $inserted ) {
				throw new RuntimeException( __( 'The request could not be saved. Please try again.', 'quoteflow-free' ) );
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function inbox( int $page ): array {
		global $wpdb;
		$requests = $wpdb->prefix . 'qf_requests';
		$offers   = $wpdb->prefix . 'qf_offers';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, o.id AS offer_id, o.state AS offer_state, o.total, o.currency, o.expires_at, o.order_id, o.email_state
				FROM {$requests} r LEFT JOIN {$offers} o ON o.request_id = r.id
				ORDER BY r.id DESC LIMIT 20 OFFSET %d",
				( max( 1, $page ) - 1 ) * 20
			)
		);
	}

	public static function respond( int $request_id, string|array $total, string $shipping_total = '0', string $shipping_country = '' ): string {
		global $wpdb;
		$table   = $wpdb->prefix . 'qf_requests';
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $request_id ) );
		if ( ! $request ) {
			throw new RuntimeException( __( 'Request not found.', 'quoteflow-free' ) );
		}
		// Derive the private link so an authorized operator can recover it after a lost response.
		$token = hash_hmac( 'sha256', 'quoteflow-request-offer|' . $request->submission_id, wp_salt( 'auth' ) );
		$lock  = 'qf_reply_' . md5( $table . ':' . $request_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			throw new RuntimeException( __( 'The offer is being prepared. Please try again.', 'quoteflow-free' ) );
		}
		try {
			// A privacy erasure may have removed the request while we waited for its lock.
			$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $request_id ) );
			if ( ! $request ) {
				throw new RuntimeException( __( 'Request not found.', 'quoteflow-free' ) );
			}
			$offers = $wpdb->prefix . 'qf_offers';
			$saved  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers} WHERE request_id = %d", $request_id ) );
			if ( $saved ) {
				if ( ! hash_equals( $saved->token_hash, hash( 'sha256', $token ) ) ) {
					throw new RuntimeException( __( 'The store security keys changed. The original private link is required.', 'quoteflow-free' ) );
				}
				return $token;
			}
			$items  = Offers::items( $request );
			$totals = is_array( $total ) ? $total : array( $total );
			if ( count( $items ) !== count( $totals ) || ! array_is_list( $totals ) ) {
				throw new RuntimeException( __( 'Enter a total for each requested product.', 'quoteflow-free' ) );
			}
			foreach ( $items as $position => &$item ) {
				$item['total'] = $totals[ $position ];
			}
			unset( $item );
			return Offers::create_items( $items, $request->email, $request_id, $token, $shipping_total, $shipping_country );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
