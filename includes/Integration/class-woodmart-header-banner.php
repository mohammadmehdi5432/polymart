<?php
/**
 * Per-language Woodmart header-banner background images.
 *
 * FA source stays in Theme Settings → header_banner_bg (compiled CSS).
 * PolyMart stores companion attachment IDs and overrides .header-banner
 * background-image on /en/ and /ar/ (same idea as slide banners / Elementor images).
 *
 * @package PolymartAI\Integration
 */

namespace PolymartAI\Integration;

use PolymartAI\Language_Registry;
use PolymartAI\REST_API;
use PolymartAI\Routing\Url_Router;


defined( 'ABSPATH' ) || exit;

/**
 * Class Woodmart_Header_Banner
 */
final class Woodmart_Header_Banner {

	/**
	 * Option key prefix: polymart_ai_header_banner_image_{lang}.
	 */
	const OPTION_PREFIX = 'polymart_ai_header_banner_image_';

	/**
	 * Admin page slug.
	 */
	const ADMIN_SLUG = 'polymart-ai-header-banner';

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private static $admin_hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_polymart_ai_save_header_banner', array( __CLASS__, 'handle_admin_save' ) );

		if ( ! is_admin() || wp_doing_ajax() ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_storefront_override' ), 10020 );
		}
	}

	/**
	 * Option name for a language companion banner image.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_option_key( $lang ) {
		return self::OPTION_PREFIX . sanitize_key( (string) $lang );
	}

	/**
	 * Stored attachment ID for a language (0 when unset).
	 *
	 * @param string $lang Language code.
	 * @return int
	 */
	public static function get_image_id( $lang ) {
		$lang = sanitize_key( (string) $lang );

		if ( '' === $lang ) {
			return 0;
		}

		$id = absint( get_option( self::get_option_key( $lang ), 0 ) );

		if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
			return $id;
		}

		return 0;
	}

	/**
	 * Persist attachment ID (0 clears).
	 *
	 * @param string $lang Language code.
	 * @param int    $id   Attachment ID.
	 * @return void
	 */
	public static function set_image_id( $lang, $id ) {
		$lang = sanitize_key( (string) $lang );
		$id   = absint( $id );

		if ( '' === $lang ) {
			return;
		}

		$key = self::get_option_key( $lang );

		if ( $id <= 0 ) {
			delete_option( $key );
			return;
		}

		if ( 'attachment' !== get_post_type( $id ) ) {
			return;
		}

		update_option( $key, $id, false );
	}

	/**
	 * Full-size image URL for a language companion, or empty.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_image_url( $lang ) {
		$id = self::get_image_id( $lang );

		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'full' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Override compiled Woodmart CSS on translated storefront URLs.
	 *
	 * @return void
	 */
	public static function enqueue_storefront_override() {
		if ( is_admin() || ! Url_Router::is_translated_request() ) {
			return;
		}

		$lang = Url_Router::get_current_language();

		if ( '' === $lang || Language_Registry::get_default_language_code() === $lang ) {
			return;
		}

		$url = self::get_image_url( $lang );

		if ( '' === $url ) {
			// No FA fallback — clear the Persian promo art on this language.
			$css = '.header-banner{background-image:none!important;}';
		} else {
			$css = sprintf(
				'.header-banner{background-image:url(%s)!important;}',
				esc_url_raw( $url )
			);
		}

		$handle = 'polymart-ai-header-banner';

		wp_register_style( $handle, false, array(), POLYMART_AI_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Register admin submenu under PolyMart.
	 *
	 * @return void
	 */
	public static function register_admin_page() {
		if ( ! defined( 'WOODMART_THEME_VERSION' ) && ! function_exists( 'woodmart_get_opt' ) ) {
			return;
		}

		self::$admin_hook = (string) add_submenu_page(
			'polymart-ai',
			__( 'بنر هدر وودمارت', 'polymart-ai' ),
			__( 'بنر هدر', 'polymart-ai' ),
			REST_API::required_admin_capability(),
			self::ADMIN_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Media library + picker script on the banner settings screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( '' === self::$admin_hook || $hook_suffix !== self::$admin_hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'polymart-ai-meta-box',
			POLYMART_AI_PLUGIN_URL . 'assets/admin/meta-box.css',
			array(),
			POLYMART_AI_VERSION
		);

		wp_register_script(
			'polymart-ai-header-banner-admin',
			false,
			array( 'jquery', 'media-editor', 'media-upload' ),
			POLYMART_AI_VERSION,
			true
		);
		wp_enqueue_script( 'polymart-ai-header-banner-admin' );
		wp_add_inline_script( 'polymart-ai-header-banner-admin', self::get_admin_picker_script() );
	}

	/**
	 * Save companion banner images from the admin form.
	 *
	 * @return void
	 */
	public static function handle_admin_save() {
		if ( ! current_user_can( REST_API::required_admin_capability() ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'polymart-ai' ) );
		}

		check_admin_referer( 'polymart_ai_save_header_banner' );

		$targets = Language_Registry::get_translation_target_languages();

		foreach ( $targets as $language ) {
			$code = sanitize_key( (string) ( $language['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$field = 'banner_image_' . $code;
			$id    = isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : 0;
			self::set_image_id( $code, $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::ADMIN_SLUG,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the admin settings screen.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( REST_API::required_admin_capability() ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'polymart-ai' ) );
		}

		$targets = Language_Registry::get_translation_target_languages();
		?>
		<div class="wrap polymart-ai-admin-wrap" dir="rtl">
			<h1><?php esc_html_e( 'بنر هدر وودمارت', 'polymart-ai' ); ?></h1>
			<p class="description">
				<?php
				esc_html_e(
					'تصویر پس‌زمینهٔ بنر بالای هدر را برای هر زبان جداگانه انتخاب کنید. تصویر فارسی همان Banner background در تنظیمات وودمارت می‌ماند. اگر برای زبانی تصویری نگذارید، در آن زبان تصویر فارسی نشان داده نمی‌شود.',
					'polymart-ai'
				);
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=xts_theme_settings' ) ); ?>">
					<?php esc_html_e( 'باز کردن تنظیمات قالب وودمارت (بنر فارسی)', 'polymart-ai' ); ?>
				</a>
			</p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ذخیره شد.', 'polymart-ai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $targets ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'هیچ زبان مقصدی فعال نیست.', 'polymart-ai' ); ?></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="polymart_ai_save_header_banner" />
					<?php wp_nonce_field( 'polymart_ai_save_header_banner' ); ?>

					<table class="form-table" role="presentation">
						<?php foreach ( $targets as $language ) : ?>
							<?php
							$code  = sanitize_key( (string) ( $language['code'] ?? '' ) );
							$label = (string) ( $language['label'] ?? $code );
							$id    = self::get_image_id( $code );
							$url   = self::get_image_url( $code );
							$field = 'banner_image_' . $code;
							$fid   = 'polymart-ai-header-banner-' . $code;
							?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $fid ); ?>">
										<?php
										printf(
											/* translators: %s: language name */
											esc_html__( 'بنر هدر (%s)', 'polymart-ai' ),
											esc_html( $label )
										);
										?>
									</label>
								</th>
								<td>
									<div
										class="polymart-ai-thumbnail-field"
										data-lang="<?php echo esc_attr( $code ); ?>"
										data-lang-label="<?php echo esc_attr( $label ); ?>"
									>
										<input
											type="hidden"
											id="<?php echo esc_attr( $fid ); ?>"
											class="polymart-ai-thumbnail-input"
											name="<?php echo esc_attr( $field ); ?>"
											value="<?php echo esc_attr( (string) $id ); ?>"
										/>
										<div class="polymart-ai-thumbnail-field__preview">
											<?php if ( $url ) : ?>
												<img src="<?php echo esc_url( $url ); ?>" alt="" />
											<?php else : ?>
												<span class="polymart-ai-thumbnail-field__placeholder"><?php esc_html_e( 'تصویری انتخاب نشده', 'polymart-ai' ); ?></span>
											<?php endif; ?>
										</div>
										<p class="polymart-ai-thumbnail-field__actions">
											<button type="button" class="button polymart-ai-thumbnail-select">
												<?php esc_html_e( 'انتخاب تصویر از رسانه', 'polymart-ai' ); ?>
											</button>
											<button
												type="button"
												class="button-link-delete polymart-ai-thumbnail-remove"
												<?php echo $id > 0 ? '' : 'style="display:none"'; ?>
											>
												<?php esc_html_e( 'حذف تصویر', 'polymart-ai' ); ?>
											</button>
										</p>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>

					<?php submit_button( __( 'ذخیره بنرها', 'polymart-ai' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Minimal media-picker JS for this screen (same UX as meta box thumbnails).
	 *
	 * @return string
	 */
	private static function get_admin_picker_script() {
		return <<<'JS'
jQuery(function ($) {
	var frames = {};
	$('.polymart-ai-thumbnail-field').each(function () {
		var $field = $(this);
		var lang = String($field.data('lang') || '');
		var label = String($field.data('lang-label') || lang);

		$field.find('.polymart-ai-thumbnail-select').on('click', function (event) {
			event.preventDefault();
			if (!frames[lang]) {
				frames[lang] = wp.media({
					title: 'Select image — ' + label,
					button: { text: 'Use image' },
					library: { type: 'image' },
					multiple: false
				});
				frames[lang].on('select', function () {
					var attachment = frames[lang].state().get('selection').first().toJSON();
					var url = attachment.url || (attachment.sizes && attachment.sizes.full && attachment.sizes.full.url) || '';
					$field.find('.polymart-ai-thumbnail-input').val(attachment.id || '');
					var $preview = $field.find('.polymart-ai-thumbnail-field__preview');
					$preview.html(url ? $('<img/>').attr({ src: url, alt: '' }) : '');
					$field.find('.polymart-ai-thumbnail-remove').show();
				});
			}
			frames[lang].open();
		});

		$field.find('.polymart-ai-thumbnail-remove').on('click', function (event) {
			event.preventDefault();
			$field.find('.polymart-ai-thumbnail-input').val('');
			$field.find('.polymart-ai-thumbnail-field__preview').html(
				'<span class="polymart-ai-thumbnail-field__placeholder">تصویری انتخاب نشده</span>'
			);
			$(this).hide();
		});
	});
});
JS;
	}
}
