<?php
/**
 * Stockland ERP REST application endpoints and operational handlers.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class Stockland_REST_App extends Stockland_REST_Base {
	/**
	 * Initializes the ERP REST controller and registers its route hooks.
	 *
	 * @return void Initializes the controller.
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'routes' ) ); }

	/** User administration has a separate frontend gate so a technical WP administrator can
	 * activate ERP profiles without first needing an ERP profile of their own. */
	private function can_manage_users_frontend() {
		return is_user_logged_in() && ( current_user_can( 'manage_options' ) || Stockland_Authority::can_manage_users() );
	}
	/**
	 * Read a local plugin source file for read-only runtime contract diagnostics.
	 *
	 * @param string $file Absolute path to a local source or asset file.
	 * @return string|false File contents, or false when the file cannot be read.
	 */
	private function read_local_source_file( $file ) {
		if ( ! is_string( $file ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		try {
			$source_lines = array();
			$source_file  = new SplFileObject( $file, 'r' );
			foreach ( $source_file as $source_line ) {
				$source_lines[] = $source_line;
			}
			return implode( '', $source_lines );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Registers ERP REST endpoints and their permission callbacks.
	 *
	 * @return void Registers the route handlers.
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bootstrap' ),
				'permission_callback' => function () {
					return is_user_logged_in() && ( current_user_can( 'manage_options' ) || Stockland_Authority::assert_profile() );
				},
			)
		);
		register_rest_route(
			'stockland/v16',
			'/sku-search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sku_search' ),
				'permission_callback' => function () {
					return is_user_logged_in() && ( current_user_can( 'manage_options' ) || Stockland_Authority::assert_profile() );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'audit' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_audit_log' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_view_system_diagnostics() ? true : new WP_Error( 'forbidden', 'System diagnostics are restricted to administrators, Owner and Management.', array( 'status' => 403 ) );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/integrity',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return rest_ensure_response( Stockland_Integrity::scan() );
				},
				'permission_callback' => function () {
						return Stockland_Authority::can_view_integrity();
				},
			)
		);
		register_rest_route(
			'stockland/v16',
			'/system/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_view_system_diagnostics() ? true : new WP_Error( 'forbidden', 'System diagnostics are restricted to administrators, Owner and Management.', array( 'status' => 403 ) );
				},
			)
		);
		register_rest_route(
			'stockland/v16',
			'/system/runtime',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'runtime_diagnostics' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_view_system_diagnostics() ? true : new WP_Error( 'forbidden', 'System diagnostics are restricted to administrators, Owner and Management.', array( 'status' => 403 ) );
				},
			)
		);
		register_rest_route(
			'stockland/v16',
			'/system/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'users' ),
				'permission_callback' => function () {
					return $this->can_manage_users_frontend();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/users',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_user' ),
				'permission_callback' => function () {
					return $this->can_manage_users_frontend();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/users/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_user' ),
				'permission_callback' => function () {
					return $this->can_manage_users_frontend();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/users/available',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'available_wp_users' ),
				'permission_callback' => function () {
					return $this->can_manage_users_frontend();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/users/activate-existing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate_existing_user' ),
				'permission_callback' => function () {
					return $this->can_manage_users_frontend();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/access/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'access_bootstrap' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'settings' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_manage_settings' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_manage_settings' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/reports/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'reports_summary' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_branch_reports' ) || current_user_can( 'stockland_view_all_branches' ) || current_user_can( 'stockland_view_warehouse' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/operations/daily',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'daily_operations' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_branch_reports' ) || current_user_can( 'stockland_view_all_branches' ) || current_user_can( 'stockland_view_warehouse' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/operations/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'operations_overview' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_branch_reports' ) || current_user_can( 'stockland_view_all_branches' ) || current_user_can( 'stockland_view_warehouse' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/reconciliation',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'reconciliation_list' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_branch_reports' ) || current_user_can( 'stockland_view_all_branches' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/reconciliation',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_reconciliation' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_request_adjustment' ) || current_user_can( 'stockland_manage_settings' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/reconciliation/(?P<id>\d+)/decision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reconciliation_decision' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_manage_settings' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'counts_list' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_branch_reports' ) || current_user_can( 'stockland_view_all_branches' ) || current_user_can( 'stockland_view_warehouse' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/counts/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'count_preview' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_request_adjustment' ) || current_user_can( 'stockland_manage_settings' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/counts/commit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'count_commit' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_request_adjustment' ) || current_user_can( 'stockland_manage_settings' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/counts/(?P<id>\d+)/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'count_report' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_branch_reports' ) || current_user_can( 'stockland_view_all_branches' ) || current_user_can( 'stockland_view_warehouse' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/counts/(?P<id>\d+)/decision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'count_decision' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_manage_settings' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/workflow/(?P<type>[a-zA-Z0-9_-]+)/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'workflow_history' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/app/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'notifications' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
	}
	/**
	 * Guarantee the first native WordPress administrator has a Stockland ERP profile.
	 * This is the one-time bootstrap bridge: it does not grant this path to ordinary
	 * users and does not touch inventory/finance/business data. The existing ERP role
	 * model remains the source of truth; the native administrator is simply made
	 * reachable from the ERP on a fresh install or after an incomplete upgrade.
	 */
	private function ensure_native_admin_profile() {
		static $checked = false;
		if ( $checked ) {
			return true;
		}
		$checked = true;
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		global $wpdb;
		$table  = Stockland_DB::table( 'users' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return false;
		}
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,role,is_active FROM %i WHERE wp_user_id=%d LIMIT 1', $table, $uid ), ARRAY_A );
		if ( ! $row ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (wp_user_id,role,branch_location_id,is_active) VALUES (%d,'owner',NULL,1)", $table, $uid ) );
			if ( $wpdb->last_error ) {
				return false;
			}
		} elseif ( 1 !== (int) $row['is_active'] || 'owner' !== (string) $row['role'] ) {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET role='owner',branch_location_id=NULL,is_active=1 WHERE wp_user_id=%d", $table, $uid ) );
			if ( $wpdb->last_error ) {
				return false;
			}
		}
		return (bool) Stockland_Roles::get_profile( $uid );
	}

	/**
	 * Synchronize only the safe WordPress identity fields used by the ERP UI.
	 *
	 * @param int   $wp_user_id WordPress user ID to update or validate.
	 * @param array $payload Validated user or record fields supplied for this operation.
	 */
	private function sync_user_identity( $wp_user_id, $payload ) {
		$id = (int) $wp_user_id;
		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return false;
		}
		$data = array( 'ID' => $id );
		if ( array_key_exists( 'display_name', (array) $payload ) ) {
			$data['display_name'] = sanitize_text_field( (string) $payload['display_name'] );
		}
		if ( array_key_exists( 'email', (array) $payload ) ) {
			$data['user_email'] = sanitize_email( (string) $payload['email'] );
		}
		if ( count( $data ) === 1 ) {
			return true;
		}
		$updated = wp_update_user( $data );
		return ! is_wp_error( $updated ) && (int) $updated === $id;
	}

	/** Selected/default location for workflows that must act on exactly one location. */
	private function scope_location() {
		$p = Stockland_Roles::get_profile( get_current_user_id() );
		if ( ! $p ) {
			return false;
		}
		if ( current_user_can( 'stockland_view_all_branches' ) ) {
			return null;
		}
		$ids = Stockland_Authority::location_ids();
		return $ids ? (int) $ids[0] : false;
	}

	/** Read scope for screens that may aggregate across every authorized location. */
	private function scope_locations() {
		if ( Stockland_Authority::company_scope() ) {
			return null;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', Stockland_Authority::location_ids() ) ) ) );
		return $ids ? $ids : false;
	}

	/**
	 * Append an IN (...) filter for a location column. Null means company-wide; false means deny.
	 *
	 * @param string         $column Internal SQL column used to apply location scope.
	 * @param array          $args Prepared SQL arguments; values are appended in placeholder order.
	 * @param array|int|null $scope Authorized location scope for the query.
	 */
	private function append_scope_filter( $column, &$args, $scope ) {
		if ( null === $scope ) {
			return '';
		}
		if ( false === $scope ) {
			return ' AND 1=0';
		}
		$ph = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
		foreach ( $scope as $id ) {
			$args[] = $id;
		}
		return ' AND ' . $column . ' IN(' . $ph . ')';
	}

	/**
	 * Builds the initial role-scoped application bootstrap payload.
	 *
	 * @return mixed The operation result.
	 */
	public function bootstrap() {
		// Native WP administrators are the fresh-install bootstrap account. Their
		// ERP profile is created lazily if setup completed without the finalization
		// request running under an authenticated administrator context.
		$this->ensure_native_admin_profile();
		$scope = $this->scope_locations();
		if ( false === $scope ) {
			return new WP_Error( 'no_profile', 'This account has no Stockland role or authorized location scope.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$location_table = Stockland_DB::table( 'locations' );
		$central_id     = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		if ( Stockland_Authority::company_scope() ) {
			$locs             = $wpdb->get_results( $wpdb->prepare( 'SELECT id,location_name,location_type,role_description,is_active FROM %i WHERE is_active=1 ORDER BY id', $location_table ), ARRAY_A );
			$central_location = $central_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id,location_name,location_type,role_description,is_active FROM %i WHERE id=%d LIMIT 1', $location_table, $central_id ), ARRAY_A ) : null;
		} else {
			$allowed = array_values( array_unique( array_filter( array_map( 'intval', $scope ) ) ) );
			// Do not inject Central Warehouse into the ordinary operational location
			// selector for branch users. Central visibility is a separate read-only
			// capability exposed through the Live ERP availability / availability endpoints.
			if ( ! $allowed ) {
				$locs = array();
			} else {
				$ph   = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
				$locs = $wpdb->get_results( $wpdb->prepare( 'SELECT id,location_name,location_type,role_description,is_active FROM %i WHERE is_active=1 AND id IN(' . $ph . ') ORDER BY id', array_merge( array( $location_table ), $allowed ) ), ARRAY_A );
			}
			$central_location = $central_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id,location_name,location_type,role_description,is_active FROM %i WHERE id=%d LIMIT 1', $location_table, $central_id ), ARRAY_A ) : null;
		}
		$products = current_user_can( 'stockland_view_cost_and_profit' )
			? $wpdb->get_results( $wpdb->prepare( "SELECT variant_id,sku,product_name,product_type,parent_sku,style_code,brand,department,sub_category,color,color_code,size,size_code,size_system,barcode,gtin,category,gender,age_group,unit,purchase_cost,retail_price,wholesale_price,notes,branch_reorder_level,central_reorder_level FROM %i WHERE is_active=1 AND product_type<>'STYLE' ORDER BY COALESCE(parent_sku,sku), color, size, sku", Stockland_DB::table( 'products' ) ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT variant_id,sku,product_name,product_type,parent_sku,style_code,brand,department,sub_category,color,color_code,size,size_code,size_system,barcode,gtin,category,gender,age_group,unit,retail_price,wholesale_price,notes,branch_reorder_level,central_reorder_level FROM %i WHERE is_active=1 AND product_type<>'STYLE' ORDER BY COALESCE(parent_sku,sku), color, size, sku", Stockland_DB::table( 'products' ) ), ARRAY_A );

		$args  = array();
		$sql   = "SELECT v.location_id,v.variant_id,p.sku,p.product_name,p.product_type,p.parent_sku,p.style_code,p.brand,p.color,p.color_code,p.size,p.size_code,p.size_system,p.barcode,p.gtin,p.category,p.gender,p.age_group,p.unit,p.retail_price,p.wholesale_price,v.physical_stock,COALESCE(r.reserved_stock,0) reserved_stock,v.physical_stock-COALESCE(r.reserved_stock,0) available_stock
              FROM (SELECT t.location_id,t.variant_id,SUM(t.quantity_signed) physical_stock FROM %i t WHERE t.variant_id IS NOT NULL GROUP BY t.location_id,t.variant_id) v
              JOIN %i p ON p.variant_id=v.variant_id
              LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_reserved) reserved_stock FROM %i WHERE status='Active' GROUP BY location_id,variant_id) r
                ON r.location_id=v.location_id AND r.variant_id=p.variant_id
              WHERE p.product_type<>'STYLE'";
		$sql  .= $this->append_scope_filter( 'v.location_id', $args, $scope );
		$sql  .= ' ORDER BY v.location_id,p.sku';
		$sql_args = array_merge( array( Stockland_DB::table( 'transactions' ), Stockland_DB::table( 'products' ), Stockland_DB::table( 'reservations' ) ), $args );
		$stock = $wpdb->get_results( $wpdb->prepare( $sql, $sql_args ), ARRAY_A );

		$dashboard = $this->dashboard( $scope );
		$can_cost  = (bool) current_user_can( 'stockland_view_cost_and_profit' );
		if ( ! $can_cost ) {
			$dashboard['gross_profit']    = null;
			$dashboard['net_sales']       = null;
			$dashboard['vat']             = null;
			$dashboard['inventory_value'] = null;
			$dashboard['month_sales']     = null;
			$dashboard['year_sales']      = null;}
		$profile = Stockland_Roles::get_profile( get_current_user_id() );
		return rest_ensure_response(
			array(
				'user'               => array(
					'id'                 => get_current_user_id(),
					'name'               => wp_get_current_user()->display_name,
					'role'               => $profile['role'] ?? '',
					'branch_location_id' => $profile['branch_location_id'] ?? null,
					'location_ids'       => array_values( array_map( 'intval', Stockland_Authority::location_ids() ) ),
					'can_all'            => (bool) current_user_can( 'stockland_view_all_branches' ),
					'can_cost'           => $can_cost,
					'capabilities'       => array(
						'settings'               => (bool) current_user_can( 'stockland_manage_settings' ),
						'products'               => (bool) current_user_can( 'stockland_manage_products' ),
						'hr_view'                => (bool) Stockland_Authority::can_view_hr(),
						'hr_manage'              => (bool) Stockland_Authority::can_manage_hr(),
						'payroll_view'           => (bool) Stockland_Authority::can_view_payroll(),
						'payroll_manage'         => (bool) Stockland_Authority::can_manage_payroll(),
						'payroll_approve'        => (bool) Stockland_Authority::can_approve_payroll(),
						'sale'                   => (bool) current_user_can( 'stockland_create_sale' ),
						'wholesale'              => (bool) current_user_can( 'stockland_create_wholesale' ),
						'warehouse'              => (bool) current_user_can( 'stockland_view_warehouse' ),
						'receive_warehouse'      => (bool) current_user_can( 'stockland_receive_warehouse' ),
						'transfer'               => (bool) current_user_can( 'stockland_manage_transfers' ),
						'approve_transfer'       => (bool) current_user_can( 'stockland_approve_transfers' ),
						'request'                => (bool) current_user_can( 'stockland_request_stock' ),
						'review_request'         => (bool) current_user_can( 'stockland_review_requisitions' ),
						'close'                  => (bool) current_user_can( 'stockland_close_day' ),
						'return'                 => (bool) current_user_can( 'stockland_record_return' ),
						'receive_transfer'       => (bool) current_user_can( 'stockland_receive_transfer' ),
						'request_adjustment'     => (bool) current_user_can( 'stockland_request_adjustment' ),
						'reports'                => (bool) ( current_user_can( 'stockland_view_branch_reports' ) || current_user_can( 'stockland_view_all_branches' ) || current_user_can( 'stockland_view_warehouse' ) ),
						'finance_view'           => (bool) current_user_can( 'stockland_view_financials' ),
						'finance_manage'         => (bool) current_user_can( 'stockland_manage_settings' ),
						'finance'                => (bool) current_user_can( 'stockland_view_financials' ),
						'manage_suppliers'       => (bool) current_user_can( 'stockland_manage_suppliers' ),
						'manage_customers'       => (bool) current_user_can( 'stockland_manage_customers' ),
						'manage_po'              => (bool) current_user_can( 'stockland_manage_purchase_orders' ),
						'po_create'              => (bool) Stockland_Authority::can_create_external_purchase_order(),
						'po_approve'             => (bool) Stockland_Authority::can_approve_external_purchase_order(),
						'po_receive'             => (bool) Stockland_Authority::can_receive_external_purchase_order(),
						'manage_payments'        => (bool) current_user_can( 'stockland_manage_payments' ),
						'manage_expenses'        => (bool) ( in_array( Stockland_Authority::role(), array( 'owner', 'management', 'branch_manager' ), true ) && current_user_can( 'stockland_manage_expenses' ) ),
						'expense_approve'        => (bool) Stockland_Authority::can_approve_expenses(),
						'finance_approve'        => (bool) Stockland_Authority::can_approve_finance(),
						'manage_payables'        => (bool) current_user_can( 'stockland_manage_payables' ),
						'manage_receivables'     => (bool) current_user_can( 'stockland_manage_receivables' ),
						'view_financials'        => (bool) current_user_can( 'stockland_view_financials' ),
						'daily_import'           => (bool) Stockland_Authority::can_daily_import(),
						'daily_post'             => (bool) Stockland_Authority::can_daily_commit(),
						'product_import'         => (bool) Stockland_Authority::can_product_import(),
						'approve_management'     => (bool) Stockland_Authority::can_approve_requisition( 'management' ),
						'approve_owner'          => (bool) Stockland_Authority::can_approve_requisition( 'owner' ),
						'manage_locations'       => (bool) Stockland_Authority::can_manage_locations(),
						'manage_users'           => (bool) Stockland_Authority::can_manage_users(),
						'install_schema'         => (bool) current_user_can( 'stockland_install_schema' ),
						'warehouse_availability' => (bool) Stockland_Authority::can_view_warehouse_availability(),
						'system_health'          => (bool) Stockland_Authority::can_view_system_health(),
						'branch_ops'             => (bool) ( current_user_can( 'stockland_manage_customers' ) || current_user_can( 'stockland_manage_expenses' ) ),
					),
				),
				'settings'           => array(
					'vat_rate'                    => Stockland_DB::get_vat_rate(),
					'vat_basis'                   => Stockland_DB::get_vat_basis(),
					'business_name'               => Stockland_DB::get_setting( 'business_name', 'Stockland Ethiopia' ),
					'tagline'                     => Stockland_DB::get_setting( 'tagline', 'Brand Apparel — Central Warehouse & Branch Retail' ),
					'brand_logo'                  => STOCKLAND_PLUGIN_URL . 'assets/stockland-logo.jpg',
					'brand_logo_source'           => 'WordPress Media Library asset supplied by Stockland',
					'branch_reorder_alert_level'  => (int) Stockland_DB::get_setting( 'branch_reorder_alert_level', '2' ),
					'central_reorder_alert_level' => (int) Stockland_DB::get_setting( 'central_reorder_alert_level', '200' ),
				),
				'brand'              => array(
					'name'        => 'Stockland Ethiopia',
					'tagline'     => 'Global Brands. Local Access.',
					'description' => 'Addis Ababa retailer and distributor of export-quality American and European brand-name apparel sourced through Ethiopia’s apparel and industrial-park supply chain, serving retail customers, wholesale buyers and online resellers.',
					'categories'  => array( 'Women', 'Men', 'Kids', 'Newborn & Baby', 'Sports & Outdoor', 'Swimwear', 'Intimate & Basics', 'Jackets', 'Nightwear', 'Jeans', 'T-Shirts', 'Polo & Dress Shirts', 'Hoodies & Fleece', 'Underwear & Socks' ),
					'locations'   => array_values(
						array_map(
							function ( $location ) {
									return array(
										'name'   => (string) ( $location['location_name'] ?? '' ),
										'detail' => (string) ( $location['role_description'] ?? $location['location_type'] ?? '' ),
									);},
							(array) $locs
						)
					),
					'website'     => home_url( '/' ),
					'logo_source' => 'WordPress Media Library asset supplied by Stockland',
				),
				'locations'          => $locs,
				'central_location'   => $central_location,
				'central_visibility' => array(
					'allowed'   => (bool) Stockland_Authority::can_view_warehouse_availability(),
					'read_only' => (bool) ! current_user_can( 'stockland_manage_reservations' ),
				),
				'products'           => $products,
				'stock'              => $stock,
				'transfers'          => $this->transfer_snapshot( $scope ),
				'dashboard'          => $dashboard,
			)
		);
	}

	/**
	 * Loads a recent transfer snapshot limited to the user's authorized locations.
	 *
	 * @param array|int|null $scope Authorized location scope for the query.
	 * @return mixed The operation result.
	 */
	private function transfer_snapshot( $scope = null ) {
		global $wpdb;
		$args = array(
			Stockland_DB::table( 'transfers' ),
			Stockland_DB::table( 'locations' ),
			Stockland_DB::table( 'locations' ),
			Stockland_DB::table( 'products' ),
		);
		$sql  = 'SELECT t.transfer_id,t.transfer_date,t.from_location_id,t.to_location_id,t.sku,t.quantity,t.status,f.location_name from_location_name,to_l.location_name to_location_name,p.product_name
              FROM %i t
              JOIN %i f ON f.id=t.from_location_id
              JOIN %i to_l ON to_l.id=t.to_location_id
              JOIN %i p ON (p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku)) WHERE 1=1';
		if ( is_array( $scope ) ) {
			$ids = array_values( array_unique( array_map( 'intval', $scope ) ) );
			if ( empty( $ids ) ) {
				return array();
			}
			$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$args = array_merge( $args, $ids, $ids );
			$sql .= " AND (t.from_location_id IN({$ph}) OR t.to_location_id IN({$ph}))";
		} elseif ( null !== $scope ) {
			$args[] = (int) $scope;
			$args[] = (int) $scope;
			$sql   .= ' AND (t.from_location_id=%d OR t.to_location_id=%d)';
		}
		$sql .= ' ORDER BY t.created_at DESC LIMIT 100';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	/**
	 * Builds dashboard totals and operational summaries within the user's authority scope.
	 *
	 * @param array|int|null $scope Authorized location scope for the query.
	 * @return mixed The operation result.
	 */
	private function dashboard( $scope = null ) {
		global $wpdb;
		$sales       = Stockland_DB::table( 'sales' );
		$wh          = Stockland_DB::table( 'wholesale_sales' );
		$tx          = Stockland_DB::table( 'transactions' );
		$loc         = Stockland_DB::table( 'locations' );
		$prod        = Stockland_DB::table( 'products' );
		$scope_ids   = is_array( $scope ) ? array_values( array_unique( array_map( 'intval', $scope ) ) ) : ( null !== $scope ? array( (int) $scope ) : null );
		$sales_where = '';
		$sales_args  = array();
		if ( is_array( $scope_ids ) ) {
			$ph          = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$sales_where = " AND branch_location_id IN({$ph})";
			$sales_args  = $scope_ids;
		} elseif ( null !== $scope_ids ) {
			$sales_where = ' AND branch_location_id=%d';
			$sales_args  = $scope_ids;}
			$rt_sql = "SELECT COALESCE(SUM(sales_total),0) revenue,COALESCE(SUM(net_sales_excl_vat),0) net,COALESCE(SUM(gross_profit),0) profit,COALESCE(SUM(vat_amount),0) vat,COALESCE(SUM(quantity_sold),0) units FROM %i WHERE posting_status='CLOSED'{$sales_where}";
			$rt     = $wpdb->get_row( $wpdb->prepare( $rt_sql, array_merge( array( $sales ), $sales_args ) ), ARRAY_A );
			// Wholesale sales are company-level in the current data model and are therefore
			// included only for company-wide dashboards, not branch-scoped dashboards.
			$wt = null !== $scope_ids ? array(
				'revenue' => 0,
				'net'     => 0,
				'profit'  => 0,
				'vat'     => 0,
				'units'   => 0,
			) : $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(sales_value),0) revenue,COALESCE(SUM(net_sales_excl_vat),0) net,COALESCE(SUM(gross_profit),0) profit,COALESCE(SUM(vat_amount),0) vat,COALESCE(SUM(quantity),0) units FROM %i", $wh ), ARRAY_A );

			$inv_sql  = "SELECT COALESCE(SUM(b.physical_stock*p.purchase_cost),0) FROM (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b JOIN %i p ON p.variant_id=b.variant_id";
			$inv_args = array( $tx, $prod );
			if ( is_array( $scope_ids ) ) {
				$ph       = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
				$inv_sql .= " WHERE b.location_id IN({$ph})";
				$inv_args = array_merge( $inv_args, $scope_ids );
			} elseif ( null !== $scope_ids ) {
				$inv_sql         .= ' WHERE b.location_id=%d';
						$inv_args = array_merge( $inv_args, $scope_ids );}
			$inv = (float) $wpdb->get_var( $wpdb->prepare( $inv_sql, $inv_args ) );

			$low_sql  = "SELECT COUNT(*) FROM %i p CROSS JOIN %i l LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b ON b.variant_id=p.variant_id AND b.location_id=l.id WHERE p.is_active=1 AND l.location_type IN('Warehouse','Branch')";
			$low_args = array( $prod, $loc, $tx );
			if ( is_array( $scope_ids ) ) {
				$ph       = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
				$low_sql .= " AND l.id IN({$ph})";
				$low_args = array_merge( $low_args, $scope_ids );
			} elseif ( null !== $scope_ids ) {
				$low_sql         .= ' AND l.id=%d';
						$low_args = array_merge( $low_args, $scope_ids );}
			$low_sql .= " AND COALESCE(b.physical_stock,0)<=IF(l.location_type='Warehouse',p.central_reorder_level,p.branch_reorder_level)";
			$low      = (int) $wpdb->get_var( $wpdb->prepare( $low_sql, $low_args ) );

			$today       = 'Y-m-d';
			$today_value = current_time( $today );
			$today_args  = array( $today_value );
			$today_where = $sales_where;
			$today_args  = array_merge( $today_args, $sales_args );
			$today_sql   = "SELECT COALESCE(SUM(sales_total),0) FROM %i WHERE posting_status='CLOSED' AND sale_date=%s{$today_where}";
			$today_sales = (float) $wpdb->get_var( $wpdb->prepare( $today_sql, array_merge( array( $sales ), $today_args ) ) );

			$req_sql  = "SELECT COUNT(*) FROM %i WHERE status IN('Requested','Reviewed','Approved','Reserved','Picked')";
			$req_args = array( Stockland_DB::table( 'requisitions' ) );
			if ( is_array( $scope_ids ) ) {
				$ph       = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
				$req_sql .= " AND branch_location_id IN({$ph})";
				$req_args = array_merge( $req_args, $scope_ids );
			} elseif ( null !== $scope_ids ) {
				$req_sql         .= ' AND branch_location_id=%d';
						$req_args = array_merge( $req_args, $scope_ids );}
			$pending = (int) $wpdb->get_var( $wpdb->prepare( $req_sql, $req_args ) );

			$approval_queue       = 0;
			$warehouse_queue      = 0;
			$receiving_exceptions = 0;
			if ( Stockland_Authority::is_management() || Stockland_Authority::is_owner() ) {
				$approval_queue       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='Reviewed'", Stockland_DB::table( 'requisitions' ) ) );
				$receiving_exceptions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='Received' AND ABS(COALESCE(variance_qty,0))>0.00001", Stockland_DB::table( 'transfers' ) ) );
			}
			if ( Stockland_Authority::can_warehouse_operate_requisition() ) {
				$warehouse_queue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN('Approved','Reserved')", Stockland_DB::table( 'requisitions' ) ) );
			}

			$pr_sql  = "SELECT COUNT(*) FROM %i WHERE status='Picked'";
			$pr_args = array( Stockland_DB::table( 'transfers' ) );
			if ( is_array( $scope_ids ) ) {
				$ph      = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
				$pr_sql .= " AND (from_location_id IN({$ph}) OR to_location_id IN({$ph}))";
				$pr_args = array_merge( $pr_args, $scope_ids, $scope_ids );
			} elseif ( null !== $scope_ids ) {
				$pr_sql         .= ' AND (from_location_id=%d OR to_location_id=%d)';
						$pr_args = array_merge( $pr_args, array( (int) $scope_ids[0], (int) $scope_ids[0] ) );}
				$pending_branch_receipts = (int) $wpdb->get_var( $wpdb->prepare( $pr_sql, $pr_args ) );

				$month_sql = "SELECT COALESCE(SUM(sales_total),0) FROM %i WHERE posting_status='CLOSED' AND sale_date>=DATE_FORMAT(CURDATE(),'%%Y-%%m-01'){$sales_where}";
				$month     = (float) $wpdb->get_var( $wpdb->prepare( $month_sql, array_merge( array( $sales ), $sales_args ) ) );
				$year_sql  = "SELECT COALESCE(SUM(sales_total),0) FROM %i WHERE posting_status='CLOSED' AND YEAR(sale_date)=YEAR(CURDATE()){$sales_where}";
				$year      = (float) $wpdb->get_var( $wpdb->prepare( $year_sql, array_merge( array( $sales ), $sales_args ) ) );

				$stock_sql  = "SELECT l.id,l.location_name,COALESCE(SUM(b.physical_stock),0) stock FROM %i l LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b ON b.location_id=l.id WHERE l.location_type IN('Warehouse','Branch')";
				$stock_args = array( $loc, $tx );
			if ( is_array( $scope_ids ) ) {
				$ph         = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
				$stock_sql .= " AND l.id IN({$ph})";
				$stock_args = array_merge( $stock_args, $scope_ids );
			} elseif ( null !== $scope_ids ) {
				$stock_sql         .= ' AND l.id=%d';
						$stock_args = array_merge( $stock_args, $scope_ids );}
				$stock_sql        .= ' GROUP BY l.id ORDER BY l.id';
				$stock_by_location = $wpdb->get_results( $wpdb->prepare( $stock_sql, $stock_args ), ARRAY_A );

				$stock_total_sql  = "SELECT COALESCE(SUM(b.physical_stock),0) FROM (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b";
				$stock_total_args = array( $tx );
			if ( is_array( $scope_ids ) ) {
				$stock_total_sql  .= ' WHERE b.location_id IN(' . implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) ) . ')';
				$stock_total_args = array_merge( $stock_total_args, array_map( 'intval', $scope_ids ) );
			} elseif ( null !== $scope_ids ) {
				$stock_total_sql  .= ' WHERE b.location_id=%d';
				$stock_total_args = array_merge( $stock_total_args, array_map( 'intval', $scope_ids ) );
			}
				$stock_total = (float) $wpdb->get_var( $wpdb->prepare( $stock_total_sql, $stock_total_args ) );

				return array(
					'today_sales'             => $today_sales,
					'stock_total'             => $stock_total,
					'month_sales'             => $month,
					'year_sales'              => $year,
					'net_sales'               => (float) $rt['net'] + (float) $wt['net'],
					'gross_profit'            => (float) $rt['profit'] + (float) $wt['profit'],
					'vat'                     => (float) $rt['vat'] + (float) $wt['vat'],
					'inventory_value'         => $inv,
					'low_stock'               => $low,
					'pending_requests'        => $pending,
					'approval_queue'          => $approval_queue,
					'warehouse_queue'         => $warehouse_queue,
					'receiving_exceptions'    => $receiving_exceptions,
					'pending_branch_receipts' => $pending_branch_receipts,
					'units_sold'              => (float) $rt['units'] + (float) $wt['units'],
					'stock_by_location'       => $stock_by_location,
				);
	}

	/**
	 * Returns a lightweight service-status response.
	 *
	 * @return mixed The operation result.
	 */
	public function ping() {
		return rest_ensure_response(
			array(
				'ok'              => true,
				'plugin_version'  => STOCKLAND_VERSION,
				'db_version'      => get_option( 'stockland_db_version', 'unknown' ),
				'rest_namespace'  => 'stockland/v16',
				'server_time'     => current_time( 'mysql' ),
				'execution_model' => 'BROWSER_RESUMABLE_BATCHES',
				'business_data'   => 'LIVE_DATABASE_ONLY',
			)
		);
	}
	/**
	 * Searches the product master for matching SKU or product identifiers.
	 *
	 * @param WP_REST_Request $request REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function sku_search( WP_REST_Request $request ) {
		global $wpdb;
		try {
			$table = Stockland_DB::table( 'products' );
			if ( ! Stockland_DB::table_exists( $table ) ) {
				return new WP_Error( 'sku_search_unavailable', 'Product Master is not available. Complete the verified 49-table setup first.', array( 'status' => 503 ) );
			}
			// In-place compatibility: old/new frontend caches may use either parameter name.
			$q = trim( (string) $request->get_param( 'q' ) );
			if ( '' === $q ) {
				$q = trim( (string) $request->get_param( 'search' ) );
			}
			$requested_limit = $request->get_param( 'limit' );
			$limit           = min( 200, max( 1, (int) ( $requested_limit ? $requested_limit : 100 ) ) );
			$args = array( $table );
			$sql  = "SELECT variant_id,sku,product_name,product_type,parent_sku,style_code,brand,color,size,barcode,gtin FROM %i WHERE is_active=1 AND product_type<>'STYLE' ORDER BY sku ASC LIMIT %d";
			if ( '' !== $q ) {
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				$sql  = "SELECT variant_id,sku,product_name,product_type,parent_sku,style_code,brand,color,size,barcode,gtin FROM %i WHERE is_active=1 AND product_type<>'STYLE' AND (sku LIKE %s OR product_name LIKE %s OR parent_sku LIKE %s OR style_code LIKE %s OR brand LIKE %s OR color LIKE %s OR size LIKE %s OR barcode LIKE %s OR gtin LIKE %s) ORDER BY CASE WHEN sku=%s THEN 0 WHEN barcode=%s THEN 1 WHEN gtin=%s THEN 2 ELSE 3 END, sku ASC LIMIT %d";
				$args = array_merge( $args, array_fill( 0, 9, $like ), array( $q, $q, $q ) );
			}
			$args[] = $limit;
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			if ( $wpdb->last_error ) {
				error_log( 'Stockland SKU search DB error: ' . $wpdb->last_error );
				return new WP_Error( 'sku_search_failed', 'Live SKU search could not be completed. No ERP data was changed.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( is_array( $rows ) ? $rows : array() );
		} catch ( Throwable $e ) {
			error_log( 'Stockland SKU search runtime error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			return new WP_Error( 'sku_search_failed', 'Live SKU search encountered a server error. No ERP data was changed.', array( 'status' => 500 ) );
		}
	}

	/**
	 * Returns permitted audit-log entries for the current ERP user.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function audit( WP_REST_Request $r ) {
		global $wpdb;
		$t    = Stockland_DB::table( 'audit_logs' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*,u.display_name,l.location_name FROM %i a LEFT JOIN %i u ON u.ID=a.user_id LEFT JOIN %i l ON l.id=a.branch_location_id ORDER BY a.action_time DESC LIMIT 500', $t, $wpdb->users, Stockland_DB::table( 'locations' ) ), ARRAY_A );
		return rest_ensure_response( $rows );}
	/** Minimal read-only endpoint that remains usable even when a larger health query fails. */
	public function runtime_diagnostics() {
		global $wpdb;
		$out  = array(
			'plugin'      => STOCKLAND_VERSION,
			'environment' => Stockland_Runtime_Diagnostics::environment(),
			'last_fatal'  => Stockland_Runtime_Diagnostics::last_fatal(),
			'schema'      => array(),
		);
		$need = array(
			'products'     => array( 'variant_id', 'sku', 'product_name', 'product_type', 'parent_sku', 'brand', 'color', 'size', 'barcode', 'gtin', 'purchase_cost', 'retail_price', 'wholesale_price', 'branch_reorder_level', 'central_reorder_level', 'is_active' ),
			'sales'        => array( 'sale_id', 'sale_date', 'branch_location_id', 'sku', 'variant_id', 'quantity_sold', 'selling_price', 'vat_mode', 'vat_rate_used', 'sales_total', 'vat_amount', 'net_sales_excl_vat', 'cogs', 'gross_profit', 'posting_status' ),
			'transactions' => array( 'location_id', 'sku', 'variant_id', 'movement_type', 'quantity_signed', 'reference_type', 'reference_id', 'source_line_id', 'transaction_date' ),
			'balances'     => array( 'location_id', 'sku', 'variant_id', 'physical_qty' ),
			'reservations' => array( 'location_id', 'sku', 'variant_id', 'quantity_reserved', 'status' ),
			'locations'    => array( 'id', 'location_name', 'location_type', 'is_active' ),
			'settings'     => array( 'setting_key', 'setting_value', 'updated_by', 'updated_at' ),
			'import_jobs'  => array( 'id', 'import_key', 'import_type', 'source_filename', 'source_sha256', 'source_system', 'status' ),
			'import_rows'  => array( 'id', 'job_id', 'source_row_number', 'status' ),
		);
		foreach ( $need as $logical => $cols ) {
			try {
				$table = Stockland_DB::table( $logical );
				if ( ! Stockland_DB::table_exists( $table ) ) {
					$out['schema'][ $logical ] = array(
						'table_present'   => false,
						'missing_columns' => $cols,
					);
					continue;}
				$present = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
				if ( $wpdb->last_error ) {
					$out['schema'][ $logical ] = array(
						'table_present' => true,
						'query_error'   => 'SHOW COLUMNS failed',
					);
					continue;}
				$out['schema'][ $logical ] = array(
					'table_present'   => true,
					'missing_columns' => array_values( array_diff( $cols, (array) $present ) ),
				);
			} catch ( Throwable $e ) {
				$out['schema'][ $logical ] = array(
					'table_present' => null,
					'query_error'   => substr( str_replace( array( "\r", "\n" ), ' ', $e->getMessage() ), 0, 300 ),
				);}
		}
		return rest_ensure_response( $out );
	}

	/** Fast, read-only health gate; expensive row-level integrity scans are explicitly deferred. */
	private function health_quick() {
		global $wpdb;
		$checks      = array();
		$diagnostics = array();
		$db_value    = null;
		try {
			$db_value = $wpdb->get_var( 'SELECT 1' );
		} catch ( Throwable $e ) {
			$db_value = null;
		}
		$checks['db']                         = ( '1' === (string) $db_value && empty( $wpdb->last_error ) );
		$diagnostics['health_mode']           = 'quick';
		$diagnostics['deep_integrity_status'] = 'DEFERRED_NOT_RUN';
		$diagnostics['php_runtime']           = PHP_VERSION;
		$diagnostics['wordpress_runtime']     = get_bloginfo( 'version' );
		$diagnostics['database_server']       = $checks['db'] ? (string) $wpdb->get_var( 'SELECT VERSION()' ) : 'unavailable';
		$diagnostics['upload_max_filesize']   = (string) ini_get( 'upload_max_filesize' );
		$diagnostics['post_max_size']         = (string) ini_get( 'post_max_size' );
		$diagnostics['memory_limit']          = (string) ini_get( 'memory_limit' );
		$diagnostics['max_execution_time']    = (string) ini_get( 'max_execution_time' );
		$diagnostics['runtime_environment']   = array(
			'php_version'            => PHP_VERSION,
			'wordpress_version'      => get_bloginfo( 'version' ),
			'memory_limit'           => (string) ini_get( 'memory_limit' ),
			'max_execution_time'     => (string) ini_get( 'max_execution_time' ),
			'upload_max_filesize'    => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'          => (string) ini_get( 'post_max_size' ),
			'zip_archive'            => class_exists( 'ZipArchive' ),
			'xml_reader'             => class_exists( 'XMLReader' ),
			'xml_reader_from_stream' => class_exists( 'XMLReader' ) && method_exists( 'XMLReader', 'fromStream' ),
			'simplexml'              => class_exists( 'SimpleXMLElement' ),
			'db_select_ok'           => $checks['db'],
		);
		$diagnostics['last_fatal']            = Stockland_Runtime_Diagnostics::last_fatal();
		$checks['php_runtime']                = version_compare( PHP_VERSION, '8.4', '>=' );
		$to_bytes                             = function ( $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return 0;
			}
			$last = strtolower( substr( $value, -1 ) );
			$n    = (float) $value;
			switch ( $last ) {
				case 'g':
					$n *= 1024;
				case 'm':
					$n *= 1024;
				case 'k':
					$n *= 1024; }
			return (int) $n;
		};
		$checks['xlsx_upload_capacity']       = $to_bytes( ini_get( 'upload_max_filesize' ) ) >= Stockland_XLSX::MAX_FILE_BYTES && $to_bytes( ini_get( 'post_max_size' ) ) >= Stockland_XLSX::MAX_FILE_BYTES;
		$checks['xlsx_reader']                = class_exists( 'ZipArchive' ) && class_exists( 'SimpleXMLElement' );
		$checks['xlsx_streaming_reader']      = class_exists( 'ZipArchive' ) && class_exists( 'XMLReader' );
		$checks['xlsx_xmlreader_stream']      = ! empty( $diagnostics['runtime_environment']['xml_reader_from_stream'] );
		$checks['logo_asset']                 = file_exists( STOCKLAND_PLUGIN_DIR . 'assets/stockland-logo.jpg' );
		$checks['ssl']                        = is_ssl() || strpos( home_url(), 'localhost' ) !== false;

		$core_expected       = Stockland_Activator::core_table_keys();
		$accounting_expected = array( 'suppliers', 'customers', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'supplier_invoices', 'supplier_invoice_items', 'customer_invoices', 'invoice_items', 'expenses', 'payments', 'payment_allocations', 'gl_accounts', 'gl_journals', 'gl_lines' );
		$hr_expected         = array( 'employees', 'employee_compensation', 'payroll_periods', 'payroll_lines' );
		$full_expected       = array_values( array_unique( array_merge( $core_expected, $accounting_expected, $hr_expected ) ) );
		$prefix              = $wpdb->prefix . 'stock_';
		$found               = array();
		if ( $checks['db'] ) {
			$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		}
		$found_keys = array();
		foreach ( (array) $found as $table ) {
			if ( strpos( $table, $prefix ) === 0 ) {
				$found_keys[] = substr( $table, strlen( $prefix ) );
			}
		}
		$missing_core                             = array_values( array_diff( $core_expected, $found_keys ) );
		$missing_full                             = array_values( array_diff( $full_expected, $found_keys ) );
		$unexpected_full                          = array_values( array_diff( $found_keys, $full_expected ) );
		$checks['core_schema']                    = $checks['db'] && empty( $missing_core );
		$checks['full_schema']                    = $checks['db'] && empty( $missing_full );
		$checks['schema_contract_exact_49']       = $checks['db'] && count( $found_keys ) === 49 && empty( $missing_full ) && empty( $unexpected_full );
		$diagnostics['core_schema_present']       = count( array_intersect( $core_expected, $found_keys ) );
		$diagnostics['core_schema_total']         = count( $core_expected );
		$diagnostics['core_schema_missing']       = $missing_core;
		$diagnostics['accounting_schema_present'] = count( array_intersect( $accounting_expected, $found_keys ) );
		$diagnostics['accounting_schema_total']   = count( $accounting_expected );
		$diagnostics['hr_schema_present']         = count( array_intersect( $hr_expected, $found_keys ) );
		$diagnostics['hr_schema_total']           = count( $hr_expected );
		$diagnostics['full_schema_present']       = count( array_intersect( $full_expected, $found_keys ) );
		$diagnostics['full_schema_total']         = count( $full_expected );
		$diagnostics['full_schema_missing']       = $missing_full;
		$diagnostics['unexpected_schema_tables']  = $unexpected_full;
		$diagnostics['schema_table_count']        = count( $found_keys );
		$diagnostics['setup_state']               = get_option( 'stockland_setup_state', 'UNKNOWN' );
		$diagnostics['execution_model']           = 'QUICK_READ_ONLY_CHECKS; DEEP_INTEGRITY_DEFERRED';
		$diagnostics['release_identity']          = STOCKLAND_VERSION;
		$diagnostics['rest_v16_base_url']         = esc_url_raw( rest_url( 'stockland/v16' ) );
		$diagnostics['rest_v16_route_count']      = 0;
		$routes                                   = array();
		$server                                   = rest_get_server();
		if ( $server ) {
			$routes = $server->get_routes();
		}
		$diagnostics['rest_v16_route_count'] = count(
			array_filter(
				array_keys( $routes ),
				static function ( $route ) {
					return strpos( $route, '/stockland/v16/' ) === 0 || '/stockland/v16' === $route;
				}
			)
		);
		$required_routes                     = array( '/stockland/v16/system/health', '/stockland/v16/system/runtime', '/stockland/v16/system/ping', '/stockland/v16/sku-search' );
		$missing_routes                      = array();
		foreach ( $required_routes as $route ) {
			if ( ! isset( $routes[ $route ] ) ) {
				$missing_routes[] = $route;
			}
		}
		$checks['rest_v16']                     = empty( $missing_routes );
		$diagnostics['rest_v16_missing_routes'] = $missing_routes;
		$checks['frontend_route']               = class_exists( 'Stockland_Frontend' ) && Stockland_Frontend::route_ready();
		$diagnostics['frontend_route_url']      = class_exists( 'Stockland_Frontend' ) ? esc_url_raw( Stockland_Frontend::page_url() ) : '';
		$diagnostics['erp_entry_page_id']       = (int) get_option( 'stockland_app_page_id' );

		// These are intentionally not represented as passing checks. The normal health
		// button does not run full-table ledger, reservation, AP/AR, GL, PLEDGER or hash-chain scans.
		$deferred                       = array(
			'audit_chain_hash_verification',
			'ledger_reference_and_negative_stock_scans',
			'balance_vs_ledger_reconciliation',
			'reservation_integrity',
			'AP_AR_reconciliation',
			'general_ledger_balance_and_orphan_scans',
			'procurement_and_PLEDGER_reference_scans',
			'identifier_duplicate_scans',
			'payroll_compensation_classification',
			'full_import_and_workflow_contract_gates',
		);
		$diagnostics['deferred_checks'] = $deferred;
		$diagnostics['live_ready_rule'] = 'Quick checks are not production certification. Live readiness requires a separately controlled deep integrity scan; deferred checks are not assumed healthy.';
		$failed_checks                  = array();
		foreach ( $checks as $key => $value ) {
			if ( ! $value ) {
				$failed_checks[] = $key;
			}
		}
		$checks['live_ready'] = false;
		$checks['ok']         = false;
		return rest_ensure_response(
			array(
				'ok'                  => false,
				'checks'              => $checks,
				'diagnostics'         => $diagnostics,
				'missing_tables'      => $missing_full,
				'missing_core_tables' => $missing_core,
				'audit'               => array(
					'ok'      => null,
					'checked' => 0,
					'reason'  => 'Deep audit-chain verification was deferred in quick health mode.',
				),
				'php'                 => PHP_VERSION,
				'wordpress'           => get_bloginfo( 'version' ),
				'plugin'              => STOCKLAND_VERSION,
				'db_version'          => get_option( 'stockland_db_version', 'unknown' ),
				'source_workbook'     => get_option( 'stockland_source_workbook', 'not loaded' ),
				'source_sha256'       => get_option( 'stockland_source_workbook_sha256', '' ),
				'seed'                => get_option( 'stockland_workbook_seed_version', 'not loaded' ),
				'data_policy'         => 'Read-only quick health check; no business data is changed.',
				'failed_checks'       => $failed_checks,
				'deferred_checks'     => $deferred,
				'live_ready_reasons'  => array( 'Deep integrity scans are deferred; no production-ready claim is made.' ),
			)
		);
	}

	/**
	 * Default health requests are deliberately lightweight on constrained shared hosting.
	 * The previous default performed full-table ledger/GL reconciliation and loaded every
	 * audit-log row into one PHP request, which could exhaust CPU/memory and return HTTP 502.
	 * A deep scan is opt-in only (?mode=deep); it is never triggered by the normal UI button.

	 * @param WP_REST_Request $request REST request that supplies the operation parameters.
	 */
	public function health( $request = null ) {
		if ( ! ( $request instanceof WP_REST_Request ) || 'deep' !== (string) $request->get_param( 'mode' ) ) {
			return $this->health_quick();
		}
		global $wpdb;
		$checks       = array();
		$diagnostics  = array();
		$checks['db'] = ( 1 === (int) $wpdb->get_var( 'SELECT 1' ) );
		// Environment diagnostics are read-only and contain no credentials. They make
		// the live acceptance evidence self-contained on constrained shared hosting.
		$diagnostics['php_runtime']         = PHP_VERSION;
		$diagnostics['wordpress_runtime']   = get_bloginfo( 'version' );
		$diagnostics['database_server']     = ( $checks['db'] ? (string) $wpdb->get_var( 'SELECT VERSION()' ) : 'unavailable' );
		$diagnostics['upload_max_filesize'] = (string) ini_get( 'upload_max_filesize' );
		$diagnostics['post_max_size']       = (string) ini_get( 'post_max_size' );
		$diagnostics['memory_limit']        = (string) ini_get( 'memory_limit' );
		$diagnostics['max_execution_time']  = (string) ini_get( 'max_execution_time' );
		$runtime                            = Stockland_Runtime_Diagnostics::environment();
		$diagnostics['runtime_environment'] = $runtime;
		$diagnostics['last_fatal']          = Stockland_Runtime_Diagnostics::last_fatal();
		$checks['xlsx_xmlreader_stream']    = ! empty( $runtime['xml_reader_from_stream'] );
		$checks['php_runtime']              = version_compare( PHP_VERSION, '8.4', '>=' );
		$to_bytes                           = function ( $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return 0;
			}$last = strtolower( substr( $value, -1 ) );
			$n     = (float) $value;
			switch ( $last ) {
				case 'g':
																																					$n *= 1024;
				case 'm':
																																					$n *= 1024;
				case 'k':
																																					$n *= 1024;
			}return (int) $n;
		};
		$checks['xlsx_upload_capacity']     = $to_bytes( ini_get( 'upload_max_filesize' ) ) >= Stockland_XLSX::MAX_FILE_BYTES && $to_bytes( ini_get( 'post_max_size' ) ) >= Stockland_XLSX::MAX_FILE_BYTES;

		$core_expected       = Stockland_Activator::core_table_keys();
		$accounting_expected = array( 'suppliers', 'customers', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'supplier_invoices', 'supplier_invoice_items', 'customer_invoices', 'invoice_items', 'expenses', 'payments', 'payment_allocations', 'gl_accounts', 'gl_journals', 'gl_lines' );
		$hr_expected         = array( 'employees', 'employee_compensation', 'payroll_periods', 'payroll_lines' );
		$full_expected       = array_values( array_unique( array_merge( $core_expected, $accounting_expected, $hr_expected ) ) );

		$prefix     = $wpdb->prefix . 'stock_';
		$found      = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$found_keys = array();
		foreach ( (array) $found as $table ) {
			if ( strpos( $table, $prefix ) === 0 ) {
				$found_keys[] = substr( $table, strlen( $prefix ) );
			}
		}
		$core_present    = array_values( array_intersect( $core_expected, $found_keys ) );
		$full_present    = array_values( array_intersect( $full_expected, $found_keys ) );
		$missing_core    = array_values( array_diff( $core_expected, $found_keys ) );
		$missing_full    = array_values( array_diff( $full_expected, $found_keys ) );
		$unexpected_full = array_values( array_diff( $found_keys, $full_expected ) );

		$checks['core_schema']                    = empty( $missing_core );
		$checks['full_schema']                    = empty( $missing_full );
		$diagnostics['core_schema_present']       = count( $core_present );
		$diagnostics['core_schema_total']         = count( $core_expected );
		$diagnostics['core_schema_missing']       = $missing_core;
		$diagnostics['accounting_schema_present'] = count( array_intersect( $accounting_expected, $found_keys ) );
		$diagnostics['accounting_schema_total']   = count( $accounting_expected );
		$diagnostics['hr_schema_present']         = count( array_intersect( $hr_expected, $found_keys ) );
		$diagnostics['hr_schema_total']           = count( $hr_expected );
		$diagnostics['full_schema_present']       = count( $full_present );
		$diagnostics['full_schema_total']         = count( $full_expected );
		$diagnostics['full_schema_missing']       = $missing_full;
		$diagnostics['unexpected_schema_tables']  = $unexpected_full;
		$diagnostics['schema_table_count']        = count( $found_keys );
		$checks['schema_contract_exact_49']       = count( $found_keys ) === 49 && empty( $missing_full ) && empty( $unexpected_full );
		$checks['ledger_runtime']                 = in_array( 'transactions', $found_keys, true ) && in_array( 'balances', $found_keys, true ) && class_exists( 'Stockland_Ledger' ) && method_exists( 'Stockland_Ledger', 'record' );

		$server          = rest_get_server();
		$routes          = $server ? $server->get_routes() : array();
		$required_routes = array(
			'/stockland/v16/system/health',
			'/stockland/v16/system/runtime',
			'/stockland/v16/system/ping',
			'/stockland/v16/sku-search',
			'/stockland/v16/import/code-maps',
			'/stockland/v16/import/code-maps/(?P<id>\d+)',
			'/stockland/v16/import/daily-sales/commit',
			'/stockland/v16/import/daily-sales/edit-row',
			'/stockland/v16/import/daily-sales/jobs',
			'/stockland/v16/import/daily-sales/jobs/(?P<id>\d+)',
			'/stockland/v16/import/daily-sales/preview',
			'/stockland/v16/import/mappings',
			'/stockland/v16/import/products/commit',
			'/stockland/v16/import/products/preview',
			'/stockland/v16/notifications',
			'/stockland/v16/notifications/(?P<id>\d+)/read',
			'/stockland/v16/organization/locations',
			'/stockland/v16/organization/locations/(?P<id>\d+)',
			'/stockland/v16/reports/daily-inventory',
			'/stockland/v16/requisitions',
			'/stockland/v16/requisitions/(?P<id>[A-Za-z0-9\-]+)',
			'/stockland/v16/requisitions/(?P<id>[A-Za-z0-9\-]+)/decision',
			'/stockland/v16/requisitions/(?P<id>[A-Za-z0-9\-]+)/receive',
			'/stockland/v16/requisitions/(?P<id>[A-Za-z0-9\-]+)/warehouse-action',
		);
		$missing_routes  = array();
		foreach ( $required_routes as $route ) {
			if ( ! isset( $routes[ $route ] ) ) {
				$missing_routes[] = $route;
			}
		}
		$checks['rest_v16']                     = empty( $missing_routes );
		$diagnostics['rest_v16_missing_routes'] = $missing_routes;
		$diagnostics['rest_v16_base_url']       = esc_url_raw( rest_url( 'stockland/v16' ) );
		$diagnostics['rest_v16_route_count']    = count(
			array_filter(
				array_keys( $routes ),
				function ( $route ) {
					return strpos( $route, '/stockland/v16/' ) === 0 || '/stockland/v16' === $route;
				}
			)
		);

		$checks['frontend_route']            = Stockland_Frontend::route_ready();
		$diagnostics['frontend_route_url']   = esc_url_raw( Stockland_Frontend::page_url() );
		$legacy_page_id                      = (int) get_option( 'stockland_app_page_id' );
		$diagnostics['erp_entry_page_id']    = $legacy_page_id;
		$checks['ssl']                       = is_ssl() || strpos( home_url(), 'localhost' ) !== false;
		$diagnostics['execution_model']      = 'BROWSER_RESUMABLE_BATCHES';
		$diagnostics['setup_state']          = get_option( 'stockland_setup_state', 'UNKNOWN' );
		$diagnostics['business_data_policy'] = 'No seeded stock/sales/payroll. Product Master starts empty; operational data is user-imported/entered through authorized workflows.';
		$checks['xlsx_reader']               = class_exists( 'ZipArchive' ) && class_exists( 'SimpleXMLElement' );
		$checks['xlsx_streaming_reader']     = class_exists( 'ZipArchive' ) && class_exists( 'XMLReader' );
		$checks['logo_asset']                = file_exists( STOCKLAND_PLUGIN_DIR . 'assets/stockland-logo.jpg' );

		$audit                            = in_array( 'audit_logs', $found_keys, true ) ? Stockland_Audit::verify_chain() : array(
			'ok'      => false,
			'checked' => 0,
			'reason'  => 'Audit table is missing.',
		);
		$checks['audit_chain']            = $audit['ok'];
		$diagnostics['audit_checked']     = (int) ( $audit['checked'] ?? 0 );
		$diagnostics['audit_legacy_rows'] = (int) ( $audit['legacy_rows'] ?? 0 );
		$diagnostics['audit_compatible_legacy_v2_rows'] = (int) ( $audit['compatible_legacy_v2_rows'] ?? 0 );
		$diagnostics['audit_failure_reason']            = $audit['reason'] ?? null;
		$diagnostics['audit_mismatch_row']              = isset( $audit['mismatch_row'] ) ? (int) $audit['mismatch_row'] : null;

		$safe_tables                                  = array( 'transactions', 'products', 'locations', 'transfers', 'receipts', 'reservations', 'balances' );
		$data_ready                                   = empty( array_diff( $safe_tables, $found_keys ) );
		$balance_columns                              = $data_ready ? (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Stockland_DB::table( 'balances' ) ), 0 ) : array();
		$balance_model_ok                             = in_array( 'physical_qty', $balance_columns, true );
		$checks['balance_model_contract']             = $balance_model_ok;
		$diagnostics['balance_model_missing_columns'] = array_values( array_diff( array( 'physical_qty' ), $balance_columns ) );
		if ( $data_ready && $balance_model_ok ) {
			$tx                               = Stockland_DB::table( 'transactions' );
			$prod                             = Stockland_DB::table( 'products' );
			$loc                              = Stockland_DB::table( 'locations' );
			$tr                               = Stockland_DB::table( 'transfers' );
			$rc                               = Stockland_DB::table( 'receipts' );
			$diagnostics['transaction_rows']  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tx ) );
			$diagnostics['product_rows']      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE product_type<>'STYLE'", $prod ) );
			$diagnostics['location_rows']     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_active=1', $loc ) );
			$diagnostics['orphan_ledger_sku'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i t LEFT JOIN %i p ON (p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku)) WHERE p.variant_id IS NULL', $tx, $prod ) );
			$diagnostics['ledger_missing_variant_identity'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE variant_id IS NULL', $tx ) );
			$diagnostics['orphan_ledger_location']          = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i t LEFT JOIN %i l ON l.id=t.location_id WHERE l.id IS NULL', $tx, $loc ) );
			$diagnostics['negative_stock_pairs']            = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT location_id,COALESCE(variant_id,0) variant_id,sku FROM %i GROUP BY location_id,COALESCE(variant_id,0),sku HAVING SUM(quantity_signed)<0) x', $tx ) );
			$diagnostics['completed_transfer_field_gaps']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='Completed' AND (dispatched_qty=0 OR received_qty=0 OR received_at IS NULL)", $tr ) );
			$diagnostics['completed_receipt_field_gaps']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='Completed' AND (actual_quantity_received IS NULL OR received_at IS NULL)", $rc ) );
			$bal                                       = Stockland_DB::table( 'balances' );
			$diagnostics['balance_ledger_mismatches']  = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
                SELECT x.location_id,x.variant_id,ROUND(x.ledger_qty,2) ledger_qty,ROUND(COALESCE(b.physical_qty,0),2) balance_qty
                FROM (SELECT location_id,variant_id,SUM(quantity_signed) ledger_qty FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) x
                LEFT JOIN %i b ON b.location_id=x.location_id AND b.variant_id=x.variant_id
                WHERE ABS(x.ledger_qty-COALESCE(b.physical_qty,0))>0.00001
                UNION ALL
                SELECT b.location_id,b.variant_id,0 ledger_qty,ROUND(b.physical_qty,2) balance_qty
                FROM %i b LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) ledger_qty FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) x
                  ON x.location_id=b.location_id AND x.variant_id=b.variant_id
                WHERE x.variant_id IS NULL AND ABS(b.physical_qty)>0.00001
            ) z",
					$tx,
					$bal,
					$bal,
					$tx
				)
			);
			$res                                       = Stockland_DB::table( 'reservations' );
			$diagnostics['orphan_reservations']        = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.variant_id=r.variant_id WHERE r.variant_id IS NULL OR p.variant_id IS NULL', $res, $prod ) );
			$diagnostics['over_reserved_pairs']        = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
                SELECT r.location_id,r.variant_id,SUM(r.quantity_reserved) reserved_qty,COALESCE(b.physical_qty,0) physical_qty
                FROM %i r LEFT JOIN %i b ON b.location_id=r.location_id AND b.variant_id=r.variant_id
                WHERE r.status='Active'
                GROUP BY r.location_id,r.variant_id
                HAVING SUM(r.quantity_reserved)>COALESCE(MAX(b.physical_qty),0)+0.00001
            ) q",
					$res,
					$bal
				)
			);
			$diagnostics['duplicate_product_barcodes'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT barcode FROM %i WHERE barcode IS NOT NULL AND barcode<>'' GROUP BY barcode HAVING COUNT(*)>1) d", $prod ) );
			$diagnostics['duplicate_product_gtins']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT gtin FROM %i WHERE gtin IS NOT NULL AND gtin<>'' GROUP BY gtin HAVING COUNT(*)>1) d", $prod ) );
			$checks['identifier_integrity']            = 0 === $diagnostics['duplicate_product_barcodes'] && 0 === $diagnostics['duplicate_product_gtins'];
			$checks['ledger_references']               = ( 0 === $diagnostics['orphan_ledger_sku'] && 0 === $diagnostics['ledger_missing_variant_identity'] && 0 === $diagnostics['orphan_ledger_location'] );
			$checks['no_negative_stock']               = 0 === $diagnostics['negative_stock_pairs'];
			$checks['balance_reconciled']              = 0 === $diagnostics['balance_ledger_mismatches'];
			$checks['reservation_integrity']           = ( 0 === $diagnostics['orphan_reservations'] && 0 === $diagnostics['over_reserved_pairs'] );
		} else {
			if ( $data_ready && ! $balance_model_ok ) {
				$diagnostics['balance_ledger_mismatches'] = null;
				$diagnostics['over_reserved_pairs']       = null;
				$diagnostics['orphan_reservations']       = null;
			}
			$checks['identifier_integrity']           = false;
			$checks['ledger_references']              = false;
			$checks['no_negative_stock']              = false;
			$checks['balance_reconciled']             = false;
			$checks['reservation_integrity']          = false;
			$diagnostics['balance_ledger_mismatches'] = null;
			$diagnostics['orphan_reservations']       = null;
			$diagnostics['over_reserved_pairs']       = null;
		}

		if ( in_array( 'suppliers', $found_keys, true ) && in_array( 'customers', $found_keys, true ) && in_array( 'supplier_invoices', $found_keys, true ) && in_array( 'customer_invoices', $found_keys, true ) && in_array( 'payments', $found_keys, true ) && in_array( 'gl_journals', $found_keys, true ) && in_array( 'gl_lines', $found_keys, true ) ) {
			$sup = Stockland_DB::table( 'suppliers' );
			$cus = Stockland_DB::table( 'customers' );
			$si  = Stockland_DB::table( 'supplier_invoices' );
			$ci  = Stockland_DB::table( 'customer_invoices' );
			$pay = Stockland_DB::table( 'payments' );
			$glj = Stockland_DB::table( 'gl_journals' );
			$gll = Stockland_DB::table( 'gl_lines' );
			$diagnostics['supplier_ap_reconciliation_mismatches'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i s LEFT JOIN (SELECT supplier_id,SUM(total_amount) total FROM %i WHERE status='POSTED' GROUP BY supplier_id) i ON i.supplier_id=s.id LEFT JOIN (SELECT supplier_id,SUM(amount) total FROM %i WHERE payment_type='SUPPLIER' AND status='POSTED' GROUP BY supplier_id) p ON p.supplier_id=s.id LEFT JOIN (SELECT l.supplier_id,SUM(l.credit-l.debit) balance FROM %i l JOIN %i j ON j.id=l.journal_id WHERE j.status='POSTED' AND l.account_code='2000' GROUP BY l.supplier_id) g ON g.supplier_id=s.id WHERE ABS(ROUND(COALESCE(s.opening_balance,0)+COALESCE(i.total,0)-COALESCE(p.total,0)-COALESCE(g.balance,0),2))>0.01",
					$sup,
					$si,
					$pay,
					$gll,
					$glj
				)
			);
			$diagnostics['customer_ar_reconciliation_mismatches'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i c LEFT JOIN (SELECT customer_id,SUM(total_amount) total FROM %i WHERE status='POSTED' GROUP BY customer_id) i ON i.customer_id=c.id LEFT JOIN (SELECT customer_id,SUM(amount) total FROM %i WHERE payment_type='CUSTOMER' AND status='POSTED' GROUP BY customer_id) p ON p.customer_id=c.id LEFT JOIN (SELECT l.customer_id,SUM(l.debit-l.credit) balance FROM %i l JOIN %i j ON j.id=l.journal_id WHERE j.status='POSTED' AND l.account_code='1100' GROUP BY l.customer_id) g ON g.customer_id=c.id WHERE ABS(ROUND(COALESCE(c.opening_balance,0)+COALESCE(i.total,0)-COALESCE(p.total,0)-COALESCE(g.balance,0),2))>0.01",
					$cus,
					$ci,
					$pay,
					$gll,
					$glj
				)
			);
			$checks['ap_ar_reconciled']                           = ( 0 === $diagnostics['supplier_ap_reconciliation_mismatches'] && 0 === $diagnostics['customer_ar_reconciliation_mismatches'] );
		} else {
			$diagnostics['supplier_ap_reconciliation_mismatches'] = null;
			$diagnostics['customer_ar_reconciliation_mismatches'] = null;
			$checks['ap_ar_reconciled']                           = $checks['full_schema'];
		}

		if ( in_array( 'gl_journals', $found_keys, true ) && in_array( 'gl_lines', $found_keys, true ) ) {
			$diagnostics['unbalanced_gl_journals'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i j JOIN (SELECT journal_id,SUM(debit) d,SUM(credit) c FROM %i GROUP BY journal_id) x ON x.journal_id=j.id WHERE j.status='POSTED' AND ABS(x.d-x.c)>0.01",
					Stockland_DB::table( 'gl_journals' ),
					Stockland_DB::table( 'gl_lines' )
				)
			);
			$diagnostics['orphan_gl_lines'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i l LEFT JOIN %i j ON j.id=l.journal_id WHERE j.id IS NULL',
					Stockland_DB::table( 'gl_lines' ),
					Stockland_DB::table( 'gl_journals' )
				)
			);
			$checks['general_ledger_balanced']     = 0 === $diagnostics['unbalanced_gl_journals'] && 0 === $diagnostics['orphan_gl_lines'];
		} else {
			$diagnostics['unbalanced_gl_journals'] = null;
			$diagnostics['orphan_gl_lines']        = null;
			$checks['general_ledger_balanced']     = $checks['full_schema'];
		}
		if ( in_array( 'purchase_order_items', $found_keys, true ) && in_array( 'purchase_orders', $found_keys, true ) && in_array( 'invoice_items', $found_keys, true ) && in_array( 'customer_invoices', $found_keys, true ) ) {
			$diagnostics['orphan_po_items'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i i LEFT JOIN %i p ON p.id=i.po_id WHERE p.id IS NULL',
					Stockland_DB::table( 'purchase_order_items' ),
					Stockland_DB::table( 'purchase_orders' )
				)
			);
			$diagnostics['orphan_invoice_items'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i i LEFT JOIN %i h ON h.id=i.invoice_id WHERE h.id IS NULL',
					Stockland_DB::table( 'invoice_items' ),
					Stockland_DB::table( 'customer_invoices' )
				)
			);
			$checks['procurement_references']    = 0 === $diagnostics['orphan_po_items'] && 0 === $diagnostics['orphan_invoice_items'];
		} else {
			$diagnostics['orphan_po_items']      = null;
			$diagnostics['orphan_invoice_items'] = null;
			$checks['procurement_references']    = $checks['full_schema'];
		}
		$checks['authorization_scope_table'] = in_array( 'user_locations', $found_keys, true );
		$checks['import_rows_schema']        = in_array( 'import_rows', $found_keys, true );

		// The statutory calculation gate now proves both the versioned calculation service
		// and required component columns. Legacy employee compensation remains a separate
		// data-readiness gate until an authorized owner explicitly classifies it.
		$hr_schema_gaps                         = class_exists( 'Stockland_HR' ) ? Stockland_HR::upgrade_gaps() : array( 'hr_service_missing' );
		$hr_missing_tables                      = class_exists( 'Stockland_HR' ) ? Stockland_HR::missing_tables() : array( 'hr_service_missing' );
		$checks['payroll_statutory_model']      = class_exists( 'Stockland_Payroll_Statutory' )
			&& method_exists( 'Stockland_Payroll_Statutory', 'calculate' )
			&& method_exists( 'Stockland_Payroll_Statutory', 'income_tax' )
			&& empty( $hr_missing_tables )
			&& empty(
				array_filter(
					$hr_schema_gaps,
					static function ( $gap ) {
						return strpos( (string) $gap, 'payroll_lines.' ) === 0 || strpos( (string) $gap, 'payroll_periods.' ) === 0 || strpos( (string) $gap, 'employee_compensation.' ) === 0;
					}
				)
			);
		$diagnostics['payroll_statutory_model'] = $checks['payroll_statutory_model']
			? array(
				'implemented'           => true,
				'rule_version'          => Stockland_Payroll_Statutory::RULE_VERSION,
				'effective_date'        => Stockland_Payroll_Statutory::EFFECTIVE_DATE,
				'employee_pension_rate' => Stockland_Payroll_Statutory::EMPLOYEE_PENSION_RATE,
				'employer_pension_rate' => Stockland_Payroll_Statutory::EMPLOYER_PENSION_RATE,
				'legacy_payroll_policy' => 'Historical and already-posted payroll is not recalculated.',
			)
			: array(
				'implemented'            => class_exists( 'Stockland_Payroll_Statutory' ),
				'missing_schema_columns' => $hr_schema_gaps,
			);

		$diagnostics['payroll_compensation_classification'] = array(
			'unresolved_active_employees'         => null,
			'excluded_retainer_service_contracts' => null,
			'rule'                                => 'Every active employee who is employed as of today requires current owner-classified compensation; explicit RETAINER_SERVICE records are excluded from employee statutory payroll and must follow separately approved contract/payment treatment.',
		);
		$checks['payroll_compensation_classification']      = false;
		if ( class_exists( 'Stockland_HR' ) && empty( $hr_missing_tables ) && empty( $hr_schema_gaps ) && in_array( 'employees', $found_keys, true ) && in_array( 'employee_compensation', $found_keys, true ) ) {
			$employees_table = Stockland_DB::table( 'employees' );
			$comp_table      = Stockland_DB::table( 'employee_compensation' );
			$as_of           = current_time( 'Y-m-d' );
			$unresolved      = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i e LEFT JOIN %i c ON c.id=(SELECT c2.id FROM %i c2 WHERE c2.employee_id=e.id AND c2.effective_from<=%s ORDER BY c2.effective_from DESC,c2.id DESC LIMIT 1) WHERE e.status='ACTIVE' AND UPPER(COALESCE(e.employment_type,''))<>'RETAINER_SERVICE' AND (e.hire_date IS NULL OR e.hire_date<=%s) AND (e.termination_date IS NULL OR e.termination_date>=%s) AND (c.id IS NULL OR c.statutory_classification_status<>'OWNER_CLASSIFIED' OR c.taxable_allowance_total IS NULL OR c.exempt_allowance_total IS NULL OR c.pensionable_salary IS NULL)",
					$employees_table,
					$comp_table,
					$comp_table,
					$as_of,
					$as_of,
					$as_of
				)
			);
			$excluded        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='ACTIVE' AND UPPER(COALESCE(employment_type,''))='RETAINER_SERVICE' AND (hire_date IS NULL OR hire_date<=%s) AND (termination_date IS NULL OR termination_date>=%s)", $employees_table, $as_of, $as_of ) );
			$diagnostics['payroll_compensation_classification']['unresolved_active_employees']         = $unresolved;
			$diagnostics['payroll_compensation_classification']['excluded_retainer_service_contracts'] = $excluded;
			$checks['payroll_compensation_classification'] = ( 0 === $unresolved );
		}

		// Source/runtime boundary checks are deliberately narrow: only executable
		// integration/scheduling/trigger primitives are forbidden in the final build.
		$root_php_files = glob( STOCKLAND_PLUGIN_DIR . '*.php' );
		if ( ! $root_php_files ) {
			$root_php_files = array();
		}
		$include_php_files = glob( STOCKLAND_PLUGIN_DIR . 'includes/*.php' );
		if ( ! $include_php_files ) {
			$include_php_files = array();
		}
		$rest_php_files = glob( STOCKLAND_PLUGIN_DIR . 'includes/rest/*.php' );
		if ( ! $rest_php_files ) {
			$rest_php_files = array();
		}
		$asset_js_files = glob( STOCKLAND_PLUGIN_DIR . 'assets/*.js' );
		if ( ! $asset_js_files ) {
			$asset_js_files = array();
		}
		$source_files     = array_merge( $root_php_files, $include_php_files, $rest_php_files, $asset_js_files );
		$integration_hits = array();
		$scheduler_hits   = array();
		$trigger_hits     = array();
		foreach ( $source_files as $source_file ) {
			$src = $this->read_local_source_file( $source_file );
			if ( false === $src ) {
				continue;
			}
			if ( preg_match( '/\bwp_remote_(?:get|post|request|head|retrieve|delete|put)\s*\(/i', $src ) || preg_match( '/\bcurl_(?:init|exec|setopt|multi_init)\s*\(/i', $src ) ) {
				$integration_hits[] = basename( $source_file );
			}
			if ( preg_match( '/\bwp_schedule_(?:event|single|recurring|cron)\s*\(/i', $src ) || preg_match( '/\bwp_next_scheduled\s*\(/i', $src ) ) {
				$scheduler_hits[] = basename( $source_file );
			}
			if ( preg_match( '/\bCREATE\s+TRIGGER\b/i', $src ) ) {
				$trigger_hits[] = basename( $source_file );
			}
		}
		$checks['no_external_provider_runtime']        = empty( $integration_hits );
		$checks['no_scheduler_dependency']             = empty( $scheduler_hits );
		$checks['no_database_trigger']                 = empty( $trigger_hits );
		$diagnostics['external_provider_runtime_hits'] = array_values( array_unique( $integration_hits ) );
		$diagnostics['scheduler_dependency_hits']      = array_values( array_unique( $scheduler_hits ) );
		$diagnostics['database_trigger_hits']          = array_values( array_unique( $trigger_hits ) );

		// Functional contract gates: these don't fabricate data; they prove that the
		// physical tables/routes required by the real ERP workflows exist before the
		// installation can be labelled LIVE READY.
		$checks['notifications_schema']    = in_array( 'notifications', $found_keys, true );
		$checks['product_master_contract'] = false;
		if ( in_array( 'products', $found_keys, true ) ) {
			$required_product_columns                      = array( 'variant_id', 'sku', 'product_name', 'product_type', 'parent_sku', 'brand', 'color', 'size', 'barcode', 'gtin', 'purchase_cost', 'retail_price', 'wholesale_price', 'branch_reorder_level', 'central_reorder_level', 'is_active' );
			$product_cols                                  = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Stockland_DB::table( 'products' ), 0 );
			$checks['product_master_contract']             = empty( array_diff( $required_product_columns, (array) $product_cols ) );
			$diagnostics['product_master_missing_columns'] = array_values( array_diff( $required_product_columns, (array) $product_cols ) );
		}
		$checks['daily_pos_contract']           = in_array( 'import_jobs', $found_keys, true ) && in_array( 'import_rows', $found_keys, true ) && isset( $routes['/stockland/v16/import/daily-sales/preview'] ) && isset( $routes['/stockland/v16/import/daily-sales/edit-row'] ) && isset( $routes['/stockland/v16/import/daily-sales/commit'] );
		$checks['warehouse_receiving_contract'] = in_array( 'receipts', $found_keys, true ) && isset( $routes['/stockland/v16/receipts'] ) && isset( $routes['/stockland/v16/receipts/(?P<receipt_id>[a-zA-Z0-9\-]+)/complete'] );
		$checks['sku_mapping_contract']         = in_array( 'product_code_maps', $found_keys, true ) && isset( $routes['/stockland/v16/import/code-maps'] ) && isset( $routes['/stockland/v16/import/code-maps/(?P<id>\d+)'] );
		$checks['sourcing_service']             = class_exists( 'Stockland_Sourcing' ) && method_exists( 'Stockland_Sourcing', 'for_items' );
		$import_source                          = $this->read_local_source_file( STOCKLAND_PLUGIN_DIR . 'includes/rest/class-rest-import-v16.php' );
		$xlsx_source                            = $this->read_local_source_file( STOCKLAND_PLUGIN_DIR . 'includes/class-stockland-xlsx.php' );
		$sourcing_source                        = $this->read_local_source_file( STOCKLAND_PLUGIN_DIR . 'includes/class-stockland-sourcing.php' );
		$checks['four_layer_import_contract']   = false !== $import_source
			&& strpos( $import_source, 'layer_1_file_integrity' ) !== false
			&& strpos( $import_source, 'layer_2_identity_mapping' ) !== false
			&& strpos( $import_source, 'layer_3_business_validation' ) !== false
			&& strpos( $import_source, 'layer_4_staging_commit_gate' ) !== false
			&& strpos( $import_source, 'STAGED_ONLY_NO_AUTO_POST' ) !== false;
		// Prove both preview rejection and commit-time defense-in-depth. This avoids
		// brittle dependence on whitespace or a magic literal that the real code need not use.
		$checks['product_master_no_stock_contract'] = false !== $import_source
			&& strpos( $import_source, 'is not permitted in Product Master import. Use Warehouse Receiving for physical stock.' ) !== false
			&& strpos( $import_source, 'Product Master commit rejected physical stock field' ) !== false
			&& strpos( $import_source, "array('initial_stock','opening_stock','stock_quantity','quantity','qty','stock')" ) !== false;
		$checks['xlsx_stream_contract']             = false !== $xlsx_source
			&& strpos( $xlsx_source, 'ZipArchive' ) !== false
			&& strpos( $xlsx_source, 'XMLReader' ) !== false
			&& strpos( $xlsx_source, 'getStream(' ) !== false
			&& strpos( $xlsx_source, 'stream_rows(' ) !== false;
		$checks['sourcing_no_mutation_contract']    = false !== $sourcing_source
			&& strpos( $sourcing_source, 'INFORMATIONAL_ONLY' ) !== false
			&& strpos( $sourcing_source, 'action_policy' ) !== false;
		$checks['no_advisor_runtime']               = true;
		foreach ( array_keys( $routes ) as $route_key ) {
			$route_l = strtolower( (string) $route_key );
			if ( strpos( $route_l, 'advisor' ) !== false || strpos( $route_l, 'stock-advisor' ) !== false || preg_match( '#/(ai|hub)(/|$)#', $route_l ) ) {
				$checks['no_advisor_runtime'] = false;
				break; }
		}

		if ( in_array( 'notifications', $found_keys, true ) ) {
			$diagnostics['notification_rows'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Stockland_DB::table( 'notifications' ) );
		}
		if ( in_array( 'import_jobs', $found_keys, true ) ) {
			$diagnostics['import_job_rows'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Stockland_DB::table( 'import_jobs' ) ) );
		}
		if ( in_array( 'receipts', $found_keys, true ) ) {
			$diagnostics['receipt_rows'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Stockland_DB::table( 'receipts' ) ) );
		}

		// Release gate dependencies must be explicitly evaluated before live_ready.
		// variant_first_product_identity: prove that the Product Master has the immutable
		// variant identity and unique SKU contract required by the ledger/import paths.
		$checks['variant_first_product_identity'] = false;
		if ( in_array( 'products', $found_keys, true ) ) {
			$product_table  = Stockland_DB::table( 'products' );
			$variant_col    = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $product_table, 'variant_id' ), ARRAY_A );
			$indexes        = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $product_table ), ARRAY_A );
			$has_variant_pk = false;
			$has_sku_unique = false;
			foreach ( $indexes as $ix ) {
				if ( ( $ix['Key_name'] ?? '' ) === 'PRIMARY' && ( $ix['Column_name'] ?? '' ) === 'variant_id' ) {
					$has_variant_pk = true;
				}
				if ( ( $ix['Key_name'] ?? '' ) === 'sku' && (int) ( $ix['Non_unique'] ?? 1 ) === 0 && ( $ix['Column_name'] ?? '' ) === 'sku' ) {
					$has_sku_unique = true;
				}
			}
			$checks['variant_first_product_identity'] = ! empty( $variant_col ) && $has_variant_pk && $has_sku_unique;
		}

		// pwa_registration_source: prove the shipped PWA registration code exists and
		// contains a real Service Worker registration path. This is a source/asset gate,
		// not a promise that the target browser has already installed the worker.
		$pwa_file                          = STOCKLAND_PLUGIN_DIR . 'assets/pwa.js';
		$pwa_source                        = $this->read_local_source_file( $pwa_file );
		$checks['pwa_registration_source'] = false !== $pwa_source
			&& stripos( $pwa_source, 'serviceWorker.register' ) !== false
			&& stripos( $pwa_source, 'navigator.serviceWorker' ) !== false;

		// Exact target-schema evidence for the three repaired paths. This is read-only.
		$target_schema                = array(
			'products'     => array( 'variant_id', 'sku', 'product_name', 'product_type', 'parent_sku', 'brand', 'color', 'size', 'barcode', 'gtin', 'purchase_cost', 'retail_price', 'wholesale_price', 'branch_reorder_level', 'central_reorder_level', 'is_active' ),
			'sales'        => array( 'sale_id', 'sale_date', 'branch_location_id', 'sku', 'variant_id', 'quantity_sold', 'selling_price', 'vat_mode', 'vat_rate_used', 'sales_total', 'vat_amount', 'net_sales_excl_vat', 'cogs', 'gross_profit', 'posting_status' ),
			'transactions' => array( 'location_id', 'sku', 'variant_id', 'movement_type', 'quantity_signed', 'reference_type', 'reference_id', 'source_line_id', 'transaction_date' ),
			'balances'     => array( 'location_id', 'sku', 'variant_id', 'physical_qty' ),
			'reservations' => array( 'location_id', 'sku', 'variant_id', 'quantity_reserved', 'status' ),
			'locations'    => array( 'id', 'location_name', 'location_type', 'is_active' ),
			'settings'     => array( 'setting_key', 'setting_value', 'updated_by', 'updated_at' ),
			'import_jobs'  => array( 'id', 'import_key', 'import_type', 'source_filename', 'source_sha256', 'source_system', 'status' ),
			'import_rows'  => array( 'id', 'job_id', 'source_row_number', 'status' ),
		);
		$diagnostics['target_schema'] = array();
		foreach ( $target_schema as $logical => $need ) {
			$table = Stockland_DB::table( $logical );
			if ( ! Stockland_DB::table_exists( $table ) ) {
				$diagnostics['target_schema'][ $logical ] = array(
					'table_present'   => false,
					'missing_columns' => $need,
				);
				continue; }
			$cols                                     = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table, 0 );
			$diagnostics['target_schema'][ $logical ] = array(
				'table_present'   => true,
				'missing_columns' => array_values( array_diff( $need, (array) $cols ) ),
			);
		}
		// Supplement the older targeted deep gates with the comprehensive read-only
		// relational scan. Only invoked by explicit mode=deep; never auto-runs on page load.
		// Missing exact schema skips SQL joins and fails closed rather than masking errors.
		if ( ! empty( $checks['db'] ) && ! empty( $checks['schema_contract_exact_49'] ) && class_exists( 'Stockland_Integrity' ) ) {
			$integrity_result                           = Stockland_Integrity::scan();
			$diagnostics['supplemental_integrity_scan'] = $integrity_result;
			$checks['supplemental_integrity_scan']      = ! empty( $integrity_result['healthy'] ) && empty( $integrity_result['unknown_checks'] );
		} else {
			$diagnostics['supplemental_integrity_scan'] = array(
				'overall_status' => 'SCAN_INCOMPLETE',
				'healthy'        => false,
				'read_only'      => true,
				'reason'         => 'Database or exact 49-table contract is not healthy; integrity joins were not run.',
			);
			$checks['supplemental_integrity_scan']      = false;
		}
		$checks['live_ready'] = ( $checks['db'] && $checks['php_runtime'] && $checks['xlsx_upload_capacity'] && $checks['balance_model_contract'] && $checks['core_schema'] && $checks['full_schema'] && $checks['schema_contract_exact_49'] && $checks['ledger_runtime'] && $checks['rest_v16'] && $checks['frontend_route'] && $checks['ssl'] && $checks['xlsx_reader'] && $checks['xlsx_streaming_reader'] && $checks['xlsx_xmlreader_stream'] && $checks['sourcing_service'] && $checks['no_advisor_runtime'] && $checks['logo_asset'] && $checks['audit_chain'] && $checks['identifier_integrity'] && $checks['ledger_references'] && $checks['no_negative_stock'] && $checks['balance_reconciled'] && $checks['ap_ar_reconciled'] && $checks['reservation_integrity'] && $checks['general_ledger_balanced'] && $checks['procurement_references'] && $checks['authorization_scope_table'] && $checks['import_rows_schema'] && $checks['notifications_schema'] && $checks['product_master_contract'] && $checks['daily_pos_contract'] && $checks['warehouse_receiving_contract'] && $checks['sku_mapping_contract'] && $checks['variant_first_product_identity'] && $checks['four_layer_import_contract'] && $checks['product_master_no_stock_contract'] && $checks['xlsx_stream_contract'] && $checks['sourcing_no_mutation_contract'] && $checks['no_external_provider_runtime'] && $checks['no_scheduler_dependency'] && $checks['no_database_trigger'] && $checks['pwa_registration_source'] && $checks['payroll_statutory_model'] && $checks['payroll_compensation_classification'] && $checks['supplemental_integrity_scan'] );
		$failed_checks        = array();
		foreach ( $checks as $check_key => $check_value ) {
			if ( 'live_ready' !== $check_key && 'ok' !== $check_key && ! $check_value ) {
				$failed_checks[] = $check_key;
			}
		}
		$checks['ok']                    = $checks['live_ready'];
		$diagnostics['release_identity'] = STOCKLAND_VERSION;
		$diagnostics['live_ready_rule']  = 'All 49 ERP schema tables, runtime routes, streaming XLSX reader, ledger/audit integrity, AP/AR-to-GL reconciliation, procurement/PLEDGER-to-GRNI consistency, role authority, accounting invariants, payroll classification and the supplementary read-only relational integrity scan must be healthy. This is an explicit full scan and may be slower on shared hosting. No Stock Advisor/AI/Hub runtime route, Cron, background worker or database trigger is permitted.';
		return rest_ensure_response(
			array(
				'ok'                  => $checks['ok'],
				'checks'              => $checks,
				'diagnostics'         => $diagnostics,
				'missing_tables'      => $missing_full,
				'missing_core_tables' => $missing_core,
				'audit'               => $audit,
				'php'                 => PHP_VERSION,
				'wordpress'           => get_bloginfo( 'version' ),
				'plugin'              => STOCKLAND_VERSION,
				'db_version'          => get_option( 'stockland_db_version', 'unknown' ),
				'source_workbook'     => get_option( 'stockland_source_workbook', 'not loaded' ),
				'source_sha256'       => get_option( 'stockland_source_workbook_sha256', '' ),
				'seed'                => get_option( 'stockland_workbook_seed_version', 'not loaded' ),
				'data_policy'         => 'Fresh install contains structure and configuration only. No fake inventory, sales, payroll, purchase or customer transactions are seeded.',
				'failed_checks'       => $failed_checks,
				'live_ready_reasons'  => $checks['live_ready'] ? array() : array_map(
					function ( $k ) {
						return $k . ' failed';},
					$failed_checks
				),
			)
		);
	}
	/**
	 * Validates and normalizes a supplied import date.
	 *
	 * @param mixed $v Value for the v argument.
	 * @return string|false The validated YYYY-MM-DD date, or false when invalid.
	 */
	private function parse_import_date( $v ) {
		if ( is_numeric( $v ) && $v > 30000 && $v < 70000 ) {
			$base = new DateTime( '1899-12-30', new DateTimeZone( 'UTC' ) );
			$base->modify( '+' . ( (int) $v ) . ' days' );
			return $base->format( 'Y-m-d' );
		}
		$v = sanitize_text_field( (string) $v );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			$d = DateTime::createFromFormat( '!Y-m-d', $v );
			return $d && $d->format( 'Y-m-d' ) === $v ? $v : false;
		}
		return false;
	}

	/**
	 * Returns a valid date value or the current site-local date.
	 *
	 * @param mixed $v Value for the v argument.
	 * @return string A YYYY-MM-DD date in the WordPress site timezone.
	 */
	private function date_value( $v ) {
		$d = $this->parse_import_date( $v );
		return $d ? $d : current_time( 'Y-m-d' );
	}

	/**
	 * Normalizes requested locations and validates their types before granting scope.
	 *
	 * @param string $role ERP role slug to assign or verify.
	 * @param mixed  $primary Value for the primary argument.
	 * @param mixed  $requested Value for the requested argument.
	 * @return int[] The validated unique location IDs.
	 */
	private function normalize_location_ids( $role, $primary, $requested ) {
		$raw = is_array( $requested ) ? $requested : preg_split( '/\s*,\s*/', (string) $requested, -1, PREG_SPLIT_NO_EMPTY );
		$ids = array();
		foreach ( (array) $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( ! $ids && null !== $primary ) {
			$ids[] = (int) $primary;
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids ) {
			return array();
		}
		global $wpdb;
		// IDs are cast to positive integers above. FIND_IN_SET keeps the value list in a single prepared string and avoids constructing SQL placeholders from request data.
		$rows         = $wpdb->get_results( $wpdb->prepare( 'SELECT id,location_type FROM %i WHERE is_active=1 AND FIND_IN_SET(id,%s)', Stockland_DB::table( 'locations' ), implode( ',', $ids ) ), ARRAY_A );
		$types        = array();
		foreach ( $rows as $row ) {
			$types[ (int) $row['id'] ] = (string) $row['location_type'];
		}
		$central = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		if ( in_array( $role, array( Stockland_Roles::ROLE_WAREHOUSE_EMPLOYEE, Stockland_Roles::ROLE_WAREHOUSE_MANAGER ), true ) ) {
			return $central > 0 && isset( $types[ $central ] ) && 'Warehouse' === $types[ $central ] ? array( $central ) : array();
		}
		if ( ! in_array( $role, array( Stockland_Roles::ROLE_BRANCH_MANAGER, Stockland_Roles::ROLE_SALES_EMPLOYEE ), true ) ) {
			return array();
		}
		$valid = array();
		foreach ( $ids as $id ) {
			if ( isset( $types[ $id ] ) && 'Branch' === $types[ $id ] ) {
				$valid[] = $id;
			}
		}
		if ( ! $valid ) {
			return array();
		}
		usort(
			$valid,
			function ( $a, $b ) use ( $primary ) {
				if ( null !== $primary && $a === (int) $primary ) {
					return -1;
				}if ( null !== $primary && $b === (int) $primary ) {
					return 1;
				}return $a <=> $b;
			}
		);
		return $valid;
	}

	/**
	 * Synchronizes a user's assigned ERP locations after role validation.
	 *
	 * @param int   $wp_user_id WordPress user ID to update or validate.
	 * @param mixed $ids Value for the ids argument.
	 * @param mixed $primary Value for the primary argument.
	 * @param mixed $created_by Value for the created by argument.
	 * @return mixed The operation result.
	 */
	private function sync_location_assignments( $wp_user_id, $ids, $primary, $created_by = null ) {
		global $wpdb;
		$table                  = Stockland_DB::table( 'user_locations' );
		static $table_available = null;
		if ( null === $table_available ) {
			$table_available = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table );
		}
		if ( ! $table_available ) {
			return true;
		}
		$user_id = (int) $wp_user_id;
		$wpdb->delete( $table, array( 'wp_user_id' => $user_id ) );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( ! $ids ) {
			return true;
		}
		$created      = (int) ( $created_by ? $created_by : get_current_user_id() );
		$values       = array();
		$placeholders = array();
		foreach ( $ids as $index => $loc ) {
			$is_primary     = ( (int) $loc === (int) $primary || ( null === $primary && 0 === $index ) ) ? 1 : 0;
			$placeholders[] = '(%d,%d,%d,1,%d)';
			array_push( $values, $user_id, $loc, $is_primary, $created );
		}
		$sql = "INSERT INTO %i (wp_user_id,location_id,is_primary,is_active,created_by) VALUES " . implode( ',', $placeholders );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is bound as %i; all remaining values are supplied via generated %d placeholders from integer-cast IDs.
		$ok  = $wpdb->query( $wpdb->prepare( $sql, array_merge( array( $table ), $values ) ) );
		return false !== $ok;
	}

	/**
	 * Lists user profiles and location assignments permitted for the current role.
	 *
	 * @return mixed The operation result.
	 */
	public function users() {
		global $wpdb;
		$users_table     = Stockland_DB::table( 'users' );
		$locations_table = Stockland_DB::table( 'locations' );
		$scope_table     = Stockland_DB::table( 'user_locations' );
		$sql  = "SELECT su.wp_user_id,su.role,su.branch_location_id,su.is_active,
                    u.user_login,u.display_name,u.user_email,l.location_name,
                    COALESCE(scope.location_ids,IF(su.branch_location_id IS NULL,'',CAST(su.branch_location_id AS CHAR))) AS location_ids_csv,
                    COALESCE(scope.location_names,IF(l.location_name IS NULL,'',l.location_name)) AS location_names_csv
             FROM %i su
             JOIN %i u ON u.ID=su.wp_user_id
             LEFT JOIN %i l ON l.id=su.branch_location_id
             LEFT JOIN (
                 SELECT ul.wp_user_id,
                        GROUP_CONCAT(DISTINCT ul.location_id ORDER BY ul.is_primary DESC,ul.id SEPARATOR ',') AS location_ids,
                        GROUP_CONCAT(DISTINCT loc.location_name ORDER BY ul.is_primary DESC,ul.id SEPARATOR '||') AS location_names
                 FROM %i ul
                 JOIN %i loc ON loc.id=ul.location_id
                 WHERE ul.is_active=1
                   AND (ul.effective_from IS NULL OR ul.effective_from<=CURDATE())
                   AND (ul.effective_to IS NULL OR ul.effective_to>=CURDATE())
                 GROUP BY ul.wp_user_id
             ) scope ON scope.wp_user_id=su.wp_user_id
             ORDER BY u.display_name,u.user_login";
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				$users_table,
				$wpdb->users,
				$locations_table,
				$scope_table,
				$locations_table
			),
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$row['location_ids']   = array_values( array_filter( array_map( 'intval', preg_split( '/\s*,\s*/', (string) ( $row['location_ids_csv'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ) ) );
			$row['location_names'] = array_values( array_filter( array_map( 'trim', explode( '||', (string) ( $row['location_names_csv'] ?? '' ) ) ) ) );
			unset( $row['location_ids_csv'], $row['location_names_csv'] );
		}
		unset( $row );
		return rest_ensure_response( $rows );
	}

	/**
	 * Apply an ERP role without replacing native WordPress roles.
	 * Stockland authorization is layered on top of the WordPress account so
	 * native wp-admin access remains intact for technical administrators.
	 * Returns previous Stockland roles so callers can restore them if the ERP
	 * profile transaction later fails.

	 * @param int    $wp_user_id WordPress user ID to update or validate.
	 * @param string $role ERP role slug to assign or verify.
	 * @param bool   $active Whether the ERP profile is active.
	 */
	private function apply_stockland_role( $wp_user_id, $role, $active = true ) {
		$user = new WP_User( (int) $wp_user_id );
		if ( ! $user || ! $user->exists() ) {
			throw new Exception( 'WordPress user account not found.' );
		}
		if ( ! get_role( $role ) ) {
			Stockland_Roles::register_roles();
		}
		$previous = array();
		foreach ( (array) $user->roles as $existing ) {
			if ( in_array( $existing, Stockland_Roles::supported_roles(), true ) ) {
				$previous[] = $existing;
				$user->remove_role( $existing );
			}
		}
		if ( $active ) {
			$user->add_role( $role );
			if ( ! in_array( $role, (array) $user->roles, true ) ) {
				throw new Exception( 'The selected Stockland role could not be activated on the WordPress account.' );
			}
		}
		return $previous;
	}

	/**
	 * Restore only the Stockland-role portion of a WordPress account.
	 *
	 * @param int   $wp_user_id WordPress user ID to update or validate.
	 * @param array $roles Allowed ERP role slugs to retain.
	 */
	private function restore_stockland_roles( $wp_user_id, $roles ) {
		$user = new WP_User( (int) $wp_user_id );
		if ( ! $user || ! $user->exists() ) {
			return;
		}
		foreach ( (array) $user->roles as $existing ) {
			if ( in_array( $existing, Stockland_Roles::supported_roles(), true ) ) {
				$user->remove_role( $existing );
			}
		}
		foreach ( (array) $roles as $old ) {
			if ( get_role( $old ) ) {
				$user->add_role( $old );
			}
		}
	}

	/**
	 * Resolve and validate the role/location assignment used by create, edit and activation.
	 *
	 * @param string   $role ERP role slug to assign or verify.
	 * @param int|null $requested_primary Requested primary location ID.
	 * @param array    $requested_locations Requested assigned location IDs.
	 */
	private function validated_assignment( $role, $requested_primary, $requested_locations ) {
		if ( ! in_array( $role, Stockland_Roles::supported_roles(), true ) ) {
			return new WP_Error( 'invalid_role', 'Invalid Stockland role.', array( 'status' => 400 ) );
		}
		if ( Stockland_Roles::ROLE_OWNER === $role && ! Stockland_Authority::is_owner() ) {
			return new WP_Error( 'owner_only', 'Only the current Owner may assign the Owner role.', array( 'status' => 403 ) );
		}
		if ( in_array( $role, array( Stockland_Roles::ROLE_BRANCH_MANAGER, Stockland_Roles::ROLE_SALES_EMPLOYEE ), true ) ) {
			$location_ids = $this->normalize_location_ids( $role, $requested_primary, $requested_locations );
			if ( ! $location_ids ) {
				return new WP_Error( 'invalid_assignment', 'Branch users must be assigned to at least one active retail branch.', array( 'status' => 400 ) );
			}
			return array(
				'location_ids'       => $location_ids,
				'branch_location_id' => (int) $location_ids[0],
			);
		}
		if ( in_array( $role, array( Stockland_Roles::ROLE_WAREHOUSE_EMPLOYEE, Stockland_Roles::ROLE_WAREHOUSE_MANAGER ), true ) ) {
			$central      = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
			$location_ids = $this->normalize_location_ids( $role, $requested_primary, $requested_locations );
			if ( ! $central || ! $location_ids || (int) $location_ids[0] !== $central ) {
				return new WP_Error( 'invalid_assignment', 'Warehouse users must be assigned to Central Warehouse.', array( 'status' => 400 ) );
			}
			return array(
				'location_ids'       => array( $central ),
				'branch_location_id' => $central,
			);
		}
		return array(
			'location_ids'       => array(),
			'branch_location_id' => null,
		);
	}

	/**
	 * Creates a WordPress user and its authorized ERP profile.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function create_user( WP_REST_Request $r ) {
		$p     = $r->get_json_params();
		$login = sanitize_user( $p['user_login'] ?? '' );
		$email = sanitize_email( $p['email'] ?? '' );
		$pass  = (string) ( $p['password'] ?? '' );
		$role  = sanitize_key( $p['role'] ?? Stockland_Roles::ROLE_SALES_EMPLOYEE );
		if ( '' === $login || strlen( $pass ) < 10 ) {
			return new WP_Error( 'invalid_user_input', 'Username and a password of at least 10 characters are required.', array( 'status' => 400 ) );
		}
		$primary    = array_key_exists( 'branch_location_id', $p ) && '' !== $p['branch_location_id'] ? (int) $p['branch_location_id'] : null;
		$assignment = $this->validated_assignment( $role, $primary, $p['location_ids'] ?? array() );
		if ( is_wp_error( $assignment ) ) {
			return $assignment;
		}
		$id = wp_create_user( $login, $pass, $email );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $id );
			return new WP_Error( 'transaction_failed', 'Could not start ERP user setup transaction.', array( 'status' => 500 ) );
		}$previous = array();
		try {
			$previous = $this->apply_stockland_role( $id, $role, true );
			$profile  = array(
				'wp_user_id'         => $id,
				'role'               => str_replace( 'stockland_', '', $role ),
				'branch_location_id' => $assignment['branch_location_id'],
				'is_active'          => 1,
			);
			if ( false === $wpdb->insert( Stockland_DB::table( 'users' ), $profile ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not create the Stockland user profile.' );
			}
			if ( ! $this->sync_location_assignments( $id, $assignment['location_ids'], $assignment['branch_location_id'], get_current_user_id() ) ) {
				throw new Exception( 'Could not save the user operational scope.' );
			}
			if ( ! $this->sync_user_identity(
				$id,
				array(
					'display_name' => sanitize_text_field( $p['display_name'] ?? $login ),
					'email'        => $email,
				)
			) ) {
				throw new Exception( 'Could not update the WordPress user identity.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $assignment['branch_location_id'], 'USER_CREATE', null, null, null, $role, 'User ' . $login, 'WP#' . (string) $id ) ) {
				throw new Exception( 'Audit event could not be recorded; user was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'User create commit failed.' );
			}
			return rest_ensure_response(
				array(
					'id'                 => $id,
					'role'               => $role,
					'branch_location_id' => $assignment['branch_location_id'],
					'location_ids'       => $assignment['location_ids'],
					'erp_active'         => true,
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$this->restore_stockland_roles( $id, $previous );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $id );
			return new WP_Error( 'profile_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Existing WordPress accounts needing ERP profile activation or role repair.
	 * The ERP remains the source of truth for operational role + location scope.
	 */
	public function available_wp_users() {
		global $wpdb;
		$stock_table = Stockland_DB::table( 'users' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID,u.user_login,u.display_name,u.user_email,u.user_registered,
                su.role AS erp_role,su.is_active AS erp_is_active
            FROM %i u
            LEFT JOIN %i su ON su.wp_user_id=u.ID
            ORDER BY u.display_name,u.user_login",
				$wpdb->users,
				$stock_table
			),
			ARRAY_A
		);
		$out         = array();
		$user_ids    = array();
		foreach ( $rows as $row ) {
			$user_ids[] = (int) $row['ID'];
		}
		if ( $user_ids && function_exists( 'cache_users' ) ) {
			cache_users( array_values( array_unique( $user_ids ) ) );
		}
		foreach ( $rows as $row ) {
			$wu              = get_userdata( (int) $row['ID'] );
			$native          = array_values( (array) ( $wu ? $wu->roles : array() ) );
			$erp_role        = $row['erp_role'] ? 'stockland_' . sanitize_key( $row['erp_role'] ) : '';
			$active          = ( (int) ( $row['erp_is_active'] ?? 0 ) === 1 );
			$wp_role_present = '' !== $erp_role && in_array( $erp_role, $native, true );
			if ( $active && $wp_role_present ) {
				continue;
			}
			$row['native_roles']          = $native;
			$row['has_stockland_profile'] = null !== $row['erp_role'] && '' !== $row['erp_role'];
			$row['is_erp_active']         = $active;
			$row['erp_role']              = $erp_role;
			$row['status_label']          = $active ? 'ERP role not active on account' : 'ERP profile not active';
			if ( ! $row['has_stockland_profile'] ) {
				$row['status_label'] = 'No ERP profile';
			} elseif ( $active && ! $wp_role_present ) {
				$row['status_label'] = 'ERP role needs activation';
			}
			$out[] = $row;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Minimal pre-profile gateway. It deliberately exposes no operational ERP data.
	 * It exists so an authenticated native WordPress administrator/management user can
	 * reach the ERP's User & Organization activation workflow when their Stockland
	 * profile has not yet been created, while ordinary unactivated users receive a
	 * read-only pending-activation screen instead of a hard bootstrap failure.
	 */
	public function access_bootstrap() {
		$this->ensure_native_admin_profile();
		$uid        = (int) get_current_user_id();
		$profile    = Stockland_Roles::get_profile( $uid );
		$can_manage = (bool) $this->can_manage_users_frontend();
		$result     = array(
			'authenticated'     => is_user_logged_in(),
			'wp_user_id'        => $uid,
			'display_name'      => (string) wp_get_current_user()->display_name,
			'user_login'        => (string) wp_get_current_user()->user_login,
			'profile_active'    => (bool) $profile,
			'role'              => $profile ? (string) $profile['role'] : '',
			'can_manage_users'  => $can_manage,
			'is_owner_eligible' => (bool) Stockland_Authority::is_owner( $uid ),
			'locations'         => array(),
			'accounts'          => array(),
		);
		if ( $can_manage ) {
			$result['locations'] = $this->locations_for_assignment();
			$result['accounts']  = $this->available_wp_users()->get_data();
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Activates an existing WordPress account for ERP use.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function activate_existing_user( WP_REST_Request $r ) {
		global $wpdb;
		$p      = $r->get_json_params();
		$target = (int) ( $p['wp_user_id'] ?? 0 );
		if ( $target <= 0 ) {
			return new WP_Error( 'invalid_user', 'A valid WordPress user account is required.', array( 'status' => 400 ) );
		}
		$wp_user = get_userdata( $target );
		if ( ! $wp_user ) {
			return new WP_Error( 'not_found', 'WordPress user account not found.', array( 'status' => 404 ) );
		}
		$role       = sanitize_key( $p['role'] ?? Stockland_Roles::ROLE_SALES_EMPLOYEE );
		$primary    = array_key_exists( 'branch_location_id', $p ) && '' !== $p['branch_location_id'] ? (int) $p['branch_location_id'] : null;
		$assignment = $this->validated_assignment( $role, $primary, $p['location_ids'] ?? array() );
		if ( is_wp_error( $assignment ) ) {
			return $assignment;
		}
		$stock_table = Stockland_DB::table( 'users' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start ERP user activation transaction.', array( 'status' => 500 ) );
		}$previous = array();
		try {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE wp_user_id=%d FOR UPDATE', $stock_table, $target ), ARRAY_A );
			$old_ref  = $existing ? $existing['role'] : 'UNLINKED';
			$previous = $this->apply_stockland_role( $target, $role, true );
			$data     = array(
				'role'               => str_replace( 'stockland_', '', $role ),
				'branch_location_id' => $assignment['branch_location_id'],
				'is_active'          => 1,
			);
			if ( $existing ) {
				if ( false === $wpdb->update( $stock_table, $data, array( 'wp_user_id' => $target ) ) ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not activate the Stockland user profile.' );
				}
			} elseif ( false === $wpdb->insert( $stock_table, array_merge( array( 'wp_user_id' => $target ), $data ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not create the Stockland user profile.' );
			}
			if ( ! $this->sync_location_assignments( $target, $assignment['location_ids'], $assignment['branch_location_id'], get_current_user_id() ) ) {
				throw new Exception( 'Could not save the user operational scope.' );
			}
			if ( ! $this->sync_user_identity( $target, $p ) ) {
				throw new Exception( 'Could not update the WordPress user identity.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $assignment['branch_location_id'], 'USER_ACTIVATE', null, null, $old_ref, $role, 'Activated existing WordPress account', 'WP#' . (string) $target ) ) {
				throw new Exception( 'Audit event could not be recorded; activation was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'User activation commit failed.' );
			}
			return rest_ensure_response(
				array(
					'activated'          => true,
					'wp_user_id'         => $target,
					'role'               => $role,
					'branch_location_id' => $assignment['branch_location_id'],
					'location_ids'       => $assignment['location_ids'],
					'erp_active'         => true,
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$this->restore_stockland_roles( $target, $previous );
			return new WP_Error( 'activation_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Returns locations available for an authorized user assignment.
	 *
	 * @return mixed The operation result.
	 */
	private function locations_for_assignment() {
		static $rows = null;
		if ( null !== $rows ) {
			return $rows;
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id,location_name,location_type FROM ' . Stockland_DB::table( 'locations' ) . " WHERE is_active=1 AND location_type IN('Branch','Warehouse') ORDER BY id", ARRAY_A );
		return $rows;
	}

	/**
	 * Updates an ERP user's role, activation state, and location scope.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function update_user( WP_REST_Request $r ) {
		global $wpdb;
		$target = (int) $r['id'];
		$p      = $r->get_json_params();
		$row    = $wpdb->get_row( $wpdb->prepare('SELECT * FROM %i WHERE wp_user_id=%d', 'users', $target), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Stockland user not found.', array( 'status' => 404 ) );
		}
		if ( get_current_user_id() === $target && isset( $p['is_active'] ) && ! (bool) $p['is_active'] ) {
			return new WP_Error( 'self_deactivate', 'You cannot deactivate your own account.', array( 'status' => 409 ) );
		}
		$role = sanitize_key( $p['role'] ?? ( 'stockland_' . $row['role'] ) );
		if ( 'owner' === $row['role'] && ! Stockland_Authority::is_owner() ) {
			return new WP_Error( 'owner_only', 'Owner accounts can be administered only by the Owner.', array( 'status' => 403 ) );
		}
		$primary    = array_key_exists( 'branch_location_id', $p ) && '' !== $p['branch_location_id'] ? (int) $p['branch_location_id'] : null;
		$assignment = $this->validated_assignment( $role, $primary, array_key_exists( 'location_ids', $p ) ? $p['location_ids'] : Stockland_Authority::location_ids( $target ) );
		if ( is_wp_error( $assignment ) ) {
			return $assignment;
		}
		$active = isset( $p['is_active'] ) ? (bool) $p['is_active'] : (bool) $row['is_active'];
		if ( 'management' === $row['role'] && ! $active ) {
			$remaining = (int) $wpdb->get_var( $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE role=\'management\' AND is_active=1 AND wp_user_id<>%d', 'users', $target) );
			if ( $remaining < 1 ) {
				return new WP_Error( 'last_management', 'At least one active Management user must remain.', array( 'status' => 409 ) );
			}
		}
		$wp_user = get_userdata( $target );
		if ( ! $wp_user ) {
			return new WP_Error( 'not_found', 'WordPress user account not found.', array( 'status' => 404 ) );
		}
		$old_roles = array_values( array_intersect( (array) $wp_user->roles, Stockland_Roles::supported_roles() ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start ERP user update transaction.', array( 'status' => 500 ) );
		}
		try {
			$branch = $assignment['branch_location_id'];
			if ( false === $wpdb->update(
				Stockland_DB::table( 'users' ),
				array(
					'role'               => str_replace( 'stockland_', '', $role ),
					'branch_location_id' => $branch,
					'is_active'          => $active ? 1 : 0,
				),
				array( 'wp_user_id' => $target )
			) && $wpdb->last_error ) {
				throw new Exception( $wpdb->last_error );
			}
			$this->apply_stockland_role( $target, $role, $active );
			if ( ! $this->sync_user_identity( $target, $p ) ) {
				throw new Exception( 'Could not update the WordPress user identity.' );
			}
			if ( ! $this->sync_location_assignments( $target, $active ? $assignment['location_ids'] : array(), $active ? $branch : null, get_current_user_id() ) ) {
				throw new Exception( 'Could not save the user operational scope.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $branch, 'USER_UPDATE', null, null, $row['role'] . '/' . $row['branch_location_id'], $active ? $role : 'INACTIVE', null, (string) $target ) ) {
				throw new Exception( 'Audit event could not be recorded; user update was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'User update commit failed.' );
			}
			return rest_ensure_response(
				array(
					'updated'            => true,
					'wp_user_id'         => $target,
					'branch_location_id' => $branch,
					'location_ids'       => $active ? $assignment['location_ids'] : array(),
					'erp_active'         => $active,
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$this->restore_stockland_roles( $target, $old_roles );
			return new WP_Error( 'update_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Returns authorized ERP settings.
	 *
	 * @return mixed The operation result.
	 */
	public function settings() {
		global $wpdb;
		return rest_ensure_response( $wpdb->get_results( 'SELECT setting_key,setting_value,updated_at FROM ' . Stockland_DB::table( 'settings' ) . " WHERE setting_key NOT LIKE '__stockland_sequence__%' ORDER BY setting_key", ARRAY_A ) );}
	/**
	 * Validates and saves permitted ERP settings with audit logging.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function save_settings( WP_REST_Request $r ) {
		$p       = $r->get_json_params();
		$allowed = array( 'vat_rate', 'vat_basis', 'business_name', 'tagline', 'branch_reorder_alert_level', 'central_reorder_alert_level' );
		global $wpdb;
		foreach ( array_keys( (array) $p ) as $submitted_key ) {
			if ( 0 === strpos( (string) $submitted_key, Stockland_Sequence::KEY_PREFIX ) ) {
				return new WP_Error( 'protected_setting', 'Internal ERP sequence state cannot be edited.', array( 'status' => 403 ) ); }
		}
		$changes = array();
		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, $p ) ) {
				continue;
			}if ( 'vat_rate' === $k ) {
				$v = (float) $p[ $k ];
				if ( $v < 0 || $v > 1 ) {
					return new WP_Error( 'invalid_vat', 'VAT rate must be between 0 and 100%.', array( 'status' => 400 ) );
				}$v = number_format( $v, 4, '.', '' );
			} else {
				$v = sanitize_text_field( (string) $p[ $k ] );
			}$changes[ $k ] = $v;}
		if ( empty( $changes ) ) {
			return $this->settings();
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start settings transaction.', array( 'status' => 500 ) );
		}
		try {
			foreach ( $changes as $k => $v ) {
				$old = Stockland_DB::get_setting( $k, null );
				if ( false === $wpdb->query( $wpdb->prepare('INSERT INTO %i (setting_key,setting_value,updated_by) VALUES(%s,%s,%d) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)', 'settings',  $k,  $v,  get_current_user_id() ) ) ) {
					throw new Exception( 'Could not save setting ' . $k . '.' );
				}
				if ( (string) $old !== (string) $v && ! Stockland_Audit::log( get_current_user_id(), null, 'SETTING_UPDATE', null, null, $old, $v, 'Management setting update', $k ) ) {
					throw new Exception( 'Audit event could not be recorded; settings were not committed.' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Settings commit failed.' );
			} return $this->settings();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'settings_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}
	/**
	 * Aggregates sales and financial summaries for a validated date range and scope.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function reports_summary( WP_REST_Request $r ) {
		global $wpdb;
		$requested_from = $r->get_param( 'from' );
		$from           = sanitize_text_field( $requested_from ? $requested_from : wp_date( 'Y-m-01' ) );
		$requested_to   = $r->get_param( 'to' );
		$to             = sanitize_text_field( $requested_to ? $requested_to : current_time( 'Y-m-d' ) );
		if ( ! $this->parse_import_date( $from ) || ! $this->parse_import_date( $to ) || $from > $to ) {
			return new WP_Error( 'invalid_date_range', 'Use a valid From/To date range.', array( 'status' => 400 ) );
		}
		$scope = $this->scope_location();
		if ( false === $scope ) {
			return new WP_Error( 'location_scope_unavailable', 'A valid assigned location is required for this report. Ask Management to correct your ERP location assignment.', array( 'status' => 403 ) );
		}
		$sales = Stockland_DB::table( 'sales' );
		$wh    = Stockland_DB::table( 'wholesale_sales' );
		$ret   = Stockland_DB::table( 'returns' );
		$loc   = Stockland_DB::table( 'locations' );

		$sales = Stockland_DB::table( 'sales' );
		$wh    = Stockland_DB::table( 'wholesale_sales' );
		$ret   = Stockland_DB::table( 'returns' );
		$loc   = Stockland_DB::table( 'locations' );
		$prod  = Stockland_DB::table( 'products' );
		$tx    = Stockland_DB::table( 'transactions' );
		$res   = Stockland_DB::table( 'reservations' );
		$tr    = Stockland_DB::table( 'transfers' );
		$cb    = Stockland_DB::table( 'count_batches' );
		$cl    = Stockland_DB::table( 'count_lines' );

		$rt_scope = $scope ? ' AND branch_location_id=%d' : '';
		$rt_args  = array( $sales, $from, $to );
		if ( $scope ) {
			$rt_args[] = (int) $scope;
		}
		$rt_sql = "SELECT COALESCE(SUM(sales_total),0) sales,COALESCE(SUM(net_sales_excl_vat),0) net,
                    COALESCE(SUM(vat_amount),0) vat,COALESCE(SUM(gross_profit),0) profit,
                    COALESCE(SUM(quantity_sold),0) units
             FROM %i WHERE posting_status='CLOSED' AND sale_date BETWEEN %s AND %s" . $rt_scope;
		$rt = $wpdb->get_row( $wpdb->prepare( $rt_sql, $rt_args ), ARRAY_A );

		$wt = $scope ? array(
			'sales'  => 0,
			'net'    => 0,
			'vat'    => 0,
			'profit' => 0,
			'units'  => 0,
		) : $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(sales_value),0) sales,COALESCE(SUM(net_sales_excl_vat),0) net,
                    COALESCE(SUM(vat_amount),0) vat,COALESCE(SUM(gross_profit),0) profit,
                    COALESCE(SUM(quantity),0) units
             FROM %i WHERE sale_date BETWEEN %s AND %s",
				$wh,
				$from,
				$to
			),
			ARRAY_A
		);

		$branch_sql = "SELECT l.location_name,
                    COALESCE(SUM(s.quantity_sold),0) retail_units,
                    COALESCE(SUM(s.sales_total),0) retail_sales,
                    COALESCE(SUM(s.gross_profit),0) retail_profit
             FROM %i l
             LEFT JOIN %i s ON s.branch_location_id=l.id AND s.posting_status='CLOSED'
                  AND s.sale_date BETWEEN %s AND %s
             WHERE l.location_type='Branch'";
		$branch_args = array( $loc, $sales, $from, $to );
		if ( $scope ) {
			$branch_sql  .= ' AND l.id=%d';
			$branch_args[] = (int) $scope;
		}
		$branch_sql .= ' GROUP BY l.id ORDER BY retail_sales DESC';
		$branch = $wpdb->get_results( $wpdb->prepare( $branch_sql, $branch_args ), ARRAY_A );

		$top_sql = "SELECT s.sku,p.product_name,COALESCE(SUM(s.quantity_sold),0) units,
                    COALESCE(SUM(s.sales_total),0) sales,COALESCE(SUM(s.gross_profit),0) profit
             FROM %i s JOIN %i p ON (p.variant_id=s.variant_id OR (s.variant_id IS NULL AND p.sku=s.sku))
             WHERE s.posting_status='CLOSED' AND s.sale_date BETWEEN %s AND %s";
		$top_args = array( $sales, $prod, $from, $to );
		if ( $scope ) {
			$top_sql  .= ' AND s.branch_location_id=%d';
			$top_args[] = (int) $scope;
		}
		$top_sql .= ' GROUP BY s.sku,p.product_name ORDER BY units DESC LIMIT 10';
		$top = $wpdb->get_results( $wpdb->prepare( $top_sql, $top_args ), ARRAY_A );

		$returns_sql  = 'SELECT COALESCE(SUM(quantity),0) units,COUNT(*) rows FROM %i WHERE return_date BETWEEN %s AND %s';
		$returns_args = array( $ret, $from, $to );
		if ( $scope ) {
			$returns_sql .= ' AND location_id=%d';
			$returns_args[] = (int) $scope;
		}
		$returns = $wpdb->get_row( $wpdb->prepare( $returns_sql, $returns_args ), ARRAY_A );

		$stock_sql = "SELECT l.location_name,p.sku,p.product_name,p.brand,p.size,p.color,
                    COALESCE(b.physical_stock,0) physical_stock,COALESCE(a.reserved_stock,0) reserved_stock,
                    COALESCE(b.physical_stock,0)-COALESCE(a.reserved_stock,0) available_stock,
                    IF(l.location_type='Warehouse',p.central_reorder_level,p.branch_reorder_level) reorder_level
             FROM %i p JOIN %i l ON l.location_type IN('Warehouse','Branch')
             LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b
               ON b.variant_id=p.variant_id AND b.location_id=l.id
             LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_reserved) reserved_stock FROM %i WHERE status='Active' AND variant_id IS NOT NULL GROUP BY location_id,variant_id) a
               ON a.variant_id=p.variant_id AND a.location_id=l.id
             WHERE p.is_active=1";
		$stock_args = array( $prod, $loc, $tx, $res );
		if ( $scope ) {
			$stock_sql  .= ' AND l.id=%d';
			$stock_args[] = (int) $scope;
		}
		$stock_sql .= ' ORDER BY l.location_name,p.sku';
		$stock = $wpdb->get_results( $wpdb->prepare( $stock_sql, $stock_args ), ARRAY_A );

		$transfers_sql = "SELECT t.transfer_id,t.status,t.transfer_date,t.sku,p.product_name,f.location_name from_location_name,to_l.location_name to_location_name,
                    t.quantity,t.dispatched_qty,t.received_qty,t.variance_qty
             FROM %i t
             JOIN %i p ON (p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku))
             JOIN %i f ON f.id=t.from_location_id
             JOIN %i to_l ON to_l.id=t.to_location_id
             WHERE t.transfer_date BETWEEN %s AND %s";
		$targs = array( $tr, $prod, $loc, $loc, $from, $to );
		if ( $scope ) {
			$transfers_sql .= ' AND (t.from_location_id=%d OR t.to_location_id=%d)';
			$targs[] = (int) $scope;
			$targs[] = (int) $scope;
		}
		$transfers_sql .= ' ORDER BY t.created_at DESC LIMIT 500';
		$transfer_rows = $wpdb->get_results( $wpdb->prepare( $transfers_sql, $targs ), ARRAY_A );

		$people_sql = "SELECT l.location_name,u.display_name,COUNT(s.sale_id) sales_count,
                    COALESCE(SUM(s.quantity_sold),0) units,COALESCE(SUM(s.sales_total),0) revenue
             FROM %i s JOIN %i l ON l.id=s.branch_location_id
             LEFT JOIN %i u ON u.ID=s.staff_user_id
             WHERE s.posting_status='CLOSED' AND s.sale_date BETWEEN %s AND %s";
		$people_args = array( $sales, $loc, $wpdb->users, $from, $to );
		if ( $scope ) {
			$people_sql .= ' AND s.branch_location_id=%d';
			$people_args[] = (int) $scope;
		}
		$people_sql .= ' GROUP BY l.id,s.staff_user_id,u.display_name ORDER BY revenue DESC LIMIT 200';
		$people = $wpdb->get_results( $wpdb->prepare( $people_sql, $people_args ), ARRAY_A );

		$variance_sql = "SELECT t.transfer_id,t.status,t.transfer_date,t.sku,p.product_name,
                    f.location_name from_location_name,to_l.location_name to_location_name,
                    t.quantity,t.dispatched_qty,t.received_qty,t.variance_qty,t.receipt_reason,t.received_at
             FROM %i t
             JOIN %i p ON (p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku))
             JOIN %i f ON f.id=t.from_location_id
             JOIN %i to_l ON to_l.id=t.to_location_id
             WHERE t.transfer_date BETWEEN %s AND %s AND t.status IN('Received','Completed')
               AND ABS(t.variance_qty)>0.00001";
		$variance_args = array( $tr, $prod, $loc, $loc, $from, $to );
		if ( $scope ) {
			$variance_sql .= ' AND (t.from_location_id=%d OR t.to_location_id=%d)';
			$variance_args[] = (int) $scope;
			$variance_args[] = (int) $scope;
		}
		$variance_sql .= ' ORDER BY t.received_at DESC LIMIT 500';
		$variances = $wpdb->get_results( $wpdb->prepare( $variance_sql, $variance_args ), ARRAY_A );

		$count_sql = "SELECT b.batch_id,b.count_date,b.status,l.location_name,b.uploaded_by,b.approved_by,
                    COUNT(cl.id) lines,SUM(CASE WHEN ABS(cl.variance_qty)>0.00001 THEN 1 ELSE 0 END) variance_lines,
                    SUM(cl.counted_qty) counted_qty,SUM(cl.system_qty) system_qty,SUM(cl.variance_qty) variance_qty
             FROM %i b
             JOIN %i l ON l.id=b.location_id
             LEFT JOIN %i cl ON cl.batch_id=b.batch_id
             WHERE b.count_date BETWEEN %s AND %s";
		$cargs = array( $cb, $loc, $cl, $from, $to );
		if ( $scope ) {
			$count_sql .= ' AND b.location_id=%d';
			$cargs[] = (int) $scope;
		}
		$count_sql .= ' GROUP BY b.id ORDER BY b.created_at DESC LIMIT 200';
		$count_batches = $wpdb->get_results( $wpdb->prepare( $count_sql, $cargs ), ARRAY_A );
		if ( ! current_user_can( 'stockland_view_cost_and_profit' ) ) {
			$rt['profit'] = 0;
			foreach ( $branch as &$br ) {
				$br['retail_profit'] = 0;
			} unset( $br );
			foreach ( $top as &$tp ) {
				$tp['profit'] = 0;
			} unset( $tp );
		}
		return rest_ensure_response(
			array(
				'from'              => $from,
				'to'                => $to,
				'totals'            => array(
					'sales'  => (float) $rt['sales'] + (float) $wt['sales'],
					'net'    => (float) $rt['net'] + (float) $wt['net'],
					'vat'    => (float) $rt['vat'] + (float) $wt['vat'],
					'profit' => (float) $rt['profit'] + (float) $wt['profit'],
					'units'  => (float) $rt['units'] + (float) $wt['units'],
				),
				'branches'          => $branch,
				'top_products'      => $top,
				'people'            => $people,
				'variances'         => $variances,
				'returns'           => $returns,
				'stock'             => $stock,
				'transfers'         => $transfer_rows,
				'physical_counts'   => $count_batches,
				'scope_location_id' => $scope,
			)
		);
	}

	/**
	 * Builds a date-scoped summary of operational transactions.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function daily_operations( WP_REST_Request $r ) {
		global $wpdb;
		$scope = $this->resolve_branch_for_user( $r->get_param( 'location_id' ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$requested_date = $r->get_param( 'date' );
		$date           = sanitize_text_field( $requested_date ? $requested_date : current_time( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'invalid_date', 'date must be YYYY-MM-DD.', array( 'status' => 400 ) );
		}

		$sales_scope_sql    = $scope ? ' AND s.branch_location_id=%d' : '';
		$transfer_scope_sql = $scope ? ' AND (t.from_location_id=%d OR t.to_location_id=%d)' : '';
		$central            = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$receipt_scope_sql  = $scope && (int) $scope !== $central ? ' AND 1=0' : '';

		$sales_sql = "SELECT s.sale_id,s.sale_date,s.branch_location_id,l.location_name,s.sku,p.product_name,s.quantity_sold,s.size,s.color,s.master_price_at_sale,s.selling_price,s.sales_total,s.vat_amount,s.net_sales_excl_vat,s.gross_profit,s.staff_user_id,u.display_name
			FROM %i s
			JOIN %i l ON l.id=s.branch_location_id
			JOIN %i p ON (p.variant_id=s.variant_id OR (s.variant_id IS NULL AND p.sku=s.sku))
			LEFT JOIN %i u ON u.ID=s.staff_user_id
			WHERE s.posting_status='CLOSED' AND s.sale_date=%s" . $sales_scope_sql . ' ORDER BY s.sale_id DESC';
		$sales_args = array( Stockland_DB::table( 'sales' ), Stockland_DB::table( 'locations' ), Stockland_DB::table( 'products' ), $wpdb->users, $date );
		if ( $scope ) {
			$sales_args[] = (int) $scope;
		}
		$sales = $wpdb->get_results( $wpdb->prepare( $sales_sql, $sales_args ), ARRAY_A );

		$transfer_sql = "SELECT t.transfer_id,t.sku,p.product_name,t.quantity,t.dispatched_qty,t.received_qty,t.variance_qty,t.from_location_id,t.to_location_id,f.location_name from_location_name,to_l.location_name to_location_name,t.status,t.received_at,u.display_name received_by_name
			FROM %i t
			JOIN %i p ON (p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku))
			JOIN %i f ON f.id=t.from_location_id
			JOIN %i to_l ON to_l.id=t.to_location_id
			LEFT JOIN %i u ON u.ID=t.received_by
			WHERE t.received_at IS NOT NULL AND DATE(t.received_at)=%s" . $transfer_scope_sql . ' ORDER BY t.received_at DESC';
		$transfer_args = array( Stockland_DB::table( 'transfers' ), Stockland_DB::table( 'products' ), Stockland_DB::table( 'locations' ), Stockland_DB::table( 'locations' ), $wpdb->users, $date );
		if ( $scope ) {
			$transfer_args[] = (int) $scope;
			$transfer_args[] = (int) $scope;
		}
		$received = $wpdb->get_results( $wpdb->prepare( $transfer_sql, $transfer_args ), ARRAY_A );

		$receipt_sql = "SELECT r.receipt_id,r.sku,p.product_name,r.quantity_received expected_qty,COALESCE(r.actual_quantity_received,0) actual_qty,r.supplier_source,r.status,r.received_at,u.display_name received_by_name
			FROM %i r
			JOIN %i p ON (p.variant_id=r.variant_id OR (r.variant_id IS NULL AND p.sku=r.sku))
			LEFT JOIN %i u ON u.ID=r.received_by
			WHERE r.receipt_date=%s" . $receipt_scope_sql . ' ORDER BY r.created_at DESC';
		$receipt_args = array( Stockland_DB::table( 'receipts' ), Stockland_DB::table( 'products' ), $wpdb->users, $date );
		$receipts = $wpdb->get_results( $wpdb->prepare( $receipt_sql, $receipt_args ), ARRAY_A );

		$summary_scope_sql = $scope ? ' AND s.branch_location_id=%d' : '';
		$summary_sql = "SELECT COUNT(*) sale_count,COALESCE(SUM(quantity_sold),0) units,COALESCE(SUM(sales_total),0) revenue,COALESCE(SUM(vat_amount),0) vat,COALESCE(SUM(gross_profit),0) profit
			FROM %i s WHERE s.posting_status='CLOSED' AND s.sale_date=%s" . $summary_scope_sql;
		$summary_args = array( Stockland_DB::table( 'sales' ), $date );
		if ( $scope ) {
			$summary_args[] = (int) $scope;
		}
		$summary = $wpdb->get_row( $wpdb->prepare( $summary_sql, $summary_args ), ARRAY_A );

		$closing_scope_sql = $scope ? ' AND dc.branch_location_id=%d' : '';
		$closing_sql = 'SELECT dc.branch_location_id,l.location_name,dc.status,dc.closed_at,dc.closed_by,dc.total_sales,dc.total_vat,dc.total_gross_profit FROM %i dc JOIN %i l ON l.id=dc.branch_location_id WHERE dc.business_date=%s' . $closing_scope_sql . ' ORDER BY l.location_name';
		$closing_args = array( Stockland_DB::table( 'daily_closings' ), Stockland_DB::table( 'locations' ), $date );
		if ( $scope ) {
			$closing_args[] = (int) $scope;
		}
		$closings = $wpdb->get_results( $wpdb->prepare( $closing_sql, $closing_args ), ARRAY_A );

		return rest_ensure_response(
			array(
				'date'              => $date,
				'summary'           => $summary,
				'sales'             => $sales,
				'receipts'          => $receipts,
				'transfer_receipts' => $received,
				'closing'           => count( $closings ) === 1 ? $closings[0] : null,
				'closings'          => $closings,
				'scope_location_id' => $scope,
			)
		);
	}

	/**
	 * Returns the role-scoped operational overview.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function operations_overview( WP_REST_Request $r ) {
		global $wpdb;
		$requested_from = $r->get_param( 'from' );
		$from           = sanitize_text_field( $requested_from ? $requested_from : gmdate( 'Y-m-01' ) );
		$requested_to   = $r->get_param( 'to' );
		$to             = sanitize_text_field( $requested_to ? $requested_to : current_time( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			return new WP_Error( 'invalid_date', 'from/to must be YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		if ( $from > $to ) {
			return new WP_Error( 'invalid_date', 'from must be on or before to.', array( 'status' => 400 ) );
		}

		$scope = $this->resolve_branch_for_user( $r->get_param( 'location_id' ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		if ( $scope && ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id=%d AND is_active=1 AND location_type IN('Branch','Warehouse')", Stockland_DB::table( 'locations' ), (int) $scope ) ) ) {
			return new WP_Error( 'invalid_location', 'Selected location is not an active branch or warehouse.', array( 'status' => 400 ) );
		}

		$branch_scope_sql   = $scope ? ' AND l.id=%d' : '';
		$product_scope_sql  = $scope ? ' AND s.branch_location_id=%d' : '';
		$person_scope_sql   = $scope ? ' AND s.branch_location_id=%d' : '';
		$transfer_scope_sql = $scope ? ' AND (t.from_location_id=%d OR t.to_location_id=%d)' : '';
		$low_scope_sql      = $scope ? ' AND l.id=%d' : '';

		$sales_sql = "SELECT l.id,l.location_name,COUNT(s.sale_id) sales_count,COALESCE(SUM(s.quantity_sold),0) units,COALESCE(SUM(s.sales_total),0) revenue,COALESCE(SUM(s.gross_profit),0) profit
			FROM %i l
			LEFT JOIN %i s ON s.branch_location_id=l.id AND s.posting_status='CLOSED' AND s.sale_date BETWEEN %s AND %s
			WHERE l.location_type='Branch'" . $branch_scope_sql . ' GROUP BY l.id,l.location_name ORDER BY revenue DESC';
		$sales_args = array( Stockland_DB::table( 'locations' ), Stockland_DB::table( 'sales' ), $from, $to );
		if ( $scope ) {
			$sales_args[] = (int) $scope;
		}
		$sales = $wpdb->get_results( $wpdb->prepare( $sales_sql, $sales_args ), ARRAY_A );

		$by_product_sql = "SELECT l.location_name,s.sku,s.variant_id,p.product_name,p.brand,p.color,p.size,p.size_system,COALESCE(SUM(s.quantity_sold),0) units,COALESCE(SUM(s.sales_total),0) revenue,COALESCE(SUM(s.gross_profit),0) profit
			FROM %i s
			JOIN %i l ON l.id=s.branch_location_id
			JOIN %i p ON (p.variant_id=s.variant_id OR (s.variant_id IS NULL AND p.sku=s.sku))
			WHERE s.posting_status='CLOSED' AND s.sale_date BETWEEN %s AND %s" . $product_scope_sql . ' GROUP BY l.id,s.sku,s.variant_id,p.product_name,p.brand,p.color,p.size,p.size_system ORDER BY revenue DESC LIMIT 200';
		$product_args = array( Stockland_DB::table( 'sales' ), Stockland_DB::table( 'locations' ), Stockland_DB::table( 'products' ), $from, $to );
		if ( $scope ) {
			$product_args[] = (int) $scope;
		}
		$by_product = $wpdb->get_results( $wpdb->prepare( $by_product_sql, $product_args ), ARRAY_A );

		$by_person_sql = "SELECT l.location_name,u.display_name,COUNT(s.sale_id) sales_count,COALESCE(SUM(s.quantity_sold),0) units,COALESCE(SUM(s.sales_total),0) revenue
			FROM %i s
			JOIN %i l ON l.id=s.branch_location_id
			LEFT JOIN %i u ON u.ID=s.staff_user_id
			WHERE s.posting_status='CLOSED' AND s.sale_date BETWEEN %s AND %s" . $person_scope_sql . ' GROUP BY l.id,s.staff_user_id,u.display_name ORDER BY revenue DESC';
		$person_args = array( Stockland_DB::table( 'sales' ), Stockland_DB::table( 'locations' ), $wpdb->users, $from, $to );
		if ( $scope ) {
			$person_args[] = (int) $scope;
		}
		$by_person = $wpdb->get_results( $wpdb->prepare( $by_person_sql, $person_args ), ARRAY_A );

		$variance_sql = "SELECT t.transfer_id,f.location_name from_location_name,to_l.location_name to_location_name,t.sku,p.product_name,t.dispatched_qty,t.received_qty,t.variance_qty,t.receipt_reason,t.received_at
			FROM %i t
			JOIN %i f ON f.id=t.from_location_id
			JOIN %i to_l ON to_l.id=t.to_location_id
			JOIN %i p ON (p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku))
			WHERE t.status IN('Received','Completed') AND DATE(t.received_at) BETWEEN %s AND %s AND ABS(t.variance_qty)>0" . $transfer_scope_sql . ' ORDER BY t.received_at DESC';
		$variance_args = array( Stockland_DB::table( 'transfers' ), Stockland_DB::table( 'locations' ), Stockland_DB::table( 'locations' ), Stockland_DB::table( 'products' ), $from, $to );
		if ( $scope ) {
			$variance_args[] = (int) $scope;
			$variance_args[] = (int) $scope;
		}
		$variance = $wpdb->get_results( $wpdb->prepare( $variance_sql, $variance_args ), ARRAY_A );

		$low_sql = "SELECT l.location_name,p.sku,p.product_name,COALESCE(b.physical_stock,0) current_stock,IF(l.location_type='Warehouse',p.central_reorder_level,p.branch_reorder_level) reorder_level
			FROM %i p CROSS JOIN %i l
			LEFT JOIN (SELECT location_id,variant_id,SUM(quantity_signed) physical_stock FROM %i WHERE variant_id IS NOT NULL GROUP BY location_id,variant_id) b ON b.variant_id=p.variant_id AND b.location_id=l.id
			WHERE p.is_active=1 AND l.location_type IN('Warehouse','Branch')" . $low_scope_sql . " AND COALESCE(b.physical_stock,0)<=IF(l.location_type='Warehouse',p.central_reorder_level,p.branch_reorder_level) ORDER BY current_stock,p.sku";
		$low_args = array( Stockland_DB::table( 'products' ), Stockland_DB::table( 'locations' ), Stockland_DB::table( 'transactions' ) );
		if ( $scope ) {
			$low_args[] = (int) $scope;
		}
		$low = $wpdb->get_results( $wpdb->prepare( $low_sql, $low_args ), ARRAY_A );

		return rest_ensure_response(
			array(
				'from'              => $from,
				'to'                => $to,
				'branches'          => $sales,
				'products'          => $by_product,
				'people'            => $by_person,
				'variances'         => $variance,
				'low_stock'         => $low,
				'scope_location_id' => $scope,
			)
		);
	}

	/**
	 * Checks whether the current user can reconcile stock at a location.
	 *
	 * @param mixed $requested Value for the requested argument.
	 * @return bool Whether the location is allowed for reconciliation.
	 */
	private function allowed_recon_location( $requested ) {
		return $this->resolve_branch_for_user( $requested );}
	/**
	 * Lists inventory reconciliations visible to the current user.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function reconciliation_list( WP_REST_Request $r ) {
		global $wpdb;
		$scope = $this->allowed_recon_location( $r->get_param( 'location_id' ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$sql  = 'SELECT a.*,l.location_name,p.product_name,u.display_name requested_by_name,au.display_name approved_by_name FROM ' . Stockland_DB::table( 'adjustments' ) . ' a JOIN ' . Stockland_DB::table( 'locations' ) . ' l ON l.id=a.location_id JOIN ' . Stockland_DB::table( 'products' ) . " p ON (p.variant_id=a.variant_id OR (a.variant_id IS NULL AND p.sku=a.sku)) LEFT JOIN {$wpdb->users} u ON u.ID=a.requested_by LEFT JOIN {$wpdb->users} au ON au.ID=a.approved_by WHERE 1=1";
		$args = array();
		if ( $scope ) {
			$sql   .= ' AND a.location_id=%d';
			$args[] = $scope;
		} $sql .= ' ORDER BY a.created_at DESC LIMIT 300';
		return rest_ensure_response( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) );
	}
	/**
	 * Creates a reconciliation draft within the user's authorized scope.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function create_reconciliation( WP_REST_Request $r ) {
		global $wpdb;
		$p   = $r->get_json_params();
		$loc = $this->allowed_recon_location( $p['location_id'] ?? null );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		} if ( ! $loc ) {
			return new WP_Error( 'location_required', 'Location is required.', array( 'status' => 400 ) );
		}
		$sku     = sanitize_text_field( $p['sku'] ?? '' );
		$counted = isset( $p['counted_qty'] ) ? (float) $p['counted_qty'] : -1;
		$reason  = sanitize_text_field( $p['reason'] ?? '' );
		if ( ! $sku || $counted < 0 || '' === $reason ) {
			return new WP_Error( 'invalid', 'SKU, counted quantity and reason are required.', array( 'status' => 400 ) );
		} if ( ! Stockland_DB::get_product( $sku ) ) {
			return new WP_Error( 'sku', 'SKU not found.', array( 'status' => 404 ) );
		}
		$system   = Stockland_DB::get_physical_stock( $loc, $sku );
		$variance = $counted - $system;
		if ( abs( $variance ) < 0.00001 ) {
			return new WP_Error( 'no_variance', 'Count matches system stock; no adjustment is required.', array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start stock-adjustment transaction.', array( 'status' => 500 ) );
		}
		$id = null;
		try {
			$id = Stockland_Sequence::next( 'ADJ-', current_time( 'Y-m-d' ) );
			if ( false === $wpdb->insert(
				Stockland_DB::table( 'adjustments' ),
				array(
					'adjustment_id' => $id,
					'location_id'   => $loc,
					'sku'           => $sku,
					'counted_qty'   => $counted,
					'system_qty'    => $system,
					'variance_qty'  => $variance,
					'reason'        => $reason,
					'status'        => 'Pending',
					'requested_by'  => get_current_user_id(),
				)
			) ) {
				throw new Exception( 'Could not save reconciliation.' );
			}
			if ( class_exists( 'Stockland_Workflow' ) && false === Stockland_Workflow::event(
				'adjustment',
				$id,
				null,
				'Pending',
				get_current_user_id(),
				$loc,
				array(
					'system_qty'   => $system,
					'counted_qty'  => $counted,
					'variance_qty' => $variance,
					'reason'       => $reason,
				)
			) ) {
				throw new Exception( 'Workflow event could not be recorded.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $loc, 'ADJUSTMENT_REQUEST', $sku, $variance, (string) $system, (string) $counted, $reason, $id ) ) {
				throw new Exception( 'Audit event could not be recorded; adjustment was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Reconciliation commit failed.' );
			}
			return rest_ensure_response( $wpdb->get_row( $wpdb->prepare('SELECT * FROM %i WHERE adjustment_id=%s', 'adjustments', $id), ARRAY_A ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}
	/**
	 * Applies an authorized decision to an inventory reconciliation.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function reconciliation_decision( WP_REST_Request $r ) {
		global $wpdb;
		$id       = (int) $r['id'];
		$p        = $r->get_json_params();
		$decision = sanitize_text_field( $p['decision'] ?? '' );
		$reason   = sanitize_text_field( $p['reason'] ?? '' );
		if ( ! in_array( $decision, array( 'Approved', 'Rejected' ), true ) ) {
			return new WP_Error( 'invalid_decision', 'Decision must be Approved or Rejected.', array( 'status' => 400 ) );
		}
		$peek = $wpdb->get_row( $wpdb->prepare('SELECT location_id FROM %i WHERE id=%d', 'adjustments', $id), ARRAY_A );
		if ( ! $peek ) {
			return new WP_Error( 'not_found', 'Adjustment request not found.', array( 'status' => 404 ) );
		}
		$lock = Stockland_DB::acquire_day_lock( (int) $peek['location_id'], current_time( 'Y-m-d' ) );
		if ( ! $lock['ok'] ) {
			return new WP_Error( 'busy', 'Another inventory operation is being processed for this location. Please retry.', array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start adjustment decision transaction.', array( 'status' => 500 ) );}
		try {
			$a = $wpdb->get_row( $wpdb->prepare('SELECT * FROM %i WHERE id=%d FOR UPDATE', 'adjustments', $id), ARRAY_A );
			if ( ! $a ) {
				throw new Exception( 'Adjustment request not found.' );
			}
			if ( ! empty( $a['count_batch_id'] ) ) {
				throw new Exception( 'This adjustment belongs to physical count ' . $a['count_batch_id'] . '. Approve or reject the whole count batch instead.' );
			}
			if ( 'Pending' !== $a['status'] ) {
				throw new Exception( 'Adjustment request is already ' . $a['status'] . '.' );
			}
			$current_system = Stockland_DB::get_physical_stock( (int) $a['location_id'], $a['sku'] );
			if ( abs( (float) $current_system - (float) $a['system_qty'] ) > 0.00001 ) {
				throw new Exception( 'Stock changed after this count was submitted. Recount is required before approval.' );
			}
			if ( 'Rejected' === $decision ) {
				if ( '' === $reason ) {
					throw new Exception( 'Rejection reason is required.' );
				}
				if ( false === $wpdb->update(
					Stockland_DB::table( 'adjustments' ),
					array(
						'status'           => 'Rejected',
						'approved_by'      => get_current_user_id(),
						'approved_at'      => current_time( 'mysql' ),
						'rejection_reason' => $reason,
					),
					array( 'id' => $id )
				) ) {
					throw new Exception( 'Could not reject adjustment.' );
				}
				Stockland_Workflow::event( 'adjustment', $a['adjustment_id'], 'Pending', 'Rejected', get_current_user_id(), $a['location_id'], array( 'reason' => $reason ) );
				if ( ! Stockland_Audit::log( get_current_user_id(), $a['location_id'], 'ADJUSTMENT_REJECT', $a['sku'], $a['variance_qty'], 'Pending', 'Rejected', $reason, $a['adjustment_id'] ) ) {
					throw new Exception( 'Audit event could not be recorded; adjustment was not committed.' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new Exception( 'Adjustment rejection commit failed.' );
				} Stockland_DB::release_day_lock( $lock );
				return rest_ensure_response( $a );
			}
			if ( false === Stockland_Ledger::record( (int) $a['location_id'], $a['sku'], 'ADJUSTMENT', (float) $a['variance_qty'], 'adjustment', (string) $a['id'], current_time( 'Y-m-d' ), get_current_user_id() ) ) {
				throw new Exception( 'Could not post adjustment to stock ledger.' );
			}
			if ( class_exists( 'Stockland_Accounting' ) ) {
				$prod  = Stockland_DB::get_product( $a['sku'] );
				$value = Stockland_Money::multiply( abs( (float) $a['variance_qty'] ), $prod['purchase_cost'] ?? 0 );
				$v     = (float) $a['variance_qty'];
				$lines = $v >= 0 ? array(
					array(
						'account_code' => '1200',
						'debit'        => $value,
						'credit'       => 0,
						'location_id'  => $a['location_id'],
					),
					array(
						'account_code' => '6100',
						'debit'        => 0,
						'credit'       => $value,
						'location_id'  => $a['location_id'],
					),
				) : array(
					array(
						'account_code' => '6100',
						'debit'        => $value,
						'credit'       => 0,
						'location_id'  => $a['location_id'],
					),
					array(
						'account_code' => '1200',
						'debit'        => 0,
						'credit'       => $value,
						'location_id'  => $a['location_id'],
					),
				);
				if ( ! Stockland_Accounting::post_journal( current_time( 'Y-m-d' ), 'STOCK_ADJUSTMENT', (string) $a['id'], 'Inventory adjustment ' . $a['adjustment_id'], $lines, get_current_user_id() ) ) {
					throw new Exception( 'Adjustment financial journal failed.' );
				}
			}
			if ( false === $wpdb->update(
				Stockland_DB::table( 'adjustments' ),
				array(
					'status'      => 'Approved',
					'approved_by' => get_current_user_id(),
					'approved_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id )
			) ) {
				throw new Exception( 'Could not approve adjustment.' );
			}
			Stockland_Workflow::event( 'adjustment', $a['adjustment_id'], 'Pending', 'Approved', get_current_user_id(), $a['location_id'], array( 'variance_qty' => $a['variance_qty'] ) );
			if ( ! Stockland_Audit::log( get_current_user_id(), $a['location_id'], 'ADJUSTMENT_APPROVE', $a['sku'], $a['variance_qty'], 'Pending', 'Approved', $a['reason'], $a['adjustment_id'] ) ) {
				throw new Exception( 'Audit event could not be recorded; adjustment was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Adjustment approval commit failed.' );
			} Stockland_DB::release_day_lock( $lock );
			return rest_ensure_response( $a );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'adjustment_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}
	/**
	 * Resolves the authorized location used for a stock count.
	 *
	 * @param mixed $requested Value for the requested argument.
	 * @return mixed The operation result.
	 */
	private function count_location( $requested ) {
		$loc = $this->resolve_branch_for_user( $requested );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		if ( $loc ) {
			return (int) $loc;
		}
		return 0;
	}

	/**
	 * Normalizes a product identifier used in stock-count matching.
	 *
	 * @param mixed $v Value for the v argument.
	 * @return string The normalized count key.
	 */
	private function normalize_count_key( $v ) {
		return strtolower( trim( preg_replace( '/\\s+/', ' ', sanitize_text_field( (string) $v ) ) ) );}

	/**
	 * Resolves a normalized count identifier to a master product.
	 *
	 * @param mixed $row Value for the row argument.
	 * @return mixed The operation result.
	 */
	private function resolve_count_product( $row ) {
		global $wpdb;
		$products = Stockland_DB::table( 'products' );
		$sku      = sanitize_text_field( $row['SKU'] ?? $row['SKU / Barcode'] ?? '' );
		$barcode  = sanitize_text_field( $row['Barcode'] ?? $row['BARCODE'] ?? '' );
		if ( $sku ) {
			$p = Stockland_DB::get_product( $sku );
			if ( $p ) {
				return $p;
			}
		}
		if ( $barcode ) {
			$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE is_active=1 AND (barcode=%s OR gtin=%s) LIMIT 2', $products, $barcode, $barcode ), ARRAY_A );
			if ( $p ) {
				return $p;
			}
		}
		$brand = $this->normalize_count_key( $row['Brand'] ?? '' );
		$name  = $this->normalize_count_key( $row['Product Name'] ?? $row['Product'] ?? '' );
		$size  = $this->normalize_count_key( $row['Size'] ?? '' );
		$color = $this->normalize_count_key( $row['Color'] ?? '' );
		if ( ! $brand || ! $name ) {
			return null;
		}
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE is_active=1 AND LOWER(TRIM(brand))=%s AND LOWER(TRIM(product_name))=%s', $products, $brand, $name ), ARRAY_A );
		$matches = array_values(
			array_filter(
				$rows,
				function ( $p ) use ( $size, $color ) {
					return $this->normalize_count_key( $p['size'] ?? '' ) === $size && $this->normalize_count_key( $p['color'] ?? '' ) === $color;
				}
			)
		);
		return count( $matches ) === 1 ? $matches[0] : null;
	}

	/**
	 * Lists stock counts available to the current user.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function counts_list( WP_REST_Request $r ) {
		global $wpdb;
		$scope = $this->resolve_branch_for_user( $r->get_param( 'location_id' ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$sql  = 'SELECT b.*,l.location_name,u.display_name uploaded_by_name,au.display_name approved_by_name,COUNT(cl.id) line_count,SUM(ABS(cl.variance_qty)) total_abs_variance FROM ' . Stockland_DB::table( 'count_batches' ) . ' b JOIN ' . Stockland_DB::table( 'locations' ) . " l ON l.id=b.location_id LEFT JOIN {$wpdb->users} u ON u.ID=b.uploaded_by LEFT JOIN {$wpdb->users} au ON au.ID=b.approved_by LEFT JOIN " . Stockland_DB::table( 'count_lines' ) . ' cl ON cl.batch_id=b.batch_id WHERE 1=1';
		$args = array();
		if ( $scope ) {
			$sql   .= ' AND b.location_id=%d';
			$args[] = $scope;}
		$sql .= ' GROUP BY b.id ORDER BY b.created_at DESC LIMIT 100';
		return rest_ensure_response( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Previews stock-count differences before posting adjustments.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function count_preview( WP_REST_Request $r ) {
		// REST auth/nonce validation and the permission callback run before this handler.
		// PHPCS cannot infer the registered REST request lifecycle from this callback.
		// Validate the upload structure, PHP upload error, normalized path and uploaded-file provenance.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The registered REST route validates nonce/auth and permission before this callback; each consumed field is normalized, and tmp_name is verified with is_uploaded_file().
		$upload     = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
		$tmp_name   = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? sanitize_text_field( wp_unslash( $upload['tmp_name'] ) ) : '';
		$file_name  = isset( $upload['name'] ) && is_string( $upload['name'] ) ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';
		$file_error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $file_error || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'file_required', 'Upload a valid Physical Count Excel file.', array( 'status' => 400 ) );
		}
		try {
			$x    = new Stockland_XLSX( $tmp_name );
			$rows = $x->rows( 'PHYSICAL COUNT', 1 );
			if ( empty( $rows ) ) {
				$rows = $x->rows( 'PHYSICAL COUNT', 3 );
			}
			if ( empty( $rows ) ) {
				$sheet_rows = $x->rows( 'Sheet1', 1 );
				if ( empty( $sheet_rows ) ) {
					$sheet_rows = $x->rows( 'Sheet1', 3 );
				}
				if ( ! empty( $sheet_rows ) ) {
					$rows = $sheet_rows;
				}
			}
			if ( empty( $rows ) ) {
				throw new Exception( 'Use a sheet named PHYSICAL COUNT (preferred) with headers on row 1 or row 3.' );
			}
			$errors    = array();
			$clean     = array();
			$locations = array();
			foreach ( $rows as $i => $row ) {
				$rn       = $i + 2;
				$date     = $this->parse_import_date( $row['Count Date'] ?? $row['Date'] ?? '' );
				$location = sanitize_text_field( $row['Location'] ?? '' );
				$qty      = isset( $row['Quantity Found'] ) ? (float) $row['Quantity Found'] : ( isset( $row['Counted Qty'] ) ? (float) $row['Counted Qty'] : -1 );
				if ( ! $date || $qty < 0 || '' === $location ) {
					$errors[] = 'Row ' . $rn . ': Count Date, Location and non-negative Quantity Found are required.';
					continue; }
				$loc_id = Stockland_DB::get_location_id( $location );
				if ( ! $loc_id ) {
					$errors[] = 'Row ' . $rn . ': unknown Location ' . $location . '.';
					continue;}
				$allowed = $this->resolve_branch_for_user( $loc_id );
				if ( is_wp_error( $allowed ) ) {
					$errors[] = 'Row ' . $rn . ': ' . $allowed->get_error_message();
					continue;
				} if ( $allowed && (int) $allowed !== (int) $loc_id ) {
					$errors[] = 'Row ' . $rn . ': you are not authorized to count ' . $location . '.';
					continue;}
				$product = $this->resolve_count_product( $row );
				if ( ! $product ) {
					$errors[] = 'Row ' . $rn . ': could not uniquely resolve Brand + Product Name + Size + Color (or SKU/Barcode).';
					continue;}
				$locations[ $loc_id ] = true;
				$clean[]              = array(
					'row'           => $rn,
					'count_date'    => $date,
					'location_id'   => (int) $loc_id,
					'location_name' => $location,
					'sku'           => $product['sku'],
					'variant_id'    => (int) $product['variant_id'],
					'brand'         => $product['brand'],
					'product_name'  => $product['product_name'],
					'size'          => $product['size'],
					'color'         => $product['color'],
					'barcode'       => $product['barcode'] ? $product['barcode'] : $product['gtin'],
					'counted_qty'   => $qty,
					'notes'         => sanitize_text_field( $row['Notes'] ?? '' ),
				);
			}
			if ( count( $locations ) > 1 ) {
				$errors[] = 'One upload must contain only one location.';
			}
			if ( count( $clean ) > 10000 ) {
				$errors[] = 'Physical count upload is limited to 10,000 lines.';
			}
			$summary = array(
				'rows'        => count( $rows ),
				'valid_lines' => count( $clean ),
				'errors'      => count( $errors ),
				'location'    => ! empty( $clean ) ? $clean[0]['location_name'] : '',
				'count_date'  => ! empty( $clean ) ? $clean[0]['count_date'] : '',
			);
			$key     = 'stockland_count_' . get_current_user_id();
			set_transient(
				$key,
				array(
					'rows'        => $clean,
					'errors'      => array_values( array_unique( $errors ) ),
					'source_file' => $file_name,
					'created'     => time(),
				),
				30 * MINUTE_IN_SECONDS
			);
			return rest_ensure_response(
				array(
					'summary'    => $summary,
					'errors'     => array_values( array_unique( $errors ) ),
					'ready'      => empty( $errors ),
					'expires_in' => 1800,
					'preview'    => $clean,
				)
			);
		} catch ( Throwable $e ) {
			return new WP_Error( 'count_parse', $e->getMessage(), array( 'status' => 400 ) );}
	}

	/**
	 * Commits validated stock-count changes through the ERP transaction path.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function count_commit( WP_REST_Request $r ) {
		global $wpdb;
		$key  = 'stockland_count_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['rows'] ) ) {
			return new WP_Error( 'preview_required', 'Preview a physical count workbook before confirming it.', array( 'status' => 409 ) );
		}
		if ( ! empty( $data['errors'] ) ) {
			return new WP_Error( 'preview_invalid', 'The physical count has blocking validation errors.', array( 'status' => 409 ) );
		}
		$confirm = ! empty( $r->get_json_params()['confirm'] );
		if ( ! $confirm ) {
			return new WP_Error( 'confirmation_required', 'Explicit confirmation is required.', array( 'status' => 400 ) );
		}
		$rows = $data['rows'];
		$loc  = (int) $rows[0]['location_id'];
		$date = $rows[0]['count_date'];
		$lock = Stockland_DB::acquire_day_lock( $loc, $date );
		if ( ! $lock['ok'] ) {
			return new WP_Error( 'busy', 'Another operation is being processed for this location/date. Please retry.', array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start physical-count transaction.', array( 'status' => 500 ) );}
		try {
			$exists = $wpdb->get_var( $wpdb->prepare('SELECT batch_id FROM %i WHERE location_id=%d AND count_date=%s AND status IN(\'Pending\',\'Approved\') LIMIT 1', 'count_batches',  $loc,  $date ) );
			if ( $exists ) {
				throw new Exception( 'A physical count is already open for this location and date: ' . $exists . '. Complete or resolve it before uploading another count.' );
			}
			$batch = Stockland_Sequence::next( 'CNT-', $date );
			if ( false === $wpdb->insert(
				Stockland_DB::table( 'count_batches' ),
				array(
					'batch_id'    => $batch,
					'location_id' => $loc,
					'count_date'  => $date,
					'cutoff_at'   => current_time( 'mysql' ),
					'status'      => 'Pending',
					'uploaded_by' => get_current_user_id(),
					'source_file' => $data['source_file'],
				)
			) ) {
				throw new Exception( 'Could not create physical count batch.' );
			}
			if ( ! $wpdb->insert_id ) {
				throw new Exception( 'Could not create physical count batch.' );
			}
			$line_count     = 0;
			$variance_lines = 0;
			foreach ( $rows as $row ) {
				$system   = Stockland_DB::get_physical_stock( $loc, $row['sku'] );
				$variance = (float) $row['counted_qty'] - (float) $system;
				$adj_id   = null;
				if ( abs( $variance ) > 0.00001 ) {
					$reason        = 'Physical count ' . $batch . ( $row['notes'] ? ' — ' . $row['notes'] : '' );
					$adjustment_id = Stockland_Sequence::next( 'ADJ-', $date );
					$wpdb->insert(
						Stockland_DB::table( 'adjustments' ),
						array(
							'adjustment_id'  => $adjustment_id,
							'location_id'    => $loc,
							'sku'            => $row['sku'],
							'variant_id'     => (int) ( Stockland_DB::get_product( $row['sku'] )['variant_id'] ?? 0 ),
							'size'           => $row['size'],
							'color'          => $row['color'],
							'barcode'        => $row['barcode'],
							'counted_qty'    => $row['counted_qty'],
							'system_qty'     => $system,
							'variance_qty'   => $variance,
							'reason'         => $reason,
							'status'         => 'Pending',
							'requested_by'   => get_current_user_id(),
							'count_batch_id' => $batch,
						)
					);
					if ( ! $wpdb->insert_id ) {
						throw new Exception( 'Could not create count adjustment for ' . $row['sku'] . '.' );
					}
					$adj_id = (int) $wpdb->insert_id;
					++$variance_lines;
					if ( false === Stockland_Workflow::event(
						'adjustment',
						$adjustment_id,
						null,
						'Pending',
						get_current_user_id(),
						$loc,
						array(
							'batch_id'     => $batch,
							'system_qty'   => $system,
							'counted_qty'  => $row['counted_qty'],
							'variance_qty' => $variance,
						)
					) ) {
						throw new Exception( 'Could not record the physical-count workflow event for ' . $row['sku'] . '.' );
					}
				}
				if ( false === $wpdb->insert(
					Stockland_DB::table( 'count_lines' ),
					array(
						'batch_id'      => $batch,
						'location_id'   => $loc,
						'sku'           => $row['sku'],
						'variant_id'    => (int) $row['variant_id'],
						'brand'         => $row['brand'],
						'product_name'  => $row['product_name'],
						'size'          => $row['size'],
						'color'         => $row['color'],
						'barcode'       => $row['barcode'],
						'counted_qty'   => $row['counted_qty'],
						'system_qty'    => $system,
						'variance_qty'  => $variance,
						'adjustment_id' => $adj_id,
					)
				) ) {
					throw new Exception( 'Could not create the physical-count line for ' . $row['sku'] . '.' );
				}
				++$line_count;
				if ( ! Stockland_Audit::log( get_current_user_id(), $loc, 'PHYSICAL_COUNT_LINE', $row['sku'], $variance, (string) $system, (string) $row['counted_qty'], 'Physical count ' . $batch, $batch ) ) {
					throw new Exception( 'Audit event could not be recorded for ' . $row['sku'] . '; physical count was not committed.' );
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $loc, 'PHYSICAL_COUNT_CREATE', null, null, null, $batch, 'Lines ' . $line_count . '; variance lines ' . $variance_lines, $batch ) ) {
				throw new Exception( 'Audit event could not be recorded; physical count was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Physical count commit failed.' );
			}
			delete_transient( $key );
			return rest_ensure_response(
				array(
					'batch_id'       => $batch,
					'status'         => 'Pending',
					'lines'          => $line_count,
					'variance_lines' => $variance_lines,
					'message'        => 'Physical count imported. Stock is unchanged until Management approves the count adjustments.',
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'count_commit_failed', $e->getMessage(), array( 'status' => 409 ) );
		} finally {
			Stockland_DB::release_day_lock( $lock );}
	}

	/**
	 * Records the authorized decision for a stock-count workflow.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function count_decision( WP_REST_Request $r ) {
		global $wpdb;
		$id       = (int) $r['id'];
		$decision = sanitize_text_field( $r->get_json_params()['decision'] ?? '' );
		$reason   = sanitize_text_field( $r->get_json_params()['reason'] ?? '' );
		if ( ! in_array( $decision, array( 'Approved', 'Rejected' ), true ) ) {
			return new WP_Error( 'invalid_decision', 'Decision must be Approved or Rejected.', array( 'status' => 400 ) );
		}
		$b = $wpdb->get_row( $wpdb->prepare('SELECT * FROM %i WHERE id=%d FOR UPDATE', 'count_batches', $id), ARRAY_A );
		if ( ! $b ) {
			return new WP_Error( 'not_found', 'Physical count batch not found.', array( 'status' => 404 ) );
		}
		if ( 'Pending' !== $b['status'] ) {
			return new WP_Error( 'already_decided', 'Physical count is already ' . $b['status'] . '.', array( 'status' => 409 ) );
		}
		if ( 'Rejected' === $decision && '' === $reason ) {
			return new WP_Error( 'reason_required', 'Rejection reason is required.', array( 'status' => 400 ) );
		}
		$lock = Stockland_DB::acquire_day_lock( (int) $b['location_id'], $b['count_date'] );
		if ( ! $lock['ok'] ) {
			return new WP_Error( 'busy', 'Another operation is being processed for this location/date. Please retry.', array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start physical-count decision transaction.', array( 'status' => 500 ) );}
		try {
			$batch = $wpdb->get_row( $wpdb->prepare('SELECT * FROM %i WHERE id=%d FOR UPDATE', 'count_batches', $id), ARRAY_A );
			if ( ! $batch || 'Pending' !== $batch['status'] ) {
				throw new Exception( 'Physical count is no longer pending.' );
			}
			if ( 'Rejected' === $decision ) {
				if ( false === $wpdb->query( $wpdb->prepare('UPDATE %i SET status=\'Rejected\',approved_by=%d,approved_at=%s,rejection_reason=%s WHERE count_batch_id=%s AND status=\'Pending\'', 'adjustments',  get_current_user_id(),  current_time( 'mysql' ),  $reason,  $batch['batch_id'] ) ) ) {
					throw new Exception( 'Could not reject pending physical-count adjustments.' );
				}
				if ( false === $wpdb->update(
					Stockland_DB::table( 'count_batches' ),
					array(
						'status'      => 'Rejected',
						'approved_by' => get_current_user_id(),
						'approved_at' => current_time( 'mysql' ),
						'notes'       => $reason,
					),
					array( 'id' => $id )
				) ) {
					throw new Exception( 'Could not reject physical count batch.' );
				}
				if ( ! Stockland_Audit::log( get_current_user_id(), $batch['location_id'], 'PHYSICAL_COUNT_REJECT', null, null, 'Pending', 'Rejected', $reason, $batch['batch_id'] ) ) {
					throw new Exception( 'Audit event could not be recorded; physical count was not committed.' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new Exception( 'Physical count rejection commit failed.' );
				}return rest_ensure_response(
					array(
						'batch_id' => $batch['batch_id'],
						'status'   => 'Rejected',
					)
				);
			}
			$lines = $wpdb->get_results( $wpdb->prepare('SELECT * FROM %i WHERE batch_id=%s ORDER BY id FOR UPDATE', 'count_lines',  $batch['batch_id'] ), ARRAY_A );
			foreach ( $lines as $line ) {
				$current = Stockland_DB::get_physical_stock( (int) $batch['location_id'], $line['sku'] );
				if ( abs( $current - (float) $line['system_qty'] ) > 0.00001 ) {
					throw new Exception( 'Stock changed after the physical count for ' . $line['sku'] . '. Recount is required before this batch can be approved.' );
				}
			}
			$adjustments = $wpdb->get_results( $wpdb->prepare('SELECT * FROM %i WHERE count_batch_id=%s AND status=\'Pending\' FOR UPDATE', 'adjustments',  $batch['batch_id'] ), ARRAY_A );
			foreach ( $adjustments as $a ) {
				if ( false === Stockland_Ledger::record( (int) $a['location_id'], $a['sku'], 'PHYSICAL_COUNT_ADJUSTMENT', (float) $a['variance_qty'], 'adjustment', (string) $a['id'], $batch['count_date'], get_current_user_id() ) ) {
					throw new Exception( 'Could not post the physical-count adjustment for ' . $a['sku'] . '.' );
				}
				if ( class_exists( 'Stockland_Accounting' ) ) {
					$prod  = Stockland_DB::get_product( $a['sku'] );
					$value = Stockland_Money::multiply( abs( (float) $a['variance_qty'] ), $prod['purchase_cost'] ?? 0 );
					$v     = (float) $a['variance_qty'];
					$lines = $v >= 0 ? array(
						array(
							'account_code' => '1200',
							'debit'        => $value,
							'credit'       => 0,
							'location_id'  => $a['location_id'],
						),
						array(
							'account_code' => '6100',
							'debit'        => 0,
							'credit'       => $value,
							'location_id'  => $a['location_id'],
						),
					) : array(
						array(
							'account_code' => '6100',
							'debit'        => $value,
							'credit'       => 0,
							'location_id'  => $a['location_id'],
						),
						array(
							'account_code' => '1200',
							'debit'        => 0,
							'credit'       => $value,
							'location_id'  => $a['location_id'],
						),
					);
					if ( ! Stockland_Accounting::post_journal( $batch['count_date'], 'PHYSICAL_COUNT_ADJUSTMENT', (string) $a['id'], 'Physical count adjustment ' . $a['adjustment_id'], $lines, get_current_user_id() ) ) {
						throw new Exception( 'Physical count financial journal failed.' );
					}
				}
				if ( false === $wpdb->update(
					Stockland_DB::table( 'adjustments' ),
					array(
						'status'      => 'Approved',
						'approved_by' => get_current_user_id(),
						'approved_at' => current_time( 'mysql' ),
					),
					array( 'id' => $a['id'] )
				) ) {
					throw new Exception( 'Could not approve adjustment ' . $a['adjustment_id'] . '.' );
				}
				if ( false === Stockland_Workflow::event(
					'adjustment',
					$a['adjustment_id'],
					'Pending',
					'Approved',
					get_current_user_id(),
					$a['location_id'],
					array(
						'count_batch_id' => $batch['batch_id'],
						'variance_qty'   => $a['variance_qty'],
					)
				) ) {
					throw new Exception( 'Could not record workflow event for ' . $a['adjustment_id'] . '.' );
				}
			}
			if ( false === $wpdb->update(
				Stockland_DB::table( 'count_batches' ),
				array(
					'status'      => 'Approved',
					'approved_by' => get_current_user_id(),
					'approved_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id )
			) ) {
				throw new Exception( 'Could not approve physical count batch.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $batch['location_id'], 'PHYSICAL_COUNT_APPROVE', null, null, 'Pending', 'Approved', 'Batch approved; ' . $batch['batch_id'], $batch['batch_id'] ) ) {
				throw new Exception( 'Audit event could not be recorded; physical count was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Physical count approval commit failed.' );
			}
			return rest_ensure_response(
				array(
					'batch_id'           => $batch['batch_id'],
					'status'             => 'Approved',
					'adjustments_posted' => count( $adjustments ),
					'message'            => 'Physical count approved and the final counted quantities are now represented by the stock ledger.',
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'count_decision_failed', $e->getMessage(), array( 'status' => 409 ) );
		} finally {
			Stockland_DB::release_day_lock( $lock );}
	}

	/**
	 * Builds the final report for a stock-count session.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function count_report( WP_REST_Request $r ) {
		global $wpdb;
		$id = sanitize_text_field( $r['id'] );
		$b  = $wpdb->get_row( $wpdb->prepare( 'SELECT b.*,l.location_name,u.display_name uploaded_by_name,au.display_name approved_by_name FROM ' . Stockland_DB::table( 'count_batches' ) . ' b JOIN ' . Stockland_DB::table( 'locations' ) . " l ON l.id=b.location_id LEFT JOIN {$wpdb->users} u ON u.ID=b.uploaded_by LEFT JOIN {$wpdb->users} au ON au.ID=b.approved_by WHERE b.id=%d", (int) $id ), ARRAY_A );
		if ( ! $b ) {
			return new WP_Error( 'not_found', 'Physical count batch not found.', array( 'status' => 404 ) );
		}
		$allowed = $this->resolve_branch_for_user( $b['location_id'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}if ( $allowed && (int) $allowed !== (int) $b['location_id'] ) {
			return new WP_Error( 'forbidden', 'You are not authorized to view this count.', array( 'status' => 403 ) );
		}
		$lines   = $wpdb->get_results( $wpdb->prepare('SELECT cl.*,p.retail_price,p.wholesale_price FROM %i cl LEFT JOIN %i p ON (p.variant_id=cl.variant_id OR (cl.variant_id IS NULL AND p.sku=cl.sku)) WHERE cl.batch_id=%s ORDER BY cl.id', 'count_lines',  $b['batch_id'] , 'products'), ARRAY_A );
		$summary = array(
			'lines'          => count( $lines ),
			'system_qty'     => 0,
			'counted_qty'    => 0,
			'variance_qty'   => 0,
			'variance_lines' => 0,
		);
		foreach ( $lines as $l ) {
			$summary['system_qty']   += (float) $l['system_qty'];
			$summary['counted_qty']  += (float) $l['counted_qty'];
			$summary['variance_qty'] += (float) $l['variance_qty'];
			if ( abs( (float) $l['variance_qty'] ) > 0.00001 ) {
				++$summary['variance_lines'];
			}
		}
		return rest_ensure_response(
			array(
				'batch'   => $b,
				'summary' => $summary,
				'lines'   => $lines,
			)
		);
	}

	/**
	 * Returns notifications visible to the current ERP user.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function notifications( WP_REST_Request $r ) {
		global $wpdb;
		$scope = $this->scope_locations();
		if ( false === $scope ) {
			return new WP_Error( 'no_profile', 'This account has no Stockland role or authorized location scope.', array( 'status' => 403 ) );
		}
		$sql  = 'SELECT e.id,e.entity_type,e.entity_id,e.from_status,e.to_status,e.user_id,e.location_id,e.meta,e.created_at,u.display_name,l.location_name FROM ' . Stockland_DB::table( 'workflow_events' ) . " e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id LEFT JOIN " . Stockland_DB::table( 'locations' ) . ' l ON l.id=e.location_id WHERE 1=1';
		$args = array();
		if ( is_array( $scope ) ) {
			$ph   = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
			$sql .= " AND e.location_id IN({$ph})";
			$args = $scope;}
		$sql .= ' ORDER BY e.id DESC LIMIT 50';
		return rest_ensure_response( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Returns permitted workflow transition history for ERP records.
	 *
	 * @param WP_REST_Request $r REST request that supplies the operation parameters.
	 * @return mixed The operation result.
	 */
	public function workflow_history( WP_REST_Request $r ) {
		global $wpdb;
		$type  = sanitize_key( $r['type'] );
		$id    = sanitize_text_field( $r['id'] );
		$scope = $this->scope_location();
		if ( false === $scope ) {
			return new WP_Error( 'no_profile', 'This account has no Stockland role or branch assignment.', array( 'status' => 403 ) );
		} $args = array( $type, $id );
		$sql    = 'SELECT e.*,u.display_name,l.location_name FROM ' . Stockland_DB::table( 'workflow_events' ) . " e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id LEFT JOIN " . Stockland_DB::table( 'locations' ) . ' l ON l.id=e.location_id WHERE e.entity_type=%s AND e.entity_id=%s';
		if ( $scope ) {
			$sql   .= ' AND e.location_id=%d';
			$args[] = $scope;}
		$sql .= ' ORDER BY e.id DESC LIMIT 500';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return rest_ensure_response( $rows );
	}
}
