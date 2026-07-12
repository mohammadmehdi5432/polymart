<?php
/**
 * Woodmart theme and global surface translation hooks for English URLs.
 *
 * @package PolymartAI\Frontend
 */

namespace PolymartAI\Frontend;

use PolymartAI\Routing\Url_Router;
use PolymartAI\Translation\Layout_Guard;
use PolymartAI\Translation\Menu_Translator;
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Runtime_String_Translator;
use PolymartAI\Translation\Storefront_Gettext_Resolver;
use PolymartAI\Translation\UI_String_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Woodmart_Translator
 */
final class Woodmart_Translator {

	/**
	 * Option key for manually stored theme string translations.
	 */
	const THEME_STRINGS_OPTION = 'polymart_ai_theme_strings';

	/**
	 * Gettext domains used by Woodmart theme and companion plugin.
	 *
	 * @var string[]
	 */
	private static $gettext_domains = array(
		'woodmart',
		'xts-theme',
		'woodmart-core',
		'woocommerce',
	);

	/**
	 * Woodmart option slugs that commonly contain user-facing Persian text.
	 *
	 * @var string[]
	 */
	private static $woodmart_text_slugs = array(
		'copyrights',
		'copyrights2',
		'preloader_text',
		'promo_popup_text',
		'empty_cart_text',
		'empty_compare_text',
		'empty_wishlist_text',
		'popup_text',
		'popup_html_block',
		'header_banner_text',
		'sticky_navigation_area',
		'info_box_text',
		'info_box_title',
		'sticky_header_text',
		'categories_menu_title',
		'mobile_menu_label',
		'burger_menu_label',
		'header_contact_text',
		'header_additional_text',
		'footer_text',
		'footer_copyright',
		'footer_additional_text',
	);

	/**
	 * Cached companion option values for the current request.
	 *
	 * @var array<string, string>
	 */
	private static $option_value_cache = array();

	/**
	 * Cached intercept flag.
	 *
	 * @var bool|null
	 */
	private $intercept_cache = null;

	/**
	 * Re-entrancy guard for gettext filters.
	 *
	 * @var int
	 */
	private $gettext_depth = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woodmart_get_opt', array( $this, 'filter_woodmart_opt' ), 20, 2 );
		add_filter( 'xts_get_opt', array( $this, 'filter_woodmart_opt' ), 20, 2 );
		add_filter( 'widget_title', array( $this, 'filter_widget_title' ), 20, 1 );
		add_filter( 'widget_text', array( $this, 'filter_widget_text' ), 20, 1 );
		add_filter( 'nav_menu_item_title', array( $this, 'filter_nav_menu_item_title' ), 20, 4 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_wp_get_nav_menu_items' ), 20, 3 );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ), 20, 1 );
		add_filter( 'bloginfo', array( $this, 'filter_bloginfo' ), 20, 2 );
		add_filter( 'the_title', array( $this, 'filter_header_footer_title' ), 9, 2 );

		add_filter( 'gettext', array( $this, 'filter_gettext' ), 26, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 26, 4 );
		add_filter( 'ngettext', array( $this, 'filter_ngettext' ), 26, 5 );
	}

	/**
	 * Translate Woodmart theme option values on English URLs.
	 *
	 * @param mixed  $value Option value.
	 * @param string $slug  Option slug.
	 * @return mixed
	 */
	public function filter_woodmart_opt( $value, $slug ) {
		if ( ! $this->should_intercept() || ! is_string( $value ) || '' === trim( $value ) ) {
			return $value;
		}

		if ( ! in_array( $slug, self::$woodmart_text_slugs, true ) && ! Persian_Detector::contains_persian( $value ) ) {
			return $value;
		}

		return $this->resolve_string( $value, 'woodmart:' . $slug );
	}

	/**
	 * Translate footer/sidebar widget titles.
	 *
	 * @param string $title Widget title.
	 * @return string
	 */
	public function filter_widget_title( $title ) {
		if ( ! $this->should_intercept() || ! is_string( $title ) ) {
			return $title;
		}

		return $this->resolve_string( $title, 'widget_title:' . sanitize_title( $title ) );
	}

	/**
	 * Translate text widget bodies.
	 *
	 * @param string $text Widget content.
	 * @return string
	 */
	public function filter_widget_text( $text ) {
		if ( ! $this->should_intercept() || ! is_string( $text ) ) {
			return $text;
		}

		if ( ! Persian_Detector::contains_persian( $text ) ) {
			return $text;
		}

		return $this->resolve_string( $text, 'widget_text:' . md5( wp_strip_all_tags( $text ) ) );
	}

	/**
	 * Translate navigation menu item labels.
	 *
	 * @param string   $title     Menu item title.
	 * @param \WP_Post $menu_item Menu item object.
	 * @param object   $args      Menu args.
	 * @param int      $depth     Depth.
	 * @return string
	 */
	public function filter_nav_menu_item_title( $title, $menu_item, $args, $depth ) {
		unset( $args, $depth );

		if ( ! $this->should_intercept() || ! is_string( $title ) || ! $menu_item instanceof \WP_Post ) {
			return $title;
		}

		$lang = $this->get_active_lang();

		$resolved = Menu_Translator::resolve_storefront_menu_title( $menu_item, $lang, $title );

		if ( $resolved !== $title && '' !== trim( $resolved ) ) {
			return $resolved;
		}

		$stored = get_post_meta( $menu_item->ID, Post_Translator::get_menu_title_meta_key( $lang ), true );

		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return $stored;
		}

		return $this->resolve_string( $title, 'menu:' . $menu_item->ID );
	}

	/**
	 * Woodmart/mobile menus sometimes read item titles before nav_menu_item_title runs.
	 *
	 * @param \WP_Post[] $items Menu items.
	 * @param \WP_Term   $menu  Menu term.
	 * @param array      $args  Menu args.
	 * @return \WP_Post[]
	 */
	public function filter_wp_get_nav_menu_items( $items, $menu, $args ) {
		unset( $menu, $args );

		if ( ! $this->should_intercept() || ! is_array( $items ) ) {
			return $items;
		}

		$lang = $this->get_active_lang();

		foreach ( $items as $item ) {
			if ( ! $item instanceof \WP_Post ) {
				continue;
			}

			$title    = is_string( $item->title ?? null ) ? $item->title : (string) $item->post_title;
			$resolved = Menu_Translator::resolve_storefront_menu_title( $item, $lang, $title );

			if ( '' !== trim( $resolved ) && $resolved !== $title ) {
				$item->title      = $resolved;
				$item->post_title = $resolved;
			}
		}

		return $items;
	}

	/**
	 * Translate document title parts when stored.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function filter_document_title_parts( $parts ) {
		if ( ! $this->should_intercept() || ! is_array( $parts ) ) {
			return $parts;
		}

		foreach ( $parts as $key => $part ) {
			if ( is_string( $part ) ) {
				$parts[ $key ] = $this->resolve_string( $part, 'doc_title:' . $key );
			}
		}

		return $parts;
	}

	/**
	 * Translate site title and tagline on English URLs.
	 *
	 * @param string $output Output value.
	 * @param string $show   Bloginfo field.
	 * @return string
	 */
	public function filter_bloginfo( $output, $show ) {
		if ( ! $this->should_intercept() || ! is_string( $output ) ) {
			return $output;
		}

		if ( 'name' === $show ) {
			return $this->resolve_string( $output, 'blogname' );
		}

		if ( 'description' === $show ) {
			return $this->resolve_string( $output, 'blogdescription' );
		}

		return $output;
	}

	/**
	 * Translate Woodmart header/footer HTML block posts (cms_block, woodmart_layout).
	 *
	 * @param string $title   Post title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function filter_header_footer_title( $title, $post_id ) {
		if ( Layout_Guard::should_preserve_post_data( $post_id ) ) {
			return $title;
		}

		if ( ! $this->should_intercept() || ! $post_id ) {
			return $title;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return $title;
		}

		$layout_types = array( 'cms_block', 'woodmart_layout', 'wd_popup', 'page' );

		if ( ! in_array( $post->post_type, $layout_types, true ) ) {
			return $title;
		}

		if ( ! Post_Translator::should_serve_stored_translation( $post_id, 'post_title', $this->get_active_lang(), $post ) ) {
			return $title;
		}

		$translated = get_post_meta( $post_id, Post_Translator::get_meta_key( 'title', $this->get_active_lang() ), true );

		if ( is_string( $translated ) && '' !== trim( $translated ) ) {
			return $translated;
		}

		return $title;
	}

	/**
	 * Translate Woodmart theme gettext strings on translated URLs.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source msgid.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext( $translation, $text, $domain ) {
		return $this->filter_registry_gettext( $translation, $text, $domain, '' );
	}

	/**
	 * Translate contextual Woodmart gettext strings.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source msgid.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext_with_context( $translation, $text, $context, $domain ) {
		return $this->filter_registry_gettext(
			$translation,
			$text,
			$domain,
			is_string( $context ) ? $context : ''
		);
	}

	/**
	 * Translate plural Woodmart gettext strings.
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Count.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_ngettext( $translation, $single, $plural, $number, $domain ) {
		$form = ( 1 === (int) $number ) ? $single : $plural;

		return $this->filter_registry_gettext( $translation, $form, $domain, '' );
	}

	/**
	 * Shared Woodmart gettext lookup from the UI string registry / runtime cache.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source msgid.
	 * @param string $domain      Text domain.
	 * @param string $context     Optional context.
	 * @return string
	 */
	private function filter_registry_gettext( $translation, $text, $domain, $context ) {
		if ( $this->gettext_depth > 0 || ! $this->should_intercept() || ! in_array( $domain, self::$gettext_domains, true ) ) {
			return $translation;
		}

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $translation;
		}

		++$this->gettext_depth;

		try {
			return Storefront_Gettext_Resolver::resolve(
				$translation,
				$text,
				$context,
				$this->get_active_lang(),
				'woodmart_gettext:',
				'woodmart_gettext:' . md5( $context . '|' . $domain . '|' . $text )
			);
		} finally {
			--$this->gettext_depth;
		}
	}

	/**
	 * Resolve a Persian string to its English companion.
	 *
	 * @param string $value   Original value.
	 * @param string $storage Storage key.
	 * @return string
	 */
	private function resolve_string( $value, $storage ) {
		$strings = Runtime_String_Translator::get_theme_strings();

		if ( isset( $strings[ $storage ] ) && is_string( $strings[ $storage ] ) && '' !== trim( $strings[ $storage ] ) ) {
			return $strings[ $storage ];
		}

		$option_key = 'polymart_ai_opt_' . sanitize_key( $storage ) . '_' . $this->get_active_lang();

		if ( isset( self::$option_value_cache[ $option_key ] ) ) {
			return self::$option_value_cache[ $option_key ];
		}

		$stored = get_option( $option_key );

		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			self::$option_value_cache[ $option_key ] = $stored;

			return $stored;
		}

		if ( Persian_Detector::contains_persian( $value ) ) {
			$translated = Runtime_String_Translator::translate( $value, $this->get_active_lang(), $storage );
			self::$option_value_cache[ $option_key ] = $translated;

			return $translated;
		}

		/**
		 * Allow plugins to override theme string translation.
		 *
		 * @param string $value   Original Persian string.
		 * @param string $storage Storage key.
		 */
		$filtered = apply_filters( 'polymart_ai_translate_theme_string', $value, $storage );

		if ( is_string( $filtered ) && $filtered !== $value ) {
			return $filtered;
		}

		return $value;
	}

	/**
	 * Active translated language for the current request.
	 *
	 * @return string
	 */
	private function get_active_lang() {
		return Url_Router::get_current_language();
	}

	/**
	 * Whether theme surface filters should run.
	 *
	 * @return bool
	 */
	private function should_intercept() {
		if ( null !== $this->intercept_cache ) {
			return $this->intercept_cache;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			$this->intercept_cache = false;
			return false;
		}

		$this->intercept_cache = Url_Router::is_translated_request();

		return $this->intercept_cache;
	}
}
