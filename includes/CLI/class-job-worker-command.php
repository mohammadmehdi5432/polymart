<?php
/**
 * WP-CLI long-running translation job worker.
 *
 * @package PolymartAI\CLI
 */

namespace PolymartAI\CLI;

use PolymartAI\Activity_Logger;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Class Job_Worker_Command
 */
final class Job_Worker_Command {

	/**
	 * Run the long-running auto-translate CLI worker.
	 *
	 * Processes queue slices back-to-back until the job finishes, is paused,
	 * or the time/memory budget is reached. Server cron only keep-alives this.
	 *
	 * ## OPTIONS
	 *
	 * [--max-seconds=<n>]
	 * : Wall-clock budget for this process (default: 3600). 0 = unlimited.
	 *
	 * [--max-steps=<n>]
	 * : Max slices to process (default: 0 = unlimited).
	 *
	 * ## EXAMPLES
	 *
	 *     wp polymart-ai job-worker
	 *     wp polymart-ai job-worker --max-seconds=1800
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ) {
		unset( $args );

		$max_seconds = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-seconds', 3600 ) );
		$max_steps   = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-steps', 0 ) );

		WP_CLI::log( 'Starting PolyMartAI long-running job worker…' );

		$result = Activity_Logger::run_cli_worker(
			array(
				'max_seconds' => $max_seconds,
				'max_steps'   => $max_steps,
			)
		);

		if ( ! is_array( $result ) ) {
			WP_CLI::success( 'Worker finished.' );
			return;
		}

		WP_CLI::log( sprintf( 'exit_reason: %s', (string) ( $result['exit_reason'] ?? 'done' ) ) );
		WP_CLI::log( sprintf( 'steps_run: %d', (int) ( $result['steps_run'] ?? 0 ) ) );

		if ( ! empty( $result['busy'] ) ) {
			WP_CLI::warning( 'Another CLI worker is already running.' );
			return;
		}

		WP_CLI::success( 'Worker finished.' );
	}
}
