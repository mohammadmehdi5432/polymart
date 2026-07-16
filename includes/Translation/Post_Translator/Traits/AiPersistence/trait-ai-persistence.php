<?php
/**
 * Post_Translator Ai_Persistence (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\AiPersistence;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator\Meta_Keys;
use PolymartAI\Translation\Post_Translator\Persistence_Guard;
use PolymartAI\Translation\Post_Translator\Text_Normalizer;
use PolymartAI\Translation\Post_Translator\Translation_Lock;

use PolymartAI\Translation\Storefront\Runtime_String_Translator;

defined( 'ABSPATH' ) || exit;

trait Trait_Ai_Persistence {

	public static function request_ai_term_translation( $term_id, $lang = 'en' ) {
		$term_id = absint( $term_id );

		if (
			! $term_id
			|| (
				! \PolymartAI\Activity_Logger::is_trusted_job_worker()
				&& ! current_user_can( 'manage_categories' )
			)
		) {
			return new \WP_Error(
				'polymart_ai_forbidden',
				__( 'شما اجازه ترجمه این برچسب را ندارید.', 'polymart-ai' )
			);
		}

		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::get_translatable_taxonomies(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_term',
				__( 'برچسب برای ترجمه نامعتبر است.', 'polymart-ai' )
			);
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$payload = self::collect_term_persian_fields( $term );

		if ( empty( $payload ) ) {
			return new \WP_Error(
				'polymart_ai_empty_source',
				__( 'متن فارسی برای ترجمه این برچسب یافت نشد.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلود پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		$translated = array();

		foreach ( self::chunk_payload_for_ai( $payload ) as $chunk ) {
			$chunk_result = AI_Client::translate_fields(
				$chunk,
				$api_key,
				$api_endpoint,
				$ai_model,
				$lang
			);

			if ( is_wp_error( $chunk_result ) ) {
				return $chunk_result;
			}

			$translated = array_merge( $translated, $chunk_result );
		}

		$translated = self::collapse_payload_parts( $translated );

		return array(
			'term'         => $term,
			'translations' => $translated,
		);
	}

	public static function save_ai_term_translations( $term_id, array $translations, $lang = 'en' ) {
		unset( $term_id );

		self::save_term_translations( $translations, $lang );

		return true;
	}

	public static function request_ai_translation( $post_id, $lang = 'en' ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! self::can_translate_post( $post_id ) ) {
			$message = __( 'شما اجازه ترجمه این مورد را ندارید.', 'polymart-ai' );

			if ( \PolymartAI\Activity_Logger::should_bypass_browser_auth_checks() ) {
				$message = sprintf(
					/* translators: 1: current user ID */
					__( 'Worker پس‌زمینه نتوانست دسترسی ترجمه را تأیید کند (User ID: %1$d).', 'polymart-ai' ),
					get_current_user_id()
				);
			}

			return new \WP_Error(
				'polymart_ai_forbidden',
				$message
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return new \WP_Error(
				'polymart_ai_invalid_post',
				__( 'نوع مطلب برای ترجمه نامعتبر است.', 'polymart-ai' )
			);
		}

		$settings = self::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return new \WP_Error(
				'polymart_ai_missing_credentials',
				__( 'اعتبارنامه API آروان‌کلاد پیکربندی نشده است.', 'polymart-ai' )
			);
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$payload      = self::collect_persian_fields( $post, $lang );
		$is_elementor = self::uses_elementor_builder( $post_id );

		if ( empty( $payload ) && ! $is_elementor ) {
			return new \WP_Error(
				'polymart_ai_empty_source',
				__( 'محتوای فارسی برای ترجمه یافت نشد.', 'polymart-ai' )
			);
		}

		if ( empty( $payload ) && '' === self::collect_elementor_persian_plain_text( $post_id ) ) {
			return new \WP_Error(
				'polymart_ai_empty_source',
				__( 'محتوای فارسی برای ترجمه یافت نشد.', 'polymart-ai' )
			);
		}

		if ( ! self::acquire_translation_lock( $post_id, $lang ) ) {
			return new \WP_Error(
				'polymart_ai_translation_in_progress',
				__( 'این مورد در حال ترجمه است. لطفاً چند لحظه صبر کنید.', 'polymart-ai' )
			);
		}

		$release_lock_on_exit = true;

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				$chunk_count = empty( $payload ) ? 0 : count( self::chunk_payload_for_ai( $payload ) );

				@set_time_limit( min( 1200, 90 + ( $chunk_count + ( $is_elementor ? 3 : 0 ) ) * 45 ) );
			}

			if ( ! empty( $payload ) ) {
				/**
				 * Fires before an AI translation request is sent.
				 *
				 * @param int                  $post_id Post ID.
				 * @param \WP_Post             $post    Post object.
				 * @param array<string,string> $payload Persian source fields.
				 * @param string               $lang    Target language code.
				 */
				do_action( 'polymart_ai_before_translate_post', $post_id, $post, $payload, $lang );
			}

			$api_key      = (string) $settings['api_key'];
			$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
			$ai_model     = AI_Client::resolve_model(
				(string) ( $settings['ai_model'] ?? '' ),
				$api_endpoint
			);

			$translated = array();

			if ( ! empty( $payload ) ) {
				foreach ( self::chunk_payload_for_ai( $payload ) as $chunk ) {
					$chunk_result = AI_Client::translate_fields(
						$chunk,
						$api_key,
						$api_endpoint,
						$ai_model,
						$lang
					);

					if ( is_wp_error( $chunk_result ) ) {
						return $chunk_result;
					}

					$translated = array_merge( $translated, $chunk_result );
				}

				$translated = self::collapse_payload_parts( $translated );
			}

			// Hand off lock ownership to save_ai_translations() so concurrent workers
			// cannot overwrite meta before persistence finishes.
			$release_lock_on_exit = false;

			return array(
				'post'         => $post,
				'translations' => $translated,
			);
		} finally {
			if ( $release_lock_on_exit ) {
				self::release_translation_lock( $post_id, $lang );
			}
		}
	}

	public static function map_ai_response_to_meta_fields( array $translations, $lang = 'en' ) {
		$lang = sanitize_key( (string) $lang );

		$core_map = array(
			'post_title'   => self::get_meta_key( 'title', $lang ),
			'post_content' => self::get_meta_key( 'content', $lang ),
			'post_excerpt' => self::get_meta_key( 'excerpt', $lang ),
		);

		$meta_fields = array();

		foreach ( $translations as $source_key => $value ) {
			if ( self::is_term_payload_key( $source_key ) || self::is_variation_title_payload_key( $source_key ) ) {
				continue;
			}

			if ( isset( $core_map[ $source_key ] ) ) {
				$meta_fields[ $core_map[ $source_key ] ] = $value;
				continue;
			}

			if ( self::is_custom_meta_key( $source_key ) ) {
				$meta_fields[ self::get_custom_meta_key( $source_key, $lang ) ] = $value;
			}
		}

		return $meta_fields;
	}

	public static function save_ai_translations( $post_id, array $translations, $lang = 'en', array $options = array() ) {
		$lang = sanitize_key( (string) $lang );

		self::begin_persisting_translations();

		$release_lock = empty( $options['keep_lock'] );

		try {
			return self::save_ai_translations_internal( $post_id, $translations, $lang, $options );
		} finally {
			self::end_persisting_translations();

			if ( $release_lock && self::owns_translation_lock( $post_id, $lang ) ) {
				self::release_translation_lock( $post_id, $lang );
			}
		}
	}

	private static function save_ai_translations_internal( $post_id, array $translations, $lang = 'en', array $options = array() ) {
		$lang = sanitize_key( (string) $lang );
		$post = get_post( absint( $post_id ) );

		foreach ( $translations as $source_key => $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$translations[ $source_key ] = self::normalize_ai_translation_value( $value );
			}
		}

		// Drop unusable AI replies before persist — equal-to-source FA copies were saved
		// for Arabic titles then rejected by is_field_translation_current (ناقص ماند — عنوان).
		if ( $post instanceof \WP_Post && 'fa' !== $lang ) {
			foreach ( $translations as $source_key => $value ) {
				if ( ! is_string( $source_key ) || ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}

				if ( Persian_Detector::should_reject_ai_translation( $value, $lang ) ) {
					unset( $translations[ $source_key ] );
					continue;
				}

				if (
					self::is_term_payload_key( $source_key )
					|| self::is_variation_title_payload_key( $source_key )
					|| 0 === strpos( $source_key, 'product_attr' )
				) {
					continue;
				}

				$source_text = self::get_field_source_text( $post, $source_key );

				if ( '' === trim( (string) $source_text ) ) {
					continue;
				}

				$normalized_source = self::normalize_translation_plaintext( $source_text );
				$normalized_value  = self::normalize_translation_plaintext( $value );

				if ( '' !== $normalized_source && hash_equals( $normalized_source, $normalized_value ) ) {
					unset( $translations[ $source_key ] );
				}
			}
		}

		$meta_fields     = self::map_ai_response_to_meta_fields( $translations, $lang );
		$content_key     = self::get_meta_key( 'content', $lang );
		$excerpt_key     = self::get_meta_key( 'excerpt', $lang );
		$title_key       = self::get_meta_key( 'title', $lang );
		$ai_analysis_key = self::get_custom_meta_key( '_apd_ai_analysis', $lang );
		$prepared_core   = array();

		foreach ( $meta_fields as $meta_key => $value ) {
			if ( $content_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'content' );
			} elseif ( $excerpt_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'excerpt' );
			} elseif ( $title_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'title' );
			} elseif ( $ai_analysis_key === $meta_key ) {
				$stored = self::prepare_stored_meta_value( $value, 'excerpt' );
			} else {
				$stored = self::prepare_stored_meta_value( $value, 'default' );
			}

			update_post_meta( $post_id, $meta_key, $stored );

			if ( $title_key === $meta_key ) {
				$prepared_core['post_title'] = $stored;
			} elseif ( $excerpt_key === $meta_key ) {
				$prepared_core['post_excerpt'] = $stored;
			} elseif ( $content_key === $meta_key ) {
				$prepared_core['post_content'] = $stored;
			}
		}

		self::save_term_translations( $translations, $lang );
		self::save_variation_title_translations( $translations, $lang );
		self::persist_attribute_runtime_cache( $post_id, $translations, $lang );

		$core_sources = array(
			'post_title'   => 'title',
			'post_excerpt' => 'excerpt',
			'post_content' => 'content',
		);
		$core_error   = null;

		foreach ( $core_sources as $source_key => $field ) {
			if ( ! isset( $translations[ $source_key ] ) || ! is_string( $translations[ $source_key ] ) ) {
				continue;
			}

			if ( '' === trim( $translations[ $source_key ] ) ) {
				continue;
			}

			$stored = isset( $prepared_core[ $source_key ] )
				? $prepared_core[ $source_key ]
				: get_post_meta( $post_id, self::get_meta_key( $field, $lang ), true );

			if ( ! self::has_meaningful_translation( $stored ) ) {
				$core_error = new \WP_Error(
					'polymart_ai_core_meta_not_saved',
					sprintf(
						/* translators: %s: source field key */
						__( 'ترجمه «%s» پس از ذخیره در meta دیده نشد.', 'polymart-ai' ),
						$source_key
					),
					array(
						'post_id'    => $post_id,
						'lang'       => $lang,
						'source_key' => $source_key,
					)
				);
				break;
			}
		}

		if ( $core_error instanceof \WP_Error ) {
			self::flush_translation_status_cache( $post_id );
			self::sync_translation_index_meta( $post_id, $lang );
			REST_API::invalidate_stats_cache();

			return $core_error;
		}

		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			self::persist_field_source_hashes( $post_id, $translations, $lang, $post );

			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				self::refresh_product_variation_titles_fingerprint( $post_id );
			}

			self::persist_cms_block_runtime_cache( $post_id, $lang, $post );
		}

		if ( empty( $options['skip_elementor'] ) && self::should_require_elementor_translation( $post_id ) ) {
			$elementor_result = self::persist_elementor_translation( $post_id, $lang );

			if ( is_wp_error( $elementor_result ) ) {
				update_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang, $elementor_result->get_error_message() );
				self::clear_elementor_translation_finalized( $post_id, $lang );
				delete_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang );
				self::flush_translation_status_cache( $post_id );
				self::sync_translation_index_meta( $post_id, $lang );
				REST_API::invalidate_stats_cache();

				$log_level = 'polymart_ai_elementor_partial' === $elementor_result->get_error_code() ? 'warning' : 'error';
				\PolymartAI\Activity_Logger::log(
					$log_level,
					sprintf(
						/* translators: 1: post ID, 2: language code, 3: error message */
						__( 'ذخیره Elementor مورد #%1$d (%2$s) ناموفق بود: %3$s', 'polymart-ai' ),
						$post_id,
						$lang,
						$elementor_result->get_error_message()
					),
					array(
						'post_id' => $post_id,
						'lang'    => $lang,
						'source'  => 'elementor_persist',
					)
				);

				return $elementor_result;
			}

			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		} else {
			delete_post_meta( $post_id, '_polymart_ai_elementor_error_' . $lang );
		}

		self::flush_translation_status_cache( $post_id );

		if ( 'translated' === self::get_translation_status( $post_id, $lang ) ) {
			update_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang, time() );
			update_post_meta( $post_id, '_polymart_ai_translated_at', time() );
		} else {
			delete_post_meta( $post_id, '_polymart_ai_translated_at_' . $lang );
		}

		/**
		 * Fires after AI translations have been saved to post meta.
		 *
		 * @param int                   $post_id      Post ID.
		 * @param array<string, string> $translations Saved translations keyed by source field.
		 */
		do_action( 'polymart_ai_after_save_translations', $post_id, $translations );

		self::sync_translation_index_meta( $post_id, $lang );
		REST_API::invalidate_stats_cache();

		return true;
	}

	private static function collect_translatable_terms( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array();
		}

		$terms = array();

		foreach ( self::get_translatable_taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$assigned = get_the_terms( $post_id, $taxonomy );

			if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
				if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product_terms' ) && 0 === strpos( $taxonomy, 'pa_' ) ) {
					$assigned = wc_get_product_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
				}
			}

			if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
				continue;
			}

			foreach ( $assigned as $term ) {
				if ( $term instanceof \WP_Term ) {
					$terms[ $term->term_id ] = $term;
				}
			}
		}

		if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );

			if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );

					if ( ! $variation instanceof \WC_Product_Variation ) {
						continue;
					}

					foreach ( $variation->get_attributes() as $taxonomy => $slug ) {
						if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) || ! is_string( $slug ) || '' === $slug ) {
							continue;
						}

						$term = get_term_by( 'slug', $slug, $taxonomy );

						if ( $term instanceof \WP_Term ) {
							$terms[ $term->term_id ] = $term;
						}
					}
				}
			}
		}

		return array_values( $terms );
	}

	private static function collect_product_attribute_fields( \WP_Post $post, $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$fields = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$attr_name = $attribute->get_name();
			$label     = function_exists( 'wc_attribute_label' )
				? (string) wc_attribute_label( $attr_name, $product )
				: (string) $attr_name;
			$label     = Persian_Detector::only_persian_value( $label );

			if ( '' !== $label ) {
				$fields[ 'product_attr_label:' . self::attribute_field_group( (string) $attr_name ) ] = $label;
			}

			if ( $attribute->is_taxonomy() ) {
				continue;
			}

			foreach ( $attribute->get_options() as $option ) {
				$value = Persian_Detector::only_persian_value( (string) $option );

				if ( '' === $value ) {
					continue;
				}

				$fields[ 'product_attr_value:' . self::attribute_field_group( (string) $attr_name ) . ':' . md5( $value ) ] = $value;
			}
		}

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );

				if ( ! $variation instanceof \WC_Product_Variation ) {
					continue;
				}

				foreach ( $variation->get_attributes() as $attr_name => $attr_value ) {
					if ( ! is_string( $attr_name ) || taxonomy_exists( $attr_name ) ) {
						continue;
					}

					$value = Persian_Detector::only_persian_value( (string) $attr_value );

					if ( '' === $value ) {
						continue;
					}

					$fields[ 'product_attr_value:' . self::attribute_field_group( $attr_name ) . ':' . md5( $value ) ] = $value;
				}
			}
		}

		if ( '' === $lang ) {
			return $fields;
		}

		$pending = array();

		foreach ( $fields as $key => $source ) {
			$context = self::attribute_runtime_context_for_key( $key );

			if ( '' === $context ) {
				continue;
			}

			if ( self::has_product_attribute_translation( $post->ID, $source, $lang, $context ) ) {
				continue;
			}

			// Eastern digits only (e.g. «۴») — map to Western digits without an API call.
			if ( Persian_Detector::is_eastern_digit_string( $source ) ) {
				$western = Persian_Detector::westernize_digits( $source );

				if ( '' !== $western && Persian_Detector::is_acceptable_translation_for_language( $western, $lang ) ) {
					self::store_product_attribute_translation( $post->ID, $source, $lang, $context, $western );
					continue;
				}
			}

			$pending[ $key ] = $source;
		}

		return $pending;
	}

	private static function store_product_attribute_translation( $post_id, $source, $lang, $context, $translated ) {
		$post_id    = absint( $post_id );
		$source     = is_string( $source ) ? trim( $source ) : '';
		$lang       = sanitize_key( (string) $lang );
		$context    = (string) $context;
		$translated = is_string( $translated ) ? trim( $translated ) : '';

		if ( $post_id <= 0 || '' === $source || '' === $lang || '' === $context || '' === $translated ) {
			return;
		}

		Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );

		$meta_key = self::get_attribute_translations_meta_key( $lang );
		$durable  = get_post_meta( $post_id, $meta_key, true );
		$durable  = is_array( $durable ) ? $durable : array();

		$durable[ self::attribute_translation_map_key( $source, $context ) ] = $translated;

		update_post_meta( $post_id, $meta_key, $durable );
		update_post_meta(
			$post_id,
			'_polymart_ai_attr_cache_snapshot',
			self::get_product_attribute_runtime_cache_entries( $post_id )
		);
	}

	private static function attribute_runtime_context_for_key( $key ) {
		if ( ! is_string( $key ) ) {
			return '';
		}

		if ( 0 === strpos( $key, 'product_attr_value:' ) ) {
			$group = substr( $key, strlen( 'product_attr_value:' ) );
			$group = preg_replace( '/:[a-f0-9]{32}$/', '', (string) $group );

			return 'wc_attr_opt:' . sanitize_key( (string) $group );
		}

		if ( 0 === strpos( $key, 'product_attr_label:' ) ) {
			$group = substr( $key, strlen( 'product_attr_label:' ) );

			return 'wc_attr_label:' . sanitize_key( (string) $group );
		}

		return '';
	}

	private static function attribute_field_group( $attr_name ) {
		$name = sanitize_title( str_replace( 'pa_', '', (string) $attr_name ) );

		return '' !== $name ? $name : 'custom';
	}

	private static function persist_cms_block_runtime_cache( $post_id, $lang, \WP_Post $post ) {
		if ( 'cms_block' !== $post->post_type ) {
			return;
		}

		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return;
		}

		$source = trim( (string) $post->post_content );

		if ( '' === $source || ! Persian_Detector::contains_persian( $source ) ) {
			return;
		}

		$translated = get_post_meta( $post_id, self::get_meta_key( 'content', $lang ), true );

		if ( ! is_string( $translated ) || '' === trim( $translated ) ) {
			return;
		}

		Runtime_String_Translator::store_translation(
			$source,
			$lang,
			'cms_block:' . $post_id,
			$translated
		);

		$hash_key = 'html_block:' . md5( wp_strip_all_tags( $source ) );
		$strings  = get_option( 'polymart_ai_theme_strings', array() );

		if ( ! is_array( $strings ) ) {
			$strings = array();
		}

		$strings[ $hash_key ] = $translated;
		update_option( 'polymart_ai_theme_strings', $strings, false );
	}

	private static function persist_attribute_runtime_cache( $post_id, array $translations, $lang ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( ! $post instanceof \WP_Post || '' === $lang ) {
			return;
		}

		// Use unfiltered sources so we persist every attribute key returned by AI,
		// not only fields still pending after other meta was saved in this request.
		$sources = self::collect_product_attribute_fields( $post, '' );
		$meta_key = self::get_attribute_translations_meta_key( $lang );
		$durable  = get_post_meta( $post_id, $meta_key, true );
		$durable  = is_array( $durable ) ? $durable : array();

		foreach ( $sources as $key => $source ) {
			if ( ! is_string( $key ) || ! is_string( $source ) || ! isset( $translations[ $key ] ) ) {
				continue;
			}

			$translated = trim( (string) $translations[ $key ] );
			$context    = self::attribute_runtime_context_for_key( $key );

			if ( '' === $translated || '' === $context ) {
				continue;
			}

			Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );
			$durable[ self::attribute_translation_map_key( $source, $context ) ] = $translated;
		}

		// Persist immediately so the next job step / live stats see durable data.
		Runtime_String_Translator::flush_pending_cache();

		update_post_meta( $post_id, $meta_key, $durable );
		update_post_meta(
			$post_id,
			'_polymart_ai_attr_cache_snapshot',
			self::get_product_attribute_runtime_cache_entries( $post_id )
		);
	}

	private static function attribute_translation_map_key( $source, $context ) {
		return md5( (string) $context . "\0" . (string) $source );
	}

	public static function get_stored_product_attribute_translation( $post_id, $source, $lang, $context ) {
		$source  = is_string( $source ) ? trim( $source ) : '';
		$lang    = sanitize_key( (string) $lang );
		$context = (string) $context;
		$post_id = absint( $post_id );

		if ( '' === $source || '' === $lang || '' === $context || $post_id <= 0 ) {
			return '';
		}

		$cached = Runtime_String_Translator::lookup_cached( $source, $lang, $context );

		if ( is_string( $cached ) && '' !== trim( $cached ) ) {
			return $cached;
		}

		$durable = get_post_meta( $post_id, self::get_attribute_translations_meta_key( $lang ), true );

		if ( ! is_array( $durable ) ) {
			return '';
		}

		$map_key    = self::attribute_translation_map_key( $source, $context );
		$translated = isset( $durable[ $map_key ] ) ? trim( (string) $durable[ $map_key ] ) : '';

		if ( '' === $translated ) {
			return '';
		}

		Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );

		return $translated;
	}

	public static function has_product_attribute_translation( $post_id, $source, $lang, $context ) {
		$source  = is_string( $source ) ? trim( $source ) : '';
		$lang    = sanitize_key( (string) $lang );
		$context = (string) $context;
		$post_id = absint( $post_id );

		if ( '' === $source || '' === $lang || '' === $context ) {
			return false;
		}

		if ( $post_id <= 0 ) {
			return false;
		}

		$durable = get_post_meta( $post_id, self::get_attribute_translations_meta_key( $lang ), true );

		if ( ! is_array( $durable ) ) {
			return false;
		}

		$map_key    = self::attribute_translation_map_key( $source, $context );
		$translated = isset( $durable[ $map_key ] ) ? trim( (string) $durable[ $map_key ] ) : '';

		if ( '' === $translated || ! self::is_clean_target_language_translation( $translated, $lang ) ) {
			return false;
		}

		// Re-hydrate the runtime cache for storefront lookups.
		Runtime_String_Translator::store_translation( $source, $lang, $context, $translated );

		return true;
	}

	private static function build_term_payload_key( $term_id, $field ) {
		return 'term:' . absint( $term_id ) . ':' . sanitize_key( (string) $field );
	}

	private static function is_term_payload_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^term:\d+:(name|desc)$/', $key );
	}

	private static function build_variation_title_payload_key( $variation_id ) {
		return 'variation_title:' . absint( $variation_id );
	}

	private static function build_variation_custom_title_payload_key( $variation_id ) {
		return 'variation_custom_title:' . absint( $variation_id );
	}

	private static function build_variation_custom_description_payload_key( $variation_id ) {
		return 'variation_custom_description:' . absint( $variation_id );
	}

	private static function is_variation_title_payload_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^variation_(custom_)?(title|description):\d+$/', $key );
	}

	private static function save_variation_title_translations( array $translations, $lang ) {
		$lang              = sanitize_key( (string) $lang );
		$parents_refreshed = array();

		foreach ( $translations as $source_key => $value ) {
			if ( ! is_string( $source_key ) || ! is_string( $value ) ) {
				continue;
			}

			$field       = '';
			$source_hash = '';
			$meta_field  = 'title';
			$variation_id = 0;

			if ( 1 === preg_match( '/^variation_custom_title:(\d+)$/', $source_key, $matches ) ) {
				$variation_id = absint( $matches[1] );
				$field        = self::WVE_VARIATION_CUSTOM_TITLE_META;
				$source_hash  = self::get_variation_custom_title( $variation_id );
				$meta_field   = 'title';
			} elseif ( 1 === preg_match( '/^variation_custom_description:(\d+)$/', $source_key, $matches ) ) {
				$variation_id = absint( $matches[1] );
				$field        = self::WVE_VARIATION_CUSTOM_DESCRIPTION_META;
				$source_hash  = self::get_variation_custom_description( $variation_id );
				$meta_field   = 'excerpt';
			} elseif ( 1 === preg_match( '/^variation_title:(\d+)$/', $source_key, $matches ) ) {
				$variation_id = absint( $matches[1] );
				$field        = 'post_title';
				$variation    = get_post( $variation_id );
				$source_hash  = $variation instanceof \WP_Post ? $variation->post_title : '';
				$meta_field   = 'title';
			} else {
				continue;
			}

			$variation = get_post( $variation_id );

			if ( $variation_id <= 0 || ! ( $variation instanceof \WP_Post ) || 'product_variation' !== $variation->post_type ) {
				continue;
			}

			$clean = 'excerpt' === $meta_field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

			if ( '' === trim( $clean ) ) {
				continue;
			}

			update_post_meta( $variation_id, self::get_meta_key( $meta_field, $lang ), $clean );

			if ( in_array( $field, array( self::WVE_VARIATION_CUSTOM_TITLE_META, self::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true ) ) {
				update_post_meta( $variation_id, self::get_custom_meta_key( $field, $lang ), $clean );
			}

			self::store_field_source_hash( $variation_id, $field, $lang, (string) $source_hash );
			self::flush_translation_status_cache( $variation_id );

			$parent_id = wp_get_post_parent_id( $variation_id );

			if ( $parent_id > 0 ) {
				$parents_refreshed[ $parent_id ] = true;
			}
		}

		foreach ( array_keys( $parents_refreshed ) as $parent_id ) {
			self::refresh_product_variation_titles_fingerprint( absint( $parent_id ) );
		}
	}

	private static function save_term_translations( array $translations, $lang ) {
		$lang = sanitize_key( (string) $lang );

		foreach ( $translations as $source_key => $value ) {
			if ( ! is_string( $source_key ) || ! is_string( $value ) ) {
				continue;
			}

			if ( 1 !== preg_match( '/^term:(\d+):(name|desc)$/', $source_key, $matches ) ) {
				continue;
			}

			$term_id = absint( $matches[1] );
			$field   = 'desc' === $matches[2] ? 'desc' : 'name';
			$term    = get_term( $term_id );

			if ( $term_id <= 0 || ! ( $term instanceof \WP_Term ) ) {
				continue;
			}

			$meta_key = self::get_term_meta_key( $field, $lang );
			$clean    = 'desc' === $field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

			if ( '' === trim( $clean ) ) {
				continue;
			}

			$source_raw = 'desc' === $field ? (string) $term->description : (string) $term->name;
			$source     = Persian_Detector::only_persian_value( $source_raw );
			$source     = '' !== $source ? $source : $source_raw;

			if ( 'fa' !== $lang ) {
				if ( Persian_Detector::should_reject_ai_translation( $clean, $lang ) ) {
					continue;
				}

				$normalized_source = self::normalize_translation_plaintext( $source );
				$normalized_clean  = self::normalize_translation_plaintext( $clean );

				if ( '' !== $normalized_source && hash_equals( $normalized_source, $normalized_clean ) ) {
					continue;
				}
			}

			update_term_meta( $term_id, $meta_key, $clean );
			update_term_meta( $term_id, self::get_term_source_hash_meta_key( $field, $lang ), md5( (string) $source ) );
		}
	}

}
