<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Canonical requisition creation service shared by the canonical v16 production endpoint. */
class Stockland_Requisition_Service {
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
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start requisition transaction.', array( 'status' => 500 ) );
		}
		$id = null;
		try {
			$id = Stockland_Sequence::next( 'REQ-', current_time( 'Y-m-d' ) );
			if ( false === $wpdb->insert(
				Stockland_DB::table( 'requisitions' ),
				array(
					'requisition_id'     => $id,
					'branch_location_id' => $branch,
					'requested_by'       => $user_id,
					'date_requested'     => current_time( 'Y-m-d' ),
					'status'             => 'Requested',
					'notes'              => sanitize_text_field( $payload['notes'] ?? '' ),
				)
			) ) {
				throw new Exception( 'Could not create requisition.' );}
			foreach ( $clean as $it ) {
				if ( false === $wpdb->insert(
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
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Requisition create commit failed.' );
			}
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
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'requisition_create_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}
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
}
