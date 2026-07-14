<?php
require dirname( __DIR__, 4 ) . '/wp-load.php';

$id   = 1021;
$lang = 'en';

echo 'post: ' . get_the_title( $id ) . PHP_EOL;
echo 'front: ' . get_option( 'page_on_front' ) . PHP_EOL;
echo 'elementor_mode: ' . get_post_meta( $id, '_elementor_edit_mode', true ) . PHP_EOL;
echo 'has_en_json: ' . ( strlen( (string) get_post_meta( $id, '_elementor_data_en', true ) ) > 10 ? 'yes' : 'no' ) . PHP_EOL;
echo 'hash: ' . get_post_meta( $id, '_polymart_ai_elementor_src_hash_en', true ) . PHP_EOL;
echo 'finalized: ' . get_post_meta( $id, '_polymart_ai_elementor_finalized_en', true ) . PHP_EOL;
echo 'progress: ' . get_post_meta( $id, '_polymart_ai_elementor_progress_en', true ) . PHP_EOL;
echo 'error: ' . get_post_meta( $id, '_polymart_ai_elementor_error_en', true ) . PHP_EOL;
echo 'status_index: ' . get_post_meta( $id, '_polymart_ai_status_en', true ) . PHP_EOL;

PolymartAI\Translation\Post_Translator::flush_translation_status_cache( $id );
echo 'can_serve: ' . ( PolymartAI\Translation\Post_Translator::can_serve_stored_elementor_json_on_storefront( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'storefront_ready: ' . ( PolymartAI\Translation\Post_Translator::elementor_translation_is_storefront_ready( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'needs_work: ' . ( PolymartAI\Translation\Post_Translator::post_needs_translation_work( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'needs_elementor: ' . ( PolymartAI\Translation\Post_Translator::post_needs_elementor_job_work( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'translation_status: ' . PolymartAI\Translation\Post_Translator::get_translation_status( $id, $lang ) . PHP_EOL;
echo 'stored_persian: ' . ( PolymartAI\Translation\Post_Translator::stored_elementor_translation_has_persian( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'show_persian_source: ' . ( PolymartAI\Translation\Post_Translator::storefront_would_show_persian_source( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;

$repaired = PolymartAI\Translation\Post_Translator::repair_completed_elementor_job_meta( $id, $lang );
echo 'repair_completed: ' . ( $repaired ? 'yes' : 'no' ) . PHP_EOL;

PolymartAI\Translation\Post_Translator::flush_translation_status_cache( $id );
echo 'after_repair can_serve: ' . ( PolymartAI\Translation\Post_Translator::can_serve_stored_elementor_json_on_storefront( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'after_repair needs_work: ' . ( PolymartAI\Translation\Post_Translator::post_needs_translation_work( $id, $lang ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'after_repair status: ' . PolymartAI\Translation\Post_Translator::get_translation_status( $id, $lang ) . PHP_EOL;
