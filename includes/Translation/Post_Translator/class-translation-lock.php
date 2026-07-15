<?php
/**
 * Per-post translation locks to prevent duplicate AI workers.
 *
 * @package PolymartAI\Translation\Post_Translator
 */

namespace PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Translation_Lock
 */
final class Translation_Lock {

	/**
	 * Transient prefix for in-flight AI translation locks.
	 */
	const TRANSLATION_LOCK_PREFIX = 'polymart_ai_post_translate_';

	/**
	 * TTL for per-post translation locks (seconds).
	 */
	const TRANSLATION_LOCK_TTL = 180;

	/**
	 * Option suffix storing the worker token that owns a per-post translation lock.
	 */
	const TRANSLATION_LOCK_OWNER_SUFFIX = '_owner';

	/**
	 * Per-request token identifying the current translation worker.
	 *
	 * @var string|null
	 */
	private static $translation_lock_token = null;

	/**
	 * Unique token for the current PHP worker (per-request).
	 *
	 * @return string
	 */
	public static function get_translation_lock_token() {
		if ( null === self::$translation_lock_token ) {
			self::$translation_lock_token = function_exists( 'wp_generate_uuid4' )
				? wp_generate_uuid4()
				: uniqid( 'pm_lock_', true );
		}

		return self::$translation_lock_token;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return array{key: string, claim: string, owner: string}
	 */
	public static function get_translation_lock_keys( $post_id, $lang ) {
		$key = self::TRANSLATION_LOCK_PREFIX . absint( $post_id ) . '_' . sanitize_key( (string) $lang );

		return array(
			'key'   => $key,
			'claim' => $key . '_claim',
			'owner' => $key . self::TRANSLATION_LOCK_OWNER_SUFFIX,
		);
	}

	/**
	 * Whether this worker currently owns the per-post translation lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	public static function owns_translation_lock( $post_id, $lang ) {
		$keys = self::get_translation_lock_keys( $post_id, $lang );

		return (string) get_option( $keys['owner'], '' ) === self::get_translation_lock_token();
	}

	/**
	 * Unconditionally take ownership of the lock (recovery / force-finalize saves).
	 *
	 * Emergency persist must never soft-fail because another worker still holds the claim.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool Always true when ids are valid.
	 */
	public static function force_claim_translation_lock( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$keys  = self::get_translation_lock_keys( $post_id, $lang );
		$token = self::get_translation_lock_token();
		$now   = time();

		update_option( $keys['claim'], (string) $now, false );
		update_option( $keys['owner'], $token, false );
		set_transient( $keys['key'], $now, self::TRANSLATION_LOCK_TTL );

		return true;
	}

	/**
	 * Acquire a per-post lock so duplicate AI requests are rejected.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function acquire_translation_lock( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return false;
		}

		$keys  = self::get_translation_lock_keys( $post_id, $lang );
		$token = self::get_translation_lock_token();
		$now   = time();

		$existing_owner = (string) get_option( $keys['owner'], '' );
		$existing_claim = absint( get_option( $keys['claim'], 0 ) );
		$existing_lock  = get_transient( $keys['key'] );
		$stamp          = max( $existing_claim, $existing_lock ? absint( $existing_lock ) : 0 );

		if ( $stamp > 0 && ( $now - $stamp ) < self::TRANSLATION_LOCK_TTL ) {
			if ( $existing_owner === $token ) {
				update_option( $keys['claim'], (string) $now, false );
				set_transient( $keys['key'], $now, self::TRANSLATION_LOCK_TTL );

				return true;
			}

			if ( ! $existing_lock && $existing_claim > 0 ) {
				set_transient( $keys['key'], $existing_claim, self::TRANSLATION_LOCK_TTL );
			}

			return false;
		}

		if ( $stamp > 0 ) {
			delete_option( $keys['claim'] );
			delete_option( $keys['owner'] );
			delete_transient( $keys['key'] );
		}

		if ( ! add_option( $keys['claim'], (string) $now, '', 'no' ) ) {
			$existing_claim = absint( get_option( $keys['claim'], 0 ) );

			if ( $existing_claim > 0 && ( $now - $existing_claim ) < self::TRANSLATION_LOCK_TTL ) {
				return false;
			}

			update_option( $keys['claim'], (string) $now, false );
		}

		update_option( $keys['owner'], $token, false );
		set_transient( $keys['key'], $now, self::TRANSLATION_LOCK_TTL );

		return true;
	}

	/**
	 * Release a per-post translation lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @param bool   $force   When true, clear the lock regardless of owner.
	 * @return void
	 */
	public static function release_translation_lock( $post_id, $lang, $force = false ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang ) {
			return;
		}

		$keys           = self::get_translation_lock_keys( $post_id, $lang );
		$existing_owner = (string) get_option( $keys['owner'], '' );

		if ( ! $force && '' !== $existing_owner && $existing_owner !== self::get_translation_lock_token() ) {
			return;
		}

		delete_transient( $keys['key'] );
		delete_option( $keys['claim'] );
		delete_option( $keys['owner'] );
	}

	/**
	 * Refresh timestamps on a lock already owned by this worker.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language code.
	 * @return bool
	 */
	public static function touch_translation_lock( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( (string) $lang );

		if ( $post_id <= 0 || '' === $lang || ! self::owns_translation_lock( $post_id, $lang ) ) {
			return false;
		}

		$keys = self::get_translation_lock_keys( $post_id, $lang );
		$now  = time();

		update_option( $keys['claim'], (string) $now, false );
		set_transient( $keys['key'], $now, self::TRANSLATION_LOCK_TTL );

		return true;
	}
}
