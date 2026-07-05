<?php
/**
 * PolyMartAI ↔ Customer Portal integration (multilingual UI + survey options).
 *
 * @package PolymartAI
 */

namespace PolymartAI\Integration;

use PolymartAI\Language_Registry;
use PolymartAI\Plugin;
use PolymartAI\Routing\Url_Router;
use PolymartAI\Translation\AI_Client;
use PolymartAI\Translation\Persian_Detector;
use PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Customer_Portal_Integration
 */
final class Customer_Portal_Integration {

	/**
	 * Customer Portal REST namespace slug.
	 */
	const REST_NAMESPACE = 'customer-portal/v1';

	/**
	 * Survey wp_options that receive per-language companion keys.
	 *
	 * @var string[]
	 */
	private static $survey_options = array(
		'customer_portal_survey_title',
		'customer_portal_survey_subtitle',
		'customer_portal_survey_questions',
	);

	/**
	 * Schedule integration hooks after Customer Portal has bootstrapped.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 25 );
	}

	/**
	 * Register integration hooks when Customer Portal is active.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! defined( 'CUSTOMER_PORTAL_VERSION' ) ) {
			return;
		}

		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'bootstrap_rest_context' ), 1, 3 );
		add_filter( 'customer_portal_localize_script', array( __CLASS__, 'filter_localize_script' ) );
		add_filter( 'customer_portal_portal_urls', array( __CLASS__, 'prefix_portal_urls' ) );
		add_filter( 'customer_portal_use_persian_digits', array( __CLASS__, 'filter_use_persian_digits' ) );
		add_action( 'updated_option', array( __CLASS__, 'maybe_translate_survey_option' ), 10, 3 );
	}

	/**
	 * Whether the current HTTP request targets Customer Portal REST routes.
	 *
	 * @return bool
	 */
	private static function is_customer_portal_rest_request() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );

		return false !== strpos( $uri, '/wp-json/' . self::REST_NAMESPACE );
	}

	/**
	 * Bootstrap language + storefront translators before Customer Portal REST handlers run.
	 *
	 * @param mixed            $result  Response to replace.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public static function bootstrap_rest_context( $result, $server, $request ) {
		unset( $server );

		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
			return $result;
		}

		Url_Router::bootstrap_current_language_from_request();
		Plugin::instance()->maybe_boot_storefront_translators();

		return $result;
	}

	/**
	 * Enrich Customer Portal frontend config with PolyMart storefront context.
	 *
	 * @param array<string, mixed> $config Existing localized config.
	 * @return array<string, mixed>
	 */
	public static function filter_localize_script( array $config ) {
		$lang      = sanitize_key( Url_Router::get_current_language() );
		$language  = Language_Registry::get_language( $lang );
		$direction = 'ltr' === ( $language['direction'] ?? 'rtl' ) ? 'ltr' : 'rtl';
		$locale    = Language_Registry::get_locale_for_language( $lang );
		$is_default = $lang === Language_Registry::get_default_language_code();

		$config['lang']                = $lang;
		$config['locale']              = $locale;
		$config['direction']           = $direction;
		$config['usesPersianDigits']   = Language_Registry::uses_persian_digits( $lang );
		$config['requirePersianNames'] = $is_default;
		$config['isTranslated']        = Url_Router::is_translated_request();

		if ( ! empty( $config['homeUrl'] ) && is_string( $config['homeUrl'] ) ) {
			$config['homeUrl'] = Url_Router::add_language_prefix_to_url( $config['homeUrl'] );
		}

		return $config;
	}

	/**
	 * Prefix portal page URLs with the active language segment.
	 *
	 * @param array<string, string|null> $urls Portal URLs.
	 * @return array<string, string|null>
	 */
	public static function prefix_portal_urls( array $urls ) {
		foreach ( $urls as $key => $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$urls[ $key ] = Url_Router::add_language_prefix_to_url( $url );
		}

		return $urls;
	}

	/**
	 * Persian digits only on the default (source) language — typically fa.
	 *
	 * @param bool $default Default from Customer Portal.
	 * @return bool
	 */
	public static function filter_use_persian_digits( $default ) {
		unset( $default );

		return Language_Registry::uses_persian_digits();
	}

	/**
	 * Auto-translate survey options when admins save Persian source values.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public static function maybe_translate_survey_option( $option, $old_value, $value ) {
		if ( ! in_array( $option, self::$survey_options, true ) ) {
			return;
		}

		if ( $value === $old_value ) {
			return;
		}

		self::translate_survey_option_to_all_languages( $option, $value );
	}

	/**
	 * Translate one survey option into every enabled non-default language.
	 *
	 * @param string $option Option base key.
	 * @param mixed  $value  Persian source value.
	 * @return void
	 */
	private static function translate_survey_option_to_all_languages( $option, $value ) {
		if ( '' === $value || null === $value ) {
			foreach ( Language_Registry::get_enabled_languages() as $language ) {
				$lang = sanitize_key( $language['code'] ?? '' );

				if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
					continue;
				}

				delete_option( $option . '_' . $lang );
			}

			return;
		}

		$settings = Post_Translator::get_translation_settings();

		if ( empty( $settings['api_key'] ) || empty( $settings['api_endpoint'] ) ) {
			return;
		}

		$api_key      = (string) $settings['api_key'];
		$api_endpoint = rtrim( (string) $settings['api_endpoint'], '/' );
		$ai_model     = AI_Client::resolve_model(
			(string) ( $settings['ai_model'] ?? '' ),
			$api_endpoint
		);

		foreach ( Language_Registry::get_enabled_languages() as $language ) {
			$lang = sanitize_key( $language['code'] ?? '' );

			if ( '' === $lang || $lang === Language_Registry::get_default_language_code() ) {
				continue;
			}

			$translated = self::translate_survey_value( $option, $value, $lang, $api_key, $api_endpoint, $ai_model );

			if ( null === $translated ) {
				continue;
			}

			update_option( $option . '_' . $lang, $translated, false );
		}
	}

	/**
	 * Translate a survey option value for one target language.
	 *
	 * @param string $option       Option base key.
	 * @param mixed  $value        Source value.
	 * @param string $lang         Target language code.
	 * @param string $api_key      API key.
	 * @param string $api_endpoint API endpoint.
	 * @param string $ai_model     Model identifier.
	 * @return mixed|null
	 */
	private static function translate_survey_value( $option, $value, $lang, $api_key, $api_endpoint, $ai_model ) {
		if ( 'customer_portal_survey_questions' === $option ) {
			return self::translate_survey_questions_json( $value, $lang, $api_key, $api_endpoint, $ai_model );
		}

		$text = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $text || ! Persian_Detector::contains_persian( $text ) ) {
			return is_string( $value ) ? $value : null;
		}

		$result = AI_Client::translate_fields(
			array( 'text' => $text ),
			$api_key,
			$api_endpoint,
			$ai_model,
			$lang
		);

		if ( is_wp_error( $result ) || empty( $result['text'] ) ) {
			return null;
		}

		return (string) $result['text'];
	}

	/**
	 * Translate structured survey questions and return JSON for companion storage.
	 *
	 * @param mixed  $value        Source questions JSON or array.
	 * @param string $lang         Target language code.
	 * @param string $api_key      API key.
	 * @param string $api_endpoint API endpoint.
	 * @param string $ai_model     Model identifier.
	 * @return string|null
	 */
	private static function translate_survey_questions_json( $value, $lang, $api_key, $api_endpoint, $ai_model ) {
		if ( is_string( $value ) ) {
			$questions = json_decode( $value, true );
		} else {
			$questions = $value;
		}

		if ( ! is_array( $questions ) || empty( $questions ) ) {
			return is_string( $value ) ? $value : null;
		}

		$payload = array();

		foreach ( $questions as $index => $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}

			$text = isset( $question['text'] ) ? trim( (string) $question['text'] ) : '';

			if ( '' !== $text && Persian_Detector::contains_persian( $text ) ) {
				$payload[ 'q_' . $index . '_text' ] = $text;
			}

			if ( empty( $question['options'] ) || ! is_array( $question['options'] ) ) {
				continue;
			}

			foreach ( $question['options'] as $option_index => $option_text ) {
				$option_text = trim( (string) $option_text );

				if ( '' !== $option_text && Persian_Detector::contains_persian( $option_text ) ) {
					$payload[ 'q_' . $index . '_opt_' . $option_index ] = $option_text;
				}
			}
		}

		if ( empty( $payload ) ) {
			return is_string( $value ) ? $value : wp_json_encode( $questions, JSON_UNESCAPED_UNICODE );
		}

		$result = AI_Client::translate_fields( $payload, $api_key, $api_endpoint, $ai_model, $lang );

		if ( is_wp_error( $result ) || empty( $result ) ) {
			return null;
		}

		foreach ( $questions as $index => &$question ) {
			$text_key = 'q_' . $index . '_text';

			if ( isset( $result[ $text_key ] ) ) {
				$question['text'] = (string) $result[ $text_key ];
			}

			if ( empty( $question['options'] ) || ! is_array( $question['options'] ) ) {
				continue;
			}

			foreach ( $question['options'] as $option_index => &$option_text ) {
				$option_key = 'q_' . $index . '_opt_' . $option_index;

				if ( isset( $result[ $option_key ] ) ) {
					$option_text = (string) $result[ $option_key ];
				}
			}
			unset( $option_text );
		}
		unset( $question );

		return wp_json_encode( $questions, JSON_UNESCAPED_UNICODE );
	}
}
