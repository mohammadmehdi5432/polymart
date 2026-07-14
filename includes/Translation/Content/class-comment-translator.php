<?php
/**
 * WooCommerce product review translation (frontend + background AI).
 *
 * @package PolymartAI\Translation
 */

namespace PolymartAI\Translation\Content;

use PolymartAI\Routing\Url_Router;

use PolymartAI\Translation\AI\AI_Client;
use PolymartAI\Translation\AI\Persian_Detector;
use PolymartAI\Translation\Post_Translator;
use PolymartAI\Translation\Storefront\Runtime_String_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Comment_Translator
 */
final class Comment_Translator {

	/**
	 * Comment meta key for English review body.
	 *
	 * @deprecated Use get_meta_key( 'comment', 'en' ).
	 */
	const META_KEY_COMMENT_EN = '_polymart_ai_comment_en';

	/**
	 * Comment meta key for English reviewer display name.
	 *
	 * @deprecated Use get_meta_key( 'author', 'en' ).
	 */
	const META_KEY_AUTHOR_EN = '_polymart_ai_author_en';

	/**
	 * WP-Cron hook for deferred review translation.
	 */
	const CRON_HOOK = 'polymart_ai_translate_comment';

	/**
	 * Cached result of should_intercept() for the current request.
	 *
	 * @var bool|null
	 */
	private $intercept_cache = null;

	/**
	 * Shared worker for APD display filters (never registers WordPress hooks).
	 *
	 * @var self|null
	 */
	private static $display_worker = null;

	/**
	 * Shared worker for background lifecycle handlers.
	 *
	 * @var self|null
	 */
	private static $lifecycle_worker = null;

	/**
	 * Whether background lifecycle hooks were registered globally.
	 *
	 * @var bool
	 */
	private static $lifecycle_hooks_registered = false;

	/**
	 * Register comment lifecycle hooks outside the storefront `wp` boot.
	 *
	 * @return void
	 */
	public static function register_lifecycle_hooks() {
		if ( self::$lifecycle_hooks_registered ) {
			return;
		}

		self::$lifecycle_hooks_registered = true;

		add_action( 'comment_post', array( __CLASS__, 'handle_comment_post_static' ), 20, 3 );
		add_action( 'edit_comment', array( __CLASS__, 'handle_edit_comment_static' ), 20, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'handle_transition_comment_status_static' ), 20, 3 );
	}

	/**
	 * Shared display worker for APD review rows.
	 *
	 * @return self
	 */
	public static function display_worker() {
		if ( null === self::$display_worker ) {
			self::$display_worker = new self( false );
		}

		return self::$display_worker;
	}

	/**
	 * Resolve translated review body for APD display filters.
	 *
	 * @param string      $content    Display content.
	 * @param \WP_Comment $comment    Source comment.
	 * @param int         $product_id Product ID.
	 * @return string
	 */
	public function resolve_display_content( $content, $comment, $product_id ) {
		unset( $product_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return is_string( $content ) ? $content : '';
		}

		return $this->filter_comment_text( (string) $content, $comment );
	}

	/**
	 * Resolve translated reviewer name for APD display filters.
	 *
	 * @param string      $author     Display author.
	 * @param \WP_Comment $comment    Source comment.
	 * @param int         $product_id Product ID.
	 * @return string
	 */
	public function resolve_display_author( $author, $comment, $product_id ) {
		unset( $product_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return is_string( $author ) ? $author : '';
		}

		return $this->filter_comment_author( (string) $author, (int) $comment->comment_ID, $comment );
	}

	/**
	 * Constructor.
	 *
	 * @param bool $register_hooks Whether to register storefront comment filters.
	 */
	public function __construct( $register_hooks = true ) {
		if ( ! $register_hooks ) {
			return;
		}

		add_filter( 'get_comment_text', array( $this, 'filter_comment_text' ), 10, 2 );
		add_filter( 'comment_text', array( $this, 'filter_comment_text' ), 10, 2 );
		add_filter( 'get_comment_author', array( $this, 'filter_comment_author' ), 10, 3 );
		add_filter( 'woocommerce_review_comment_text', array( $this, 'filter_comment_text' ), 10, 2 );
	}

	/**
	 * Lifecycle: new approved review.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved Approval status.
	 * @param array      $commentdata      Comment data.
	 * @return void
	 */
	public static function handle_comment_post_static( $comment_id, $comment_approved, $commentdata ) {
		self::lifecycle_worker()->handle_comment_post( $comment_id, $comment_approved, $commentdata );
	}

	/**
	 * Lifecycle: edited review.
	 *
	 * @param int   $comment_id   Comment ID.
	 * @param array $comment_data Updated comment data.
	 * @return void
	 */
	public static function handle_edit_comment_static( $comment_id, $comment_data ) {
		self::lifecycle_worker()->handle_edit_comment( $comment_id, $comment_data );
	}

	/**
	 * Lifecycle: review status transition.
	 *
	 * @param string|int  $new_status New comment status.
	 * @param string|int  $old_status Previous comment status.
	 * @param \WP_Comment $comment    Comment object.
	 * @return void
	 */
	public static function handle_transition_comment_status_static( $new_status, $old_status, $comment ) {
		self::lifecycle_worker()->handle_transition_comment_status( $new_status, $old_status, $comment );
	}

	/**
	 * @return self
	 */
	private static function lifecycle_worker() {
		if ( null === self::$lifecycle_worker ) {
			self::$lifecycle_worker = new self( false );
		}

		return self::$lifecycle_worker;
	}

	/**
	 * WP-Cron entry point registered from Plugin bootstrap (outside the `wp` hook).
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public static function process_scheduled_translation( $comment_id ) {
		( new self( false ) )->process_comment_translation( absint( $comment_id ) );
	}

	/**
	 * Replace review text with its English translation on /en/ URLs.
	 *
	 * @param string        $comment_text Original comment text.
	 * @param \WP_Comment|object|null $comment Comment object.
	 * @return string
	 */
	public function filter_comment_text( $comment_text, $comment ) {
		if ( ! $this->should_intercept() || ! $comment instanceof \WP_Comment ) {
			return $comment_text;
		}

		if ( ! $this->is_translatable_review( $comment ) ) {
			return $comment_text;
		}

		$lang       = $this->get_active_lang();
		$comment_id = (int) $comment->comment_ID;
		$translated = get_comment_meta( $comment_id, self::get_meta_key( 'comment', $lang ), true );

		if ( is_string( $translated ) && '' !== trim( $translated ) ) {
			return $translated;
		}

		$this->queue_if_missing_translation( $comment_id );

		if ( Persian_Detector::contains_persian( $comment_text ) ) {
			$cached = Runtime_String_Translator::lookup_cached( $comment_text, $lang, 'comment:' . $comment_id );

			if ( is_string( $cached ) && '' !== trim( $cached ) ) {
				return $cached;
			}
		}

		return $comment_text;
	}

	/**
	 * Replace reviewer name with its English translation on /en/ URLs.
	 *
	 * @param string                   $author     Original author name.
	 * @param int                      $comment_id Comment ID.
	 * @param \WP_Comment|object|null  $comment    Comment object.
	 * @return string
	 */
	public function filter_comment_author( $author, $comment_id, $comment ) {
		if ( ! $this->should_intercept() || ! $comment_id ) {
			return $author;
		}

		if ( ! $comment instanceof \WP_Comment ) {
			$comment = get_comment( (int) $comment_id );
		}

		if ( ! $comment instanceof \WP_Comment || ! $this->is_translatable_review( $comment ) ) {
			return $author;
		}

		$lang       = $this->get_active_lang();
		$comment_id = (int) $comment_id;
		$translated = get_comment_meta( $comment_id, self::get_meta_key( 'author', $lang ), true );

		if ( is_string( $translated ) && '' !== trim( $translated ) ) {
			return $translated;
		}

		$this->queue_if_missing_translation( $comment_id );

		if ( Persian_Detector::contains_persian( $author ) ) {
			$cached = Runtime_String_Translator::lookup_cached( $author, $lang, 'comment_author:' . $comment_id );

			if ( is_string( $cached ) && '' !== trim( $cached ) ) {
				return $cached;
			}
		}

		return $author;
	}

	/**
	 * Schedule translation when a review is submitted already approved.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved Approval status.
	 * @param array      $commentdata      Comment data.
	 * @return void
	 */
	public function handle_comment_post( $comment_id, $comment_approved, $commentdata ) {
		unset( $commentdata );

		if ( ! $this->is_approved_status( $comment_approved ) ) {
			return;
		}

		$this->schedule_translation( (int) $comment_id );
	}

	/**
	 * Schedule translation when an approved review is edited.
	 *
	 * @param int   $comment_id   Comment ID.
	 * @param array $comment_data Updated comment data.
	 * @return void
	 */
	public function handle_edit_comment( $comment_id, $comment_data ) {
		unset( $comment_data );

		$comment = get_comment( (int) $comment_id );

		if ( ! $comment instanceof \WP_Comment || ! $this->is_translatable_review( $comment ) ) {
			return;
		}

		// The source text changed — drop stale translations so the worker regenerates them.
		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			delete_comment_meta( (int) $comment_id, self::get_meta_key( 'comment', $lang ) );
			delete_comment_meta( (int) $comment_id, self::get_meta_key( 'author', $lang ) );
		}

		$this->schedule_translation( (int) $comment_id, true );
	}

	/**
	 * Schedule translation when a review transitions to approved.
	 *
	 * @param string|int   $new_status New comment status.
	 * @param string|int   $old_status Previous comment status.
	 * @param \WP_Comment  $comment    Comment object.
	 * @return void
	 */
	public function handle_transition_comment_status( $new_status, $old_status, $comment ) {
		unset( $old_status );

		if ( 'approved' !== $new_status && 'approve' !== $new_status && 1 !== $new_status && '1' !== $new_status ) {
			return;
		}

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$this->schedule_translation( (int) $comment->comment_ID );
	}

	/**
	 * Background worker: translate an approved product review via AI.
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function process_comment_translation( $comment_id ) {
		$comment_id = absint( $comment_id );

		if ( ! $comment_id ) {
			return;
		}

		try {
			$comment = get_comment( $comment_id );

			if ( ! $comment instanceof \WP_Comment || ! $this->is_translatable_review( $comment ) ) {
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

			/**
			 * Fires before a background review translation request is sent.
			 *
			 * @param int         $comment_id Comment ID.
			 * @param \WP_Comment $comment    Comment object.
			 * @param array       $payload    Source fields for translation.
			 */
			do_action( 'polymart_ai_before_translate_comment', $comment_id, $comment, $this->build_translation_payload( $comment ) );

			foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
				$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

				if ( '' === $lang ) {
					continue;
				}

				$lang_payload = $this->build_translation_payload( $comment, $lang );

				if ( empty( $lang_payload ) ) {
					continue;
				}

				$translated = AI_Client::send_translation_request(
					$lang_payload,
					$api_key,
					$api_endpoint,
					$ai_model,
					$lang
				);

				if ( is_wp_error( $translated ) ) {
					/**
					 * Fires when background review translation fails.
					 *
					 * @param \WP_Error   $translated Error object.
					 * @param int         $comment_id Comment ID.
					 * @param \WP_Comment $comment    Comment object.
					 */
					do_action( 'polymart_ai_comment_translation_failed', $translated, $comment_id, $comment );
					continue;
				}

				if ( isset( $translated['comment_content'] ) ) {
					update_comment_meta(
						$comment_id,
						self::get_meta_key( 'comment', $lang ),
						wp_kses_post( $translated['comment_content'] )
					);
				}

				if ( isset( $translated['comment_author'] ) ) {
					update_comment_meta(
						$comment_id,
						self::get_meta_key( 'author', $lang ),
						sanitize_text_field( $translated['comment_author'] )
					);

					if ( isset( $lang_payload['comment_author'] ) && Persian_Detector::contains_persian( $lang_payload['comment_author'] ) ) {
						Runtime_String_Translator::store_translation(
							$lang_payload['comment_author'],
							$lang,
							'comment_author:' . $comment_id,
							sanitize_text_field( $translated['comment_author'] )
						);
					}
				}

				if ( isset( $translated['comment_content'] ) && isset( $lang_payload['comment_content'] ) ) {
					Runtime_String_Translator::store_translation(
						$lang_payload['comment_content'],
						$lang,
						'comment:' . $comment_id,
						wp_kses_post( $translated['comment_content'] )
					);
				}

				/**
				 * Fires after background review translations have been saved.
				 *
				 * @param int                   $comment_id   Comment ID.
				 * @param \WP_Comment           $comment      Comment object.
				 * @param array<string, string> $translated   Saved translations.
				 */
				do_action( 'polymart_ai_after_save_comment_translations', $comment_id, $comment, $translated );
			}
		} catch ( \Throwable $exception ) {
			/**
			 * Fires when an unexpected exception occurs during review translation.
			 *
			 * @param \Throwable  $exception  Caught exception.
			 * @param int         $comment_id Comment ID.
			 */
			do_action( 'polymart_ai_comment_translation_exception', $exception, $comment_id );
		}
	}

	/**
	 * Queue a single deferred translation job (never blocks comment submission).
	 *
	 * @param int  $comment_id Comment ID.
	 * @param bool $force      When true, allow re-scheduling even if a job exists.
	 * @return void
	 */
	private function schedule_translation( $comment_id, $force = false ) {
		$comment_id = absint( $comment_id );

		if ( ! $comment_id ) {
			return;
		}

		try {
			$comment = get_comment( $comment_id );

			if ( ! $comment instanceof \WP_Comment || ! $this->is_translatable_review( $comment ) ) {
				return;
			}

			if ( ! $force && wp_next_scheduled( self::CRON_HOOK, array( $comment_id ) ) ) {
				return;
			}

			if ( $force ) {
				wp_clear_scheduled_hook( self::CRON_HOOK, array( $comment_id ) );
			}

			wp_schedule_single_event( time(), self::CRON_HOOK, array( $comment_id ) );

			if ( ! wp_doing_cron() && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
				spawn_cron();
			}
		} catch ( \Throwable $exception ) {
			do_action( 'polymart_ai_comment_schedule_exception', $exception, $comment_id );
		}
	}

	/**
	 * Queue background translation when a review is shown without stored translations.
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	private function queue_if_missing_translation( $comment_id ) {
		$comment_id = absint( $comment_id );

		if ( ! $comment_id ) {
			return;
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment || ! $this->is_translatable_review( $comment ) ) {
			return;
		}

		foreach ( \PolymartAI\Language_Registry::get_translation_target_languages() as $language ) {
			$lang = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $lang ) {
				continue;
			}

			if ( ! empty( $this->build_translation_payload( $comment, $lang ) ) ) {
				$this->schedule_translation( $comment_id );

				return;
			}
		}
	}

	/**
	 * Build the AI payload from an approved product review.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @param string      $lang    Target language code.
	 * @return array<string, string>
	 */
	private function build_translation_payload( \WP_Comment $comment, $lang = '' ) {
		$lang    = sanitize_key( (string) $lang );
		$payload = array();

		$content = trim( (string) $comment->comment_content );

		if ( '' !== $content && Persian_Detector::contains_persian( $content ) ) {
			$existing = '' !== $lang
				? (string) get_comment_meta( (int) $comment->comment_ID, self::get_meta_key( 'comment', $lang ), true )
				: '';

			if ( '' === $lang || '' === trim( $existing ) ) {
				$payload['comment_content'] = $content;
			}
		}

		$author = trim( (string) $comment->comment_author );

		if ( '' !== $author && Persian_Detector::contains_persian( $author ) ) {
			$existing = '' !== $lang
				? (string) get_comment_meta( (int) $comment->comment_ID, self::get_meta_key( 'author', $lang ), true )
				: '';

			if ( '' === $lang || '' === trim( $existing ) ) {
				$payload['comment_author'] = $author;
			}
		}

		return $payload;
	}

	/**
	 * Whether a comment is an approved WooCommerce product review.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return bool
	 */
	private function is_translatable_review( \WP_Comment $comment ) {
		if ( ! $this->is_approved_status( $comment->comment_approved ) ) {
			return false;
		}

		if ( 'review' === $comment->comment_type ) {
			return true;
		}

		// WooCommerce shop replies sometimes keep an empty comment_type.
		if ( (int) $comment->comment_parent > 0 ) {
			$parent = get_comment( (int) $comment->comment_parent );

			return $parent instanceof \WP_Comment
				&& 'review' === $parent->comment_type
				&& $this->is_approved_status( $parent->comment_approved );
		}

		return false;
	}

	/**
	 * Normalize WordPress comment approval values.
	 *
	 * @param int|string $status Approval status.
	 * @return bool
	 */
	private function is_approved_status( $status ) {
		return 1 === $status || '1' === $status || 'approve' === $status || 'approved' === $status;
	}

	/**
	 * Build a comment meta key for a translated field and language code.
	 *
	 * @param string $field comment|author.
	 * @param string $lang  Language code.
	 * @return string
	 */
	public static function get_meta_key( $field, $lang ) {
		return '_polymart_ai_' . sanitize_key( (string) $field ) . '_' . sanitize_key( (string) $lang );
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
	 * Determine whether frontend filters should swap in translated content.
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
