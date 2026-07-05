<?php
/**
 * Batch sync of WooCommerce Toman prices into stored USD meta.
 *
 * @package PolymartAI
 */

namespace PolymartAI\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Class Currency_Price_Sync
 */
final class Currency_Price_Sync {

	const JOB_OPTION          = 'polymart_ai_currency_sync_job';
	const SYNC_STATUS_OPTION  = 'polymart_ai_currency_sync_status';
	const META_USD_PRICE      = '_polymart_ai_usd_price';
	const META_USD_REGULAR    = '_polymart_ai_usd_regular_price';
	const META_USD_SALE       = '_polymart_ai_usd_sale_price';
	const META_USD_RATE       = '_polymart_ai_usd_rate_used';
	const META_USD_SYNCED_AT  = '_polymart_ai_usd_synced_at';
	const META_USD_PRICE_HTML = '_polymart_ai_usd_price_html';
	const SYNC_CRON_HOOK      = 'polymart_ai_currency_sync_continue';
	const DEFAULT_BATCH_SIZE  = 30;
	const CRON_BATCH_SIZE     = 40;

	/**
	 * Register sync hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::SYNC_CRON_HOOK, array( __CLASS__, 'cron_continue_sync' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'sync_product_on_save' ), 99, 1 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'sync_product_on_save' ), 99, 2 );
	}

	/**
	 * Clear scheduled sync continuation events.
	 *
	 * @return void
	 */
	public static function unschedule_sync_cron() {
		wp_clear_scheduled_hook( self::SYNC_CRON_HOOK );
	}

	/**
	 * Restart price sync when the exchange rate changes.
	 *
	 * @param float|null $previous_rate Rate before refresh.
	 * @return array<string, mixed>
	 */
	public static function kickoff_after_rate_refresh( $previous_rate = null ) {
		$rate = Currency::get_rate();

		if ( $rate <= 0 ) {
			return array(
				'started' => false,
				'reason'  => 'no_rate',
				'job'     => self::get_job(),
			);
		}

		if ( null !== $previous_rate && $previous_rate > 0 && abs( (float) $previous_rate - $rate ) < 0.0001 ) {
			return array(
				'started' => false,
				'reason'  => 'rate_unchanged',
				'job'     => self::get_job(),
			);
		}

		self::start_job();
		self::schedule_sync_continuation( 15 );

		return array(
			'started' => true,
			'job'     => self::get_job(),
		);
	}

	/**
	 * Schedule a single WP-Cron tick to continue a running sync job.
	 *
	 * @param int $delay_seconds Seconds until the next tick.
	 * @return void
	 */
	public static function schedule_sync_continuation( $delay_seconds = 30 ) {
		if ( wp_next_scheduled( self::SYNC_CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event(
			time() + max( 10, absint( $delay_seconds ) ),
			self::SYNC_CRON_HOOK
		);
	}

	/**
	 * WP-Cron callback — continue sync until the job completes.
	 *
	 * @return void
	 */
	public static function cron_continue_sync() {
		self::run_background_sync( 55 );

		$job = self::get_job();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			self::schedule_sync_continuation( 30 );
		}
	}

	/**
	 * Sync one product immediately after a WooCommerce save.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function sync_product_on_save( $product_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( Currency::get_rate() <= 0 ) {
			return;
		}

		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$rate = Currency::get_rate();
		$result = self::sync_product( $product_id, $rate );

		if ( is_wp_error( $result ) ) {
			return;
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );

			if ( $product && $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();

				if ( $parent_id > 0 && class_exists( '\WC_Product_Variable' ) ) {
					\WC_Product_Variable::sync( $parent_id );
				}
			}
		}
	}

	/**
	 * Get normalized sync job state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_job() {
		$job = get_option( self::JOB_OPTION, array() );

		if ( ! is_array( $job ) || empty( $job ) ) {
			return self::empty_job();
		}

		return self::normalize_job( $job );
	}

	/**
	 * Aggregate sync stats for admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_stats() {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts}
			WHERE post_type IN ('product','product_variation')
			AND post_status IN ('publish','private')"
		);

		$synced = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				self::META_USD_PRICE
			)
		);

		$status = get_option( self::SYNC_STATUS_OPTION, array() );

		return array(
			'total_products'    => $total,
			'synced_products'   => $synced,
			'pending_products'  => max( 0, $total - $synced ),
			'last_full_sync_at' => (string) ( $status['last_full_sync_at'] ?? '' ),
			'last_full_sync_human' => ! empty( $status['last_full_sync_at'] )
				? wp_date( 'Y/m/d H:i', strtotime( (string) $status['last_full_sync_at'] ) )
				: '',
			'last_rate_used'    => isset( $status['last_rate_used'] ) ? (float) $status['last_rate_used'] : null,
		);
	}

	/**
	 * Start or restart a full price sync job.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start_job() {
		$rate = Currency::get_rate();

		if ( $rate <= 0 ) {
			return new \WP_Error(
				'polymart_ai_currency_no_rate',
				__( 'ابتدا نرخ دلار را دریافت و ذخیره کنید.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$stats = self::get_stats();
		$job   = array(
			'status'       => 'running',
			'total'        => (int) $stats['total_products'],
			'processed'    => 0,
			'succeeded'    => 0,
			'failed'       => 0,
			'offset'       => 0,
			'rate'         => $rate,
			'started_at'   => time(),
			'updated_at'   => time(),
			'progress_pct' => 0,
			'last_step'    => null,
		);

		self::save_job( $job );

		return self::get_job();
	}

	/**
	 * Pause a running job.
	 *
	 * @return array<string, mixed>
	 */
	public static function pause_job() {
		$job = self::get_job();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			$job['status']     = 'paused';
			$job['updated_at'] = time();
			self::save_job( $job );
		}

		return $job;
	}

	/**
	 * Resume a paused job.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function resume_job() {
		$job = self::get_job();

		if ( 'paused' !== ( $job['status'] ?? '' ) ) {
			return new \WP_Error(
				'polymart_ai_currency_job_not_paused',
				__( 'کار تبدیل قیمت در حالت توقف نیست.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$job['status']     = 'running';
		$job['updated_at'] = time();
		self::save_job( $job );

		return self::get_job();
	}

	/**
	 * Reset job state.
	 *
	 * @return array<string, mixed>
	 */
	public static function reset_job() {
		self::save_job( self::empty_job() );

		return self::get_job();
	}

	/**
	 * Process the next batch of products.
	 *
	 * @param int $batch_size Items per step.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function process_step( $batch_size = self::DEFAULT_BATCH_SIZE ) {
		$job = self::get_job();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return $job;
		}

		$rate = (float) ( $job['rate'] ?? 0 );

		if ( $rate <= 0 ) {
			$rate = Currency::get_rate();
		}

		if ( $rate <= 0 ) {
			$job['status'] = 'paused';
			self::save_job( $job );

			return new \WP_Error(
				'polymart_ai_currency_no_rate',
				__( 'نرخ دلار موجود نیست — job متوقف شد.', 'polymart-ai' ),
				array( 'status' => 400 )
			);
		}

		$batch_size = max( 1, min( 50, absint( $batch_size ) ) );
		$ids        = self::query_product_ids( (int) $job['offset'], $batch_size );
		$titles     = self::get_product_titles( $ids );

		if ( empty( $ids ) ) {
			$job['status']       = 'completed';
			$job['progress_pct'] = 100;
			$job['updated_at']   = time();
			self::save_job( $job );
			self::mark_full_sync_complete( $rate );
			self::flush_product_caches();
			self::unschedule_sync_cron();

			return $job;
		}

		$variable_parents = array();

		foreach ( $ids as $product_id ) {
			$result = self::sync_product( (int) $product_id, $rate, $variable_parents );
			$title  = $titles[ (int) $product_id ] ?? (string) get_the_title( (int) $product_id );

			$job['processed']++;
			$job['offset']++;

			if ( is_wp_error( $result ) ) {
				$job['failed']++;
				$job['last_step'] = array(
					'product_id' => (int) $product_id,
					'title'      => $title,
					'status'     => 'failed',
					'message'    => $result->get_error_message(),
				);
			} else {
				$job['succeeded']++;
				$job['last_step'] = array(
					'product_id' => (int) $product_id,
					'title'      => (string) ( $result['title'] ?? $title ),
					'status'     => 'synced',
					'usd_price'  => $result['usd_price'] ?? null,
					'message'    => sprintf(
						/* translators: 1: product title, 2: USD price */
						__( '%1$s → $%2$s', 'polymart-ai' ),
						(string) ( $result['title'] ?? $title ),
						(string) ( $result['usd_price'] ?? '' )
					),
				);
			}
		}

		self::sync_variable_parents( $variable_parents );

		$total = max( 1, (int) $job['total'] );
		$job['progress_pct'] = min( 100, (int) round( ( (int) $job['offset'] / $total ) * 100 ) );
		$job['updated_at']   = time();
		$job['rate']         = $rate;

		if ( (int) $job['offset'] >= (int) $job['total'] ) {
			$job['status']       = 'completed';
			$job['progress_pct'] = 100;
			self::mark_full_sync_complete( $rate );
			self::flush_product_caches();
			self::unschedule_sync_cron();
		}

		self::save_job( $job );

		return $job;
	}

	/**
	 * Run multiple batches within a time budget (WP-Cron).
	 *
	 * @param int $time_limit_seconds Max runtime.
	 * @return void
	 */
	public static function run_background_sync( $time_limit_seconds = 50 ) {
		$rate = Currency::get_rate();

		if ( $rate <= 0 ) {
			return;
		}

		$job = self::get_job();

		if ( in_array( $job['status'], array( 'idle', 'completed' ), true ) ) {
			self::start_job();
		} elseif ( 'paused' === $job['status'] ) {
			self::resume_job();
		}

		$deadline = time() + max( 5, absint( $time_limit_seconds ) );

		while ( time() < $deadline ) {
			$job = self::get_job();

			if ( 'running' !== ( $job['status'] ?? '' ) ) {
				break;
			}

			$before_offset = (int) $job['offset'];
			$result        = self::process_step( self::CRON_BATCH_SIZE );

			if ( is_wp_error( $result ) ) {
				break;
			}

			if ( 'completed' === ( $result['status'] ?? '' ) ) {
				self::unschedule_sync_cron();
				break;
			}

			if ( (int) $result['offset'] === $before_offset ) {
				break;
			}
		}

		$job = self::get_job();

		if ( 'running' === ( $job['status'] ?? '' ) ) {
			self::schedule_sync_continuation( 30 );
		}
	}

	/**
	 * Sync one product or variation to USD meta.
	 *
	 * @param int   $product_id Product ID.
	 * @param float $rate       Toman per USD.
	 * @param array<int, bool> $variable_parent_ids Parent IDs to sync after batch.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function sync_product( $product_id, $rate, &$variable_parent_ids = null ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || $rate <= 0 ) {
			return new \WP_Error( 'polymart_ai_invalid_product', __( 'محصول یا نرخ نامعتبر است.', 'polymart-ai' ) );
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'polymart_ai_no_woocommerce', __( 'ووکامرس فعال نیست.', 'polymart-ai' ) );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new \WP_Error( 'polymart_ai_missing_product', __( 'محصول یافت نشد.', 'polymart-ai' ) );
		}

		Currency::set_sync_context( true );

		$regular_raw = (string) get_post_meta( $product_id, '_regular_price', true );
		$sale_raw    = (string) get_post_meta( $product_id, '_sale_price', true );
		$price_raw   = (string) get_post_meta( $product_id, '_price', true );

		$usd_regular = self::convert_amount( $regular_raw, $rate );
		$usd_sale    = self::convert_amount( $sale_raw, $rate );
		$usd_price   = self::convert_amount( $price_raw, $rate );

		if ( '' === $usd_price && '' === $usd_regular ) {
			delete_post_meta( $product_id, self::META_USD_PRICE );
			delete_post_meta( $product_id, self::META_USD_REGULAR );
			delete_post_meta( $product_id, self::META_USD_SALE );
			delete_post_meta( $product_id, self::META_USD_RATE );
			delete_post_meta( $product_id, self::META_USD_SYNCED_AT );
			delete_post_meta( $product_id, self::META_USD_PRICE_HTML );
			Currency::set_sync_context( false );

			return array(
				'title'     => $product->get_name(),
				'usd_price' => null,
				'skipped'   => true,
			);
		}

		self::update_meta( $product_id, self::META_USD_REGULAR, $usd_regular );
		self::update_meta( $product_id, self::META_USD_SALE, $usd_sale );
		self::update_meta( $product_id, self::META_USD_PRICE, $usd_price );
		update_post_meta( $product_id, self::META_USD_RATE, $rate );
		update_post_meta( $product_id, self::META_USD_SYNCED_AT, wp_date( 'c' ) );
		update_post_meta( $product_id, self::META_USD_PRICE_HTML, self::build_usd_price_html( $product_id, $usd_regular, $usd_sale, $usd_price ) );

		Currency::set_sync_context( false );

		if ( is_array( $variable_parent_ids ) ) {
			if ( $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();

				if ( $parent_id > 0 ) {
					$variable_parent_ids[ $parent_id ] = true;
				}
			} elseif ( $product->is_type( 'variable' ) ) {
				$variable_parent_ids[ $product_id ] = true;
			}
		}

		return array(
			'title'     => $product->get_name(),
			'usd_price' => $usd_price,
		);
	}

	/**
	 * Read stored USD price meta for a product.
	 *
	 * @param \WC_Product $product Product object.
	 * @param string      $type    price|regular|sale.
	 * @return float|null
	 */
	public static function get_stored_usd_price( $product, $type = 'price' ) {
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$key_map = array(
			'price'    => self::META_USD_PRICE,
			'regular'  => self::META_USD_REGULAR,
			'sale'     => self::META_USD_SALE,
		);

		$meta_key = $key_map[ $type ] ?? self::META_USD_PRICE;
		$stored   = get_post_meta( $product->get_id(), $meta_key, true );

		if ( '' === $stored || ! is_numeric( $stored ) ) {
			return null;
		}

		return (float) $stored;
	}

	/**
	 * Read pre-rendered USD price HTML for storefront cards.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	public static function get_stored_usd_price_html( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$html = get_post_meta( $product->get_id(), self::META_USD_PRICE_HTML, true );

		return is_string( $html ) ? trim( $html ) : '';
	}

	/**
	 * Build formatted USD price HTML for storage.
	 *
	 * @param int    $product_id  Product ID.
	 * @param string $usd_regular Regular USD price.
	 * @param string $usd_sale    Sale USD price.
	 * @param string $usd_price   Active USD price.
	 * @return string
	 */
	private static function build_usd_price_html( $product_id, $usd_regular, $usd_sale, $usd_price ) {
		unset( $product_id );

		$args = array(
			'currency'           => 'USD',
			'decimal_separator'    => '.',
			'thousand_separator' => ',',
			'decimals'             => 2,
			'price_format'         => '%1$s%2$s',
		);

		if ( '' !== $usd_sale && '' !== $usd_regular && (float) $usd_sale < (float) $usd_regular ) {
			return wc_format_sale_price(
				wc_price( (float) $usd_regular, $args ),
				wc_price( (float) $usd_sale, $args )
			);
		}

		if ( '' !== $usd_price ) {
			return wc_price( (float) $usd_price, $args );
		}

		if ( '' !== $usd_regular ) {
			return wc_price( (float) $usd_regular, $args );
		}

		return '';
	}

	/**
	 * Convert Toman string to USD string for storage.
	 *
	 * @param string $raw  Toman amount.
	 * @param float  $rate Exchange rate.
	 * @return string
	 */
	private static function convert_amount( $raw, $rate ) {
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return '';
		}

		$amount = (float) $raw;

		if ( $amount <= 0 ) {
			return '';
		}

		return (string) round( $amount / $rate, 2 );
	}

	/**
	 * Update or delete empty meta.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $key        Meta key.
	 * @param string $value      Meta value.
	 * @return void
	 */
	private static function update_meta( $product_id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $product_id, $key );
			return;
		}

		update_post_meta( $product_id, $key, $value );
	}

	/**
	 * Query product IDs for batch processing.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return array<int, int>
	 */
	private static function query_product_ids( $offset, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ('product','product_variation')
				AND post_status IN ('publish','private')
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return array_map( 'absint', $rows ?: array() );
	}

	/**
	 * Batch-load post titles for sync progress UI.
	 *
	 * @param array<int, int> $ids Product IDs.
	 * @return array<int, string>
	 */
	private static function get_product_titles( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$id_list = implode( ',', $ids );
		$rows    = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$id_list})",
			ARRAY_A
		);

		$titles = array();

		foreach ( $rows ?: array() as $row ) {
			$titles[ (int) $row['ID'] ] = (string) $row['post_title'];
		}

		return $titles;
	}

	/**
	 * Sync variable parent min/max prices once per batch.
	 *
	 * @param array<int, bool> $variable_parent_ids Parent product IDs.
	 * @return void
	 */
	private static function sync_variable_parents( array $variable_parent_ids ) {
		if ( empty( $variable_parent_ids ) || ! class_exists( '\WC_Product_Variable' ) ) {
			return;
		}

		foreach ( array_keys( $variable_parent_ids ) as $parent_id ) {
			$parent_id = absint( $parent_id );

			if ( $parent_id > 0 ) {
				\WC_Product_Variable::sync( $parent_id );
			}
		}
	}

	/**
	 * Persist job state.
	 *
	 * @param array<string, mixed> $job Job data.
	 * @return void
	 */
	private static function save_job( array $job ) {
		update_option( self::JOB_OPTION, self::normalize_job( $job ), false );
	}

	/**
	 * Default idle job.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_job() {
		return array(
			'status'       => 'idle',
			'total'        => 0,
			'processed'    => 0,
			'succeeded'    => 0,
			'failed'       => 0,
			'offset'       => 0,
			'rate'         => 0,
			'progress_pct' => 0,
			'started_at'   => 0,
			'updated_at'   => 0,
			'last_step'    => null,
		);
	}

	/**
	 * Normalize job payload.
	 *
	 * @param array<string, mixed> $job Raw job.
	 * @return array<string, mixed>
	 */
	private static function normalize_job( array $job ) {
		$job = wp_parse_args( $job, self::empty_job() );

		$total  = max( 0, (int) $job['total'] );
		$offset = max( 0, (int) $job['offset'] );

		if ( $total > 0 ) {
			$job['progress_pct'] = min( 100, (int) round( ( $offset / $total ) * 100 ) );
		}

		if ( 'completed' === $job['status'] ) {
			$job['progress_pct'] = 100;
		}

		$job['remaining'] = max( 0, $total - $offset );

		return $job;
	}

	/**
	 * Record successful full sync metadata.
	 *
	 * @param float $rate Rate used.
	 * @return void
	 */
	private static function mark_full_sync_complete( $rate ) {
		update_option(
			self::SYNC_STATUS_OPTION,
			array(
				'last_full_sync_at' => wp_date( 'c' ),
				'last_rate_used'    => (float) $rate,
			),
			false
		);
	}

	/**
	 * Flush WooCommerce product transients after bulk sync.
	 *
	 * @return void
	 */
	private static function flush_product_caches() {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		delete_transient( 'wc_products_onsale' );
	}
}
