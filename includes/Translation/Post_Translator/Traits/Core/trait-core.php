<?php
/**
 * Post_Translator Core (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Core;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Core {

	private static function normalize_ai_translation_value( $value ) {
		return Text_Normalizer::normalize_ai_translation_value( $value );
	}

	private static function prepare_stored_meta_value( $value, $field_kind ) {
		return Text_Normalizer::prepare_stored_meta_value( $value, $field_kind );
	}

	private static function normalize_translation_plaintext( $value ) {
		return Text_Normalizer::normalize_translation_plaintext( $value );
	}

	private static function is_clean_target_language_translation( $value, $lang ) {
		return Text_Normalizer::is_clean_target_language_translation( $value, $lang );
	}

	private static function utf8_strlen( $text ) {
		return Text_Normalizer::utf8_strlen( $text );
	}

	private static function utf8_substr( $text, $start, $length ) {
		return Text_Normalizer::utf8_substr( $text, $start, $length );
	}

	public static function is_commerce_product_post( $post_id, $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( absint( $post_id ) );
		}

		if ( $post instanceof \WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return true;
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( absint( $post_id ) );

			return $product instanceof \WC_Product;
		}

		return false;
	}

	private static function filter_elementor_finalize_blocker_skipped( array $skipped, array $source_data, array $map, array $state ) {
		if ( empty( $skipped ) ) {
			return array();
		}

		$source_payload = self::collect_elementor_translation_payload( $source_data );
		$persist_map    = is_array( $state['elementor_persist_map'] ?? null ) ? $state['elementor_persist_map'] : array();
		$merged_map     = array_merge( $persist_map, $map );
		$blocking       = array();

		foreach ( $skipped as $path ) {
			$path = (string) $path;

			if ( '' === $path ) {
				continue;
			}

			// Segment-level timeouts must not block whole-post finalize.
			if ( self::elementor_path_is_segment( $path ) ) {
				continue;
			}

			$text = (string) ( $source_payload[ $path ] ?? '' );

			if ( '' !== $text && self::elementor_field_translation_complete( $path, $text, $merged_map ) ) {
				continue;
			}

			$blocking[] = $path;
		}

		return array_values( array_unique( $blocking ) );
	}

	private static function retry_untranslated_elementor_chunk_fields(
		array $chunk,
		array $mapped_chunk,
		$api_key,
		$api_endpoint,
		$ai_model,
		$lang,
		array $ai_options,
		array &$failures,
		array &$state,
		$max_retries = 4
	) {
		$max_retries = max( 1, absint( $max_retries ) );
		$attempts    = 0;

		foreach ( $chunk as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( '' === $path ) {
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $mapped_chunk ) ) {
				continue;
			}

			if ( $attempts >= $max_retries ) {
				break;
			}

			++$attempts;

			$field_payload = self::expand_payload_for_ai( array( $path => $text ) );
			list( $aliased_field, $field_alias_map ) = self::alias_elementor_payload_keys( $field_payload );

			if ( empty( $aliased_field ) ) {
				continue;
			}

			\PolymartAI\Activity_Logger::wait_for_arvan_api_gap();

			$single = AI_Client::translate_fields(
				$aliased_field,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang,
				$ai_options
			);

			\PolymartAI\Activity_Logger::touch_arvan_api_attempt();

			if ( is_wp_error( $single ) ) {
				$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;
				continue;
			}

			$single_map = self::unmap_elementor_aliases(
				self::collapse_payload_parts( $single ),
				$field_alias_map
			);

			foreach ( $single_map as $single_path => $translated ) {
				$single_path  = (string) $single_path;
				$translated   = trim( (string) $translated );
				$source_text  = (string) ( $field_payload[ $single_path ] ?? $text );

				if ( preg_match( '/^(.+)::__part\d+$/', $single_path, $matches ) ) {
					$source_text = (string) ( $field_payload[ $matches[1] ] ?? $text );
				}

				if ( ! self::elementor_map_value_is_valid_translation( $single_path, $source_text, $translated ) ) {
					$failures[ $path ] = absint( $failures[ $path ] ?? 0 ) + 1;

					if ( $failures[ $path ] >= 2 ) {
						$skipped   = is_array( $state['elementor_skipped'] ?? null ) ? $state['elementor_skipped'] : array();
						$skipped[] = $path;
						$state['elementor_skipped'] = array_values( array_unique( array_map( 'strval', $skipped ) ) );
						unset( $failures[ $path ] );
					}

					continue;
				}

				$mapped_chunk[ $single_path ] = $translated;
			}
		}

		return $mapped_chunk;
	}

	private static function filter_remaining_elementor_payload( array $payload, array $map, array $skipped = array() ) {
		$remaining      = array();
		$skipped_lookup = array_flip( array_map( 'strval', $skipped ) );

		foreach ( $payload as $path => $text ) {
			$path = (string) $path;
			$text = (string) $text;

			if ( isset( $skipped_lookup[ $path ] ) ) {
				continue;
			}

			if ( self::elementor_field_translation_complete( $path, $text, $map ) ) {
				continue;
			}

			$remaining[ $path ] = $text;
		}

		return $remaining;
	}

	private static function collect_variation_title_fields( \WP_Post $post, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return array();
		}

		$fields = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation_id = absint( $variation_id );

			if ( $variation_id <= 0 ) {
				continue;
			}

			$custom_title = Persian_Detector::only_persian_value( self::get_variation_custom_title_meta_raw( $variation_id ) );

			if ( '' !== $custom_title ) {
				if ( '' === $lang || ! self::is_field_translation_current( $variation_id, self::WVE_VARIATION_CUSTOM_TITLE_META, $lang, $custom_title ) ) {
					$fields[ self::build_variation_custom_title_payload_key( $variation_id ) ] = $custom_title;
				}
			} else {
				$title = Persian_Detector::only_persian_value( (string) get_post_field( 'post_title', $variation_id ) );

				if ( '' !== $title && ( '' === $lang || ! self::is_field_translation_current( $variation_id, 'post_title', $lang, $title ) ) ) {
					$fields[ self::build_variation_title_payload_key( $variation_id ) ] = $title;
				}
			}

			$description = Persian_Detector::only_persian_value( self::get_variation_custom_description( $variation_id ) );

			if ( '' === $description ) {
				continue;
			}

			if ( '' !== $lang && self::is_field_translation_current( $variation_id, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META, $lang, $description ) ) {
				continue;
			}

			$fields[ self::build_variation_custom_description_payload_key( $variation_id ) ] = $description;
		}

		return $fields;
	}

	private static function get_attribute_translations_meta_key( $lang ) {
		return '_polymart_ai_attr_i18n_' . sanitize_key( (string) $lang );
	}

}
