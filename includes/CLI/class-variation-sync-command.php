<?php
/**
 * WP-CLI maintenance commands for PolyMartAI.
 *
 * @package PolymartAI\CLI
 */

namespace PolymartAI\CLI;

use PolymartAI\Translation\WooCommerce\Variation_Translation_Sync;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Class Variation_Sync_Command
 */
final class Variation_Sync_Command {

	/**
	 * Re-sync WVE variation translation meta keys with the canonical storage layout.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report changes without writing to the database.
	 *
	 * [--lang=<code>]
	 * : Limit to one target language (default: all configured targets).
	 *
	 * [--batch-size=<number>]
	 * : Variations per batch (default: 200, max: 500).
	 *
	 * ## EXAMPLES
	 *
	 *     wp polymart-ai resync-variations
	 *     wp polymart-ai resync-variations --dry-run
	 *     wp polymart-ai resync-variations --lang=en --batch-size=100
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ) {
		unset( $args );

		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$lang    = sanitize_key( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'lang', '' ) );
		$limit   = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 200 ) );

		WP_CLI::log(
			sprintf(
				'Starting variation translation re-sync (%s)...',
				$dry_run ? 'dry-run' : 'write'
			)
		);

		$result = Variation_Translation_Sync::run_all(
			array(
				'dry_run' => (bool) $dry_run,
				'lang'    => $lang,
				'limit'   => $limit,
			)
		);

		$stats = isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array();

		foreach ( $stats as $key => $value ) {
			if ( is_scalar( $value ) ) {
				WP_CLI::log( sprintf( '%s: %s', $key, (string) $value ) );
			}
		}

		WP_CLI::success( (string) ( $result['message'] ?? 'Variation translation re-sync finished.' ) );
	}
}
