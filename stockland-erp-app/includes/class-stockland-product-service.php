<?php
/**
 * Product Master mutation safeguards for Stockland ERP.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical Product Master domain service.  All SKU replacement paths delegate here. */
class Stockland_Product_Service {
	/**
	 * Replaces a variant SKU only when operational references permit it.
	 *
	 * All writes, SKU-history evidence, ledger balance rebuild and audit event
	 * remain part of the same transaction when this service owns the transaction.
	 *
	 * @param int    $variant_id Immutable Product Master variant identifier.
	 * @param string $new_sku New SKU to assign.
	 * @param string $reason Business justification for the SKU replacement.
	 * @param string $reference Optional supporting document/reference.
	 * @param bool   $manage_transaction Whether this service should start/commit its own transaction.
	 * @return array|false Saved Product Master product row, or false if it cannot be re-read.
	 * @throws Exception When validation, active references, audit logging or persistence fails.
	 * @throws RuntimeException When the ledger service cannot rebuild the derived balance.
	 * @throws Throwable When an unexpected PHP error escapes the transaction workflow.
	 */
	public static function replace_sku( $variant_id, $new_sku, $reason, $reference = '', $manage_transaction = true ) {
		global $wpdb;
		$variant_id = (int) $variant_id;
		$new_sku    = sanitize_text_field( (string) $new_sku );
		$reason     = sanitize_text_field( (string) $reason );
		$reference  = sanitize_text_field( (string) $reference );
		if ( $variant_id <= 0 || '' === $new_sku || '' === $reason ) {
			throw new Exception( 'variant_id, new SKU and reason are required.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $new_sku ) ) {
			throw new Exception( 'Stockland SKU must begin with STK- and use letters, numbers, hyphens or underscores (max 64 characters).' );
		}
		$products = Stockland_DB::table( 'products' );
		$history  = Stockland_DB::table( 'product_sku_history' );
		$owns     = false;
		if ( $manage_transaction ) {
			if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
				throw new Exception( 'Could not start SKU replacement transaction.' );
			}
			$owns = true;
		}
		try {
			// The lock must be acquired while the transaction is active. A SELECT ...
			// FOR UPDATE issued before START TRANSACTION is not a reliable serialization
			// boundary on MariaDB/MySQL.
			$old = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE variant_id=%d FOR UPDATE', $products, $variant_id ), ARRAY_A );
			if ( ! $old ) {
				throw new Exception( 'Product variant was not found.' );
			}
			if ( (string) $old['sku'] === $new_sku ) {
				if ( $owns && false === Stockland_DB::execute_query( 'COMMIT' ) ) {
					throw new Exception( 'No-change SKU replacement verification commit failed.' );
				} return $old; }
			$collision = Stockland_DB::read_var( $wpdb->prepare( 'SELECT sku FROM %i WHERE sku=%s AND variant_id<>%d LIMIT 1', $products, $new_sku, $variant_id ) );
			if ( $collision ) {
				throw new Exception( 'The new SKU is already assigned to another variant.' );
			}
			$checks = array(
				'reservations'    => array(
					'table' => 'reservations',
				),
				'transfers'       => array(
					'table' => 'transfers',
				),
				'requisitions'    => array(
					'table' => 'requisition_items',
				),
				'purchase_orders' => array(
					'table' => 'purchase_order_items',
				),
			);
			foreach ( $checks as $name => $check ) {
				$table = Stockland_DB::table( $check['table'] );
				if ( ! Stockland_DB::table_exists( $table ) ) {
					continue;
				}
				switch ( $name ) {
					case 'reservations':
						$count = Stockland_DB::read_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE variant_id=%d AND status IN('Active','Reserved')", $table, $variant_id ) );
						break;
					case 'transfers':
						$count = Stockland_DB::read_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE variant_id=%d AND status NOT IN('Rejected','Cancelled','Completed','Received')", $table, $variant_id ) );
						break;
					case 'requisitions':
						$count = Stockland_DB::read_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE variant_id=%d AND quantity_approved IS NOT NULL AND COALESCE(quantity_received,0)<COALESCE(quantity_approved,0)', $table, $variant_id ) );
						break;
					case 'purchase_orders':
						$count = Stockland_DB::read_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE variant_id=%d AND quantity_received<quantity_ordered', $table, $variant_id ) );
						break;
					default:
						// Unknown internal check keys must fail closed instead of executing dynamic SQL.
						throw new Exception( 'Unsupported operational reference check.' );
				}
				if ( (int) $count > 0 ) {
					throw new Exception( 'SKU replacement is blocked while there are open ' . $name . '.' );
				}
			}
			if ( false === Stockland_DB::update_record( $products, array( 'sku' => $new_sku ), array( 'variant_id' => $variant_id ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Product SKU could not be changed.' );
			}
			if ( false === Stockland_DB::insert_record(
				$history,
				array(
					'variant_id'     => $variant_id,
					'old_sku'        => $old['sku'],
					'new_sku'        => $new_sku,
					'effective_from' => current_time( 'mysql' ),
					'reason'         => $reason,
					'changed_by'     => get_current_user_id(),
					'reference'      => $reference ? $reference : null,
				)
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'SKU history could not be recorded.' );
			}
			if ( method_exists( 'Stockland_Ledger', 'rebuild_balance_for_variant' ) ) {
				if ( false === Stockland_Ledger::rebuild_balance_for_variant( $variant_id, $new_sku ) ) {
					throw new Exception( 'Derived balance could not be rebuilt after SKU replacement.' );
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'SKU_REPLACE', $new_sku, null, $old['sku'], $new_sku, $reason, $reference ? $reference : ( 'VARIANT-' . $variant_id ) ) ) {
				throw new Exception( 'Audit event could not be recorded; SKU replacement rolled back.' );
			}
			if ( $owns && false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'SKU replacement commit failed.' );
			}
		} catch ( Throwable $e ) {
			if ( $owns ) {
				Stockland_DB::execute_query( 'ROLLBACK' );
			} throw $e; }
		return Stockland_DB::get_product_by_id( $variant_id );
	}
}
