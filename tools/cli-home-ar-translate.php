<?php
/**
 * CLI: translate homepage to Arabic via Metabox Action Scheduler path.
 *
 * Usage (from plugin tools dir, Local PHP + DB port):
 *   php cli-home-ar-translate.php status
 *   php cli-home-ar-translate.php clear-start
 *   php cli-home-ar-translate.php tick 8
 *   php cli-home-ar-translate.php run 30
 *
 * @package PolymartAI
 */

if ( php_sapi_name() !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

// Local by Flywheel MySQL is not on 3306.
if ( ! defined( 'DB_HOST' ) ) {
	// wp-config defines DB_HOST as localhost — override via env before load.
}

$_SERVER['HTTP_HOST']   = 'sepi-live.local';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'sepi-live.local';

// Force Local MySQL port before wp-config constants stick.
putenv( 'POLYMART_AI_CLI=1' );

$root = dirname( __DIR__, 4 );

// Patch DB host for CLI if needed by loading a tiny drop-in.
$wp_config = $root . '/wp-config.php';
$config    = file_get_contents( $wp_config );
if ( false !== strpos( $config, "define( 'DB_HOST', 'localhost' )" ) ) {
	// Define before wp-config — WordPress only defines if not defined.
	define( 'DB_HOST', '127.0.0.1:10018' );
}

define( 'WP_USE_THEMES', false );
require $root . '/wp-load.php';

use PolymartAI\Activity_Logger;
use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Activity_Logger\Translation_Scheduler_Coordinator;
use PolymartAI\Language_Registry;
use PolymartAI\Translation\Post_Translator;

set_time_limit( 0 );

$cmd     = $argv[1] ?? 'status';
$arg2    = $argv[2] ?? '';
$post_id = absint( getenv( 'POLYMART_POST_ID' ) ?: ( $argv[3] ?? 0 ) );
$lang    = sanitize_key( (string) ( getenv( 'POLYMART_LANG' ) ?: 'ar' ) );

if ( $post_id <= 0 ) {
	$post_id = absint( get_option( 'page_on_front' ) );
}
if ( $post_id <= 0 ) {
	$post_id = 44207;
}

$post = get_post( $post_id );

echo "=== PolyMart CLI home translate ===\n";
echo 'db_host=' . DB_HOST . "\n";
echo 'page_on_front=' . absint( get_option( 'page_on_front' ) ) . "\n";
echo "post={$post_id} title=" . ( $post ? $post->post_title : 'MISSING' ) . " lang={$lang}\n";
echo 'elementor=' . ( Post_Translator::uses_elementor_builder( $post_id ) ? 'yes' : 'no' ) . "\n";

$langs = Language_Registry::format_for_api();
echo 'languages=';
foreach ( $langs as $l ) {
	$en = ! empty( $l['enabled'] ) ? 'on' : 'off';
	echo "{$l['code']}:{$en}/{$l['url_prefix']} ";
}
echo "\n";

/**
 * Print translation status.
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Lang.
 * @return void
 */
function pmai_cli_status( $post_id, $lang ) {
	$state     = Post_Translator::get_job_partial_state( $post_id, $lang );
	$progress  = (string) get_post_meta( $post_id, '_polymart_ai_elementor_progress_' . $lang, true );
	$raw       = get_post_meta( $post_id, '_elementor_data_' . $lang, true );
	$ok        = is_string( $raw ) && '' !== $raw && is_array( json_decode( $raw, true ) );
	$remain    = Post_Translator::count_elementor_remaining_fields( $post_id, $lang );
	$marker    = Post_Translator::format_elementor_job_progress_marker(
		$post_id,
		$lang,
		is_array( $state ) ? $state : array()
	);
	$error     = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );
	$finalized = (string) get_post_meta( $post_id, '_polymart_ai_elementor_finalized_' . $lang, true );
	$title     = (string) get_post_meta( $post_id, '_polymart_ai_title_' . $lang, true );

	echo "---- STATUS ----\n";
	echo "progress_meta={$progress}\n";
	echo "marker={$marker}\n";
	echo "remaining_fields={$remain}\n";
	echo 'json_valid=' . ( $ok ? 'yes' : 'no' ) . ' len=' . ( is_string( $raw ) ? strlen( $raw ) : 0 ) . "\n";
	echo 'title_meta=' . mb_substr( $title, 0, 80 ) . "\n";
	echo 'gap_fill=' . ( ! empty( $state['elementor_gap_fill'] ) ? '1' : '0' ) . "\n";
	echo 'primary_done=' . absint( $state['elementor_primary_batches_done'] ?? 0 ) . "\n";
	echo 'chunk_index=' . absint( $state['elementor_chunk_index'] ?? 0 ) . '/' . absint( $state['elementor_chunks_total'] ?? 0 ) . "\n";
	echo "finalized={$finalized}\n";
	echo "error={$error}\n";
	echo 'needs_work=' . ( Post_Translator::post_needs_translation_work( $post_id, $lang ) ? 'yes' : 'no' ) . "\n";

	$job = Activity_Logger::get_job_for_as_debug();
	echo 'bulk_status=' . (string) ( $job['status'] ?? 'n/a' ) . ' partial_post=' . absint( $job['partial_post_id'] ?? 0 ) . "\n";
}

/**
 * Ensure Arabic language is enabled with prefix ar.
 *
 * @return void
 */
function pmai_cli_ensure_ar_enabled() {
	$languages = Language_Registry::get_languages();
	$found     = false;
	$changed   = false;

	foreach ( $languages as &$language ) {
		if ( 'ar' === ( $language['code'] ?? '' ) ) {
			$found = true;
			if ( empty( $language['enabled'] ) ) {
				$language['enabled'] = true;
				$changed             = true;
			}
			if ( empty( $language['url_prefix'] ) ) {
				$language['url_prefix'] = 'ar';
				$changed                = true;
			}
		}
	}
	unset( $language );

	if ( ! $found ) {
		foreach ( Language_Registry::get_presets() as $preset ) {
			if ( 'ar' === ( $preset['code'] ?? '' ) ) {
				$preset['enabled'] = true;
				$languages[]       = $preset;
				$changed           = true;
				break;
			}
		}
	}

	if ( $changed ) {
		Language_Registry::save_languages( $languages );
		echo "enabled Arabic language + refreshed rewrite rules\n";
	} else {
		echo "Arabic already enabled\n";
	}
}

/**
 * Run N metabox-equivalent slices (same path AS uses).
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Lang.
 * @param int    $ticks   Tick count.
 * @return bool True when done.
 */
function pmai_cli_ticks( $post_id, $lang, $ticks ) {
	$ticks = max( 1, min( 80, absint( $ticks ) ) );
	echo "---- INLINE TICKS={$ticks} (Post_Translator slice = AS metabox path) ----\n";

	for ( $i = 1; $i <= $ticks; $i++ ) {
		Translation_Scheduler_Coordinator::clear_halt();
		Activity_Logger::begin_metabox_as_worker( $post_id, true );
		$t0    = microtime( true );
		$slice = Post_Translator::process_job_translation_slice( $post_id, $lang, true );
		Activity_Logger::end_metabox_as_worker();
		$sec = round( microtime( true ) - $t0, 1 );

		if ( is_wp_error( $slice ) ) {
			echo "tick{$i} ERROR {$sec}s " . $slice->get_error_code() . ': ' . $slice->get_error_message() . "\n";
			if ( 'polymart_ai_job_aborted' === $slice->get_error_code() ) {
				Translation_Scheduler_Coordinator::clear_halt();
			}
			continue;
		}

		$done = ! empty( $slice['done'] ) ? 'done' : 'partial';
		$prog = (string) ( $slice['phase_progress'] ?? '' );
		$msg  = (string) ( $slice['message'] ?? '' );
		echo "tick{$i} {$done} {$sec}s progress={$prog} msg={$msg}\n";

		if ( ! empty( $slice['done'] ) ) {
			return true;
		}
	}

	return false;
}

if ( 'ensure-ar' === $cmd ) {
	pmai_cli_ensure_ar_enabled();
	pmai_cli_status( $post_id, $lang );
	exit( 0 );
}

if ( 'status' === $cmd ) {
	pmai_cli_status( $post_id, $lang );
	exit( 0 );
}

if ( 'clear' === $cmd || 'clear-start' === $cmd || 'run' === $cmd ) {
	pmai_cli_ensure_ar_enabled();
	echo "---- CLEAR ar companions ----\n";
	Translation_Scheduler_Coordinator::halt_all_schedulers();
	Activity_Logger::stop_job();
	Translation_Scheduler_Coordinator::clear_halt();
	Job_Action_Scheduler::cancel_all();
	Metabox_Action_Scheduler::cancel_all_plugin_actions();
	Post_Translator::clear_post_language_translations( $post_id, $lang );
	Post_Translator::clear_job_partial_state( $post_id, $lang, true );
	Post_Translator::release_translation_lock( $post_id, $lang, true );
	delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
	echo "cleared\n";
}

if ( 'start' === $cmd || 'clear-start' === $cmd || 'run' === $cmd ) {
	echo "---- START metabox AS ----\n";
	Translation_Scheduler_Coordinator::clear_halt();
	Activity_Logger::stop_job();
	Translation_Scheduler_Coordinator::clear_halt();

	$result = Metabox_Action_Scheduler::start_translation(
		$post_id,
		$lang,
		array(
			'force'  => true,
			'unlock' => true,
		)
	);

	if ( is_wp_error( $result ) ) {
		echo 'start_error: ' . $result->get_error_code() . ' — ' . $result->get_error_message() . "\n";
	} else {
		echo "start_ok\n";
		if ( is_array( $result ) ) {
			echo 'status=' . (string) ( $result['status'] ?? '' ) . ' message=' . (string) ( $result['message'] ?? '' ) . "\n";
		}
	}

	// Also nudge AS queue once.
	Job_Action_Scheduler::ensure_as_table_names();
	Metabox_Action_Scheduler::run_queue_inline( true );
}

if ( 'tick' === $cmd ) {
	$n = absint( $arg2 ?: 6 );
	pmai_cli_ticks( $post_id, $lang, $n );
	pmai_cli_status( $post_id, $lang );
	exit( 0 );
}

if ( 'run' === $cmd ) {
	$n = absint( $arg2 ?: 40 );
	$ok = pmai_cli_ticks( $post_id, $lang, $n );
	pmai_cli_status( $post_id, $lang );
	echo $ok ? "RESULT=DONE\n" : "RESULT=PARTIAL\n";
	exit( $ok ? 0 : 2 );
}

if ( 'as-tick' === $cmd ) {
	$n = absint( $arg2 ?: 5 );
	echo "---- AS queue_inline ticks={$n} ----\n";
	for ( $i = 1; $i <= $n; $i++ ) {
		Job_Action_Scheduler::ensure_as_table_names();
		$done = Metabox_Action_Scheduler::run_queue_inline( true );
		echo "as_tick{$i} processed={$done}\n";
		pmai_cli_status( $post_id, $lang );
		$remain = Post_Translator::count_elementor_remaining_fields( $post_id, $lang );
		if ( $remain <= 0 && ! Post_Translator::post_needs_translation_work( $post_id, $lang ) ) {
			echo "RESULT=DONE\n";
			exit( 0 );
		}
	}
	echo "RESULT=PARTIAL\n";
	exit( 2 );
}

pmai_cli_status( $post_id, $lang );
echo "DONE\n";
