<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical Product Master domain service.  All SKU replacement paths delegate here. */
class Stockland_Product_Service {
	public static function replace_sku( $variant_id, $new_sku, $reason, $reference = '', $manage_transaction = true ) {
		global $wpdb;
		$variant_id = (int) $variant_id;
		$new_sku    = sanitize_text_field( (string) $new_sku );
		$reason     = sanitize_text_field( (string) $reason );
		$reference  = sanitize_text_field( (string) $reference );
		if ( $variant_id <= 0 || $new_sku === '' || $reason === '' ) {
			throw new Exception( 'variant_id, new SKU and reason are required.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $new_sku ) ) {
			throw new Exception( 'Stockland SKU must begin with STK- and use letters, numbers, hyphens or underscores (max 64 characters).' );
		}
		$products = Stockland_DB::table( 'products' );
		$history  = Stockland_DB::table( 'product_sku_history' );
		$owns     = false;
		if ( $manage_transaction ) {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new Exception( 'Could not start SKU replacement transaction.' );
			}
			$owns = true;
		}
		try {
			// The lock must be acquired while the transaction is active. A SELECT ...
			// FOR UPDATE issued before START TRANSACTION is not a reliable serialization
			// boundary on MariaDB/MySQL.
			$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$products} WHERE variant_id=%d FOR UPDATE", $variant_id ), ARRAY_A );
			if ( ! $old ) {
				throw new Exception( 'Product variant was not found.' );
			}
			if ( (string) $old['sku'] === $new_sku ) {
				if ( $owns && false === $wpdb->query( 'COMMIT' ) ) {
					throw new Exception( 'No-change SKU replacement verification commit failed.' );
				} return $old; }
			$collision = $wpdb->get_var( $wpdb->prepare( "SELECT sku FROM {$products} WHERE sku=%s AND variant_id<>%d LIMIT 1", $new_sku, $variant_id ) );
			if ( $collision ) {
				throw new Exception( 'The new SKU is already assigned to another variant.' );
			}
			$checks = array(
				'reservations'    => 'SELECT COUNT(*) FROM ' . Stockland_DB::table( 'reservations' ) . " WHERE variant_id=%d AND status IN('Active','Reserved')",
				'transfers'       => 'SELECT COUNT(*) FROM ' . Stockland_DB::table( 'transfers' ) . " WHERE variant_id=%d AND status NOT IN('Rejected','Cancelled','Completed','Received')",
				'requisitions'    => 'SELECT COUNT(*) FROM ' . Stockland_DB::table( 'requisition_items' ) . ' WHERE variant_id=%d AND (quantity_approved IS NOT NULL AND COALESCE(quantity_received,0)<COALESCE(quantity_approved,0))',
				'purchase_orders' => 'SELECT COUNT(*) FROM ' . Stockland_DB::table( 'purchase_order_items' ) . ' WHERE variant_id=%d AND quantity_received<quantity_ordered',
			);
			foreach ( $checks as $name => $sql ) {
				if ( Stockland_DB::table_exists( Stockland_DB::table( $name ) ) && (int) $wpdb->get_var( $wpdb->prepare( $sql, $variant_id ) ) > 0 ) {
					throw new Exception( 'SKU replacement is blocked while there are open ' . $name . '.' );
				}
			}
			if ( false === $wpdb->update( $products, array( 'sku' => $new_sku ), array( 'variant_id' => $variant_id ) ) ) {
				throw new Exception( $wpdb->last_error ?: 'Product SKU could not be changed.' );
			}
			if ( false === $wpdb->insert(
				$history,
				array(
					'variant_id'     => $variant_id,
					'old_sku'        => $old['sku'],
					'new_sku'        => $new_sku,
					'effective_from' => current_time( 'mysql' ),
					'reason'         => $reason,
					'changed_by'     => get_current_user_id(),
					'reference'      => $reference ?: null,
				)
			) ) {
				throw new Exception( $wpdb->last_error ?: 'SKU history could not be recorded.' );
			}
			if ( method_exists( 'Stockland_Ledger', 'rebuild_balance_for_variant' ) ) {
				if ( false === Stockland_Ledger::rebuild_balance_for_variant( $variant_id, $new_sku ) ) {
					throw new Exception( 'Derived balance could not be rebuilt after SKU replacement.' );
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'SKU_REPLACE', $new_sku, null, $old['sku'], $new_sku, $reason, $reference ?: ( 'VARIANT-' . $variant_id ) ) ) {
				throw new Exception( 'Audit event could not be recorded; SKU replacement rolled back.' );
			}
			if ( $owns && false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'SKU replacement commit failed.' );
			}
		} catch ( Throwable $e ) {
			if ( $owns ) {
				$wpdb->query( 'ROLLBACK' );
			} throw $e; }
		return Stockland_DB::get_product_by_id( $variant_id );
	}
}
