<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only stock endpoints. Always derives stock from the immutable transaction ledger and active reservations, never a stored balance
 * column — see Stockland_Ledger. Branch-scoped per section 13.
 */
class Stockland_REST_Stock extends Stockland_REST_Base {

	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/stock',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stock' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile(); },
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/stock/reorder',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_reorder_status' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile(); },
			)
		);
	}

	public function get_stock( WP_REST_Request $request ) {
		$location_id = $this->resolve_branch_for_user( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		global $wpdb;
		$tx       = Stockland_DB::table( 'transactions' );
		$res      = Stockland_DB::table( 'reservations' );
		$products = Stockland_DB::table( 'products' );

		$variant_id = 0;
		$has_sku    = (bool) $request->get_param( 'sku' );
		if ( $has_sku ) {
			$product = Stockland_DB::get_product( sanitize_text_field( $request->get_param( 'sku' ) ) );
			if ( ! $product ) {
				return rest_ensure_response( array() );
			}
			$variant_id = (int) $product['variant_id'];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.location_id,v.variant_id,p.sku,p.product_name,p.product_type,p.style_code,p.parent_sku,p.color,p.size,p.barcode,p.gtin,v.physical_stock,COALESCE(r.reserved_stock,0) reserved_stock,v.physical_stock-COALESCE(r.reserved_stock,0) available_stock
				FROM (SELECT t.location_id,t.variant_id,SUM(t.quantity_signed) physical_stock FROM %i t WHERE t.variant_id IS NOT NULL GROUP BY t.location_id,t.variant_id) v
				JOIN %i p ON p.variant_id=v.variant_id
				LEFT JOIN (SELECT location_id,variant_id,sku,SUM(quantity_reserved) reserved_stock FROM %i WHERE status='Active' GROUP BY location_id,variant_id,sku) r ON r.location_id=v.location_id AND r.variant_id=p.variant_id
				WHERE p.is_active=1 AND p.product_type<>'STYLE'
					AND (%d = 0 OR v.location_id=%d)
					AND (%d = 0 OR v.variant_id=%d)
				ORDER BY v.location_id,p.sku",
				$tx,
				$products,
				$res,
				$location_id ? 1 : 0,
				$location_id ? (int) $location_id : 0,
				$has_sku ? 1 : 0,
				$variant_id
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}

	/** Mirrors REORDER ACTIONS sheet: Current Stock vs Reorder Level, per location. */
	public function get_reorder_status( WP_REST_Request $request ) {
		$location_id = $this->resolve_branch_for_user( $request->get_param( 'location_id' ) );
		if ( is_wp_error( $location_id ) ) {
			return $location_id;
		}
		global $wpdb;
		$locations = Stockland_DB::table( 'locations' );
		$products  = Stockland_DB::table( 'products' );
		$tx        = Stockland_DB::table( 'transactions' );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.location_name,p.variant_id,p.sku,p.product_name,p.style_code,p.brand,p.color,p.size,p.barcode,p.gtin,COALESCE(b.physical_stock,0) current_stock,IF(l.location_type='Warehouse',p.central_reorder_level,p.branch_reorder_level) reorder_level
				FROM %i p CROSS JOIN %i l
				LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b ON b.variant_id=p.variant_id AND b.location_id=l.id
				WHERE l.location_type IN('Warehouse','Branch') AND p.is_active=1 AND p.product_type<>'STYLE'
					AND (%d = 0 OR l.id=%d)
				ORDER BY current_stock,p.sku",
				$products,
				$locations,
				$tx,
				$location_id ? 1 : 0,
				$location_id ? (int) $location_id : 0
			),
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$current       = (float) $row['current_stock'];
			$level         = (float) $row['reorder_level'];
			$row['status'] = 0.0 === $current ? 'CRITICAL' : ( $current <= $level ? 'REORDER NOW' : 'OK' );
		}
		return rest_ensure_response( $rows );
	}

}
