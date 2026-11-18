<?php
/**
 * Test-only transport for the disposable local M0 store.
 * Captures every mail call and prevents real delivery while installed.
 */
defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_wp_mail',
	static function ( $result, array $message ) {
		$mode = get_option( 'qf_test_mail_mode', 'accept' );
		file_put_contents( '/tmp/quoteflow-test-mail.jsonl', wp_json_encode( $message ) . PHP_EOL, FILE_APPEND | LOCK_EX );
		if ( 'throw' === $mode ) {
			throw new RuntimeException( 'Simulated transport interruption' );
		}
		return 'fail' !== $mode;
	},
	10,
	2
);
