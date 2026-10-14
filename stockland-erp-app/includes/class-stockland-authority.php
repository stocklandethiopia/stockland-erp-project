<?php
/**
 * Authorization and location-scope services for Stockland ERP.
 *
 * Centralizes role capabilities, location access, and workflow authorization.
 *
 * @package StocklandERP
 */

/**
 * Stockland ERP authorization and location-scope rules.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central authorization + branch scope layer for Stockland ERP 16.1.44.
 *
 * Capabilities answer WHAT a user may do. The Stockland profile answers WHERE
 * the user may do it. Workflow methods answer WHETHER the current document
 * state permits the requested transition.
 */
class Stockland_Authority {
	const OWNER              = 'owner';
	const MANAGEMENT         = 'management';
	const BRANCH_MANAGER     = 'branch_manager';
	const SALES_EMPLOYEE     = 'sales_employee';
	const WAREHOUSE_EMPLOYEE = 'warehouse_employee';
	const WAREHOUSE_MANAGER  = 'warehouse_manager';
	const AUDITOR            = 'auditor';

	/**
	 * Returns the active Stockland ERP profile for a WordPress user.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return array|false The active ERP profile, or false when no profile exists.
	 */
	public static function profile( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return Stockland_Roles::get_profile( (int) $user_id );
	}

	/**
	 * Returns the assigned Stockland ERP role for a user.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return string The assigned ERP role slug, or an empty string.
	 */
	public static function role( $user_id = null ) {
		$p = self::profile( $user_id );
		return $p ? (string) $p['role'] : '';
	}

	/**
	 * Checks whether a user has the ERP Owner role or Owner capability.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user is an Owner.
	 */
	public static function is_owner( $user_id = null ) {
		return self::role( $user_id ) === self::OWNER || user_can( $user_id ? $user_id : get_current_user_id(), 'stockland_owner' );
	}

	/**
	 * Checks whether a user has Owner or Management scope.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user has Owner or Management role scope.
	 */
	public static function is_management( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::OWNER, self::MANAGEMENT ), true );
	}

	/**
	 * Checks whether a user has an authorized warehouse role.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user has a warehouse role.
	 */
	public static function is_warehouse( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::WAREHOUSE_EMPLOYEE, self::WAREHOUSE_MANAGER ), true );
	}

	/**
	 * Returns the user's primary branch location ID, or zero when none is assigned.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return int The user's primary branch ID, or zero.
	 */
	public static function branch_id( $user_id = null ) {
		$p = self::profile( $user_id );
		return $p && ! empty( $p['branch_location_id'] ) ? (int) $p['branch_location_id'] : 0;
	}

	/**
	 * Returns all authorized location IDs assigned to a user.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return int[] The location IDs assigned to the user.
	 */
	public static function location_ids( $user_id = null ) {
		return Stockland_Roles::get_location_scope( $user_id ? $user_id : get_current_user_id() );
	}

	/**
	 * Checks the profile and capability required to view warehouse availability.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view warehouse availability.
	 */
	public static function can_view_warehouse_availability( $user_id = null ) {
		return current_user_can( 'stockland_view_warehouse_availability' ) && (bool) self::profile( $user_id );
	}

	/** Return the user's operational location scope. Company-wide roles intentionally return an empty list;
	 * callers that need company scope should use company_scope(). Branch users may have one or many locations.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return int[] The branch location IDs, or an empty list for company-wide roles.
	 */
	public static function operational_scope( $user_id = null ) {
		return self::company_scope( $user_id ) ? array() : self::location_ids( $user_id );
	}

	/**
	 * Checks whether a positive location ID is inside the user's authorized scope.
	 *
	 * @param int|string      $location_id Location ID to validate.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the location falls within the user's authorized scope.
	 */
	public static function can_access_location( $location_id, $user_id = null ) {
		$id = (int) $location_id;
		if ( $id <= 0 ) {
			return false;
		}
		if ( self::company_scope( $user_id ) ) {
			return true;
		}
		return in_array( $id, self::location_ids( $user_id ), true );
	}

	/**
	 * Checks whether a user is allowed company-wide access.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user has company-wide scope.
	 */
	public static function company_scope( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::OWNER, self::MANAGEMENT, self::AUDITOR ), true );
	}

	/**
	 * Checks a WordPress capability for the specified user.
	 *
	 * @param string          $cap WordPress capability to check.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user has the requested capability.
	 */
	public static function can( $cap, $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, $cap );
	}

	/**
	 * Checks whether the user can submit daily branch POS imports.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may submit daily POS imports.
	 */
	public static function can_daily_import( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$r       = self::role( $user_id );
		return in_array( $r, array( self::BRANCH_MANAGER, self::SALES_EMPLOYEE ), true )
			&& user_can( $user_id, 'stockland_daily_import' );
	}

	/**
	 * Checks whether the user can commit daily-import transactions.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may commit daily imports.
	 */
	public static function can_daily_commit( $user_id = null ) {
		return self::role( $user_id ) === self::BRANCH_MANAGER;
	}

	/**
	 * Checks Owner/Management authority to maintain import mappings.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may manage import mappings.
	 */
	public static function can_manage_import_mappings( $user_id = null ) {
		return self::role( $user_id ) === self::OWNER || self::role( $user_id ) === self::MANAGEMENT;
	}

	/**
	 * Checks authority to maintain external product-code mappings.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may manage external product-code mappings.
	 */
	public static function can_manage_product_code_maps( $user_id = null ) {
		return self::can_manage_import_mappings( $user_id );
	}

	/**
	 * Management-only submission authority for financial/procurement records; Owner decides separately.
	 *
	 * @param string          $capability WordPress capability to check.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may prepare the specified finance operation.
	 */
	public static function can_prepare_finance( $capability, $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, $capability );
	}

	/**
	 * Expenses may be submitted by Management or an assigned Branch Manager; Owner reviews them.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may submit expenses.
	 */
	public static function can_submit_expenses( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::MANAGEMENT, self::BRANCH_MANAGER, self::WAREHOUSE_MANAGER ), true ) && user_can( $uid, 'stockland_manage_expenses' );
	}

	/**
	 * External procurement read access is limited to active Stockland operational roles.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view external purchase orders.
	 */
	public static function can_view_external_purchase_orders( $user_id = null ) {
		$uid  = $user_id ? $user_id : get_current_user_id();
		$role = self::role( $uid );
		if ( self::OWNER === $role || self::MANAGEMENT === $role ) {
			return user_can( $uid, 'stockland_view_financials' );
		}
		if ( self::WAREHOUSE_MANAGER === $role ) {
			return user_can( $uid, 'stockland_view_warehouse' );
		}
		return false;
	}

	/**
	 * Supplier/AP transactions are restricted to active Owner or Management profiles.
	 *
	 * @param string          $capability WordPress capability to check.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may manage supplier/AP operations.
	 */
	public static function can_manage_external_supplier_finance( $capability = 'stockland_manage_payables', $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::MANAGEMENT ), true )
			&& user_can( $uid, $capability );
	}

	/**
	 * External procurement is deliberately role-separated: Management enters, Owner approves.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may create external purchase orders.
	 */
	public static function can_create_external_purchase_order( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, 'stockland_manage_purchase_orders' );
	}

	/**
	 * Checks Owner authority to approve external purchase orders.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may approve external purchase orders.
	 */
	public static function can_approve_external_purchase_order( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::OWNER && user_can( $uid, 'stockland_approve_purchase_orders' );
	}

	/**
	 * Checks Warehouse Manager authority to receive external purchase orders.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may receive external purchase orders.
	 */
	public static function can_receive_external_purchase_order( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::WAREHOUSE_MANAGER && user_can( $uid, 'stockland_receive_warehouse' );
	}

	/**
	 * Checks Owner/Management authority to import Product Master data.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may import Product Master data.
	 */
	public static function can_product_import( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::OWNER, self::MANAGEMENT ), true );
	}

	/**
	 * Checks whether the user may review a branch requisition.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may review a requisition.
	 */
	public static function can_review_requisition( $user_id = null ) {
		return self::role( $user_id ) === self::BRANCH_MANAGER;
	}

	/**
	 * Checks whether the user holds a warehouse requisition-operation role.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may operate a requisition at the warehouse.
	 */
	public static function can_warehouse_operate_requisition( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::WAREHOUSE_EMPLOYEE, self::WAREHOUSE_MANAGER ), true );
	}

	/**
	 * Checks whether the user may reserve warehouse stock for a requisition.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may reserve warehouse stock.
	 */
	public static function can_reserve_requisition( $user_id = null ) {
		return self::role( $user_id ) === self::WAREHOUSE_MANAGER;
	}

	/**
	 * Checks whether the user may pick stock for a requisition.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may pick requisition stock.
	 */
	public static function can_pick_requisition( $user_id = null ) {
		$r = self::role( $user_id );
		return self::WAREHOUSE_EMPLOYEE === $r;
	}

	/**
	 * Checks whether the user may receive a requisition transfer at a branch.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may receive requisition transfers.
	 */
	public static function can_receive_requisition( $user_id = null ) {
		return self::role( $user_id ) === self::BRANCH_MANAGER && current_user_can( 'stockland_receive_transfer' );
	}


	/**
	 * Checks whether the user's profile, scope, and capabilities permit return visibility.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view returns.
	 */
	public static function can_view_returns( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return (bool) self::profile( $user_id ) && (
			self::company_scope( $user_id ) ||
			user_can( $user_id, 'stockland_record_return' ) ||
			user_can( $user_id, 'stockland_view_audit_log' )
		);
	}

	/**
	 * Checks whether the user may view the ERP integrity report.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view integrity data.
	 */
	public static function can_view_integrity( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return (bool) self::profile( $user_id ) && (
			self::company_scope( $user_id ) || user_can( $user_id, 'stockland_view_audit_log' )
		);
	}

	/**
	 * Detailed server/runtime diagnostics may contain internal error messages,
	 * schema details and environment versions. Restrict these to system owners,
	 * management and native WordPress administrators; ordinary branch/warehouse
	 * accounts must not receive operational internals.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view sensitive diagnostics.
	 */
	public static function can_view_system_diagnostics( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		if ( $uid > 0 && user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		return (bool) self::profile( $uid ) && self::is_management( $uid );
	}

	/**
	 * Checks access to system-health information.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view system health.
	 */
	public static function can_view_system_health( $user_id = null ) {
		return self::can_view_system_diagnostics( $user_id );
	}

	/**
	 * Checks authority for the requested Management or Owner requisition-approval stage.
	 *
	 * @param string          $stage Requested requisition approval stage.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may approve the given requisition stage.
	 */
	public static function can_approve_requisition( $stage, $user_id = null ) {
		$r = self::role( $user_id );
		if ( 'management' === $stage ) {
			return self::MANAGEMENT === $r;
		}
		if ( 'owner' === $stage ) {
			return self::OWNER === $r;
		}
		return false;
	}

	/**
	 * Checks Owner-only authority to manage locations.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may manage locations.
	 */
	public static function can_manage_locations( $user_id = null ) {
		return self::is_owner( $user_id );
	}

	/**
	 * Checks Owner/Management authority to administer ERP users.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may administer users.
	 */
	public static function can_manage_users( $user_id = null ) {
		return self::is_owner( $user_id ) || self::is_management( $user_id );
	}

	/**
	 * Checks whether the user may access HR records.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view HR data.
	 */
	public static function can_view_hr( $user_id = null ) {
		return self::is_owner( $user_id ) || self::is_management( $user_id );
	}

	/** HR review and compensation classification is available to Owner and Management.
	 * Employee master entry itself is Management-only via can_prepare_hr().
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may manage HR records.
	 */
	public static function can_manage_hr( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::MANAGEMENT ), true ) && user_can( $uid, 'stockland_manage_hr' );
	}

	/**
	 * Checks Management-only authority to prepare HR records.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may prepare HR records.
	 */
	public static function can_prepare_hr( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, 'stockland_manage_hr' );
	}

	/**
	 * Checks capability-backed Owner/Management authority to view payroll.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may view payroll.
	 */
	public static function can_view_payroll( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::MANAGEMENT ), true ) && user_can( $uid, 'stockland_view_payroll' );
	}

	/**
	 * Checks Management-only authority to manage payroll.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may manage payroll.
	 */
	public static function can_manage_payroll( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, 'stockland_manage_payroll' );
	}

	/**
	 * Owner-only final payroll approval/payment. Requires the active ERP Owner profile.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may approve payroll as Owner.
	 */
	public static function can_approve_payroll( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::OWNER && user_can( $uid, 'stockland_manage_payroll' );
	}

	/**
	 * Owner-only final approval for posted financial commitments.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user has an expense decision role; record-level scope is enforced by expense_status().
	 */
	public static function can_approve_expenses( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::BRANCH_MANAGER, self::WAREHOUSE_MANAGER ), true ) && user_can( $uid, 'stockland_manage_expenses' );
	}

	/**
	 * Checks Owner-only final finance approval authority.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the user may approve finance operations.
	 */
	public static function can_approve_finance( $user_id = null ) {
		$uid = $user_id ? $user_id : get_current_user_id();
		return self::role( $uid ) === self::OWNER && user_can( $uid, 'stockland_view_financials' );
	}

	/**
	 * Maps a named ERP workflow action to its role-specific authorization check.
	 *
	 * @param string          $action Named ERP workflow action.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return bool Whether the action is authorized for the user.
	 */
	public static function action_authorized( $action, $user_id = null ) {
		switch ( $action ) {
			case 'REQUISITION_REVIEW':
				return self::can_review_requisition( $user_id );
			case 'REQUISITION_APPROVE_MANAGEMENT':
				return self::can_approve_requisition( 'management', $user_id );
			case 'REQUISITION_APPROVE_OWNER':
				return self::can_approve_requisition( 'owner', $user_id );
			case 'WAREHOUSE_RESERVE':
				return self::can_reserve_requisition( $user_id );
			case 'WAREHOUSE_PICK':
				return self::can_pick_requisition( $user_id );
			case 'BRANCH_RECEIVE':
				return self::can_receive_requisition( $user_id );
			case 'PO_CREATE':
			case 'PO_SUBMIT':
				return self::can( 'stockland_manage_purchase_orders', $user_id );
			case 'PO_APPROVE':
				return self::can( 'stockland_approve_purchase_orders', $user_id );
			case 'PO_RECEIVE':
				return self::can( 'stockland_receive_warehouse', $user_id );
			case 'HR_MANAGE':
				return self::can_manage_hr( $user_id );
			case 'PAYROLL_MANAGE':
				return self::can_manage_payroll( $user_id );
			default:
				return false;
		}
	}

	/**
	 * Verifies that the user has an active Stockland ERP profile.
	 *
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return true|WP_Error True for an active profile, or a REST error when no profile exists.
	 */
	public static function assert_profile( $user_id = null ) {
		if ( ! self::profile( $user_id ) ) {
			return new WP_Error( 'no_stockland_profile', 'This account has no active Stockland role/profile.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Verifies that a requested location is within the user's authorized branch scope.
	 *
	 * @param int|string      $location_id Location ID to validate.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return true|WP_Error True for authorized scope, or a REST error when access is denied.
	 */
	public static function assert_branch( $location_id, $user_id = null ) {
		$profile = self::profile( $user_id );
		if ( ! $profile ) {
			return new WP_Error( 'no_stockland_profile', 'This account has no active Stockland role/profile.', array( 'status' => 403 ) );
		}
		if ( self::company_scope( $user_id ) ) {
			return true;
		}
		if ( ! self::can_access_location( (int) $location_id, $user_id ) ) {
			return new WP_Error( 'branch_forbidden', 'This action is outside your authorized branch scope.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Verifies that the user is allowed to operate at Central Warehouse.
	 *
	 * @param int|string      $location_id Location ID to validate.
	 * @param int|string|null $user_id WordPress user ID; defaults to the current user.
	 * @return true|WP_Error True for authorized warehouse access, or a REST error.
	 */
	public static function assert_warehouse( $location_id, $user_id = null ) {
		$profile = self::profile( $user_id );
		if ( ! $profile ) {
			return new WP_Error( 'no_stockland_profile', 'This account has no active Stockland role/profile.', array( 'status' => 403 ) );
		}
		$central = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		if ( self::is_warehouse( $user_id ) && (int) $location_id === $central ) {
			return true;
		}
		return new WP_Error( 'warehouse_forbidden', 'This action is restricted to Central Warehouse operations.', array( 'status' => 403 ) );
	}
}
