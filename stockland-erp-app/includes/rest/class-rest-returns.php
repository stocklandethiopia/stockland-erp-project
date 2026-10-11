<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns, damage, and adjustments (section 12 + the correction path
 * referenced by sections 7 and 15). Every row REQUIRES a reason —
 * enforced here, matching the sheet's own control rule: "Every
 * adjustment must have a reason." Posts to the ledger immediately
 * (no OPEN/CLOSED gate — these are already-reviewed corrections).
 */
class Stockland_REST_Returns extends Stockland_REST_Base {

	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/returns',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_returns' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_view_returns(); },
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_return' ),
					'permission_callback' => function () {
						return current_user_can( 'stockland_record_return' ); },
				),
			)
		);
	}

	public function list_returns( WP_REST_Request $request ) {
		$location_id = $this->resolve_branch_for_user( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		global $wpdb;
		$table = Stockland_DB::table( 'returns' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE (%d = 0 OR location_id = %d) ORDER BY created_at DESC LIMIT %d',
				$table,
				$location_id ? 1 : 0,
				$location_id ? (int) $location_id : 0,
				500
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}

	public function create_return( WP_REST_Request $request ) {
		$p = $request->get_json_params();
		foreach ( array( 'location_id', 'return_type', 'sku', 'quantity', 'reason' ) as $field ) {
			if ( empty( $p[ $field ] ) ) {
				return new WP_Error( 'missing_field', "Field '{$field}' is required (reason is mandatory for every return/adjustment).", array( 'status' => 400 ) );
			}
		}
		if ( ! in_array( $p['return_type'], array( 'Return', 'Damage', 'Adjustment' ), true ) ) {
			return new WP_Error( 'invalid_type', "return_type must be 'Return', 'Damage', or 'Adjustment'.", array( 'status' => 400 ) );
		}

		$location_id = $this->resolve_branch_for_user( $p['location_id'] );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		$sku     = sanitize_text_field( (string) $p['sku'] );
		$product = Stockland_DB::get_product( $sku );
		if ( ! $product || empty( $product['variant_id'] ) ) {
			return new WP_Error( 'invalid_sku', 'SKU does not resolve to an active Product Master variant.', array( 'status' => 409 ) );
		}

		global $wpdb;
		$table        = Stockland_DB::table( 'returns' );
		$user_id      = get_current_user_id();
		$current_date = current_time( 'Y-m-d' );
		$return_date  = $p['return_date'] ?? $current_date;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $return_date ) ) {
			return new WP_Error( 'invalid_date', 'return_date must be YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		$date_obj = DateTime::createFromFormat( '!Y-m-d', (string) $return_date );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== (string) $return_date ) {
			return new WP_Error( 'invalid_date', 'return_date is not a valid calendar date.', array( 'status' => 400 ) );
		}
		if ( (string) $return_date > $current_date ) {
			return new WP_Error( 'future_date', 'Return/damage date cannot be in the future.', array( 'status' => 400 ) );
		}
		if ( 'Adjustment' !== $p['return_type'] && Stockland_DB::is_day_closed( $location_id, (string) $return_date ) ) {
			return new WP_Error( 'closed_date', 'The selected business date is already closed. Use the controlled post-close adjustment/reversal workflow.', array( 'status' => 409 ) );
		}
		$lock_date = 'Adjustment' === $p['return_type'] ? $current_date : (string) $return_date;
		$lock      = Stockland_DB::acquire_day_lock( $location_id, $lock_date );
		if ( ! $lock['ok'] ) {
			return new WP_Error( 'busy', 'Another inventory operation is being processed for this location/date. Please retry.', array( 'status' => 409 ) );
		}

		if ( 'Adjustment' === $p['return_type'] ) {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				Stockland_DB::release_day_lock( $lock );
				return new WP_Error( 'transaction_failed', 'Could not start adjustment transaction.', array( 'status' => 500 ) );}
			try {
				$system = Stockland_DB::get_physical_stock( $location_id, sanitize_text_field( $p['sku'] ) );
				$sign   = isset( $p['adjustment_sign'] ) && (int) $p['adjustment_sign'] < 0 ? -1 : 1;
				$delta  = $sign * abs( (float) $p['quantity'] );
				$aid    = Stockland_Sequence::next( 'ADJ-', $current_date );
				$ok     = $wpdb->insert(
					Stockland_DB::table( 'adjustments' ),
					array(
						'adjustment_id' => $aid,
						'location_id'   => $location_id,
						'sku'           => $sku,
						'variant_id'    => (int) $product['variant_id'],
						'counted_qty'   => $system + $delta,
						'system_qty'    => $system,
						'variance_qty'  => $delta,
						'reason'        => sanitize_text_field( $p['reason'] ),
						'status'        => 'Pending',
						'requested_by'  => $user_id,
					)
				);
				if ( false === $ok ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not create adjustment request.' );
				}
				if ( class_exists( 'Stockland_Workflow' ) && ! Stockland_Workflow::event(
					'adjustment',
					$aid,
					null,
					'Pending',
					$user_id,
					$location_id,
					array(
						'system_qty'   => $system,
						'counted_qty'  => $system + $delta,
						'variance_qty' => $delta,
						'reason'       => $p['reason'],
					)
				) ) {
					throw new Exception( 'Adjustment workflow event could not be recorded.' );
				}
				if ( ! Stockland_Audit::log( $user_id, $location_id, 'ADJUSTMENT_REQUEST', sanitize_text_field( $p['sku'] ), $delta, (string) $system, (string) ( $system + $delta ), $p['reason'], $aid ) ) {
					throw new Exception( 'Audit event could not be recorded.' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new Exception( 'Adjustment commit failed.' );
				}
				Stockland_DB::release_day_lock( $lock );
				return rest_ensure_response(
					array(
						'adjustment_id'      => $aid,
						'status'             => 'Pending',
						'system_qty'         => $system,
						'requested_variance' => $delta,
						'message'            => 'Adjustment submitted for Management approval.',
					)
				);
			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				Stockland_DB::release_day_lock( $lock );
				return new WP_Error( 'adjustment_failed', $e->getMessage(), array( 'status' => 409 ) );
			}
		}

		if ( 'Damage' === $p['return_type'] && Stockland_DB::get_available_stock( $location_id, $sku ) < (float) $p['quantity'] ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'insufficient_stock', 'Insufficient available stock for this damage quantity.', array( 'status' => 409 ) ); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start return transaction.', array( 'status' => 500 ) );}
		try {
			$reference_sale_id = sanitize_text_field( $p['reference_sale_id'] ?? '' );
			$wpdb->insert(
				$table,
				array(
					'return_date'       => $return_date,
					'location_id'       => $location_id,
					'return_type'       => $p['return_type'],
					'sku'               => $sku,
					'variant_id'        => (int) $product['variant_id'],
					'quantity'          => (float) $p['quantity'],
					'reason'            => sanitize_text_field( $p['reason'] ),
					'reference_sale_id' => $reference_sale_id ? $reference_sale_id : null,
					'created_by'        => $user_id,
					'approver_id'       => user_can( $user_id, 'stockland_manage_products' ) ? $user_id : null,
				)
			);
			$id = $wpdb->insert_id;
			if ( ! $id ) {
				throw new Exception( 'Could not record return.' );
			}

			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
			if ( 'Adjustment' === $p['return_type'] ) {
				return rest_ensure_response( $row ); // protected above; retained for defensive compatibility
			}

			if ( false === Stockland_Ledger::post_return( $row, $user_id ) ) {
				throw new Exception( 'Could not post return to stock ledger.' );
			}
			if ( class_exists( 'Stockland_Accounting' ) ) {
				$sale       = null;
				$prod       = Stockland_DB::get_product( $row['sku'] );
				$unit_cost  = (float) ( $prod['purchase_cost'] ?? 0 );
				$unit_price = (float) ( $prod['retail_price'] ?? 0 );
				if ( ! empty( $row['reference_sale_id'] ) ) {
					$sale = $wpdb->get_row( $wpdb->prepare( 'SELECT selling_price,sales_total,net_sales_excl_vat,vat_amount,cogs,quantity_sold FROM %i WHERE sale_id=%s AND (variant_id=%d OR (variant_id IS NULL AND sku=%s)) LIMIT 1', Stockland_DB::table( 'sales' ), $row['reference_sale_id'], (int) $row['variant_id'], $row['sku'] ), ARRAY_A );
					if ( $sale ) {
						$unit_price = Stockland_Money::divide( $sale['sales_total'], $sale['quantity_sold'] );
						$unit_cost  = Stockland_Money::divide( $sale['cogs'], $sale['quantity_sold'] );}
				}
				$q     = (float) $row['quantity'];
				$gross = Stockland_Money::multiply( $q, $unit_price );
				$net   = Stockland_Money::subtract( $gross, Stockland_Money::inclusive_tax( $gross, Stockland_DB::get_vat_rate() ) );
				$vat   = Stockland_Money::inclusive_tax( $gross, Stockland_DB::get_vat_rate() );
				$cogs  = Stockland_Money::multiply( $q, $unit_cost );
				if ( ! empty( $sale ) ) {
					$net   = Stockland_Money::proportion_of_quantity( $sale['net_sales_excl_vat'], $q, $sale['quantity_sold'] );
					$vat   = Stockland_Money::proportion_of_quantity( $sale['vat_amount'], $q, $sale['quantity_sold'] );
					$gross = Stockland_Money::add( $net, $vat ); }
				if ( 'Return' === $row['return_type'] ) {
					$gl = Stockland_Accounting::post_journal(
						$row['return_date'],
						'SALES_RETURN',
						(string) $id,
						'Sales return ' . $id,
						array(
							array(
								'account_code' => '4000',
								'debit'        => $net,
								'credit'       => 0,
								'location_id'  => $location_id,
							),
							array(
								'account_code' => '2100',
								'debit'        => $vat,
								'credit'       => 0,
								'location_id'  => $location_id,
							),
							array(
								'account_code' => '1000',
								'debit'        => 0,
								'credit'       => $gross,
								'location_id'  => $location_id,
							),
							array(
								'account_code' => '1200',
								'debit'        => $cogs,
								'credit'       => 0,
								'location_id'  => $location_id,
							),
							array(
								'account_code' => '5000',
								'debit'        => 0,
								'credit'       => $cogs,
								'location_id'  => $location_id,
							),
						),
						$user_id
					);
				} else {
					$gl = Stockland_Accounting::post_journal(
						$row['return_date'],
						'DAMAGE_LOSS',
						(string) $id,
						'Inventory damage ' . $id,
						array(
							array(
								'account_code' => '5000',
								'debit'        => $cogs,
								'credit'       => 0,
								'location_id'  => $location_id,
							),
							array(
								'account_code' => '1200',
								'debit'        => 0,
								'credit'       => $cogs,
								'location_id'  => $location_id,
							),
						),
						$user_id
					);
				}
				if ( ! $gl ) {
					throw new Exception( 'Financial return/damage journal failed.' );
				}
			}
			if ( ! Stockland_Audit::log( $user_id, $location_id, 'RETURN_RECORD_' . strtoupper( $p['return_type'] ), $p['sku'], $p['quantity'], null, null, $p['reason'], (string) $id ) ) {
				throw new Exception( 'Audit event could not be recorded; return was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Return transaction commit failed.' );
			}
			Stockland_DB::release_day_lock( $lock );
			return rest_ensure_response( $row );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'return_failed', $e->getMessage(), array( 'status' => 409 ) ); }
	}
}
