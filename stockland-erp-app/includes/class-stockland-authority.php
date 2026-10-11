<?php
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

	public static function profile( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return Stockland_Roles::get_profile( (int) $user_id );
	}

	public static function role( $user_id = null ) {
		$p = self::profile( $user_id );
		return $p ? (string) $p['role'] : '';
	}

	public static function is_owner( $user_id = null ) {
		return self::role( $user_id ) === self::OWNER || user_can( $user_id ?: get_current_user_id(), 'stockland_owner' );
	}

	public static function is_management( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::OWNER, self::MANAGEMENT ), true );
	}

	public static function is_warehouse( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::WAREHOUSE_EMPLOYEE, self::WAREHOUSE_MANAGER ), true );
	}

	public static function branch_id( $user_id = null ) {
		$p = self::profile( $user_id );
		return $p && ! empty( $p['branch_location_id'] ) ? (int) $p['branch_location_id'] : 0;
	}

	public static function location_ids( $user_id = null ) {
		return Stockland_Roles::get_location_scope( $user_id ?: get_current_user_id() );
	}

	public static function can_view_warehouse_availability( $user_id = null ) {
		return current_user_can( 'stockland_view_warehouse_availability' ) && (bool) self::profile( $user_id );
	}

	/** Return the user's operational location scope. Company-wide roles intentionally return an empty list;
	 * callers that need company scope should use company_scope(). Branch users may have one or many locations. */
	public static function operational_scope( $user_id = null ) {
		return self::company_scope( $user_id ) ? array() : self::location_ids( $user_id );
	}

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

	public static function company_scope( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::OWNER, self::MANAGEMENT, self::AUDITOR ), true );
	}

	public static function can( $cap, $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return user_can( $user_id, $cap );
	}

	public static function can_daily_import( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$r       = self::role( $user_id );
		return in_array( $r, array( self::BRANCH_MANAGER, self::SALES_EMPLOYEE ), true )
			&& user_can( $user_id, 'stockland_daily_import' );
	}

	public static function can_daily_commit( $user_id = null ) {
		return self::role( $user_id ) === self::BRANCH_MANAGER;
	}

	public static function can_manage_import_mappings( $user_id = null ) {
		return self::role( $user_id ) === self::OWNER || self::role( $user_id ) === self::MANAGEMENT;
	}

	public static function can_manage_product_code_maps( $user_id = null ) {
		return self::can_manage_import_mappings( $user_id );
	}

	/** Management-only submission authority for financial/procurement records; Owner decides separately. */
	public static function can_prepare_finance( $capability, $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, $capability );
	}

	/** Expenses may be submitted by Management or an assigned Branch Manager; Owner reviews them. */
	public static function can_submit_expenses( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return in_array( self::role( $uid ), array( self::MANAGEMENT, self::BRANCH_MANAGER ), true ) && user_can( $uid, 'stockland_manage_expenses' );
	}

	/** External procurement read access is limited to active Stockland operational roles. */
	public static function can_view_external_purchase_orders( $user_id = null ) {
		$uid  = $user_id ?: get_current_user_id();
		$role = self::role( $uid );
		if ( $role === self::OWNER || $role === self::MANAGEMENT ) {
			return user_can( $uid, 'stockland_view_financials' );
		}
		if ( $role === self::WAREHOUSE_MANAGER ) {
			return user_can( $uid, 'stockland_view_warehouse' );
		}
		return false;
	}

	/** Supplier/AP transactions are restricted to active Owner or Management profiles. */
	public static function can_manage_external_supplier_finance( $capability = 'stockland_manage_payables', $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::MANAGEMENT ), true )
			&& user_can( $uid, $capability );
	}

	/** External procurement is deliberately role-separated: Management enters, Owner approves. */
	public static function can_create_external_purchase_order( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, 'stockland_manage_purchase_orders' );
	}

	public static function can_approve_external_purchase_order( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::OWNER && user_can( $uid, 'stockland_approve_purchase_orders' );
	}

	public static function can_receive_external_purchase_order( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::WAREHOUSE_MANAGER && user_can( $uid, 'stockland_receive_warehouse' );
	}

	public static function can_product_import( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::OWNER, self::MANAGEMENT ), true );
	}

	public static function can_review_requisition( $user_id = null ) {
		return self::role( $user_id ) === self::BRANCH_MANAGER;
	}

	public static function can_warehouse_operate_requisition( $user_id = null ) {
		$r = self::role( $user_id );
		return in_array( $r, array( self::WAREHOUSE_EMPLOYEE, self::WAREHOUSE_MANAGER ), true );
	}

	public static function can_reserve_requisition( $user_id = null ) {
		return self::role( $user_id ) === self::WAREHOUSE_MANAGER;
	}

	public static function can_pick_requisition( $user_id = null ) {
		$r = self::role( $user_id );
		return $r === self::WAREHOUSE_EMPLOYEE;
	}

	public static function can_receive_requisition( $user_id = null ) {
		return self::role( $user_id ) === self::BRANCH_MANAGER && current_user_can( 'stockland_receive_transfer' );
	}


	public static function can_view_returns( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return (bool) self::profile( $user_id ) && (
			self::company_scope( $user_id ) ||
			user_can( $user_id, 'stockland_record_return' ) ||
			user_can( $user_id, 'stockland_view_audit_log' )
		);
	}

	public static function can_view_integrity( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		return (bool) self::profile( $user_id ) && (
			self::company_scope( $user_id ) || user_can( $user_id, 'stockland_view_audit_log' )
		);
	}

	/**
	 * Detailed server/runtime diagnostics may contain internal error messages,
	 * schema details and environment versions. Restrict these to system owners,
	 * management and native WordPress administrators; ordinary branch/warehouse
	 * accounts must not receive operational internals.
	 */
	public static function can_view_system_diagnostics( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		if ( $uid > 0 && user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		return (bool) self::profile( $uid ) && self::is_management( $uid );
	}

	public static function can_view_system_health( $user_id = null ) {
		return self::can_view_system_diagnostics( $user_id );
	}

	public static function can_approve_requisition( $stage, $user_id = null ) {
		$r = self::role( $user_id );
		if ( $stage === 'management' ) {
			return $r === self::MANAGEMENT;
		}
		if ( $stage === 'owner' ) {
			return $r === self::OWNER;
		}
		return false;
	}

	public static function can_manage_locations( $user_id = null ) {
		return self::is_owner( $user_id );
	}

	public static function can_manage_users( $user_id = null ) {
		return self::is_owner( $user_id ) || self::is_management( $user_id );
	}

	public static function can_view_hr( $user_id = null ) {
		return self::is_owner( $user_id ) || self::is_management( $user_id );
	}

	/** HR review and compensation classification is available to Owner and Management.
	 * Employee master entry itself is Management-only via can_prepare_hr(). */
	public static function can_manage_hr( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::MANAGEMENT ), true ) && user_can( $uid, 'stockland_manage_hr' );
	}

	public static function can_prepare_hr( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, 'stockland_manage_hr' );
	}

	public static function can_view_payroll( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return in_array( self::role( $uid ), array( self::OWNER, self::MANAGEMENT ), true ) && user_can( $uid, 'stockland_view_payroll' );
	}

	public static function can_manage_payroll( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::MANAGEMENT && user_can( $uid, 'stockland_manage_payroll' );
	}

	/** Owner-only final payroll approval/payment. Requires the active ERP Owner profile. */
	public static function can_approve_payroll( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::OWNER && user_can( $uid, 'stockland_manage_payroll' );
	}

	/** Owner-only final approval for posted financial commitments. */
	public static function can_approve_expenses( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::OWNER && user_can( $uid, 'stockland_manage_expenses' );
	}

	public static function can_approve_finance( $user_id = null ) {
		$uid = $user_id ?: get_current_user_id();
		return self::role( $uid ) === self::OWNER && user_can( $uid, 'stockland_view_financials' );
	}

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

	public static function assert_profile( $user_id = null ) {
		if ( ! self::profile( $user_id ) ) {
			return new WP_Error( 'no_stockland_profile', 'This account has no active Stockland role/profile.', array( 'status' => 403 ) );
		}
		return true;
	}

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
