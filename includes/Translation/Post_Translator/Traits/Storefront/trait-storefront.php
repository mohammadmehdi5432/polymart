<?php
/**
 * Post_Translator Storefront (auto-split).
 *
 * @package PolymartAI\Translation\Post_Translator\Traits
 */

namespace PolymartAI\Translation\Post_Translator\Traits\Storefront;

use PolymartAI\Translation\Post_Translator\Storefront_Resolver;


defined( 'ABSPATH' ) || exit;

trait Trait_Storefront {

	public static function supports_featured_image_translation( $post_type ) {
		return Storefront_Resolver::supports_featured_image_translation( $post_type );
	}

	public static function resolve_storefront_core_field( $post_id, $source_key, $lang, $post = null ) {
		return Storefront_Resolver::resolve_storefront_core_field( $post_id, $source_key, $lang, $post );
	}

	public static function resolve_storefront_title( $post_id, $lang, $allow_content_fallback = false ) {
		return Storefront_Resolver::resolve_storefront_title( $post_id, $lang, $allow_content_fallback );
	}

	public static function resolve_storefront_field( $post_id, $source_key, $lang, $post = null ) {
		return Storefront_Resolver::resolve_storefront_field( $post_id, $source_key, $lang, $post );
	}

	public static function peek_storefront_title( $post_id, $lang ) {
		return Storefront_Resolver::peek_storefront_title( $post_id, $lang );
	}

	public static function storefront_title_uses_content_fallback( $post_id, $lang ) {
		return Storefront_Resolver::storefront_title_uses_content_fallback( $post_id, $lang );
	}

	public static function get_storefront_companion_meta_key( $post_id, $source_key, $lang ) {
		return Storefront_Resolver::get_storefront_companion_meta_key( $post_id, $source_key, $lang );
	}

	public static function get_translated_thumbnail_id( $post_id, $lang ) {
		return Storefront_Resolver::get_translated_thumbnail_id( $post_id, $lang );
	}

	public static function ensure_translated_thumbnail_fallback( $post_id, $lang ) {
		return Storefront_Resolver::ensure_translated_thumbnail_fallback( $post_id, $lang );
	}

}
