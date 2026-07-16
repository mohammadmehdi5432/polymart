<?php
/**
 * Configurable language registry (flags, URL prefixes, directions).
 *
 * @package PolymartAI
 */

namespace PolymartAI;

defined( 'ABSPATH' ) || exit;

/**
 * Class Language_Registry
 */
final class Language_Registry {

	/**
	 * Option key for stored languages.
	 */
	const OPTION_KEY = 'polymart_ai_languages';

	/**
	 * Cached language list for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static $languages_cache = null;

	/**
	 * Guard against recursive get_option() while languages are loading.
	 *
	 * @var bool
	 */
	private static $loading_languages = false;

	/**
	 * Bootstrap default languages on first run.
	 *
	 * @return void
	 */
	public static function maybe_seed_defaults() {
		$stored = get_option( self::OPTION_KEY, null );

		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return;
		}

		update_option( self::OPTION_KEY, self::get_default_languages() );
	}

	/**
	 * Default language configuration (Persian + English).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_default_languages() {
		return array(
			self::normalize_language(
				array(
					'code'                 => 'fa',
					'name'                 => __( 'فارسی', 'polymart-ai' ),
					'native_name'          => 'فارسی',
					'url_prefix'           => '',
					'direction'            => 'rtl',
					'is_default'           => true,
					'enabled'              => true,
					'flag_attachment_id'   => 0,
					'cpc_currency_icon_attachment_id' => 0,
					'product_placeholder_attachment_id' => 0,
				)
			),
			self::normalize_language(
				array(
					'code'                 => 'en',
					'name'                 => __( 'انگلیسی', 'polymart-ai' ),
					'native_name'          => 'English',
					'url_prefix'           => 'en',
					'direction'            => 'ltr',
					'is_default'           => false,
					'enabled'              => true,
					'flag_attachment_id'   => 0,
					'cpc_currency_icon_attachment_id' => 0,
					'product_placeholder_attachment_id' => 0,
				)
			),
		);
	}

	/**
	 * Preset languages users can add from the admin UI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_presets() {
		$presets = array(
			array( 'code' => 'ar', 'name' => __( 'عربی', 'polymart-ai' ), 'native_name' => 'العربية', 'url_prefix' => 'ar', 'direction' => 'rtl' ),
			array( 'code' => 'zh', 'name' => __( 'چینی', 'polymart-ai' ), 'native_name' => '中文', 'url_prefix' => 'zh', 'direction' => 'ltr' ),
			array( 'code' => 'tr', 'name' => __( 'ترکی', 'polymart-ai' ), 'native_name' => 'Türkçe', 'url_prefix' => 'tr', 'direction' => 'ltr' ),
			array( 'code' => 'de', 'name' => __( 'آلمانی', 'polymart-ai' ), 'native_name' => 'Deutsch', 'url_prefix' => 'de', 'direction' => 'ltr' ),
			array( 'code' => 'fr', 'name' => __( 'فرانسوی', 'polymart-ai' ), 'native_name' => 'Français', 'url_prefix' => 'fr', 'direction' => 'ltr' ),
			array( 'code' => 'es', 'name' => __( 'اسپانیایی', 'polymart-ai' ), 'native_name' => 'Español', 'url_prefix' => 'es', 'direction' => 'ltr' ),
			array( 'code' => 'ru', 'name' => __( 'روسی', 'polymart-ai' ), 'native_name' => 'Русский', 'url_prefix' => 'ru', 'direction' => 'ltr' ),
			array( 'code' => 'ja', 'name' => __( 'ژاپنی', 'polymart-ai' ), 'native_name' => '日本語', 'url_prefix' => 'ja', 'direction' => 'ltr' ),
			array( 'code' => 'ko', 'name' => __( 'کره‌ای', 'polymart-ai' ), 'native_name' => '한국어', 'url_prefix' => 'ko', 'direction' => 'ltr' ),
			array( 'code' => 'it', 'name' => __( 'ایتالیایی', 'polymart-ai' ), 'native_name' => 'Italiano', 'url_prefix' => 'it', 'direction' => 'ltr' ),
			array( 'code' => 'pt', 'name' => __( 'پرتغالی', 'polymart-ai' ), 'native_name' => 'Português', 'url_prefix' => 'pt', 'direction' => 'ltr' ),
			array( 'code' => 'hi', 'name' => __( 'هندی', 'polymart-ai' ), 'native_name' => 'हिन्दी', 'url_prefix' => 'hi', 'direction' => 'ltr' ),
		);

		return array_map(
			static function ( $preset ) {
				$preset['is_default']         = false;
				$preset['enabled']            = false;
				$preset['flag_attachment_id'] = 0;
				$preset['cpc_currency_icon_attachment_id'] = 0;
				$preset['product_placeholder_attachment_id'] = 0;

				return self::normalize_language( $preset );
			},
			$presets
		);
	}

	/**
	 * All configured languages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_languages() {
		if ( null !== self::$languages_cache ) {
			return self::$languages_cache;
		}

		if ( self::$loading_languages ) {
			return self::get_default_languages();
		}

		self::$loading_languages = true;

		self::maybe_seed_defaults();

		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			self::$languages_cache = self::get_default_languages();
			self::$loading_languages = false;

			return self::$languages_cache;
		}

		$languages = array();

		foreach ( $stored as $language ) {
			if ( is_array( $language ) ) {
				$languages[] = self::normalize_language( $language );
			}
		}

		self::$languages_cache = ! empty( $languages ) ? $languages : self::get_default_languages();
		self::$loading_languages = false;

		return self::$languages_cache;
	}

	/**
	 * Enabled languages for the frontend switcher.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_enabled_languages() {
		return array_values(
			array_filter(
				self::get_languages(),
				static function ( $language ) {
					return ! empty( $language['enabled'] );
				}
			)
		);
	}

	/**
	 * Enabled non-default languages that receive translations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_translation_target_languages() {
		return array_values(
			array_filter(
				self::get_enabled_languages(),
				static function ( $language ) {
					return empty( $language['is_default'] );
				}
			)
		);
	}

	/**
	 * Enabled languages that use a URL prefix (non-default).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_routed_languages() {
		return array_values(
			array_filter(
				self::get_enabled_languages(),
				static function ( $language ) {
					return '' !== $language['url_prefix'];
				}
			)
		);
	}

	/**
	 * Default (source) language code.
	 *
	 * @return string
	 */
	public static function get_default_language_code() {
		foreach ( self::get_languages() as $language ) {
			if ( ! empty( $language['is_default'] ) ) {
				return (string) $language['code'];
			}
		}

		return 'fa';
	}

	/**
	 * Find a language by code.
	 *
	 * @param string $code Language code.
	 * @return array<string, mixed>|null
	 */
	public static function get_language( $code ) {
		$code = sanitize_key( (string) $code );

		foreach ( self::get_languages() as $language ) {
			if ( $language['code'] === $code ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Map a plugin language code to a full WordPress locale.
	 *
	 * Used to switch the request locale on translated URLs so WooCommerce,
	 * Woodmart, and core load their own translations (or English source
	 * strings) instead of the default-language .mo files.
	 *
	 * @param string $code Language code.
	 * @return string WordPress locale, or empty string when unknown.
	 */
	public static function get_locale_for_language( $code ) {
		$code = sanitize_key( (string) $code );

		$map = array(
			'fa' => 'fa_IR',
			'en' => 'en_US',
			'ar' => 'ar',
			'zh' => 'zh_CN',
			'tr' => 'tr_TR',
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'es' => 'es_ES',
			'ru' => 'ru_RU',
			'ja' => 'ja',
			'ko' => 'ko_KR',
			'it' => 'it_IT',
			'pt' => 'pt_PT',
			'hi' => 'hi_IN',
		);

		$locale = $map[ $code ] ?? '';

		/**
		 * Filter the WordPress locale used for a PolyMart language code.
		 *
		 * @param string $locale WordPress locale (e.g. en_US) or empty string.
		 * @param string $code   PolyMart language code.
		 */
		return (string) apply_filters( 'polymart_ai_language_locale', $locale, $code );
	}

	/**
	 * BCP47 hreflang value for a language code (e.g. en-us, fa-ir).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function get_hreflang_code( $code ) {
		$locale = self::get_locale_for_language( $code );

		if ( '' === $locale ) {
			return sanitize_key( (string) $code );
		}

		$hreflang = strtolower( str_replace( '_', '-', $locale ) );

		/**
		 * Filter the hreflang attribute value for a PolyMart language code.
		 *
		 * @param string $hreflang BCP47-style language tag.
		 * @param string $code     PolyMart language code.
		 * @param string $locale   WordPress locale.
		 */
		return (string) apply_filters( 'polymart_ai_hreflang_code', $hreflang, $code, $locale );
	}

	/**
	 * Validate an incoming language list before save.
	 *
	 * @param array<int, array<string, mixed>> $languages Raw languages.
	 * @return true|\WP_Error
	 */
	public static function validate_languages( array $languages ) {
		$codes    = array();
		$prefixes = array();

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) ) {
				continue;
			}

			$normalized = self::normalize_language( $language );
			$code       = (string) $normalized['code'];
			$prefix     = (string) $normalized['url_prefix'];

			if ( '' === $code ) {
				continue;
			}

			if ( in_array( $code, $codes, true ) ) {
				return new \WP_Error(
					'polymart_ai_duplicate_language_code',
					sprintf(
						/* translators: %s: language code */
						__( 'کد زبان «%s» تکراری است. هر زبان باید کد یکتا داشته باشد.', 'polymart-ai' ),
						$code
					)
				);
			}

			$codes[] = $code;

			if ( '' === $prefix || ! empty( $normalized['is_default'] ) ) {
				continue;
			}

			if ( in_array( $prefix, $prefixes, true ) ) {
				return new \WP_Error(
					'polymart_ai_duplicate_url_prefix',
					sprintf(
						/* translators: %s: URL prefix */
						__( 'پیش‌وند URL «%s» برای بیش از یک زبان استفاده شده. هر زبان غیرپیش‌فرض باید پیش‌وند یکتا داشته باشد.', 'polymart-ai' ),
						$prefix
					)
				);
			}

			$prefixes[] = $prefix;
		}

		return true;
	}

	/**
	 * Human-readable language name for AI prompts.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function get_language_label_for_ai( $code ) {
		$language = self::get_language( $code );

		if ( ! $language ) {
			return $code;
		}

		$native = trim( (string) $language['native_name'] );
		$name   = trim( (string) $language['name'] );

		if ( '' !== $native && $native !== $name ) {
			return $native . ' (' . $name . ')';
		}

		return '' !== $native ? $native : $name;
	}

	/**
	 * Save languages and flush rewrite rules when prefixes change.
	 *
	 * @param array<int, array<string, mixed>> $languages Incoming languages.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_languages( array $languages ) {
		$old_prefixes = self::collect_prefixes( self::get_languages() );
		$sanitized    = self::sanitize_languages( $languages );

		update_option( self::OPTION_KEY, $sanitized );
		self::$languages_cache = $sanitized;

		$new_prefixes = self::collect_prefixes( $sanitized );

		if ( $old_prefixes !== $new_prefixes ) {
			// Re-register rules for the just-saved prefixes, then flush.
			// Bare flush_rewrite_rules() would persist only rules from init
			// (missing newly enabled prefixes like /ar/).
			\PolymartAI\Routing\Url_Router::refresh_rewrite_rules();
		}

		return self::format_for_api( $sanitized );
	}

	/**
	 * Currency icon URL for CPC product cards.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function get_cpc_currency_icon_url( $code ) {
		$language = self::get_language( $code );

		if ( ! $language ) {
			return '';
		}

		$attachment_id = absint( $language['cpc_currency_icon_attachment_id'] ?? 0 );

		if ( 0 === $attachment_id ) {
			$attachment_id = absint( $language['currency_icon_attachment_id'] ?? 0 );
		}

		if ( $attachment_id > 0 ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );

			return $url ? esc_url_raw( $url ) : '';
		}

		if ( $code === self::get_default_language_code() ) {
			return self::get_legacy_default_currency_icon_url( $code );
		}

		return '';
	}

	/**
	 * CPC currency icon URL for the active storefront language.
	 *
	 * @return string
	 */
	public static function get_cpc_currency_icon_url_for_current_language() {
		if ( ! class_exists( '\PolymartAI\Routing\Url_Router' ) ) {
			return self::get_cpc_currency_icon_url( self::get_default_language_code() );
		}

		return self::get_cpc_currency_icon_url( \PolymartAI\Routing\Url_Router::get_current_language() );
	}

	/**
	 * Currency icon URL for a language (CPC — backward compatible alias).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function get_currency_icon_url( $code ) {
		return self::get_cpc_currency_icon_url( $code );
	}

	/**
	 * Legacy fallback icon for Persian until an attachment is uploaded in Languages admin.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private static function get_legacy_default_currency_icon_url( $code ) {
		$default = 'https://knddecor.com/wp-content/uploads/2025/09/Toman-1.png';

		/**
		 * Filter legacy fallback currency icon for the default (Persian) language.
		 *
		 * @param string $url  Legacy icon URL.
		 * @param string $code Language code.
		 */
		return (string) apply_filters( 'polymart_ai_legacy_currency_icon_url', $default, $code );
	}

	/**
	 * Currency icon URL for the active storefront language.
	 *
	 * @return string
	 */
	/**
	 * Currency icon URL for the active storefront language (CPC).
	 *
	 * @return string
	 */
	public static function get_currency_icon_url_for_current_language() {
		return self::get_cpc_currency_icon_url_for_current_language();
	}

	/**
	 * Placeholder image URL for products without a featured image.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function get_product_placeholder_url( $code ) {
		$language = self::get_language( $code );

		if ( ! $language ) {
			return '';
		}

		$attachment_id = absint( $language['product_placeholder_attachment_id'] ?? 0 );

		if ( $attachment_id > 0 ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );

			return $url ? esc_url_raw( $url ) : '';
		}

		if ( $code === self::get_default_language_code() ) {
			return self::get_legacy_default_product_placeholder_url( $code );
		}

		return '';
	}

	/**
	 * Product placeholder URL for the active storefront language.
	 *
	 * @return string
	 */
	public static function get_product_placeholder_url_for_current_language() {
		if ( ! class_exists( '\PolymartAI\Routing\Url_Router' ) ) {
			return self::get_product_placeholder_url( self::get_default_language_code() );
		}

		return self::get_product_placeholder_url( \PolymartAI\Routing\Url_Router::get_current_language() );
	}

	/**
	 * Legacy fallback placeholder for the default language until an attachment is set.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private static function get_legacy_default_product_placeholder_url( $code ) {
		$default = 'https://knddecor.com/wp-content/uploads/2025/08/non.png';

		/**
		 * Filter legacy fallback product placeholder for the default language.
		 *
		 * @param string $url  Legacy placeholder URL.
		 * @param string $code Language code.
		 */
		return (string) apply_filters( 'polymart_ai_legacy_product_placeholder_url', $default, $code );
	}

	/**
	 * Whether the given language should use Persian digits in CPC UI.
	 *
	 * @param string|null $code Optional language code; current request when null.
	 * @return bool
	 */
	public static function uses_persian_digits( $code = null ) {
		if ( null === $code ) {
			if ( class_exists( '\PolymartAI\Routing\Url_Router' ) ) {
				$code = \PolymartAI\Routing\Url_Router::get_current_language();
			} else {
				$code = self::get_default_language_code();
			}
		}

		return sanitize_key( (string) $code ) === self::get_default_language_code();
	}

	/**
	 * Format languages for REST / React (with flag URLs).
	 *
	 * @param array<int, array<string, mixed>>|null $languages Optional language list.
	 * @return array<int, array<string, mixed>>
	 */
	public static function format_for_api( $languages = null ) {
		if ( null === $languages ) {
			$languages = self::get_languages();
		}

		$formatted = array();

		foreach ( $languages as $language ) {
			$flag_id  = (int) ( $language['flag_attachment_id'] ?? 0 );
			$flag_url = $flag_id > 0 ? wp_get_attachment_image_url( $flag_id, 'thumbnail' ) : '';
			$cpc_icon_id = absint( $language['cpc_currency_icon_attachment_id'] ?? 0 );

			if ( 0 === $cpc_icon_id ) {
				$cpc_icon_id = absint( $language['currency_icon_attachment_id'] ?? 0 );
			}

			$cpc_icon_url = $cpc_icon_id > 0 ? wp_get_attachment_image_url( $cpc_icon_id, 'thumbnail' ) : '';
			$placeholder_id  = absint( $language['product_placeholder_attachment_id'] ?? 0 );
			$placeholder_url = $placeholder_id > 0 ? wp_get_attachment_image_url( $placeholder_id, 'thumbnail' ) : '';

			$formatted[] = array(
				'code'               => $language['code'],
				'name'               => $language['name'],
				'native_name'        => $language['native_name'],
				'url_prefix'         => $language['url_prefix'],
				'direction'          => $language['direction'],
				'is_default'         => (bool) $language['is_default'],
				'enabled'            => (bool) $language['enabled'],
				'flag_attachment_id' => $flag_id,
				'flag_url'           => $flag_url ? esc_url_raw( $flag_url ) : '',
				'cpc_currency_icon_attachment_id' => $cpc_icon_id,
				'cpc_currency_icon_url'  => $cpc_icon_url ? esc_url_raw( $cpc_icon_url ) : '',
				'product_placeholder_attachment_id' => $placeholder_id,
				'product_placeholder_url' => $placeholder_url ? esc_url_raw( $placeholder_url ) : '',
				'url'                => self::get_language_home_url( $language['code'] ),
			);
		}

		return $formatted;
	}

	/**
	 * Build the home URL for a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function get_language_home_url( $code ) {
		$language = self::get_language( $code );
		$home     = \PolymartAI\Routing\Url_Router::get_site_home_url();

		if ( '' === $home ) {
			$home = untrailingslashit( (string) home_url( '/' ) );
		}

		if ( ! $language || empty( $language['url_prefix'] ) ) {
			return trailingslashit( $home );
		}

		return trailingslashit( $home . '/' . $language['url_prefix'] );
	}

	/**
	 * Sanitize incoming language list from admin.
	 *
	 * @param array<int, array<string, mixed>> $languages Raw languages.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_languages( array $languages ) {
		$sanitized = array();
		$has_default = false;

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) ) {
				continue;
			}

			$normalized = self::normalize_language( $language );

			if ( '' === $normalized['code'] ) {
				continue;
			}

			if ( ! empty( $normalized['is_default'] ) ) {
				if ( $has_default ) {
					$normalized['is_default'] = false;
				} else {
					$has_default              = true;
					$normalized['url_prefix'] = '';
				}
			}

			$sanitized[] = $normalized;
		}

		if ( empty( $sanitized ) ) {
			return self::get_default_languages();
		}

		if ( ! $has_default ) {
			$sanitized[0]['is_default'] = true;
			$sanitized[0]['url_prefix'] = '';
		}

		$codes = array();

		foreach ( $sanitized as $index => $language ) {
			if ( in_array( $language['code'], $codes, true ) ) {
				unset( $sanitized[ $index ] );
				continue;
			}

			$codes[] = $language['code'];
		}

		$prefixes = array();

		foreach ( $sanitized as $index => $language ) {
			if ( '' === $language['url_prefix'] || ! empty( $language['is_default'] ) ) {
				continue;
			}

			if ( in_array( $language['url_prefix'], $prefixes, true ) ) {
				unset( $sanitized[ $index ] );
				continue;
			}

			$prefixes[] = $language['url_prefix'];
		}

		return array_values( $sanitized );
	}

	/**
	 * Normalize a single language record.
	 *
	 * @param array<string, mixed> $language Raw language.
	 * @return array<string, mixed>
	 */
	private static function normalize_language( array $language ) {
		$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

		if ( '' === $code ) {
			$code = sanitize_key( (string) ( $language['url_prefix'] ?? '' ) );
		}

		$is_default = ! empty( $language['is_default'] );
		$prefix     = $is_default ? '' : sanitize_key( (string) ( $language['url_prefix'] ?? $code ) );
		$direction  = 'ltr' === ( $language['direction'] ?? 'rtl' ) ? 'ltr' : 'rtl';
		$legacy_icon = absint( $language['currency_icon_attachment_id'] ?? 0 );
		$cpc_icon    = absint( $language['cpc_currency_icon_attachment_id'] ?? 0 );

		if ( 0 === $cpc_icon && $legacy_icon > 0 ) {
			$cpc_icon = $legacy_icon;
		}

		return array(
			'code'               => $code,
			'name'               => sanitize_text_field( (string) ( $language['name'] ?? $code ) ),
			'native_name'        => sanitize_text_field( (string) ( $language['native_name'] ?? $language['name'] ?? $code ) ),
			'url_prefix'         => $prefix,
			'direction'          => $direction,
			'is_default'         => $is_default,
			'enabled'            => ! empty( $language['enabled'] ),
			'flag_attachment_id' => absint( $language['flag_attachment_id'] ?? 0 ),
			'cpc_currency_icon_attachment_id' => $cpc_icon,
			'product_placeholder_attachment_id' => absint( $language['product_placeholder_attachment_id'] ?? 0 ),
		);
	}

	/**
	 * Collect URL prefixes from a language list.
	 *
	 * @param array<int, array<string, mixed>> $languages Languages.
	 * @return string[]
	 */
	private static function collect_prefixes( array $languages ) {
		$prefixes = array();

		foreach ( $languages as $language ) {
			if ( empty( $language['enabled'] ) || empty( $language['url_prefix'] ) ) {
				continue;
			}

			$prefixes[] = (string) $language['url_prefix'];
		}

		sort( $prefixes );

		return $prefixes;
	}
}
