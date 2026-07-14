<?php
require dirname( __DIR__, 4 ) . '/wp-load.php';

use PolymartAI\Translation\Pipeline\Translation_Query;
use PolymartAI\Translation\Post_Translator;

$lang  = 'en';
$front = (int) get_option( 'page_on_front' );

Post_Translator::flush_translation_status_cache();

echo 'front: ' . $front . ' — ' . get_the_title( $front ) . PHP_EOL;

$ids = array_filter( array_unique( array_merge( array( $front, 1021 ), get_posts( array(
	'post_type'      => 'page',
	'post_status'    => 'publish',
	'posts_per_page' => 20,
	'fields'         => 'ids',
) ) ) ) );

foreach ( $ids as $id ) {
	$id = (int) $id;
	echo '--- #' . $id . ' ' . get_the_title( $id ) . PHP_EOL;
	echo 'index: ' . get_post_meta( $id, '_polymart_ai_status_en', true ) . PHP_EOL;
	echo 'status: ' . Post_Translator::get_translation_status( $id, $lang ) . PHP_EOL;
	echo 'needs_work: ' . ( Post_Translator::post_needs_translation_work( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
	echo 'storefront_persian: ' . ( Post_Translator::storefront_would_show_persian_source( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
	echo 'can_serve: ' . ( Post_Translator::can_serve_stored_elementor_json_on_storefront( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
	echo 'elementor: ' . ( Post_Translator::uses_elementor_builder( $id ) ? 'yes' : 'no' ) . PHP_EOL;
}

echo 'stats_index: ' . wp_json_encode( Translation_Query::compute_translation_stats( $lang, false ) ) . PHP_EOL;
echo 'stats_full: ' . wp_json_encode( Translation_Query::compute_translation_stats( $lang, true ) ) . PHP_EOL;
echo 'remaining_pages: ' . Translation_Query::get_remaining_work_page( array(
	'lang'      => $lang,
	'post_type' => 'page',
	'page'      => 1,
	'per_page'  => 50,
) )['total'] . PHP_EOL;
echo 'probe: ' . wp_json_encode( Translation_Query::probe_priority_unfinished_post_ids( $lang, 8, true ) ) . PHP_EOL;
echo 'issues: ' . count( Translation_Query::collect_storefront_translation_issues( $lang, 8, true ) ) . PHP_EOL;
