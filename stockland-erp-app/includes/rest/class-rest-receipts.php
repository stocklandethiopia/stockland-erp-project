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
 * Central Warehouse receiving (section 8). Only a transition to
 * 'Completed' increases warehouse stock — mirrors "On confirmation:
 * Central Warehouse physical stock increases. A permanent inventory
 * transaction is created."
 */
class Stockland_REST_Receipts extends Stockland_REST_Base {

	/**
	 * Registers the legacy receipt evidence endpoints.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers read-only historical receipt and guarded transition routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/receipts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_receipts' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_view_external_purchase_orders(); },
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_receipt' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_receive_external_purchase_order(); },
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/receipts/(?P<receipt_id>[a-zA-Z0-9\-]+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_receipt' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_receive_external_purchase_order(); },
			)
		);
	}

	/**
	 * Lists historical receipt evidence without posting inventory.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response Historical receipt rows.
	 */
	public function list_receipts( WP_REST_Request $request ) {
		global $wpdb;
		$table = Stockland_DB::table( 'receipts' );
		// This table is retained for historical/manual receipt evidence only. New
		// external supplier stock is received through an Owner-approved PO.
		return rest_ensure_response( Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT 500', $table ), ARRAY_A ) );
	}

	/**
	 * Rejects free-form supplier receipt creation under the PO approval SOP.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_Error Always returns the guarded purchase-order-required response.
	 */
	public function create_receipt( WP_REST_Request $request ) {
		// Free-form supplier receipts would bypass Management entry + separate Owner approval.
		// Fail before writes; use POST /accounting/purchase-orders and its approved receipt path.
		return new WP_Error(
			'purchase_order_required',
			'Direct supplier receipts are disabled by Stockland SOP. Management must create the Central Warehouse purchase order, a different Owner must approve it, and the Warehouse Manager must accept actual quantities from the approved PO action queue.',
			array( 'status' => 409 )
		);
	}

	/**
	 * Rejects completion of legacy manual receipts so it cannot post new stock.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_Error Always returns the guarded purchase-order-required response.
	 */
	public function complete_receipt( WP_REST_Request $request ) {
		// Historical pending manual receipts cannot be used to post fresh stock.
		// Completed historical receipts remain unchanged; reconcile pending entries against a PO.
		return new WP_Error(
			'purchase_order_required',
			'This legacy receipt cannot be posted directly. Reconcile it to an Owner-approved Central Warehouse purchase order. Previously completed historical transactions are unchanged.',
			array( 'status' => 409 )
		);
	}

	/**
	 * Retrieves a historical receipt by its public receipt identifier.
	 *
	 * @param string $receipt_id Receipt identifier.
	 * @return mixed Receipt row or null when no matching row exists.
	 */
	private function get_receipt( $receipt_id ) {
		global $wpdb;
		$table = Stockland_DB::table( 'receipts' );
		return Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE receipt_id = %s', $table, $receipt_id ), ARRAY_A );
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
