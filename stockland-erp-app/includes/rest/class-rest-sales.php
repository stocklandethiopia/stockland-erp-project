<?php
/**
 * REST controller source for Stockland ERP operational workflows.
 *
 * @package Stockland_ERP
 */
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Branch sales endpoints. This is where sections 3, 4, 5, 6 and 7 of the
 * business rules actually get enforced in code, not just in the schema:
 *
 *  - Every sale is created from the CURRENT Product Master retail_price
 *    (never from a client-supplied "master price").
 *  - The employee may keep or override selling_price for THIS sale only;
 *    the override is stored per-row and never written back to
 *    wp_stock_products (rule #3).
 *  - vat_mode is a closed choice (VAT_INCLUDED / WITHOUT_VAT); the VAT
 *    RATE itself always comes from Stockland_DB::get_vat_rate() — the
 *    client cannot supply or change it (rule #4).
 *  - A sale only affects live stock once posting_status = CLOSED
 *    (section 7); closing writes exactly one ledger row via
 *    Stockland_Ledger::post_sale_closed().
 *  - Once a sale is CLOSED it cannot be edited through this controller —
 *    corrections must go through /returns as an Adjustment (section 7/15).
 */
class Stockland_REST_Sales extends Stockland_REST_Base {

	/**
	 * Registers the retail POS route callbacks.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) );
	}

	/**
	 * Checks whether the current ERP profile may create a retail POS sale.
	 *
	 * @return bool Whether POS creation is authorized.
	 */
	private function can_create_pos() {
		if ( ! current_user_can( 'stockland_create_sale' ) ) {
			return new WP_Error( 'forbidden', 'Your Stockland role is not authorized to create a retail POS sale.', array( 'status' => 403 ) );
		}
		return Stockland_Authority::assert_profile();
	}

	/**
	 * Registers sales listing, POS creation and day-close routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/sales',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_sales' ),
					'permission_callback' => function () {
						return current_user_can( 'stockland_view_own_branch' ) || current_user_can( 'stockland_view_all_branches' ); },
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_sale' ),
					'permission_callback' => function () {
						return $this->can_create_pos(); },
				),
			)
		);

		// Kept for controlled recovery of legacy OPEN rows created by pre-16.1.39
		// releases. New POS sales are atomically posted as CLOSED and never use this path.
		register_rest_route(
			self::NAMESPACE_V1,
			'/sales/(?P<sale_id>[a-zA-Z0-9\-]+)/close',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'close_sale' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_close_day' ); },
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/daily-closings/close',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'close_day' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_close_day' ); },
			)
		);
	}

	/**
	 * Lists sales within the current user's authorized location scope.
	 *
	 * @param WP_REST_Request $request REST request with location/date filters.
	 * @return mixed REST response containing sales rows or a WP_Error.
	 */
	public function list_sales( WP_REST_Request $request ) {
		$location_id = $this->resolve_branch_for_user( $request->get_param( 'branch_location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}

		global $wpdb;
		$table          = Stockland_DB::table( 'sales' );
		$can_see_profit = current_user_can( 'stockland_view_cost_and_profit' );
		$date           = $request->get_param( 'date' );
		$has_location   = (bool) $location_id;
		$has_date       = (bool) $date;
		$location_value = $has_location ? (int) $location_id : 0;
		$date_value     = $has_date ? (string) $date : '';

		if ( $can_see_profit ) {
			$rows = Stockland_DB::read_results(
				$wpdb->prepare(
					'SELECT sale_id,sale_date,branch_location_id,sku,quantity_sold,size,color,master_price_at_sale,selling_price,price_overridden,vat_mode,vat_rate_used,sales_total,vat_amount,net_sales_excl_vat,posting_status,cogs,gross_profit
					FROM %i
					WHERE (%d = 0 OR branch_location_id = %d)
						AND (%d = 0 OR sale_date = %s)
					ORDER BY sale_date DESC, sale_id DESC LIMIT %d',
					$table,
					$has_location ? 1 : 0,
					$location_value,
					$has_date ? 1 : 0,
					$date_value,
					500
				),
				ARRAY_A
			);
		} else {
			$rows = Stockland_DB::read_results(
				$wpdb->prepare(
					'SELECT sale_id,sale_date,branch_location_id,sku,quantity_sold,size,color,master_price_at_sale,selling_price,price_overridden,vat_mode,vat_rate_used,sales_total,vat_amount,net_sales_excl_vat,posting_status
					FROM %i
					WHERE (%d = 0 OR branch_location_id = %d)
						AND (%d = 0 OR sale_date = %s)
					ORDER BY sale_date DESC, sale_id DESC LIMIT %d',
					$table,
					$has_location ? 1 : 0,
					$location_value,
					$has_date ? 1 : 0,
					$date_value,
					500
				),
				ARRAY_A
			);
		}
		return rest_ensure_response( $rows );
	}

	/**
	 * Validates and posts a retail POS sale with corresponding ledger movements.
	 *
	 * @param WP_REST_Request $request REST request containing sale header and lines.
	 * @return mixed REST response containing the committed sale or a WP_Error.
	 * @throws Exception When the sale transaction cannot satisfy a posting invariant.
	 */
	public function create_sale( WP_REST_Request $request ) {
		$p       = $request->get_json_params();
		$user_id = get_current_user_id();

		foreach ( array( 'sku', 'quantity_sold', 'vat_mode' ) as $field ) {
			if ( ! isset( $p[ $field ] ) || '' === $p[ $field ] ) {
				return new WP_Error( 'missing_field', "Field '{$field}' is required.", array( 'status' => 400 ) );
			}
		}

		$sku = sanitize_text_field( (string) $p['sku'] );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $sku ) ) {
			return new WP_Error( 'invalid_sku', 'SKU must contain 1–64 letters, numbers, dot, underscore or hyphen characters.', array( 'status' => 400 ) );
		}

		$requested_branch   = $p['branch_location_id'] ?? null;
		$branch_location_id = $this->resolve_branch_for_user( $requested_branch );
		if ( is_wp_error( $branch_location_id ) ) {
			return $branch_location_id;
		}
		if ( ! $branch_location_id ) {
			return new WP_Error( 'branch_required', 'branch_location_id is required for this POS role.', array( 'status' => 400 ) );
		}

		$sale_date = isset( $p['sale_date'] ) && '' !== $p['sale_date'] ? sanitize_text_field( $p['sale_date'] ) : current_time( 'Y-m-d' );
		if ( ! Stockland_Business_Calendar::valid_date( $sale_date ) ) {
			return new WP_Error( 'invalid_date', 'sale_date must be a real YYYY-MM-DD date.', array( 'status' => 400 ) );
		}

		try {
			$quantity = (float) $p['quantity_sold'];
			if ( $quantity <= 0 ) {
				throw new InvalidArgumentException( 'quantity_sold must be greater than zero.' );
			}
			$selling_price_input = isset( $p['selling_price'] ) && '' !== $p['selling_price'] ? $p['selling_price'] : null;
			if ( null === $selling_price_input ) {
				$selling_price_input = '0'; // Replaced from live master immediately after row lock.
			}
			if ( ! in_array( strtoupper( (string) $p['vat_mode'] ), array( 'VAT_INCLUDED', 'WITHOUT_VAT' ), true ) ) {
				throw new InvalidArgumentException( "vat_mode must be 'VAT_INCLUDED' or 'WITHOUT_VAT'." );
			}
		} catch ( Throwable $e ) {
			return new WP_Error( 'invalid_sale_input', $e->getMessage(), array( 'status' => 400 ) );
		}

		$sale_id_input = isset( $p['sale_id'] ) && '' !== $p['sale_id'] ? sanitize_text_field( (string) $p['sale_id'] ) : '';
		if ( '' !== $sale_id_input && ( strlen( $sale_id_input ) > 20 || ! preg_match( '/^[A-Za-z0-9-]+$/', $sale_id_input ) ) ) {
			return new WP_Error( 'invalid_sale_id', 'sale_id must be at most 20 letters, numbers or hyphens.', array( 'status' => 400 ) );
		}

		global $wpdb;
		$sales_table = Stockland_DB::table( 'sales' );
		$day_lock    = Stockland_DB::acquire_day_lock( $branch_location_id, $sale_date );
		if ( ! $day_lock['ok'] ) {
			return new WP_Error( 'busy', 'Another POS/inventory operation is being processed for this branch/date. Please retry.', array( 'status' => 409 ) );
		}

		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $day_lock );
			return new WP_Error( 'transaction_failed', 'Could not start the POS sale transaction.', array( 'status' => 500 ) );
		}
		try {
			Stockland_Business_Calendar::assert_business_day( $sale_date );
			if ( Stockland_DB::is_day_closed( $branch_location_id, $sale_date ) ) {
				throw new Exception( 'This business day is already closed.' );
			}

			// Idempotent client retry: an explicitly repeated sale_id must describe the
			// same transaction, otherwise return a conflict rather than double-posting.
			if ( '' !== $sale_id_input ) {
				$existing = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE sale_id=%s LIMIT 1 FOR UPDATE', $sales_table, $sale_id_input ), ARRAY_A );
				if ( $existing ) {
					$requested_price = '0' === $selling_price_input ? $existing['selling_price'] : Stockland_Money::decimal( Stockland_Money::minor( $selling_price_input ) );
					if ( (int) $existing['branch_location_id'] !== (int) $branch_location_id ||
						(string) $existing['sale_date'] !== $sale_date ||
						(string) $existing['sku'] !== $sku ||
						abs( (float) $existing['quantity_sold'] - $quantity ) > 0.00001 ||
						! Stockland_Sale_Math::same_money( $existing['selling_price'], $requested_price ) ||
						strtoupper( (string) $p['vat_mode'] ) !== (string) $existing['vat_mode'] ) {
						throw new Exception( 'sale_id is already used by a different POS transaction.' );
					}
					if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
						throw new Exception( 'POS idempotent retry commit failed.' );
					}
					Stockland_DB::release_day_lock( $day_lock );
					return rest_ensure_response( $existing );
				}
			}

			// Lock the authoritative master row before reading price/cost/variant identity.
			$product = Stockland_DB::read_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE sku=%s AND is_active=1 LIMIT 1 FOR UPDATE',
					Stockland_DB::table( 'products' ),
					$sku
				),
				ARRAY_A
			);
			if ( ! $product || ( $product['product_type'] ?? 'SIMPLE' ) === 'STYLE' ) {
				throw new Exception( 'SKU not found or is not a stockable variant.' );
			}
			if ( $wpdb->last_error ) {
				throw new Exception( 'Product Master lookup failed: ' . $wpdb->last_error );
			}

			$master_price  = Stockland_Money::decimal( Stockland_Money::minor( $product['retail_price'] ) );
			$selling_price = '0' === $selling_price_input ? $master_price : Stockland_Money::decimal( Stockland_Money::minor( $selling_price_input ) );
			$vat_rate      = (float) Stockland_DB::get_vat_rate();
			$calc          = Stockland_Sale_Math::calculate( $quantity, $selling_price, $p['vat_mode'], $vat_rate );
			$cogs          = Stockland_Money::multiply( $quantity, $product['purchase_cost'] );
			$gross_profit  = Stockland_Money::subtract( $calc['net_sales'], $cogs );

			$available = Stockland_DB::get_available_stock( $branch_location_id, $sku );
			if ( $available < $quantity ) {
				throw new Exception( sprintf( 'Insufficient available stock for %s. Available %s, requested %s.', $sku, $available, $quantity ) );
			}

			$sale_id  = '' !== $sale_id_input ? $sale_id_input : Stockland_Sequence::next( 'POS', $sale_date );
			$inserted = Stockland_DB::insert_record(
				$sales_table,
				array(
					'sale_id'              => $sale_id,
					'sale_date'            => $sale_date,
					'branch_location_id'   => $branch_location_id,
					'sku'                  => $sku,
					'quantity_sold'        => $quantity,
					'variant_id'           => (int) $product['variant_id'],
					'size'                 => $product['size'],
					'color'                => $product['color'],
					'barcode'              => $product['barcode'] ? $product['barcode'] : ( $product['gtin'] ?? null ),
					'master_price_at_sale' => $master_price,
					'selling_price'        => $selling_price,
					'price_overridden'     => Stockland_Sale_Math::same_money( $selling_price, $master_price ) ? 0 : 1,
					'vat_mode'             => $calc['vat_mode'],
					'vat_rate_used'        => $vat_rate,
					'sales_total'          => $calc['sales_total'],
					'vat_amount'           => $calc['vat_amount'],
					'net_sales_excl_vat'   => $calc['net_sales'],
					'cogs'                 => $cogs,
					'gross_profit'         => $gross_profit,
					'posting_status'       => 'CLOSED',
					'staff_user_id'        => $user_id,
					'closed_at'            => current_time( 'mysql' ),
					'closed_by'            => $user_id,
					'external_receipt_no'  => isset( $p['external_receipt_no'] ) && '' !== $p['external_receipt_no'] ? sanitize_text_field( (string) $p['external_receipt_no'] ) : null,
				)
			);
			if ( ! $inserted ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not record POS sale.' );
			}

			$sale = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE sale_id=%s LIMIT 1 FOR UPDATE', $sales_table, $sale_id ), ARRAY_A );
			if ( ! $sale ) {
				throw new Exception( 'POS sale was inserted but could not be re-read for posting.' );
			}
			if ( false === Stockland_Ledger::post_sale_closed( $sale, $user_id ) ) {
				throw new Exception( 'Could not post POS sale to the inventory ledger.' );
			}

			$gl = Stockland_Accounting::post_journal(
				$sale_date,
				'POS_SALE',
				$sale_id,
				'Retail POS sale ' . $sale_id,
				array(
					array(
						'account_code' => '1020',
						'debit'        => $sale['sales_total'],
						'credit'       => '0.00',
						'location_id'  => $branch_location_id,
					),
					array(
						'account_code' => '4000',
						'debit'        => '0.00',
						'credit'       => $sale['net_sales_excl_vat'],
						'location_id'  => $branch_location_id,
					),
					array(
						'account_code' => '2100',
						'debit'        => '0.00',
						'credit'       => $sale['vat_amount'],
						'location_id'  => $branch_location_id,
					),
					array(
						'account_code' => '5000',
						'debit'        => $sale['cogs'],
						'credit'       => '0.00',
						'location_id'  => $branch_location_id,
					),
					array(
						'account_code' => '1200',
						'debit'        => '0.00',
						'credit'       => $sale['cogs'],
						'location_id'  => $branch_location_id,
					),
				),
				$user_id
			);
			if ( ! $gl ) {
				throw new Exception( 'POS financial journal could not be posted; transaction rolled back.' );
			}

			if ( ! Stockland_Audit::log( $user_id, $branch_location_id, 'POS_SALE', $sku, $quantity, $master_price, $selling_price, 'Retail POS sale posted atomically', $sale_id, $user_id ) ) {
				throw new Exception( 'POS audit event could not be recorded; transaction rolled back.' );
			}

			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'POS transaction commit failed.' );
			}
			Stockland_DB::release_day_lock( $day_lock );
			$verified = $this->get_sale_row( $sale_id );
			if ( ! $verified || 'CLOSED' !== (string) $verified['posting_status'] ) {
				return new WP_Error( 'pos_verification_failed', 'POS sale posted but final verification did not return a CLOSED transaction.', array( 'status' => 409 ) );
			}
			return rest_ensure_response( $verified );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $day_lock );
			return new WP_Error( 'pos_sale_failed', $e->getMessage(), array( 'status' => 409 ) );
		}
	}

	/**
	 * Closes one sale row by locking it and posting the stock deduction to the ledger.
	 *
	 * @param WP_REST_Request $request REST request identifying the sale to close.
	 * @return mixed REST response containing the closed sale or a WP_Error.
	 * @throws Exception When business-day validation or the guarded transaction fails.
	 */
	public function close_sale( WP_REST_Request $request ) {
		global $wpdb;
		$table     = Stockland_DB::table( 'sales' );
		$user_id   = get_current_user_id();
		$sale_id   = sanitize_text_field( $request['sale_id'] );
		$sale_date = (string) Stockland_DB::read_var( $wpdb->prepare( 'SELECT sale_date FROM %i WHERE sale_id=%s', $table, $sale_id ) );
		if ( ! $sale_date ) {
			return new WP_Error( 'not_found', 'Sale not found.', array( 'status' => 404 ) );
		}
		$sale = $this->get_sale_row( $sale_id );
		if ( ! $sale ) {
			return new WP_Error( 'not_found', 'Sale not found.', array( 'status' => 404 ) );
		}
		$branch_check = $this->resolve_branch_for_user( $sale['branch_location_id'] );
		if ( is_wp_error( $branch_check ) ) {
			return $branch_check;
		}
		$lock = Stockland_DB::acquire_day_lock( $sale['branch_location_id'], $sale_date );
		if ( ! $lock['ok'] ) {
			return new WP_Error( 'busy', 'Another operation is being processed for this location/date. Please retry.', array( 'status' => 409 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start sale-close transaction.', array( 'status' => 500 ) );}
		try {
			if ( Stockland_DB::is_day_closed( $sale['branch_location_id'], $sale_date ) ) {
				throw new Exception( 'This business day is already closed.' );
			}
			$sale = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE sale_id=%s FOR UPDATE', $table, $sale_id ), ARRAY_A );
			if ( ! $sale ) {
				throw new Exception( 'Sale not found.' );
			}
			if ( 'CLOSED' === $sale['posting_status'] ) {
				throw new Exception( 'This sale is already closed and cannot be modified. Use /returns for corrections.' );
			}
			if ( Stockland_DB::get_available_stock( $sale['branch_location_id'], $sale['sku'] ) < (float) $sale['quantity_sold'] ) {
				throw new Exception( 'Insufficient available stock.' );
			}
			if ( false === Stockland_DB::update_record(
				$table,
				array(
					'posting_status' => 'CLOSED',
					'closed_at'      => current_time( 'mysql' ),
					'closed_by'      => $user_id,
				),
				array( 'sale_id' => $sale_id )
			) ) {
				throw new Exception( 'Could not close sale.' );
			}
			if ( false === Stockland_Ledger::post_sale_closed( $sale, $user_id ) ) {
				throw new Exception( 'Could not post sale to inventory ledger.' );
			}
			if ( ! Stockland_Audit::log( $user_id, $sale['branch_location_id'], 'SALE_CLOSE', $sale['sku'], $sale['quantity_sold'], 'OPEN', 'CLOSED', null, $sale_id ) ) {
				throw new Exception( 'Audit event could not be recorded; sale was not committed.' );
			}
			if ( class_exists( 'Stockland_Accounting' ) ) {
				$gl = Stockland_Accounting::post_journal(
					$sale['sale_date'],
					'LEGACY_RETAIL_SALE',
					$sale_id,
					'Legacy retail POS sale ' . $sale_id,
					array(
						array(
							'account_code' => '1020',
							'debit'        => Stockland_Money::decimal( Stockland_Money::minor( $sale['sales_total'] ) ),
							'credit'       => '0.00',
							'location_id'  => $sale['branch_location_id'],
						),
						array(
							'account_code' => '4000',
							'debit'        => '0.00',
							'credit'       => Stockland_Money::decimal( Stockland_Money::minor( $sale['net_sales_excl_vat'] ) ),
							'location_id'  => $sale['branch_location_id'],
						),
						array(
							'account_code' => '2100',
							'debit'        => '0.00',
							'credit'       => Stockland_Money::decimal( Stockland_Money::minor( $sale['vat_amount'] ) ),
							'location_id'  => $sale['branch_location_id'],
						),
						array(
							'account_code' => '5000',
							'debit'        => Stockland_Money::decimal( Stockland_Money::minor( $sale['cogs'] ) ),
							'credit'       => '0.00',
							'location_id'  => $sale['branch_location_id'],
						),
						array(
							'account_code' => '1200',
							'debit'        => '0.00',
							'credit'       => Stockland_Money::decimal( Stockland_Money::minor( $sale['cogs'] ) ),
							'location_id'  => $sale['branch_location_id'],
						),
					),
					$user_id
				);
				if ( ! $gl ) {
					throw new Exception( 'Financial journal could not be posted; sale was not committed.' );
				}
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Sale-close commit failed.' );
			}Stockland_DB::release_day_lock( $lock );
			return rest_ensure_response( $this->get_sale_row( $sale_id ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'close_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * End-of-day: closes every remaining OPEN sale for the branch/date, then
	 * locks the day itself in wp_stock_daily_closings. After this, normal
	 * users cannot edit any transaction from that day (section 7).
	 *
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function close_day( WP_REST_Request $request ) {
		$p                  = $request->get_json_params();
		$branch_location_id = $this->resolve_branch_for_user( $p['branch_location_id'] ?? null );
		if ( is_wp_error( $branch_location_id ) ) {
			return $branch_location_id;
		}
		$business_date = sanitize_text_field( $p['business_date'] ?? current_time( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $business_date ) || ! Stockland_Business_Calendar::valid_date( $business_date ) ) {
			return new WP_Error( 'invalid_date', 'business_date must be a real YYYY-MM-DD date.', array( 'status' => 400 ) );
		}
		try {
			Stockland_Business_Calendar::assert_business_day( $business_date );
		} catch ( Throwable $e ) {
			return new WP_Error( 'business_day_closed', $e->getMessage(), array( 'status' => 409 ) );}
		$user_id = get_current_user_id();
		global $wpdb;
		$closings_table = Stockland_DB::table( 'daily_closings' );
		$lock           = Stockland_DB::acquire_day_lock( $branch_location_id, $business_date );
		if ( ! $lock['ok'] ) {
			return new WP_Error( 'busy', 'Another operation is being processed for this location/date. Please retry.', array( 'status' => 409 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start day-close transaction.', array( 'status' => 500 ) );}
		try {
			$existing = Stockland_DB::read_var( $wpdb->prepare( 'SELECT status FROM %i WHERE branch_location_id=%d AND business_date=%s FOR UPDATE', $closings_table, $branch_location_id, $business_date ) );
			if ( 'CLOSED' === $existing ) {
				throw new Exception( 'This business day is already closed.' );
			}
			$import_jobs    = Stockland_DB::table( 'import_jobs' );
			$pending_import = Stockland_DB::read_row( $wpdb->prepare( "SELECT id,status FROM %i WHERE import_type='DAILY_SALES' AND branch_location_id=%d AND business_date=%s AND status<>'POSTED' ORDER BY id DESC LIMIT 1", $import_jobs, $branch_location_id, $business_date ), ARRAY_A );
			if ( $pending_import ) {
				throw new Exception( 'Daily POS Excel import job #' . $pending_import['id'] . ' is still ' . $pending_import['status'] . '. Complete the import before closing the business day.' );
			}
			$sales_table = Stockland_DB::table( 'sales' );
			$open_sales  = Stockland_DB::read_results( $wpdb->prepare( "SELECT sale_id FROM %i WHERE branch_location_id=%d AND sale_date=%s AND posting_status='OPEN' FOR UPDATE", $sales_table, $branch_location_id, $business_date ), ARRAY_A );
			if ( ! empty( $open_sales ) ) {
				throw new Exception( 'Unposted retail sale records remain for ' . $business_date . '. Import and successfully post the external POS Daily XLSX before closing this business day.' );
			}
			$totals = Stockland_DB::read_row( $wpdb->prepare( "SELECT COALESCE(SUM(sales_total),0) total_sales,COALESCE(SUM(vat_amount),0) total_vat,COALESCE(SUM(gross_profit),0) total_gross_profit FROM %i WHERE branch_location_id=%d AND sale_date=%s AND posting_status='CLOSED'", $sales_table, $branch_location_id, $business_date ), ARRAY_A );
			$ok     = Stockland_DB::execute_query( $wpdb->prepare( "INSERT INTO %i (branch_location_id,business_date,status,closed_at,closed_by,total_sales,total_vat,total_gross_profit) VALUES(%d,%s,'CLOSED',%s,%d,%f,%f,%f) ON DUPLICATE KEY UPDATE status='CLOSED',closed_at=VALUES(closed_at),closed_by=VALUES(closed_by),total_sales=VALUES(total_sales),total_vat=VALUES(total_vat),total_gross_profit=VALUES(total_gross_profit)", $closings_table, $branch_location_id, $business_date, current_time( 'mysql' ), $user_id, $totals['total_sales'], $totals['total_vat'], $totals['total_gross_profit'] ) );
			if ( false === $ok ) {
				throw new Exception( 'Could not close business day.' );
			}
			if ( ! Stockland_Audit::log( $user_id, $branch_location_id, 'DAY_CLOSE', null, null, 'OPEN', 'CLOSED', null, $business_date ) ) {
				throw new Exception( 'Audit event could not be recorded; day close was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Day-close commit failed.' );
			}Stockland_DB::release_day_lock( $lock );
			return rest_ensure_response(
				array(
					'branch_location_id' => $branch_location_id,
					'business_date'      => $business_date,
					'sales_closed'       => count( $open_sales ),
					'totals'             => $totals,
				)
			);
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'day_close_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Retrieves a sale row by its stable sale identifier.
	 *
	 * @param string $sale_id Sale identifier.
	 * @return array|null Sale row or null when missing.
	 */
	private function get_sale_row( $sale_id ) {
		global $wpdb;
		$table = Stockland_DB::table( 'sales' );
		return Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE sale_id = %s', $table, $sale_id ), ARRAY_A );
	}

	/**
	 * Generates the next sale identifier for a branch and business date.
	 *
	 * @param int         $branch_location_id Branch/location identifier.
	 * @param string|null $sale_date Optional business date.
	 * @return string Generated sale identifier.
	 */
	private static function generate_sale_id( $branch_location_id, $sale_date = null ) {
		global $wpdb;
		$locations = Stockland_DB::table( 'locations' );
		$name      = Stockland_DB::read_var( $wpdb->prepare( 'SELECT location_name FROM %i WHERE id = %d', $locations, $branch_location_id ) );
		$prefix    = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $name ? $name : 'BR' ), 0, 3 ) );
		return Stockland_Sequence::next( 'SAL-' . sanitize_key( $prefix ), $sale_date );
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
