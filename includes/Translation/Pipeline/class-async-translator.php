<?php
/**
 * Background auto-translation when post or term content changes (WP-Cron).
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\Pipeline;

use PolymartAI\Activity_Logger;
use PolymartAI\Language_Registry;
use PolymartAI\REST_API;

defined( 'ABSPATH' ) || exit;

/**
 * Class Async_Translator
 */
final class Async_Translator {

	/**
	 * WP-Cron hook for deferred post translation.
	 */
	const CRON_HOOK = 'polymart_ai_async_translate_post';

	/**
	 * WP-Cron hook for deferred taxonomy term translation.
	 */
	const TERM_CRON_HOOK = 'polymart_ai_async_translate_term';

	/**
	 * Post statuses that trigger auto-translation when transitioning to publish.
	 *
	 * @var string[]
	 */
	const FIRST_PUBLISH_STATUSES = array( 'draft', 'auto-draft', 'pending', 'future' );

	/**
	 * Guard against re-entrancy while persisting AI translations.
	 *
	 * @var bool
	 */
	private static $is_translating = false;

	/**
	 * Post IDs queued for immediate translation at the end of the admin request.
	 *
	 * @var array<int, bool>
	 */
	private static $shutdown_translation_queue = array();

	/**
	 * Whether the shutdown runner has been registered.
	 *
	 * @var bool
	 */
	private static $shutdown_hook_registered = false;

	/**
	 * Term field snapshot captured on edit_term (before save).
	 *
	 * @var array<int, array{name: string, description: string}>
	 */
	private static $term_edit_snapshots = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'post_updated', array( $this, 'handle_post_updated' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 10, 3 );
		add_filter( 'update_post_metadata', array( $this, 'detect_custom_meta_change' ), 10, 5 );
		add_action( 'added_post_meta', array( $this, 'handle_added_post_meta' ), 10, 4 );
		add_action( self::CRON_HOOK, array( $this, 'execute_background_translation' ), 10, 1 );
		add_action( self::TERM_CRON_HOOK, array( $this, 'execute_background_term_translation' ), 10, 1 );

		add_action( 'edit_term', array( $this, 'capture_term_before_edit' ), 5, 3 );
		add_action( 'edited_term', array( $this, 'handle_edited_term' ), 10, 3 );
		add_action( 'created_term', array( $this, 'handle_created_term' ), 10, 3 );

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_update_product', array( $this, 'handle_woocommerce_product_update' ), 20, 1 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'handle_woocommerce_save_product_variation' ), 20, 2 );
		}
	}

	/**
	 * Queue translation when core post text fields change.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post object after update.
	 * @param \WP_Post $post_before Post object before update.
	 * @return void
	 */
	public function handle_post_updated( $post_id, $post_after, $post_before ) {
		if ( self::$is_translating || Post_Translator::should_skip_translation_invalidation() ) {
			return;
		}

		if ( $post_after instanceof \WP_Post && 'product_variation' === $post_after->post_type ) {
			$this->handle_variation_post_updated( $post_id, $post_after, $post_before );

			return;
		}

		if ( $this->should_skip_request( $post_id, $post_after ) ) {
			return;
		}

		if ( ! $post_before instanceof \WP_Post || ! $this->core_text_changed( $post_before, $post_after ) ) {
			return;
		}

		if ( 'product' === $post_after->post_type ) {
			Post_Translator::invalidate_product_attribute_runtime_cache( $post_id );
		}

		Post_Translator::reconcile_post_translations_after_save( $post_id, $post_after, $post_before );

		if ( Post_Translator::is_admin_translation_save_request() ) {
			return;
		}

		if ( ! Post_Translator::has_persian_content( $post_id ) ) {
			return;
		}

		$this->schedule_translation( $post_id );
	}

	/**
	 * Queue parent-product translation when a variation custom title changes.
	 *
	 * @param int           $variation_id Variation post ID.
	 * @param \WP_Post      $post_after   Variation after save.
	 * @param \WP_Post|null $post_before  Variation before save.
	 * @return void
	 */
	private function handle_variation_post_updated( $variation_id, $post_after, $post_before = null ) {
		$variation_id = absint( $variation_id );

		if ( ! $variation_id || wp_is_post_revision( $variation_id ) || wp_is_post_autosave( $variation_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( $post_before instanceof \WP_Post && $post_before->post_title === $post_after->post_title ) {
			return;
		}

		Post_Translator::reconcile_variation_translation_after_save( $variation_id, $post_after, $post_before );

		$parent_id = wp_get_post_parent_id( $variation_id );

		if ( $parent_id <= 0 ) {
			return;
		}

		$parent = get_post( $parent_id );

		if ( ! $parent instanceof \WP_Post || $this->should_skip_request( $parent_id, $parent ) ) {
			return;
		}

		Post_Translator::invalidate_product_variation_title_runtime_cache( $parent_id );

		if ( ! Post_Translator::has_persian_content( $parent_id ) ) {
			return;
		}

		$this->schedule_translation( $parent_id );
	}

	/**
	 * Re-translate WooCommerce products when attributes or other product data change.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function handle_woocommerce_product_update( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || self::$is_translating ) {
			return;
		}

		$post = get_post( $product_id );

		if ( ! $post instanceof \WP_Post || $this->should_skip_request( $product_id, $post ) ) {
			return;
		}

		$current_attr = Post_Translator::get_product_attribute_runtime_cache_entries( $product_id );
		$attr_fp      = md5( wp_json_encode( $current_attr ) );
		$variation_fp = Post_Translator::compute_product_variation_titles_fingerprint( $product_id );
		$stored_attr  = (string) get_post_meta( $product_id, '_polymart_ai_attr_fingerprint', true );
		$stored_var   = (string) get_post_meta( $product_id, '_polymart_ai_variation_titles_fingerprint', true );
		$attr_changed = '' !== $stored_attr && ! hash_equals( $stored_attr, $attr_fp );
		$var_changed  = '' !== $stored_var && ! hash_equals( $stored_var, $variation_fp );

		if ( ! $attr_changed && ! $var_changed ) {
			return;
		}

		if ( $attr_changed ) {
			Post_Translator::invalidate_product_attribute_runtime_cache( $product_id );
		}

		if ( $var_changed ) {
			Post_Translator::invalidate_product_variation_title_runtime_cache( $product_id );
		}

		if ( Post_Translator::has_persian_content( $product_id ) ) {
			$this->schedule_translation( $product_id );
		}
	}

	/**
	 * Queue parent-product translation after WooCommerce saves one variation row.
	 *
	 * Covers bulk variation saves where post_updated may not carry a reliable before snapshot.
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $loop_index   Variation loop index in the product editor.
	 * @return void
	 */
	public function handle_woocommerce_save_product_variation( $variation_id, $loop_index ) {
		unset( $loop_index );

		if ( self::$is_translating ) {
			return;
		}

		$variation_id = absint( $variation_id );
		$post         = get_post( $variation_id );

		if ( ! $post instanceof \WP_Post || 'product_variation' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $variation_id ) || wp_is_post_autosave( $variation_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$parent_id = wp_get_post_parent_id( $variation_id );

		if ( $parent_id <= 0 ) {
			return;
		}

		$parent = get_post( $parent_id );

		if ( ! $parent instanceof \WP_Post || $this->should_skip_request( $parent_id, $parent ) ) {
			return;
		}

		$current_fp = Post_Translator::compute_product_variation_titles_fingerprint( $parent_id );
		$stored_fp  = (string) get_post_meta( $parent_id, '_polymart_ai_variation_titles_fingerprint', true );

		if ( '' !== $stored_fp && hash_equals( $stored_fp, $current_fp ) ) {
			return;
		}

		Post_Translator::reconcile_variation_translation_after_save( $variation_id, $post );
		Post_Translator::invalidate_product_variation_title_runtime_cache( $parent_id );

		if ( ! Post_Translator::has_persian_content( $parent_id ) ) {
			return;
		}

		$this->schedule_translation( $parent_id );
	}

	/**
	 * Snapshot term source text before WordPress writes the edit.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy row ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function capture_term_before_edit( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );

		$term_id  = absint( $term_id );
		$taxonomy = sanitize_key( (string) $taxonomy );

		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		self::$term_edit_snapshots[ $term_id ] = array(
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
		);
	}

	/**
	 * Queue translation when a taxonomy term is updated in wp-admin.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy row ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function handle_edited_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );

		$this->queue_term_translation_if_needed( $term_id, $taxonomy, true );
	}

	/**
	 * Queue translation when a new taxonomy term is created.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy row ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function handle_created_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );

		$this->queue_term_translation_if_needed( $term_id, $taxonomy, false );
	}

	/**
	 * Shared handler for created/edited taxonomy terms.
	 *
	 * @param int    $term_id       Term ID.
	 * @param string $taxonomy      Taxonomy slug.
	 * @param bool   $forget_legacy Whether to clear runtime cache for pre-edit text.
	 * @return void
	 */
	private function queue_term_translation_if_needed( $term_id, $taxonomy, $forget_legacy ) {
		if ( self::$is_translating ) {
			return;
		}

		$term_id  = absint( $term_id );
		$taxonomy = sanitize_key( (string) $taxonomy );

		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		if ( ! in_array( $taxonomy, Post_Translator::get_translatable_taxonomies(), true ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof \WP_Term || ! Post_Translator::term_has_persian_content( $term_id ) ) {
			unset( self::$term_edit_snapshots[ $term_id ] );

			return;
		}

		if ( $forget_legacy && isset( self::$term_edit_snapshots[ $term_id ] ) ) {
			$previous = self::$term_edit_snapshots[ $term_id ];
			Runtime_String_Translator::invalidate_term_runtime_cache(
				$term_id,
				$previous['name'] ?? '',
				$previous['description'] ?? ''
			);
		}

		unset( self::$term_edit_snapshots[ $term_id ] );

		Runtime_String_Translator::invalidate_term_runtime_cache(
			$term_id,
			$term->name,
			$term->description
		);

		Post_Translator::invalidate_term_translations( $term_id );
		$this->schedule_term_translation( $term_id );
	}

	/**
	 * Queue translation when a post is first published.
	 *
	 * Covers draft, auto-draft, pending review, and scheduled (future) posts.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function handle_transition_post_status( $new_status, $old_status, $post ) {
		if ( self::$is_translating || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( 'publish' !== $new_status || ! in_array( $old_status, self::FIRST_PUBLISH_STATUSES, true ) ) {
			return;
		}

		if ( $this->should_skip_request( $post->ID, $post ) ) {
			return;
		}

		$this->schedule_translation( $post->ID );
	}

	/**
	 * Queue translation when a tracked custom meta value changes.
	 *
	 * @param mixed  $check      Short-circuit return value.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 * @param mixed  $prev_value Previous meta value.
	 * @return mixed
	 */
	public function detect_custom_meta_change( $check, $post_id, $meta_key, $meta_value, $prev_value ) {
		if ( null !== $check || self::$is_translating || Post_Translator::should_skip_translation_invalidation() ) {
			return $check;
		}

		if ( in_array( $meta_key, array( Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META, Post_Translator::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true ) ) {
			if ( $this->meta_values_equal( $meta_value, $prev_value ) ) {
				return $check;
			}

			$post = get_post( absint( $post_id ) );

			if ( ! $post instanceof \WP_Post || 'product_variation' !== $post->post_type ) {
				return $check;
			}

			Post_Translator::reconcile_variation_custom_meta_after_save( $post->ID, $meta_key, $prev_value );

			$parent_id = wp_get_post_parent_id( $post->ID );

			if ( $parent_id > 0 ) {
				$parent = get_post( $parent_id );

				if ( $parent instanceof \WP_Post && ! $this->should_skip_request( $parent_id, $parent ) ) {
					Post_Translator::invalidate_product_variation_title_runtime_cache( $parent_id );
					$this->schedule_translation( $parent_id );
				}
			}

			return $check;
		}

		if ( '_product_attributes' === $meta_key ) {
			if ( $this->meta_values_equal( $meta_value, $prev_value ) ) {
				return $check;
			}

			$post = get_post( absint( $post_id ) );

			if ( ! $post instanceof \WP_Post || $this->should_skip_request( $post_id, $post ) ) {
				return $check;
			}

			Post_Translator::invalidate_product_attribute_runtime_cache( $post_id );
			$this->schedule_translation( $post_id );

			return $check;
		}

		if ( '_elementor_data' === $meta_key ) {
			if ( $this->meta_values_equal( $meta_value, $prev_value ) ) {
				return $check;
			}

			$post = get_post( absint( $post_id ) );

			if ( ! $post instanceof \WP_Post || $this->should_skip_request( $post_id, $post ) ) {
				return $check;
			}

			Post_Translator::invalidate_elementor_translations( $post_id );
			$this->schedule_translation( $post_id );

			return $check;
		}

		if ( ! in_array( $meta_key, Post_Translator::CUSTOM_META_KEYS, true ) && ! Post_Translator::is_translatable_meta_key( $meta_key ) ) {
			return $check;
		}

		if ( $this->meta_values_equal( $meta_value, $prev_value ) ) {
			return $check;
		}

		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof \WP_Post || $this->should_skip_request( $post_id, $post ) ) {
			return $check;
		}

		// Never auto-delete companion translations when third-party meta changes.
		return $check;
	}

	/**
	 * Queue translation when a tracked custom meta key is first added.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function handle_added_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id );

		if ( self::$is_translating || Post_Translator::should_skip_translation_invalidation() ) {
			return;
		}

		if ( in_array( $meta_key, array( Post_Translator::WVE_VARIATION_CUSTOM_TITLE_META, Post_Translator::WVE_VARIATION_CUSTOM_DESCRIPTION_META ), true ) ) {
			$post = get_post( absint( $post_id ) );

			if ( ! $post instanceof \WP_Post || 'product_variation' !== $post->post_type ) {
				return;
			}

			Post_Translator::reconcile_variation_custom_meta_after_save( $post->ID, $meta_key );

			$parent_id = wp_get_post_parent_id( $post->ID );

			if ( $parent_id > 0 ) {
				$parent = get_post( $parent_id );

				if ( $parent instanceof \WP_Post && ! $this->should_skip_request( $parent_id, $parent ) ) {
					Post_Translator::invalidate_product_variation_title_runtime_cache( $parent_id );
					$this->schedule_translation( $parent_id );
				}
			}

			return;
		}

		if ( '_product_attributes' === $meta_key ) {
			$post = get_post( absint( $post_id ) );

			if ( ! $post instanceof \WP_Post || $this->should_skip_request( $post_id, $post ) ) {
				return;
			}

			Post_Translator::invalidate_product_attribute_runtime_cache( $post_id );
			$this->schedule_translation( $post_id );

			return;
		}

		if ( '_elementor_data' === $meta_key ) {
			$post = get_post( absint( $post_id ) );

			if ( ! $post instanceof \WP_Post || $this->should_skip_request( $post_id, $post ) ) {
				return;
			}

			Post_Translator::invalidate_elementor_translations( $post_id );
			$this->schedule_translation( $post_id );

			return;
		}

		if ( ! in_array( $meta_key, Post_Translator::CUSTOM_META_KEYS, true ) && ! Post_Translator::is_translatable_meta_key( $meta_key ) ) {
			return;
		}

		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof \WP_Post || $this->should_skip_request( $post_id, $post ) ) {
			return;
		}

		if ( Post_Translator::is_admin_translation_save_request() ) {
			return;
		}

		if ( ! Post_Translator::has_persian_content( $post_id ) ) {
			return;
		}

		$this->schedule_translation( $post_id );
	}

	/**
	 * Run the queued AI translation in the background.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function execute_background_translation( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || self::$is_translating ) {
			return;
		}

		// Auto-translate job owns posts — reschedule async work until it finishes.
		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $post_id ) );
			}

			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Post_Translator::get_supported_post_types(), true ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			return;
		}

		if ( ! Post_Translator::has_persian_content( $post_id ) ) {
			return;
		}

		$settings = Post_Translator::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: %d: post ID */
					__( 'ترجمه پس‌زمینه مورد #%d لغو شد — کلید API یا آدرس Gateway تنظیم نشده است.', 'polymart-ai' ),
					$post_id
				),
				array(
					'post_id' => $post_id,
					'source'  => 'async',
				)
			);

			return;
		}

		$languages = Language_Registry::get_translation_target_languages();

		if ( empty( $languages ) ) {
			Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: %d: post ID */
					__( 'ترجمه پس‌زمینه مورد #%d لغو شد — زبان مقصد فعالی یافت نشد.', 'polymart-ai' ),
					$post_id
				),
				array(
					'post_id' => $post_id,
					'source'  => 'async',
				)
			);

			return;
		}

		// Increase timeout for background cron jobs
		set_time_limit( 300 );

		self::$is_translating = true;

		try {
			foreach ( $languages as $language ) {
				$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' === $lang ) {
					continue;
				}

				$result = Post_Translator::request_ai_translation( $post_id, $lang );

				if ( is_wp_error( $result ) ) {
					Activity_Logger::log(
						'error',
						sprintf(
							/* translators: 1: post ID, 2: language code, 3: error message */
							__( 'ترجمه پس‌زمینه مورد #%1$d (%2$s) ناموفق بود: %3$s', 'polymart-ai' ),
							$post_id,
							$lang,
							$result->get_error_message()
						),
						array(
							'post_id' => $post_id,
							'lang'    => $lang,
							'source'  => 'async',
						)
					);
					continue;
				}

				Post_Translator::save_ai_translations( $post_id, $result['translations'], $lang );
				REST_API::invalidate_stats_cache();

				Activity_Logger::log(
					'success',
					sprintf(
						/* translators: 1: post title, 2: language label */
						__( 'ترجمه پس‌زمینه «%1$s» به %2$s ذخیره شد.', 'polymart-ai' ),
						get_the_title( $post_id ),
						$language['native_name'] ?? $lang
					),
					array(
						'post_id' => $post_id,
						'lang'    => $lang,
						'source'  => 'async',
					)
				);
			}
		} catch ( \Throwable $exception ) {
			Activity_Logger::log(
				'error',
				sprintf(
					/* translators: 1: post ID, 2: exception message */
					__( 'خطای غیرمنتظره در ترجمه پس‌زمینه مورد #%1$d: %2$s', 'polymart-ai' ),
					$post_id,
					$exception->getMessage()
				),
				array(
					'post_id' => $post_id,
					'source'  => 'async',
				)
			);
		} finally {
			self::$is_translating = false;
		}
	}

	/**
	 * Run the queued AI translation for one taxonomy term.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function execute_background_term_translation( $term_id ) {
		$term_id = absint( $term_id );

		if ( ! $term_id || self::$is_translating ) {
			return;
		}

		// Defer while auto-translate owns the AI budget; reschedule shortly after.
		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			if ( ! wp_next_scheduled( self::TERM_CRON_HOOK, array( $term_id ) ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::TERM_CRON_HOOK, array( $term_id ) );
			}

			return;
		}

		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term || ! Post_Translator::term_has_persian_content( $term_id ) ) {
			return;
		}

		$settings = Post_Translator::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: %d: term ID */
					__( 'ترجمه پس‌زمینه برچسب #%d لغو شد — کلید API یا آدرس Gateway تنظیم نشده است.', 'polymart-ai' ),
					$term_id
				),
				array(
					'term_id' => $term_id,
					'source'  => 'async',
				)
			);

			return;
		}

		$languages = Language_Registry::get_translation_target_languages();

		if ( empty( $languages ) ) {
			Activity_Logger::log(
				'warning',
				sprintf(
					/* translators: %d: term ID */
					__( 'ترجمه پس‌زمینه برچسب #%d لغو شد — زبان مقصد فعالی یافت نشد.', 'polymart-ai' ),
					$term_id
				),
				array(
					'term_id' => $term_id,
					'source'  => 'async',
				)
			);

			return;
		}

		// Increase timeout for background cron jobs
		set_time_limit( 300 );

		self::$is_translating = true;

		try {
			foreach ( $languages as $language ) {
				$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' === $lang ) {
					continue;
				}

				$result = Post_Translator::request_ai_term_translation( $term_id, $lang );

				if ( is_wp_error( $result ) ) {
					Activity_Logger::log(
						'error',
						sprintf(
							/* translators: 1: term ID, 2: language code, 3: error message */
							__( 'ترجمه پس‌زمینه برچسب #%1$d (%2$s) ناموفق بود: %3$s', 'polymart-ai' ),
							$term_id,
							$lang,
							$result->get_error_message()
						),
						array(
							'term_id' => $term_id,
							'lang'    => $lang,
							'source'  => 'async_term',
						)
					);
					continue;
				}

				Post_Translator::save_ai_term_translations( $term_id, $result['translations'], $lang );

				Activity_Logger::log(
					'success',
					sprintf(
						/* translators: 1: term name, 2: language label */
						__( 'ترجمه پس‌زمینه برچسب «%1$s» به %2$s ذخیره شد.', 'polymart-ai' ),
						$term->name,
						$language['native_name'] ?? $lang
					),
					array(
						'term_id' => $term_id,
						'lang'    => $lang,
						'source'  => 'async_term',
					)
				);
			}
		} catch ( \Throwable $exception ) {
			Activity_Logger::log(
				'error',
				sprintf(
					/* translators: 1: term ID, 2: exception message */
					__( 'خطای غیرمنتظره در ترجمه پس‌زمینه برچسب #%1$d: %2$s', 'polymart-ai' ),
					$term_id,
					$exception->getMessage()
				),
				array(
					'term_id' => $term_id,
					'source'  => 'async_term',
				)
			);
		} finally {
			self::$is_translating = false;
		}
	}

	/**
	 * Schedule a single deferred translation job for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function schedule_translation( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || self::$is_translating ) {
			return;
		}

		// Bulk auto-translate owns the queue — avoid racing the same product.
		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, array( $post_id ) );
		}

		self::maybe_spawn_cron();

		if ( defined( 'POLYMART_AI_SHUTDOWN_TRANSLATE' ) && POLYMART_AI_SHUTDOWN_TRANSLATE ) {
			self::queue_shutdown_translation( $post_id );
		}
	}

	/**
	 * Run queued admin saves immediately after the current request finishes.
	 *
	 * Local/dev sites often miss WP-Cron until the next page load; this keeps
	 * variation custom-title edits visible on /en/ right after saving.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	private static function queue_shutdown_translation( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 || self::$is_translating || ! is_admin() || wp_doing_cron() ) {
			return;
		}

		self::$shutdown_translation_queue[ $post_id ] = true;

		if ( self::$shutdown_hook_registered ) {
			return;
		}

		self::$shutdown_hook_registered = true;
		add_action( 'shutdown', array( __CLASS__, 'run_shutdown_translation_queue' ), 999 );
	}

	/**
	 * Execute any admin-queued background translations before the request ends.
	 *
	 * @return void
	 */
	public static function run_shutdown_translation_queue() {
		if ( empty( self::$shutdown_translation_queue ) || self::$is_translating ) {
			return;
		}

		$queue = array_keys( self::$shutdown_translation_queue );
		self::$shutdown_translation_queue = array();

		$async = new self();

		foreach ( $queue as $post_id ) {
			$async->execute_background_translation( absint( $post_id ) );
		}
	}

	/**
	 * Schedule a single deferred translation job for a taxonomy term.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	private function schedule_term_translation( $term_id ) {
		$term_id = absint( $term_id );

		if ( ! $term_id || self::$is_translating ) {
			return;
		}

		// Bulk auto-translate owns the AI budget — defer term work until it finishes.
		if ( \PolymartAI\Activity_Logger::is_bulk_job_running() ) {
			return;
		}

		if ( wp_next_scheduled( self::TERM_CRON_HOOK, array( $term_id ) ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::TERM_CRON_HOOK, array( $term_id ) );

		self::maybe_spawn_cron();
	}

	/**
	 * Trigger wp-cron unless the host disabled loopback cron spawning.
	 *
	 * @return void
	 */
	private static function maybe_spawn_cron() {
		if ( wp_doing_cron() || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return;
		}

		spawn_cron();
	}

	/**
	 * Static WP-Cron entry point for term translation jobs.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function execute_background_term_translation_static( $term_id ) {
		( new self() )->execute_background_term_translation( absint( $term_id ) );
	}

	/**
	 * Count scheduled WP-Cron events for one hook.
	 *
	 * @param string $hook Cron hook name.
	 * @return int
	 */
	public static function count_scheduled_events( $hook ) {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $cron as $events_by_hook ) {
			if ( ! is_array( $events_by_hook ) || ! isset( $events_by_hook[ $hook ] ) ) {
				continue;
			}

			$count += count( $events_by_hook[ $hook ] );
		}

		return $count;
	}

	/**
	 * Snapshot of Polymart background queue sizes for the admin dashboard.
	 *
	 * @return array<string, int|bool>
	 */
	public static function get_queue_status() {
		return array(
			'pending_strings'                 => Runtime_String_Translator::get_pending_queue_count(),
			'scheduled_posts'                 => self::count_scheduled_events( self::CRON_HOOK ),
			'scheduled_terms'                 => self::count_scheduled_events( self::TERM_CRON_HOOK ),
			'pending_string_worker_scheduled' => (bool) wp_next_scheduled( Runtime_String_Translator::PENDING_CRON_HOOK ),
		);
	}

	/**
	 * Process due Polymart background queue events (admin warm-up helper).
	 *
	 * @param int $max_pending_passes Maximum runtime-string batches per request.
	 * @return array<string, int>
	 */
	public static function process_due_queue_events( $max_pending_passes = 15 ) {
		$max_pending_passes = max( 1, min( 50, absint( $max_pending_passes ) ) );

		$processed = array(
			'pending_batches'             => 0,
			'posts'                       => 0,
			'terms'                       => 0,
			'pending_strings_remaining'   => 0,
		);

		for ( $pass = 0; $pass < $max_pending_passes; $pass++ ) {
			$before = Runtime_String_Translator::get_pending_queue_count();

			if ( $before <= 0 ) {
				break;
			}

			Runtime_String_Translator::process_pending_queue();
			++$processed['pending_batches'];

			$after = Runtime_String_Translator::get_pending_queue_count();

			if ( $after >= $before ) {
				break;
			}
		}

		$processed['pending_strings_remaining'] = Runtime_String_Translator::get_pending_queue_count();

		$cron  = _get_cron_array();
		$hooks = array(
			self::CRON_HOOK      => 'posts',
			self::TERM_CRON_HOOK => 'terms',
		);
		$async = new self();

		if ( ! is_array( $cron ) ) {
			return $processed;
		}

		for ( $round = 0; $round < 25; $round++ ) {
			$round_count = 0;

			foreach ( $cron as $timestamp => $hook_events ) {
				if ( (int) $timestamp > time() ) {
					continue;
				}

				foreach ( $hook_events as $hook => $events ) {
					if ( ! isset( $hooks[ $hook ] ) || ! is_array( $events ) ) {
						continue;
					}

					foreach ( $events as $event ) {
						$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();

						if ( self::CRON_HOOK === $hook ) {
							$async->execute_background_translation( isset( $args[0] ) ? (int) $args[0] : 0 );
						} else {
							$async->execute_background_term_translation( isset( $args[0] ) ? (int) $args[0] : 0 );
						}

						wp_unschedule_event( (int) $timestamp, $hook, $args );
						++$processed[ $hooks[ $hook ] ];
						++$round_count;
					}
				}
			}

			if ( $round_count <= 0 ) {
				break;
			}

			$cron = _get_cron_array();

			if ( ! is_array( $cron ) ) {
				break;
			}
		}

		return $processed;
	}

	/**
	 * Fast bail-out checks shared by all detection hooks.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 * @return bool
	 */
	private function should_skip_request( $post_id, $post ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return true;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Post_Translator::get_supported_post_types(), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether any core text field changed between two post revisions.
	 *
	 * @param \WP_Post $before Post before save.
	 * @param \WP_Post $after  Post after save.
	 * @return bool
	 */
	private function core_text_changed( \WP_Post $before, \WP_Post $after ) {
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $source_key ) {
			$before_source = Post_Translator::get_field_source_text( $before, $source_key );
			$after_source  = Post_Translator::get_field_source_text( $after, $source_key );

			if ( Post_Translator::field_source_text_meaningfully_changed( $before_source, $after_source ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare meta values without triggering extra database reads.
	 *
	 * Arrays and JSON/serialized strings are normalized before comparison so
	 * Elementor payloads and WooCommerce attribute arrays are not treated as
	 * the literal string "Array".
	 *
	 * @param mixed $next Next value.
	 * @param mixed $prev Previous value.
	 * @return bool
	 */
	private function meta_values_equal( $next, $prev ) {
		if ( $next === $prev ) {
			return true;
		}

		$next_norm = $this->normalize_meta_compare_value( $next );
		$prev_norm = $this->normalize_meta_compare_value( $prev );

		if ( $next_norm === $prev_norm ) {
			return true;
		}

		if ( is_array( $next_norm ) && is_array( $prev_norm ) ) {
			$next_json = wp_json_encode( $next_norm );
			$prev_json = wp_json_encode( $prev_norm );

			return is_string( $next_json ) && is_string( $prev_json ) && $next_json === $prev_json;
		}

		return (string) $next_norm === (string) $prev_norm;
	}

	/**
	 * Normalize a meta value into a comparable form (array or scalar).
	 *
	 * @param mixed $value Raw meta value.
	 * @return mixed
	 */
	private function normalize_meta_compare_value( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$decoded = json_decode( $value, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		$unserialized = maybe_unserialize( $value );

		if ( is_array( $unserialized ) ) {
			return $unserialized;
		}

		return $value;
	}
}
