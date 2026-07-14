<?php

/**

 * Bridges PolyMartAI review translation with Advanced Product Description (APD).

 *

 * @package PolymartAI

 */



namespace PolymartAI\Integration;



use PolymartAI\Routing\Url_Router;

use PolymartAI\Translation\Content\Comment_Translator;

use PolymartAI\Translation\AI\Persian_Detector;

use PolymartAI\Translation\Post_Translator;

use PolymartAI\Translation\Storefront\Runtime_String_Translator;




defined( 'ABSPATH' ) || exit;



/**

 * Class Apd_Integration

 */

final class Apd_Integration {



	/**

	 * Schedule integration hooks after APD has bootstrapped.

	 *

	 * @return void

	 */

	public static function init() {

		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 25 );

	}



	/**

	 * Register APD companion hooks.

	 *

	 * Display translation uses a singleton worker so review rows never spawn

	 * duplicate WordPress filters (which previously broke product titles).

	 *

	 * @return void

	 */

	public static function register_hooks() {

		if ( ! defined( 'APD_VERSION' ) ) {

			return;

		}



		add_filter( 'apd_review_display_content', array( __CLASS__, 'filter_review_display_content' ), 10, 3 );

		add_filter( 'apd_review_display_author', array( __CLASS__, 'filter_review_display_author' ), 10, 3 );

		add_filter( 'apd_product_ai_analysis', array( __CLASS__, 'filter_product_ai_analysis' ), 10, 2 );



		add_action( 'apd_review_created', array( __CLASS__, 'schedule_review_translation' ), 10, 1 );

		add_action( 'apd_review_updated', array( __CLASS__, 'schedule_review_translation' ), 10, 1 );

	}



	/**

	 * Translate one APD review row body on /en/, /ar/, etc.

	 *

	 * @param string      $content    Display content.

	 * @param \WP_Comment $comment    Source comment.

	 * @param int         $product_id Product ID.

	 * @return string

	 */

	public static function filter_review_display_content( $content, $comment, $product_id ) {

		return Comment_Translator::display_worker()->resolve_display_content( $content, $comment, $product_id );

	}



	/**

	 * Translate one APD review row author on translated storefront URLs.

	 *

	 * @param string      $author     Display author.

	 * @param \WP_Comment $comment    Source comment.

	 * @param int         $product_id Product ID.

	 * @return string

	 */

	public static function filter_review_display_author( $author, $comment, $product_id ) {

		return Comment_Translator::display_worker()->resolve_display_author( $author, $comment, $product_id );

	}



	/**

	 * Serve stored AI review summary translations for APD widgets.

	 *

	 * @param string $analysis   Persian analysis text.

	 * @param int    $product_id Product ID.

	 * @return string

	 */

	public static function filter_product_ai_analysis( $analysis, $product_id ) {

		if ( ! is_string( $analysis ) || '' === trim( $analysis ) ) {

			return is_string( $analysis ) ? $analysis : '';

		}



		if ( ! class_exists( Url_Router::class ) || ! Url_Router::is_translated_request() ) {

			return $analysis;

		}



		$product_id = absint( $product_id );

		$lang       = Url_Router::get_current_language();



		if ( $product_id <= 0 || '' === $lang ) {

			return $analysis;

		}



		$stored = get_post_meta(

			$product_id,

			Post_Translator::get_custom_meta_key( '_apd_ai_analysis', $lang ),

			true

		);



		if ( Post_Translator::has_meaningful_translation( $stored ) ) {

			return (string) $stored;

		}

		if ( Persian_Detector::contains_persian( $analysis ) ) {
			$cached = Runtime_String_Translator::lookup_cached(
				$analysis,
				$lang,
				'apd_ai_analysis:' . $product_id
			);

			if ( is_string( $cached ) && '' !== trim( $cached ) ) {
				return $cached;
			}
		}

		return $analysis;

	}



	/**

	 * Queue AI translation as soon as APD stores a review.

	 *

	 * @param int $comment_id Comment ID.

	 * @return void

	 */

	public static function schedule_review_translation( $comment_id ) {

		$comment_id = absint( $comment_id );



		if ( ! $comment_id ) {

			return;

		}



		add_action(

			'shutdown',

			static function () use ( $comment_id ) {

				Comment_Translator::process_scheduled_translation( $comment_id );

			},

			20

		);

	}

}


