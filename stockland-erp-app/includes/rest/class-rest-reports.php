<?php
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
/**
 * Management dashboard and read-only business reporting endpoints.
 *
 * @package Stockland_ERP
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Management dashboard/report (section 16, mirrors COMPANY DASHBOARD +
 * REPORT sheets). Every value here is computed on read from the ledger,
 * sales, and returns tables — nothing is stored, exactly like the
 * original sheets being 100% formula cells. Requires
 * stockland_view_cost_and_profit (Management/Auditor only).
 *
 * @package Stockland_ERP
 */
class Stockland_REST_Reports extends Stockland_REST_Base {

	/**
	 * Registers the dashboard report REST route.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the management dashboard reporting endpoint.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/reports/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_cost_and_profit' ); },
			)
		);
	}

	/**
	 * Computes company/branch reporting values from authoritative ERP records.
	 *
	 * @param WP_REST_Request $request REST request with report scope filters.
	 * @return mixed REST response containing dashboard metrics or a WP_Error.
	 */
	public function get_dashboard( WP_REST_Request $request ) {
		global $wpdb;
		$locations    = Stockland_DB::table( 'locations' );
		$transactions = Stockland_DB::table( 'transactions' );
		$sales        = Stockland_DB::table( 'sales' );
		$wholesale    = Stockland_DB::table( 'wholesale_sales' );

		$stock_by_location = Stockland_DB::read_results(
			$wpdb->prepare(
				"SELECT l.location_name, COALESCE(SUM(b.physical_stock), 0) AS current_stock
             FROM %i l
             LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b ON b.location_id = l.id
             WHERE l.location_type IN ('Warehouse','Branch')
             GROUP BY l.id, l.location_name",
				$locations,
				$transactions
			),
			ARRAY_A
		);

		$retail_totals = Stockland_DB::read_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(sales_total),0) AS revenue,
                    COALESCE(SUM(net_sales_excl_vat),0) AS net_sales,
                    COALESCE(SUM(gross_profit),0) AS gross_profit,
                    COALESCE(SUM(vat_amount),0) AS vat,
                    COALESCE(SUM(quantity_sold),0) AS units_sold
             FROM %i WHERE posting_status = 'CLOSED'",
				$sales
			),
			ARRAY_A
		);

		$wholesale_totals = Stockland_DB::read_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(sales_value),0) AS revenue,
                    COALESCE(SUM(net_sales_excl_vat),0) AS net_sales,
                    COALESCE(SUM(gross_profit),0) AS gross_profit,
                    COALESCE(SUM(vat_amount),0) AS vat,
                    COALESCE(SUM(quantity),0) AS units_sold
             FROM %i',
				$wholesale
			),
			ARRAY_A
		);

		$inventory_value = Stockland_DB::read_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(b.physical_stock*p.purchase_cost),0)
             FROM (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b
             JOIN %i p ON p.variant_id=b.variant_id',
				$transactions,
				Stockland_DB::table( 'products' )
			)
		);

		$reorder_count = Stockland_DB::read_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
                SELECT p.sku FROM %i p
                CROSS JOIN %i l
                LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b ON b.variant_id = p.variant_id AND b.location_id = l.id
                WHERE l.location_type IN ('Warehouse','Branch')
                  AND COALESCE(b.physical_stock,0) <= IF(l.location_type='Warehouse', p.central_reorder_level, p.branch_reorder_level)
             ) t",
				Stockland_DB::table( 'products' ),
				$locations,
				$transactions
			)
		);

		return rest_ensure_response(
			array(
				'stock_by_location'   => $stock_by_location,
				'retail_sales'        => $retail_totals,
				'wholesale_sales'     => $wholesale_totals,
				'net_sales_total'     => (float) $retail_totals['net_sales'] + (float) $wholesale_totals['net_sales'],
				'gross_profit_total'  => (float) $retail_totals['gross_profit'] + (float) $wholesale_totals['gross_profit'],
				'vat_payable_total'   => (float) $retail_totals['vat'] + (float) $wholesale_totals['vat'],
				'inventory_value'     => (float) $inventory_value,
				'reorder_alert_count' => (int) $reorder_count,
			)
		);
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
