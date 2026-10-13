<?php
/**
 * Stockland ERP role registration and profile management.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares canonical role slugs and provisions their capabilities.
 *
 * @package Stockland_ERP
 */
class Stockland_Roles {
	const ROLE_OWNER              = 'stockland_owner';
	const ROLE_MANAGEMENT         = 'stockland_management';
	const ROLE_BRANCH_MANAGER     = 'stockland_branch_manager';
	const ROLE_SALES_EMPLOYEE     = 'stockland_sales_employee';
	const ROLE_WAREHOUSE_EMPLOYEE = 'stockland_warehouse_employee';
	const ROLE_WAREHOUSE_MANAGER  = 'stockland_warehouse_manager';
	const ROLE_AUDITOR            = 'stockland_auditor';

	/** Return the canonical capabilities that form the base ERP role namespace. */
	private static function base_caps() {
		return array(
			'stockland_view_audit_log',
			'stockland_view_branch_reports',
			'stockland_view_all_branches',
			'stockland_view_warehouse',
			'stockland_view_warehouse_availability',
			'stockland_request_stock',
			'stockland_review_requisitions',
			'stockland_request_adjustment',
			'stockland_manage_products',
			'stockland_manage_settings',
			'stockland_view_cost_and_profit',
			'stockland_close_day',
			'stockland_create_sale',
			'stockland_record_return',
			'stockland_import_excel',
			'stockland_create_wholesale',
			'stockland_view_financials',
			'stockland_manage_suppliers',
			'stockland_manage_customers',
			'stockland_manage_purchase_orders',
			'stockland_approve_purchase_orders',
			'stockland_manage_payables',
			'stockland_manage_receivables',
			'stockland_manage_expenses',
			'stockland_manage_payments',
			'stockland_manage_gl',
			'stockland_product_import',
			'stockland_manage_locations',
			'stockland_manage_users',
			'stockland_approve_management',
			'stockland_approve_owner',
			'stockland_manage_notifications',
			'stockland_view_hr',
			'stockland_manage_hr',
			'stockland_view_payroll',
			'stockland_manage_payroll',
		);
	}

	/** Canonical roles accepted by every Stockland user-management write path. */
	public static function supported_roles() {
		return array(
			self::ROLE_OWNER,
			self::ROLE_MANAGEMENT,
			self::ROLE_BRANCH_MANAGER,
			self::ROLE_SALES_EMPLOYEE,
			self::ROLE_WAREHOUSE_EMPLOYEE,
			self::ROLE_WAREHOUSE_MANAGER,
			self::ROLE_AUDITOR,
		);
	}

	/** Register the supported Stockland ERP roles and their initial capabilities. */
	public static function register_roles() {
		add_role(
			self::ROLE_OWNER,
			'Stockland Owner',
			array_merge(
				array( 'read' => true ),
				array_fill_keys( self::base_caps(), true ),
				array(
					'stockland_owner'          => true,
					'stockland_install_schema' => true,
				)
			)
		);
		add_role(
			self::ROLE_MANAGEMENT,
			'Stockland Management',
			array_merge(
				array( 'read' => true ),
				array_fill_keys(
					array(
						'stockland_view_all_branches',
						'stockland_manage_products',
						'stockland_manage_settings',
						'stockland_view_cost_and_profit',
						'stockland_approve_transfers',
						'stockland_approve_requisitions',
						'stockland_close_day',
						'stockland_record_return',
						'stockland_import_excel',
						'stockland_view_audit_log',
						'stockland_view_warehouse',
						'stockland_view_warehouse_availability',
						'stockland_review_requisitions',
						'stockland_request_stock',
						'stockland_view_branch_reports',
						'stockland_view_warehouse_availability',
						'stockland_view_financials',
						'stockland_manage_suppliers',
						'stockland_manage_customers',
						'stockland_manage_purchase_orders',
						'stockland_create_sale',
						'stockland_manage_payables',
						'stockland_manage_receivables',
						'stockland_manage_expenses',
						'stockland_manage_payments',
						'stockland_manage_gl',
						'stockland_product_import',
						'stockland_approve_management',
						'stockland_manage_notifications',
						'stockland_view_hr',
						'stockland_manage_hr',
						'stockland_view_payroll',
						'stockland_manage_payroll',
					),
					true
				)
			)
		);
		add_role(
			self::ROLE_BRANCH_MANAGER,
			'Stockland Branch Manager',
			array_fill_keys(
				array(
					'read',
					'stockland_view_own_branch',
					'stockland_close_day',
					'stockland_request_stock',
					'stockland_view_branch_reports',
					'stockland_view_warehouse_availability',
					'stockland_request_adjustment',
					'stockland_record_return',
					'stockland_create_sale',
					'stockland_receive_transfer',
					'stockland_review_requisitions',
					'stockland_manage_customers',
					'stockland_manage_expenses',
				),
				true
			)
		);
		add_role( self::ROLE_SALES_EMPLOYEE, 'Stockland Sales Employee', array_fill_keys( array( 'read', 'stockland_view_own_branch', 'stockland_record_return', 'stockland_request_adjustment', 'stockland_request_stock', 'stockland_view_warehouse_availability', 'stockland_daily_import' ), true ) );
		add_role( self::ROLE_WAREHOUSE_EMPLOYEE, 'Stockland Warehouse Employee', array_fill_keys( array( 'read', 'stockland_view_warehouse', 'stockland_view_warehouse_availability', 'stockland_manage_reservations', 'stockland_manage_transfers', 'stockland_receive_transfer' ), true ) );
		add_role(
			self::ROLE_WAREHOUSE_MANAGER,
			'Stockland Warehouse Manager',
			array_fill_keys(
				array(
					'read',
					'stockland_view_warehouse',
					'stockland_view_warehouse_availability',
					'stockland_receive_warehouse',
					'stockland_manage_reservations',
					'stockland_manage_transfers',
					'stockland_review_requisitions',
					'stockland_approve_transfers',
					'stockland_request_adjustment',
				),
				true
			)
		);
		add_role( self::ROLE_AUDITOR, 'Stockland Auditor', array_fill_keys( array( 'read', 'stockland_view_all_branches', 'stockland_view_cost_and_profit', 'stockland_view_audit_log', 'stockland_view_financials' ), true ) );
	}

	/** Canonical Stockland capabilities by role. Existing role capabilities are normalized to this map on every boot/setup. */
	private static function role_caps_map() {
		$base = self::base_caps();
		return array(
			self::ROLE_OWNER              => array_merge(
				array( 'read' => true ),
				array_fill_keys( $base, true ),
				array(
					'stockland_owner'          => true,
					'stockland_install_schema' => true,
				)
			),
			self::ROLE_MANAGEMENT         => array_fill_keys(
				array(
					'read',
					'stockland_view_all_branches',
					'stockland_manage_products',
					'stockland_manage_settings',
					'stockland_view_cost_and_profit',
					'stockland_approve_transfers',
					'stockland_approve_requisitions',
					'stockland_close_day',
					'stockland_record_return',
					'stockland_import_excel',
					'stockland_view_audit_log',
					'stockland_view_warehouse',
					'stockland_view_warehouse_availability',
					'stockland_review_requisitions',
					'stockland_request_stock',
					'stockland_view_branch_reports',
					'stockland_view_financials',
					'stockland_manage_suppliers',
					'stockland_manage_customers',
					'stockland_manage_purchase_orders',
					'stockland_manage_payables',
					'stockland_manage_receivables',
					'stockland_manage_expenses',
					'stockland_manage_payments',
					'stockland_manage_gl',
					'stockland_product_import',
					'stockland_approve_management',
					'stockland_manage_notifications',
					'stockland_view_hr',
					'stockland_manage_hr',
					'stockland_view_payroll',
					'stockland_manage_payroll',
				),
				true
			),
			self::ROLE_BRANCH_MANAGER     => array_fill_keys(
				array(
					'read',
					'stockland_view_own_branch',
					'stockland_close_day',
					'stockland_request_stock',
					'stockland_view_branch_reports',
					'stockland_view_warehouse_availability',
					'stockland_request_adjustment',
					'stockland_record_return',
					'stockland_create_sale',
					'stockland_receive_transfer',
					'stockland_daily_import',
					'stockland_review_requisitions',
					'stockland_manage_customers',
					'stockland_manage_expenses',
				),
				true
			),
			self::ROLE_SALES_EMPLOYEE     => array_fill_keys(
				array(
					'read',
					'stockland_view_own_branch',
					'stockland_record_return',
					'stockland_request_adjustment',
					'stockland_request_stock',
					'stockland_view_warehouse_availability',
					'stockland_daily_import',
					'stockland_create_sale',
				),
				true
			),
			self::ROLE_WAREHOUSE_EMPLOYEE => array_fill_keys(
				array(
					'read',
					'stockland_view_warehouse',
					'stockland_view_warehouse_availability',
					'stockland_manage_reservations',
					'stockland_manage_transfers',
				),
				true
			),
			self::ROLE_WAREHOUSE_MANAGER  => array_fill_keys(
				array(
					'read',
					'stockland_view_warehouse',
					'stockland_view_warehouse_availability',
					'stockland_receive_warehouse',
					'stockland_manage_reservations',
					'stockland_manage_transfers',
					'stockland_review_requisitions',
					'stockland_approve_transfers',
					'stockland_request_adjustment',
				),
				true
			),
			self::ROLE_AUDITOR            => array_fill_keys(
				array(
					'read',
					'stockland_view_all_branches',
					'stockland_view_cost_and_profit',
					'stockland_view_audit_log',
					'stockland_view_financials',
				),
				true
			),
		);
	}

	/** Synchronize existing role capabilities with the canonical role map. */
	public static function sync_roles() {
		$map = self::role_caps_map();
		// Build the complete ERP capability namespace from the role map itself.
		// This prevents capabilities removed from a role in a later release from
		// lingering on the WordPress role object after an upgrade.
		$map_caps = array();
		foreach ( $map as $caps ) {
			foreach ( (array) $caps as $cap => $grant ) {
				$map_caps[] = $cap; }
		}
		$stockland_caps = array_values(
			array_unique(
				array_merge(
					self::base_caps(),
					$map_caps,
					array( 'stockland_owner', 'stockland_install_schema', 'stockland_view_own_branch', 'stockland_receive_transfer' )
				)
			)
		);
		foreach ( $map as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $stockland_caps as $cap ) {
				$role->remove_cap( $cap );}
			foreach ( $caps as $cap => $grant ) {
				if ( $grant ) {
					$role->add_cap( $cap );
				}
			}
		}
		// Administrators retain WordPress administrator privileges; only the Stockland
		// capability namespace is normalized so stale ERP caps cannot survive upgrades.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $stockland_caps as $cap ) {
				$admin->remove_cap( $cap );}
			foreach ( self::base_caps() as $cap ) {
				$admin->add_cap( $cap );}
			$admin->add_cap( 'stockland_owner' );
			$admin->add_cap( 'stockland_install_schema' );
		}

		// An active ERP Owner may use WordPress Administrator rather than the
		// dedicated Stockland role. Grant the PO decision capability per account,
		// not to the shared Administrator role; clear stale direct grants otherwise.
		global $wpdb;
		$users_table = Stockland_DB::table( 'users' );
		$profiles    = Stockland_DB::read_results( $wpdb->prepare( 'SELECT wp_user_id, role, is_active FROM %i', $users_table ), ARRAY_A );
		foreach ( (array) $profiles as $profile ) {
			$wp_user_id = absint( $profile['wp_user_id'] ?? 0 );
			if ( ! $wp_user_id ) {
				continue;
			}
			$wp_user = new WP_User( $wp_user_id );
			if ( 1 === (int) ( $profile['is_active'] ?? 0 ) && self::ROLE_OWNER === (string) ( $profile['role'] ?? '' ) ) {
				$wp_user->add_cap( 'stockland_approve_purchase_orders' );
			} else {
				$wp_user->remove_cap( 'stockland_approve_purchase_orders' );
			}
		}
	}

	/**
	 * Retrieve the active ERP profile for a WordPress user.
	 *
	 * @param int $wp_user_id WordPress user identifier.
	 * @return array|null Active ERP user profile, or null when not assigned.
	 */
	public static function get_profile( $wp_user_id ) {
		global $wpdb;
		$table = Stockland_DB::table( 'users' );
		$row   = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE wp_user_id=%d AND is_active=1', $table, (int) $wp_user_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Returns active operational locations assigned to a WordPress user.
	 *
	 * Company-wide roles are handled by Authority::company_scope() and do not
	 * require a separate assignment row for each branch.
	 *
	 * @param int $wp_user_id WordPress user identifier.
	 * @return array<int> Assigned active location IDs.
	 */
	public static function get_location_scope( $wp_user_id ) {
		global $wpdb;
		$ids    = array();
		$table  = $wpdb->prefix . 'stock_user_locations';
		$exists = Stockland_DB::read_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists === $table ) {
			$rows = Stockland_DB::read_col( $wpdb->prepare( 'SELECT location_id FROM %i WHERE wp_user_id=%d AND is_active=1 AND (effective_from IS NULL OR effective_from<=CURDATE()) AND (effective_to IS NULL OR effective_to>=CURDATE()) ORDER BY is_primary DESC,id', $table, (int) $wp_user_id ) );
			foreach ( $rows as $id ) {
				$ids[] = (int) $id;
			}
		}
		if ( ! $ids ) {
			$p = self::get_profile( $wp_user_id );
			if ( $p && ! empty( $p['branch_location_id'] ) ) {
				$ids[] = (int) $p['branch_location_id'];
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Check whether a user may view a branch's operational data.
	 *
	 * @param int $wp_user_id WordPress user identifier.
	 * @param int $branch_location_id Branch location identifier.
	 * @return bool True when the role or assigned location scope permits access.
	 */
	public static function can_view_branch( $wp_user_id, $branch_location_id ) {
		$profile = self::get_profile( $wp_user_id );
		if ( ! $profile ) {
			return false;
		}
		if ( in_array( $profile['role'], array( 'owner', 'management', 'auditor' ), true ) || user_can( $wp_user_id, 'stockland_view_all_branches' ) ) {
			return true;
		}
		$ids = self::get_location_scope( $wp_user_id );
		return in_array( (int) $branch_location_id, $ids, true );
	}
}
