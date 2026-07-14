<?php
/**
 * Post_Translator Meta_Discovery (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\MetaDiscovery;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI_Client;
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Storefront_Resolver;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Meta_Discovery {

	public static function collect_discovered_meta_fields( $post_id, $lang = '' ) {
		$post_id  = absint( $post_id );
		$lang     = sanitize_key( (string) $lang );
		$all_meta = get_post_meta( $post_id );
		$fields   = array();

		if ( ! is_array( $all_meta ) ) {
			return $fields;
		}

		foreach ( $all_meta as $meta_key => $values ) {
			if ( ! self::is_translatable_meta_key( $meta_key, $lang ) ) {
				continue;
			}

			if ( in_array( $meta_key, self::CUSTOM_META_KEYS, true ) ) {
				continue;
			}

			if ( '' !== $lang ) {
				$existing = (string) get_post_meta( $post_id, self::get_custom_meta_key( $meta_key, $lang ), true );

				if ( self::has_meaningful_translation( $existing ) ) {
					$value = is_array( $values ) ? reset( $values ) : $values;
					$persian_probe = is_string( $value ) ? Persian_Detector::only_persian_value( $value ) : '';

					if ( '' !== $persian_probe && self::is_field_translation_current( $post_id, $meta_key, $lang, $persian_probe ) ) {
						continue;
					}
				}
			}

			$value = is_array( $values ) ? reset( $values ) : $values;

			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( strlen( $value ) > self::MAX_META_VALUE_LENGTH ) {
				$value = substr( $value, 0, self::MAX_META_VALUE_LENGTH );
			}

			$persian = Persian_Detector::only_persian_value( $value );

			if ( '' !== $persian ) {
				$fields[ $meta_key ] = $persian;
			}
		}

		return $fields;
	}

	public static function get_product_attribute_runtime_cache_entries( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return array();
		}

		$entries = array();

		foreach ( self::collect_product_attribute_fields( $post, '' ) as $key => $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				continue;
			}

			if ( 0 === strpos( $key, 'product_attr_value:' ) ) {
				$group     = substr( $key, strlen( 'product_attr_value:' ) );
				$group     = preg_replace( '/:[a-f0-9]{32}$/', '', (string) $group );
				$entries[] = array(
					'text'    => $text,
					'context' => 'wc_attr_opt:' . sanitize_key( (string) $group ),
				);
				continue;
			}

			if ( 0 === strpos( $key, 'product_attr_label:' ) ) {
				$group     = substr( $key, strlen( 'product_attr_label:' ) );
				$entries[] = array(
					'text'    => $text,
					'context' => 'wc_attr_label:' . sanitize_key( (string) $group ),
				);
			}
		}

		return $entries;
	}

	private static function collect_core_persian_fields( \WP_Post $post, $lang = '' ) {
		$fields = array();
		$lang   = sanitize_key( (string) $lang );

		$title = Persian_Detector::only_persian_value( $post->post_title );

		if ( '' !== $title && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_title', $lang, $title ) ) ) {
			$fields['post_title'] = $title;
		}

		if ( self::uses_elementor_builder( $post->ID ) && ! self::is_commerce_product_post( $post->ID, $post ) ) {
			if ( '' !== self::collect_elementor_persian_plain_text( $post->ID ) ) {
				// Translated via persist_elementor_translation().
			} else {
				$content = self::extract_elementor_html_persian_excerpt( $post->post_content );

				if ( '' !== $content && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_content', $lang, $content ) ) ) {
					$fields['post_content'] = $content;
				}
			}
		} else {
			$content = Persian_Detector::only_persian_value( $post->post_content );

			if ( '' !== $content && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_content', $lang, $content ) ) ) {
				$fields['post_content'] = $content;
			}
		}

		$excerpt = Persian_Detector::only_persian_value( $post->post_excerpt );

		if ( '' !== $excerpt && ( '' === $lang || ! self::is_field_translation_current( $post->ID, 'post_excerpt', $lang, $excerpt ) ) ) {
			$fields['post_excerpt'] = $excerpt;
		}

		foreach ( self::CUSTOM_META_KEYS as $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );
			$value = Persian_Detector::only_persian_value( $value );

			if ( '' === $value ) {
				continue;
			}

			if ( '' !== $lang && self::is_field_translation_current( $post->ID, $meta_key, $lang, $value ) ) {
				continue;
			}

			$fields[ $meta_key ] = $value;
		}

		foreach ( self::collect_discovered_meta_fields( $post->ID, $lang ) as $meta_key => $value ) {
			$fields[ $meta_key ] = $value;
		}

		return $fields;
	}

	private static function collect_commerce_persian_fields( \WP_Post $post, $lang = '' ) {
		$fields = array();
		$lang   = sanitize_key( (string) $lang );

		foreach ( self::collect_translatable_terms( $post->ID ) as $term ) {
			$name = Persian_Detector::only_persian_value( $term->name );

			if ( '' !== $name && ( '' === $lang || ! self::is_term_translation_current( $term->term_id, 'name', $lang, $name ) ) ) {
				$fields[ self::build_term_payload_key( $term->term_id, 'name' ) ] = $name;
			}

			$description = Persian_Detector::only_persian_value( $term->description );

			if ( '' !== $description && ( '' === $lang || ! self::is_term_translation_current( $term->term_id, 'desc', $lang, $description ) ) ) {
				$fields[ self::build_term_payload_key( $term->term_id, 'desc' ) ] = $description;
			}
		}

		foreach ( self::collect_product_attribute_fields( $post, $lang ) as $key => $value ) {
			$fields[ $key ] = $value;
		}

		return $fields;
	}

}
