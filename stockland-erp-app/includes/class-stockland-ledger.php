<?php
/**
 * Immutable inventory ledger and derived stock-balance operations.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable stock ledger choke point; each movement is line-addressable and idempotent.
 *
 * @package Stockland_ERP
 */
class Stockland_Ledger {
	/** Begin an atomic unit; use a savepoint when called inside a caller-owned transaction. */
	private static function begin_unit_of_work( &$owns_transaction, &$savepoint ) {
		global $wpdb;
		$state = $wpdb->get_var( 'SELECT @@session.in_transaction' );
		if ( null === $state ) {
			return false; // Unknown transaction state is unsafe; fail closed.
		}
		$owns_transaction = ( 0 === (int) $state );
		$savepoint        = '';
		if ( $owns_transaction ) {
			return false !== $wpdb->query( 'START TRANSACTION' );
		}
		$savepoint = 'sl_ledger_' . wp_rand( 100000, 999999 );
		return false !== $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', $savepoint ) );
	}
	/**
	 * Roll back the owned transaction or restore the caller's savepoint.
	 *
	 * @param bool   $owns_transaction Whether this method started the transaction.
	 * @param string $savepoint Savepoint name for a nested unit of work.
	 * @return void
	 */
	private static function rollback_unit_of_work( $owns_transaction, $savepoint ) {
		global $wpdb;
		if ( $owns_transaction ) {
			$wpdb->query( 'ROLLBACK' );
			return;}
		if ( '' !== $savepoint ) {
			$wpdb->query( $wpdb->prepare( 'ROLLBACK TO SAVEPOINT %i', $savepoint ) );
			$wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', $savepoint ) );}
	}
	/**
	 * Commit the owned transaction or release a nested savepoint.
	 *
	 * @param bool   $owns_transaction Whether this method started the transaction.
	 * @param string $savepoint Savepoint name for a nested unit of work.
	 * @return bool True when commit/release succeeds.
	 */
	private static function commit_unit_of_work( $owns_transaction, $savepoint ) {
		global $wpdb;
		$ok = $owns_transaction ? $wpdb->query( 'COMMIT' ) : $wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', $savepoint ) );
		if ( false !== $ok ) {
			return true;
		}
		self::rollback_unit_of_work( $owns_transaction, $savepoint );
		return false;
	}
	/**
	 * Record one idempotent stock movement and update the derived balance atomically.
	 *
	 * @param int         $location_id Operational location identifier.
	 * @param string      $sku Product Master SKU.
	 * @param string      $movement_type Canonical movement type.
	 * @param float       $quantity_signed Signed stock quantity.
	 * @param string      $reference_type Source document type.
	 * @param int|string  $reference_id Source document identifier.
	 * @param string      $transaction_date Effective movement date.
	 * @param int|null    $created_by User responsible for the movement.
	 * @param string      $source_line_id Stable source-line idempotency key.
	 * @return int|false Ledger identifier, or false when the movement cannot be committed.
	 */
	public static function record( $location_id, $sku, $movement_type, $quantity_signed, $reference_type, $reference_id, $transaction_date, $created_by = null, $source_line_id = '' ) {
		global $wpdb;
		$table          = Stockland_DB::table( 'transactions' );
		$source_line_id = sanitize_text_field( (string) $source_line_id );
		if ( abs( (float) $quantity_signed ) < 0.00001 ) {
			return false;
		}

		$owns_transaction = false;
		$savepoint        = '';
		if ( ! self::begin_unit_of_work( $owns_transaction, $savepoint ) ) {
			return false;
		}
		try {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,location_id,sku,variant_id,quantity_signed FROM %i WHERE reference_type=%s AND reference_id=%s AND source_line_id=%s AND movement_type=%s LIMIT 1 FOR UPDATE', $table, $reference_type, $reference_id, $source_line_id, $movement_type ), ARRAY_A );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Ledger idempotency lookup failed.' );
			}
			if ( $existing ) {
				$same = (int) $existing['location_id'] === (int) $location_id && (string) $existing['sku'] === (string) $sku && abs( (float) $existing['quantity_signed'] - (float) $quantity_signed ) < 0.00001;
				if ( ! self::commit_unit_of_work( $owns_transaction, $savepoint ) ) {
					return false;
				}
				return $same ? (int) $existing['id'] : false;
			}
			$product = Stockland_DB::get_product( $sku );
			if ( ! $product || empty( $product['variant_id'] ) ) {
				throw new RuntimeException( 'The SKU has no active Product Master variant.' );
			}
			$ok = $wpdb->insert(
				$table,
				array(
					'location_id'      => (int) $location_id,
					'sku'              => $sku,
					'variant_id'       => (int) $product['variant_id'],
					'size'             => $product['size'] ?? null,
					'color'            => $product['color'] ?? null,
					'barcode'          => $product['barcode'] ?? ( $product['gtin'] ?? null ),
					'movement_type'    => $movement_type,
					'quantity_signed'  => $quantity_signed,
					'reference_type'   => $reference_type,
					'reference_id'     => $reference_id,
					'source_line_id'   => $source_line_id,
					'transaction_date' => $transaction_date,
					'created_by'       => $created_by,
				)
			);
			if ( false === $ok ) {
				$duplicate = $wpdb->get_row( $wpdb->prepare( 'SELECT id,location_id,sku,quantity_signed FROM %i WHERE reference_type=%s AND reference_id=%s AND source_line_id=%s AND movement_type=%s LIMIT 1', $table, $reference_type, $reference_id, $source_line_id, $movement_type ), ARRAY_A );
				if ( $wpdb->last_error || ! $duplicate || (int) $duplicate['location_id'] !== (int) $location_id || (string) $duplicate['sku'] !== (string) $sku || abs( (float) $duplicate['quantity_signed'] - (float) $quantity_signed ) >= 0.00001 ) {
					throw new RuntimeException( 'Conflicting ledger idempotency key.' );
				}
				if ( ! self::commit_unit_of_work( $owns_transaction, $savepoint ) ) {
					return false;
				}
				return (int) $duplicate['id'];
			}
			$balances = Stockland_DB::table( 'balances' );
			if ( Stockland_DB::table_exists( $balances ) ) {
				$balance_ok = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (location_id,variant_id,sku,physical_qty) VALUES (%d,%d,%s,%f) ON DUPLICATE KEY UPDATE sku=VALUES(sku), physical_qty=physical_qty+VALUES(physical_qty), updated_at=CURRENT_TIMESTAMP', $balances, (int) $location_id, (int) $product['variant_id'], $sku, (float) $quantity_signed ) );
				if ( false === $balance_ok ) {
					throw new RuntimeException( 'Stock balance update failed: ' . ( $wpdb->last_error ? $wpdb->last_error : 'unknown database error' ) );
				}
			}
			if ( ! self::commit_unit_of_work( $owns_transaction, $savepoint ) ) {
				return false;
			}
			return (int) $wpdb->insert_id;
		} catch ( Throwable $e ) {
			self::rollback_unit_of_work( $owns_transaction, $savepoint );
			return false;}
	}

	/**
	 * Recalculate all location balances for a Product Master variant from the ledger.
	 *
	 * @param int         $variant_id Product variant identifier.
	 * @param string|null $new_sku Replacement SKU to persist in derived balances, if any.
	 * @return bool True when the derived balances are rebuilt successfully.
	 */
	public static function rebuild_balance_for_variant( $variant_id, $new_sku = null ) {
		global $wpdb;
		$variant_id = (int) $variant_id;
		if ( $variant_id <= 0 ) {
			return false;
		}
		$tx  = Stockland_DB::table( 'transactions' );
		$bal = Stockland_DB::table( 'balances' );
		if ( ! Stockland_DB::table_exists( $bal ) || ! Stockland_DB::table_exists( $tx ) ) {
			return false;
		}
		$owns_transaction = false;
		$savepoint        = '';
		if ( ! self::begin_unit_of_work( $owns_transaction, $savepoint ) ) {
			return false;
		}
		try {
			$product = Stockland_DB::get_product_by_id( $variant_id );
			if ( ! $product ) {
				throw new RuntimeException( 'Cannot rebuild balance: Product Master variant not found.' );
			}
			$sku  = null !== $new_sku ? (string) $new_sku : (string) $product['sku'];
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT location_id,COALESCE(SUM(quantity_signed),0) qty FROM %i WHERE variant_id=%d GROUP BY location_id', $tx, $variant_id ), ARRAY_A );
			if ( $wpdb->last_error || ! is_array( $rows ) ) {
				throw new RuntimeException( 'Could not read ledger rows for balance rebuild.' );
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE variant_id=%d', $bal, $variant_id ) ) ) {
				throw new RuntimeException( 'Could not clear derived balance rows.' );
			}
			foreach ( $rows as $row ) {
				if ( false === $wpdb->insert(
					$bal,
					array(
						'location_id'  => (int) $row['location_id'],
						'variant_id'   => $variant_id,
						'sku'          => $sku,
						'physical_qty' => (float) $row['qty'],
					)
				) ) {
					throw new RuntimeException( 'Could not rebuild a derived balance row.' );
				}
			}
			if ( ! self::commit_unit_of_work( $owns_transaction, $savepoint ) ) {
				return false;
			}
			return true;
		} catch ( Throwable $e ) {
			self::rollback_unit_of_work( $owns_transaction, $savepoint );
			return false;}
	}
	/**
	 * Recalculate one location/SKU balance from immutable ledger movements.
	 *
	 * @param int    $location_id Operational location identifier.
	 * @param string $sku Product Master SKU.
	 * @return bool True when the derived balance is rebuilt successfully.
	 */
	public static function rebuild_balance( $location_id, $sku ) {
		global $wpdb;
		$bal = Stockland_DB::table( 'balances' );
		$tx  = Stockland_DB::table( 'transactions' );
		if ( ! Stockland_DB::table_exists( $bal ) || ! Stockland_DB::table_exists( $tx ) ) {
			return false;
		}
		$owns_transaction = false;
		$savepoint        = '';
		if ( ! self::begin_unit_of_work( $owns_transaction, $savepoint ) ) {
			return false;
		}
		try {
			$product = Stockland_DB::get_product( $sku );
			if ( ! $product || empty( $product['variant_id'] ) ) {
				throw new RuntimeException( 'Cannot rebuild balance without a valid Product Master variant.' );
			}
			$vid = (int) $product['variant_id'];
			$qty = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(quantity_signed),0) FROM %i WHERE location_id=%d AND variant_id=%d', $tx, (int) $location_id, $vid ) );
			if ( null === $qty || $wpdb->last_error ) {
				throw new RuntimeException( 'Could not read inventory ledger total.' );
			}
			if ( false === $wpdb->delete(
				$bal,
				array(
					'location_id' => (int) $location_id,
					'variant_id'  => $vid,
				)
			) ) {
				throw new RuntimeException( 'Could not clear derived balance.' );
			}
			if ( false === $wpdb->insert(
				$bal,
				array(
					'location_id'  => (int) $location_id,
					'variant_id'   => $vid,
					'sku'          => $sku,
					'physical_qty' => (float) $qty,
				)
			) ) {
				throw new RuntimeException( 'Could not write derived balance.' );
			}
			if ( ! self::commit_unit_of_work( $owns_transaction, $savepoint ) ) {
				return false;
			}
			return true;
		} catch ( Throwable $e ) {
			self::rollback_unit_of_work( $owns_transaction, $savepoint );
			return false;}
	}

	/**
	 * Post the outbound ledger movement for a dispatched transfer.
	 *
	 * @param array $transfer Transfer record.
	 * @param int   $user_id User responsible for posting.
	 * @return int|false Ledger movement identifier, or false on failure.
	 */
	public static function post_transfer_dispatched( $transfer, $user_id ) {
		$line = (string) ( $transfer['transfer_id'] . '-OUT' );
		return self::record( $transfer['from_location_id'], $transfer['sku'], 'TRANSFER_OUT', -abs( (float) ( $transfer['dispatched_qty'] ?? $transfer['quantity'] ) ), 'transfer', $transfer['transfer_id'], $transfer['transfer_date'], $user_id, $line );}
	/**
	 * Post the inbound ledger movement for a received transfer.
	 *
	 * @param array $transfer Transfer record.
	 * @param int   $user_id User responsible for posting.
	 * @return int|false Ledger movement identifier, or false on failure.
	 */
	public static function post_transfer_received( $transfer, $user_id ) {
		$line = (string) ( $transfer['transfer_id'] . '-IN' );
		return self::record( $transfer['to_location_id'], $transfer['sku'], 'TRANSFER_IN', abs( (float) ( $transfer['received_qty'] ?? $transfer['quantity'] ) ), 'transfer', $transfer['transfer_id'], $transfer['transfer_date'], $user_id, $line );}
	/**
	 * Post dispatch and receipt movements within one unit of work.
	 *
	 * @param array $transfer Completed transfer record.
	 * @param int   $user_id User responsible for posting.
	 * @return int|false Inbound movement identifier, or false when either leg fails.
	 */
	public static function post_transfer_completed( $transfer, $user_id ) {
		$t                   = $transfer;
		$t['dispatched_qty'] = (float) ( $t['dispatched_qty'] ?? $t['quantity'] );
		$t['received_qty']   = (float) ( $t['received_qty'] ?? $t['quantity'] );
		$owns_transaction    = false;
		$savepoint           = '';
		if ( ! self::begin_unit_of_work( $owns_transaction, $savepoint ) ) {
			return false;
		}
		try {
			$out = self::post_transfer_dispatched( $t, $user_id );
			if ( false === $out ) {
				throw new RuntimeException( 'Transfer dispatch ledger post failed.' );
			}
			$in = self::post_transfer_received( $t, $user_id );
			if ( false === $in ) {
				throw new RuntimeException( 'Transfer receipt ledger post failed.' );
			}
			if ( ! self::commit_unit_of_work( $owns_transaction, $savepoint ) ) {
				return false;
			}
			return $in;
		} catch ( Throwable $e ) {
			self::rollback_unit_of_work( $owns_transaction, $savepoint );
			return false;}
	}

	/**
	 * Post a completed warehouse receipt to the immutable ledger.
	 *
	 * @param array $receipt Completed receipt record.
	 * @param int   $warehouse_location_id Receiving warehouse identifier.
	 * @param int   $user_id User responsible for posting.
	 * @return int|false Ledger movement identifier, or false on failure.
	 */
	public static function post_receipt_completed( $receipt, $warehouse_location_id, $user_id ) {
		return self::record( $warehouse_location_id, $receipt['sku'], 'RECEIPT', abs( (float) $receipt['quantity_received'] ), 'receipt', $receipt['receipt_id'], $receipt['receipt_date'], $user_id, (string) $receipt['receipt_id'] );}
	/**
	 * Post a closed branch sale to the immutable ledger.
	 *
	 * @param array $sale Closed sale line.
	 * @param int   $user_id User responsible for posting.
	 * @return int|false Ledger movement identifier, or false on failure.
	 */
	public static function post_sale_closed( $sale, $user_id ) {
		return self::record( $sale['branch_location_id'], $sale['sku'], 'SALE', -abs( (float) $sale['quantity_sold'] ), 'sale', $sale['source_job_id'] ?? $sale['sale_id'], $sale['sale_date'], $user_id, (string) $sale['source_row_number'] ? (string) $sale['source_row_number'] : (string) $sale['sale_id'] );}
	/**
	 * Post a wholesale sale as an outbound warehouse movement.
	 *
	 * @param array $sale Wholesale sale line.
	 * @param int   $warehouse_location_id Fulfilling warehouse identifier.
	 * @param int   $user_id User responsible for posting.
	 * @return int|false Ledger movement identifier, or false on failure.
	 */
	public static function post_wholesale_sale( $sale, $warehouse_location_id, $user_id ) {
		return self::record( $warehouse_location_id, $sale['sku'], 'WHOLESALE_SALE', -abs( (float) $sale['quantity'] ), 'wholesale_sale', $sale['sale_id'], $sale['sale_date'], $user_id, (string) $sale['sale_id'] );}
	/**
	 * Post a return, damage or adjustment movement to the ledger.
	 *
	 * @param array $ret Return or adjustment record.
	 * @param int   $user_id User responsible for posting.
	 * @return int|false Ledger movement identifier, or false on failure.
	 */
	public static function post_return( $ret, $user_id ) {
		$sign = ( 'Return' === $ret['return_type'] ) ? 1 : -1;
		if ( 'Adjustment' === $ret['return_type'] && isset( $ret['adjustment_sign'] ) ) {
			$sign = (int) $ret['adjustment_sign'];
		}$movement = 'Return' === $ret['return_type'] ? 'RETURN_IN' : ( 'Damage' === $ret['return_type'] ? 'DAMAGE_LOSS' : 'ADJUSTMENT' );
		return self::record( $ret['location_id'], $ret['sku'], $movement, $sign * abs( (float) $ret['quantity'] ), 'return', (string) $ret['id'], $ret['return_date'], $user_id, (string) $ret['id'] );}
}
