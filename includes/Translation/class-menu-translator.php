<?php
/**
 * Navigation menu item translation for translated storefront URLs.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class Menu_Translator
 */
final class Menu_Translator {

	/**
	 * Post type for WordPress nav menu items.
	 */
	const POST_TYPE = 'nav_menu_item';

	/**
	 * Count menu items with Persian labels missing a stored translation.
	 *
	 * @param string $lang Target language code.
	 * @return int
	 */
	public static function count_untranslated( $lang ) {
		$lang  = sanitize_key( (string) $lang );
		$count = 0;

		foreach ( self::collect_menu_item_ids() as $item_id ) {
			if ( self::needs_translation( $item_id, $lang ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Find the next menu item ID that still needs translation.
	 *
	 * @param string $lang       Target language code.
	 * @param int    $after_id   Resume after this menu item ID.
	 * @param int[]  $exclude_ids Item IDs to skip.
	 * @return int
	 */
	public static function find_next_untranslated_id( $lang, $after_id = 0, array $exclude_ids = array() ) {
		$lang          = sanitize_key( (string) $lang );
		$after_id      = absint( $after_id );
		$exclude_lookup = array_fill_keys( array_map( 'absint', $exclude_ids ), true );

		foreach ( self::collect_menu_item_ids() as $item_id ) {
			if ( $item_id <= $after_id || isset( $exclude_lookup[ $item_id ] ) ) {
				continue;
			}

			if ( self::needs_translation( $item_id, $lang ) ) {
				return $item_id;
			}
		}

		if ( $after_id <= 0 ) {
			return 0;
		}

		foreach ( self::collect_menu_item_ids() as $item_id ) {
			if ( isset( $exclude_lookup[ $item_id ] ) ) {
				continue;
			}

			if ( self::needs_translation( $item_id, $lang ) ) {
				return $item_id;
			}
		}

		return 0;
	}

	/**
	 * Whether a menu item still needs translation for a language.
	 *
	 * @param int    $item_id Menu item post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function needs_translation( $item_id, $lang ) {
		$item_id = absint( $item_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $item_id <= 0 || '' === $lang ) {
			return false;
		}

		$item = get_post( $item_id );

		if ( ! $item instanceof \WP_Post || self::POST_TYPE !== $item->post_type ) {
			return false;
		}

		$title = trim( (string) $item->post_title );

		if ( '' === $title || ! Persian_Detector::contains_persian( $title ) ) {
			return false;
		}

		$stored = get_post_meta( $item_id, Post_Translator::get_menu_title_meta_key( $lang ), true );

		return ! Post_Translator::is_usable_storefront_translation( $stored, $lang );
	}

	/**
	 * Translate one menu item label via AI and persist the result.
	 *
	 * @param int    $item_id Menu item post ID.
	 * @param string $lang    Target language code.
	 * @return array{title: string, source: string}|\WP_Error
	 */
	public static function request_ai_translation( $item_id, $lang ) {
		$item_id = absint( $item_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $item_id <= 0 ) {
			return new \WP_Error(
				'polymart_ai_invalid_menu_item',
				__( 'آیتم منو نامعتبر است.', 'polymart-ai' )
			);
		}

		if ( ! wp_doing_cron() && ! current_user_can( 'edit_theme_options' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'polymart_ai_forbidden',
				__( 'شما اجازه ترجمه منو را ندارید.', 'polymart-ai' )
			);
		}

		$item = get_post( $item_id );

		if ( ! $item instanceof \WP_Post || self::POST_TYPE !== $item->post_type ) {
			return new \WP_Error(
				'polymart_ai_invalid_menu_item',
				__( 'آیتم منو یافت نشد.', 'polymart-ai' )
			);
		}

		$source = trim( (string) $item->post_title );

		if ( '' === $source || ! Persian_Detector::contains_persian( $source ) ) {
			return new \WP_Error(
				'polymart_ai_empty_source',
				__( 'برچسب فارسی برای این آیتم منو یافت نشد.', 'polymart-ai' )
			);
		}

		$settings = Post_Translator::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		$result = AI_Client::translate_fields(
			array( 'title' => $source ),
			$api_key,
			$api_endpoint,
			$ai_model,
			$lang
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$translated = isset( $result['title'] ) ? trim( (string) $result['title'] ) : '';

		if ( '' === $translated ) {
			return new \WP_Error(
				'polymart_ai_empty_translation',
				__( 'ترجمه برچسب منو خالی برگشت.', 'polymart-ai' )
			);
		}

		self::save_translation( $item_id, $source, $translated, $lang );

		return array(
			'source' => $source,
			'title'  => $translated,
		);
	}

	/**
	 * Persist a translated menu item label.
	 *
	 * @param int    $item_id    Menu item post ID.
	 * @param string $source     Original Persian label.
	 * @param string $translated Translated label.
	 * @param string $lang       Target language code.
	 * @return void
	 */
	public static function save_translation( $item_id, $source, $translated, $lang ) {
		$item_id    = absint( $item_id );
		$source     = is_string( $source ) ? trim( $source ) : '';
		$translated = is_string( $translated ) ? trim( $translated ) : '';
		$lang       = sanitize_key( (string) $lang );

		if ( $item_id <= 0 || '' === $source || '' === $translated || '' === $lang ) {
			return;
		}

		update_post_meta( $item_id, Post_Translator::get_menu_title_meta_key( $lang ), sanitize_text_field( $translated ) );
		update_post_meta( $item_id, '_polymart_ai_menu_translated_at_' . $lang, time() );

		Runtime_String_Translator::store_translation(
			$source,
			$lang,
			'menu:' . $item_id,
			$translated
		);
	}

	/**
	 * All published nav menu item IDs in stable order.
	 *
	 * @return int[]
	 */
	private static function collect_menu_item_ids() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$ids = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'no_found_rows'          => true,
			)
		);

		$cache = is_array( $ids ) ? array_values( array_map( 'absint', $ids ) ) : array();

		return $cache;
	}
}
