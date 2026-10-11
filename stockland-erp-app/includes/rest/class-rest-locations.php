<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class Stockland_REST_Locations {
	const NS = 'stockland/v16';
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'routes' ) );}
	public function routes() {
		register_rest_route(
			self::NS,
			'/organization/locations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/organization/locations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_locations() ? true : new WP_Error( 'forbidden', 'Only Owner may add a branch/location.', array( 'status' => 403 ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/organization/locations/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_locations() ? true : new WP_Error( 'forbidden', 'Only Owner may change a branch/location.', array( 'status' => 403 ) );
				},
			)
		);
	}
	public function list() {
		global $wpdb;
		return rest_ensure_response( $wpdb->get_results( 'SELECT id,location_name,branch_code,location_type,role_description,is_active FROM ' . Stockland_DB::table( 'locations' ) . " ORDER BY (location_type='Warehouse') DESC, location_name", ARRAY_A ) );}
	public function create( WP_REST_Request $r ) {
		global $wpdb;
		$p    = $r->get_json_params();
		$name = sanitize_text_field( $p['location_name'] ?? '' );
		$type = in_array( $p['location_type'] ?? 'Branch', array( 'Branch', 'Sales channel' ), true ) ? $p['location_type'] : 'Branch';
		$desc = sanitize_text_field( $p['role_description'] ?? '' );
		$code = sanitize_key( $p['branch_code'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'invalid', 'Location name is required.', array( 'status' => 400 ) );
		}$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE location_name=%s LIMIT 1', $name ) );
		if ( $exists ) {
			return new WP_Error( 'duplicate', 'A location with this name already exists.', array( 'status' => 409 ) );
		}if ( 'Branch' === $type && '' === $code ) {
			$next = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0)+1 FROM ' . Stockland_DB::table( 'locations' ) );
			$code = 'BR' . str_pad( (string) $next, 3, '0', STR_PAD_LEFT );
		}if ( '' !== $code ) {
			$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE branch_code=%s LIMIT 1', $code ) );
			if ( $dup ) {
				return new WP_Error( 'duplicate_code', 'This branch/location code is already in use.', array( 'status' => 409 ) );
			}
		}if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start location creation transaction.', array( 'status' => 500 ) );
		}try {
			if ( false === $wpdb->insert(
				Stockland_DB::table( 'locations' ),
				array(
					'location_name'    => $name,
					'branch_code'      => $code ?: null,
					'location_type'    => $type,
					'role_description' => $desc,
					'is_active'        => 1,
				)
			) ) {
				throw new Exception( 'Could not create location.' );
			}$id = (int) $wpdb->insert_id;
			if ( ! Stockland_Audit::log( get_current_user_id(), $id, 'LOCATION_CREATE', null, null, null, $name, 'Created by Owner', 'LOCATION-' . $id, get_current_user_id() ) ) {
				throw new Exception( 'Audit event could not be recorded; location was not committed.' );
			}if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Location commit failed.' );
			}return rest_ensure_response(
				array(
					'id'               => $id,
					'location_name'    => $name,
					'branch_code'      => $code,
					'location_type'    => $type,
					'role_description' => $desc,
					'is_active'        => 1,
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'create_failed', $e->getMessage(), array( 'status' => 409 ) );}}
	public function update( WP_REST_Request $r ) {
		global $wpdb;
		$id  = (int) $r['id'];
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Location not found.', array( 'status' => 404 ) );
		}$p      = $r->get_json_params();
		$data    = array();
		$central = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		if ( $id === $central && isset( $p['location_name'] ) && sanitize_text_field( $p['location_name'] ) !== 'Central Warehouse' ) {
			return new WP_Error( 'central_fixed', 'Central Warehouse is a protected system location. Add/rename retail branches without changing the warehouse identity.', array( 'status' => 409 ) );
		}if ( isset( $p['location_name'] ) ) {
			$name = sanitize_text_field( $p['location_name'] );
			if ( '' === $name ) {
				return new WP_Error( 'invalid', 'Location name cannot be empty.', array( 'status' => 400 ) );
			}$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE location_name=%s AND id<>%d', $name, $id ) );
			if ( $dup ) {
				return new WP_Error( 'duplicate', 'Another location already uses that name.', array( 'status' => 409 ) );
			}$data['location_name'] = $name;
		}if ( isset( $p['branch_code'] ) ) {
			$code = sanitize_key( $p['branch_code'] );
			if ( '' === $code ) {
				return new WP_Error( 'invalid', 'Location code cannot be empty.', array( 'status' => 400 ) );
			} $dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE branch_code=%s AND id<>%d LIMIT 1', $code, $id ) );
			if ( $dup ) {
				return new WP_Error( 'duplicate_code', 'Another location already uses this code.', array( 'status' => 409 ) );
			} $data['branch_code'] = $code;
		} if ( isset( $p['role_description'] ) ) {
			$data['role_description'] = sanitize_text_field( $p['role_description'] );
		}if ( isset( $p['is_active'] ) ) {
			$data['is_active'] = (int) (bool) $p['is_active'];
		}if ( isset( $p['location_type'] ) && in_array( $p['location_type'], array( 'Branch', 'Sales channel' ), true ) ) {
			$data['location_type'] = $p['location_type'];
		}if ( ! $data ) {
			return rest_ensure_response( $row );
		}if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start location update transaction.', array( 'status' => 500 ) );
		}try {
			if ( false === $wpdb->update( Stockland_DB::table( 'locations' ), $data, array( 'id' => $id ) ) ) {
				throw new Exception( $wpdb->last_error ?: 'No location change was saved.' );
			}if ( ! Stockland_Audit::log( get_current_user_id(), $id, 'LOCATION_UPDATE', null, null, $row['location_name'], wp_json_encode( $data ), 'Owner changed organization structure', 'LOCATION-' . $id, get_current_user_id() ) ) {
				throw new Exception( 'Audit event could not be recorded; location was not committed.' );
			}if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Location update commit failed.' );
			}return rest_ensure_response( array_merge( $row, $data ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'update_failed', $e->getMessage(), array( 'status' => 409 ) );}}
}
