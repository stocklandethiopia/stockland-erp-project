<?php
/**
 * Branch-scoped requisition creation and availability services.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Canonical requisition creation service shared by the canonical v16 production endpoint. */
class Stockland_Requisition_Service {
	/**
	 * Creates a branch-scoped requisition and records its audit event atomically.
	 *
	 * @param array $payload Sanitized requisition header and item payload.
	 * @param int   $user_id WordPress user identifier of the requester.
	 * @return mixed REST response or WP_Error describing validation/persistence failure.
	 * @throws Exception When a requisition row, item or audit event cannot be persisted.
	 */
	public static function create( $payload, $user_id ) {
		$user_id = (int) $user_id;
		$profile = Stockland_Roles::get_profile( $user_id );
		if ( ! $profile ) {
			return new WP_Error( 'no_profile', 'This account has no active Stockland role/profile.', array( 'status' => 403 ) );
		}
		$requested_branch = (int) ( $payload['location_id'] ?? 0 );
		$allowed          = Stockland_Authority::location_ids( $user_id );
		$branch           = $requested_branch > 0 ? $requested_branch : (int) ( $profile['branch_location_id'] ?? ( $allowed[0] ?? 0 ) );
		if ( ! $branch || ! Stockland_Authority::can_access_location( $branch, $user_id ) ) {
			return new WP_Error( 'branch_forbidden', 'This requisition is outside your authorized operational scope.', array( 'status' => 403 ) );
		}
		$items = is_array( $payload['items'] ?? null ) ? $payload['items'] : array();
		if ( empty( $items ) ) {
			return new WP_Error( 'invalid', 'Branch and items are required.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$seen = array();
		foreach ( $items as $it ) {
			$sku = sanitize_text_field( $it['sku'] ?? '' );
			$q   = (float) ( $it['quantity'] ?? 0 );
			if ( ! $sku || $q <= 0 ) {
				continue;
			}$seen[ $sku ] = ( $seen[ $sku ] ?? 0 ) + $q;}
		ksort( $seen, SORT_STRING );
		$clean = array();
		foreach ( $seen as $sku => $q ) {
			$prod = Stockland_DB::get_product( $sku );
			if ( ! $prod || empty( $prod['variant_id'] ) ) {
				return new WP_Error( 'invalid_sku', 'SKU ' . $sku . ' does not exist or has no immutable variant identity.', array( 'status' => 400 ) );
			}$clean[] = array(
				'sku'        => $sku,
				'variant_id' => (int) $prod['variant_id'],
				'quantity'   => $q,
			);}
		if ( empty( $clean ) ) {
			return new WP_Error( 'invalid', 'At least one valid requisition item is required.', array( 'status' => 400 ) );
		}
		$request_key = sanitize_text_field( (string) ( $payload['request_key'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,80}$/', $request_key ) ) {
			return new WP_Error( 'idempotency_key_required', 'A stable request_key is required to prevent duplicate requisitions after a timeout. Retry with the same form key.', array( 'status' => 400 ) );
		}
		$fingerprint = substr(
			hash(
				'sha256',
				wp_json_encode(
					array(
						'user_id'   => $user_id,
						'branch_id' => $branch,
						'notes'     => sanitize_text_field( $payload['notes'] ?? '' ),
						'items'     => array_map(
							static function ( $item ) {
								return array(
									'sku'      => (string) $item['sku'],
									'quantity' => number_format( (float) $item['quantity'], 2, '.', '' ),
								);
							},
							$clean
						),
					)
				)
			),
			0,
			32
		);
		$key_hash    = substr( hash( 'sha256', strtolower( $request_key ) ), 0, 20 );
		$marker      = '[[SLREQ:' . $key_hash . ':' . $fingerprint . ']]';
		$lock        = Stockland_DB::acquire_named_lock( 'requisition_create_' . $key_hash, 10 );
		if ( empty( $lock['ok'] ) ) {
			return new WP_Error( 'busy', 'This requisition submission is already being processed. Retry the same form shortly.', array( 'status' => 409 ) );
		}
		$pattern  = $wpdb->esc_like( '[[SLREQ:' . $key_hash . ':' ) . '%';
		$existing = Stockland_DB::read_row(
			$wpdb->prepare(
				'SELECT requisition_id,notes,status,branch_location_id FROM %i WHERE requested_by=%d AND notes LIKE %s ORDER BY created_at DESC LIMIT 1',
				Stockland_DB::table( 'requisitions' ),
				$user_id,
				$pattern
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			Stockland_DB::release_named_lock( $lock );
			return new WP_Error( 'idempotency_lookup_failed', 'Could not verify the earlier requisition submission; no new requisition was created. Retry shortly.', array( 'status' => 500 ) );
		}
		if ( $existing ) {
			if ( ! preg_match( '/^\\[\\[SLREQ:([a-f0-9]{20}):([a-f0-9]{32})\\]\\]/', (string) $existing['notes'], $match ) || ! hash_equals( $key_hash, $match[1] ) || ! hash_equals( $fingerprint, $match[2] ) ) {
				Stockland_DB::release_named_lock( $lock );
				return new WP_Error( 'idempotency_conflict', 'This requisition request key has already been used with different details.', array( 'status' => 409 ) );
			}
			Stockland_DB::release_named_lock( $lock );
			return rest_ensure_response(
				array(
					'requisition_id' => $existing['requisition_id'],
					'status'         => $existing['status'],
					'replayed'       => true,
					'availability'   => self::availability_snapshot( $clean ),
				)
			);
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_named_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start requisition transaction.', array( 'status' => 500 ) );
		}
		$id = null;
		try {
			$id = Stockland_Sequence::next( 'REQ-', current_time( 'Y-m-d' ) );
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'requisitions' ),
				array(
					'requisition_id'     => $id,
					'branch_location_id' => $branch,
					'requested_by'       => $user_id,
					'date_requested'     => current_time( 'Y-m-d' ),
					'status'             => 'Requested',
					'notes'              => substr( $marker . ' ' . sanitize_text_field( $payload['notes'] ?? '' ), 0, 255 ),
				)
			) ) {
				throw new Exception( 'Could not create requisition.' );}
			foreach ( $clean as $it ) {
				if ( false === Stockland_DB::insert_record(
					Stockland_DB::table( 'requisition_items' ),
					array(
						'requisition_id'      => $id,
						'sku'                 => $it['sku'],
						'variant_id'          => (int) $it['variant_id'],
						'quantity_requested'  => $it['quantity'],
						'quantity_approved'   => 0,
						'quantity_dispatched' => 0,
						'quantity_received'   => 0,
					)
				) ) {
					throw new Exception( 'Could not save requisition item ' . $it['sku'] . '.' );
				}
			}
			if ( ! Stockland_Audit::log( $user_id, $branch, 'REQUISITION_CREATE', null, null, null, $id, null, $id, $user_id ) ) {
				throw new Exception( 'Audit event could not be recorded; requisition was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Requisition create commit failed.' );
			}
			Stockland_DB::release_named_lock( $lock );
			$role = Stockland_Authority::role( $user_id );
			if ( Stockland_Authority::BRANCH_MANAGER !== $role ) {
				Stockland_Notifications::push_role( 'branch_manager', $branch, 'requisition_review', 'Requisition awaiting review', 'Requisition ' . $id . ' was requested and is waiting for Branch Manager review.', 'requisition', $id, 'action' );
			}
			return rest_ensure_response(
				array(
					'requisition_id' => $id,
					'status'         => 'Requested',
					'availability'   => self::availability_snapshot( $clean ),
				)
			);
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_named_lock( $lock );
			return new WP_Error( 'requisition_create_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}
	/**
	 * Returns a current availability snapshot for the requested requisition items.
	 *
	 * @param array $items Validated SKU, variant and quantity rows.
	 * @return array Availability rows for the requested items.
	 */
	public static function availability_snapshot( $items ) {
		global $wpdb;
		$wh  = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$out = array();
		foreach ( (array) $items as $item ) {
			$sku       = sanitize_text_field( $item['sku'] ?? '' );
			$requested = (float) ( $item['quantity'] ?? $item['quantity_requested'] ?? 0 );
			if ( ! $sku || $requested <= 0 ) {
				continue;
			}$prod     = Stockland_DB::get_product( $sku );
			$live_sku  = $prod['sku'] ?? $sku;
			$available = Stockland_DB::get_available_stock( $wh, $live_sku );
			$out[]     = array(
				'sku'               => $sku,
				'current_sku'       => $live_sku,
				'requested'         => $requested,
				'available'         => $available,
				'max_approvable'    => min( $requested, max( 0, $available ) ),
				'shortage'          => max( 0, $requested - $available ),
				'can_fully_reserve' => $available + 0.00001 >= $requested,
			);}
		return $out;
	}
	/**
	 * Removes the internal idempotency marker from the user-visible reason.
	 * The marker lives in the existing notes column; no table/schema change is required.
	 *
	 * @param string|null $notes Stored notes field.
	 * @return string Cleaned notes suitable for UI/API display.
	 */
	public static function display_notes( $notes ) {
		return trim( (string) preg_replace( '/^\\[\\[SLREQ:[a-f0-9]{20}:[a-f0-9]{32}\\]\\]\\s*/i', '', (string) $notes ) );
	}
}
