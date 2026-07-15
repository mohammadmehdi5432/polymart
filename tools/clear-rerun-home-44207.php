<?php
/**
 * Clear EN translation for homepage #44207 and re-run via Metabox Action Scheduler.
 *
 * Usage:
 *   http://sepi-live.local/wp-content/plugins/PolyMartAI/tools/clear-rerun-home-44207.php?action=status
 *   http://sepi-live.local/wp-content/plugins/PolyMartAI/tools/clear-rerun-home-44207.php?action=clear
 *   http://sepi-live.local/wp-content/plugins/PolyMartAI/tools/clear-rerun-home-44207.php?action=clear&start=1&ticks=6
 *
 * @package PolymartAI
 */

require dirname( __DIR__, 4 ) . '/wp-load.php';

header( 'Content-Type: text/plain; charset=utf-8' );
set_time_limit( 300 );

use PolymartAI\Activity_Logger;
use PolymartAI\Activity_Logger\Job_Action_Scheduler;
use PolymartAI\Activity_Logger\Metabox_Action_Scheduler;
use PolymartAI\Activity_Logger\Translation_Scheduler_Coordinator;
use PolymartAI\Translation\Post_Translator;

$post_id = absint( $_GET['post_id'] ?? 44207 );
$lang    = sanitize_key( (string) ( $_GET['lang'] ?? 'en' ) );
$action  = sanitize_key( (string) ( $_GET['action'] ?? 'status' ) );
$ticks   = max( 0, min( 20, absint( $_GET['ticks'] ?? 0 ) ) );
$start   = ! empty( $_GET['start'] );

if ( $post_id <= 0 || '' === $lang ) {
	echo "bad args\n";
	exit;
}

$post = get_post( $post_id );
echo "post={$post_id} title=" . ( $post ? $post->post_title : 'MISSING' ) . " lang={$lang}\n";
echo 'elementor=' . ( Post_Translator::uses_elementor_builder( $post_id ) ? 'yes' : 'no' ) . "\n";

/**
 * @param int    $post_id Post ID.
 * @param string $lang    Lang.
 * @return void
 */
function pmai_home_status( $post_id, $lang ) {
	$state    = Post_Translator::get_job_partial_state( $post_id, $lang );
	$progress = (string) get_post_meta( $post_id, '_polymart_ai_elementor_progress_' . $lang, true );
	$en_raw   = get_post_meta( $post_id, '_elementor_data_' . $lang, true );
	$en_ok    = is_string( $en_raw ) && '' !== $en_raw && is_array( json_decode( $en_raw, true ) );
	$remain   = Post_Translator::count_elementor_remaining_fields( $post_id, $lang );
	$marker   = Post_Translator::format_elementor_job_progress_marker(
		$post_id,
		$lang,
		is_array( $state ) ? $state : array()
	);
	$error    = (string) get_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, true );
	$finalized = (string) get_post_meta( $post_id, '_polymart_ai_elementor_finalized_' . $lang, true );
	$cursor    = (string) get_post_meta( $post_id, '_polymart_ai_elementor_slice_cursor_' . $lang, true );

	echo "---- STATUS ----\n";
	echo "progress_meta={$progress}\n";
	echo "marker={$marker}\n";
	echo "cursor_meta={$cursor}\n";
	echo "remaining_fields={$remain}\n";
	echo 'en_json_valid=' . ( $en_ok ? 'yes' : 'no' ) . ' len=' . ( is_string( $en_raw ) ? strlen( $en_raw ) : 0 ) . "\n";
	echo 'gap_fill=' . ( ! empty( $state['elementor_gap_fill'] ) ? '1' : '0' ) . "\n";
	echo 'primary_done=' . absint( $state['elementor_primary_batches_done'] ?? 0 ) . "\n";
	echo 'chunk_index=' . absint( $state['elementor_chunk_index'] ?? 0 ) . "\n";
	echo 'chunks_total=' . absint( $state['elementor_chunks_total'] ?? 0 ) . "\n";
	echo 'empty_queue_escapes=' . absint( $state['elementor_empty_queue_escapes'] ?? 0 ) . "\n";
	echo "finalized={$finalized}\n";
	echo "error={$error}\n";

	$job = Activity_Logger::get_job_for_as_debug();
	echo 'bulk_status=' . (string) ( $job['status'] ?? 'n/a' ) . ' partial_post=' . absint( $job['partial_post_id'] ?? 0 ) . "\n";
	echo 'halt=' . ( Translation_Scheduler_Coordinator::is_halted() ? 'yes' : 'no' ) . "\n";
	echo 'needs_work=' . ( Post_Translator::post_needs_translation_work( $post_id, $lang ) ? 'yes' : 'no' ) . "\n";
}

if ( 'clear' === $action || ! empty( $_GET['clear'] ) ) {
	echo "---- CLEAR ----\n";
	Translation_Scheduler_Coordinator::halt_all_schedulers();
	Activity_Logger::stop_job();
	Translation_Scheduler_Coordinator::clear_halt();
	Job_Action_Scheduler::cancel_all();
	Metabox_Action_Scheduler::cancel_all_plugin_actions();
	Post_Translator::clear_post_language_translations( $post_id, $lang );
	Post_Translator::clear_job_partial_state( $post_id, $lang, true );
	Post_Translator::release_translation_lock( $post_id, $lang, true );
	delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
	echo "cleared EN companions + partial + locks + AS\n";
}

pmai_home_status( $post_id, $lang );

if ( $start || 'start' === $action ) {
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
}

if ( $ticks > 0 ) {
	echo "---- INLINE TICKS={$ticks} (same Post_Translator slice path as AS) ----\n";
	for ( $i = 1; $i <= $ticks; $i++ ) {
		Translation_Scheduler_Coordinator::clear_halt();
		Activity_Logger::begin_metabox_as_worker( $post_id, true );
		$slice = Post_Translator::process_job_translation_slice( $post_id, $lang, true );
		Activity_Logger::end_metabox_as_worker();

		if ( is_wp_error( $slice ) ) {
			echo "tick{$i} ERROR " . $slice->get_error_code() . ': ' . $slice->get_error_message() . "\n";
			if ( 'polymart_ai_job_aborted' === $slice->get_error_code() ) {
				Translation_Scheduler_Coordinator::clear_halt();
			}
		} else {
			$done = ! empty( $slice['done'] ) ? 'done' : 'partial';
			$prog = (string) ( $slice['phase_progress'] ?? '' );
			$msg  = (string) ( $slice['message'] ?? '' );
			echo "tick{$i} {$done} progress={$prog} msg={$msg}\n";
			if ( ! empty( $slice['done'] ) ) {
				break;
			}
		}
	}
	pmai_home_status( $post_id, $lang );
}

echo "DONE\n";
