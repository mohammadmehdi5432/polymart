<?php
/**
 * Post_Translator Translation_Status (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\TranslationStatus;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

defined( 'ABSPATH' ) || exit;

trait Trait_Translation_Status {

	public function sync_translation_index_on_save( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		self::sync_translation_index_meta( $post_id );
	}

	public static function has_persian_content( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return self::post_has_persian_content( $post );
	}

	public static function post_has_persian_content( \WP_Post $post ) {
		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return false;
		}

		if ( 'woodmart_slide' === $post->post_type && get_post_thumbnail_id( $post->ID ) ) {
			return true;
		}

		if ( ! empty( self::collect_persian_fields( $post ) ) ) {
			return true;
		}

		if ( self::uses_elementor_builder( $post->ID ) ) {
			if ( '' !== self::collect_elementor_persian_plain_text( $post->ID ) ) {
				return true;
			}

			if ( '' !== self::extract_elementor_html_persian_excerpt( $post->post_content ) ) {
				return true;
			}
		}

		return false;
	}

	public static function get_translation_settings() {
		$settings = wp_parse_args(
			get_option( REST_API::OPTION_KEY, array() ),
			REST_API::get_default_settings()
		);

		$translation = $settings['translation'] ?? array();

		if ( empty( $translation['api_key'] ) && ! empty( $translation['arvan_api_key'] ) ) {
			$translation['api_key'] = $translation['arvan_api_key'];
		}

		if ( empty( $translation['api_endpoint'] ) && ! empty( $translation['arvan_api_endpoint'] ) ) {
			$translation['api_endpoint'] = $translation['arvan_api_endpoint'];
		}

		if ( empty( $translation['ai_model'] ) && ! empty( $translation['arvan_model'] ) ) {
			$translation['ai_model'] = $translation['arvan_model'];
		}

		if ( empty( $translation['ai_model'] ) ) {
			$translation['ai_model'] = AI_Client::DEFAULT_MODEL;
		}

		return $translation;
	}

	public static function get_custom_meta_label( $meta_key, $lang = 'en' ) {
		$lang_label = \PolymartAI\Language_Registry::get_language_label_for_ai( $lang );

		$labels = array(
			'custom_card_subtitle' => sprintf(
				/* translators: %s: language name */
				__( 'زیرعنوان کارت (%s)', 'polymart-ai' ),
				$lang_label
			),
			'custom_card_btn_text' => sprintf(
				/* translators: %s: language name */
				__( 'متن دکمه کارت (%s)', 'polymart-ai' ),
				$lang_label
			),
			'_apd_ai_analysis'     => sprintf(
				/* translators: %s: language name */
				__( 'تحلیل هوش مصنوعی (%s)', 'polymart-ai' ),
				$lang_label
			),
		);

		if ( isset( $labels[ $meta_key ] ) ) {
			return $labels[ $meta_key ];
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $meta_key ) ) . ' (' . $lang . ')';
	}

	public static function get_translation_status( $post_id, $lang = 'en' ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! $post_id ) {
			return 'untranslated';
		}

		$cache_key = $post_id . ':' . $lang;

		if ( isset( self::$translation_status_cache[ $cache_key ] ) && is_string( self::$translation_status_cache[ $cache_key ] ) ) {
			return self::$translation_status_cache[ $cache_key ];
		}

		$audit = self::sanitize_translation_audit( self::compute_translation_audit( $post_id, $lang ), $post_id );

		$status = (string) ( $audit['status'] ?? 'untranslated' );

		if ( 'translated' === $status ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post && ! empty( self::collect_persian_fields( $post, $lang ) ) ) {
				$status = 'partial';
			} elseif ( self::stored_elementor_translation_has_persian( $post_id, $lang ) ) {
				$status = 'partial';
			} elseif ( self::storefront_would_show_persian_source( $post_id, $lang ) ) {
				$status = 'partial';
			}
		}

		self::$translation_status_cache[ $cache_key ] = $status;

		$index_key     = self::get_status_index_meta_key( $lang );
		$stored_status = sanitize_key( (string) get_post_meta( $post_id, $index_key, true ) );

		if ( $stored_status !== $status ) {
			update_post_meta( $post_id, $index_key, $status );
		}

		return $status;
	}

	public static function get_translation_gaps( $post_id, $lang = 'en' ) {
		self::flush_translation_status_cache( $post_id );

		$audit = self::sanitize_translation_audit( self::compute_translation_audit( $post_id, $lang ), $post_id );

		return array(
			'required' => array_column( $audit['fields'], 'label' ),
			'missing'  => $audit['missing'],
			'labels'   => $audit['labels'],
			'fields'   => $audit['fields'],
			'status'   => $audit['status'],
			'notes'    => $audit['notes'],
		);
	}

	private static function compute_translation_audit( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );
		$post    = get_post( $post_id );
		$fields  = array();
		$notes   = array();

		if ( ! $post instanceof \WP_Post ) {
			return array(
				'status'  => 'translated',
				'fields'  => array(),
				'missing' => array(),
				'labels'  => array(),
				'notes'   => array( __( 'پست یافت نشد.', 'polymart-ai' ) ),
			);
		}

		if ( 'woodmart_slide' === $post->post_type && get_post_thumbnail_id( $post_id ) ) {
			self::ensure_translated_thumbnail_fallback( $post_id, $lang );

			$fields[] = array(
				'key'        => 'thumbnail',
				'label'      => __( 'تصویر شاخص', 'polymart-ai' ),
				'meta_key'   => self::get_thumbnail_meta_key( $lang ),
				'has_source' => true,
				'translated' => self::get_translated_thumbnail_id( $post_id, $lang ) > 0,
			);
		}

		$title_source = Persian_Detector::only_persian_value( $post->post_title );

		if ( '' !== $title_source ) {
			$fields[] = array(
				'key'        => 'post_title',
				'label'      => __( 'عنوان', 'polymart-ai' ),
				'meta_key'   => self::get_meta_key( 'title', $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, 'post_title', $lang, $title_source ),
			);
		}

		if ( ! self::is_commerce_product_post( $post_id, $post ) && self::should_require_elementor_translation( $post_id, $post ) ) {
			if ( self::has_elementor_persian_content( $post_id ) ) {
				$fields[] = array(
					'key'        => 'elementor_json',
					'label'      => __( 'بخش‌های Elementor', 'polymart-ai' ),
					'meta_key'   => self::get_elementor_meta_key( $lang ),
					'has_source' => true,
					'translated' => self::has_stored_elementor_translation( $post_id, $lang )
						&& ! self::stored_elementor_translation_has_persian( $post_id, $lang )
						&& ! self::elementor_job_has_remaining_payload( $post_id, $lang ),
				);
			} else {
				$html_source = self::extract_elementor_html_persian_excerpt( $post->post_content );

				if ( '' !== $html_source ) {
					$fields[] = array(
						'key'        => 'post_content_html',
						'label'      => __( 'محتوای HTML (کش Elementor)', 'polymart-ai' ),
						'meta_key'   => self::get_meta_key( 'content', $lang ),
						'has_source' => true,
						'translated' => self::is_field_translation_current( $post_id, 'post_content', $lang, $html_source ),
					);
				}
			}
		} else {
			$content_source = Persian_Detector::only_persian_value( $post->post_content );

			if ( '' !== $content_source ) {
				$fields[] = array(
					'key'        => 'post_content',
					'label'      => __( 'محتوا', 'polymart-ai' ),
					'meta_key'   => self::get_meta_key( 'content', $lang ),
					'has_source' => true,
					'translated' => self::is_field_translation_current( $post_id, 'post_content', $lang, $content_source ),
				);
			}
		}

		$excerpt_source = Persian_Detector::only_persian_value( $post->post_excerpt );

		if ( '' !== $excerpt_source ) {
			$fields[] = array(
				'key'        => 'post_excerpt',
				'label'      => __( 'خلاصه', 'polymart-ai' ),
				'meta_key'   => self::get_meta_key( 'excerpt', $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, 'post_excerpt', $lang, $excerpt_source ),
			);
		}

		foreach ( self::CUSTOM_META_KEYS as $meta_key ) {
			$source = get_post_meta( $post_id, $meta_key, true );
			$source = is_string( $source ) ? Persian_Detector::only_persian_value( $source ) : '';

			if ( '' === $source ) {
				continue;
			}

			$fields[] = array(
				'key'        => $meta_key,
				'label'      => self::get_custom_meta_label( $meta_key, $lang ),
				'meta_key'   => self::get_custom_meta_key( $meta_key, $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, $meta_key, $lang, $source ),
			);
		}

		foreach ( self::get_discovered_meta_fields_cached( $post_id ) as $meta_key => $source ) {
			if ( self::is_commerce_product_post( $post_id, $post ) ) {
				break;
			}

			if ( '' === trim( $source ) ) {
				continue;
			}

			$fields[] = array(
				'key'        => $meta_key,
				'label'      => self::get_custom_meta_label( $meta_key, $lang ),
				'meta_key'   => self::get_custom_meta_key( $meta_key, $lang ),
				'has_source' => true,
				'translated' => self::is_field_translation_current( $post_id, $meta_key, $lang, $source ),
			);
		}

		foreach ( self::collect_translatable_terms( $post_id ) as $term ) {
			$name_source = Persian_Detector::only_persian_value( $term->name );

			if ( '' !== $name_source ) {
				$fields[] = array(
					'key'        => 'term_name_' . $term->term_id,
					'label'      => sprintf(
						/* translators: 1: taxonomy term name */
						__( 'برچسب: %s', 'polymart-ai' ),
						$term->name
					),
					'meta_key'   => self::get_term_meta_key( 'name', $lang ) . ' #' . $term->term_id,
					'has_source' => true,
					'translated' => self::is_term_translation_current( $term->term_id, 'name', $lang, $name_source ),
				);
			}

			$desc_source = Persian_Detector::only_persian_value( $term->description );

			if ( '' !== $desc_source ) {
				$fields[] = array(
					'key'        => 'term_desc_' . $term->term_id,
					'label'      => sprintf(
						/* translators: 1: taxonomy term name */
						__( 'توضیح برچسب: %s', 'polymart-ai' ),
						$term->name
					),
					'meta_key'   => self::get_term_meta_key( 'desc', $lang ) . ' #' . $term->term_id,
					'has_source' => true,
					'translated' => self::is_term_translation_current( $term->term_id, 'desc', $lang, $desc_source ),
				);
			}
		}

		if ( $post instanceof \WP_Post && 'product' === $post->post_type ) {
			foreach ( self::collect_product_attribute_fields( $post, $lang ) as $key => $source ) {
				if ( 0 === strpos( $key, 'product_attr_value:' ) ) {
					$group   = substr( $key, strlen( 'product_attr_value:' ) );
					$group   = preg_replace( '/:[a-f0-9]{32}$/', '', (string) $group );
					$context = 'wc_attr_opt:' . sanitize_key( (string) $group );
					$label   = sprintf(
						/* translators: 1: attribute option value */
						__( 'مقدار ویژگی: %s', 'polymart-ai' ),
						$source
					);
				} elseif ( 0 === strpos( $key, 'product_attr_label:' ) ) {
					$group   = substr( $key, strlen( 'product_attr_label:' ) );
					$context = 'wc_attr_label:' . sanitize_key( (string) $group );
					$label   = sprintf(
						/* translators: 1: attribute label */
						__( 'برچسب ویژگی: %s', 'polymart-ai' ),
						$source
					);
				} else {
					continue;
				}

				$fields[] = array(
					'key'        => $key,
					'label'      => $label,
					'meta_key'   => $context,
					'has_source' => true,
					'translated' => self::has_product_attribute_translation( $post_id, $source, $lang, $context ),
				);
			}

			foreach ( self::collect_variation_title_fields( $post, $lang ) as $key => $source ) {
				if ( 1 === preg_match( '/^variation_custom_title:(\d+)$/', $key, $matches ) ) {
					$variation_id = absint( $matches[1] );
					$source_key   = self::WVE_VARIATION_CUSTOM_TITLE_META;
					$label        = sprintf(
						/* translators: 1: WVE custom variation title */
						__( 'عنوان سفارشی متغیر: %s', 'polymart-ai' ),
						$source
					);
					$meta_key     = self::get_meta_key( 'title', $lang ) . ' #' . $variation_id;
				} elseif ( 1 === preg_match( '/^variation_custom_description:(\d+)$/', $key, $matches ) ) {
					$variation_id = absint( $matches[1] );
					$source_key   = self::WVE_VARIATION_CUSTOM_DESCRIPTION_META;
					$label        = sprintf(
						/* translators: 1: WVE custom variation description */
						__( 'توضیح سفارشی متغیر: %s', 'polymart-ai' ),
						$source
					);
					$meta_key     = self::get_meta_key( 'excerpt', $lang ) . ' #' . $variation_id;
				} elseif ( 1 === preg_match( '/^variation_title:(\d+)$/', $key, $matches ) ) {
					$variation_id = absint( $matches[1] );
					$source_key   = 'post_title';
					$label        = sprintf(
						/* translators: 1: variation title */
						__( 'عنوان متغیر: %s', 'polymart-ai' ),
						$source
					);
					$meta_key     = self::get_meta_key( 'title', $lang ) . ' #' . $variation_id;
				} else {
					continue;
				}

				$fields[] = array(
					'key'        => $key,
					'label'      => $label,
					'meta_key'   => $meta_key,
					'has_source' => true,
					'translated' => self::is_field_translation_current( $variation_id, $source_key, $lang, $source ),
				);
			}
		}

		if ( empty( $fields ) && self::post_has_persian_content( $post ) ) {
			$notes[] = __( 'محتوای فارسی شناسایی شد اما فیلد دقیق مشخص نشد — post_content یا meta سفارشی را دستی بررسی کنید.', 'polymart-ai' );
		}

		return self::finalize_translation_audit( $fields, $notes, $post_id );
	}

	private static function sanitize_translation_audit( array $audit, $post_id ) {
		if ( ! self::is_commerce_product_post( $post_id ) ) {
			return $audit;
		}

		$fields = array();

		foreach ( (array) ( $audit['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key      = (string) ( $field['key'] ?? '' );
			$meta_key = (string) ( $field['meta_key'] ?? '' );

			if (
				'elementor_json' === $key
				|| 0 === strpos( $meta_key, '_elementor_' )
				|| 0 === strpos( $key, '_elementor_' )
			) {
				continue;
			}

			$fields[] = $field;
		}

		$notes = is_array( $audit['notes'] ?? null ) ? $audit['notes'] : array();

		return self::finalize_translation_audit( $fields, $notes, $post_id );
	}

	private static function finalize_translation_audit( array $fields, array $notes, $post_id = 0 ) {
		$labels  = array();
		$missing = array();

		foreach ( $fields as $field ) {
			$label            = (string) ( $field['label'] ?? '' );
			$labels[ $label ] = ! empty( $field['translated'] );

			if ( empty( $field['translated'] ) ) {
				$missing[] = $label;
			}
		}

		if ( empty( $fields ) ) {
			if ( $post_id > 0 && self::has_persian_content( $post_id ) ) {
				$status = 'untranslated';
			} elseif ( empty( $notes ) ) {
				$status = 'translated';
			} else {
				$status = 'partial';
			}
		} elseif ( empty( $missing ) ) {
			$status = 'translated';
		} elseif ( count( $missing ) === count( $fields ) ) {
			$status = 'untranslated';
		} else {
			$status = 'partial';
		}

		if ( 'partial' === $status && empty( $missing ) && ! empty( $notes ) ) {
			$missing[] = __( 'نیاز به بررسی دستی', 'polymart-ai' );
		}

		return array(
			'status'  => $status,
			'fields'  => $fields,
			'missing' => $missing,
			'labels'  => $labels,
			'notes'   => $notes,
		);
	}

	private static function get_discovered_meta_fields_cached( $post_id ) {
		$post_id = absint( $post_id );

		if ( isset( self::$discovered_meta_cache[ $post_id ] ) ) {
			return self::$discovered_meta_cache[ $post_id ];
		}

		self::$discovered_meta_cache[ $post_id ] = self::collect_discovered_meta_fields( $post_id );

		return self::$discovered_meta_cache[ $post_id ];
	}

	public static function get_status_index_meta_key( $lang ) {
		return self::STATUS_INDEX_META_PREFIX . sanitize_key( (string) $lang );
	}

	public static function reconcile_all_flagged_translation_indexes( $lang ) {
		global $wpdb;

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return 0;
		}

		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', self::get_supported_post_types() )
			)
		);

		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$type_clause  = " AND p.post_type IN ( {$placeholders} ) ";
		$checked      = 0;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_flag
					ON p.ID = pm_flag.post_id
					AND pm_flag.meta_key = %s
					AND pm_flag.meta_value = '1'
				WHERE p.post_status = 'publish'
					{$type_clause}
				ORDER BY p.ID ASC",
				array_merge( array( self::PERSIAN_CONTENT_FLAG_META ), $post_types )
			)
		);

		if ( ! is_array( $post_ids ) ) {
			return 0;
		}

		foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}

			self::flush_translation_status_cache( $post_id );
			self::get_translation_status( $post_id, $lang );
			++$checked;
		}

		return $checked;
	}

	public static function reconcile_stale_translation_indexes( $lang, $batch_limit = 500 ) {
		global $wpdb;

		$lang        = sanitize_key( (string) $lang );
		$batch_limit = max( 1, absint( $batch_limit ) );

		if ( '' === $lang ) {
			return 0;
		}

		$post_types = self::get_supported_post_types();

		if ( empty( $post_types ) ) {
			return 0;
		}

		$status_key = self::get_status_index_meta_key( $lang );
		$title_key  = self::get_meta_key( 'title', $lang );
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types )
			)
		);
		$reconciled = 0;

		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$type_clause  = " AND p.post_type IN ( {$placeholders} ) ";

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_flag
					ON p.ID = pm_flag.post_id
					AND pm_flag.meta_key = %s
					AND pm_flag.meta_value = '1'
				INNER JOIN {$wpdb->postmeta} pm_status
					ON p.ID = pm_status.post_id
					AND pm_status.meta_key = %s
					AND pm_status.meta_value = 'translated'
				LEFT JOIN {$wpdb->postmeta} pm_title
					ON p.ID = pm_title.post_id
					AND pm_title.meta_key = %s
				WHERE p.post_status = 'publish'
					{$type_clause}
				ORDER BY ( pm_title.meta_id IS NULL OR pm_title.meta_value = '' OR pm_title.meta_value IS NULL ) DESC, p.ID ASC
				LIMIT %d",
				array_merge(
					array( self::PERSIAN_CONTENT_FLAG_META, $status_key, $title_key ),
					$post_types,
					array( $batch_limit )
				)
			)
		);

		if ( ! is_array( $post_ids ) ) {
			return 0;
		}

		foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}

			self::flush_translation_status_cache( $post_id );
			$stored_status = sanitize_key( (string) get_post_meta( $post_id, $status_key, true ) );
			$live_status   = self::get_translation_status( $post_id, $lang );

			if ( $stored_status !== $live_status ) {
				++$reconciled;
			}
		}

		return $reconciled;
	}

	public static function sync_translation_index_meta( $post_id, $lang = null ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			delete_post_meta( $post_id, self::PERSIAN_CONTENT_FLAG_META );

			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' !== $code ) {
					delete_post_meta( $post_id, self::get_status_index_meta_key( $code ) );
				}
			}

			return;
		}

		if ( ! self::post_has_persian_content( $post ) ) {
			delete_post_meta( $post_id, self::PERSIAN_CONTENT_FLAG_META );

			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' !== $code ) {
					delete_post_meta( $post_id, self::get_status_index_meta_key( $code ) );
				}
			}

			return;
		}

		update_post_meta( $post_id, self::PERSIAN_CONTENT_FLAG_META, '1' );

		$languages = array();

		if ( is_string( $lang ) && '' !== sanitize_key( $lang ) ) {
			$languages[] = sanitize_key( $lang );
		} else {
			foreach ( Language_Registry::get_translation_target_languages() as $language ) {
				$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' !== $code ) {
					$languages[] = $code;
				}
			}
		}

		foreach ( $languages as $target_lang ) {
			update_post_meta(
				$post_id,
				self::get_status_index_meta_key( $target_lang ),
				self::get_translation_status( $post_id, $target_lang )
			);
		}
	}

	public static function flush_translation_status_cache( $post_id = 0 ) {
		$post_id = absint( $post_id );

		if ( $post_id > 0 ) {
			unset(
				self::$discovered_meta_cache[ $post_id ],
				self::$elementor_persian_cache[ $post_id ],
				self::$elementor_source_hash_cache[ $post_id ],
				self::$elementor_plain_text_cache[ $post_id ]
			);

			foreach ( array_keys( self::$stored_elementor_persian_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$stored_elementor_persian_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$translation_status_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$translation_status_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$elementor_current_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$elementor_current_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$stored_elementor_json_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$stored_elementor_json_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$elementor_storefront_serve_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, 'serve:' . $post_id . ':' ) ) {
					unset( self::$elementor_storefront_serve_cache[ $cache_key ] );
				}
			}

			foreach ( array_keys( self::$should_serve_cache ) as $cache_key ) {
				if ( 0 === strpos( $cache_key, $post_id . ':' ) ) {
					unset( self::$should_serve_cache[ $cache_key ] );
				}
			}

			return;
		}

		self::$translation_status_cache        = array();
		self::$discovered_meta_cache           = array();
		self::$elementor_persian_cache         = array();
		self::$elementor_source_hash_cache     = array();
		self::$elementor_current_cache         = array();
		self::$elementor_plain_text_cache      = array();
		self::$stored_elementor_json_cache     = array();
		self::$elementor_storefront_serve_cache = array();
		self::$should_serve_cache              = array();
	}

	public static function format_translation_record( \WP_Post $post, $lang = 'en' ) {
		$post_id = (int) $post->ID;
		$lang    = sanitize_key( (string) $lang );
		$status  = self::get_translation_status( $post_id, $lang );
		$type    = $post->post_type;

		$custom_fields = array();

		foreach ( self::CUSTOM_META_KEYS as $meta_key ) {
			$value_fa = get_post_meta( $post_id, $meta_key, true );

			if ( ! is_string( $value_fa ) || ! Persian_Detector::contains_persian( $value_fa ) ) {
				continue;
			}

			$translated_key = self::get_custom_meta_key( $meta_key, $lang );

			$custom_fields[] = array(
				'key'      => $meta_key,
				'meta_key' => $translated_key,
				'label'    => self::get_custom_meta_label( $meta_key, $lang ),
				'value_fa' => $value_fa,
				'value_en' => (string) get_post_meta( $post_id, $translated_key, true ),
			);
		}

		foreach ( self::collect_discovered_meta_fields( $post_id ) as $meta_key => $value_fa ) {
			$translated_key = self::get_custom_meta_key( $meta_key, $lang );

			$custom_fields[] = array(
				'key'      => $meta_key,
				'meta_key' => $translated_key,
				'label'    => self::get_custom_meta_label( $meta_key, $lang ),
				'value_fa' => $value_fa,
				'value_en' => (string) get_post_meta( $post_id, $translated_key, true ),
			);
		}

		$translated_at = (int) get_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, true );

		if ( $translated_at <= 0 ) {
			$translated_at = (int) get_post_meta( $post_id, '_polymart_ai_translated_at', true );
		}

		$language = \PolymartAI\Language_Registry::get_language( $lang );
		$prefix   = $language && ! empty( $language['url_prefix'] ) ? $language['url_prefix'] : 'en';

		return array(
			'post_id'              => $post_id,
			'post_type'            => $type,
			'post_type_label'      => self::get_post_type_label( $type ),
			'lang'                 => $lang,
			'lang_label'           => $language ? $language['native_name'] : $lang,
			'status'               => $status,
			'has_persian_content'  => self::has_persian_content( $post_id ),
			'title_fa'             => Persian_Detector::contains_persian( $post->post_title ) ? $post->post_title : '',
			'title_en'             => (string) get_post_meta( $post_id, self::get_meta_key( 'title', $lang ), true ),
			'excerpt_fa'           => Persian_Detector::contains_persian( $post->post_excerpt ) ? $post->post_excerpt : '',
			'excerpt_en'           => (string) get_post_meta( $post_id, self::get_meta_key( 'excerpt', $lang ), true ),
			'content_fa'           => Persian_Detector::contains_persian( $post->post_content ) ? $post->post_content : '',
			'content_en'           => (string) get_post_meta( $post_id, self::get_meta_key( 'content', $lang ), true ),
			'custom_fields'        => $custom_fields,
			'translated_at'        => $translated_at > 0 ? $translated_at : null,
			'edit_url'             => get_edit_post_link( $post_id, 'raw' ),
			'view_url_fa'          => get_permalink( $post_id ),
			'view_url_en'          => '' !== $prefix
				? home_url( '/' . $prefix . '/' . trim( str_replace( home_url(), '', get_permalink( $post_id ) ), '/' ) . '/' )
				: get_permalink( $post_id ),
		);
	}

	public static function get_post_type_label( $post_type ) {
		$labels = array(
			'product'         => __( 'محصول', 'polymart-ai' ),
			'post'            => __( 'نوشته', 'polymart-ai' ),
			'page'            => __( 'برگه', 'polymart-ai' ),
			'woodmart_slide'  => __( 'اسلاید وودمارت', 'polymart-ai' ),
			'cms_block'       => __( 'بلوک CMS', 'polymart-ai' ),
			'woodmart_layout' => __( 'چیدمان وودمارت', 'polymart-ai' ),
		);

		return $labels[ $post_type ] ?? $post_type;
	}

}
