<?php
/**
 * Navigation menu item translation for translated storefront URLs.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\Content;

use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Storefront\Runtime_String_Translator;

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

		$source = self::get_menu_label_source( $item_id, $item );

		if ( '' === $source || ! Persian_Detector::contains_persian( $source ) ) {
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

		if (
			! \PolymartAI\Activity_Logger::is_trusted_job_worker()
			&& ! current_user_can( 'edit_theme_options' )
			&& ! current_user_can( 'manage_options' )
		) {
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

		$source = self::get_menu_label_source( $item_id, $item );

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

		clean_post_cache( $item_id );

		$menu_terms = wp_get_post_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );

		if ( is_array( $menu_terms ) ) {
			foreach ( $menu_terms as $menu_id ) {
				wp_cache_delete( (int) $menu_id, 'nav_menu' );
			}
		}
	}

	/**
	 * Resolve the storefront label for a menu item on translated URLs.
	 *
	 * Handles Home/front-page links whose nav label is English while the linked
	 * page title (or site front page) is still Persian.
	 *
	 * @param \WP_Post $menu_item Menu item object.
	 * @param string   $lang      Target language code.
	 * @param string   $title     Title WordPress passed to the filter.
	 * @return string
	 */
	public static function resolve_storefront_menu_title( \WP_Post $menu_item, $lang, $title ) {
		$lang = sanitize_key( (string) $lang );

		if ( ! $menu_item instanceof \WP_Post || '' === $lang ) {
			return is_string( $title ) ? $title : '';
		}

		$stored = get_post_meta( $menu_item->ID, Post_Translator::get_menu_title_meta_key( $lang ), true );

		if ( Post_Translator::is_usable_storefront_translation( $stored, $lang ) ) {
			return (string) $stored;
		}

		if ( self::menu_item_targets_front_page( $menu_item->ID ) ) {
			$front_id = absint( get_option( 'page_on_front' ) );

			if ( $front_id > 0 ) {
				$translated = get_post_meta( $front_id, Post_Translator::get_meta_key( 'title', $lang ), true );

				if ( Post_Translator::is_usable_storefront_translation( $translated, $lang ) ) {
					return (string) $translated;
				}
			}
		}

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Best Persian source string for translating a menu item label.
	 *
	 * @param int           $item_id Menu item post ID.
	 * @param \WP_Post|null $item    Optional menu item object.
	 * @return string
	 */
	public static function get_menu_label_source( $item_id, $item = null ) {
		$item_id = absint( $item_id );

		if ( ! $item instanceof \WP_Post ) {
			$item = get_post( $item_id );
		}

		if ( ! $item instanceof \WP_Post || self::POST_TYPE !== $item->post_type ) {
			return '';
		}

		$title = trim( (string) $item->post_title );

		if ( '' !== $title && Persian_Detector::contains_persian( $title ) ) {
			return $title;
		}

		$attr_title = trim( (string) get_post_meta( $item_id, '_menu_item_attr_title', true ) );

		if ( '' !== $attr_title && Persian_Detector::contains_persian( $attr_title ) ) {
			return $attr_title;
		}

		$type      = (string) get_post_meta( $item_id, '_menu_item_type', true );
		$object    = (string) get_post_meta( $item_id, '_menu_item_object', true );
		$object_id = absint( get_post_meta( $item_id, '_menu_item_object_id', true ) );

		if ( 'post_type' === $type && $object_id > 0 ) {
			$linked = get_post( $object_id );

			if ( $linked instanceof \WP_Post ) {
				$linked_title = trim( (string) $linked->post_title );

				if ( '' !== $linked_title && Persian_Detector::contains_persian( $linked_title ) ) {
					return $linked_title;
				}
			}
		}

		if ( self::menu_item_targets_front_page( $item_id ) ) {
			$front_id = absint( get_option( 'page_on_front' ) );

			if ( $front_id > 0 ) {
				$page = get_post( $front_id );

				if ( $page instanceof \WP_Post ) {
					$page_title = trim( (string) $page->post_title );

					if ( '' !== $page_title && Persian_Detector::contains_persian( $page_title ) ) {
						return $page_title;
					}
				}
			}
		}

		return $title;
	}

	/**
	 * Whether a nav menu item points at the site front page.
	 *
	 * @param int $item_id Menu item post ID.
	 * @return bool
	 */
	public static function menu_item_targets_front_page( $item_id ) {
		$item_id = absint( $item_id );

		if ( $item_id <= 0 ) {
			return false;
		}

		$front_id = absint( get_option( 'page_on_front' ) );
		$type     = (string) get_post_meta( $item_id, '_menu_item_type', true );

		if ( 'post_type' === $type && $front_id > 0 ) {
			$object    = (string) get_post_meta( $item_id, '_menu_item_object', true );
			$object_id = absint( get_post_meta( $item_id, '_menu_item_object_id', true ) );

			return 'page' === $object && $object_id === $front_id;
		}

		if ( 'custom' !== $type ) {
			return false;
		}

		$url = trim( (string) get_post_meta( $item_id, '_menu_item_url', true ) );

		if ( '' === $url || '/' === $url || '#' === $url ) {
			return true;
		}

		$home      = untrailingslashit( (string) home_url() );
		$candidate = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( '' === $candidate || '/' === $candidate ) {
			return true;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = untrailingslashit( is_string( $home_path ) ? $home_path : '' );

		if ( '' !== $home_path && $candidate === $home_path ) {
			return true;
		}

		return untrailingslashit( $url ) === $home;
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
