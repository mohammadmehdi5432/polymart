<?php
/**
 * Manual multilingual translation meta box for posts, products, and slides.
 *
 * @package PolymartAI\Admin
 */

namespace PolymartAI\Admin;

use PolymartAI\Language_Registry;
use PolymartAI\Translation\Post_Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Meta_Box
 */
final class Meta_Box {

	/**
	 * Meta box identifier.
	 */
	const META_BOX_ID = 'polymart-ai-english-translation';

	/**
	 * Nonce action for saving meta box fields.
	 */
	const NONCE_ACTION = Post_Translator::META_BOX_NONCE_ACTION;

	/**
	 * Nonce field name.
	 */
	const NONCE_NAME = Post_Translator::META_BOX_NONCE_NAME;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register the meta box on supported post types.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		foreach ( Post_Translator::get_supported_post_types() as $post_type ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'پلی‌مارت AI — ترجمه چندزبانه', 'polymart-ai' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render meta box HTML.
	 *
	 * @param \WP_Post $post Current post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$languages        = Language_Registry::get_translation_target_languages();
		$is_slide         = ( 'woodmart_slide' === $post->post_type );
		$show_image_field = Post_Translator::supports_featured_image_translation( $post->post_type );
		?>
		<div class="polymart-ai-metabox">
			<p class="polymart-ai-metabox__intro">
				<?php if ( $is_slide ) : ?>
					<?php esc_html_e( 'برای هر زبان فعال، بنر جایگزین (تصویر با متن آن زبان) را انتخاب کنید. در آدرس‌های ترجمه‌شده همان اسلاید با تصویر مربوطه نمایش داده می‌شود.', 'polymart-ai' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'برای هر زبان فعال، ترجمه را دستی وارد کنید یا با هوش مصنوعی تولید کنید.', 'polymart-ai' ); ?>
				<?php endif; ?>
			</p>

			<?php if ( empty( $languages ) ) : ?>
				<div class="notice notice-warning inline polymart-ai-metabox__empty">
					<p>
						<?php esc_html_e( 'هیچ زبان مقصدی فعال نیست. از منوی «مترجم پلی‌مارت → زبان‌ها» حداقل یک زبان غیرپیش‌فرض را فعال کنید.', 'polymart-ai' ); ?>
					</p>
				</div>
			<?php else : ?>
				<?php foreach ( $languages as $language ) : ?>
					<?php $this->render_language_panel( $post, $language, $is_slide, $show_image_field ); ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<p class="description polymart-ai-metabox__footnote">
				<?php esc_html_e( 'برای ذخیره ویرایش‌های دستی روی «به‌روزرسانی» کلیک کنید. تولید هوش مصنوعی فیلدها را پر می‌کند بدون انتشار مطلب.', 'polymart-ai' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render fields for a single target language.
	 *
	 * @param \WP_Post              $post             Post object.
	 * @param array<string, mixed>  $language         Language record.
	 * @param bool                  $is_slide         Whether the post is a Woodmart slide.
	 * @param bool                  $show_image_field Whether to show translated featured image.
	 * @return void
	 */
	private function render_language_panel( \WP_Post $post, array $language, $is_slide, $show_image_field ) {
		$lang         = sanitize_key( (string) $language['code'] );
		$lang_label   = ! empty( $language['native_name'] ) ? (string) $language['native_name'] : $lang;
		$lang_name    = ! empty( $language['name'] ) ? (string) $language['name'] : '';
		$prefix       = ! empty( $language['url_prefix'] ) ? (string) $language['url_prefix'] : $lang;
		$title_key    = Post_Translator::get_meta_key( 'title', $lang );
		$excerpt_key  = Post_Translator::get_meta_key( 'excerpt', $lang );
		$content_key  = Post_Translator::get_meta_key( 'content', $lang );
		$editor_id    = 'polymart_ai_content_' . $lang . '_editor';
		$title_val    = (string) get_post_meta( $post->ID, $title_key, true );
		$excerpt_val  = (string) get_post_meta( $post->ID, $excerpt_key, true );
		$content_val  = (string) get_post_meta( $post->ID, $content_key, true );
		$thumbnail_id = Post_Translator::get_translated_thumbnail_id( $post->ID, $lang );
		$thumbnail_url = $thumbnail_id > 0 ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';
		$panel_id     = 'polymart-ai-lang-panel-' . $lang;
		?>
		<section
			class="polymart-ai-metabox__lang-panel"
			id="<?php echo esc_attr( $panel_id ); ?>"
			data-lang="<?php echo esc_attr( $lang ); ?>"
			dir="<?php echo esc_attr( 'rtl' === ( $language['direction'] ?? 'ltr' ) ? 'rtl' : 'ltr' ); ?>"
		>
			<header class="polymart-ai-metabox__lang-header">
				<div>
					<h3 class="polymart-ai-metabox__lang-title">
						<?php echo esc_html( $lang_label ); ?>
						<span class="polymart-ai-metabox__lang-code">(<?php echo esc_html( $lang ); ?>)</span>
					</h3>
					<?php if ( '' !== $lang_name && $lang_name !== $lang_label ) : ?>
						<p class="polymart-ai-metabox__lang-subtitle"><?php echo esc_html( $lang_name ); ?></p>
					<?php endif; ?>
					<p class="description polymart-ai-metabox__lang-prefix">
						<?php
						printf(
							/* translators: %s: URL prefix */
							esc_html__( 'پیشوند URL: /%s/', 'polymart-ai' ),
							esc_html( $prefix )
						);
						?>
					</p>
				</div>
				<?php if ( ! $is_slide ) : ?>
					<button
						type="button"
						class="button button-secondary polymart-ai-generate-btn"
						data-lang="<?php echo esc_attr( $lang ); ?>"
					>
						<span class="polymart-ai-generate-btn__label">
							<?php
							printf(
								/* translators: %s: language name */
								esc_html__( '✨ تولید با AI — %s', 'polymart-ai' ),
								esc_html( $lang_label )
							);
							?>
						</span>
						<span class="polymart-ai-generate-btn__spinner spinner" style="float:none;margin:0 0 0 8px;display:none;"></span>
					</button>
				<?php endif; ?>
			</header>

			<span
				class="polymart-ai-metabox__status polymart-ai-metabox__status--lang"
				data-lang="<?php echo esc_attr( $lang ); ?>"
				role="status"
				aria-live="polite"
			></span>

			<table class="form-table polymart-ai-metabox__table" role="presentation">
				<tbody>
					<?php if ( $show_image_field ) : ?>
						<?php $this->render_thumbnail_field( $post, $lang, $lang_label, $is_slide, $thumbnail_id, $thumbnail_url ); ?>
					<?php endif; ?>

					<?php if ( ! $is_slide ) : ?>
						<tr>
							<th scope="row">
								<label for="polymart-ai-title-<?php echo esc_attr( $lang ); ?>">
									<?php
									printf(
										/* translators: %s: language name */
										esc_html__( 'عنوان (%s)', 'polymart-ai' ),
										esc_html( $lang_label )
									);
									?>
								</label>
							</th>
							<td>
								<input
									type="text"
									class="large-text"
									id="polymart-ai-title-<?php echo esc_attr( $lang ); ?>"
									name="<?php echo esc_attr( $title_key ); ?>"
									value="<?php echo esc_attr( $title_val ); ?>"
									dir="<?php echo esc_attr( 'rtl' === ( $language['direction'] ?? 'ltr' ) ? 'rtl' : 'ltr' ); ?>"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="polymart-ai-excerpt-<?php echo esc_attr( $lang ); ?>">
									<?php
									printf(
										/* translators: %s: language name */
										esc_html__( 'خلاصه (%s)', 'polymart-ai' ),
										esc_html( $lang_label )
									);
									?>
								</label>
							</th>
							<td>
								<textarea
									class="large-text"
									rows="3"
									id="polymart-ai-excerpt-<?php echo esc_attr( $lang ); ?>"
									name="<?php echo esc_attr( $excerpt_key ); ?>"
									dir="<?php echo esc_attr( 'rtl' === ( $language['direction'] ?? 'ltr' ) ? 'rtl' : 'ltr' ); ?>"
								><?php echo esc_textarea( $excerpt_val ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $editor_id ); ?>">
									<?php
									printf(
										/* translators: %s: language name */
										esc_html__( 'محتوا (%s)', 'polymart-ai' ),
										esc_html( $lang_label )
									);
									?>
								</label>
							</th>
							<td>
								<?php
								wp_editor(
									$content_val,
									$editor_id,
									array(
										'textarea_name' => $content_key,
										'textarea_rows' => 8,
										'media_buttons' => true,
										'teeny'         => false,
										'quicktags'     => true,
										'editor_height' => 180,
									)
								);
								?>
							</td>
						</tr>

						<?php foreach ( Post_Translator::CUSTOM_META_KEYS as $meta_key ) : ?>
							<?php
							$translated_key = Post_Translator::get_custom_meta_key( $meta_key, $lang );
							$field_value    = (string) get_post_meta( $post->ID, $translated_key, true );
							$field_id       = 'polymart-ai-' . sanitize_html_class( str_replace( '_', '-', $translated_key ) );
							?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<?php echo esc_html( Post_Translator::get_custom_meta_label( $meta_key, $lang ) ); ?>
									</label>
								</th>
								<td>
									<input
										type="text"
										class="large-text polymart-ai-custom-field"
										id="<?php echo esc_attr( $field_id ); ?>"
										name="<?php echo esc_attr( $translated_key ); ?>"
										value="<?php echo esc_attr( $field_value ); ?>"
										data-source-key="<?php echo esc_attr( $meta_key ); ?>"
										data-lang="<?php echo esc_attr( $lang ); ?>"
									/>
								</td>
							</tr>
						<?php endforeach; ?>

						<?php foreach ( Post_Translator::collect_discovered_meta_fields( $post->ID ) as $meta_key => $source_value ) : ?>
							<?php
							$translated_key = Post_Translator::get_custom_meta_key( $meta_key, $lang );
							$field_value    = (string) get_post_meta( $post->ID, $translated_key, true );
							$field_id       = 'polymart-ai-' . sanitize_html_class( str_replace( '_', '-', $translated_key ) );
							?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<?php echo esc_html( Post_Translator::get_custom_meta_label( $meta_key, $lang ) ); ?>
									</label>
								</th>
								<td>
									<textarea
										class="large-text polymart-ai-custom-field"
										id="<?php echo esc_attr( $field_id ); ?>"
										name="<?php echo esc_attr( $translated_key ); ?>"
										rows="3"
										data-source-key="<?php echo esc_attr( $meta_key ); ?>"
										data-lang="<?php echo esc_attr( $lang ); ?>"
									><?php echo esc_textarea( $field_value ); ?></textarea>
									<p class="description"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $source_value ), 12, '…' ) ); ?></p>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Render translated featured image picker for a language.
	 *
	 * @param \WP_Post $post          Post object.
	 * @param string   $lang          Language code.
	 * @param string   $lang_label    Display label.
	 * @param bool     $is_slide      Slide post type flag.
	 * @param int      $thumbnail_id  Current attachment ID.
	 * @param string   $thumbnail_url Preview URL.
	 * @return void
	 */
	private function render_thumbnail_field( \WP_Post $post, $lang, $lang_label, $is_slide, $thumbnail_id, $thumbnail_url ) {
		unset( $post );

		$thumbnail_key = Post_Translator::get_thumbnail_meta_key( $lang );
		$field_id      = 'polymart-ai-thumbnail-' . $lang;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $field_id ); ?>">
					<?php
					echo esc_html(
						$is_slide
							? sprintf(
								/* translators: %s: language name */
								__( 'بنر (%s)', 'polymart-ai' ),
								$lang_label
							)
							: sprintf(
								/* translators: %s: language name */
								__( 'تصویر شاخص (%s)', 'polymart-ai' ),
								$lang_label
							)
					);
					?>
				</label>
			</th>
			<td>
				<div
					class="polymart-ai-thumbnail-field"
					data-lang="<?php echo esc_attr( $lang ); ?>"
					data-lang-label="<?php echo esc_attr( $lang_label ); ?>"
				>
					<input
						type="hidden"
						id="<?php echo esc_attr( $field_id ); ?>"
						class="polymart-ai-thumbnail-input"
						name="<?php echo esc_attr( $thumbnail_key ); ?>"
						value="<?php echo esc_attr( (string) $thumbnail_id ); ?>"
					/>
					<div class="polymart-ai-thumbnail-field__preview">
						<?php if ( $thumbnail_url ) : ?>
							<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="" />
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
							<?php echo $thumbnail_id > 0 ? '' : 'style="display:none"'; ?>
						>
							<?php esc_html_e( 'حذف تصویر', 'polymart-ai' ); ?>
						</button>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: meta key */
							esc_html__( 'متای ذخیره‌شده: %s', 'polymart-ai' ),
							esc_html( $thumbnail_key )
						);
						?>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Enqueue meta box admin script on supported edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, Post_Translator::get_supported_post_types(), true ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'polymart-ai-meta-box',
			POLYMART_AI_PLUGIN_URL . 'assets/admin/meta-box.css',
			array(),
			POLYMART_AI_VERSION
		);

		wp_enqueue_script(
			'polymart-ai-meta-box',
			POLYMART_AI_PLUGIN_URL . 'assets/admin/meta-box.js',
			array( 'jquery', 'media-editor', 'media-upload' ),
			POLYMART_AI_VERSION,
			true
		);

		wp_localize_script(
			'polymart-ai-meta-box',
			'polymartAiMetaBox',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Ajax_Handler::NONCE_ACTION ),
				'postId'    => isset( $GLOBALS['post'] ) ? (int) $GLOBALS['post']->ID : 0,
				'languages' => self::build_language_script_config( isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null ),
				'strings'   => array(
					'generating'      => __( 'در حال تولید ترجمه…', 'polymart-ai' ),
					'success'         => __( 'ترجمه تولید شد. فیلدها را بررسی کرده و برای ذخیره روی «به‌روزرسانی» کلیک کنید.', 'polymart-ai' ),
					'error'           => __( 'ترجمه ناموفق بود. لطفاً تنظیمات API را بررسی کنید.', 'polymart-ai' ),
					'noPostId'        => __( 'ابتدا مطلب را به‌صورت پیش‌نویس ذخیره کنید، سپس ترجمه را تولید کنید.', 'polymart-ai' ),
					'selectImage'     => __( 'انتخاب تصویر بنر', 'polymart-ai' ),
					'selectImageBtn'  => __( 'استفاده از این تصویر', 'polymart-ai' ),
					'noImageSelected' => __( 'تصویری انتخاب نشده', 'polymart-ai' ),
				),
			)
		);
	}

	/**
	 * Build per-language config for the meta box script.
	 *
	 * @param \WP_Post|null $post Current post.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_language_script_config( $post ) {
		$post_id   = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$languages = Language_Registry::get_translation_target_languages();
		$config    = array();

		foreach ( $languages as $language ) {
			$lang          = sanitize_key( (string) $language['code'] );
			$thumbnail_id  = $post_id ? Post_Translator::get_translated_thumbnail_id( $post_id, $lang ) : 0;
			$content_key   = Post_Translator::get_meta_key( 'content', $lang );

			$config[] = array(
				'code'        => $lang,
				'label'       => ! empty( $language['native_name'] ) ? (string) $language['native_name'] : $lang,
				'editorId'    => 'polymart_ai_content_' . $lang . '_editor',
				'fields'      => array(
					'title'     => Post_Translator::get_meta_key( 'title', $lang ),
					'excerpt'   => Post_Translator::get_meta_key( 'excerpt', $lang ),
					'content'   => $content_key,
					'thumbnail' => Post_Translator::get_thumbnail_meta_key( $lang ),
				),
				'thumbnail'   => array(
					'id'  => $thumbnail_id,
					'url' => $thumbnail_id > 0 ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '',
				),
				'generateLabel' => sprintf(
					/* translators: %s: language name */
					__( '✨ تولید با AI — %s', 'polymart-ai' ),
					! empty( $language['native_name'] ) ? (string) $language['native_name'] : $lang
				),
			);
		}

		return $config;
	}
}
