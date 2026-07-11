<?php
/**
 * Long-running CLI translation job worker.
 *
 * Invoked by keep-alive cron / WP-CLI / manual shell:
 *   php bin/job-worker.php
 *   php bin/job-worker.php --max-seconds=3600
 *
 * Loads WordPress, then runs Activity_Logger::run_cli_worker() in a tight loop
 * until the queue is done, time/memory budget is hit, or the job is paused.
 *
 * @package PolymartAI
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "PolyMartAI job worker must run via CLI.\n" );
	exit( 1 );
}

$max_seconds = 3600;
$max_steps   = 0;

foreach ( array_slice( $argv ?? array(), 1 ) as $arg ) {
	if ( preg_match( '/^--max-seconds=(\d+)$/', $arg, $m ) ) {
		$max_seconds = (int) $m[1];
	} elseif ( preg_match( '/^--max-steps=(\d+)$/', $arg, $m ) ) {
		$max_steps = (int) $m[1];
	}
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "Could not find wp-load.php at {$wp_load}\n" );
	exit( 1 );
}

// Background worker — never abort mid-AI-call because the parent HTTP request ended.
if ( function_exists( 'ignore_user_abort' ) ) {
	ignore_user_abort( true );
}

if ( function_exists( 'set_time_limit' ) ) {
	set_time_limit( 0 );
}

require_once $wp_load;

if ( ! class_exists( '\PolymartAI\Activity_Logger' ) ) {
	fwrite( STDERR, "PolyMartAI is not loaded.\n" );
	exit( 1 );
}

try {
	$result = \PolymartAI\Activity_Logger::run_cli_worker(
		array(
			'max_seconds' => $max_seconds,
			'max_steps'   => $max_steps,
		)
	);
} catch ( \Throwable $e ) {
	if ( class_exists( '\PolymartAI\Activity_Logger' ) ) {
		\PolymartAI\Activity_Logger::recover_job_item_failure(
			$e->getMessage(),
			0,
			array(
				'exception' => get_class( $e ),
				'file'      => $e->getFile(),
				'line'      => $e->getLine(),
				'source'    => 'bin/job-worker.php',
			)
		);
	}

	fwrite( STDERR, 'PolyMartAI job worker exception: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

$status = is_array( $result ) ? (string) ( $result['exit_reason'] ?? 'done' ) : 'done';
$steps  = is_array( $result ) ? (int) ( $result['steps_run'] ?? 0 ) : 0;
$fails  = is_array( $result ) ? (int) ( $result['soft_fails'] ?? 0 ) : 0;

fwrite( STDOUT, "PolyMartAI job worker finished: {$status} (steps={$steps}, soft_fails={$fails})\n" );
exit( 0 );
