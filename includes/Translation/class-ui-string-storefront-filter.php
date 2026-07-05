<?php
/**
 * Storefront-only filtering for bulk UI string scans.
 *
 * Keeps cart/checkout/account/header strings; drops wp-admin, settings, emails, reports.
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Class UI_String_Storefront_Filter
 */
final class UI_String_Storefront_Filter {

	/**
	 * WooCommerce paths that render on the public storefront.
	 *
	 * @return string[]
	 */
	public static function woocommerce_patterns() {
		$patterns = array(
			'#(^|/)templates/(?!emails/)#',
			'#includes/wc-template#',
			'#includes/wc-cart#',
			'#includes/wc-account#',
			'#includes/wc-notice#',
			'#includes/wc-coupon#',
			'#includes/wc-stock#',
			'#includes/wc-product-functions\.php#',
			'#includes/wc-formatting-functions\.php#',
			'#includes/wc-conditional-functions\.php#',
			'#includes/wc-term-functions\.php#',
			'#includes/class-wc-checkout\.php#',
			'#includes/class-wc-cart\.php#',
			'#includes/class-wc-form-handler\.php#',
			'#includes/class-wc-frontend-scripts\.php#',
			'#includes/class-wc-breadcrumb\.php#',
			'#includes/class-wc-session-handler\.php#',
			'#includes/class-wc-customer\.php#',
			'#includes/class-wc-order-search\.php#',
			'#includes/shortcodes/#',
			'#includes/widgets/#',
			'#includes/gateways/#',
			'#includes/shipping/#',
			'#includes/class-wc-shipping-zones\.php#',
			'#includes/class-wc-payment-gateways\.php#',
			'#includes/class-wc-tax\.php#',
			'#includes/walkers/#',
			'#assets/js/frontend/#',
			'#src/blocks/#',
			'#src/Blocks/#',
			'#patterns/#',
		);

		/**
		 * Filter WooCommerce catalog reference allowlist patterns.
		 *
		 * @param string[] $patterns Regex fragments.
		 */
		return (array) apply_filters( 'polymart_ai_ui_wc_storefront_patterns', $patterns );
	}

	/**
	 * Woodmart theme / woodmart-core storefront paths.
	 *
	 * @return string[]
	 */
	public static function woodmart_patterns() {
		$patterns = array(
			'#(^|/)woocommerce/#',
			'#inc/integrations/woocommerce/#',
			'#inc/modules/woocommerce/#',
			'#inc/modules/header-builder/#',
			'#inc/modules/sticky-toolbar/#',
			'#inc/modules/mobile-optimization/#',
			'#inc/modules/shop/#',
			'#inc/modules/layouts/#',
			'#inc/modules/wishlist/#',
			'#inc/modules/compare/#',
			'#inc/modules/mini-cart/#',
			'#inc/shortcodes/#',
			'#inc/template-tags/#',
			'#inc/widgets/#',
			'#template-parts/#',
			'#header-elements/#',
			'#footer-elements/#',
			'#css/parts/#',
		);

		/**
		 * Filter Woodmart catalog reference allowlist patterns.
		 *
		 * @param string[] $patterns Regex fragments.
		 */
		return (array) apply_filters( 'polymart_ai_ui_woodmart_storefront_patterns', $patterns );
	}

	/**
	 * Companion plugin paths that are usually customer-facing.
	 *
	 * @return string[]
	 */
	public static function companion_patterns() {
		$patterns = array(
			'#(^|/)(public|frontend|storefront|templates|template-parts|shortcodes|widgets|blocks)(/|$)#',
			'#(^|/)includes/(public|frontend|storefront|templates|shortcodes|widgets|blocks)(/|$)#',
			'#(^|/)src/(public|frontend|storefront|blocks|components)(/|$)#',
			'#(^|/)assets/(js|css)/frontend(/|$)#',
			'#(^|/)assets/js/(?!admin)(/|$)#',
			'#(^|/)elementor/widgets/#',
			'#(^|/)includes/elementor/#',
		);

		/**
		 * Filter companion-plugin storefront path patterns.
		 *
		 * @param string[] $patterns Regex fragments.
		 */
		return (array) apply_filters( 'polymart_ai_ui_companion_storefront_patterns', $patterns );
	}

	/**
	 * Subdirectories scanned for companion plugin PHP/JS sources.
	 *
	 * @return string[]
	 */
	public static function companion_source_subdirs() {
		$dirs = array(
			'public',
			'frontend',
			'storefront',
			'templates',
			'template-parts',
			'shortcodes',
			'widgets',
			'blocks',
			'includes/public',
			'includes/frontend',
			'includes/templates',
			'includes/shortcodes',
			'src/frontend',
			'src/public',
			'assets/js',
		);

		/**
		 * Filter companion plugin subdirectories scanned for UI strings.
		 *
		 * @param string[] $dirs Relative directory paths.
		 */
		return (array) apply_filters( 'polymart_ai_ui_companion_source_subdirs', $dirs );
	}

	/**
	 * Whether a gettext catalog file is admin-only and should be skipped entirely.
	 *
	 * @param string $path Catalog file path.
	 * @return bool
	 */
	public static function is_admin_catalog_file( $path ) {
		$name = strtolower( (string) basename( (string) $path ) );

		return (bool) preg_match( '/(?:^|-)admin(?:\.|-)/', $name );
	}

	/**
	 * Filter parsed entries down to likely storefront strings for one plugin/theme slug.
	 *
	 * @param array<int, array<string, mixed>> $entries Parsed entries.
	 * @param string                           $slug    Plugin or theme slug label.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_entries( array $entries, $slug ) {
		$slug   = strtolower( (string) $slug );
		$strict = self::uses_strict_allowlist( $slug );

		return array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $slug, $strict ) {
					if ( ! is_array( $entry ) ) {
						return false;
					}

					$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';

					if ( self::is_admin_context( $context ) ) {
						return false;
					}

					$msgid = isset( $entry['msgid'] ) ? (string) $entry['msgid'] : '';

					if ( strlen( $msgid ) > 500 ) {
						return false;
					}

					$references = isset( $entry['references'] ) && is_array( $entry['references'] )
						? $entry['references']
						: array();

					if ( empty( $references ) ) {
						return ! $strict;
					}

					foreach ( $references as $reference ) {
						if ( self::is_storefront_reference( (string) $reference, $slug ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
	}

	/**
	 * Whether this slug uses strict storefront allowlists (WooCommerce, Woodmart, theme).
	 *
	 * @param string $slug Plugin/theme slug.
	 * @return bool
	 */
	public static function uses_strict_allowlist( $slug ) {
		$slug = strtolower( (string) $slug );

		if ( 0 === strpos( $slug, 'theme:' ) ) {
			return true;
		}

		return in_array(
			$slug,
			array( 'woocommerce', 'woodmart-core', 'persian-woocommerce' ),
			true
		);
	}

	/**
	 * Whether a catalog/source reference is customer-facing for the given slug.
	 *
	 * @param string $reference File reference from .pot or source scan.
	 * @param string $slug      Plugin/theme slug.
	 * @return bool
	 */
	public static function is_storefront_reference( $reference, $slug ) {
		$reference = strtolower( wp_normalize_path( (string) $reference ) );
		$slug      = strtolower( (string) $slug );

		if ( '' === $reference || self::is_blocked_reference( $reference ) ) {
			return false;
		}

		$patterns = self::patterns_for_slug( $slug );

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $reference ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve allowlist patterns for a plugin/theme slug.
	 *
	 * @param string $slug Slug label.
	 * @return string[]
	 */
	private static function patterns_for_slug( $slug ) {
		if ( 'woocommerce' === $slug || 'persian-woocommerce' === $slug ) {
			return array_merge(
				self::woocommerce_patterns(),
				array(
					'#(^|/)includes/(public|frontend)(/|$)#',
				)
			);
		}

		if ( 'woodmart-core' === $slug || 0 === strpos( $slug, 'theme:' ) ) {
			return self::woodmart_patterns();
		}

		return self::companion_patterns();
	}

	/**
	 * Paths that are never storefront regardless of allowlist.
	 *
	 * @param string $reference Normalized reference path.
	 * @return bool
	 */
	private static function is_blocked_reference( $reference ) {
		return (bool) preg_match(
			'#(^|/)(admin|wp-admin|dashboard|settings|includes/admin|assets/client/admin|src/admin|assets/js/admin|packages|emails|email|legacy|sample-data|importers|export|import|cli|client|node_modules|vendor|build|dist|tests|test|reports|analytics|rest-api|webhooks|tools|status|logs|debug|onboarding|wizard|setup|license|updater|marketplace|internal|data-stores|abstracts|interfaces|queue|background|cron|tracks|telemetry|notes|meta-boxes|customizer/admin)(/|$)#',
			$reference
		);
	}

	/**
	 * Whether a gettext context indicates wp-admin / settings UI.
	 *
	 * @param string $context msgctxt value.
	 * @return bool
	 */
	private static function is_admin_context( $context ) {
		$context = strtolower( trim( (string) $context ) );

		if ( '' === $context ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(admin|dashboard|settings|meta box|bulk|screen|menu order|post type|taxonomy|email|editor|block editor|setup wizard|rest api|webhook|import|export|status|log|debug|internal|database|db table|cli|tool|system|permission|capability|network admin|plugin|theme update|license|onboarding|analytics|report|order note admin|product admin|coupon admin|shipping zone admin|payment gateway admin|attribute admin|webhook|system status|action scheduler)\b/',
			$context
		);
	}
}
