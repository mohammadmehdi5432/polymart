<?php
/**
 * Translation diagnostics (localhost only).
 */

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
require $wp_load;

header( 'Content-Type: application/json; charset=utf-8' );

$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
if ( ! in_array( $remote, array( '127.0.0.1', '::1' ), true ) ) {
	status_header( 403 );
	exit;
}

use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator;

$post_id = absint( $_GET['post_id'] ?? 7930 );
$lang    = sanitize_key( (string) ( $_GET['lang'] ?? 'ar' ) );
$post    = get_post( $post_id );

if ( ! $post instanceof WP_Post ) {
	echo wp_json_encode( array( 'error' => 'not found' ) );
	exit;
}

Post_Translator::flush_translation_status_cache( $post_id );

$title_key = Post_Translator::get_meta_key( 'title', $lang );
$stored    = get_post_meta( $post_id, $title_key, true );
$pending   = Post_Translator::collect_persian_fields( $post, $lang );

$all_meta = get_post_meta( $post_id );
$poly_meta = array();
foreach ( $all_meta as $key => $values ) {
	if ( ! is_string( $key ) || false === strpos( $key, 'polymart_ai' ) ) {
		continue;
	}
	$poly_meta[ $key ] = is_array( $values ) ? array_map( 'strlen', array_map( 'strval', $values ) ) : strlen( (string) $values );
}

$out = array(
	'post_id'      => $post_id,
	'lang'         => $lang,
	'title_fa'     => $post->post_title,
	'title_key'    => $title_key,
	'stored_title' => $stored,
	'stored_len'   => is_string( $stored ) ? strlen( $stored ) : 0,
	'meaningful'   => Post_Translator::has_meaningful_translation( $stored ),
	'acceptable'   => Persian_Detector::is_acceptable_translation_for_language( $stored, $lang ),
	'sanitize_test' => sanitize_text_field( 'مرآة قديمة زخرفية معدنية سوداء موديل حلالي 80*180 MR132' ),
	'status'       => Post_Translator::get_translation_status( $post_id, $lang ),
	'gaps'         => Post_Translator::get_translation_gaps( $post_id, $lang ),
	'pending_keys' => array_keys( $pending ),
	'poly_meta'    => $poly_meta,
);

if ( isset( $_GET['mock_save'] ) && '1' === (string) $_GET['mock_save'] ) {
	wp_set_current_user( 1 );
	$mock = array(
		'post_title'   => 'مرآة قديمة زخرفية معدنية سوداء موديل حلالي 80*180 MR132',
		'post_excerpt' => 'الأبعاد: 80*180<br>الخامة: معدن',
	);
	$pending = Post_Translator::collect_persian_fields( $post, $lang );
	foreach ( $pending as $key => $source ) {
		if ( 0 === strpos( $key, 'product_attr_label:' ) ) {
			$mock[ $key ] = 'الوزن';
		}
	}
	$save = Post_Translator::save_ai_translations( $post_id, $mock, $lang );
	$out['mock_save']          = is_wp_error( $save ) ? array(
		'error' => $save->get_error_message(),
		'code'  => $save->get_error_code(),
	) : 'ok';
	$out['stored_title_after'] = get_post_meta( $post_id, $title_key, true );
}

echo wp_json_encode( $out,
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
