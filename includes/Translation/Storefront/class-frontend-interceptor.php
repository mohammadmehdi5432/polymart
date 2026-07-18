<?php
/**
 * Frontend content interception for English URLs.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\Storefront;

use PolymartAI\Routing\Url_Router;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator;

use PolymartAI\Translation\WooCommerce\WooCommerce_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend_Interceptor
 */
final class Frontend_Interceptor {

	/**
	 * Cached result of should_intercept() for the current request.
	 *
	 * @var bool|null
	 */
	private $intercept_cache = null;

	/**
	 * Guard against re-entrancy while mutating queried posts.
	 *
	 * @var bool
	 */
	private static $filtering_posts = false;

	/**
	 * Guard against get_post_metadata recursion.
	 *
	 * should_serve_stored_translation() reads the Persian source via get_post_meta(),
	 * which re-enters this filter and previously exhausted memory on product pages.
	 *
	 * @var int
	 */
	private static $metadata_depth = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'the_title', array( $this, 'filter_the_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 10 );
		add_filter( 'the_excerpt', array( $this, 'filter_the_excerpt' ), 10 );
		add_filter( 'the_posts', array( $this, 'filter_queried_posts' ), 20, 2 );

		// Browser tab / SEO title — beat Rank Math & theme filters.
		add_filter( 'pre_get_document_title', array( $this, 'filter_pre_get_document_title' ), 9999 );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ), 9999 );
		add_filter( 'rank_math/frontend/title', array( $this, 'filter_rank_math_title' ), 9999 );

		/**
		 * Intercepts get_post_meta() for custom plugin keys.
		 *
		 * When the URL is /en/..., a request for `custom_card_subtitle` transparently
		 * returns `custom_card_subtitle_en` so third-party plugins need no changes.
		 */
		add_filter( 'get_post_metadata', array( $this, 'filter_post_metadata' ), 10, 4 );
		add_filter( 'post_thumbnail_id', array( $this, 'filter_post_thumbnail_id' ), 10, 2 );

		/**
		 * Intercepts third-party wp_options on English URLs.
		 *
		 * When a `{option}_en` companion exists, serve it instead of the Persian default.
		 */
		add_filter( 'option_apd_satisfaction_videos', array( $this, 'filter_apd_satisfaction_videos' ) );
		add_filter( 'option_customer_portal_survey_title', array( $this, 'filter_customer_portal_survey_title' ) );
		add_filter( 'option_customer_portal_survey_subtitle', array( $this, 'filter_customer_portal_survey_subtitle' ) );
		add_filter( 'option_customer_portal_survey_questions', array( $this, 'filter_customer_portal_survey_questions' ) );

		add_filter( 'the_content', array( $this, 'filter_layout_block_content' ), 9 );
		add_filter( 'woodmart_html_block_shortcode_content', array( $this, 'filter_layout_block_html' ), 10, 2 );
		add_filter( 'woodmart_html_block_content', array( $this, 'filter_layout_block_html' ), 10, 2 );
	}

	/**
	 * Replace the post title with its English translation.
	 *
	 * @param string $title   Original title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function filter_the_title( $title, $post_id ) {
		if ( Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $title;
		}

		if ( ! $this->should_intercept() || ! $post_id ) {
			return $title;
		}

		return $this->get_stored_core_translation( (int) $post_id, 'post_title', $title );
	}

	/**
	 * Replace `<title>` early when WordPress builds the document title.
	 *
	 * @param string $title Current document title.
	 * @return string
	 */
	public function filter_pre_get_document_title( $title ) {
		if ( ! $this->should_intercept() ) {
			return $title;
		}

		$translated = $this->resolve_queried_document_title();

		return '' !== $translated ? $translated : $title;
	}

	/**
	 * Replace the title part of document_title_parts (themes / SEO plugins).
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function filter_document_title_parts( $parts ) {
		if ( ! $this->should_intercept() || ! is_array( $parts ) ) {
			return $parts;
		}

		$translated = $this->resolve_queried_document_title();

		if ( '' !== $translated ) {
			$parts['title'] = $translated;
		}

		return $parts;
	}

	/**
	 * Rank Math frontend title override.
	 *
	 * @param string $title Rank Math title.
	 * @return string
	 */
	public function filter_rank_math_title( $title ) {
		if ( ! $this->should_intercept() ) {
			return $title;
		}

		$translated = $this->resolve_queried_document_title();

		return '' !== $translated ? $translated : $title;
	}

	/**
	 * Resolve storefront document/page title from PolyMart translation metas.
	 *
	 * Prefers Rank Math EN title when present, otherwise core title_en.
	 *
	 * @return string
	 */
	private function resolve_queried_document_title() {
		$post_id = get_queried_object_id();

		if ( $post_id <= 0 && is_front_page() ) {
			$post_id = absint( get_option( 'page_on_front' ) );
		}

		if ( $post_id <= 0 || Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return '';
		}

		$lang = $this->get_active_lang();

		$rank_math_key = Post_Translator::get_custom_meta_key( 'rank_math_title', $lang );
		$rank_math     = is_string( $rank_math_key ) ? trim( (string) get_post_meta( $post_id, $rank_math_key, true ) ) : '';

		// Discovered / translated Rank Math title metas may use _en suffix pattern directly.
		if ( '' === $rank_math ) {
			$rank_math = trim( (string) get_post_meta( $post_id, 'rank_math_title_' . $lang, true ) );
		}

		if ( '' !== $rank_math && Persian_Detector::is_acceptable_translation_for_language( $rank_math, $lang ) ) {
			return $rank_math;
		}

		$core = Post_Translator::resolve_storefront_core_field( $post_id, 'post_title', $lang );

		return is_string( $core ) ? trim( $core ) : '';
	}

	/**
	 * Replace post content with the English translation.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function filter_the_content( $content ) {
		$post_id = get_the_ID();

		if ( $post_id && Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $content;
		}

		if ( ! $this->should_intercept() ) {
			return $content;
		}

		if ( ! $post_id ) {
			return $content;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || Layout_Guard::is_storefront_body_exempt( $post ) ) {
			return $content;
		}

		return $this->get_stored_core_translation( $post_id, 'post_content', $content, $post );
	}

	/**
	 * Replace post excerpt with the English translation.
	 *
	 * @param string $excerpt Original excerpt.
	 * @return string
	 */
	public function filter_the_excerpt( $excerpt ) {
		$post_id = get_the_ID();

		if ( $post_id && Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $excerpt;
		}

		if ( ! $this->should_intercept() ) {
			return $excerpt;
		}

		if ( ! $post_id ) {
			return $excerpt;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || Layout_Guard::is_storefront_body_exempt( $post ) ) {
			return $excerpt;
		}

		return $this->get_stored_core_translation( $post_id, 'post_excerpt', $excerpt, $post );
	}

	/**
	 * Swap translated fields on posts returned by WP_Query (Elementor/Woodmart grids).
	 *
	 * @param \WP_Post[]     $posts Query results.
	 * @param \WP_Query|null $query Query instance.
	 * @return \WP_Post[]
	 */
	public function filter_queried_posts( $posts, $query ) {
		if ( $query instanceof \WP_Query && $query->is_singular( 'product' ) ) {
			return $posts;
		}

		if (
			$query instanceof \WP_Query
			&& $query->is_main_query()
			&& ! $this->is_product_listing_main_query( $query )
		) {
			return $posts;
		}

		if ( self::$filtering_posts || ! $this->should_intercept() || empty( $posts ) || ! is_array( $posts ) ) {
			return $posts;
		}

		self::$filtering_posts = true;

		foreach ( $posts as $index => $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( Layout_Guard::should_preserve_post_data( $post ) ) {
				continue;
			}

			$posts[ $index ] = $this->apply_translation_to_post_object( $post );
		}

		self::$filtering_posts = false;

		return $posts;
	}

	/**
	 * Apply translated title/content/excerpt onto a post object in memory.
	 *
	 * @param \WP_Post $post Post object.
	 * @return \WP_Post
	 */
	private function apply_translation_to_post_object( \WP_Post $post ) {
		if ( Layout_Guard::should_preserve_post_data( $post ) ) {
			return $post;
		}

		$lang       = $this->get_active_lang();
		$is_product = in_array( $post->post_type, array( 'product', 'product_variation' ), true );

		$title = Post_Translator::resolve_storefront_core_field( $post->ID, 'post_title', $lang, $post );

		if ( '' !== $title ) {
			$post->post_title = $title;
		}

		$excerpt = Post_Translator::resolve_storefront_core_field( $post->ID, 'post_excerpt', $lang, $post );

		if ( '' !== $excerpt ) {
			$post->post_excerpt = $excerpt;
		}

		// Product long descriptions are Elementor/WC-tab driven — skip post_content in grids.
		if ( ! $is_product && ! Layout_Guard::is_storefront_body_exempt( $post ) ) {
			$content = Post_Translator::resolve_storefront_core_field( $post->ID, 'post_content', $lang, $post );

			if ( '' !== $content ) {
				$post->post_content = $content;
			}
		}

		return $post;
	}

	/**
	 * Whether the main query is a WooCommerce product archive/listing.
	 *
	 * @param \WP_Query $query Query instance.
	 * @return bool
	 */
	private function is_product_listing_main_query( \WP_Query $query ) {
		$post_type = $query->get( 'post_type' );

		if ( 'product' === $post_type ) {
			return true;
		}

		if ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) {
			return true;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return true;
		}

		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'product' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Swap custom meta keys for their _en counterparts on English URLs.
	 *
	 * Third-party plugins call get_post_meta( $id, 'custom_card_subtitle', true )
	 * without knowing about our translation layer. Filtering get_post_metadata lets
	 * us serve English values before WordPress reads the database.
	 *
	 * @param mixed  $value     Short-circuit value (null = fetch from DB).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Requested meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public function filter_post_metadata( $value, $object_id, $meta_key, $single ) {
		// Nested get_post_meta() from should_serve / hash checks must hit the DB directly.
		if ( self::$metadata_depth > 0 ) {
			return $value;
		}

		if ( null !== $value ) {
			return $value;
		}

		if ( ! is_string( $meta_key ) || '' === $meta_key ) {
			return $value;
		}

		// Cheap rejects before any post/meta lookups.
		if ( 0 === strpos( $meta_key, '_elementor' ) || 0 === strpos( $meta_key, '_polymart_ai_' ) ) {
			return $value;
		}

		if ( ! $this->should_intercept() ) {
			return $value;
		}

		if ( Layout_Guard::should_preserve_post_data( $object_id ) ) {
			return $value;
		}

		++self::$metadata_depth;

		try {
			if ( '_thumbnail_id' === $meta_key ) {
				return $this->resolve_translated_meta_value(
					$object_id,
					Post_Translator::get_thumbnail_meta_key( $this->get_active_lang() ),
					$single,
					$value
				);
			}

			// Live storefront swaps are limited to the explicit companion-key list.
			// is_translatable_meta_key() is intentionally broad for AI scans and must
			// not short-circuit arbitrary public meta (Woodmart/Elementor config keys).
			if ( ! in_array( $meta_key, Post_Translator::CUSTOM_META_KEYS, true ) ) {
				return $value;
			}

			if ( ! Post_Translator::should_serve_stored_translation( $object_id, $meta_key, $this->get_active_lang() ) ) {
				return $value;
			}

			$translated_key = Post_Translator::get_storefront_companion_meta_key( $object_id, $meta_key, $this->get_active_lang() );

			if ( '' === $translated_key ) {
				return $value;
			}

			return $this->resolve_translated_meta_value( $object_id, $translated_key, $single, $value, $meta_key );
		} finally {
			--self::$metadata_depth;
		}
	}

	/**
	 * Swap the featured image ID on translated storefront requests.
	 *
	 * @param int|false $thumbnail_id Current thumbnail attachment ID.
	 * @param int|\WP_Post|null $post  Post ID or object.
	 * @return int|false
	 */
	public function filter_post_thumbnail_id( $thumbnail_id, $post ) {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : absint( $post );

		if ( Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $thumbnail_id;
		}

		if ( ! $this->should_intercept() ) {
			return $thumbnail_id;
		}

		if ( ! $post_id ) {
			return $thumbnail_id;
		}

		$post_type = get_post_type( $post_id );

		if ( ! Post_Translator::supports_featured_image_translation( $post_type ) ) {
			return $thumbnail_id;
		}

		$translated_id = Post_Translator::get_translated_thumbnail_id( $post_id, $this->get_active_lang() );

		return $translated_id > 0 ? $translated_id : $thumbnail_id;
	}

	/**
	 * Read a translated companion meta value without recursion.
	 *
	 * @param int    $object_id     Post ID.
	 * @param string $translated_key Companion meta key.
	 * @param bool   $single        Whether a single value was requested.
	 * @param mixed  $value         Original short-circuit value.
	 * @param string $source_key    Original requested meta key.
	 * @return mixed
	 */
	private function resolve_translated_meta_value( $object_id, $translated_key, $single, $value, $source_key = '' ) {
		remove_filter( 'get_post_metadata', array( $this, 'filter_post_metadata' ), 10 );

		if ( $single ) {
			$translated_value = get_post_meta( $object_id, $translated_key, true );
			add_filter( 'get_post_metadata', array( $this, 'filter_post_metadata' ), 10, 4 );

			if ( is_numeric( $translated_value ) && (int) $translated_value > 0 ) {
				return (string) (int) $translated_value;
			}

			if ( is_string( $translated_value ) && '' !== trim( $translated_value ) ) {
				return $translated_value;
			}

			if ( is_string( $source_key ) && '' !== $source_key ) {
				$resolved = Post_Translator::resolve_storefront_field( $object_id, $source_key, $this->get_active_lang() );

				if ( is_string( $resolved ) && '' !== trim( $resolved ) ) {
					return $resolved;
				}
			}

			return $value;
		}

		$translated_value = get_post_meta( $object_id, $translated_key, false );
		add_filter( 'get_post_metadata', array( $this, 'filter_post_metadata' ), 10, 4 );

		if ( ! empty( $translated_value ) ) {
			return $translated_value;
		}

		return $value;
	}

	/**
	 * Return the English APD satisfaction videos option when available.
	 *
	 * @param mixed $value Original option value from `apd_satisfaction_videos`.
	 * @return mixed
	 */
	public function filter_apd_satisfaction_videos( $value ) {
		if ( ! $this->should_intercept() ) {
			return $value;
		}

		$english = get_option( 'apd_satisfaction_videos_' . $this->get_active_lang() );

		if ( false === $english || null === $english || '' === $english ) {
			$english = get_option( 'apd_satisfaction_videos_en' );
		}

		if ( false === $english || null === $english || '' === $english ) {
			return $value;
		}

		if ( is_string( $english ) ) {
			$english = maybe_unserialize( $english );
		}

		if ( ! is_array( $english ) || empty( $english ) ) {
			return $value;
		}

		return $english;
	}

	/**
	 * Return the English Customer Portal survey title when available.
	 *
	 * @param mixed $value Original option value from `customer_portal_survey_title`.
	 * @return mixed
	 */
	public function filter_customer_portal_survey_title( $value ) {
		return $this->filter_translated_option( $value, 'customer_portal_survey_title' );
	}

	/**
	 * Return the English Customer Portal survey subtitle when available.
	 *
	 * @param mixed $value Original option value from `customer_portal_survey_subtitle`.
	 * @return mixed
	 */
	public function filter_customer_portal_survey_subtitle( $value ) {
		return $this->filter_translated_option( $value, 'customer_portal_survey_subtitle' );
	}

	/**
	 * Return the English Customer Portal survey questions when available.
	 *
	 * The `_en` value is returned exactly as stored (JSON string or serialized array).
	 * A Phase 3 UI will manage saving this option.
	 *
	 * @param mixed $value Original option value from `customer_portal_survey_questions`.
	 * @return mixed
	 */
	public function filter_customer_portal_survey_questions( $value ) {
		return $this->filter_translated_option( $value, 'customer_portal_survey_questions', true );
	}

	/**
	 * Swap a wp_option for its translated companion on non-default language URLs.
	 *
	 * @param mixed  $value       Original option value.
	 * @param string $option_base Base option key without language suffix.
	 * @param bool   $passthrough When true, return the translated value as stored without coercion.
	 * @return mixed
	 */
	private function filter_translated_option( $value, $option_base, $passthrough = false ) {
		if ( ! $this->should_intercept() ) {
			return $value;
		}

		$translated_key = $option_base . '_' . $this->get_active_lang();
		$translated     = get_option( $translated_key );

		if ( false === $translated && 'en' !== $this->get_active_lang() ) {
			$translated = get_option( $option_base . '_en' );
		}

		if ( false === $translated ) {
			return $value;
		}

		if ( $passthrough ) {
			if ( null === $translated || '' === $translated || ( is_array( $translated ) && empty( $translated ) ) ) {
				return $value;
			}

			return $translated;
		}

		if ( is_string( $translated ) && '' !== trim( $translated ) ) {
			return $translated;
		}

		return $value;
	}

	/**
	 * Swap a wp_option for its `_en` companion on English URLs.
	 *
	 * @param mixed  $value              Original option value.
	 * @param string $english_option_key Companion option key (e.g. `foo_en`).
	 * @param bool   $passthrough        When true, return the English value as stored without coercion.
	 * @return mixed
	 * @deprecated Use filter_translated_option().
	 */
	private function filter_english_option( $value, $english_option_key, $passthrough = false ) {
		if ( ! $this->should_intercept() ) {
			return $value;
		}

		$english = get_option( $english_option_key );

		if ( false === $english ) {
			return $value;
		}

		if ( $passthrough ) {
			if ( null === $english || '' === $english || ( is_array( $english ) && empty( $english ) ) ) {
				return $value;
			}

			return $english;
		}

		if ( is_string( $english ) && '' !== trim( $english ) ) {
			return $english;
		}

		return $value;
	}

	/**
	 * Replace Woodmart/HTML block post content with English meta when available.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function filter_layout_block_content( $content ) {
		if ( ! $this->should_intercept() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $content;
		}

		return $this->get_stored_core_translation( $post_id, 'post_content', $content );
	}

	/**
	 * Replace inline Woodmart HTML block shortcode output.
	 *
	 * @param string   $content  Block HTML.
	 * @param int|null $block_id Optional cms_block post ID when provided by Woodmart.
	 * @return string
	 */
	public function filter_layout_block_html( $content, $block_id = null ) {
		if ( ! $this->should_intercept() || ! is_string( $content ) ) {
			return $content;
		}

		$block_id = absint( $block_id );

		if ( $block_id <= 0 ) {
			$block_id = $this->guess_layout_block_id_from_html( $content );
		}

		if ( $block_id > 0 ) {
			$resolved = $this->resolve_cms_block_html( $block_id, $content );

			if ( is_string( $resolved ) && $resolved !== $content ) {
				return $resolved;
			}
		}

		if ( ! Persian_Detector::contains_persian( $content ) ) {
			return $content;
		}

		$cached = $this->lookup_layout_block_runtime_translation( $content );

		if ( null !== $cached ) {
			return $cached;
		}

		return Runtime_String_Translator::translate(
			$content,
			$this->get_active_lang(),
			'html_block:' . md5( wp_strip_all_tags( $content ) )
		);
	}

	/**
	 * Resolve translated HTML for a cms_block when Woodmart passes the block ID.
	 *
	 * @param int    $block_id Block post ID.
	 * @param string $content  Fallback rendered HTML.
	 * @return string|null
	 */
	private function resolve_cms_block_html( $block_id, $content ) {
		$post = get_post( $block_id );

		if ( ! $post instanceof \WP_Post || 'cms_block' !== $post->post_type ) {
			return null;
		}

		$lang = $this->get_active_lang();

		if ( Post_Translator::can_serve_stored_elementor_json_on_storefront( $block_id, $lang, 'embedded' ) ) {
			// Elementor JSON swap handles rendering; fall back to runtime/hash for Persian leftovers.
			if ( ! Persian_Detector::contains_persian( $content ) ) {
				return $content;
			}

			return $this->lookup_layout_block_runtime_translation( $content )
				?? Runtime_String_Translator::translate(
					$content,
					$lang,
					'html_block:' . $block_id . ':' . md5( wp_strip_all_tags( $content ) )
				);
		}

		if ( Post_Translator::should_serve_stored_translation( $block_id, 'post_content', $lang, $post ) ) {
			$translated = get_post_meta( $block_id, Post_Translator::get_meta_key( 'content', $lang ), true );

			if ( is_string( $translated ) && '' !== trim( $translated ) ) {
				return $translated;
			}
		}

		return null;
	}

	/**
	 * Look up a pre-cached HTML block translation by rendered content hash.
	 *
	 * @param string $content Rendered HTML.
	 * @return string|null
	 */
	private function lookup_layout_block_runtime_translation( $content ) {
		$hash    = md5( wp_strip_all_tags( $content ) );
		$strings = Runtime_String_Translator::get_theme_strings();
		$key     = 'html_block:' . $hash;

		if ( is_array( $strings ) && ! empty( $strings[ $key ] ) && is_string( $strings[ $key ] ) ) {
			return $strings[ $key ];
		}

		$cached = Runtime_String_Translator::lookup_cached(
			wp_strip_all_tags( $content ),
			$this->get_active_lang(),
			$key
		);

		return is_string( $cached ) && '' !== trim( $cached ) ? $cached : null;
	}

	/**
	 * Try to recover a cms_block post ID from Woodmart wrapper markup.
	 *
	 * @param string $content Rendered HTML.
	 * @return int
	 */
	private function guess_layout_block_id_from_html( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return 0;
		}

		$patterns = array(
			'/data-block-id=["\'](\d+)["\']/i',
			'/data-id=["\'](\d+)["\']/i',
			'/class=["\'][^"\']*cms-block-(\d+)/i',
			'/class=["\'][^"\']*html-block-(\d+)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content, $matches ) ) {
				$block_id = absint( $matches[1] );

				if ( $block_id > 0 && 'cms_block' === get_post_type( $block_id ) ) {
					return $block_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Determine whether frontend filters should swap in English content.
	 *
	 * @return bool
	 */
	private function should_intercept() {
		// APD snapshot mode is transient — never memoize a false result across it.
		if ( class_exists( WooCommerce_Translator::class ) && WooCommerce_Translator::is_apd_snapshot_active() ) {
			return false;
		}

		if ( null !== $this->intercept_cache ) {
			return $this->intercept_cache;
		}

		// admin-ajax requests never fire `wp`; language is resolved from the referer.
		if ( ! did_action( 'wp' ) && ! wp_doing_ajax() ) {
			$this->intercept_cache = false;
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			$this->intercept_cache = false;
			return false;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			$this->intercept_cache = false;
			return false;
		}

		$this->intercept_cache = Url_Router::is_translated_request();

		return $this->intercept_cache;
	}

	/**
	 * Active non-default language code for the current request.
	 *
	 * @return string
	 */
	private function get_active_lang() {
		return Url_Router::get_current_language();
	}

	/**
	 * Return a stored core-field translation only when it still matches the Persian source.
	 *
	 * @param int           $post_id    Post ID.
	 * @param string        $source_key Source field identifier.
	 * @param string        $original   Original storefront value.
	 * @param \WP_Post|null $post       Optional post object.
	 * @return string
	 */
	private function get_stored_core_translation( $post_id, $source_key, $original, $post = null ) {
		$lang = $this->get_active_lang();

		$translated = Post_Translator::resolve_storefront_core_field( $post_id, $source_key, $lang, $post );

		return '' !== $translated ? $translated : $original;
	}
}
