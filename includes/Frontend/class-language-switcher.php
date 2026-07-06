<?php
/**
 * Frontend language switcher shortcode with flags from WordPress media.
 *
 * @package PolymartAI\Frontend
 */

namespace PolymartAI\Frontend;

use PolymartAI\Language_Registry;
use PolymartAI\Routing\Url_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Class Language_Switcher
 */
final class Language_Switcher {

	/**
	 * Whether switcher styles have been enqueued.
	 *
	 * @var bool
	 */
	private static $styles_enqueued = false;

	/**
	 * Use dropdown UI when more than this many languages are enabled.
	 */
	const DROPDOWN_THRESHOLD = 2;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 20 );
		add_filter( 'body_class', array( $this, 'filter_body_class' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_direction_styles' ), 999 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'render_canonical_link' ), 1 );
		add_action( 'wp_head', array( $this, 'render_hreflang_links' ), 2 );
	}

	/**
	 * Point canonical URLs at the language-specific version of the current page.
	 *
	 * Skipped when a major SEO plugin is active so it can own canonical tags.
	 *
	 * @param string $canonical_url Existing canonical URL.
	 * @param mixed  $object        Queried object (unused).
	 * @return string
	 */
	public function filter_canonical_url( $canonical_url, $object = null ) {
		unset( $object );

		if ( is_admin() || is_404() || is_search() ) {
			return $canonical_url;
		}

		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return $canonical_url;
		}

		$url = $this->get_url_for_language( Url_Router::get_current_language() );

		return is_string( $url ) && '' !== $url ? $url : $canonical_url;
	}

	/**
	 * Output a canonical link when WordPress core does not print one.
	 *
	 * @return void
	 */
	public function render_canonical_link() {
		if ( is_admin() || is_404() || is_search() ) {
			return;
		}

		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}

		if ( function_exists( 'wp_get_canonical_url' ) && wp_get_canonical_url() ) {
			return;
		}

		$url = $this->get_url_for_language( Url_Router::get_current_language() );

		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( $url )
		);
	}

	/**
	 * Print hreflang alternate links for every enabled language (SEO parity with WPML).
	 *
	 * @return void
	 */
	public function render_hreflang_links() {
		if ( is_admin() || is_404() || is_search() ) {
			return;
		}

		$languages = Language_Registry::get_enabled_languages();

		if ( count( $languages ) < 2 ) {
			return;
		}

		foreach ( $languages as $language ) {
			$code = (string) ( $language['code'] ?? '' );

			if ( '' === $code ) {
				continue;
			}

			$url = $this->get_url_for_language( $code );
			$hreflang = Language_Registry::get_hreflang_code( $code );

			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( $hreflang ),
				esc_url( $url )
			);

			if ( ! empty( $language['is_default'] ) ) {
				printf(
					'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
					esc_url( $url )
				);
			}
		}
	}

	/**
	 * Register the [polymart_language_switcher] shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'polymart_language_switcher', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the language switcher with optional flag images.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$this->enqueue_styles();

		$languages = Language_Registry::get_enabled_languages();
		$current   = Url_Router::get_current_language();

		if ( count( $languages ) < 2 ) {
			return '';
		}

		if ( count( $languages ) > self::DROPDOWN_THRESHOLD ) {
			return $this->render_dropdown_switcher( $languages, $current );
		}

		return $this->render_inline_switcher( $languages, $current );
	}

	/**
	 * Inline pill switcher for two languages.
	 *
	 * @param array<int, array<string, mixed>> $languages Enabled languages.
	 * @param string                          $current   Active language code.
	 * @return string
	 */
	private function render_inline_switcher( array $languages, $current ) {
		ob_start();
		?>
		<nav class="polymart-lang-switcher polymart-lang-switcher--inline" aria-label="<?php esc_attr_e( 'تغییر زبان', 'polymart-ai' ); ?>">
			<?php foreach ( $languages as $language ) : ?>
				<?php echo $this->render_language_link( $language, $current, 'polymart-lang-switcher__link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Dropdown switcher for three or more languages.
	 *
	 * @param array<int, array<string, mixed>> $languages Enabled languages.
	 * @param string                          $current   Active language code.
	 * @return string
	 */
	private function render_dropdown_switcher( array $languages, $current ) {
		$active_language = null;

		foreach ( $languages as $language ) {
			if ( (string) $language['code'] === $current ) {
				$active_language = $language;
				break;
			}
		}

		if ( ! $active_language ) {
			$active_language = $languages[0];
		}

		$uid = 'polymart-lang-dd-' . wp_unique_id();

		ob_start();
		?>
		<div class="polymart-lang-switcher polymart-lang-switcher--dropdown">
			<details class="polymart-lang-switcher__details" id="<?php echo esc_attr( $uid ); ?>">
				<summary class="polymart-lang-switcher__toggle" aria-label="<?php esc_attr_e( 'انتخاب زبان', 'polymart-ai' ); ?>">
					<span class="polymart-lang-switcher__toggle-inner">
						<?php echo $this->render_flag_or_code( $active_language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="polymart-lang-switcher__label"><?php echo esc_html( $this->get_language_label( $active_language ) ); ?></span>
						<span class="polymart-lang-switcher__chevron" aria-hidden="true"></span>
					</span>
				</summary>
				<ul class="polymart-lang-switcher__menu" role="list">
					<?php foreach ( $languages as $language ) : ?>
						<?php
						$code      = (string) $language['code'];
						$is_active = $code === $current;
						$url       = esc_url( $this->get_url_for_language( $code ) );
						$item_class = 'polymart-lang-switcher__menu-item' . ( $is_active ? ' polymart-lang-switcher__menu-item--active' : '' );
						?>
						<li class="<?php echo esc_attr( $item_class ); ?>" role="listitem">
							<a
								href="<?php echo $url; ?>"
								class="polymart-lang-switcher__menu-link"
								lang="<?php echo esc_attr( $code ); ?>"
								hreflang="<?php echo esc_attr( $code ); ?>"
								<?php echo $is_active ? 'aria-current="true"' : ''; ?>
							>
								<?php echo $this->render_flag_or_code( $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="polymart-lang-switcher__menu-text">
									<span class="polymart-lang-switcher__menu-name"><?php echo esc_html( $this->get_language_label( $language ) ); ?></span>
									<?php if ( ! empty( $language['name'] ) && $language['name'] !== $this->get_language_label( $language ) ) : ?>
										<span class="polymart-lang-switcher__menu-sub"><?php echo esc_html( $language['name'] ); ?></span>
									<?php endif; ?>
								</span>
								<?php if ( $is_active ) : ?>
									<span class="polymart-lang-switcher__check" aria-hidden="true"></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a single language anchor for inline mode.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param string             $current  Active code.
	 * @param string             $class    Link class.
	 * @return string
	 */
	private function render_language_link( array $language, $current, $class ) {
		$code       = (string) $language['code'];
		$is_active  = $code === $current;
		$url        = esc_url( $this->get_url_for_language( $code ) );
		$link_class = $class . ( $is_active ? ' ' . $class . '--active' : '' );

		ob_start();
		?>
		<a
			href="<?php echo $url; ?>"
			class="<?php echo esc_attr( $link_class ); ?>"
			lang="<?php echo esc_attr( $code ); ?>"
			hreflang="<?php echo esc_attr( $code ); ?>"
			<?php echo $is_active ? 'aria-current="true"' : ''; ?>
		>
			<?php echo $this->render_flag_or_code( $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="polymart-lang-switcher__label"><?php echo esc_html( $this->get_language_label( $language ) ); ?></span>
		</a>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render flag image or code fallback badge.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	private function render_flag_or_code( array $language ) {
		$flag_id  = (int) ( $language['flag_attachment_id'] ?? 0 );
		$flag_url = $flag_id > 0 ? wp_get_attachment_image_url( $flag_id, 'thumbnail' ) : '';
		$code     = (string) ( $language['code'] ?? '' );

		if ( $flag_url ) {
			return sprintf(
				'<img src="%1$s" alt="" class="polymart-lang-switcher__flag" width="22" height="22" loading="lazy" decoding="async" />',
				esc_url( $flag_url )
			);
		}

		return sprintf(
			'<span class="polymart-lang-switcher__code-badge" aria-hidden="true">%s</span>',
			esc_html( strtoupper( $code ) )
		);
	}

	/**
	 * Display label for a language.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	private function get_language_label( array $language ) {
		if ( ! empty( $language['native_name'] ) ) {
			return (string) $language['native_name'];
		}

		return (string) ( $language['name'] ?? $language['code'] ?? '' );
	}

	/**
	 * Set lang and text direction for non-default language URLs.
	 *
	 * @param string $output Existing language attributes.
	 * @return string
	 */
	public function filter_language_attributes( $output ) {
		if ( is_admin() || ! Url_Router::is_translated_request() ) {
			return $output;
		}

		$language = Language_Registry::get_language( Url_Router::get_current_language() );

		if ( ! $language ) {
			return $output;
		}

		$output = preg_replace( '/\sdir=["\'][^"\']*["\']/', '', $output );
		$output = preg_replace( '/\slang=["\'][^"\']*["\']/', '', $output );

		return trim( $output ) . ' lang="' . esc_attr( $language['code'] ) . '" dir="' . esc_attr( $language['direction'] ) . '"';
	}

	/**
	 * Swap RTL body classes for LTR on LTR language URLs.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function filter_body_class( array $classes ) {
		if ( is_admin() || ! Url_Router::is_translated_request() ) {
			return $classes;
		}

		$language = Language_Registry::get_language( Url_Router::get_current_language() );

		if ( ! $language ) {
			return $classes;
		}

		$classes[] = 'polymart-lang-' . sanitize_html_class( $language['code'] );

		if ( 'ltr' === $language['direction'] ) {
			$classes   = array_diff( $classes, array( 'rtl', 'woodmart-rtl' ) );
			$classes[] = 'ltr';
		}

		return $classes;
	}

	/**
	 * Override theme RTL styles on LTR storefront pages.
	 *
	 * @return void
	 */
	public function enqueue_direction_styles() {
		if ( is_admin() || ! Url_Router::is_translated_request() ) {
			return;
		}

		$language = Language_Registry::get_language( Url_Router::get_current_language() );

		if ( ! $language || 'ltr' !== $language['direction'] ) {
			return;
		}

		wp_register_style( 'polymart-lang-direction', false, array(), POLYMART_AI_VERSION );
		wp_enqueue_style( 'polymart-lang-direction' );
		wp_add_inline_style(
			'polymart-lang-direction',
			'html[dir="ltr"], html.polymart-lang-' . esc_attr( $language['code'] ) . ' { direction: ltr; }
			body.polymart-lang-' . esc_attr( $language['code'] ) . ', body.ltr {
				direction: ltr;
			}
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .website-wrapper,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .wd-page-wrapper,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .wd-header,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .wd-header-main-nav,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .main-page-wrapper,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .entry-content,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .woocommerce {
				direction: ltr;
			}
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .wd-header-main-nav > *,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .wd-nav,
			body.polymart-lang-' . esc_attr( $language['code'] ) . ' .wd-tools-element {
				direction: ltr;
			}'
		);
	}

	/**
	 * Build URL for a specific language preserving the current path.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function get_url_for_language( $code ) {
		$language = Language_Registry::get_language( $code );

		if ( ! $language ) {
			return home_url( '/' ) . $this->get_request_query_suffix();
		}

		$path = $this->get_base_path();
		$query_suffix = $this->get_request_query_suffix();

		if ( empty( $language['url_prefix'] ) ) {
			if ( '' === $path ) {
				return home_url( '/' ) . $query_suffix;
			}

			return home_url( '/' . $path . '/' ) . $query_suffix;
		}

		if ( '' === $path ) {
			return home_url( '/' . $language['url_prefix'] . '/' ) . $query_suffix;
		}

		return home_url( '/' . $language['url_prefix'] . '/' . $path . '/' ) . $query_suffix;
	}

	/**
	 * Preserve the current request query string in alternate language URLs.
	 *
	 * @return string Query suffix including leading ? when present.
	 */
	private function get_request_query_suffix() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$query = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_QUERY );

		return is_string( $query ) && '' !== $query ? '?' . $query : '';
	}

	/**
	 * Get the current request path without any language prefix.
	 *
	 * @return string
	 */
	private function get_base_path() {
		global $wp;

		$path = '';

		if ( isset( $wp ) && is_object( $wp ) && ! empty( $wp->request ) ) {
			$path = trim( (string) $wp->request, '/' );
		} else {
			$path = $this->path_from_request_uri();
		}

		foreach ( Language_Registry::get_routed_languages() as $language ) {
			$prefix = (string) $language['url_prefix'];

			if ( '' === $prefix ) {
				continue;
			}

			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				$path = preg_replace( '#^' . preg_quote( $prefix, '#' ) . '/?#', '', $path );
				break;
			}
		}

		return trim( (string) $path, '/' );
	}

	/**
	 * Fallback path extraction from REQUEST_URI for edge cases.
	 *
	 * @return string
	 */
	private function path_from_request_uri() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return '';
		}

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( is_string( $home_path ) && '' !== $home_path && '/' !== $home_path ) {
			$home_path = trim( $home_path, '/' );

			if ( 0 === strpos( trim( $path, '/' ), $home_path ) ) {
				$path = substr( trim( $path, '/' ), strlen( $home_path ) );
			}
		}

		return trim( $path, '/' );
	}

	/**
	 * Enqueue switcher styles once per request.
	 *
	 * @return void
	 */
	private function enqueue_styles() {
		if ( self::$styles_enqueued ) {
			return;
		}

		wp_register_style( 'polymart-lang-switcher', false, array(), POLYMART_AI_VERSION );
		wp_enqueue_style( 'polymart-lang-switcher' );
		wp_add_inline_style( 'polymart-lang-switcher', $this->get_inline_css() );

		self::$styles_enqueued = true;
	}

	/**
	 * Switcher styles for inline and dropdown modes.
	 *
	 * @return string
	 */
	private function get_inline_css() {
		return '
			.polymart-lang-switcher {
				position: relative;
				display: inline-block;
				font-size: 13px;
				font-weight: 500;
				line-height: 1;
				z-index: 100;
			}

			/* ── Inline (2 languages) ── */
			.polymart-lang-switcher--inline {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 4px;
				border-radius: 999px;
				background: rgba(0, 0, 0, 0.04);
				border: 1px solid rgba(0, 0, 0, 0.06);
			}
			.polymart-lang-switcher__link {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 6px 12px;
				border-radius: 999px;
				color: inherit;
				opacity: 0.65;
				text-decoration: none;
				transition: all 0.2s ease;
			}
			.polymart-lang-switcher__link:hover {
				opacity: 1;
				background: rgba(255, 255, 255, 0.7);
			}
			.polymart-lang-switcher__link--active {
				opacity: 1;
				background: #fff;
				box-shadow: 0 1px 4px rgba(0,0,0,0.1);
			}

			/* ── Shared flag / badge ── */
			.polymart-lang-switcher__flag {
				width: 22px;
				height: 22px;
				border-radius: 50%;
				object-fit: cover;
				box-shadow: 0 1px 3px rgba(0,0,0,0.12);
				border: 2px solid #fff;
				flex-shrink: 0;
			}
			.polymart-lang-switcher__code-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 22px;
				height: 22px;
				border-radius: 50%;
				background: #e5e7eb;
				font-size: 9px;
				font-weight: 700;
				letter-spacing: 0.02em;
				color: #374151;
				flex-shrink: 0;
			}
			.polymart-lang-switcher__label {
				font-size: 12px;
				font-weight: 600;
				letter-spacing: 0.01em;
				white-space: nowrap;
			}

			/* ── Dropdown (3+ languages) ── */
			.polymart-lang-switcher--dropdown {
				display: inline-block;
			}
			.polymart-lang-switcher__details {
				position: relative;
			}
			.polymart-lang-switcher__details > summary {
				list-style: none;
				cursor: pointer;
			}
			.polymart-lang-switcher__details > summary::-webkit-details-marker {
				display: none;
			}
			.polymart-lang-switcher__toggle {
				display: inline-flex;
				align-items: center;
				border-radius: 999px;
				background: rgba(0, 0, 0, 0.04);
				border: 1px solid rgba(0, 0, 0, 0.08);
				padding: 5px 10px 5px 12px;
				transition: all 0.2s ease;
				user-select: none;
			}
			.polymart-lang-switcher__toggle:hover,
			.polymart-lang-switcher__details[open] .polymart-lang-switcher__toggle {
				background: #fff;
				box-shadow: 0 2px 12px rgba(0,0,0,0.1);
				border-color: rgba(0,0,0,0.06);
			}
			.polymart-lang-switcher__toggle-inner {
				display: inline-flex;
				align-items: center;
				gap: 7px;
			}
			.polymart-lang-switcher__chevron {
				display: inline-block;
				width: 0;
				height: 0;
				border-left: 4px solid transparent;
				border-right: 4px solid transparent;
				border-top: 5px solid currentColor;
				opacity: 0.5;
				margin-inline-start: 2px;
				transition: transform 0.2s ease;
			}
			.polymart-lang-switcher__details[open] .polymart-lang-switcher__chevron {
				transform: rotate(180deg);
				opacity: 0.8;
			}
			.polymart-lang-switcher__menu {
				position: absolute;
				top: calc(100% + 8px);
				inset-inline-end: 0;
				min-width: 200px;
				margin: 0;
				padding: 6px;
				list-style: none;
				background: #fff;
				border: 1px solid rgba(0,0,0,0.08);
				border-radius: 14px;
				box-shadow: 0 8px 30px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.06);
				animation: polymart-lang-menu-in 0.18s ease;
				z-index: 9999;
			}
			@keyframes polymart-lang-menu-in {
				from { opacity: 0; transform: translateY(-6px) scale(0.97); }
				to   { opacity: 1; transform: translateY(0) scale(1); }
			}
			.polymart-lang-switcher__menu-item {
				margin: 0;
				padding: 0;
			}
			.polymart-lang-switcher__menu-link {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 9px 12px;
				border-radius: 10px;
				color: inherit;
				text-decoration: none;
				transition: background 0.15s ease;
			}
			.polymart-lang-switcher__menu-link:hover {
				background: rgba(0,0,0,0.04);
			}
			.polymart-lang-switcher__menu-item--active .polymart-lang-switcher__menu-link {
				background: rgba(34, 113, 177, 0.08);
			}
			.polymart-lang-switcher__menu-text {
				display: flex;
				flex-direction: column;
				gap: 1px;
				flex: 1;
				min-width: 0;
			}
			.polymart-lang-switcher__menu-name {
				font-size: 13px;
				font-weight: 600;
				line-height: 1.2;
			}
			.polymart-lang-switcher__menu-sub {
				font-size: 11px;
				opacity: 0.55;
				line-height: 1.2;
			}
			.polymart-lang-switcher__check {
				display: inline-block;
				width: 16px;
				height: 16px;
				flex-shrink: 0;
				background: #2271b1;
				border-radius: 50%;
				position: relative;
			}
			.polymart-lang-switcher__check::after {
				content: "";
				position: absolute;
				top: 3px;
				left: 5px;
				width: 4px;
				height: 7px;
				border: solid #fff;
				border-width: 0 2px 2px 0;
				transform: rotate(45deg);
			}
		';
	}
}
