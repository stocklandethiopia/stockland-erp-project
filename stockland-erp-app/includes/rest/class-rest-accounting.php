<?php
/**
 * REST controller source for Stockland ERP operational workflows.
 *
 * @package Stockland_ERP
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
/**
 * Stockland ERP accounting, procurement, receivables, payables, and ledger REST handlers.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live Accounting / Procurement REST API for 10.5.1.
 * All write operations are authenticated, permission-checked, transactional
 * and audited. Posted financial journals are append-only.
 */
class Stockland_REST_Accounting extends Stockland_REST_Base {
	/**
	 * Registers accounting and procurement routes for the ERP REST controller.
	 *
	 * @return void Registers controller hooks.
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'routes' ) ); }

	/**
	 * Checks the current user's role and capability for an accounting operation.
	 *
	 * @param string $cap Capability required for the operation.
	 * @return bool Whether the current user is authorized for the capability.
	 */
	private function can( $cap ) {
		$uid  = get_current_user_id();
		$role = Stockland_Authority::role( $uid );
		if ( ! $uid || '' === $role || ! current_user_can( $cap ) ) {
			return false;
		}
		$policy = array(
			'stockland_view_financials'         => array( 'owner', 'management', 'auditor' ),
			'stockland_view_cost_and_profit'    => array( 'owner', 'management', 'auditor' ),
			'stockland_manage_customers'        => array( 'owner', 'management', 'branch_manager', 'sales_employee' ),
			'stockland_manage_expenses'         => array( 'owner', 'management', 'branch_manager', 'warehouse_manager' ),
			'stockland_manage_receivables'      => array( 'owner', 'management' ),
			'stockland_manage_payables'         => array( 'owner', 'management' ),
			'stockland_manage_suppliers'        => array( 'owner', 'management' ),
			'stockland_manage_payments'         => array( 'owner', 'management' ),
			'stockland_manage_gl'               => array( 'owner', 'management' ),
			'stockland_manage_purchase_orders'  => array( 'management' ),
			'stockland_approve_purchase_orders' => array( 'owner' ),
		);
		if ( isset( $policy[ $cap ] ) ) {
			return in_array( $role, $policy[ $cap ], true );
		}
		return in_array( $role, array( 'owner', 'management' ), true );
	}
	// Accounting needs an extra capability rule (management/settings can also pass).
	// Keep this helper uniquely named so it does not override the base REST
	// controller's protected require_cap( $request, $capability ) contract.
	/**
	 * Handles the require accounting cap operation for the ERP.
	 *
	 * @param string $cap Capability required for the operation.
	 * @return mixed The operation result.
	 */
	private function require_accounting_cap( $cap ) {
		return $this->can( $cap ) ? true : new WP_Error( 'forbidden', 'You are not authorized for this accounting operation.', array( 'status' => 403 ) );
	}
	/**
	 * Validates and normalizes a supplied business date.
	 *
	 * @param mixed $v Value to validate or normalize.
	 * @param mixed $fallback_date Optional fallback value.
	 * @return string|false The normalized date, or false when the value is invalid.
	 */
	private function date( $v, $fallback_date = null ) {
		$v = sanitize_text_field( null !== $v && '' !== $v ? $v : ( $fallback_date ? $fallback_date : current_time( 'Y-m-d' ) ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}
	/**
	 * Normalizes a monetary input for ledger-safe processing.
	 *
	 * @param mixed $v Value to validate or normalize.
	 * @return string The normalized monetary value.
	 */
	private function money( $v ) {
		return Stockland_Money::decimal( Stockland_Money::minor( $v ) ); }
	/**
	 * Allocates the next document number for a business record.
	 *
	 * @param string      $prefix Document-number prefix.
	 * @param mixed       $table Internal table name used for the numbering sequence.
	 * @param string      $field Internal field used for the numbering sequence.
	 * @param string|null $date Business date used to scope numbering.
	 * @return string The next document number.
	 */
	private function next_number( $prefix, $table = null, $field = null, $date = null ) {
		return Stockland_Sequence::next( $prefix, $date );
	}
	/**
	 * Resolves and validates a requested operational location.
	 *
	 * @param mixed $requested Value for the requested argument.
	 * @return int|WP_Error|false A validated location identifier, or an error/false result.
	 */
	private function location( $requested = null ) {
		$id = $this->resolve_branch_for_user( $requested );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $id ) {
			return (int) $id;
		}
		$cw = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		return $cw ? $cw : 0;
	}

	/**
	 * Registers supplier, customer, procurement, payments, and general-ledger REST routes.
	 *
	 * @return void Registers the REST routes.
	 */
	public function routes() {
		$read = array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'read' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'read' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_external_supplier_finance( 'stockland_view_financials' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/suppliers',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'suppliers' ),
					'permission_callback' => function () {
											return Stockland_Authority::can_manage_external_supplier_finance( 'stockland_view_financials' );},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'supplier_save' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_prepare_finance( 'stockland_manage_suppliers' );},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/customers',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'customers' ),
					'permission_callback' => function () {
											return $this->can( 'stockland_view_financials' ) || $this->can( 'stockland_manage_customers' );},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'customer_save' ),
					'permission_callback' => function () {
						return $this->can( 'stockland_manage_customers' );},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/purchase-orders',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'purchase_orders' ),
					'permission_callback' => function () {
											return Stockland_Authority::can_view_external_purchase_orders();},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'purchase_order_create' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_create_external_purchase_order();},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/purchase-orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'purchase_order_detail' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_view_external_purchase_orders();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/purchase-orders/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purchase_order_status' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_create_external_purchase_order() || Stockland_Authority::can_approve_external_purchase_order();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/purchase-orders/(?P<id>\d+)/receive',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purchase_order_receive' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_receive_external_purchase_order();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/supplier-invoices',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'supplier_invoices' ),
					'permission_callback' => function () {
											return Stockland_Authority::can_manage_external_supplier_finance( 'stockland_view_financials' );},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'supplier_invoice_create' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_prepare_finance( 'stockland_manage_payables' );},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/customer-invoices',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'customer_invoices' ),
					'permission_callback' => function () {
											return $this->can( 'stockland_view_financials' );},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'customer_invoice_create' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_prepare_finance( 'stockland_manage_receivables' );},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/expenses',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'expenses' ),
					'permission_callback' => function () {
											return $this->can( 'stockland_view_financials' ) || $this->can( 'stockland_manage_expenses' );},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'expense_create' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_submit_expenses();},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/expenses/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'expense_status' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_approve_expenses();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/supplier-invoices/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'supplier_invoice_status' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_approve_finance();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/payments/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'payment_status' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_approve_finance();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/payments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'payments' ),
					'permission_callback' => function () {
											return Stockland_Authority::can_manage_external_supplier_finance( 'stockland_view_financials' );},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'payment_create' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_prepare_finance( 'stockland_manage_payments' );},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/barcode/(?P<code>[A-Za-z0-9\-\._]+)/?',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'barcode' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_view_cost_and_profit' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/trial-balance',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'trial_balance' ),
				'permission_callback' => function () {
					return $this->can( 'stockland_view_financials' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/financials',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'financials' ),
				'permission_callback' => function () {
					return $this->can( 'stockland_view_financials' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/accounting/gl',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'gl' ),
				'permission_callback' => function () {
					return $this->can( 'stockland_view_financials' );
				},
			)
		);
	}

	/**
	 * Returns the accounting data authorized for the current user.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function read( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_manage_external_supplier_finance( 'stockland_view_financials' ) ) {
			return new WP_Error( 'forbidden', 'Supplier/AP accounting bootstrap is restricted to active Stockland Owner and Management profiles.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$sup = Stockland_DB::table( 'suppliers' );
		$cus = Stockland_DB::table( 'customers' );
		$si  = Stockland_DB::table( 'supplier_invoices' );
		$ci  = Stockland_DB::table( 'customer_invoices' );
		$ex  = Stockland_DB::table( 'expenses' );
		$pay = Stockland_DB::table( 'payments' );
		$ap  = Stockland_Money::subtract( Stockland_Money::decimal( Stockland_Money::minor( Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM %i WHERE status='POSTED'", $si ) ) ) ), Stockland_Money::decimal( Stockland_Money::minor( Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE payment_type='SUPPLIER' AND status='POSTED'", $pay ) ) ) ) );
		$ar  = Stockland_Money::subtract( Stockland_Money::decimal( Stockland_Money::minor( Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM %i WHERE status='POSTED'", $ci ) ) ) ), Stockland_Money::decimal( Stockland_Money::minor( Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE payment_type='CUSTOMER' AND status='POSTED'", $pay ) ) ) ) );
		// Single-query balance aggregation: avoid 3 extra queries per supplier/customer.
		$sr = Stockland_DB::read_results(
			$wpdb->prepare(
				"SELECT s.id,s.supplier_code,s.supplier_name,s.phone,s.tax_id,s.opening_balance,
            ROUND(s.opening_balance+COALESCE(i.inv_total,0)-COALESCE(p.pay_total,0),2) balance
            FROM %i s
            LEFT JOIN (SELECT supplier_id,SUM(total_amount) inv_total FROM %i WHERE status='POSTED' GROUP BY supplier_id) i ON i.supplier_id=s.id
            LEFT JOIN (SELECT supplier_id,SUM(amount) pay_total FROM %i WHERE payment_type='SUPPLIER' AND status='POSTED' GROUP BY supplier_id) p ON p.supplier_id=s.id
            WHERE s.is_active=1 ORDER BY s.supplier_name LIMIT 500",
				$sup,
				$si,
				$pay
			),
			ARRAY_A
		);
		$cr = Stockland_DB::read_results(
			$wpdb->prepare(
				"SELECT c.id,c.customer_code,c.customer_name,c.customer_type,c.phone,c.opening_balance,
            ROUND(c.opening_balance+COALESCE(i.inv_total,0)-COALESCE(p.pay_total,0),2) balance
            FROM %i c
            LEFT JOIN (SELECT customer_id,SUM(total_amount) inv_total FROM %i WHERE status='POSTED' GROUP BY customer_id) i ON i.customer_id=c.id
            LEFT JOIN (SELECT customer_id,SUM(amount) pay_total FROM %i WHERE payment_type='CUSTOMER' AND status='POSTED' GROUP BY customer_id) p ON p.customer_id=c.id
            WHERE c.is_active=1 ORDER BY c.customer_name LIMIT 500",
				$cus,
				$ci,
				$pay
			),
			ARRAY_A
		);
		return rest_ensure_response(
			array(
				'module'              => 'accounting',
				'version'             => Stockland_Accounting::DB_VERSION,
				'live'                => true,
				'suppliers'           => count( $sr ),
				'customers'           => count( $cr ),
				'accounts_payable'    => $ap,
				'accounts_receivable' => $ar,
				'expenses_month'      => Stockland_Money::decimal( Stockland_Money::minor( Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount+tax_amount),0) FROM %i WHERE status='POSTED' AND expense_date>=DATE_FORMAT(CURDATE(),'%%Y-%%m-01')", $ex ) ) ) ),
				'payments_month'      => Stockland_Money::decimal( Stockland_Money::minor( Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE status='POSTED' AND payment_date>=DATE_FORMAT(CURDATE(),'%%Y-%%m-01')", $pay ) ) ) ),
				'supplier_rows'       => $sr,
				'customer_rows'       => $cr,
			)
		);
	}
	/**
	 * Lists supplier records available to the current role.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function suppliers( WP_REST_Request $r ) {
		global $wpdb;
		$t    = Stockland_DB::table( 'suppliers' );
		$inv  = Stockland_DB::table( 'supplier_invoices' );
		$pay  = Stockland_DB::table( 'payments' );
		$rows = Stockland_DB::read_results(
			$wpdb->prepare(
				"SELECT s.*,ROUND(s.opening_balance+COALESCE(i.inv_total,0)-COALESCE(p.pay_total,0),2) balance,COALESCE(g.gl_balance,0) gl_balance
            FROM %i s
            LEFT JOIN (SELECT supplier_id,SUM(total_amount) inv_total FROM %i WHERE status='POSTED' GROUP BY supplier_id) i ON i.supplier_id=s.id
            LEFT JOIN (SELECT supplier_id,SUM(amount) pay_total FROM %i WHERE payment_type='SUPPLIER' AND status='POSTED' GROUP BY supplier_id) p ON p.supplier_id=s.id
            LEFT JOIN (SELECT supplier_id,SUM(debit-credit) gl_balance FROM %i WHERE account_code='2000' GROUP BY supplier_id) g ON g.supplier_id=s.id
            ORDER BY s.supplier_name LIMIT 1000",
				$t,
				$inv,
				$pay,
				Stockland_DB::table( 'gl_lines' )
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}
	/**
	 * Creates or updates a supplier record after capability and field validation.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function supplier_save( WP_REST_Request $r ) {
		$e = $this->require_accounting_cap( 'stockland_manage_suppliers' );
		if ( true !== $e ) {
			return $e;
		}
		global $wpdb;
		$p    = $r->get_json_params();
		$name = sanitize_text_field( $p['supplier_name'] ?? '' );
		if ( ! $name ) {
			return new WP_Error( 'missing', 'supplier_name is required', array( 'status' => 400 ) );
		}
		$code        = sanitize_text_field( $p['supplier_code'] ?? '' );
		$id          = ! empty( $p['id'] ) ? (int) $p['id'] : 0;
		$existing    = null;
		$old_opening = 0.0;
		if ( $id ) {
			$existing = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'suppliers' ), $id ), ARRAY_A );
			if ( ! $existing ) {
				return new WP_Error( 'not_found', 'Supplier not found.', array( 'status' => 404 ) );
			}$old_opening = $this->money( $existing['opening_balance'] );}
		$new_opening = array_key_exists( 'opening_balance', $p ) ? $this->money( $p['opening_balance'] ) : $old_opening;
		$data        = array(
			'supplier_code'   => $code,
			'supplier_name'   => $name,
			'phone'           => sanitize_text_field( $p['phone'] ?? ( $existing['phone'] ?? '' ) ),
			'email'           => sanitize_email( $p['email'] ?? ( $existing['email'] ?? '' ) ),
			'address'         => sanitize_text_field( $p['address'] ?? ( $existing['address'] ?? '' ) ),
			'tax_id'          => sanitize_text_field( $p['tax_id'] ?? ( $existing['tax_id'] ?? '' ) ),
			'currency'        => sanitize_text_field( $p['currency'] ?? ( $existing['currency'] ?? 'ETB' ) ),
			'credit_limit'    => $this->money( $p['credit_limit'] ?? ( $existing['credit_limit'] ?? 0 ) ),
			'opening_balance' => $new_opening,
			'notes'           => sanitize_text_field( $p['notes'] ?? ( $existing['notes'] ?? '' ) ),
			'created_by'      => $existing ? (int) $existing['created_by'] : get_current_user_id(),
		);
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start supplier transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( '' === $code ) {
				$code = $this->next_number( 'SUP-', current_time( 'Y-m-d' ) );
			}
			$data['supplier_code'] = $code;
			if ( $id ) {
				$ok = Stockland_DB::update_record( Stockland_DB::table( 'suppliers' ), $data, array( 'id' => $id ) );
			} else {
				$ok = Stockland_DB::insert_record( Stockland_DB::table( 'suppliers' ), $data );
				$id = (int) $wpdb->insert_id;}
			if ( false === $ok ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not save supplier.' );
			}
			if ( Stockland_Money::minor( Stockland_Money::subtract( $new_opening, $old_opening ) ) !== 0 ) {
				$journal = Stockland_Accounting::sync_opening_balance_journal( 'SUPPLIER', $id, $old_opening, $new_opening, get_current_user_id() );
				if ( false === $journal ) {
					throw new Exception( 'Supplier opening balance GL journal failed.' );
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'SUPPLIER_SAVE', null, null, $old_opening, $new_opening, $name, 'SUP-' . $id ) ) {
				throw new Exception( 'Audit event could not be recorded; supplier was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Supplier commit failed.' );
			}
			return rest_ensure_response(
				array(
					'id'              => $id,
					'opening_balance' => $new_opening,
				)
			);
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Lists customer records authorized for the current user.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function customers( WP_REST_Request $r ) {
		global $wpdb;
		$t       = Stockland_DB::table( 'customers' );
		$inv     = Stockland_DB::table( 'customer_invoices' );
		$pay     = Stockland_DB::table( 'payments' );
		$finance = current_user_can( 'stockland_view_financials' );
		$uid     = get_current_user_id();
		$role    = Stockland_Authority::role( $uid );
		// The branch-only view is intentionally restricted to records created by the current user.
		if ( in_array( $role, array( 'branch_manager', 'sales_employee' ), true ) ) {
			if ( $finance ) {
				$rows = Stockland_DB::read_results(
					$wpdb->prepare(
						"SELECT c.*,ROUND(c.opening_balance+COALESCE(i.inv_total,0)-COALESCE(p.pay_total,0),2) balance FROM %i c LEFT JOIN (SELECT customer_id,SUM(total_amount) inv_total FROM %i WHERE status='POSTED' GROUP BY customer_id) i ON i.customer_id=c.id LEFT JOIN (SELECT customer_id,SUM(amount) pay_total FROM %i WHERE payment_type='CUSTOMER' AND status='POSTED' GROUP BY customer_id) p ON p.customer_id=c.id WHERE c.created_by=%d ORDER BY c.customer_name LIMIT 1000",
						$t,
						$inv,
						$pay,
						$uid
					),
					ARRAY_A
				);
			} else {
				$rows = Stockland_DB::read_results(
					$wpdb->prepare(
						'SELECT c.id,c.customer_code,c.customer_name,c.customer_type,c.phone,c.email,c.address,c.created_at FROM %i c WHERE c.created_by=%d ORDER BY c.customer_name LIMIT 1000',
						$t,
						$uid
					),
					ARRAY_A
				);
			}
		} elseif ( $finance ) {
			$rows = Stockland_DB::read_results(
				$wpdb->prepare(
					"SELECT c.*,ROUND(c.opening_balance+COALESCE(i.inv_total,0)-COALESCE(p.pay_total,0),2) balance FROM %i c LEFT JOIN (SELECT customer_id,SUM(total_amount) inv_total FROM %i WHERE status='POSTED' GROUP BY customer_id) i ON i.customer_id=c.id LEFT JOIN (SELECT customer_id,SUM(amount) pay_total FROM %i WHERE payment_type='CUSTOMER' AND status='POSTED' GROUP BY customer_id) p ON p.customer_id=c.id ORDER BY c.customer_name LIMIT 1000",
					$t,
					$inv,
					$pay
				),
				ARRAY_A
			);
		} else {
			$rows = Stockland_DB::read_results(
				$wpdb->prepare(
					'SELECT c.id,c.customer_code,c.customer_name,c.customer_type,c.phone,c.email,c.address,c.created_at FROM %i c ORDER BY c.customer_name LIMIT 1000',
					$t
				),
				ARRAY_A
			);
		}
		return rest_ensure_response( $rows );
	}
	/**
	 * Creates or updates a customer record after field validation.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function customer_save( WP_REST_Request $r ) {
		$e = $this->require_accounting_cap( 'stockland_manage_customers' );
		if ( true !== $e ) {
			return $e;
		}
		global $wpdb;
		$p    = $r->get_json_params();
		$name = sanitize_text_field( $p['customer_name'] ?? '' );
		if ( ! $name ) {
			return new WP_Error( 'missing', 'customer_name is required', array( 'status' => 400 ) );
		}
		$code            = sanitize_text_field( $p['customer_code'] ?? '' );
		$id              = ! empty( $p['id'] ) ? (int) $p['id'] : 0;
		$existing        = null;
		$old_opening     = 0.0;
		$branch_operator = current_user_can( 'stockland_manage_customers' ) && ! current_user_can( 'stockland_view_financials' );
		if ( $branch_operator && $id ) {
			return new WP_Error( 'branch_customer_registration_only', 'Branch customer registration creates new customers only. Existing customer financial/master changes remain management-controlled.', array( 'status' => 403 ) );
		}
		if ( $id ) {
			$existing = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'customers' ), $id ), ARRAY_A );
			if ( ! $existing ) {
				return new WP_Error( 'not_found', 'Customer not found.', array( 'status' => 404 ) );
			}$old_opening = $this->money( $existing['opening_balance'] );}
		$new_opening = $branch_operator ? 0.0 : ( array_key_exists( 'opening_balance', $p ) ? $this->money( $p['opening_balance'] ) : $old_opening );
		$new_credit  = $branch_operator ? 0.0 : $this->money( $p['credit_limit'] ?? ( $existing['credit_limit'] ?? 0 ) );
		$data        = array(
			'customer_code'   => $code,
			'customer_name'   => $name,
			'customer_type'   => sanitize_text_field( $p['customer_type'] ?? ( $existing['customer_type'] ?? 'RETAIL' ) ),
			'phone'           => sanitize_text_field( $p['phone'] ?? ( $existing['phone'] ?? '' ) ),
			'email'           => sanitize_email( $p['email'] ?? ( $existing['email'] ?? '' ) ),
			'address'         => sanitize_text_field( $p['address'] ?? ( $existing['address'] ?? '' ) ),
			'tax_id'          => sanitize_text_field( $p['tax_id'] ?? ( $existing['tax_id'] ?? '' ) ),
			'credit_limit'    => $new_credit,
			'opening_balance' => $new_opening,
			'notes'           => sanitize_text_field( $p['notes'] ?? ( $existing['notes'] ?? '' ) ),
			'created_by'      => get_current_user_id(),
		);
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start customer transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( '' === $code ) {
				$code = $this->next_number( 'CUS-', current_time( 'Y-m-d' ) );
			}
			$data['customer_code'] = $code;
			if ( $id ) {
				$ok = Stockland_DB::update_record( Stockland_DB::table( 'customers' ), $data, array( 'id' => $id ) );
			} else {
				$ok = Stockland_DB::insert_record( Stockland_DB::table( 'customers' ), $data );
				$id = (int) $wpdb->insert_id;}
			if ( false === $ok ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not save customer.' );
			}
			if ( Stockland_Money::minor( Stockland_Money::subtract( $new_opening, $old_opening ) ) !== 0 ) {
				$journal = Stockland_Accounting::sync_opening_balance_journal( 'CUSTOMER', $id, $old_opening, $new_opening, get_current_user_id() );
				if ( false === $journal ) {
					throw new Exception( 'Customer opening balance GL journal failed.' );
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'CUSTOMER_SAVE', null, null, $old_opening, $new_opening, $name, 'CUS-' . $id ) ) {
				throw new Exception( 'Audit event could not be recorded; customer was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Customer commit failed.' );
			}
			return rest_ensure_response(
				array(
					'id'              => $id,
					'opening_balance' => $new_opening,
				)
			);
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Lists purchase orders permitted by the current role and scope.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function purchase_orders( WP_REST_Request $r ) {
		global $wpdb;
		$p    = $r->get_param( 'status' );
		$sql  = 'SELECT po.*,s.supplier_name,l.location_name
            FROM %i po
            JOIN %i s ON s.id=po.supplier_id
            JOIN %i l ON l.id=po.location_id
            WHERE 1=1';
		$args = array(
			Stockland_DB::table( 'purchase_orders' ),
			Stockland_DB::table( 'suppliers' ),
			Stockland_DB::table( 'locations' ),
		);
		if ( ! Stockland_Authority::company_scope() ) {
			$allowed = array_values( array_unique( array_map( 'intval', Stockland_Authority::location_ids() ) ) );
			if ( empty( $allowed ) ) {
				return rest_ensure_response( array() );
			}
			$ph   = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
			$sql  = str_replace( ' WHERE 1=1', ' WHERE po.location_id IN(' . $ph . ') AND 1=1', $sql );
			$args = array_merge( $args, $allowed );
		}
		if ( $p ) {
			$sql   .= ' AND po.status=%s';
			$args[] = $p;
		}
		$sql .= ' ORDER BY po.id DESC LIMIT 500';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is assembled only from fixed SQL fragments and %i/%d/%s placeholders; all identifiers and values are passed in the matching prepare arguments.
		$rows         = Stockland_DB::read_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$finance_view = $this->can( 'stockland_view_financials' );
		$uid          = get_current_user_id();
		$role         = Stockland_Authority::role( $uid );
		$can_create   = Stockland_Authority::can_create_external_purchase_order();
		$can_approve  = Stockland_Authority::can_approve_external_purchase_order();
		$can_receive  = Stockland_Authority::can_receive_external_purchase_order();
		$central      = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$role_cache   = array();
		$profile_role = static function ( $user_id ) use ( &$role_cache ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 ) {
				return '';
			}
			if ( ! array_key_exists( $user_id, $role_cache ) ) {
				$role_cache[ $user_id ] = Stockland_Authority::role( $user_id );
			}
			return $role_cache[ $user_id ];
		};
		foreach ( $rows as &$row ) {
			$actions               = array();
			$status                = (string) $row['status'];
			$creator               = (int) $row['created_by'];
			$created_by_management = 'management' === $profile_role( $creator );
			$approved_by           = (int) ( $row['approved_by'] ?? 0 );
			$owner_approved        = $approved_by > 0 && $approved_by !== $creator && 'owner' === $profile_role( $approved_by ) && ! empty( $row['approved_at'] );
			$central_po            = $central > 0 && (int) $row['location_id'] === $central;
			if ( 'DRAFT' === $status && $can_create && $creator === $uid && $created_by_management ) {
				$actions[] = 'SUBMIT';
			}
			if ( 'SUBMITTED' === $status && $can_approve && $created_by_management && $creator !== $uid ) {
				$actions[] = 'APPROVE';
				$actions[] = 'REJECT';}
			// Legacy one-step-approved records require a visible Owner re-review before any further receipt.
			if ( in_array( $status, array( 'APPROVED', 'PARTIALLY_RECEIVED' ), true ) && $can_approve && $created_by_management && $central_po && $creator !== $uid ) {
				$actions[] = 'OWNER_REVIEW';
			}
			if ( ( 'DRAFT' === $status && $can_create && $creator === $uid ) || ( in_array( $status, array( 'SUBMITTED', 'OWNER_APPROVED', 'APPROVED', 'PARTIALLY_RECEIVED' ), true ) && $can_approve ) ) {
				$actions[] = 'CANCEL';
			}
			if ( in_array( $status, array( 'OWNER_APPROVED', 'PARTIALLY_RECEIVED' ), true ) && $can_receive && $central_po && $created_by_management && $owner_approved && $creator !== $approved_by ) {
				$actions[] = 'RECEIVE';
			}
			if ( 'RECEIVED' === $status && ( $can_create || $can_approve ) && $created_by_management && $owner_approved ) {
				$actions[] = 'CLOSE';
			}
			$row['approval_integrity_ok'] = $created_by_management && $owner_approved && $central_po;
			$row['available_actions']     = $actions;
			$row['next_action']           = $actions[0] ?? 'VIEW';
			if ( ! $finance_view ) {
				foreach ( array( 'subtotal', 'tax_amount', 'total_amount' ) as $k ) {
					unset( $row[ $k ] );
				}
			}
		}
		return rest_ensure_response( $rows );
	}

	/**
	 * Creates a purchase order using the approved procurement workflow.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function purchase_order_create( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_create_external_purchase_order() ) {
			return new WP_Error( 'forbidden', 'Only an active Stockland Management profile may enter an external-supply purchase order. WordPress Administrator/settings capability alone is not purchasing authority.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p                  = $r->get_json_params();
		$supplier           = (int) ( $p['supplier_id'] ?? 0 );
		$requested_location = (int) ( $p['location_id'] ?? 0 );
		$central            = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$lines              = is_array( $p['lines'] ?? null ) ? $p['lines'] : array();
		if ( $central <= 0 ) {
			return new WP_Error( 'central_warehouse_missing', 'Central Warehouse is not configured; external purchasing is blocked until the canonical warehouse is verified.', array( 'status' => 409 ) );
		}
		if ( $requested_location > 0 && $requested_location !== $central ) {
			return new WP_Error( 'external_supply_destination', 'External-supply purchase orders must be received into Central Warehouse. Branch supply must follow the approved requisition/transfer workflow.', array( 'status' => 403 ) );
		}
		$location = $central;
		if ( ! $supplier || ! $lines ) {
			return new WP_Error( 'invalid', 'supplier_id and at least one line are required.', array( 'status' => 400 ) );
		}
		if ( ! Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d AND is_active=1', Stockland_DB::table( 'suppliers' ), $supplier ) ) ) {
			return new WP_Error( 'supplier_not_found', 'Supplier not found.', array( 'status' => 404 ) );
		}
		$sub   = 0;
		$tax   = 0;
		$clean = array();
		foreach ( $lines as $line ) {
			$sku  = sanitize_text_field( $line['sku'] ?? '' );
			$q    = (float) ( $line['quantity'] ?? 0 );
			$cost = $this->money( $line['unit_cost'] ?? 0 );
			$rate = (float) ( $line['tax_rate'] ?? Stockland_DB::get_vat_rate() );
			$prod = Stockland_DB::get_product( $sku );
			if ( ! $prod || ( $prod['product_type'] ?? 'SIMPLE' ) === 'STYLE' || $q <= 0 || $cost <= 0 || abs( $q - round( $q, 2 ) ) > 0.000001 || $rate < 0 || $rate > 1 ) {
				return new WP_Error( 'invalid_line', 'Each PO line must contain a valid stockable SKU, quantity to two decimals, positive unit cost and a tax rate from 0 to 1.', array( 'status' => 400 ) );
			}$ls     = Stockland_Money::multiply( $q, $cost );
			$lt      = Stockland_Money::percentage( $ls, $rate );
			$sub     = Stockland_Money::add( $sub, $ls );
			$tax     = Stockland_Money::add( $tax, $lt );
			$clean[] = array(
				'sku'              => $sku,
				'variant_id'       => (int) $prod['variant_id'],
				'description'      => $prod['product_name'],
				'quantity_ordered' => number_format( $q, 2, '.', '' ),
				'unit_cost'        => $cost,
				'tax_rate'         => number_format( $rate, 4, '.', '' ),
				'line_subtotal'    => $ls,
				'line_tax'         => $lt,
				'line_total'       => Stockland_Money::add( $ls, $lt ),
			);}
		$date     = $this->date( $p['order_date'] ?? null );
		$expected = $this->date( $p['expected_date'] ?? null, null );
		if ( ! $date ) {
			return new WP_Error( 'invalid_date', 'order_date is invalid.', array( 'status' => 400 ) );
		}
		$note        = sanitize_text_field( $p['notes'] ?? '' );
		$request_key = sanitize_text_field( (string) ( $p['request_key'] ?? '' ) );
		if ( '' === $request_key ) {
			return new WP_Error( 'idempotency_key_required', 'A stable request_key is required to prevent duplicate purchase orders after a timeout. Reload the form to generate a new key.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Fa-f0-9-]{16,80}$/', $request_key ) ) {
			return new WP_Error( 'invalid_request_key', 'request_key must be a valid generated idempotency token.', array( 'status' => 400 ) );
		}
		$fingerprint  = hash(
			'sha256',
			wp_json_encode(
				array(
					'supplier_id'   => $supplier,
					'location_id'   => $location,
					'order_date'    => $date,
					'expected_date' => $expected,
					'notes'         => $note,
					'lines'         => array_map(
						function ( $x ) {
							return array(
								'sku'              => $x['sku'],
								'quantity_ordered' => $x['quantity_ordered'],
								'unit_cost'        => $x['unit_cost'],
								'tax_rate'         => $x['tax_rate'],
							);
						},
						$clean
					),
				)
			)
		);
		$marker       = '' !== $request_key ? '[[SLPOREQ:' . $request_key . ':' . $fingerprint . ']]' : '';
		$request_lock = null;
		if ( '' !== $request_key ) {
			$request_lock = Stockland_DB::acquire_named_lock( 'po_create_request_' . $request_key, 10 );
			if ( empty( $request_lock['ok'] ) ) {
				return new WP_Error( 'busy', 'This purchase-order submission is already being processed. Retry the same form shortly.', array( 'status' => 409 ) );
			}
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'transaction_failed', 'Could not start the purchase-order transaction.', array( 'status' => 500 ) );}
		try {
			if ( '' !== $request_key ) {
				$pattern  = $wpdb->esc_like( '[[SLPOREQ:' . $request_key . ':' ) . '%';
				$existing = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,po_number,status,total_amount,notes FROM %i WHERE notes LIKE %s ORDER BY id DESC LIMIT 1', Stockland_DB::table( 'purchase_orders' ), $pattern ), ARRAY_A );
				if ( $wpdb->last_error ) {
					throw new Exception( 'Could not verify purchase-order retry marker.' );
				}
				if ( $existing ) {
					if ( ! preg_match( '/^\[\[SLPOREQ:([A-Fa-f0-9-]{16,80}):([a-f0-9]{64})\]\]/', $existing['notes'] ?? '', $m ) || strtolower( $m[1] ) !== strtolower( $request_key ) || ! hash_equals( $m[2], $fingerprint ) ) {
						Stockland_DB::execute_query( 'ROLLBACK' );
						Stockland_DB::release_named_lock( $request_lock );
						return new WP_Error( 'idempotency_conflict', 'This purchase-order request key has already been used with different details. Reload the form before creating another order.', array( 'status' => 409 ) );
					}
					Stockland_DB::execute_query( 'ROLLBACK' );
					Stockland_DB::release_named_lock( $request_lock );
					return rest_ensure_response(
						array(
							'id'        => (int) $existing['id'],
							'po_number' => $existing['po_number'],
							'status'    => $existing['status'],
							'total'     => (float) $existing['total_amount'],
							'replayed'  => true,
						)
					);
				}
			}
			$po           = $this->next_number( 'PO-', $date );
			$stored_notes = trim( ( '' !== $marker ? $marker . ' ' : '' ) . $note );
			if ( strlen( $stored_notes ) > 500 ) {
				throw new Exception( 'Receipt note plus idempotency marker exceeds the supported length.' );
			}
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'purchase_orders' ),
				array(
					'po_number'     => $po,
					'supplier_id'   => $supplier,
					'order_date'    => $date,
					'expected_date' => $expected,
					'location_id'   => $location,
					'status'        => 'DRAFT',
					'subtotal'      => $sub,
					'tax_amount'    => $tax,
					'total_amount'  => Stockland_Money::add( $sub, $tax ),
					'notes'         => $stored_notes,
					'created_by'    => get_current_user_id(),
				)
			) ) {
				throw new Exception( 'PO header failed' );
			}
			$id = (int) $wpdb->insert_id;
			foreach ( $clean as $line ) {
				$line['po_id'] = $id;
				if ( false === Stockland_DB::insert_record( Stockland_DB::table( 'purchase_order_items' ), $line ) ) {
					throw new Exception( 'PO line failed' );
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $location, 'PO_CREATE', null, $sub, null, 'DRAFT', null, $po ) ) {
				throw new Exception( 'Audit failed' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Purchase-order create commit failed.' );
			}if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return rest_ensure_response(
				array(
					'id'        => $id,
					'po_number' => $po,
					'status'    => 'DRAFT',
					'total'     => Stockland_Money::add( $sub, $tax ),
					'replayed'  => false,
				)
			);
		} catch ( Throwable $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'po_failed', $x->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Returns the requested purchase-order detail within the user's scope.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function purchase_order_detail( WP_REST_Request $r ) {
		global $wpdb;
		$id = (int) $r['id'];
		$po = Stockland_DB::read_row( $wpdb->prepare( 'SELECT po.*,s.supplier_name,l.location_name FROM %i po JOIN %i s ON s.id=po.supplier_id JOIN %i l ON l.id=po.location_id WHERE po.id=%d', Stockland_DB::table( 'purchase_orders' ), Stockland_DB::table( 'suppliers' ), Stockland_DB::table( 'locations' ), $id ), ARRAY_A );
		if ( ! $po ) {
			return new WP_Error( 'not_found', 'Purchase order not found.', array( 'status' => 404 ) );
		}
		if ( ! Stockland_Authority::company_scope() && ! Stockland_Authority::can_access_location( (int) $po['location_id'] ) ) {
			return new WP_Error( 'forbidden', 'This purchase order is outside your authorized location scope.', array( 'status' => 403 ) );
		}
		$po['items'] = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE po_id=%d ORDER BY id', Stockland_DB::table( 'purchase_order_items' ), $id ), ARRAY_A );
		if ( ! $this->can( 'stockland_view_financials' ) ) {
			foreach ( array( 'subtotal', 'tax_amount', 'total_amount' ) as $k ) {
				unset( $po[ $k ] );
			}
			foreach ( $po['items'] as &$item ) {
				foreach ( array( 'unit_cost', 'line_subtotal', 'line_tax', 'line_total' ) as $k ) {
					unset( $item[ $k ] );
				}
			}unset( $item );
		}
		return rest_ensure_response( $po );
	}

	/**
	 * Applies an allowed purchase-order state transition.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function purchase_order_status( WP_REST_Request $r ) {
		global $wpdb;
		$id     = (int) $r['id'];
		$action = strtoupper( sanitize_text_field( $r->get_param( 'status' ) ?? '' ) );
		if ( ! $action ) {
			return new WP_Error( 'missing', 'status/action is required.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $action, array( 'SUBMITTED', 'OWNER_APPROVED', 'APPROVED', 'OWNER_REVIEW', 'REJECTED', 'CANCELLED', 'CLOSED' ), true ) ) {
			return new WP_Error( 'invalid_transition', 'Unsupported purchase-order action.', array( 'status' => 400 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start the purchase-order transaction.', array( 'status' => 500 ) );
		}
		try {
			$po = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'purchase_orders' ), $id ), ARRAY_A );
			if ( ! $po ) {
				throw new Exception( 'PO not found.' );
			}
			$uid          = get_current_user_id();
			$role         = Stockland_Authority::role( $uid );
			$creator      = (int) $po['created_by'];
			$creator_role = Stockland_Authority::role( $creator );
			$from         = (string) $po['status'];
			$central      = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
			$central_po   = $central > 0 && (int) $po['location_id'] === $central;
			if ( ! Stockland_Authority::company_scope() && ! Stockland_Authority::can_access_location( (int) $po['location_id'] ) ) {
				throw new Exception( 'This purchase order is outside your authorized location scope.' );
			}
			$note = sanitize_text_field( (string) ( $r->get_param( 'note' ) ?? '' ) );
			$to   = $action;
			if ( 'SUBMITTED' === $action ) {
				if ( 'DRAFT' !== $from || ! Stockland_Authority::can_create_external_purchase_order() || $creator !== $uid || 'management' !== $creator_role || ! $central_po ) {
					throw new Exception( 'Only the Management member who created this Central Warehouse external-supply PO may submit it to Owner approval.' );
				}
			} elseif ( 'OWNER_APPROVED' === $action ) {
				if ( 'SUBMITTED' !== $from || ! Stockland_Authority::can_approve_external_purchase_order() || 'management' !== $creator_role || $creator === $uid || ! $central_po ) {
					throw new Exception( 'Only a different active Owner may approve a Management-entered Central Warehouse PO.' );
				}
				$to = 'OWNER_APPROVED';
			} elseif ( 'APPROVED' === $action ) {
				throw new Exception( 'The legacy one-step APPROVED transition is disabled. Use Owner approval (OWNER_APPROVED).' );
			} elseif ( 'REJECTED' === $action ) {
				if ( 'SUBMITTED' !== $from || ! Stockland_Authority::can_approve_external_purchase_order() || 'management' !== $creator_role || $creator === $uid ) {
					throw new Exception( 'Only a different Owner may reject a submitted Management PO.' );
				}
				if ( '' === $note ) {
					throw new Exception( 'A rejection reason is required.' );
				}
			} elseif ( 'OWNER_REVIEW' === $action ) {
				if ( ! in_array( $from, array( 'APPROVED', 'PARTIALLY_RECEIVED' ), true ) || ! Stockland_Authority::can_approve_external_purchase_order() || 'management' !== $creator_role || $creator === $uid || ! $central_po ) {
					throw new Exception( 'Only the Owner can re-review a legacy Central Warehouse PO originally entered by Management. Non-central legacy POs must be cancelled, not re-approved.' );
				}
				if ( '' === $note ) {
					throw new Exception( 'Explain why this legacy PO is being re-reviewed under the two-layer approval SOP.' );
				}
				$to = 'SUBMITTED';
			} elseif ( 'CANCELLED' === $action ) {
				$draft_cancel = 'DRAFT' === $from && Stockland_Authority::can_create_external_purchase_order() && $creator === $uid;
				$owner_cancel = Stockland_Authority::can_approve_external_purchase_order() && in_array( $from, array( 'SUBMITTED', 'OWNER_APPROVED', 'APPROVED', 'PARTIALLY_RECEIVED' ), true );
				if ( ! $draft_cancel && ! $owner_cancel ) {
					throw new Exception( 'Only the Management creator may cancel its draft, or the Owner may cancel a submitted/approved PO.' );
				}
				if ( $owner_cancel && '' === $note ) {
					throw new Exception( 'Owner cancellation requires a reason. Any already-posted partial receipt remains in inventory and ledger.' );
				}
			} elseif ( 'CLOSED' === $action ) {
				if ( 'RECEIVED' !== $from || ( ! Stockland_Authority::can_create_external_purchase_order() && ! Stockland_Authority::can_approve_external_purchase_order() ) ) {
					throw new Exception( 'Only Management/Owner may close a fully received external-supply PO.' );
				}
				if ( 'management' !== $creator_role || (int) $po['approved_by'] < 1 || Stockland_Authority::role( (int) $po['approved_by'] ) !== 'owner' || (int) $po['approved_by'] === $creator ) {
					throw new Exception( 'PO approval evidence is incomplete; cannot close this order.' );
				}
				$wpdb->last_error    = '';
				$open_unreceived_raw = Stockland_DB::read_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(quantity_ordered-quantity_received,0)),0) FROM %i WHERE po_id=%d', Stockland_DB::table( 'purchase_order_items' ), $id ) );
				if ( null === $open_unreceived_raw || $wpdb->last_error ) {
					throw new Exception( 'Could not verify unreceived PO quantities; closure is blocked.' );
				}if ( (float) $open_unreceived_raw > 0.00001 ) {
					throw new Exception( 'A purchase order cannot close while any ordered quantity remains unreceived.' );
				}
				$wpdb->last_error          = '';
				$open_received_invoice_raw = Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(GREATEST(poi.quantity_received-COALESCE((SELECT SUM(sii.quantity) FROM %i sii INNER JOIN %i si ON si.id=sii.invoice_id WHERE sii.po_item_id=poi.id AND si.po_id=poi.po_id AND si.status='POSTED'),0),0)),0) FROM %i poi WHERE poi.po_id=%d", Stockland_DB::table( 'supplier_invoice_items' ), Stockland_DB::table( 'supplier_invoices' ), Stockland_DB::table( 'purchase_order_items' ), $id ) );
				if ( null === $open_received_invoice_raw || $wpdb->last_error ) {
					throw new Exception( 'Could not verify received-but-uninvoiced quantities; PO closure is blocked.' );
				}$open_received_invoice_qty = (float) $open_received_invoice_raw;
				if ( $open_received_invoice_qty > 0.00001 ) {
					throw new Exception( 'A purchase order cannot be closed while received quantities remain uninvoiced. Match the supplier invoice to the received PO lines first.' );
				}
			} else {
				throw new Exception( 'Unsupported PO transition.' );
			}
			$data = array( 'status' => $to );
			if ( 'OWNER_APPROVED' === $action ) {
				$data['approved_by'] = $uid;
				$data['approved_at'] = current_time( 'mysql' );
				$data['status_note'] = '' !== $note ? $note : null;}
			if ( 'OWNER_REVIEW' === $action ) {
				$data['approved_by'] = null;
				$data['approved_at'] = null;
				$data['status_note'] = $note;}
			if ( in_array( $action, array( 'REJECTED', 'CANCELLED', 'CLOSED' ), true ) ) {
				$data['status_note'] = '' !== $note ? $note : null;
			}
			if ( false === Stockland_DB::update_record( Stockland_DB::table( 'purchase_orders' ), $data, array( 'id' => $id ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not update PO.' );
			}
			if ( ! Stockland_Audit::log( $uid, $po['location_id'], 'PO_STATUS', null, null, $from, $to, ( $action !== $to ? $action . ' — ' : '' ) . $note, $po['po_number'] ) ) {
				throw new Exception( 'Audit event could not be recorded; purchase-order status was not committed.' );
			}
			if ( 'SUBMITTED' === $action ) {
				if ( ! Stockland_Notifications::push_role( 'owner', null, 'purchase_order_approval_required', 'External supply PO awaits Owner approval', 'Management submitted ' . $po['po_number'] . ' for Owner review. No stock or PLEDGER/GL posting has occurred.', 'purchase_order', $id, 'action' ) ) {
					throw new Exception( 'Owner notification could not be saved; PO submission was rolled back.' );
				}
			} elseif ( 'OWNER_REVIEW' === $action ) {
				if ( ! Stockland_Notifications::push_role( 'management', null, 'purchase_order_owner_rereview', 'Legacy purchase order returned to Owner approval', $po['po_number'] . ' was returned to SUBMITTED for a fresh Owner decision. The previous approval marker was cleared; no new stock or ledger posting occurred.', 'purchase_order', $id, 'info' ) ) {
					throw new Exception( 'Management re-review notification could not be saved; the PO transition was rolled back.' );
				}
			} elseif ( 'OWNER_APPROVED' === $action ) {
				if ( ! Stockland_Notifications::push_role( 'warehouse_manager', $central, 'purchase_order_receipt_required', 'Approved supply awaiting warehouse receipt', $po['po_number'] . ' is Owner-approved. Verify physical goods and enter only the actual accepted quantity; any balance remains open.', 'purchase_order', $id, 'action' ) ) {
					throw new Exception( 'Warehouse Manager notification could not be saved; Owner approval was rolled back.' );
				}
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Purchase-order commit failed.' );
			}
			return rest_ensure_response(
				array(
					'status'                  => $to,
					'action'                  => $action,
					'owner_approval_required' => 'SUBMITTED' === $to,
					'stock_changed'           => false,
					'ledger_posted'           => false,
				)
			);
		} catch ( Throwable $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			$msg       = $x->getMessage();
			$not_found = ( stripos( $msg, 'PO not found' ) !== false );
			$forbidden = ( stripos( $msg, 'only ' ) !== false || stripos( $msg, 'authority' ) !== false || stripos( $msg, 'Owner' ) !== false || stripos( $msg, 'Management' ) !== false || stripos( $msg, 'transition' ) !== false || stripos( $msg, 'central warehouse' ) !== false || stripos( $msg, 'non-central' ) !== false || stripos( $msg, 'reason' ) !== false || stripos( $msg, 're-review' ) !== false || stripos( $msg, 'close' ) !== false );
			return new WP_Error( $not_found ? 'not_found' : ( $forbidden ? 'forbidden_transition' : 'po_status_failed' ), $msg, array( 'status' => $not_found ? 404 : ( $forbidden ? 403 : 500 ) ) );
		}
	}

	/**
	 * Records receipt against an authorized purchase order.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function purchase_order_receive( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_receive_external_purchase_order() ) {
			return new WP_Error( 'forbidden', 'Only an active Stockland Warehouse Manager may accept external-supply quantities. Warehouse Employee and WordPress Administrator capabilities alone are insufficient.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$poid        = (int) $r['id'];
		$p           = $r->get_json_params();
		$request_key = sanitize_text_field( (string) ( $p['request_key'] ?? '' ) );
		if ( '' === $request_key ) {
			return new WP_Error( 'idempotency_key_required', 'A stable request_key is required for goods receiving. Retry with the same key to avoid duplicate stock/PLEDGER posting.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Fa-f0-9-]{16,80}$/', $request_key ) ) {
			return new WP_Error( 'invalid_request_key', 'request_key must be a valid generated idempotency token.', array( 'status' => 400 ) );
		}
		$received = is_array( $p['lines'] ?? null ) ? $p['lines'] : array();
		if ( ! $received ) {
			return new WP_Error( 'invalid', 'Receive lines are required.', array( 'status' => 400 ) );
		}
		$by = array();
		foreach ( $received as $line ) {
			$item_id = (int) ( $line['po_item_id'] ?? 0 );
			$raw_q   = $line['quantity'] ?? null;
			if ( $item_id <= 0 ) {
				return new WP_Error( 'invalid', 'Every receiving line must include a valid po_item_id.', array( 'status' => 400 ) );
			}if ( array_key_exists( $item_id, $by ) ) {
				return new WP_Error( 'invalid', 'A purchase-order line was submitted more than once.', array( 'status' => 400 ) );
			}if ( null === $raw_q || ! is_numeric( $raw_q ) || (float) $raw_q < 0 || abs( (float) $raw_q - round( (float) $raw_q, 2 ) ) > 0.000001 ) {
				return new WP_Error( 'invalid', 'Received quantity must be a non-negative number with at most two decimal places.', array( 'status' => 400 ) );
			}$by[ $item_id ] = round( (float) $raw_q, 2 );}
		ksort( $by, SORT_NUMERIC );
		$date = $this->date( $p['receipt_date'] ?? null );
		if ( ! $date || ! Stockland_Business_Calendar::valid_date( $date ) ) {
			return new WP_Error( 'invalid_date', 'receipt_date must be a valid YYYY-MM-DD date.', array( 'status' => 400 ) );
		}
		$note      = sanitize_text_field( $p['notes'] ?? '' );
		$canonical = array();
		foreach ( $by as $id => $q ) {
			$canonical[] = array(
				'po_item_id' => (int) $id,
				'quantity'   => number_format( $q, 2, '.', '' ),
			);
		}
		$fingerprint  = hash(
			'sha256',
			wp_json_encode(
				array(
					'po_id'        => $poid,
					'receipt_date' => $date,
					'notes'        => $note,
					'lines'        => $canonical,
				)
			)
		);
		$marker       = '' !== $request_key ? '[[SLGRNREQ:' . $request_key . ':' . $fingerprint . ']]' : '';
		$request_lock = null;
		// Stable request lock serializes concurrent retries before checking the receipt marker.
		if ( '' !== $request_key ) {
			$request_lock = Stockland_DB::acquire_named_lock( 'grn_request_' . $request_key, 10 );
			if ( empty( $request_lock['ok'] ) ) {
				return new WP_Error( 'busy', 'This goods receipt submission is already being processed. Retry the same form shortly.', array( 'status' => 409 ) );
			}
		}
		$peek = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,location_id,status,created_by,approved_by,approved_at,po_number FROM %i WHERE id=%d', Stockland_DB::table( 'purchase_orders' ), $poid ), ARRAY_A );
		if ( ! $peek ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'not_found', 'PO not found.', array( 'status' => 404 ) );}
		if ( ! Stockland_Authority::company_scope() && ! Stockland_Authority::can_access_location( (int) $peek['location_id'] ) ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'forbidden', 'This purchase order is outside your authorized receiving location.', array( 'status' => 403 ) );}
		$location = (int) $peek['location_id'];
		$central  = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		if ( $central <= 0 || $location !== $central ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'external_supply_destination', 'External supply receipts are restricted to Central Warehouse. Branch fulfillment must use approved requisitions/transfers.', array( 'status' => 403 ) );}
		$warehouse_guard = Stockland_Authority::assert_warehouse( $location );
		if ( is_wp_error( $warehouse_guard ) || (int) $peek['created_by'] <= 0 || Stockland_Authority::role( (int) $peek['created_by'] ) !== 'management' || (int) $peek['approved_by'] <= 0 || (int) $peek['approved_by'] === (int) $peek['created_by'] || Stockland_Authority::role( (int) $peek['approved_by'] ) !== 'owner' || empty( $peek['approved_at'] ) ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'owner_approval_required', 'The PO lacks valid, separate Management-entry and Owner-approval evidence. Stop receiving and route legacy records to the Owner review queue.', array( 'status' => 409 ) );}
		if ( '' !== $request_key ) {
			$pattern  = $wpdb->esc_like( '[[SLGRNREQ:' . $request_key . ':' ) . '%';
			$existing = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,receipt_number,po_id,notes FROM %i WHERE notes LIKE %s ORDER BY id DESC LIMIT 1', Stockland_DB::table( 'purchase_receipts' ), $pattern ), ARRAY_A );
			if ( $wpdb->last_error ) {
				Stockland_DB::release_named_lock( $request_lock );
				return new WP_Error( 'retry_check_failed', 'Could not verify goods-receipt retry marker.', array( 'status' => 500 ) );}
			if ( $existing ) {
				if ( (int) $existing['po_id'] !== $poid || ! preg_match( '/^\[\[SLGRNREQ:([A-Fa-f0-9-]{16,80}):([a-f0-9]{64})\]\]/', $existing['notes'] ?? '', $m ) || strtolower( $m[1] ) !== strtolower( $request_key ) || ! hash_equals( $m[2], $fingerprint ) ) {
					Stockland_DB::release_named_lock( $request_lock );
					return new WP_Error( 'idempotency_conflict', 'This goods-receipt request key already belongs to a different submission. Reopen the receiving form before making a new receipt.', array( 'status' => 409 ) );
				}
				$rid     = (int) $existing['id'];
				$journal = Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_type=%s AND source_id=%s AND status=\'POSTED\' LIMIT 1', Stockland_DB::table( 'gl_journals' ), 'PURCHASE_RECEIPT', (string) $rid ) );
				if ( ! $journal ) {
					Stockland_DB::release_named_lock( $request_lock );
					return new WP_Error( 'receipt_integrity_error', 'The prior receipt exists but its posted PLEDGER/GL journal could not be verified. Stop and ask Management to reconcile before retrying.', array( 'status' => 500 ) );}
				$value   = (float) Stockland_DB::read_var( $wpdb->prepare( 'SELECT COALESCE(SUM(line_value),0) FROM %i WHERE receipt_id=%d', Stockland_DB::table( 'purchase_receipt_items' ), $rid ) );
				$current = (string) Stockland_DB::read_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id=%d', Stockland_DB::table( 'purchase_orders' ), $poid ) );
				Stockland_DB::release_named_lock( $request_lock );
				return rest_ensure_response(
					array(
						'receipt_number'  => $existing['receipt_number'],
						'status'          => $current,
						'inventory_value' => $value,
						'journal_id'      => (int) $journal,
						'replayed'        => true,
					)
				);
			}
		}
		if ( ! in_array( $peek['status'], array( 'OWNER_APPROVED', 'PARTIALLY_RECEIVED' ), true ) ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'invalid_status', 'Only an Owner-approved or partially received PO may accept further quantities. Legacy one-step approvals must be re-reviewed by the Owner.', array( 'status' => 409 ) );}
		try {
			Stockland_Business_Calendar::assert_business_day( $date );
		} catch ( Throwable $e ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'business_day_closed', $e->getMessage(), array( 'status' => 409 ) );}
		if ( Stockland_DB::is_day_closed( $location, $date ) ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'business_day_closed', 'This receiving business day is already closed.', array( 'status' => 409 ) );}
		$locks = Stockland_DB::acquire_location_locks( array( $location ), $date );
		if ( ! $locks['ok'] ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'busy', 'Another inventory operation is processing this warehouse date.', array( 'status' => 409 ) );}
		if ( $request_lock ) {
			$locks['locks'][] = $request_lock;
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_location_locks( $locks['locks'] );
			return new WP_Error( 'transaction_failed', 'Could not start receiving transaction.', array( 'status' => 500 ) );}
		try {
			$po = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'purchase_orders' ), $poid ), ARRAY_A );
			if ( ! $po ) {
				throw new Exception( 'PO not found.' );
			}
			if ( ! Stockland_Authority::company_scope() && ! Stockland_Authority::can_access_location( (int) $po['location_id'] ) ) {
				throw new Exception( 'This purchase order is outside your authorized receiving location.' );
			}
			if ( (int) $po['location_id'] !== $location || $location !== $central ) {
				throw new Exception( 'External supply must be received at the verified Central Warehouse.' );
			}
			if ( Stockland_Authority::role( (int) $po['created_by'] ) !== 'management' || (int) $po['approved_by'] <= 0 || (int) $po['approved_by'] === (int) $po['created_by'] || Stockland_Authority::role( (int) $po['approved_by'] ) !== 'owner' || empty( $po['approved_at'] ) ) {
				throw new Exception( 'Separate Management-entry and Owner-approval evidence is required before stock receipt.' );
			}
			$warehouse_guard = Stockland_Authority::assert_warehouse( $location );
			if ( is_wp_error( $warehouse_guard ) ) {
				throw new Exception( $warehouse_guard->get_error_message() );
			}
			Stockland_Business_Calendar::assert_business_day( $date );
			if ( Stockland_DB::is_day_closed( $location, $date ) ) {
				throw new Exception( 'This receiving business day is already closed.' );
			}
			if ( ! in_array( $po['status'], array( 'OWNER_APPROVED', 'PARTIALLY_RECEIVED' ), true ) ) {
				throw new Exception( 'PO must have separate Owner approval before receiving.' );
			}
			$items = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE po_id=%d ORDER BY id FOR UPDATE', Stockland_DB::table( 'purchase_order_items' ), $poid ), ARRAY_A );
			if ( ! $items ) {
				throw new Exception( 'Purchase order has no lines.' );
			}
			$item_ids = array();
			foreach ( $items as $item ) {
				$item_ids[ (int) $item['id'] ] = $item;
			}foreach ( $by as $item_id => $q ) {
				if ( ! isset( $item_ids[ $item_id ] ) ) {
					throw new Exception( 'Receiving line ' . $item_id . ' does not belong to this purchase order.' );
				}
			}
			if ( ! array_filter(
				$by,
				function ( $q ) {
					return $q > 0;
				}
			) ) {
				throw new Exception( 'At least one positive receiving quantity is required.' );
			}
			foreach ( $items as $item ) {
				$q         = (float) ( $by[ (int) $item['id'] ] ?? 0 );
				$remaining = max( 0, (float) $item['quantity_ordered'] - (float) $item['quantity_received'] );
				if ( $q > $remaining + 0.00001 ) {
					throw new Exception( 'Received quantity exceeds PO balance for SKU ' . $item['sku'] . '.' );
				}
			}
			$receipt      = $this->next_number( 'GRN-', $date );
			$stored_notes = trim( ( '' !== $marker ? $marker . ' ' : '' ) . $note );
			if ( strlen( $stored_notes ) > 500 ) {
				throw new Exception( 'Receipt note plus idempotency marker exceeds the supported length.' );
			}
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'purchase_receipts' ),
				array(
					'receipt_number' => $receipt,
					'po_id'          => $poid,
					'supplier_id'    => $po['supplier_id'],
					'location_id'    => $location,
					'receipt_date'   => $date,
					'status'         => 'POSTED',
					'notes'          => $stored_notes,
					'created_by'     => get_current_user_id(),
				)
			) ) {
				throw new Exception( 'GRN header failed' );
			}
			$rid       = (int) $wpdb->insert_id;
			$total     = 0;
			$grni      = 0;
			$input_tax = 0;
			$count     = 0;
			foreach ( $items as $item ) {
				$q = (float) ( $by[ (int) $item['id'] ] ?? 0 );
				if ( $q <= 0 ) {
					continue;
				}$value    = Stockland_Money::multiply( $q, $item['unit_cost'] );
				$line_tax  = Stockland_Money::percentage( $value, $item['tax_rate'] );
				$total     = Stockland_Money::add( $total, $value );
				$input_tax = Stockland_Money::add( $input_tax, $line_tax );
				$grni      = Stockland_Money::add( $grni, Stockland_Money::add( $value, $line_tax ) );
				++$count;
				$received_variant_id = (int) ( $item['variant_id'] ?? 0 );
				if ( false === Stockland_DB::insert_record(
					Stockland_DB::table( 'purchase_receipt_items' ),
					array(
						'receipt_id'        => $rid,
						'po_item_id'        => $item['id'],
						'sku'               => $item['sku'],
						'variant_id'        => $received_variant_id ? $received_variant_id : null,
						'quantity_received' => $q,
						'unit_cost'         => $item['unit_cost'],
						'line_value'        => $value,
					)
				) ) {
					throw new Exception( 'GRN line failed' );
				}
				$ref = 'GRN' . $rid . 'I' . $item['id'];
				if ( strlen( $ref ) > 30 ) {
					$ref = 'G' . $rid . 'I' . $item['id'];
				}if ( false === Stockland_Ledger::record( $location, $item['sku'], 'PURCHASE_RECEIPT', $q, 'po_receipt', $ref, $date, get_current_user_id() ) ) {
					throw new Exception( 'Inventory ledger posting failed for ' . $item['sku'] );
				}
				$po_item_ok = Stockland_DB::execute_query( $wpdb->prepare( 'UPDATE %i SET quantity_received=quantity_received+%f WHERE id=%d', Stockland_DB::table( 'purchase_order_items' ), $q, $item['id'] ) );
				if ( false === $po_item_ok ) {
					throw new Exception( 'PO received quantity update failed.' );
				}
			}
			if ( $count <= 0 ) {
				throw new Exception( 'No receivable quantity was submitted.' );
			}$new_status     = 'PARTIALLY_RECEIVED';
			$remaining_total = (float) Stockland_DB::read_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(quantity_ordered-quantity_received,0)),0) FROM %i WHERE po_id=%d', Stockland_DB::table( 'purchase_order_items' ), $poid ) );
			if ( $remaining_total <= 0.00001 ) {
				$new_status = 'RECEIVED';
			}
			$journal = Stockland_Accounting::post_journal(
				$date,
				'PURCHASE_RECEIPT',
				(string) $rid,
				'GRN ' . $receipt,
				array(
					array(
						'account_code' => '1200',
						'debit'        => $total,
						'credit'       => 0,
						'location_id'  => $location,
					),
					array(
						'account_code' => '1300',
						'debit'        => $input_tax,
						'credit'       => 0,
						'location_id'  => $location,
					),
					array(
						'account_code' => '2010',
						'debit'        => 0,
						'credit'       => $grni,
						'location_id'  => $location,
					),
				),
				get_current_user_id()
			);
			if ( ! $journal ) {
				throw new Exception( 'GRN journal failed' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $location, 'PURCHASE_RECEIPT', null, $total, null, 'POSTED', 'PO ' . $po['po_number'], $receipt ) ) {
				throw new Exception( 'Receipt audit failed' );
			}
			if ( false === Stockland_DB::update_record( Stockland_DB::table( 'purchase_orders' ), array( 'status' => $new_status ), array( 'id' => $poid ) ) ) {
				throw new Exception( 'PO status update failed.' );
			}
			$accepted_summary = array();
			foreach ( $items as $accepted_item ) {
				$accepted_qty = (float) ( $by[ (int) $accepted_item['id'] ] ?? 0 );
				if ( $accepted_qty > 0 ) {
					$accepted_summary[] = $accepted_item['sku'] . ' × ' . number_format( $accepted_qty, 2, '.', '' );
				}
			}
			$accepted_text = implode( ', ', $accepted_summary );
			$outcome       = $po['po_number'] . ' receipt ' . $receipt . ' accepted: ' . $accepted_text . '. Total open PO quantity remaining: ' . number_format( $remaining_total, 2, '.', '' ) . '.';
			if ( ! Stockland_Notifications::push_role( 'owner', null, 'purchase_order_receipt_posted', 'Warehouse accepted supply quantity', $outcome, 'purchase_order', $poid, 'info' ) ) {
				throw new Exception( 'Owner receipt notification failed; receipt posting was rolled back.' );
			}
			if ( ! Stockland_Notifications::push_role( 'management', null, 'purchase_order_receipt_posted', 'Warehouse accepted supply quantity', $outcome, 'purchase_order', $poid, 'info' ) ) {
				throw new Exception( 'Management receipt notification failed; receipt posting was rolled back.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Receiving transaction commit failed.' );
			}Stockland_DB::release_location_locks( $locks['locks'] );
			return rest_ensure_response(
				array(
					'receipt_number'          => $receipt,
					'status'                  => $new_status,
					'inventory_value'         => $total,
					'journal_id'              => $journal,
					'accepted_lines'          => $count,
					'open_quantity_remaining' => $remaining_total,
					'replayed'                => false,
				)
			);
		} catch ( Throwable $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_location_locks( $locks['locks'] );
			$message = $x->getMessage();
			$closed  = ( stripos( $message, 'business date is closed' ) !== false || stripos( $message, 'business day is already closed' ) !== false );
			return new WP_Error( $closed ? 'business_day_closed' : 'receive_failed', $message, array( 'status' => $closed ? 409 : 500 ) );}
	}

	/**
	 * Lists supplier invoices visible to the current role.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function supplier_invoices( WP_REST_Request $r ) {
		global $wpdb;
		$status     = strtoupper( sanitize_text_field( (string) $r->get_param( 'status' ) ) );
		$has_status = in_array( $status, array( 'PENDING', 'POSTED', 'REJECTED' ), true );
		$rows       = Stockland_DB::read_results(
			$wpdb->prepare(
				'SELECT i.*,s.supplier_name,po.po_number,l.location_name,u.display_name AS created_by_name,
				COALESCE(pa.allocated_amount,0) AS allocated_amount,
				GREATEST(i.total_amount-COALESCE(pa.allocated_amount,0),0) AS outstanding_amount
				FROM %i i
				LEFT JOIN (
					SELECT a.invoice_id,SUM(a.amount) AS allocated_amount
					FROM %i a
					INNER JOIN %i pay ON pay.id=a.payment_id
					WHERE a.invoice_type=\'AP\' AND pay.status IN (\'PENDING\',\'POSTED\')
					GROUP BY a.invoice_id
				) pa ON pa.invoice_id=i.id
				JOIN %i s ON s.id=i.supplier_id
				LEFT JOIN %i po ON po.id=i.po_id
				LEFT JOIN %i l ON l.id=po.location_id
				LEFT JOIN %i u ON u.ID=i.created_by
				WHERE (%d = 0 OR i.status = %s)
				ORDER BY i.id DESC LIMIT 500',
				Stockland_DB::table( 'supplier_invoices' ),
				Stockland_DB::table( 'payment_allocations' ),
				Stockland_DB::table( 'payments' ),
				Stockland_DB::table( 'suppliers' ),
				Stockland_DB::table( 'purchase_orders' ),
				Stockland_DB::table( 'locations' ),
				$wpdb->users,
				$has_status ? 1 : 0,
				$has_status ? $status : ''
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}

	/**
	 * Creates a supplier invoice and links it to eligible procurement records.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function supplier_invoice_create( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_prepare_finance( 'stockland_manage_payables' ) ) {
			return new WP_Error( 'forbidden', 'Supplier invoice entry is Management-only; the active Owner must approve the submitted invoice separately.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p       = $r->get_json_params();
		$sid     = (int) ( $p['supplier_id'] ?? 0 );
		$poid    = (int) ( $p['po_id'] ?? 0 );
		$invoice = sanitize_text_field( $p['invoice_number'] ?? '' );
		if ( ! $sid || ! $invoice ) {
			return new WP_Error( 'missing', 'supplier_id and invoice_number are required.', array( 'status' => 400 ) );
		}
		if ( $poid <= 0 ) {
			return new WP_Error( 'po_required', 'External supplier invoices must be matched to a Management-entered, separately Owner-approved Central Warehouse PO. Unlinked stock invoices are disabled.', array( 'status' => 409 ) );
		}
		$date  = $this->date( $p['invoice_date'] ?? null );
		$due   = $this->date( $p['due_date'] ?? null, null );
		$sub   = $this->money( $p['subtotal'] ?? 0 );
		$tax   = $this->money( $p['tax_amount'] ?? 0 );
		$total = Stockland_Money::add( $sub, $tax );
		if ( $total <= 0 ) {
			return new WP_Error( 'invalid', 'Invoice total must be positive.', array( 'status' => 400 ) );
		}
		$invoice_lines  = is_array( $p['lines'] ?? null ) ? $p['lines'] : array();
		$invoice_status = 'PENDING';
		// The authoritative PO read/lock is repeated only inside the invoice transaction below.
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start supplier-invoice transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( $poid ) {
				$po = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d AND supplier_id=%d FOR UPDATE', Stockland_DB::table( 'purchase_orders' ), $poid, $sid ), ARRAY_A );
				if ( ! $po ) {
					throw new Exception( 'PO not found for supplier.' );
				}
				if ( ! in_array( $po['status'], array( 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED' ), true ) ) {
					throw new Exception( 'A supplier invoice linked to a PO requires Owner-approved received stock first.' );
				}
				$central = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
				if ( $central <= 0 || (int) $po['location_id'] !== $central ) {
					throw new Exception( 'External supplier invoice must match Central Warehouse procurement; branch stock must flow through the approved transfer/requisition workflow.' );
				}
				if ( Stockland_Authority::role( (int) $po['created_by'] ) !== 'management' || (int) $po['approved_by'] <= 0 || (int) $po['approved_by'] === (int) $po['created_by'] || Stockland_Authority::role( (int) $po['approved_by'] ) !== 'owner' || empty( $po['approved_at'] ) ) {
					throw new Exception( 'Supplier invoice posting requires separate Management entry and Owner approval evidence on the linked PO.' );
				}
				if ( ! Stockland_Authority::company_scope() && ! Stockland_Authority::can_access_location( (int) $po['location_id'] ) ) {
					throw new Exception( 'This purchase order is outside your authorized location scope.' );
				}
			}
			$po_item_rows = array();
			$po_item_ids  = array();
			$already      = array();
			if ( $poid ) {
				$po_item_rows = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE po_id=%d FOR UPDATE', Stockland_DB::table( 'purchase_order_items' ), $poid ), ARRAY_A );
				if ( ! $po_item_rows ) {
					throw new Exception( 'Linked purchase order has no lines.' );
				}
				foreach ( $po_item_rows as $pi ) {
					$po_item_ids[ (int) $pi['id'] ] = $pi;
				}
				$already_rows = Stockland_DB::read_results( $wpdb->prepare( 'SELECT sii.po_item_id,COALESCE(SUM(sii.quantity),0) qty FROM %i sii JOIN %i si ON si.id=sii.invoice_id WHERE si.po_id=%d AND si.status IN (\'POSTED\',\'PENDING\') AND sii.po_item_id IS NOT NULL GROUP BY sii.po_item_id', Stockland_DB::table( 'supplier_invoice_items' ), Stockland_DB::table( 'supplier_invoices' ), $poid ), ARRAY_A );
				foreach ( $already_rows as $ar ) {
					$already[ (int) $ar['po_item_id'] ] = (float) $ar['qty'];
				}
				if ( ! $invoice_lines ) {
					foreach ( $po_item_rows as $pi ) {
						$remaining_received = max( 0, (float) $pi['quantity_received'] - (float) ( $already[ (int) $pi['id'] ] ?? 0 ) );
						if ( $remaining_received <= 0.00001 ) {
							continue;
						}$ls             = Stockland_Money::multiply( $remaining_received, $pi['unit_cost'] );
						$lt              = Stockland_Money::percentage( $ls, $pi['tax_rate'] );
						$invoice_lines[] = array(
							'po_item_id'    => $pi['id'],
							'sku'           => $pi['sku'],
							'variant_id'    => $pi['variant_id'] ?? null,
							'description'   => $pi['description'],
							'quantity'      => $remaining_received,
							'unit_cost'     => $pi['unit_cost'],
							'tax_rate'      => $pi['tax_rate'],
							'line_subtotal' => $ls,
							'line_tax'      => $lt,
							'line_total'    => Stockland_Money::add( $ls, $lt ),
						);}
				}
			}
			if ( ! $invoice_lines ) {
				throw new Exception( 'No invoice lines were supplied and no uninvoiced received PO quantity is available.' );
			}
			Stockland_DB::execute_query( 'SAVEPOINT supplier_invoice_before_header' );
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'supplier_invoices' ),
				array(
					'invoice_number' => $invoice,
					'supplier_id'    => $sid,
					'po_id'          => $poid ? $poid : null,
					'invoice_date'   => $date,
					'due_date'       => $due,
					'subtotal'       => $sub,
					'tax_amount'     => $tax,
					'total_amount'   => $total,
					'status'         => $invoice_status,
					'notes'          => sanitize_text_field( $p['notes'] ?? '' ),
					'created_by'     => get_current_user_id(),
				)
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Supplier invoice failed' );
			}
			$id             = (int) $wpdb->insert_id;
			$line_sub_total = 0;
			$line_tax_total = 0;
			$seen_po        = array();
			foreach ( $invoice_lines as $li ) {
				$po_item_id = (int) ( $li['po_item_id'] ?? 0 );
				if ( $poid ) {
					if ( ! $po_item_id || ! isset( $po_item_ids[ $po_item_id ] ) ) {
						throw new Exception( 'Every PO-linked supplier invoice line must reference a line from the selected purchase order.' );
					}
					if ( isset( $seen_po[ $po_item_id ] ) ) {
						throw new Exception( 'A purchase-order line cannot be invoiced more than once in the same supplier invoice.' );
					}
					$seen_po[ $po_item_id ] = 1;
					$pi                     = $po_item_ids[ $po_item_id ];
					$q                      = (float) ( $li['quantity'] ?? 0 );
					$remaining_received     = max( 0, (float) $pi['quantity_received'] - (float) ( $already[ $po_item_id ] ?? 0 ) );
					if ( $q <= 0 || $q > $remaining_received + 0.00001 ) {
						throw new Exception( 'Supplier invoice quantity exceeds uninvoiced received quantity for SKU ' . $pi['sku'] . '.' );
					}
				}
				$ls       = $this->money( $li['line_subtotal'] ?? 0 );
				$lt       = $this->money( $li['line_tax'] ?? 0 );
				$q        = (float) ( $li['quantity'] ?? 0 );
				$uc       = $this->money( $li['unit_cost'] ?? 0 );
				$tax_rate = (float) ( $li['tax_rate'] ?? 0 );
				$desc     = sanitize_text_field( $li['description'] ?? ( $li['sku'] ?? 'Supplier item' ) );
				if ( $ls < 0 || $lt < 0 || $q <= 0 || $uc < 0 || $tax_rate < 0 || $tax_rate > 1 ) {
					throw new Exception( 'Invalid supplier invoice line' );
				}
				$expected_sub = $this->money( Stockland_Money::multiply( $q, $uc ) );
				$expected_tax = $this->money( Stockland_Money::percentage( $expected_sub, $tax_rate ) );
				if ( Stockland_Money::minor( Stockland_Money::subtract( $ls, $expected_sub ) ) !== 0 || Stockland_Money::minor( Stockland_Money::subtract( $lt, $expected_tax ) ) !== 0 ) {
					throw new Exception( 'Supplier invoice line subtotal/tax does not match its quantity, unit cost and tax rate.' );
				}
				if ( $poid ) {
					$pi       = $po_item_ids[ $po_item_id ];
					$line_sku = sanitize_text_field( $li['sku'] ?? $pi['sku'] );
					if ( $line_sku !== (string) $pi['sku'] || Stockland_Money::minor( Stockland_Money::subtract( $uc, $pi['unit_cost'] ) ) !== 0 || abs( $tax_rate - (float) $pi['tax_rate'] ) > 0.00001 ) {
						throw new Exception( 'Supplier invoice SKU, unit cost and tax must match the approved PO. Request an Owner-reviewed PO amendment for price/tax differences before posting.' );
					}
				}
				$line_sub_total   = Stockland_Money::add( $line_sub_total, $ls );
				$line_tax_total   = Stockland_Money::add( $line_tax_total, $lt );
				$invoice_line_sku = sanitize_text_field( $li['sku'] ?? ( $poid ? $po_item_ids[ $po_item_id ]['sku'] : '' ) );
				if ( false === Stockland_DB::insert_record(
					Stockland_DB::table( 'supplier_invoice_items' ),
					array(
						'invoice_id'    => $id,
						'po_item_id'    => $po_item_id ? $po_item_id : null,
						'sku'           => $invoice_line_sku ? $invoice_line_sku : null,
						'description'   => $desc,
						'quantity'      => $q,
						'unit_cost'     => $uc,
						'tax_rate'      => (float) ( $li['tax_rate'] ?? 0 ),
						'line_subtotal' => $ls,
						'line_tax'      => $lt,
						'line_total'    => Stockland_Money::add( $ls, $lt ),
					)
				) ) {
					throw new Exception( 'Supplier invoice line failed' );
				}
			}
			if ( abs( Stockland_Money::minor( Stockland_Money::subtract( $line_sub_total, $sub ) ) ) > 0 || abs( Stockland_Money::minor( Stockland_Money::subtract( $line_tax_total, $tax ) ) ) > 0 ) {
				throw new Exception( 'Supplier invoice lines do not equal invoice subtotal/tax.' );
			}
			$journal = null;
			if ( 'POSTED' === $invoice_status ) {
				if ( ! $poid ) {
					throw new Exception( 'A supplier invoice cannot post to GL without a PO-linked, Owner-approved Central Warehouse receipt.' );
				}
				// Goods receiving has already posted Dr Inventory, Dr input VAT, Cr GRNI (2010).
				// Supplier invoice approval must clear GRNI for the complete invoice total against AP.
				// Debiting input VAT again here would double-count VAT and leave GRNI unreconciled.
				$journal = Stockland_Accounting::post_journal(
					$date,
					'SUPPLIER_INVOICE',
					(string) $id,
					'Supplier invoice ' . $invoice,
					array(
						array(
							'account_code' => '2010',
							'debit'        => $total,
							'credit'       => 0,
							'supplier_id'  => $sid,
						),
						array(
							'account_code' => '2000',
							'debit'        => 0,
							'credit'       => $total,
							'supplier_id'  => $sid,
						),
					),
					get_current_user_id()
				);
				if ( ! $journal ) {
					throw new Exception( 'AP/GRNI clearing journal failed' );
				}
			}
			if ( $poid && 'POSTED' === $invoice_status ) {
				$wpdb->last_error         = '';
				$remaining_unreceived_raw = Stockland_DB::read_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(quantity_ordered-quantity_received,0)),0) FROM %i WHERE po_id=%d', Stockland_DB::table( 'purchase_order_items' ), $poid ) );
				if ( null === $remaining_unreceived_raw || $wpdb->last_error ) {
					throw new Exception( 'Could not verify unreceived purchase quantity; PO status was not changed.' );
				}$remaining_unreceived    = (float) $remaining_unreceived_raw;
				$wpdb->last_error         = '';
				$remaining_uninvoiced_raw = Stockland_DB::read_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(poi.quantity_received-COALESCE((SELECT SUM(sii.quantity) FROM %i sii INNER JOIN %i si ON si.id=sii.invoice_id WHERE sii.po_item_id=poi.id AND si.po_id=poi.po_id AND si.status=\'POSTED\'),0),0)),0) FROM %i poi WHERE poi.po_id=%d', Stockland_DB::table( 'supplier_invoice_items' ), Stockland_DB::table( 'supplier_invoices' ), Stockland_DB::table( 'purchase_order_items' ), $poid ) );
				if ( null === $remaining_uninvoiced_raw || $wpdb->last_error ) {
					throw new Exception( 'Could not verify received-but-uninvoiced purchase quantity; PO status was not changed.' );
				}$remaining_uninvoiced = (float) $remaining_uninvoiced_raw;
				if ( $remaining_unreceived <= 0.00001 && $remaining_uninvoiced <= 0.00001 && in_array( $po['status'], array( 'RECEIVED', 'CLOSED' ), true ) ) {
					if ( false === Stockland_DB::update_record( Stockland_DB::table( 'purchase_orders' ), array( 'status' => 'CLOSED' ), array( 'id' => $poid ) ) ) {
						throw new Exception( 'Could not close fully received and invoiced purchase order.' );
					}
				} elseif ( 'CLOSED' === $po['status'] && $remaining_uninvoiced > 0.00001 ) {
					$reopened_status = $remaining_unreceived > 0.00001 ? 'PARTIALLY_RECEIVED' : 'RECEIVED';
					if ( false === Stockland_DB::update_record( Stockland_DB::table( 'purchase_orders' ), array( 'status' => $reopened_status ), array( 'id' => $poid ) ) ) {
						throw new Exception( 'Could not reopen legacy closed PO with uninvoiced received balance.' );
					}
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'POSTED' === $invoice_status ? 'SUPPLIER_INVOICE_POST' : 'SUPPLIER_INVOICE_SUBMIT', null, $total, null, $invoice_status, 'Supplier invoice ' . $invoice . ' linked to Owner-approved PO ' . $poid, 'AP' . $id ) ) {
				throw new Exception( 'Supplier invoice audit event failed.' );
			}
			if ( 'PENDING' === $invoice_status ) {
				if ( ! Stockland_Notifications::push_role( 'owner', null, 'supplier_invoice_approval', 'Supplier invoice awaiting Owner approval', $invoice . ' · ETB ' . number_format( (float) $total, 2, '.', '' ) . ' linked to PO ' . $poid . '.', 'supplier_invoice', $id, 'action' ) ) {
					throw new Exception( 'Owner notification could not be queued; invoice was not committed.' );
				}if ( ! Stockland_Notifications::push_role( 'management', null, 'supplier_invoice_submitted', 'Supplier invoice submitted', $invoice . ' is waiting for Owner approval.', 'supplier_invoice', $id, 'info' ) ) {
					throw new Exception( 'Management notification could not be queued; invoice was not committed.' );
				}
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Supplier invoice commit failed.' );
			}return rest_ensure_response(
				array(
					'id'         => $id,
					'total'      => $total,
					'status'     => $invoice_status,
					'journal_id' => $journal,
					'balance'    => Stockland_Accounting::supplier_balance( $sid ),
				)
			);
		} catch ( Throwable $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'invoice_failed', $x->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Lists customer invoices authorized for the current user.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function customer_invoices( WP_REST_Request $r ) {
		global $wpdb;
		$rows = Stockland_DB::read_results(
			$wpdb->prepare(
				'SELECT i.*,c.customer_name,l.location_name,
				COALESCE(ar.allocated_amount,0) AS allocated_amount,
				GREATEST(i.total_amount-COALESCE(ar.allocated_amount,0),0) AS outstanding_amount
				FROM %i i
				LEFT JOIN (
					SELECT a.invoice_id,SUM(a.amount) AS allocated_amount
					FROM %i a
					INNER JOIN %i pay ON pay.id=a.payment_id
					WHERE a.invoice_type=\'AR\' AND pay.status IN (\'PENDING\',\'POSTED\')
					GROUP BY a.invoice_id
				) ar ON ar.invoice_id=i.id
				JOIN %i c ON c.id=i.customer_id
				LEFT JOIN %i l ON l.id=i.location_id
				ORDER BY i.id DESC LIMIT 500',
				Stockland_DB::table( 'customer_invoices' ),
				Stockland_DB::table( 'payment_allocations' ),
				Stockland_DB::table( 'payments' ),
				Stockland_DB::table( 'customers' ),
				Stockland_DB::table( 'locations' )
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}

	/**
	 * Inserts a customer payment into the active database transaction.
	 *
	 * @param mixed $p Validated payment, invoice, or party values.
	 * @param int   $user_id WordPress user ID associated with the transaction.
	 * @param mixed $invoice_total Total amount of the invoice.
	 * @return int|WP_Error|false The payment identifier, or an error/failure result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	private function insert_customer_payment_in_transaction( $p, $user_id, $invoice_total = 0 ) {
		global $wpdb;
		$cid    = (int) ( $p['customer_id'] ?? 0 );
		$amount = $this->money( $p['amount'] ?? $p['payment_amount'] ?? 0 );
		if ( ! $cid || $amount <= 0 || ( $invoice_total > 0 && $amount > $invoice_total + 0.01 ) ) {
			throw new Exception( 'Invalid POS payment amount.' );
		}
		$date   = $this->date( $p['payment_date'] ?? null );
		$method = strtoupper( sanitize_text_field( $p['method'] ?? $p['payment_method'] ?? 'CASH' ) );
		$asset  = 'BANK' === $method ? '1010' : '1000';
		$num    = $this->next_number( 'PAY-', $date );
		if ( false === Stockland_DB::insert_record(
			Stockland_DB::table( 'payments' ),
			array(
				'payment_number' => $num,
				'payment_date'   => $date,
				'payment_type'   => 'CUSTOMER',
				'customer_id'    => $cid,
				'amount'         => $amount,
				'method'         => $method,
				'reference'      => sanitize_text_field( $p['reference'] ?? '' ),
				'status'         => 'POSTED',
				'created_by'     => $user_id,
			)
		) ) {
			throw new Exception( 'POS payment failed' );
		}
		$id      = (int) $wpdb->insert_id;
		$journal = Stockland_Accounting::post_journal(
			$date,
			'PAYMENT',
			(string) $id,
			'POS payment ' . $num,
			array(
				array(
					'account_code' => $asset,
					'debit'        => $amount,
					'credit'       => 0,
					'customer_id'  => $cid,
				),
				array(
					'account_code' => '1100',
					'debit'        => 0,
					'credit'       => $amount,
					'customer_id'  => $cid,
				),
			),
			$user_id
		);
		if ( ! $journal ) {
			throw new Exception( 'POS payment journal failed.' );
		}
		if ( ! $this->save_allocations( $id, 'CUSTOMER', $cid, $p['allocations'] ?? array(), $amount ) ) {
			throw new Exception( 'Invalid payment allocation.' );
		}
		return array(
			'payment_number' => $num,
			'journal_id'     => $journal,
			'amount'         => $amount,
		);
	}

	/**
	 * Creates a customer invoice from validated source data.
	 *
	 * @param mixed $p Validated payment, invoice, or party values.
	 * @param int   $user_id WordPress user ID associated with the transaction.
	 * @param mixed $source_type Value for the source type argument.
	 * @param mixed $source_id Value for the source id argument.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	private function create_customer_invoice( $p, $user_id, $source_type = 'MANUAL', $source_id = null ) {
		global $wpdb;
		$cid = (int) ( $p['customer_id'] ?? 0 );
		if ( ! $cid ) {
			return array( 'error' => new WP_Error( 'customer_required', 'customer_id is required.', array( 'status' => 400 ) ) );
		}
		$customer = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d AND is_active=1', Stockland_DB::table( 'customers' ), $cid ), ARRAY_A );
		if ( ! $customer ) {
			return array( 'error' => new WP_Error( 'customer_not_found', 'Customer not found.', array( 'status' => 404 ) ) );
		}
		$lines = is_array( $p['lines'] ?? null ) ? $p['lines'] : array();
		if ( ! $lines ) {
			return array( 'error' => new WP_Error( 'lines_required', 'At least one invoice line is required.', array( 'status' => 400 ) ) );
		}
		$vat_rate = (float) Stockland_DB::get_vat_rate();
		$sub      = 0;
		$tax      = 0;
		$cogs     = 0;
		$clean    = array();
		$location = $this->location( $p['location_id'] ?? null );
		foreach ( $lines as $line ) {
			$sku   = sanitize_text_field( $line['sku'] ?? '' );
			$q     = (float) ( $line['quantity'] ?? 0 );
			$price = $this->money( $line['unit_price'] ?? 0 );
			$mode  = sanitize_text_field( $line['vat_mode'] ?? 'VAT_INCLUDED' );
			$prod  = Stockland_DB::get_product( $sku );
			if ( ! $prod || ( $prod['product_type'] ?? 'SIMPLE' ) === 'STYLE' || $q <= 0 || $price < 0 ) {
				return array( 'error' => new WP_Error( 'invalid_line', 'Invalid invoice SKU, quantity or price.', array( 'status' => 400 ) ) );
			}
			$gross = Stockland_Money::multiply( $q, $price );
			if ( 'WITHOUT_VAT' === $mode ) {
				$ls = $gross;
				$lt = Stockland_Money::percentage( $ls, $vat_rate );
			} else {
				$lt = Stockland_Money::inclusive_tax( $gross, $vat_rate );
				$ls = Stockland_Money::subtract( $gross, $lt );}
			$sub     = Stockland_Money::add( $sub, $ls );
			$tax     = Stockland_Money::add( $tax, $lt );
			$clean[] = array(
				'sku'           => $sku,
				'description'   => $prod['product_name'],
				'quantity'      => $q,
				'unit_price'    => $price,
				'tax_rate'      => $vat_rate,
				'line_subtotal' => $ls,
				'line_tax'      => $lt,
				'line_total'    => Stockland_Money::add( $ls, $lt ),
				'product'       => $prod,
			);
		}
		$date = $this->date( $p['invoice_date'] ?? null );
		$due  = $this->date( $p['due_date'] ?? null, null );
		if ( ! $date ) {
			return array( 'error' => new WP_Error( 'invalid_date', 'invoice_date is invalid.', array( 'status' => 400 ) ) );
		}
		$total = Stockland_Money::add( $sub, $tax );
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return array( 'error' => new WP_Error( 'transaction_failed', 'Could not start customer invoice transaction.', array( 'status' => 500 ) ) );
		}
		try {
			$invoice = $this->next_number( 'INV-', $date );
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'customer_invoices' ),
				array(
					'invoice_number' => $invoice,
					'customer_id'    => $cid,
					'invoice_date'   => $date,
					'due_date'       => $due,
					'location_id'    => $location,
					'subtotal'       => $sub,
					'tax_amount'     => $tax,
					'total_amount'   => $total,
					'status'         => 'POSTED',
					'notes'          => sanitize_text_field( $p['notes'] ?? '' ),
					'source_type'    => $source_type,
					'source_id'      => $source_id ? $source_id : $invoice,
					'created_by'     => $user_id,
				)
			) ) {
				throw new Exception( 'Invoice header failed' );
			}
			$iid = (int) $wpdb->insert_id;
			foreach ( $clean as $line ) {
				if ( false === Stockland_DB::insert_record(
					Stockland_DB::table( 'invoice_items' ),
					array(
						'invoice_id'    => $iid,
						'sku'           => $line['sku'],
						'description'   => $line['description'],
						'quantity'      => $line['quantity'],
						'unit_price'    => $line['unit_price'],
						'tax_rate'      => $line['tax_rate'],
						'line_subtotal' => $line['line_subtotal'],
						'line_tax'      => $line['line_tax'],
						'line_total'    => $line['line_total'],
					)
				) ) {
					throw new Exception( 'Invoice line failed' );
				}
			}
			$journal_lines = array(
				array(
					'account_code' => '1100',
					'debit'        => $total,
					'credit'       => 0,
					'customer_id'  => $cid,
					'location_id'  => $location,
				),
				array(
					'account_code' => '4000',
					'debit'        => 0,
					'credit'       => $sub,
					'customer_id'  => $cid,
					'location_id'  => $location,
				),
				array(
					'account_code' => '2100',
					'debit'        => 0,
					'credit'       => $tax,
					'customer_id'  => $cid,
					'location_id'  => $location,
				),
			);
			$journal       = Stockland_Accounting::post_journal( $date, 'CUSTOMER_INVOICE', (string) $iid, 'Customer invoice ' . $invoice, $journal_lines, $user_id );
			if ( ! $journal ) {
				throw new Exception( 'Accounts receivable journal failed' );
			}
			$payment_result = null;
			if ( 'POS' === $source_type && $this->money( $p['payment_amount'] ?? 0 ) > 0 ) {
				$payment_result = $this->insert_customer_payment_in_transaction(
					array(
						'customer_id'  => $cid,
						'amount'       => $this->money( $p['payment_amount'] ),
						'method'       => $p['payment_method'] ?? 'CASH',
						'payment_date' => $date,
						'reference'    => 'POS ' . $invoice,
					),
					$user_id,
					$total
				);}
			if ( ! Stockland_Audit::log( $user_id, $location, 'CUSTOMER_INVOICE', null, $total, null, 'POSTED', null, $invoice ) ) {
				throw new Exception( 'Audit failed' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Customer invoice commit failed.' );
			}return array(
				'invoice_id'     => $iid,
				'invoice_number' => $invoice,
				'total'          => $total,
				'journal_id'     => $journal,
				'payment'        => $payment_result,
				'balance'        => Stockland_Accounting::customer_balance( $cid ),
			);
		} catch ( Exception $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return array( 'error' => new WP_Error( 'invoice_failed', $x->getMessage(), array( 'status' => 500 ) ) );}
	}

	/**
	 * Creates a customer invoice through the REST workflow.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function customer_invoice_create( WP_REST_Request $r ) {
		$p      = $r->get_json_params();
		$result = $this->create_customer_invoice( $p, get_current_user_id(), 'MANUAL', null );
		if ( isset( $result['error'] ) ) {
			return $result['error'];
		}return rest_ensure_response( $result );
	}


	/**
	 * Resolves the configured walk-in customer record.
	 *
	 * @return array|false The walk-in customer record, or false when unavailable.
	 */
	private function walkin_customer() {
		global $wpdb;
		$id = (int) Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE customer_code=%s LIMIT 1', Stockland_DB::table( 'customers' ), 'WALK-IN' ) );
		return $id;
	}

	/**
	 * Looks up product master information for a barcode.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function barcode( WP_REST_Request $r ) {
		$code = sanitize_text_field( $r['code'] );
		$p    = Stockland_DB::get_product_by_identifier( $code );
		if ( ! $p ) {
			return new WP_Error( 'not_found', 'Barcode / GTIN not found.', array( 'status' => 404 ) );
		}

		// Barcode lookup feeds POS. Never expose accounting-only fields to
		// sales employees even though the product master contains them.
		if ( ! current_user_can( 'stockland_view_cost_and_profit' ) ) {
			unset( $p['purchase_cost'] );
		}
		return rest_ensure_response( $p );
	}

	/**
	 * Lists expense records visible to the current user.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function expenses( WP_REST_Request $r ) {
		global $wpdb;
		$table        = Stockland_DB::table( 'expenses' );
		$status       = strtoupper( sanitize_text_field( (string) $r->get_param( 'status' ) ) );
		$has_status   = in_array( $status, array( 'PENDING', 'POSTED', 'REJECTED' ), true );
		$company_wide = Stockland_Authority::company_scope();
		$current_role = Stockland_Authority::role();
		$location_ids = array();
		if ( ! $company_wide ) {
			if ( 'branch_manager' === $current_role ) {
				$branch       = Stockland_Authority::branch_id();
				$location_ids = $branch ? array( (int) $branch ) : array();
			} elseif ( 'warehouse_manager' === $current_role ) {
				$central_id   = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
				$location_ids = $central_id > 0 ? array( $central_id ) : array();
			} else {
				$location_ids = array_values( array_unique( array_map( 'intval', Stockland_Authority::location_ids() ) ) );
				if ( ! $location_ids ) {
					$branch = Stockland_Authority::branch_id();
					if ( $branch ) {
						$location_ids = array( (int) $branch );
					}
				}
			}
			if ( ! $location_ids ) {
				return rest_ensure_response( array() );
			}
		}
		$location_filter = implode( ',', array_map( 'intval', $location_ids ) );
		$rows            = Stockland_DB::read_results(
			$wpdb->prepare(
				'SELECT e.*,l.location_name,wu.display_name AS created_by_name
				FROM %i e
				LEFT JOIN %i l ON l.id=e.location_id
				LEFT JOIN %i wu ON wu.ID=e.created_by
				WHERE (%d = 0 OR e.status = %s)
					AND (%d = 0 OR FIND_IN_SET(e.location_id, %s))
				ORDER BY e.expense_date DESC,e.id DESC LIMIT 500',
				$table,
				Stockland_DB::table( 'locations' ),
				$wpdb->users,
				$has_status ? 1 : 0,
				$has_status ? $status : '',
				$company_wide ? 0 : 1,
				$location_filter
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}

	/**
	 * Creates an expense record within the configured preparation and approval workflow.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function expense_create( WP_REST_Request $r ) {
		$e = $this->require_accounting_cap( 'stockland_manage_expenses' );
		if ( true !== $e ) {
			return $e;
		}
		if ( ! Stockland_Authority::can_submit_expenses() ) {
			return new WP_Error( 'forbidden', 'Expenses are limited to the active Owner, Management, assigned Branch Manager, or Central Warehouse Manager workflow.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p      = $r->get_json_params();
		$uid    = get_current_user_id();
		$role   = Stockland_Authority::role( $uid );
		$amount = $this->money( $p['amount'] ?? 0 );
		$tax    = $this->money( $p['tax_amount'] ?? 0 );
		if ( $amount <= 0 || $tax < 0 ) {
			return new WP_Error( 'invalid', 'Expense amount must be positive and VAT cannot be negative.', array( 'status' => 400 ) );
		}
		$category    = sanitize_text_field( $p['category'] ?? '' );
		$description = sanitize_text_field( $p['description'] ?? '' );
		if ( '' === $category || '' === $description ) {
			return new WP_Error( 'missing', 'Expense category and description are required.', array( 'status' => 400 ) );
		}
		$method = strtoupper( sanitize_text_field( $p['payment_method'] ?? 'CASH' ) );
		if ( ! in_array( $method, array( 'CASH', 'BANK' ), true ) ) {
			return new WP_Error( 'invalid_method', 'Payment method must be CASH or BANK.', array( 'status' => 400 ) );
		}
		$date = $this->date( $p['expense_date'] ?? null );
		if ( ! $date ) {
			return new WP_Error( 'invalid_date', 'expense_date is invalid.', array( 'status' => 400 ) );
		}
		$profile           = Stockland_Authority::profile( $uid );
		$branch_role       = 'branch_manager' === $role;
		$warehouse_role    = 'warehouse_manager' === $role;
		$corporate_expense = in_array( $role, array( 'owner', 'management' ), true );
		$central_id        = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		if ( $branch_role ) {
			$location_id = (int) ( $profile['branch_location_id'] ?? 0 );
			if ( ! $location_id || $location_id === $central_id ) {
				return new WP_Error( 'branch_scope_missing', 'The active Branch Manager must have an assigned branch location; Central Warehouse expenses use the Warehouse Manager workflow.', array( 'status' => 403 ) );
			}
		} elseif ( $warehouse_role ) {
			$location_id = $central_id;
			if ( $location_id <= 0 ) {
				return new WP_Error( 'central_warehouse_missing', 'Central Warehouse is not configured; expense posting is blocked.', array( 'status' => 409 ) );
			}
		} elseif ( $corporate_expense ) {
			// Corporate/Owner expenses have no branch attribution; ignore client-supplied location IDs.
			$location_id = 0;
		} else {
			return new WP_Error( 'forbidden', 'This ERP role cannot create expenses.', array( 'status' => 403 ) );
		}
		if ( $corporate_expense ) {
			$location = array(
				'id'            => 0,
				'location_name' => 'owner' === $role ? 'Owner / Corporate' : 'Management / Corporate',
				'is_active'     => 1,
			);
		} else {
			$location = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,location_name,is_active FROM %i WHERE id=%d', Stockland_DB::table( 'locations' ), $location_id ), ARRAY_A );
			if ( ! $location || empty( $location['is_active'] ) ) {
				return new WP_Error( 'invalid_location', 'The assigned active branch/warehouse location was not found.', array( 'status' => 400 ) );
			}
			if ( ! Stockland_Authority::can_access_location( $location_id ) ) {
				return new WP_Error( 'location_scope', 'This expense is outside your assigned location scope.', array( 'status' => 403 ) );
			}
		}
		$request_key = sanitize_text_field( (string) ( $p['request_key'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,80}$/', $request_key ) ) {
			return new WP_Error( 'idempotency_key_required', 'A stable request_key is required. Retry a failed submission using the same form key.', array( 'status' => 400 ) );
		}
		$fingerprint  = substr(
			hash(
				'sha256',
				wp_json_encode(
					array(
						'date'        => $date,
						'location_id' => $location_id,
						'category'    => $category,
						'description' => $description,
						'amount'      => $amount,
						'tax'         => $tax,
						'method'      => $method,
						'reference'   => sanitize_text_field( $p['reference'] ?? '' ),
					)
				)
			),
			0,
			32
		);
		$key_hash     = substr( hash( 'sha256', strtolower( $request_key ) ), 0, 20 );
		$marker       = '[[SLEXP:' . $key_hash . ':' . $fingerprint . ']]';
		$request_lock = Stockland_DB::acquire_named_lock( 'expense_create_' . $key_hash, 10 );
		if ( empty( $request_lock['ok'] ) ) {
			return new WP_Error( 'busy', 'This expense submission is already being processed. Retry using the same form key.', array( 'status' => 409 ) );
		}
		$pattern  = $wpdb->esc_like( '[[SLEXP:' . $key_hash . ':' ) . '%';
		$existing = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,expense_number,status,reference,amount FROM %i WHERE reference LIKE %s ORDER BY id DESC LIMIT 1', Stockland_DB::table( 'expenses' ), $pattern ), ARRAY_A );
		if ( $existing ) {
			if ( ! preg_match( '/^\\[\\[SLEXP:([a-f0-9]{20}):([a-f0-9]{32})\\]\\]/', (string) $existing['reference'], $m ) || ! hash_equals( $key_hash, $m[1] ) || ! hash_equals( $fingerprint, $m[2] ) ) {
				Stockland_DB::release_named_lock( $request_lock );
				return new WP_Error( 'idempotency_conflict', 'This expense request key was already used for different details.', array( 'status' => 409 ) );}
			$journal = Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_type=\'EXPENSE\' AND source_id=%s AND status=\'POSTED\' LIMIT 1', Stockland_DB::table( 'gl_journals' ), (string) $existing['id'] ) );
			if ( 'POSTED' === $existing['status'] && ! $journal ) {
				Stockland_DB::release_named_lock( $request_lock );
				return new WP_Error( 'expense_integrity_error', 'The prior posted expense has no verifiable GL journal. Stop and reconcile before retrying.', array( 'status' => 500 ) );}
			Stockland_DB::release_named_lock( $request_lock );
			return rest_ensure_response(
				array(
					'id'             => (int) $existing['id'],
					'expense_number' => $existing['expense_number'],
					'status'         => $existing['status'],
					'journal_id'     => $journal ? (int) $journal : null,
					'replayed'       => true,
				)
			);
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_named_lock( $request_lock );
			return new WP_Error( 'transaction_failed', 'Could not start expense transaction.', array( 'status' => 500 ) );}
		try {
			$num            = $this->next_number( 'EXP-', $date );
			$direct_post    = 'owner' === $role;
			$status         = $direct_post ? 'POSTED' : 'PENDING';
			$user_reference = sanitize_text_field( $p['reference'] ?? '' );
			$reference      = substr( $marker . ( '' !== $user_reference ? ' ' . $user_reference : '' ), 0, 100 );
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'expenses' ),
				array(
					'expense_number' => $num,
					'expense_date'   => $date,
					'location_id'    => $location_id > 0 ? $location_id : null,
					'category'       => $category,
					'description'    => $description,
					'amount'         => $amount,
					'tax_amount'     => $tax,
					'payment_method' => $method,
					'reference'      => $reference,
					'status'         => $status,
					'created_by'     => $uid,
				)
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Expense submission failed.' );
			}
			$id      = (int) $wpdb->insert_id;
			$journal = null;
			if ( $direct_post ) {
				$journal = Stockland_Accounting::post_journal(
					$date,
					'EXPENSE',
					(string) $id,
					'Expense ' . $num,
					array(
						array(
							'account_code' => '6000',
							'debit'        => $amount,
							'credit'       => 0,
							'location_id'  => $location_id > 0 ? $location_id : null,
						),
						array(
							'account_code' => '1300',
							'debit'        => $tax,
							'credit'       => 0,
							'location_id'  => $location_id > 0 ? $location_id : null,
						),
						array(
							'account_code' => 'BANK' === $method ? '1010' : '1000',
							'debit'        => 0,
							'credit'       => Stockland_Money::add( $amount, $tax ),
							'location_id'  => $location_id > 0 ? $location_id : null,
						),
					),
					$uid
				);
				if ( ! $journal ) {
					throw new Exception( 'Expense GL journal failed; no expense was committed.' );
				}
			}
			$event = $direct_post ? 'CORPORATE_EXPENSE_POST' : ( $corporate_expense ? 'CORPORATE_EXPENSE_SUBMIT' : 'LOCATION_EXPENSE_SUBMIT' );
			if ( ! Stockland_Audit::log( $uid, $location_id > 0 ? $location_id : null, $event, null, $amount, null, $status, $description, $num ) ) {
				throw new Exception( 'Expense audit event failed; submission was rolled back.' );
			}
			if ( ! $direct_post ) {
				$final_role       = $corporate_expense ? 'owner' : ( $branch_role ? 'branch_manager' : 'warehouse_manager' );
				$approval_title   = $corporate_expense ? 'Corporate expense awaiting Owner approval' : 'Location expense awaiting final approval';
				$approval_message = $num . ' · ' . $location['location_name'] . ' · ' . $category . ' · ETB ' . number_format( (float) $amount + (float) $tax, 2, '.', '' ) . ' submitted by ' . wp_get_current_user()->display_name . '.';
				if ( ! Stockland_Notifications::push_role( $final_role, $location_id > 0 ? $location_id : null, 'expense_approval', $approval_title, $approval_message, 'expense', $id, 'action' ) ) {
					throw new Exception( 'Expense-authority notification could not be queued; expense was not committed.' );
				}
				if ( ! $corporate_expense ) {
					Stockland_Notifications::push_role( 'management', null, 'expense_submitted', 'Location expense submitted', $num . ' awaits final approval for ' . $location['location_name'] . '.', 'expense', $id, 'info' );
				}
			} else {
				$report_role = 'management';
				Stockland_Notifications::push_role( $report_role, null, 'expense_posted', 'Owner corporate expense posted', $num . ' posted for ' . $location['location_name'] . '.', 'expense', $id, 'info' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Expense commit failed.' );
			}Stockland_DB::release_named_lock( $request_lock );
			return rest_ensure_response(
				array(
					'id'             => $id,
					'expense_number' => $num,
					'status'         => $status,
					'journal_id'     => $journal ? (int) $journal : null,
					'replayed'       => false,
				)
			);
		} catch ( Throwable $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_named_lock( $request_lock );
			return new WP_Error( 'expense_failed', $x->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Applies an authorized expense state transition.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function expense_status( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_approve_expenses() ) {
			return new WP_Error( 'forbidden', 'This ERP role cannot make an expense decision.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$id     = (int) $r['id'];
		$p      = $r->get_json_params();
		$action = strtoupper( sanitize_text_field( $p['action'] ?? '' ) );
		$note   = sanitize_textarea_field( $p['note'] ?? '' );
		if ( ! in_array( $action, array( 'APPROVE', 'REJECT' ), true ) ) {
			return new WP_Error( 'invalid_action', 'Action must be APPROVE or REJECT.', array( 'status' => 400 ) );
		}
		if ( 'REJECT' === $action && strlen( trim( $note ) ) < 5 ) {
			return new WP_Error( 'rejection_reason_required', 'Provide a meaningful rejection reason of at least five characters.', array( 'status' => 400 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start expense approval.', array( 'status' => 500 ) );
		}
		try {
			$t   = Stockland_DB::table( 'expenses' );
			$row = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $t, $id ), ARRAY_A );
			if ( ! $row ) {
				throw new Exception( 'Expense not found.' );
			}if ( 'PENDING' !== $row['status'] ) {
				throw new Exception( 'Only PENDING expenses may be approved/rejected.' );
			}
			// Separation of duties: the expense creator may never decide the same expense.
			if ( get_current_user_id() === (int) $row['created_by'] ) {
				throw new Exception( 'Self-approval is prohibited. A different authorized user must decide this expense.' );
			}
			$uid              = get_current_user_id();
			$approver_role    = Stockland_Authority::role( $uid );
			$creator_role     = Stockland_Authority::role( (int) $row['created_by'] );
			$expense_location = (int) ( $row['location_id'] ?? 0 );
			$central_id       = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
			$is_corporate     = $expense_location <= 0;
			$journal_location = $expense_location > 0 ? $expense_location : null;
			if ( 'owner' === $approver_role ) {
				if ( ! $is_corporate || 'management' !== $creator_role ) {
					throw new Exception( 'Owner approval is limited to a Management corporate expense submitted by a different active ERP user.' );
				}
				$authority_label = 'Owner';
			} elseif ( 'branch_manager' === $approver_role ) {
				$assigned_branch = Stockland_Authority::branch_id( $uid );
				if ( ! $assigned_branch || $expense_location !== $assigned_branch || $expense_location === $central_id || ! Stockland_Authority::can_access_location( $expense_location, $uid ) ) {
					throw new Exception( 'A Branch Manager may decide expenses only for their assigned branch, never another branch or Central Warehouse.' );
				}
				// The current SOP authority applies to the expense location. This also
				// lets the assigned manager resolve PENDING branch records created under
				// the former Management-submission policy without rewriting their history.
				$authority_label = 'Branch Manager';
			} elseif ( 'warehouse_manager' === $approver_role ) {
				if ( $central_id <= 0 || $expense_location !== $central_id || ! Stockland_Authority::can_access_location( $expense_location, $uid ) ) {
					throw new Exception( 'A Warehouse Manager may decide expenses only for the assigned Central Warehouse.' );
				}
				$authority_label = 'Central Warehouse Manager';
			} else {
				throw new Exception( 'Only the Owner for Management corporate expenses or the assigned location manager for location expenses may decide this record.' );
			}
			$journal = null;
			$next    = 'APPROVE' === $action ? 'POSTED' : 'REJECTED';
			if ( 'APPROVE' === $action ) {
				$amount = $this->money( $row['amount'] );
				$tax    = $this->money( $row['tax_amount'] );
				$method = strtoupper( (string) $row['payment_method'] );
				if ( $amount <= 0 || $tax < 0 || ! in_array( $method, array( 'CASH', 'BANK' ), true ) ) {
					throw new Exception( 'Stored expense amounts or payment method failed validation.' );
				}
				$journal = Stockland_Accounting::post_journal(
					$row['expense_date'],
					'EXPENSE',
					(string) $id,
					'Expense ' . $row['expense_number'],
					array(
						array(
							'account_code' => '6000',
							'debit'        => $amount,
							'credit'       => 0,
							'location_id'  => $journal_location,
						),
						array(
							'account_code' => '1300',
							'debit'        => $tax,
							'credit'       => 0,
							'location_id'  => $journal_location,
						),
						array(
							'account_code' => 'BANK' === $method ? '1010' : '1000',
							'debit'        => 0,
							'credit'       => Stockland_Money::add( $amount, $tax ),
							'location_id'  => $journal_location,
						),
					),
					get_current_user_id()
				);
				if ( ! $journal ) {
					throw new Exception( 'Expense GL journal failed; approval was rolled back.' );
				}
			}
			$reference = (string) ( $row['reference'] ?? '' );
			if ( 'REJECT' === $action ) {
				$reference = substr( $reference . ' [' . $authority_label . ' rejection: ' . trim( $note ) . ']', 0, 100 );
			}
			if ( false === Stockland_DB::update_record(
				$t,
				array(
					'status'    => $next,
					'reference' => $reference,
				),
				array( 'id' => $id )
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Expense status update failed.' );
			}
			$event = 'owner' === $approver_role
				? ( 'APPROVE' === $action ? 'CORPORATE_EXPENSE_OWNER_APPROVE' : 'CORPORATE_EXPENSE_OWNER_REJECT' )
				: ( 'APPROVE' === $action ? 'LOCATION_EXPENSE_APPROVE' : 'LOCATION_EXPENSE_REJECT' );
			if ( ! Stockland_Audit::log( get_current_user_id(), $journal_location, $event, null, $row['amount'], 'PENDING', $next, 'REJECT' === $action ? $note : 'Final location authority approved; GL journal ' . $journal, $row['expense_number'] ) ) {
				throw new Exception( 'Expense decision could not be audited.' );
			}
			$title   = 'APPROVE' === $action ? 'Expense approved' : 'Expense rejected';
			$message = $row['expense_number'] . ' was ' . $next . ' by ' . $authority_label . ' final authority.' . ( 'REJECT' === $action ? ' Reason: ' . $note : ' GL journal: ' . $journal . '.' );
			if ( ! Stockland_Notifications::push_user( (int) $row['created_by'], 'expense_decision', $title, $message, 'expense', $id, 'REJECT' === $action ? 'warning' : 'info' ) ) {
				throw new Exception( 'Submitter notification could not be queued; decision was rolled back.' );
			}
			if ( ! Stockland_Notifications::push_role( 'management', null, 'expense_decision', $title, $message, 'expense', $id, 'REJECT' === $action ? 'warning' : 'info' ) ) {
				throw new Exception( 'Management notification could not be queued; decision was rolled back.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Expense approval commit failed.' );
			}
			return rest_ensure_response(
				array(
					'id'         => $id,
					'status'     => $next,
					'journal_id' => $journal ? (int) $journal : null,
				)
			);
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'expense_decision_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Lists payment records available to the current role.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 */
	public function payments( WP_REST_Request $r ) {
		global $wpdb;
		$status     = strtoupper( sanitize_text_field( (string) $r->get_param( 'status' ) ) );
		$has_status = in_array( $status, array( 'PENDING', 'POSTED', 'REJECTED' ), true );
		$rows       = Stockland_DB::read_results(
			$wpdb->prepare(
				'SELECT p.*,s.supplier_name,c.customer_name,u.display_name AS created_by_name
				FROM %i p
				LEFT JOIN %i s ON s.id=p.supplier_id
				LEFT JOIN %i c ON c.id=p.customer_id
				LEFT JOIN %i u ON u.ID=p.created_by
				WHERE (%d = 0 OR p.status = %s)
				ORDER BY p.payment_date DESC,p.id DESC LIMIT 500',
				Stockland_DB::table( 'payments' ),
				Stockland_DB::table( 'suppliers' ),
				Stockland_DB::table( 'customers' ),
				$wpdb->users,
				$has_status ? 1 : 0,
				$has_status ? $status : ''
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}

	/**
	 * Records an internal customer payment and its ledger effects.
	 *
	 * @param mixed $p Validated payment, invoice, or party values.
	 * @param int   $user_id WordPress user ID associated with the transaction.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	private function record_internal_customer_payment( $p, $user_id ) {
		global $wpdb;
		$cid    = (int) ( $p['customer_id'] ?? 0 );
		$amount = $this->money( $p['amount'] ?? 0 );
		if ( ! $cid || $amount <= 0 ) {
			return new WP_Error( 'invalid_payment', 'Invalid POS payment.', array( 'status' => 400 ) );
		}
		$date   = $this->date( $p['payment_date'] ?? null );
		$method = strtoupper( sanitize_text_field( $p['method'] ?? 'CASH' ) );
		if ( ! in_array( $method, array( 'CASH', 'BANK' ), true ) ) {
			return new WP_Error( 'invalid_method', 'Payment method must be CASH or BANK.', array( 'status' => 400 ) );
		}$asset = 'BANK' === $method ? '1010' : '1000';
		if ( ! $date ) {
			return new WP_Error( 'invalid_date', 'payment_date is invalid.', array( 'status' => 400 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start internal payment transaction.', array( 'status' => 500 ) );
		}
		try {
			$num = $this->next_number( 'PAY-', $date );
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'payments' ),
				array(
					'payment_number' => $num,
					'payment_date'   => $date,
					'payment_type'   => 'CUSTOMER',
					'customer_id'    => $cid,
					'amount'         => $amount,
					'method'         => $method,
					'reference'      => sanitize_text_field( $p['reference'] ?? '' ),
					'status'         => 'POSTED',
					'created_by'     => $user_id,
				)
			) ) {
				throw new Exception( 'POS payment failed' );
			}
			$id      = (int) $wpdb->insert_id;
			$journal = Stockland_Accounting::post_journal(
				$date,
				'PAYMENT',
				(string) $id,
				'POS payment ' . $num,
				array(
					array(
						'account_code' => $asset,
						'debit'        => $amount,
						'credit'       => 0,
						'customer_id'  => $cid,
					),
					array(
						'account_code' => '1100',
						'debit'        => 0,
						'credit'       => $amount,
						'customer_id'  => $cid,
					),
				),
				$user_id
			);
			if ( ! $journal ) {
				throw new Exception( 'POS payment journal failed' );
			}if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'POS payment commit failed.' );
			}return rest_ensure_response(
				array(
					'payment_number' => $num,
					'journal_id'     => $journal,
				)
			);
		} catch ( Exception $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payment_failed', $x->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Validates and saves payment allocations against eligible open balances.
	 *
	 * @param int    $payment_id Payment record ID.
	 * @param string $type Payment party/allocation type.
	 * @param int    $party_id Customer or supplier ID.
	 * @param array  $allocations Requested invoice or balance allocations.
	 * @param mixed  $payment_amount Total amount available for allocation.
	 * @return bool|WP_Error Whether allocations were saved, or an error.
	 */
	private function save_allocations( $payment_id, $type, $party_id, $allocations, $payment_amount ) {
		global $wpdb;
		if ( ! is_array( $allocations ) || ! $allocations ) {
			return true;
		}$sum    = '0.00';
		$grouped = array();
		foreach ( $allocations as $a ) {
			$iid = (int) ( $a['invoice_id'] ?? 0 );
			$amt = $this->money( $a['amount'] ?? 0 );
			if ( ! $iid || $amt <= 0 ) {
				return false;
			}
			$key             = 'SUPPLIER' === $type ? 'AP:' . $iid : 'AR:' . $iid;
			$grouped[ $key ] = Stockland_Money::add( $grouped[ $key ] ?? '0.00', $amt );
		}
		foreach ( $grouped as $key => $amt ) {
			list($invoice_type, $iid) = explode( ':', $key, 2 );
			$iid                      = (int) $iid;
			if ( 'SUPPLIER' === $type ) {
				$invoice = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,total_amount FROM %i WHERE id=%d AND supplier_id=%d AND status=\'POSTED\' FOR UPDATE', Stockland_DB::table( 'supplier_invoices' ), $iid, $party_id ), ARRAY_A );
			} else {
				$invoice = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,total_amount FROM %i WHERE id=%d AND customer_id=%d AND status=\'POSTED\' FOR UPDATE', Stockland_DB::table( 'customer_invoices' ), $iid, $party_id ), ARRAY_A );
			}
			if ( ! $invoice ) {
				return false;
			}
			$allocated   = (float) Stockland_DB::read_var( $wpdb->prepare( 'SELECT COALESCE(SUM(a.amount),0) FROM %i a INNER JOIN %i p ON p.id=a.payment_id WHERE a.invoice_type=%s AND a.invoice_id=%d AND p.status IN (\'POSTED\',\'PENDING\') AND a.payment_id<>%d', Stockland_DB::table( 'payment_allocations' ), Stockland_DB::table( 'payments' ), $invoice_type, $iid, (int) $payment_id ) );
			$outstanding = Stockland_Money::subtract( $invoice['total_amount'], $allocated );
			if ( $outstanding < 0 ) {
				$outstanding = 0;
			}
			if ( Stockland_Money::minor( Stockland_Money::subtract( $amt, $outstanding ) ) > 1 ) {
				return false;
			}$sum = Stockland_Money::add( $sum, $amt );
			if ( Stockland_Money::minor( Stockland_Money::subtract( $sum, $payment_amount ) ) > 1 ) {
				return false;
			}
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'payment_allocations' ),
				array(
					'payment_id'   => $payment_id,
					'invoice_type' => $invoice_type,
					'invoice_id'   => $iid,
					'amount'       => $amt,
				)
			) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Creates a payment under the accounting authorization and transaction rules.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return mixed The operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function payment_create( WP_REST_Request $r ) {
		$e = $this->require_accounting_cap( 'stockland_manage_payments' );
		if ( true !== $e ) {
			return $e;
		}
		global $wpdb;
		$p      = $r->get_json_params();
		$type   = strtoupper( sanitize_text_field( $p['payment_type'] ?? '' ) );
		$amount = $this->money( $p['amount'] ?? 0 );
		$sid    = (int) ( $p['supplier_id'] ?? 0 );
		$cid    = (int) ( $p['customer_id'] ?? 0 );
		if ( ! in_array( $type, array( 'SUPPLIER', 'CUSTOMER' ), true ) || $amount <= 0 ) {
			return new WP_Error( 'invalid', 'Choose SUPPLIER or CUSTOMER and provide a positive amount. General operating expenses must use the Owner-approved Expense workflow.', array( 'status' => 400 ) );
		}
		if ( 'SUPPLIER' === $type ) {
			if ( ! Stockland_Authority::can_prepare_finance( 'stockland_manage_payments' ) ) {
				return new WP_Error( 'forbidden', 'Manual finance payments are Management-entered and require separate Owner approval.', array( 'status' => 403 ) );
			}
			if ( ! $sid ) {
				return new WP_Error( 'supplier_required', 'supplier_id is required.', array( 'status' => 400 ) );
			}
		}
		if ( 'CUSTOMER' === $type && ! $cid ) {
			return new WP_Error( 'customer_required', 'customer_id is required.', array( 'status' => 400 ) );
		}
		$date   = $this->date( $p['payment_date'] ?? null );
		$method = strtoupper( sanitize_text_field( $p['method'] ?? 'CASH' ) );
		if ( ! in_array( $method, array( 'CASH', 'BANK' ), true ) ) {
			return new WP_Error( 'invalid_method', 'Payment method must be CASH or BANK.', array( 'status' => 400 ) );
		}$asset = 'BANK' === $method ? '1010' : '1000';
		if ( ! $date ) {
			return new WP_Error( 'invalid_date', 'payment_date is invalid.', array( 'status' => 400 ) );
		}
		$reference           = sanitize_text_field( $p['reference'] ?? '' );
		$notes               = sanitize_text_field( $p['notes'] ?? '' );
		$allocations         = $p['allocations'] ?? array();
		$request_lock        = null;
		$fingerprint         = '';
		$payment_request_key = '';
		$stored_notes        = $notes;
		// Idempotency covers every manual payment type, not only AP. Retrying the same
		// request returns the original result; reusing a key with changed details fails.
		$payment_request_key = strtolower( sanitize_text_field( (string) ( $p['request_key'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-z0-9_-]{16,80}$/', $payment_request_key ) ) {
			return new WP_Error( 'idempotency_key_required', 'A stable request_key is required to prevent duplicate payment/PLEDGER/GL transactions.', array( 'status' => 400 ) );
		}
		$allocation_input = is_array( $allocations ) ? $allocations : array();
		$groups           = array();
		$allocated_total  = '0.00';
		if ( 'SUPPLIER' === $type && ! $sid ) {
			return new WP_Error( 'supplier_required', 'supplier_id is required.', array( 'status' => 400 ) );
		}
		if ( 'SUPPLIER' === $type && ! $allocation_input ) {
			return new WP_Error( 'allocation_required', 'Supplier payments must be allocated to one or more posted supplier invoices.', array( 'status' => 409 ) );
		}
		if ( 'CUSTOMER' === $type && ! $cid ) {
			return new WP_Error( 'customer_required', 'customer_id is required.', array( 'status' => 400 ) );
		}
		foreach ( $allocation_input as $allocation ) {
			$invoice_id = (int) ( $allocation['invoice_id'] ?? 0 );
			$allocated  = $this->money( $allocation['amount'] ?? 0 );
			if ( $invoice_id <= 0 || $allocated <= 0 ) {
				return new WP_Error( 'invalid_allocation', 'Each payment allocation needs a valid invoice and positive amount.', array( 'status' => 400 ) );
			}
			$groups[ $invoice_id ] = Stockland_Money::add( $groups[ $invoice_id ] ?? '0.00', $allocated );
		}
		if ( $groups ) {
			ksort( $groups, SORT_NUMERIC );
			foreach ( $groups as $invoice_id => $allocated ) {
				$canonical[]     = array(
					'invoice_id' => (int) $invoice_id,
					'amount'     => $allocated,
				);
				$allocated_total = Stockland_Money::add( $allocated_total, $allocated );}
		} else {
			$canonical = array();}
		if ( $canonical && Stockland_Money::minor( Stockland_Money::subtract( $allocated_total, $amount ) ) !== 0 ) {
			return new WP_Error( 'allocation_total_mismatch', 'Payment allocation total must equal the payment amount exactly.', array( 'status' => 409 ) );
		}
		if ( 'SUPPLIER' === $type && Stockland_Money::minor( Stockland_Money::subtract( $allocated_total, $amount ) ) !== 0 ) {
			return new WP_Error( 'allocation_total_mismatch', 'Supplier payment allocation total must equal the payment amount exactly.', array( 'status' => 409 ) );
		}
		$fingerprint  = hash(
			'sha256',
			wp_json_encode(
				array(
					'type'        => $type,
					'supplier_id' => $sid,
					'customer_id' => $cid,
					'amount'      => $amount,
					'date'        => $date,
					'method'      => $method,
					'reference'   => $reference,
					'notes'       => $notes,
					'allocations' => $canonical,
				)
			)
		);
		$marker       = '[[SLPAYREQ:' . $payment_request_key . ':' . $fingerprint . ']]';
		$stored_notes = trim( $marker . ' ' . $notes );
		if ( strlen( $stored_notes ) > 500 ) {
			return new WP_Error( 'notes_too_long', 'Payment notes exceed the supported length after the idempotency marker.', array( 'status' => 400 ) );
		}
		$request_lock = Stockland_DB::acquire_named_lock( 'payment_' . $type . '_' . $payment_request_key, 10 );
		if ( empty( $request_lock['ok'] ) ) {
			return new WP_Error( 'busy', 'This payment request is already being processed. Retry the same request key.', array( 'status' => 409 ) );
		}
		$pattern  = '%' . $wpdb->esc_like( '[[SLPAYREQ:' . $payment_request_key . ':' ) . '%';
		$existing = Stockland_DB::read_row( $wpdb->prepare( 'SELECT id,payment_number,notes,amount,supplier_id,customer_id,payment_type,status FROM %i WHERE payment_type=%s AND notes LIKE %s ORDER BY id DESC LIMIT 1', Stockland_DB::table( 'payments' ), $type, $pattern ), ARRAY_A );
		if ( $existing ) {
			$stored = (string) ( $existing['notes'] ?? '' );
			if ( ! preg_match( '/\[\[SLPAYREQ:([a-z0-9_-]{16,80}):([a-f0-9]{64})\]\]/', $stored, $m ) || strtolower( $m[1] ) !== $payment_request_key || ! hash_equals( $m[2], $fingerprint ) ) {
				Stockland_DB::release_named_lock( $request_lock );
				return new WP_Error( 'idempotency_conflict', 'This payment key is already used for different payment details.', array( 'status' => 409 ) );
			}
			$journal = Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_type=%s AND source_id=%s AND status=\'POSTED\' LIMIT 1', Stockland_DB::table( 'gl_journals' ), 'PAYMENT', (string) $existing['id'] ) );
			if ( 'POSTED' === $existing['status'] && ! $journal ) {
				Stockland_DB::release_named_lock( $request_lock );
				return new WP_Error( 'payment_integrity_error', 'The prior posted payment has no verifiable PLEDGER/GL journal. Stop and reconcile before retrying.', array( 'status' => 500 ) );}
			Stockland_DB::release_named_lock( $request_lock );
			$balance = 'SUPPLIER' === $type ? Stockland_Accounting::supplier_balance( $sid ) : Stockland_Accounting::customer_balance( $cid );
			return rest_ensure_response(
				array(
					'id'             => (int) $existing['id'],
					'payment_number' => $existing['payment_number'],
					'status'         => $existing['status'],
					'journal_id'     => $journal ? (int) $journal : null,
					'balance'        => $balance,
					'replayed'       => true,
				)
			);
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'transaction_failed', 'Could not start payment transaction.', array( 'status' => 500 ) );}
		try {
			$num                  = $this->next_number( 'PAY-', $date );
			$uid                  = get_current_user_id();
			$needs_owner_approval = true;
			$status               = 'PENDING';
			if ( false === Stockland_DB::insert_record(
				Stockland_DB::table( 'payments' ),
				array(
					'payment_number' => $num,
					'payment_date'   => $date,
					'payment_type'   => $type,
					'supplier_id'    => $sid ? $sid : null,
					'customer_id'    => $cid ? $cid : null,
					'amount'         => $amount,
					'method'         => $method,
					'reference'      => $reference,
					'notes'          => $stored_notes,
					'status'         => $status,
					'created_by'     => $uid,
				)
			) ) {
				throw new Exception( 'Payment failed' );
			}
			$id      = (int) $wpdb->insert_id;
			$journal = null;
			if ( ! $this->save_allocations( $id, $type, 'SUPPLIER' === $type ? $sid : $cid, $allocations, $amount ) ) {
				throw new Exception( 'Invalid payment allocation. Supplier allocations must reference posted invoices for the selected party, cannot exceed outstanding balance, and must equal the payment total.' );
			}
			if ( ! $needs_owner_approval ) {
				if ( 'SUPPLIER' === $type ) {
					$lines = array(
						array(
							'account_code' => '2000',
							'debit'        => $amount,
							'credit'       => 0,
							'supplier_id'  => $sid,
						),
						array(
							'account_code' => $asset,
							'debit'        => 0,
							'credit'       => $amount,
						),
					);
				} else {
					$lines = array(
						array(
							'account_code' => $asset,
							'debit'        => $amount,
							'credit'       => 0,
							'customer_id'  => $cid,
						),
						array(
							'account_code' => '1100',
							'debit'        => 0,
							'credit'       => $amount,
							'customer_id'  => $cid,
						),
					);
				}
				$journal = Stockland_Accounting::post_journal( $date, 'PAYMENT', (string) $id, 'Payment ' . $num, $lines, $uid );
				if ( ! $journal ) {
					throw new Exception( 'Payment journal failed' );
				}
			}
			if ( ! Stockland_Audit::log( $uid, null, $needs_owner_approval ? 'FINANCE_PAYMENT_SUBMIT' : 'FINANCE_PAYMENT_OWNER_POST', null, $amount, null, $status, 'Payment ' . $num . ' ' . $type . ' / ' . $method, 'PAY' . $id ) ) {
				throw new Exception( 'Payment audit event failed.' );
			}
			if ( $needs_owner_approval ) {
				if ( ! Stockland_Notifications::push_role( 'owner', null, 'payment_approval', 'Finance payment awaiting Owner approval', $num . ' · ETB ' . number_format( (float) $amount, 2, '.', '' ) . ' · ' . $type . '.', 'payment', $id, 'action' ) ) {
					throw new Exception( 'Owner notification could not be queued; payment was not committed.' );
				}if ( ! Stockland_Notifications::push_role( 'management', null, 'payment_submitted', 'Finance payment submitted', $num . ' is waiting for Owner approval.', 'payment', $id, 'info' ) ) {
					throw new Exception( 'Management notification could not be queued; payment was not committed.' );
				}
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payment commit failed.' );
			}if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}
			return rest_ensure_response(
				array(
					'id'             => $id,
					'payment_number' => $num,
					'status'         => $status,
					'journal_id'     => $journal,
					'balance'        => 'SUPPLIER' === $type ? Stockland_Accounting::supplier_balance( $sid ) : ( 'CUSTOMER' === $type ? Stockland_Accounting::customer_balance( $cid ) : null ),
					'replayed'       => false,
				)
			);
		} catch ( Throwable $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			if ( $request_lock ) {
				Stockland_DB::release_named_lock( $request_lock );
			}return new WP_Error( 'payment_failed', $x->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Owner approves/rejects a Management-entered supplier invoice; the posted AP journal is created in the same transaction.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function supplier_invoice_status( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_approve_finance() ) {
			return new WP_Error( 'forbidden', 'Only the active Stockland Owner profile may approve supplier invoices.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$id     = (int) $r['id'];
		$p      = $r->get_json_params();
		$action = strtoupper( sanitize_text_field( $p['action'] ?? '' ) );
		$note   = sanitize_textarea_field( $p['note'] ?? '' );
		if ( ! in_array( $action, array( 'APPROVE', 'REJECT' ), true ) ) {
			return new WP_Error( 'invalid_action', 'Action must be APPROVE or REJECT.', array( 'status' => 400 ) );
		}
		if ( 'REJECT' === $action && strlen( trim( $note ) ) < 5 ) {
			return new WP_Error( 'rejection_reason_required', 'A rejection reason of at least five characters is required.', array( 'status' => 400 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start supplier-invoice decision.', array( 'status' => 500 ) );
		}
		try {
			$it      = Stockland_DB::table( 'supplier_invoices' );
			$invoice = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $it, $id ), ARRAY_A );
			if ( ! $invoice ) {
				throw new Exception( 'Supplier invoice not found.' );
			}if ( 'PENDING' !== $invoice['status'] ) {
				throw new Exception( 'Only PENDING supplier invoices can be approved/rejected.' );
			}
			if ( get_current_user_id() === (int) $invoice['created_by'] || Stockland_Authority::role( (int) $invoice['created_by'] ) !== 'management' ) {
				throw new Exception( 'Supplier invoice requires a different active Owner approving a Management-submitted invoice.' );
			}
			$poid = (int) ( $invoice['po_id'] ?? 0 );
			if ( 'APPROVE' === $action && $poid <= 0 ) {
				throw new Exception( 'Supplier invoice approval is blocked: a posted stock purchase requires a linked Owner-approved Central Warehouse PO and posted receipt. Legacy unlinked invoices must be reconciled manually before any GL posting.' );
			}$debit_account = '2010';
			if ( $poid ) {
				$po = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d AND supplier_id=%d FOR UPDATE', Stockland_DB::table( 'purchase_orders' ), $poid, (int) $invoice['supplier_id'] ), ARRAY_A );
				if ( ! $po || ! in_array( $po['status'], array( 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED' ), true ) ) {
					throw new Exception( 'Linked PO must remain valid and received before invoice approval.' );
				}
				$central = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
				if ( $central <= 0 || (int) $po['location_id'] !== $central ) {
					throw new Exception( 'Supplier invoice is not linked to the canonical Central Warehouse PO.' );
				}
				if ( Stockland_Authority::role( (int) $po['created_by'] ) !== 'management' || (int) $po['approved_by'] < 1 || (int) $po['approved_by'] === (int) $po['created_by'] || Stockland_Authority::role( (int) $po['approved_by'] ) !== 'owner' || empty( $po['approved_at'] ) ) {
					throw new Exception( 'Linked PO owner-approval evidence failed validation.' );
				}
				$debit_account = '2010';
				$poitems       = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE po_id=%d FOR UPDATE', Stockland_DB::table( 'purchase_order_items' ), $poid ), ARRAY_A );
				$po_map        = array();
				foreach ( $poitems as $pi ) {
					$po_map[ (int) $pi['id'] ] = $pi;
				}
				$already_rows = Stockland_DB::read_results( $wpdb->prepare( "SELECT sii.po_item_id,COALESCE(SUM(sii.quantity),0) qty FROM %i sii JOIN %i si ON si.id=sii.invoice_id WHERE si.po_id=%d AND si.id<>%d AND si.status IN ('POSTED','PENDING') AND sii.po_item_id IS NOT NULL GROUP BY sii.po_item_id", Stockland_DB::table( 'supplier_invoice_items' ), $it, $poid, $id ), ARRAY_A );
				$already      = array();
				foreach ( $already_rows as $ar ) {
					$already[ (int) $ar['po_item_id'] ] = (float) $ar['qty'];
				}
				$items = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE invoice_id=%d FOR UPDATE', Stockland_DB::table( 'supplier_invoice_items' ), $id ), ARRAY_A );
				if ( ! $items ) {
					throw new Exception( 'Supplier invoice has no saved lines.' );
				}
				foreach ( $items as $li ) {
					$pid = (int) ( $li['po_item_id'] ?? 0 );
					if ( ! $pid || ! isset( $po_map[ $pid ] ) ) {
						throw new Exception( 'Invoice line has no valid linked PO item.' );
					}$pi       = $po_map[ $pid ];
					$remaining = max( 0, (float) $pi['quantity_received'] - (float) ( $already[ $pid ] ?? 0 ) );
					if ( (float) $li['quantity'] <= 0 || (float) $li['quantity'] > $remaining + 0.00001 ) {
						throw new Exception( 'Invoice quantity exceeds the remaining received quantity for ' . $pi['sku'] . '.' );
					}if ( (string) $li['sku'] !== (string) $pi['sku'] || Stockland_Money::minor( Stockland_Money::subtract( $li['unit_cost'], $pi['unit_cost'] ) ) !== 0 || abs( (float) $li['tax_rate'] - (float) $pi['tax_rate'] ) > 0.00001 ) {
						throw new Exception( 'Invoice SKU, cost or tax differs from the approved PO.' );
					}
				}
			} else {
				$items = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE invoice_id=%d FOR UPDATE', Stockland_DB::table( 'supplier_invoice_items' ), $id ), ARRAY_A );
				if ( ! $items ) {
					throw new Exception( 'Supplier invoice has no saved lines.' );
				}
			}
			$sum_sub = '0.00';
			$sum_tax = '0.00';
			foreach ( $items as $li ) {
				$sum_sub = Stockland_Money::add( $sum_sub, $li['line_subtotal'] );
				$sum_tax = Stockland_Money::add( $sum_tax, $li['line_tax'] );}
			if ( Stockland_Money::minor( Stockland_Money::subtract( $sum_sub, $invoice['subtotal'] ) ) !== 0 || Stockland_Money::minor( Stockland_Money::subtract( $sum_tax, $invoice['tax_amount'] ) ) !== 0 || Stockland_Money::minor( Stockland_Money::subtract( Stockland_Money::add( $sum_sub, $sum_tax ), $invoice['total_amount'] ) ) !== 0 ) {
				throw new Exception( 'Stored invoice header and line totals do not reconcile.' );
			}
			$next    = 'APPROVE' === $action ? 'POSTED' : 'REJECTED';
			$journal = null;
			if ( 'APPROVE' === $action ) {
				// The receipt journal already debited inventory and input VAT, and credited GRNI.
				// The AP invoice now clears GRNI for the validated invoice total, exactly once.
				$journal = Stockland_Accounting::post_journal(
					$invoice['invoice_date'],
					'SUPPLIER_INVOICE',
					(string) $id,
					'Supplier invoice ' . $invoice['invoice_number'],
					array(
						array(
							'account_code' => '2010',
							'debit'        => $invoice['total_amount'],
							'credit'       => 0,
							'supplier_id'  => (int) $invoice['supplier_id'],
						),
						array(
							'account_code' => '2000',
							'debit'        => 0,
							'credit'       => $invoice['total_amount'],
							'supplier_id'  => (int) $invoice['supplier_id'],
						),
					),
					get_current_user_id()
				);
				if ( ! $journal ) {
					throw new Exception( 'Supplier AP/GRNI clearing journal failed; approval rolled back.' );
				}
			}
			$notes = (string) ( $invoice['notes'] ?? '' );
			if ( 'REJECT' === $action ) {
				$notes = substr( $notes . ' [Owner rejected: ' . trim( $note ) . ']', 0, 500 );
			}
			if ( false === Stockland_DB::update_record(
				$it,
				array(
					'status' => $next,
					'notes'  => $notes,
				),
				array( 'id' => $id )
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Supplier invoice status update failed.' );
			}
			if ( 'APPROVE' === $action && $poid ) {
				$remaining_unreceived = (float) Stockland_DB::read_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(quantity_ordered-quantity_received,0)),0) FROM %i WHERE po_id=%d', Stockland_DB::table( 'purchase_order_items' ), $poid ) );
				$remaining_uninvoiced = (float) Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(GREATEST(poi.quantity_received-COALESCE((SELECT SUM(sii.quantity) FROM %i sii INNER JOIN %i si ON si.id=sii.invoice_id WHERE sii.po_item_id=poi.id AND si.po_id=poi.po_id AND si.status='POSTED'),0),0)),0) FROM %i poi WHERE poi.po_id=%d", Stockland_DB::table( 'supplier_invoice_items' ), $it, Stockland_DB::table( 'purchase_order_items' ), $poid ) );
				if ( $wpdb->last_error ) {
					throw new Exception( 'Could not reconcile remaining PO quantities after invoice approval.' );
				}
				if ( $remaining_unreceived <= 0.00001 && $remaining_uninvoiced <= 0.00001 && in_array( $po['status'], array( 'RECEIVED', 'CLOSED' ), true ) ) {
					if ( false === Stockland_DB::update_record( Stockland_DB::table( 'purchase_orders' ), array( 'status' => 'CLOSED' ), array( 'id' => $poid ) ) ) {
						throw new Exception( 'Could not close fully received/invoiced PO.' );
					}
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'APPROVE' === $action ? 'SUPPLIER_INVOICE_APPROVE' : 'SUPPLIER_INVOICE_REJECT', null, $invoice['total_amount'], 'PENDING', $next, 'REJECT' === $action ? $note : 'Owner approved; GL journal ' . $journal, 'AP' . $id ) ) {
				throw new Exception( 'Supplier invoice decision audit failed.' );
			}
			$message = $invoice['invoice_number'] . ' was ' . $next . ' by the Owner.' . ( 'REJECT' === $action ? ' Reason: ' . $note : ' Journal ' . $journal . '.' );
			if ( ! Stockland_Notifications::push_user( (int) $invoice['created_by'], 'supplier_invoice_decision', 'Supplier invoice ' . $next, $message, 'supplier_invoice', $id, 'REJECT' === $action ? 'warning' : 'info' ) ) {
				throw new Exception( 'Management submitter notification failed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Supplier invoice decision commit failed.' );
			}
			return rest_ensure_response(
				array(
					'id'         => $id,
					'status'     => $next,
					'journal_id' => $journal ? (int) $journal : null,
				)
			);
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'supplier_invoice_decision_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Owner-only posting of a previously submitted Finance payment. Supplier allocations are revalidated under row locks.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function payment_status( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_approve_finance() ) {
			return new WP_Error( 'forbidden', 'Only the active Stockland Owner profile can approve or reject finance payments.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$id     = (int) $r['id'];
		$p      = $r->get_json_params();
		$action = strtoupper( sanitize_text_field( $p['action'] ?? '' ) );
		$note   = sanitize_textarea_field( $p['note'] ?? '' );
		if ( ! in_array( $action, array( 'APPROVE', 'REJECT' ), true ) ) {
			return new WP_Error( 'invalid_action', 'Action must be APPROVE or REJECT.', array( 'status' => 400 ) );
		}
		if ( 'REJECT' === $action && strlen( trim( $note ) ) < 5 ) {
			return new WP_Error( 'rejection_reason_required', 'A meaningful rejection reason is required.', array( 'status' => 400 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payment decision.', array( 'status' => 500 ) );
		}
		try {
			$pt      = Stockland_DB::table( 'payments' );
			$payment = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $pt, $id ), ARRAY_A );
			if ( ! $payment ) {
				throw new Exception( 'Payment not found.' );
			}if ( 'PENDING' !== $payment['status'] ) {
				throw new Exception( 'Only PENDING payments can be approved/rejected.' );
			}
			if ( get_current_user_id() === (int) $payment['created_by'] || Stockland_Authority::role( (int) $payment['created_by'] ) !== 'management' ) {
				throw new Exception( 'A different active Owner must approve a Management-submitted payment.' );
			}
			$amount = $this->money( $payment['amount'] );
			if ( $amount <= 0 ) {
				throw new Exception( 'Payment amount is invalid.' );
			}$type = strtoupper( $payment['payment_type'] );
			if ( 'APPROVE' === $action && ! in_array( $type, array( 'SUPPLIER', 'CUSTOMER' ), true ) ) {
				throw new Exception( 'General operating expenses must be submitted through the controlled Expense workflow; no approval bypass is allowed.' );
			}$sid   = (int) $payment['supplier_id'];
			$cid    = (int) $payment['customer_id'];
			$method = strtoupper( (string) $payment['method'] );
			if ( ! in_array( $method, array( 'CASH', 'BANK' ), true ) ) {
				throw new Exception( 'Payment method is invalid.' );
			}$asset  = 'BANK' === $method ? '1010' : '1000';
			$journal = null;
			if ( 'APPROVE' === $action ) {
				if ( 'SUPPLIER' === $type ) {
					if ( ! $sid ) {
						throw new Exception( 'Supplier payment is missing supplier_id.' );
					}
					$allocs = Stockland_DB::read_results( $wpdb->prepare( "SELECT * FROM %i WHERE payment_id=%d AND invoice_type='AP' FOR UPDATE", Stockland_DB::table( 'payment_allocations' ), $id ), ARRAY_A );
					if ( ! $allocs ) {
						throw new Exception( 'Supplier payment has no PLEDGER invoice allocations.' );
					}
					$allocated_total = '0.00';
					foreach ( $allocs as $a ) {
						$iid   = (int) $a['invoice_id'];
						$alloc = $this->money( $a['amount'] );
						$iv    = Stockland_DB::read_row( $wpdb->prepare( "SELECT * FROM %i WHERE id=%d AND supplier_id=%d AND status='POSTED' FOR UPDATE", Stockland_DB::table( 'supplier_invoices' ), $iid, $sid ), ARRAY_A );
						if ( ! $iv ) {
							throw new Exception( 'A supplier payment allocation no longer points to a POSTED invoice for this supplier.' );
						}$other      = (float) Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(a.amount),0) FROM %i a INNER JOIN %i p ON p.id=a.payment_id WHERE a.invoice_type='AP' AND a.invoice_id=%d AND p.status IN ('POSTED','PENDING') AND p.id<>%d", Stockland_DB::table( 'payment_allocations' ), $pt, $iid, $id ) );
						$outstanding = Stockland_Money::subtract( $iv['total_amount'], number_format( $other, 2, '.', '' ) );
						if ( Stockland_Money::minor( Stockland_Money::subtract( $alloc, $outstanding ) ) > 0 ) {
							throw new Exception( 'Supplier payment would over-allocate invoice ' . $iv['invoice_number'] . '.' );
						}$allocated_total = Stockland_Money::add( $allocated_total, $alloc );}
					if ( Stockland_Money::minor( Stockland_Money::subtract( $allocated_total, $amount ) ) !== 0 ) {
						throw new Exception( 'Supplier payment allocations do not equal payment amount.' );
					}
				} elseif ( 'CUSTOMER' === $type && $cid <= 0 ) {
					throw new Exception( 'Customer payment is missing customer_id.' );}
				if ( 'SUPPLIER' === $type ) {
					$lines = array(
						array(
							'account_code' => '2000',
							'debit'        => $amount,
							'credit'       => 0,
							'supplier_id'  => $sid,
						),
						array(
							'account_code' => $asset,
							'debit'        => 0,
							'credit'       => $amount,
						),
					);
				} else {
					$lines = array(
						array(
							'account_code' => $asset,
							'debit'        => $amount,
							'credit'       => 0,
							'customer_id'  => $cid,
						),
						array(
							'account_code' => '1100',
							'debit'        => 0,
							'credit'       => $amount,
							'customer_id'  => $cid,
						),
					);
				}
				$journal = Stockland_Accounting::post_journal( $payment['payment_date'], 'PAYMENT', (string) $id, 'Payment ' . $payment['payment_number'], $lines, get_current_user_id() );
				if ( ! $journal ) {
					throw new Exception( 'Payment PLEDGER/GL journal failed; decision rolled back.' );
				}
			}
			$next  = 'APPROVE' === $action ? 'POSTED' : 'REJECTED';
			$notes = (string) ( $payment['notes'] ?? '' );
			if ( 'REJECT' === $action ) {
				$notes = substr( $notes . ' [Owner rejected: ' . trim( $note ) . ']', 0, 500 );
			}
			if ( false === Stockland_DB::update_record(
				$pt,
				array(
					'status' => $next,
					'notes'  => $notes,
				),
				array( 'id' => $id )
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Payment status update failed.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'APPROVE' === $action ? 'FINANCE_PAYMENT_APPROVE' : 'FINANCE_PAYMENT_REJECT', null, $amount, 'PENDING', $next, 'REJECT' === $action ? $note : 'Owner approved; GL journal ' . $journal, 'PAY' . $id ) ) {
				throw new Exception( 'Payment decision audit failed.' );
			}
			$message = $payment['payment_number'] . ' was ' . $next . ' by the Owner.' . ( 'REJECT' === $action ? ' Reason: ' . $note : ' Journal ' . $journal . '.' );
			if ( ! Stockland_Notifications::push_user( (int) $payment['created_by'], 'payment_decision', 'Finance payment ' . $next, $message, 'payment', $id, 'REJECT' === $action ? 'warning' : 'info' ) ) {
				throw new Exception( 'Payment submitter notification failed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payment decision commit failed.' );
			}return rest_ensure_response(
				array(
					'id'         => $id,
					'status'     => $next,
					'journal_id' => $journal ? (int) $journal : null,
				)
			);
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payment_decision_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Builds a trial-balance report from posted general-ledger entries.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return WP_REST_Response|WP_Error The authorized trial-balance response or an error.
	 */
	public function trial_balance( WP_REST_Request $r ) {
		global $wpdb;
		$rows = Stockland_DB::read_results(
			$wpdb->prepare(
				"SELECT a.account_code,a.account_name,a.account_type,COALESCE(SUM(l.debit),0) debit,COALESCE(SUM(l.credit),0) credit,COALESCE(SUM(l.debit-l.credit),0) balance
				FROM %i a
				LEFT JOIN %i l ON l.account_code=a.account_code
				LEFT JOIN %i j ON j.id=l.journal_id
				WHERE a.is_active=1 AND (j.id IS NULL OR j.status='POSTED')
				GROUP BY a.id ORDER BY a.account_code",
				Stockland_DB::table( 'gl_accounts' ),
				Stockland_DB::table( 'gl_lines' ),
				Stockland_DB::table( 'gl_journals' )
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}

	/**
	 * Builds the authorized financial summary report.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return WP_REST_Response|WP_Error The authorized financial summary or an error.
	 */
	public function financials( WP_REST_Request $r ) {
		$tb = $this->trial_balance( $r );
		if ( is_wp_error( $tb ) ) {
			return $tb;
		}$rows = $tb->get_data();
		$pl    = array(
			'revenue'    => '0.00',
			'cogs'       => '0.00',
			'expenses'   => '0.00',
			'net_income' => '0.00',
		);
		$bs    = array(
			'assets'      => '0.00',
			'liabilities' => '0.00',
			'equity'      => '0.00',
		);
		foreach ( $rows as $x ) {
			$bal = Stockland_Money::decimal( Stockland_Money::minor( $x['balance'] ) );
			if ( 'REVENUE' === $x['account_type'] ) {
				$pl['revenue'] = Stockland_Money::subtract( $pl['revenue'], $bal );
			} elseif ( '5000' === $x['account_code'] ) {
				$pl['cogs'] = Stockland_Money::add( $pl['cogs'], $bal );
			} elseif ( 'EXPENSE' === $x['account_type'] && '5000' !== $x['account_code'] ) {
				$pl['expenses'] = Stockland_Money::add( $pl['expenses'], $bal );
			} elseif ( 'ASSET' === $x['account_type'] ) {
				$bs['assets'] = Stockland_Money::add( $bs['assets'], $bal );
			} elseif ( 'LIABILITY' === $x['account_type'] ) {
				$bs['liabilities'] = Stockland_Money::subtract( $bs['liabilities'], $bal );
			} elseif ( 'EQUITY' === $x['account_type'] ) {
				$bs['equity'] = Stockland_Money::subtract( $bs['equity'], $bal );
			}
		}
		$pl['net_income'] = Stockland_Money::subtract( Stockland_Money::subtract( $pl['revenue'], $pl['cogs'] ), $pl['expenses'] );
		$equity           = Stockland_Money::add( $bs['equity'], $pl['net_income'] );
		$check            = Stockland_Money::subtract( $bs['assets'], Stockland_Money::add( $bs['liabilities'], $equity ) );
		return rest_ensure_response(
			array(
				'trial_balance'   => $rows,
				'profit_and_loss' => $pl,
				'balance_sheet'   => array(
					'assets'      => $bs['assets'],
					'liabilities' => $bs['liabilities'],
					'equity'      => $equity,
					'check'       => $check,
				),
			)
		);
	}

	/**
	 * Returns permitted general-ledger journal and account information.
	 *
	 * @param WP_REST_Request $r REST request containing the endpoint parameters.
	 * @return WP_REST_Response|WP_Error The authorized general-ledger response or an error.
	 */
	public function gl( WP_REST_Request $r ) {
		global $wpdb;
		$from     = $this->date( $r->get_param( 'from' ) ?? null, null );
		$to       = $this->date( $r->get_param( 'to' ) ?? null, null );
		$has_from = (bool) $from;
		$has_to   = (bool) $to;
		$rows     = Stockland_DB::read_results(
			$wpdb->prepare(
				"SELECT j.journal_number,j.journal_date,j.source_type,j.source_id,j.memo,l.account_code,a.account_name,l.debit,l.credit,l.location_id
				FROM %i j
				JOIN %i l ON l.journal_id=j.id
				JOIN %i a ON a.account_code=l.account_code
				WHERE j.status='POSTED'
					AND (%d = 0 OR j.journal_date >= %s)
					AND (%d = 0 OR j.journal_date <= %s)
				ORDER BY j.journal_date DESC,j.id DESC,l.id ASC LIMIT 2000",
				Stockland_DB::table( 'gl_journals' ),
				Stockland_DB::table( 'gl_lines' ),
				Stockland_DB::table( 'gl_accounts' ),
				$has_from ? 1 : 0,
				$has_from ? $from : '',
				$has_to ? 1 : 0,
				$has_to ? $to : ''
			),
			ARRAY_A
		);
		return rest_ensure_response( $rows );
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
