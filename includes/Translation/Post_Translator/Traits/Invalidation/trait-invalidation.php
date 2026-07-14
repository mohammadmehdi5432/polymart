<?php
/**
 * Post_Translator Invalidation (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Invalidation;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Invalidation {

	public static function invalidate_field_translation( $post_id, $source_key ) {
		$post_id    = absint( $post_id );
		$source_key = (string) $source_key;

		if ( $post_id <= 0 || '' === $source_key ) {
			return;
		}

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			$translated_key = self::resolve_translated_meta_key_for_source( $source_key, $lang );

			if ( '' !== $translated_key ) {
				delete_post_meta( $post_id, $translated_key );
			}

			delete_post_meta( $post_id, self::get_field_source_hash_meta_key( $source_key, $lang ) );
		}

		self::flush_translation_status_cache( $post_id );
	}

	public static function clear_all_post_translations( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		self::invalidate_elementor_translations( $post_id );

		$all_meta = get_post_meta( $post_id );

		if ( ! is_array( $all_meta ) ) {
			return;
		}

		$lang_suffixes = array();

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' !== $lang ) {
				$lang_suffixes[] = '_' . $lang;
			}
		}

		foreach ( array_keys( $all_meta ) as $meta_key ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key ) {
				continue;
			}

			if ( 0 === strpos( $meta_key, '_polymart_ai_' ) ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			foreach ( $lang_suffixes as $suffix ) {
				if ( strlen( $meta_key ) > strlen( $suffix ) && substr( $meta_key, -strlen( $suffix ) ) === $suffix ) {
					delete_post_meta( $post_id, $meta_key );
					break;
				}
			}
		}

		delete_post_meta( $post_id, '_polymart_ai_translated_at' );
		self::flush_translation_status_cache( $post_id );
	}

	public static function reconcile_post_translations_after_save( $post_id, \WP_Post $post_after, $post_before = null ) {
		unset( $post_before );

		$post_id = absint( $post_id );

		if ( $post_id <= 0 || ! $post_after instanceof \WP_Post ) {
			return;
		}

		foreach ( Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			self::sync_field_source_hashes_for_post( $post_id, $lang );
		}
	}

	public static function get_field_source_text( \WP_Post $post, $source_key ) {
		$source_key = (string) $source_key;

		switch ( $source_key ) {
			case 'post_title':
				return Persian_Detector::only_persian_value( $post->post_title );
			case self::WVE_VARIATION_CUSTOM_TITLE_META:
				$value = get_post_meta( $post->ID, self::WVE_VARIATION_CUSTOM_TITLE_META, true );

				return is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';
			case self::WVE_VARIATION_CUSTOM_DESCRIPTION_META:
				$value = get_post_meta( $post->ID, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, true );

				return is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';
			case 'post_content':
				if ( self::uses_elementor_builder( $post->ID ) && ! self::is_commerce_product_post( $post->ID, $post ) ) {
					$plain = self::collect_elementor_persian_plain_text( $post->ID );

					if ( '' !== $plain ) {
						return $plain;
					}

					return self::extract_elementor_html_persian_excerpt( $post->post_content );
				}

				return Persian_Detector::only_persian_value( $post->post_content );
			case 'post_excerpt':
				return Persian_Detector::only_persian_value( $post->post_excerpt );
		}

		if ( self::is_custom_meta_key( $source_key ) ) {
			$value = get_post_meta( $post->ID, $source_key, true );

			return is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';
		}

		return '';
	}

	public static function resolve_source_key_for_translated_meta( $meta_key, $lang, $post_id = 0 ) {
		$meta_key = (string) $meta_key;
		$lang     = sanitize_key( (string) $lang );
		$post_id  = absint( $post_id );

		if ( '' === $meta_key || '' === $lang ) {
			return '';
		}

		$core_map = array(
			self::get_meta_key( 'title', $lang )   => 'post_title',
			self::get_meta_key( 'content', $lang ) => 'post_content',
			self::get_meta_key( 'excerpt', $lang )  => 'post_excerpt',
		);

		if ( isset( $core_map[ $meta_key ] ) ) {
			if ( $post_id > 0 && 'product_variation' === get_post_type( $post_id ) ) {
				if ( 'post_title' === $core_map[ $meta_key ] ) {
					$custom = get_post_meta( $post_id, self::WVE_VARIATION_CUSTOM_TITLE_META, true );

					if ( is_string( $custom ) && '' !== trim( $custom ) ) {
						return self::WVE_VARIATION_CUSTOM_TITLE_META;
					}
				}

				if ( 'post_excerpt' === $core_map[ $meta_key ] ) {
					$custom = get_post_meta( $post_id, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, true );

					if ( is_string( $custom ) && '' !== trim( $custom ) ) {
						return self::WVE_VARIATION_CUSTOM_DESCRIPTION_META;
					}
				}
			}

			return $core_map[ $meta_key ];
		}

		foreach ( self::CUSTOM_META_KEYS as $source_key ) {
			if ( self::get_custom_meta_key( $source_key, $lang ) === $meta_key ) {
				return $source_key;
			}
		}

		return '';
	}

	public static function should_serve_stored_translation( $post_id, $source_key, $lang, $post = null ) {
		$post_id    = absint( $post_id );
		$source_key = (string) $source_key;
		$lang       = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $source_key || '' === $lang ) {
			return false;
		}

		$cache_key = $post_id . ':' . $source_key . ':' . $lang;

		if ( array_key_exists( $cache_key, self::$should_serve_cache ) ) {
			return self::$should_serve_cache[ $cache_key ];
		}

		// Elementor pages render from `_elementor_data`; walking/decoding the tree here
		// on every filter call exhausted memory on translated URLs.
		if ( 'post_content' === $source_key && self::uses_elementor_builder( $post_id ) && ! is_admin() ) {
			self::$should_serve_cache[ $cache_key ] = false;

			return false;
		}

		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			self::$should_serve_cache[ $cache_key ] = false;

			return false;
		}

		$source = self::get_field_source_text( $post, $source_key );

		if ( '' === trim( $source ) ) {
			self::$should_serve_cache[ $cache_key ] = false;

			return false;
		}

		$result = self::is_field_translation_current( $post_id, $source_key, $lang, $source );
		self::$should_serve_cache[ $cache_key ] = $result;

		return $result;
	}

	private static function is_field_translation_current( $post_id, $source_key, $lang, $source ) {
		$translated_key = self::resolve_translated_meta_key_for_source( $source_key, $lang );

		if ( '' === $translated_key ) {
			return false;
		}

		if ( ! self::has_meaningful_translation( get_post_meta( absint( $post_id ), $translated_key, true ) ) ) {
			$stored_hash = (string) get_post_meta( absint( $post_id ), self::get_field_source_hash_meta_key( $source_key, $lang ), true );

			// Fingerprints without stored meta block auto-translate retries — drop stale rows.
			if ( '' !== trim( $stored_hash ) ) {
				delete_post_meta( absint( $post_id ), self::get_field_source_hash_meta_key( $source_key, $lang ) );
			}

			return false;
		}

		$stored_value = (string) get_post_meta( absint( $post_id ), $translated_key, true );

		if ( ! self::is_clean_target_language_translation( $stored_value, $lang ) ) {
			return false;
		}

		if ( 'fa' !== $lang ) {
			$normalized_source  = self::normalize_translation_plaintext( $source );
			$normalized_stored  = self::normalize_translation_plaintext( $stored_value );

			if ( '' !== $normalized_source && hash_equals( $normalized_source, $normalized_stored ) ) {
				return false;
			}
		}

		$stored_hash = (string) get_post_meta( absint( $post_id ), self::get_field_source_hash_meta_key( $source_key, $lang ), true );

		if ( '' === trim( $stored_hash ) ) {
			// Legacy rows saved before source fingerprints — backfill and serve.
			self::store_field_source_hash( $post_id, $source_key, $lang, $source );

			return true;
		}

		return hash_equals( $stored_hash, md5( (string) $source ) );
	}

	private static function is_term_translation_current( $term_id, $field, $lang, $source ) {
		$meta_key = self::get_term_meta_key( $field, $lang );

		if ( ! self::has_meaningful_translation( get_term_meta( absint( $term_id ), $meta_key, true ) ) ) {
			return false;
		}

		$stored_hash = (string) get_term_meta( absint( $term_id ), self::get_term_source_hash_meta_key( $field, $lang ), true );

		if ( '' === trim( $stored_hash ) ) {
			// Legacy rows saved before source fingerprints — backfill and serve.
			update_term_meta( absint( $term_id ), self::get_term_source_hash_meta_key( $field, $lang ), md5( (string) $source ) );

			return true;
		}

		return hash_equals( $stored_hash, md5( (string) $source ) );
	}

	private static function store_field_source_hash( $post_id, $source_key, $lang, $source ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$source  = (string) $source;

		if ( $post_id <= 0 || '' === $lang || '' === trim( $source ) ) {
			return;
		}

		update_post_meta( $post_id, self::get_field_source_hash_meta_key( $source_key, $lang ), md5( $source ) );
	}

	private static function resolve_translated_meta_key_for_source( $source_key, $lang ) {
		$core_map = array(
			'post_title'          => self::get_meta_key( 'title', $lang ),
			'post_content'        => self::get_meta_key( 'content', $lang ),
			'post_excerpt'        => self::get_meta_key( 'excerpt', $lang ),
			self::WVE_VARIATION_CUSTOM_TITLE_META       => self::get_meta_key( 'title', $lang ),
			self::WVE_VARIATION_CUSTOM_DESCRIPTION_META => self::get_meta_key( 'excerpt', $lang ),
		);

		if ( isset( $core_map[ $source_key ] ) ) {
			return $core_map[ $source_key ];
		}

		if ( self::is_custom_meta_key( $source_key ) ) {
			return self::get_custom_meta_key( $source_key, $lang );
		}

		return '';
	}

	private static function persist_field_source_hashes( $post_id, array $translations, $lang, \WP_Post $post ) {
		unset( $translations, $post );

		self::sync_field_source_hashes_for_post( $post_id, $lang );
	}

	public static function sync_field_source_hashes_for_post( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$post    = get_post( $post_id );

		if ( $post_id <= 0 || '' === $lang || ! $post instanceof \WP_Post ) {
			return;
		}

		$sources = self::collect_persian_fields( $post, '' );

		foreach ( $sources as $source_key => $source ) {
			if ( ! is_string( $source_key ) || ! is_string( $source ) ) {
				continue;
			}

			if ( self::is_term_payload_key( $source_key ) || 0 === strpos( $source_key, 'product_attr_' ) || self::is_variation_title_payload_key( $source_key ) ) {
				continue;
			}

			$translated_key = self::resolve_translated_meta_key_for_source( $source_key, $lang );

			if ( '' === $translated_key ) {
				continue;
			}

			if ( ! self::has_meaningful_translation( get_post_meta( $post_id, $translated_key, true ) ) ) {
				continue;
			}

			self::store_field_source_hash( $post_id, $source_key, $lang, $source );
		}
	}

	public static function invalidate_product_attribute_runtime_cache( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return;
		}

		$snapshot = get_post_meta( $post_id, '_polymart_ai_attr_cache_snapshot', true );

		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}

		$current = self::get_product_attribute_runtime_cache_entries( $post_id );
		$entries = array_merge( $snapshot, $current );

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['text'] ) || empty( $entry['context'] ) ) {
					continue;
				}

				Runtime_String_Translator::forget_translation(
					(string) $entry['text'],
					$lang,
					(string) $entry['context']
				);
			}
		}

		update_post_meta( $post_id, '_polymart_ai_attr_cache_snapshot', $current );
		update_post_meta( $post_id, '_polymart_ai_attr_fingerprint', md5( wp_json_encode( $current ) ) );
	}

	public static function invalidate_product_variation_title_runtime_cache( $product_id, $previous_snapshot = null ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		if ( null === $previous_snapshot ) {
			$previous_snapshot = get_post_meta( $product_id, '_polymart_ai_variation_titles_snapshot', true );
		}

		if ( ! is_array( $previous_snapshot ) ) {
			$previous_snapshot = array();
		}

		$entries = array_merge( $previous_snapshot, self::get_product_variation_title_snapshot( $product_id ) );
		$seen    = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['variation_id'] ) ) {
				continue;
			}

			$variation_id = absint( $entry['variation_id'] );
			$texts        = array(
				array(
					'text'    => (string) ( $entry['custom_title'] ?? $entry['title'] ?? '' ),
					'context' => 'wc_variation_custom_title:' . $variation_id,
				),
				array(
					'text'    => (string) ( $entry['custom_description'] ?? '' ),
					'context' => 'wc_variation_custom_description:' . $variation_id,
				),
			);

			foreach ( $texts as $text_entry ) {
				$text = trim( (string) ( $text_entry['text'] ?? '' ) );

				if ( '' === $text ) {
					continue;
				}

				$cache_key = $variation_id . "\0" . $text_entry['context'] . "\0" . $text;

				if ( isset( $seen[ $cache_key ] ) ) {
					continue;
				}

				$seen[ $cache_key ] = true;

				foreach ( Language_Registry::get_translation_target_languages() as $language ) {
					$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

					if ( '' === $lang ) {
						continue;
					}

					Runtime_String_Translator::forget_translation(
						$text,
						$lang,
						(string) $text_entry['context']
					);
				}
			}
		}

		self::refresh_product_variation_titles_fingerprint( $product_id );
	}

	public static function reconcile_variation_translation_after_save( $variation_id, \WP_Post $post_after, $post_before = null ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 || ! $post_after instanceof \WP_Post || 'product_variation' !== $post_after->post_type ) {
			return;
		}

		if ( $post_before instanceof \WP_Post && $post_before->post_title !== $post_after->post_title ) {
			self::invalidate_field_translation( $variation_id, 'post_title' );
		}

		if ( '' === trim( Persian_Detector::only_persian_value( $post_after->post_title ) ) ) {
			self::invalidate_field_translation( $variation_id, 'post_title' );
		}
	}

	public static function reconcile_variation_custom_meta_after_save( $variation_id, $meta_key, $prev_value = null ) {
		$variation_id = absint( $variation_id );
		$meta_key     = (string) $meta_key;

		if ( $variation_id <= 0 ) {
			return;
		}

		if ( ! in_array( $meta_key, array( self::WVE_VARIATION_CUSTOM_TITLE_META, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true ) ) {
			return;
		}

		self::invalidate_field_translation( $variation_id, $meta_key );

		$previous = is_string( $prev_value ) ? trim( $prev_value ) : '';

		if ( '' !== $previous ) {
			$context = self::WVE_VARIATION_CUSTOM_TITLE_META === $meta_key
				? 'wc_variation_custom_title:' . $variation_id
				: 'wc_variation_custom_description:' . $variation_id;

			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' === $lang ) {
					continue;
				}

				Runtime_String_Translator::forget_translation( $previous, $lang, $context );
			}
		}

		$current = get_post_meta( $variation_id, $meta_key, true );
		$current = is_string( $current ) ? trim( $current ) : '';

		if ( '' === trim( Persian_Detector::only_persian_value( $current ) ) ) {
			self::invalidate_field_translation( $variation_id, $meta_key );
		}
	}

	public static function invalidate_term_translations( $term_id ) {
		$term_id = absint( $term_id );

		if ( $term_id <= 0 ) {
			return;
		}

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			delete_term_meta( $term_id, self::get_term_meta_key( 'name', $lang ) );
			delete_term_meta( $term_id, self::get_term_meta_key( 'desc', $lang ) );
			delete_term_meta( $term_id, self::get_term_source_hash_meta_key( 'name', $lang ) );
			delete_term_meta( $term_id, self::get_term_source_hash_meta_key( 'desc', $lang ) );
		}
	}

	public static function clear_post_language_translations( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$suffix = '_' . $lang;

		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $meta_key ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key ) {
				continue;
			}

			$is_polymart = 0 === strpos( $meta_key, '_polymart_ai_' );
			$is_langged  = strlen( $meta_key ) > strlen( $suffix ) && substr( $meta_key, -strlen( $suffix ) ) === $suffix;

			if ( $is_polymart || $is_langged ) {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		delete_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang );
		self::flush_translation_status_cache( $post_id );
	}

}
