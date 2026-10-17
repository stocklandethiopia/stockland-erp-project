<?php
/**
 * REST controller source for Stockland ERP operational workflows.
 *
 * @package Stockland_ERP
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
/**
 * Stockland ERP employee, compensation, and payroll REST endpoints.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements controlled HR, compensation, payroll generation, and payroll approval flows.
 *
 * @package Stockland_ERP
 */
class Stockland_REST_HR extends Stockland_REST_Base {
	/**
	 * Registers the HR and payroll REST controller hooks.
	 *
	 * @return void Registers controller hooks.
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) ); }

	/**
	 * Checks whether the current user has Owner authority.
	 *
	 * @return bool Whether the current user is an authorized Owner.
	 */
	private function owner() {
		return Stockland_Authority::can_manage_hr(); }
	/**
	 * Checks whether the current user may manage payroll as Management.
	 *
	 * @return bool Whether the current user may manage payroll.
	 */
	private function payroll_manager() {
		return Stockland_Authority::can_manage_payroll(); }
	/**
	 * Checks whether the current user may approve payroll as Owner.
	 *
	 * @return bool Whether the current user may approve payroll.
	 */
	private function payroll_owner() {
		return Stockland_Authority::can_approve_payroll(); }
	/**
	 * Builds a forbidden response for an unauthorized HR/payroll action.
	 *
	 * @param mixed  $message Value for the message argument.
	 * @param string $status Requested record status.
	 * @return WP_Error A forbidden REST response.
	 */
	private function deny( $message = 'You do not have HR authority.', $status = 403 ) {
		return new WP_Error( 'forbidden', $message, array( 'status' => $status ) ); }
	/**
	 * Normalizes a monetary amount using the ERP money rules.
	 *
	 * @param mixed $v Value for the v argument.
	 * @return string The normalized monetary value.
	 */
	private function money( $v ) {
		return Stockland_Money::decimal( Stockland_Money::minor( $v ) ); }
	/**
	 * Validates a statutory, employment, or payroll date.
	 *
	 * @param mixed $v Value for the v argument.
	 * @param mixed $fallback Value for the fallback argument.
	 * @return string|false The normalized date, or false when invalid.
	 */
	private function valid_date( $v, $fallback = null ) {
		$v = sanitize_text_field( (string) $v );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $fallback;
		} return $v; }

	/**
	 * Registers HR employee, compensation, and payroll endpoints.
	 *
	 * @return void Registers HR/payroll routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bootstrap' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_view_hr();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/employees',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'employees' ),
					'permission_callback' => function () {
																return Stockland_Authority::can_view_hr();},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'employee_create' ),
					'permission_callback' => function () {
											return Stockland_Authority::can_prepare_hr();},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/employees/(?P<id>\\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'employee_update' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_prepare_hr();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/employees/(?P<id>\\d+)/decision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'employee_decision' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_hr();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/employees/(?P<id>\\d+)/compensation',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'compensation_create' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_hr();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'payroll_list' ),
					'permission_callback' => function () {
														return Stockland_Authority::can_view_payroll();},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'payroll_create' ),
					'permission_callback' => function () {
											return Stockland_Authority::can_manage_payroll();},
				),
			)
		);
		// Read-only payroll review is a separate GET route. It exposes line-level
		// amounts only to users already authorized to view payroll and never changes state.
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll/(?P<id>\\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'payroll_get' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_view_payroll();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll/(?P<id>\\d+)/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'payroll_generate' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_payroll();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll/(?P<id>\\d+)/line/(?P<line_id>\\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'payroll_line_update' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_payroll();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll/(?P<id>\\d+)/line/(?P<line_id>\\d+)/classification',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'payroll_line_classification_update' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_approve_payroll();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll/(?P<id>\\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'payroll_submit' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_manage_payroll();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll/(?P<id>\\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'payroll_approve' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_approve_payroll();
				},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/hr/payroll/(?P<id>\\d+)/pay',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'payroll_pay' ),
				'permission_callback' => function () {
					return Stockland_Authority::can_approve_payroll();
				},
			)
		);
	}

	/**
	 * Builds the role-scoped HR/payroll bootstrap data.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 */
	public function bootstrap( WP_REST_Request $r ) {
		$payroll_visible = Stockland_Authority::can_view_payroll();
		return rest_ensure_response(
			array(
				'employees'       => $this->employee_rows( $payroll_visible ),
				'payroll'         => $payroll_visible ? $this->payroll_rows() : array(),
				'payroll_visible' => $payroll_visible,
				'statutory_rules' => array(
					'rule_version'          => Stockland_Payroll_Statutory::RULE_VERSION,
					'law_effective_date'    => Stockland_Payroll_Statutory::LAW_EFFECTIVE_DATE,
					'first_full_month'      => Stockland_Payroll_Statutory::EFFECTIVE_DATE,
					'employee_pension_rate' => Stockland_Payroll_Statutory::EMPLOYEE_PENSION_RATE,
					'employer_pension_rate' => Stockland_Payroll_Statutory::EMPLOYER_PENSION_RATE,
					'monthly_tax_bands'     => Stockland_Payroll_Statutory::monthly_tax_bands(),
					'allowance_catalog'     => Stockland_Payroll_Statutory::allowance_catalog(),
					'income_tax_source'     => 'https://justice.gov.et/en/law/',
					'pension_source'        => 'https://justice.gov.et/en/entity/private-organizatio-employees-social-security-administration/',
				),
			)
		);
	}

	/**
	 * Reads employee rows authorized for the requested HR view.
	 *
	 * @param mixed $with_comp Value for the with comp argument.
	 * @return array The authorized employee rows.
	 */
	private function employee_rows( $with_comp = false ) {
		global $wpdb;
		$e = Stockland_DB::table( 'employees' );
		$l = Stockland_DB::table( 'locations' );
		if ( ! $with_comp ) {
			return Stockland_DB::read_results( $wpdb->prepare( 'SELECT e.*,l.location_name FROM %i e LEFT JOIN %i l ON l.id=e.branch_location_id ORDER BY e.status DESC,e.full_name', $e, $l ), ARRAY_A );
		}
		$c = Stockland_DB::table( 'employee_compensation' );
		// Do not break the whole HR page during an add-only upgrade. Until the owner runs
		// the protected HR schema migration, return a NULL alias and let Save explain the gap.
		$gaps             = class_exists( 'Stockland_HR' ) ? Stockland_HR::upgrade_gaps() : array( 'employee_compensation.allowance_breakdown_json' );
		$breakdown_select = in_array( 'employee_compensation.allowance_breakdown_json', $gaps, true ) ? 'NULL current_allowance_breakdown_json' : 'c.allowance_breakdown_json current_allowance_breakdown_json';
		$sql              = "SELECT e.*,l.location_name,COALESCE(c.base_salary,0) current_base_salary,COALESCE(c.allowance_total,0) current_allowance_total,COALESCE(c.standard_deduction_total,0) current_deduction_total,c.taxable_allowance_total current_taxable_allowance_total,c.exempt_allowance_total current_exempt_allowance_total,c.pensionable_salary current_pensionable_salary,c.statutory_classification_status current_statutory_classification_status,c.statutory_classification_note current_statutory_classification_note,{$breakdown_select},c.effective_from compensation_effective_from FROM %i e LEFT JOIN %i l ON l.id=e.branch_location_id LEFT JOIN %i c ON c.id=(SELECT c2.id FROM %i c2 WHERE c2.employee_id=e.id AND c2.effective_from<=%s ORDER BY c2.effective_from DESC,c2.id DESC LIMIT 1) ORDER BY e.status DESC,e.full_name";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL uses a fixed two-choice allowance-column fragment; table names and the effective date are passed via %i/%s placeholders.
		return Stockland_DB::read_results( $wpdb->prepare( $sql, $e, $l, $c, $c, current_time( 'Y-m-d' ) ), ARRAY_A );
	}
	/**
	 * Reads payroll data within the caller's authorized scope.
	 *
	 * @return array The authorized payroll rows.
	 */
	private function payroll_rows() {
		global $wpdb;
		$p = Stockland_DB::table( 'payroll_periods' );
		return Stockland_DB::read_results( $wpdb->prepare( 'SELECT p.*,u.display_name approved_by_name FROM %i p LEFT JOIN %i u ON u.ID=p.approved_by ORDER BY p.end_date DESC,p.id DESC LIMIT 100', $p, $wpdb->users ), ARRAY_A );
	}

	/**
	 * Lists employee records visible to the current user.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 */
	public function employees( WP_REST_Request $r ) {
		return rest_ensure_response( $this->employee_rows( Stockland_Authority::can_view_payroll() ) ); }

	/**
	 * Creates an employee master record after HR authority and field validation.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function employee_create( WP_REST_Request $r ) {
		if ( ! $this->owner() ) {
			return $this->deny();
		} global $wpdb;
		$p    = $r->get_json_params();
		$code = sanitize_text_field( $p['employee_id'] ?? '' );
		$name = sanitize_text_field( $p['full_name'] ?? '' );
		if ( '' === $code || '' === $name ) {
			return new WP_Error( 'invalid', 'employee_id and full_name are required.', array( 'status' => 400 ) );
		}
		if ( Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE employee_id=%s', Stockland_DB::table( 'employees' ), $code ) ) ) {
			return new WP_Error( 'duplicate', 'Employee ID already exists.', array( 'status' => 409 ) );
		}
		$branch = isset( $p['branch_location_id'] ) && '' !== $p['branch_location_id'] ? (int) $p['branch_location_id'] : null;
		if ( $branch && ! Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d AND is_active=1', Stockland_DB::table( 'locations' ), $branch ) ) ) {
			return new WP_Error( 'location', 'Active branch/location not found.', array( 'status' => 400 ) );
		}
		$row = array(
			'employee_id'        => $code,
			'wp_user_id'         => isset( $p['wp_user_id'] ) && '' !== $p['wp_user_id'] ? (int) $p['wp_user_id'] : null,
			'full_name'          => $name,
			'branch_location_id' => $branch,
			'position_title'     => sanitize_text_field( $p['position_title'] ?? '' ),
			'employment_type'    => sanitize_text_field( $p['employment_type'] ?? 'FULL_TIME' ),
			'hire_date'          => $this->valid_date( $p['hire_date'] ?? null, null ),
			'status'             => 'PENDING',
			'phone'              => sanitize_text_field( $p['phone'] ?? '' ),
			'email'              => sanitize_email( $p['email'] ?? '' ),
			'notes'              => sanitize_textarea_field( $p['notes'] ?? '' ),
			'created_by'         => get_current_user_id(),
			'updated_by'         => get_current_user_id(),
		);
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start employee transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( false === Stockland_DB::insert_record( Stockland_DB::table( 'employees' ), $row ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not create employee.' );
			}
			$id = (int) $wpdb->insert_id;
			if ( ! Stockland_Audit::log( get_current_user_id(), $branch, 'HR_EMPLOYEE_CREATE', null, null, null, $code, $name, 'EMP' . $id ) ) {
				throw new Exception( 'Audit event could not be recorded; employee was not committed.' );
			}
			if ( false === Stockland_Workflow::event( 'employee', $id, 'NEW', 'PENDING', get_current_user_id(), $branch, array( 'employee_id' => $code ) ) ) {
				throw new Exception( 'Employee approval workflow event could not be recorded.' );
			}
			if ( ! Stockland_Notifications::push_role( 'owner', null, 'hr_employee_approval', 'Employee file awaiting approval', 'Management created employee ' . $code . ' — ' . $name . '. The file remains PENDING and is excluded from active payroll until Owner approval.', 'employee', $id, 'action' ) ) {
				throw new Exception( 'Owner approval notification could not be recorded; employee file was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Employee commit failed.' );
			}
			return rest_ensure_response( Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'employees' ), $id ), ARRAY_A ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Applies an independent Owner decision to a Management-created employee file.
	 *
	 * @param WP_REST_Request $r Employee identifier and decision request.
	 * @return mixed The updated employee row or a REST error.
	 * @throws Exception When the approval, audit, notification, or transaction fails.
	 */
	public function employee_decision( WP_REST_Request $r ) {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( Stockland_Authority::role( $user_id ) !== Stockland_Authority::OWNER || ! Stockland_Authority::can_manage_hr( $user_id ) ) {
			return $this->deny( 'Only an authorized ERP Owner may approve an employee file.', 403 );
		}
		$id = (int) $r['id'];
		if ( $id < 1 ) {
			return new WP_Error( 'invalid_employee', 'A valid employee identifier is required.', array( 'status' => 400 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start employee approval transaction.', array( 'status' => 500 ) );
		}
		try {
			$employee = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'employees' ), $id ), ARRAY_A );
			if ( ! $employee ) {
				throw new Exception( 'Employee file not found.' );
			}
			if ( 'PENDING' !== strtoupper( (string) $employee['status'] ) ) {
				throw new Exception( 'Only a pending employee file can be approved. Current status: ' . $employee['status'] . '.' );
			}
			if ( (int) ( $employee['created_by'] ?? 0 ) === $user_id ) {
				throw new Exception( 'The employee-file creator cannot approve the same employee file.' );
			}
			if ( false === Stockland_DB::update_record(
				Stockland_DB::table( 'employees' ),
				array(
					'status'     => 'ACTIVE',
					'updated_by' => $user_id,
				),
				array(
					'id'     => $id,
					'status' => 'PENDING',
				)
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Employee approval status could not be saved.' );
			}
			if ( false === Stockland_Workflow::event(
				'employee',
				$id,
				'PENDING',
				'ACTIVE',
				$user_id,
				$employee['branch_location_id'],
				array(
					'employee_id' => $employee['employee_id'],
					'decision'    => 'APPROVE',
				)
			) ) {
				throw new Exception( 'Employee approval workflow event could not be recorded.' );
			}
			if ( ! Stockland_Audit::log( $user_id, $employee['branch_location_id'], 'HR_EMPLOYEE_APPROVE', null, null, 'PENDING', 'ACTIVE', 'Owner approved employee file', 'EMP' . $id, $user_id ) ) {
				throw new Exception( 'Employee approval audit event could not be recorded.' );
			}
			if ( ! Stockland_Notifications::push_user( (int) $employee['created_by'], 'hr_employee_approved', 'Employee file approved', 'Employee ' . $employee['employee_id'] . ' — ' . $employee['full_name'] . ' was approved by the Owner and is now active.', 'employee', $id, 'info' ) ) {
				throw new Exception( 'Employee approval notification could not be recorded.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Employee approval commit failed.' );
			}
			return rest_ensure_response( Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'employees' ), $id ), ARRAY_A ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'employee_decision_failed', $e->getMessage(), array( 'status' => 409 ) );
		}
	}

	/**
	 * Updates an employee master record without rewriting posted payroll history.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function employee_update( WP_REST_Request $r ) {
		if ( ! $this->owner() ) {
			return $this->deny();
		} global $wpdb;
		$id  = (int) $r['id'];
		$cur = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'employees' ), $id ), ARRAY_A );
		if ( ! $cur ) {
			return new WP_Error( 'not_found', 'Employee not found.', array( 'status' => 404 ) );
		}$p   = $r->get_json_params();
		$data = array();
		foreach ( array( 'full_name', 'position_title', 'employment_type', 'phone', 'notes' ) as $f ) {
			if ( array_key_exists( $f, $p ) ) {
				$data[ $f ] = sanitize_text_field( $p[ $f ] );
			}
		}
		if ( array_key_exists( 'email', $p ) ) {
			$data['email'] = sanitize_email( $p['email'] );
		}
		if ( array_key_exists( 'branch_location_id', $p ) ) {
			$b = '' === $p['branch_location_id'] || null === $p['branch_location_id'] ? null : (int) $p['branch_location_id'];
			if ( $b && ! Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d AND is_active=1', Stockland_DB::table( 'locations' ), $b ) ) ) {
				return new WP_Error( 'location', 'Active location not found.', array( 'status' => 400 ) );
			}$data['branch_location_id'] = $b; }
		if ( array_key_exists( 'status', $p ) ) {
			$requested_status = strtoupper( sanitize_text_field( (string) $p['status'] ) );
			$current_status   = strtoupper( (string) ( $cur['status'] ?? '' ) );
			// Management may deactivate or terminate an employee but cannot activate or self-approve a pending employee file.
			if ( 'PENDING' === $current_status || 'ACTIVE' === $requested_status ) {
				$data['status'] = $cur['status'];
			} elseif ( in_array( $requested_status, array( 'INACTIVE', 'TERMINATED' ), true ) ) {
				$data['status'] = $requested_status;
			}
		}
		if ( array_key_exists( 'hire_date', $p ) ) {
			$data['hire_date'] = $this->valid_date( $p['hire_date'], null );
		}if ( array_key_exists( 'termination_date', $p ) ) {
			$data['termination_date'] = $this->valid_date( $p['termination_date'], null );
		}
		if ( ! $data ) {
			return rest_ensure_response( $cur );
		}$data['updated_by'] = get_current_user_id();
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start employee update transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( false === Stockland_DB::update_record( Stockland_DB::table( 'employees' ), $data, array( 'id' => $id ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Employee update failed.' );
			}
			$wp_update = array( 'ID' => $id );
			if ( array_key_exists( 'full_name', $data ) ) {
				$wp_update['display_name'] = $data['full_name'];
			}if ( array_key_exists( 'email', $data ) ) {
				$wp_update['user_email'] = $data['email'];
			}$target_wp = (int) $cur['wp_user_id'];
			if ( $target_wp && function_exists( 'wp_update_user' ) ) {
				$wp_update['ID'] = $target_wp;
				$wr              = wp_update_user( $wp_update );
				if ( is_wp_error( $wr ) ) {
					throw new Exception( $wr->get_error_message() );
				}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $data['branch_location_id'] ?? $cur['branch_location_id'], 'HR_EMPLOYEE_UPDATE', null, null, wp_json_encode( $cur ), wp_json_encode( $data ), 'EMP' . $id ) ) {
				throw new Exception( 'Audit event could not be recorded; employee update was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Employee update commit failed.' );
			}
			return rest_ensure_response( Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'employees' ), $id ), ARRAY_A ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Creates a compensation agreement or segment for an employee.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When validation or transaction processing fails.
	 * @throws InvalidArgumentException When a compensation amount is invalid.
	 */
	public function compensation_create( WP_REST_Request $r ) {
		if ( ! $this->owner() ) {
			return $this->deny();
		}
		global $wpdb;
		$id        = (int) $r['id'];
		$employees = Stockland_DB::table( 'employees' );
		$employee  = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $employees, $id ), ARRAY_A );
		if ( ! $employee ) {
			return new WP_Error( 'not_found', 'Employee not found.', array( 'status' => 404 ) );
		}
		if ( strtoupper( (string) ( $employee['employment_type'] ?? '' ) ) === 'RETAINER_SERVICE' ) {
			return new WP_Error( 'service_contract_outside_employee_payroll', 'This record is classified as RETAINER_SERVICE, so do not create employee PAYE/pension compensation here. Use the separately approved contract/payment procedure.', array( 'status' => 409 ) );
		}
		$p = $r->get_json_params();
		if ( ! array_key_exists( 'base_salary', $p ) || '' === $p['base_salary'] ) {
			return new WP_Error( 'classification_required', 'Base monthly salary is required.', array( 'status' => 400 ) );
		}
		$has_allowance_rows = array_key_exists( 'allowances', $p ) && is_array( $p['allowances'] );
		if ( ! $has_allowance_rows && ( ! array_key_exists( 'taxable_allowance_total', $p ) || ! array_key_exists( 'exempt_allowance_total', $p ) ) ) {
			return new WP_Error( 'classification_required', 'Submit the allowance component editor or explicitly classify taxable and exempt totals.', array( 'status' => 400 ) );
		}
		if ( $has_allowance_rows && count( $p['allowances'] ) > 40 ) {
			return new WP_Error( 'too_many_allowances', 'Enter no more than 40 allowance components per employee.', array( 'status' => 400 ) );
		}
		$schema_gaps = class_exists( 'Stockland_HR' ) ? Stockland_HR::upgrade_gaps() : array();
		if ( $has_allowance_rows && in_array( 'employee_compensation.allowance_breakdown_json', $schema_gaps, true ) ) {
			return new WP_Error( 'hr_schema_upgrade_required', 'Run the protected Owner/System Control HR schema upgrade before saving allowance details. No compensation was saved.', array( 'status' => 409 ) );
		}
		$effective_raw = isset( $p['effective_from'] ) ? (string) $p['effective_from'] : '';
		$effective     = '' === $effective_raw ? current_time( 'Y-m-d' ) : $this->valid_date( $effective_raw, null );
		if ( ! $effective ) {
			return new WP_Error( 'invalid_effective_date', 'Effective date must be a real date in YYYY-MM-DD format.', array( 'status' => 400 ) );
		}
		try {
			$base                  = $this->money( $p['base_salary'] );
			$normalized_allowances = array();
			$taxable               = '0.00';
			$exempt                = '0.00';
			if ( $has_allowance_rows ) {
				$catalog = Stockland_Payroll_Statutory::allowance_catalog();
				foreach ( $p['allowances'] as $i => $item ) {
					if ( ! is_array( $item ) ) {
						throw new InvalidArgumentException( 'Allowance row ' . ( $i + 1 ) . ' is invalid.' );
					}
					$category = strtoupper( sanitize_key( $item['category'] ?? '' ) );
					if ( ! isset( $catalog[ $category ] ) ) {
						throw new InvalidArgumentException( 'Allowance row ' . ( $i + 1 ) . ' has an unknown category.' );
					}
					$amount = $this->money( $item['amount'] ?? '0.00' );
					if ( Stockland_Money::minor( $amount ) <= 0 ) {
						continue;
					}
					$treatment = strtoupper( sanitize_text_field( $item['tax_treatment'] ?? 'TAXABLE' ) );
					if ( ! in_array( $treatment, array( 'TAXABLE', 'EXEMPT' ), true ) ) {
						throw new InvalidArgumentException( 'Allowance row ' . ( $i + 1 ) . ' must be taxable or explicitly claim a statutory exemption.' );
					}
					$basis    = sanitize_textarea_field( $item['legal_basis'] ?? '' );
					$evidence = sanitize_text_field( $item['evidence_reference'] ?? '' );
					$verified = ! empty( $item['owner_verified'] ) && Stockland_Authority::role() === 'owner';
					if ( 'EXEMPT' === $treatment && ( Stockland_Authority::role() !== 'owner' || strlen( trim( $basis ) ) < 20 || strlen( trim( $evidence ) ) < 4 || ! $verified ) ) {
						throw new InvalidArgumentException( 'Allowance row ' . ( $i + 1 ) . ' exemption needs a specific current legal clause (20+ characters), a supporting document/reference, and Owner verification. It remains taxable until all three are recorded.' );
					}
					$entry                   = array(
						'category'           => $category,
						'label'              => sanitize_text_field( $catalog[ $category ]['label'] ),
						'amount'             => $amount,
						'tax_treatment'      => $treatment,
						'legal_basis'        => 'EXEMPT' === $treatment ? substr( trim( $basis ), 0, 500 ) : '',
						'evidence_reference' => 'EXEMPT' === $treatment ? substr( trim( $evidence ), 0, 190 ) : '',
						'owner_verified'     => 'EXEMPT' === $treatment && $verified,
						'verified_by'        => 'EXEMPT' === $treatment ? (int) get_current_user_id() : null,
						'verified_at'        => 'EXEMPT' === $treatment ? current_time( 'mysql' ) : null,
					);
					$normalized_allowances[] = $entry;
					if ( 'EXEMPT' === $treatment ) {
						$exempt = Stockland_Money::add( $exempt, $amount );
					} else {
						$taxable = Stockland_Money::add( $taxable, $amount );
					}
				}
			} else {
				// Backwards-compatible API for existing clients. It may not claim exemption
				// without the same current-clause/evidence/Owner gate as the component editor.
				$taxable = $this->money( $p['taxable_allowance_total'] );
				$exempt  = $this->money( $p['exempt_allowance_total'] );
				if ( Stockland_Money::minor( $taxable ) > 0 ) {
					$normalized_allowances[] = array(
						'category'           => 'GENERAL_CASH',
						'label'              => 'Legacy taxable allowance',
						'amount'             => $taxable,
						'tax_treatment'      => 'TAXABLE',
						'legal_basis'        => '',
						'evidence_reference' => '',
						'owner_verified'     => false,
					);
				}
				if ( Stockland_Money::minor( $exempt ) > 0 ) {
					$legacy_basis    = sanitize_textarea_field( $p['statutory_classification_note'] ?? '' );
					$legacy_evidence = sanitize_text_field( $p['exemption_evidence_reference'] ?? '' );
					if ( Stockland_Authority::role() !== 'owner' || strlen( trim( $legacy_basis ) ) < 20 || strlen( trim( $legacy_evidence ) ) < 4 || empty( $p['owner_confirmed_exemption'] ) ) {
						throw new InvalidArgumentException( 'Legacy exemption total requires a current 20+ character legal clause, supporting evidence reference and explicit Owner confirmation.' );
					}
					$normalized_allowances[] = array(
						'category'           => 'GENERAL_CASH',
						'label'              => 'Owner-verified legacy exemption',
						'amount'             => $exempt,
						'tax_treatment'      => 'EXEMPT',
						'legal_basis'        => substr( trim( $legacy_basis ), 0, 500 ),
						'evidence_reference' => substr( trim( $legacy_evidence ), 0, 190 ),
						'owner_verified'     => true,
						'verified_by'        => (int) get_current_user_id(),
						'verified_at'        => current_time( 'mysql' ),
					);
				}
			}
			$pensionable = $this->money( $p['pensionable_salary'] ?? $base );
			if ( Stockland_Authority::role() !== 'owner' && Stockland_Money::minor( Stockland_Money::subtract( $pensionable, $base ) ) !== 0 ) {
				throw new InvalidArgumentException( 'Only the Owner may classify pensionable salary differently from base salary. Management may prepare standard taxable compensation for Owner review.' );
			}
			$other = $this->money( $p['other_deduction_total'] ?? $p['standard_deduction_total'] ?? '0' );
			$check = Stockland_Payroll_Statutory::calculate( $base, $taxable, $exempt, $pensionable, $other );
		} catch ( Throwable $e ) {
			return new WP_Error( 'invalid_compensation', $e->getMessage(), array( 'status' => 400 ) );
		}
		$note                   = sanitize_text_field( $p['statutory_classification_note'] ?? '' );
		$pensionability_differs = Stockland_Money::minor( $pensionable ) !== Stockland_Money::minor( $base );
		if ( ( Stockland_Money::minor( $exempt ) > 0 || $pensionability_differs ) && strlen( $note ) < 20 ) {
			return new WP_Error( 'classification_basis_required', 'Record a specific legal/contract basis (at least 20 characters) for exempt allowances or when pensionable salary differs from base salary. The ERP does not decide statutory eligibility.', array( 'status' => 400 ) );
		}
		if ( '' === $note ) {
			$note = 'All allowance classified as taxable; pensionable salary equals base salary by Owner classification.';
		}
		$row = array(
			'employee_id'                     => $id,
			'effective_from'                  => $effective,
			'base_salary'                     => $base,
			'allowance_total'                 => $check['allowance_total'],
			'standard_deduction_total'        => $other,
			'taxable_allowance_total'         => $taxable,
			'exempt_allowance_total'          => $exempt,
			'pensionable_salary'              => $pensionable,
			'statutory_classification_status' => Stockland_Authority::role() === 'owner' ? 'OWNER_CLASSIFIED' : 'MANAGEMENT_PREPARED',
			'statutory_classification_note'   => substr( $note, 0, 255 ),
			'allowance_breakdown_json'        => wp_json_encode( $normalized_allowances ),
			'currency'                        => 'ETB',
			'notes'                           => sanitize_textarea_field( $p['notes'] ?? '' ),
			'created_by'                      => get_current_user_id(),
		);
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start compensation transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( false === Stockland_DB::insert_record( Stockland_DB::table( 'employee_compensation' ), $row ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not save compensation.' );
			}
			$details = array(
				'taxable_allowance'   => $taxable,
				'exempt_allowance'    => $exempt,
				'allowance_breakdown' => $normalized_allowances,
				'pensionable_salary'  => $pensionable,
				'other_deduction'     => $other,
				'classification'      => $row['statutory_classification_status'],
				'classification_note' => $note,
				'tax_rule_version'    => Stockland_Payroll_Statutory::RULE_VERSION,
				'law_effective_date'  => Stockland_Payroll_Statutory::LAW_EFFECTIVE_DATE,
			);
			if ( ! Stockland_Audit::log( get_current_user_id(), $employee['branch_location_id'], 'HR_COMPENSATION_CREATE', null, $base, null, $base, wp_json_encode( $details ), 'EMP' . $id ) ) {
				throw new Exception( 'Audit event could not be recorded; compensation was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Compensation commit failed.' );
			}
			$row['id'] = (int) $wpdb->insert_id;
			return rest_ensure_response( $row );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) ); }
	}

	/**
	 * Lists payroll periods and status visible to the current user.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 */
	public function payroll_list( WP_REST_Request $r ) {
		global $wpdb;
		$rows = $this->payroll_rows();
		if ( ! $rows ) {
			return rest_ensure_response( array() );
		}
		$ids = array_map(
			static function ( $x ) {
				return (int) $x['id'];
			},
			$rows
		);
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ph contains only one %d token per integer-cast period ID; table name and IDs are passed to prepare in order.
		$lines = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE period_id IN(' . $ph . ') ORDER BY period_id,employee_name', array_merge( array( Stockland_DB::table( 'payroll_lines' ) ), array_map( 'intval', $ids ) ) ), ARRAY_A );
		$by    = array();
		foreach ( $lines as $line ) {
			$by[ (int) $line['period_id'] ][] = $line;}
		foreach ( $rows as &$row ) {
			$row['lines'] = $by[ (int) $row['id'] ] ?? array();
		}unset( $row );
		return rest_ensure_response( $rows );
	}

	/**
	 * Returns one payroll period and its detail lines for read-only review.
	 *
	 * @param WP_REST_Request $r REST request containing the payroll period ID.
	 * @return mixed Read-only payroll detail or a not-found/forbidden response.
	 */
	public function payroll_get( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_view_payroll() ) {
			return $this->deny( 'You do not have permission to view payroll details.', 403 );
		}
		$period = $this->payroll_detail( (int) $r['id'] );
		if ( ! $period ) {
			return new WP_Error( 'not_found', 'Payroll period not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $period );
	}

	/**
	 * Creates a draft payroll period within the configured statutory rules.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When validation or transaction processing fails.
	 */
	public function payroll_create( WP_REST_Request $r ) {
		if ( ! $this->payroll_manager() ) {
			return $this->deny( 'Only active Stockland Management can prepare payroll; the Owner has read and final-approval access.', 403 );
		}global $wpdb;
		$p     = $r->get_json_params();
		$key   = sanitize_text_field( $p['period_key'] ?? '' );
		$start = $this->valid_date( $p['start_date'] ?? null, null );
		$end   = $this->valid_date( $p['end_date'] ?? null, null );
		if ( '' === $key || ! $start || ! $end || $start > $end ) {
			return new WP_Error( 'invalid', 'Valid period_key, start_date and end_date are required.', array( 'status' => 400 ) );
		}$month_start = substr( $start, 0, 7 ) . '-01';
		$month_end    = gmdate( 'Y-m-t', strtotime( $start . ' 00:00:00 UTC' ) );
		if ( $start !== $month_start || $end !== $month_end || substr( $start, 0, 7 ) !== $key ) {
			return new WP_Error( 'payroll_month_required', 'Statutory PAYE is monthly: use period key YYYY-MM with the first and last date of that same calendar month.', array( 'status' => 400 ) );
		}if ( $start < Stockland_Payroll_Statutory::EFFECTIVE_DATE ) {
			return new WP_Error( 'historical_tax_rule_unavailable', 'This monthly statutory calculator starts on ' . Stockland_Payroll_Statutory::EFFECTIVE_DATE . '. The law applies from ' . Stockland_Payroll_Statutory::LAW_EFFECTIVE_DATE . '; July 2025 crosses that boundary and requires separately approved split-period treatment. Preserve posted payroll; do not silently recalculate historical periods.', array( 'status' => 400 ) );
		}if ( Stockland_DB::read_var( $wpdb->prepare( 'SELECT id FROM %i WHERE period_key=%s', Stockland_DB::table( 'payroll_periods' ), $key ) ) ) {
			return new WP_Error( 'duplicate', 'Payroll period already exists.', array( 'status' => 409 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll-period transaction.', array( 'status' => 500 ) );
		}
		try {
			$ok = Stockland_DB::insert_record(
				Stockland_DB::table( 'payroll_periods' ),
				array(
					'period_key' => $key,
					'start_date' => $start,
					'end_date'   => $end,
					'status'     => 'DRAFT',
					'notes'      => sanitize_textarea_field( $p['notes'] ?? '' ),
					'created_by' => get_current_user_id(),
				)
			);
			if ( false === $ok ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not create payroll period.' );
			}$id = (int) $wpdb->insert_id;
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'PAYROLL_PERIOD_CREATE', null, null, null, 'DRAFT', $key, 'PAY' . $id ) ) {
				throw new Exception( 'Audit event could not be recorded; payroll period was not committed.' );
			}if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll-period commit failed.' );
			}return rest_ensure_response( Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'payroll_periods' ), $id ), ARRAY_A ) );
		} catch ( Throwable $x ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $x->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Returns an employee's effective-dated compensation segments.
	 *
	 * @param mixed $employee Value for the employee argument.
	 * @param mixed $period Value for the period argument.
	 * @return array Effective-dated compensation segments.
	 * @throws RuntimeException When a compensation classification or calculation invariant fails.
	 */
	private function compensation_segments( $employee, $period ) {
		global $wpdb;
		$table = Stockland_DB::table( 'employee_compensation' );
		$rows  = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE employee_id=%d AND effective_from<=%s ORDER BY effective_from ASC,id ASC', $table, (int) $employee['id'], $period['end_date'] ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}
		// When more than one revision shares a date, only the highest id for that date is effective.
		$by_date = array();
		foreach ( $rows as $row ) {
			$by_date[ $row['effective_from'] ] = $row;
		}
		ksort( $by_date, SORT_STRING );
		$schedule            = array_values( $by_date );
		$period_start        = $period['start_date'];
		$period_end          = $period['end_date'];
		$hire                = ! empty( $employee['hire_date'] ) && $employee['hire_date'] > $period_start ? $employee['hire_date'] : $period_start;
		$term                = ! empty( $employee['termination_date'] ) && $employee['termination_date'] < $period_end ? $employee['termination_date'] : $period_end;
		$days_in_month       = (int) gmdate( 't', strtotime( $period_start . ' 00:00:00 UTC' ) );
		$totals              = array(
			'base_salary'             => '0.00',
			'taxable_allowance_total' => '0.00',
			'exempt_allowance_total'  => '0.00',
			'pensionable_salary'      => '0.00',
			'other_deduction_total'   => '0.00',
		);
		$used                = 0;
		$notes               = array();
		$allowance_snapshots = array();
		foreach ( $schedule as $i => $c ) {
			$from = $c['effective_from'] > $hire ? $c['effective_from'] : $hire;
			if ( $from < $period_start ) {
				$from = $period_start;
			}
			$to = $term;
			if ( isset( $schedule[ $i + 1 ] ) ) {
				$next_start = $schedule[ $i + 1 ]['effective_from'];
				$next_day   = gmdate( 'Y-m-d', strtotime( $next_start . ' 00:00:00 UTC -1 day' ) );
				if ( $next_day < $to ) {
					$to = $next_day;
				}
			}
			if ( $from > $to || $from > $period_end || $to < $period_start ) {
				continue;
			}
			$comp_status  = (string) ( $c['statutory_classification_status'] ?? '' );
			$manager_safe = 'MANAGEMENT_PREPARED' === $comp_status && Stockland_Money::minor( $c['exempt_allowance_total'] ?? '0.00' ) === 0 && Stockland_Money::minor( Stockland_Money::subtract( $c['pensionable_salary'] ?? '0.00', $c['base_salary'] ?? '0.00' ) ) === 0;
			if ( ( 'OWNER_CLASSIFIED' !== $comp_status && ! $manager_safe ) || null === $c['taxable_allowance_total'] || null === $c['exempt_allowance_total'] || null === $c['pensionable_salary'] ) {
				throw new RuntimeException( 'Compensation effective ' . esc_html( (string) $c['effective_from'] ) . ' for ' . esc_html( (string) $employee['employee_id'] ) . ' is not owner-classified. Open HR → Set Compensation and explicitly classify taxable/exempt allowance and pensionable salary.' );
			}
			$exempt           = Stockland_Money::decimal( Stockland_Money::minor( $c['exempt_allowance_total'] ) );
			$basis            = trim( (string) ( $c['statutory_classification_note'] ?? '' ) );
			$saved_components = json_decode( (string) ( $c['allowance_breakdown_json'] ?? '' ), true );
			if ( ! is_array( $saved_components ) ) {
				$saved_components = array();
			}
			if ( Stockland_Money::minor( $exempt ) > 0 ) {
				if ( strlen( $basis ) < 20 ) {
					throw new RuntimeException( 'Exempt allowance classification for ' . esc_html( (string) $employee['employee_id'] ) . ' requires a current 20+ character legal basis note.' );
				}
				$saved_exempt = '0.00';
				foreach ( $saved_components as $component ) {
					if ( strtoupper( (string) ( $component['tax_treatment'] ?? '' ) ) !== 'EXEMPT' ) {
						continue;
					}
					if ( empty( $component['owner_verified'] ) || strlen( trim( (string) ( $component['legal_basis'] ?? '' ) ) ) < 20 || strlen( trim( (string) ( $component['evidence_reference'] ?? '' ) ) ) < 4 ) {
						throw new RuntimeException( 'Exempt allowance for ' . esc_html( (string) $employee['employee_id'] ) . ' lacks verified legal-basis/evidence snapshot. Re-save compensation through the Owner allowance editor.' );
					}
					$saved_exempt = Stockland_Money::add( $saved_exempt, $component['amount'] ?? '0.00' );
				}
				if ( Stockland_Money::minor( $saved_exempt ) !== Stockland_Money::minor( $exempt ) ) {
					throw new RuntimeException( 'Exempt allowance snapshot does not match the amount for ' . esc_html( (string) $employee['employee_id'] ) . '. Re-save compensation through the Owner allowance editor.' );
				}
			}
			if ( ! $saved_components ) {
				$taxable_legacy = Stockland_Money::decimal( Stockland_Money::minor( $c['taxable_allowance_total'] ?? '0.00' ) );
				if ( Stockland_Money::minor( $taxable_legacy ) > 0 ) {
					$saved_components[] = array(
						'category'           => 'GENERAL_CASH',
						'label'              => 'Legacy taxable allowance',
						'amount'             => $taxable_legacy,
						'tax_treatment'      => 'TAXABLE',
						'legal_basis'        => '',
						'evidence_reference' => '',
						'owner_verified'     => false,
					);
				}
			}
			$snapshot_taxable = '0.00';
			$snapshot_exempt  = '0.00';
			foreach ( $saved_components as $component ) {
				if ( strtoupper( (string) ( $component['tax_treatment'] ?? 'TAXABLE' ) ) === 'EXEMPT' ) {
					$snapshot_exempt = Stockland_Money::add( $snapshot_exempt, $component['amount'] ?? '0.00' );
				} else {
					$snapshot_taxable = Stockland_Money::add( $snapshot_taxable, $component['amount'] ?? '0.00' );
				}
			}
			if ( Stockland_Money::minor( $snapshot_taxable ) !== Stockland_Money::minor( $c['taxable_allowance_total'] ?? '0.00' ) || Stockland_Money::minor( $snapshot_exempt ) !== Stockland_Money::minor( $c['exempt_allowance_total'] ?? '0.00' ) ) {
				throw new RuntimeException( 'Allowance component breakdown does not match saved compensation for ' . esc_html( (string) $employee['employee_id'] ) . '. Re-save compensation from Owner HR before payroll generation.' );
			}
			$start_obj = new DateTimeImmutable( $from );
			$end_obj   = new DateTimeImmutable( $to );
			$days      = (int) $start_obj->diff( $end_obj )->days + 1;
			foreach ( $totals as $field => $old ) {
				$source_field     = 'other_deduction_total' === $field ? 'standard_deduction_total' : $field;
				$amount           = Stockland_Money::decimal( Stockland_Money::minor( $c[ $source_field ] ?? 0 ) );
				$part             = Stockland_Money::proportion_of_quantity( $amount, $days, $days_in_month );
				$totals[ $field ] = Stockland_Money::add( $totals[ $field ], $part );
			}
			foreach ( $saved_components as $component ) {
				$component_amount = Stockland_Money::proportion_of_quantity( $this->money( $component['amount'] ?? '0.00' ), $days, $days_in_month );
				if ( Stockland_Money::minor( $component_amount ) <= 0 ) {
					continue;
				}
				$component['amount'] = $component_amount;
				$sig                 = hash( 'sha256', wp_json_encode( array( $component['category'] ?? 'OTHER', $component['tax_treatment'] ?? 'TAXABLE', $component['legal_basis'] ?? '', $component['evidence_reference'] ?? '', $component['owner_verified'] ?? false, $component['verified_by'] ?? null, $component['verified_at'] ?? null ) ) );
				if ( ! isset( $allowance_snapshots[ $sig ] ) ) {
					$allowance_snapshots[ $sig ] = $component;
				} else {
					$allowance_snapshots[ $sig ]['amount'] = Stockland_Money::add( $allowance_snapshots[ $sig ]['amount'], $component_amount );
				}
			}
			$used   += $days;
			$notes[] = $c['effective_from'] . ' (' . $days . '/' . $days_in_month . ' days)';
		}
		if ( $used ) {
			// Prorating each salary component separately can create one-cent rounding drift.
			// Align allowance evidence snapshots to the exact statutory totals before approval.
			foreach ( array(
				'TAXABLE' => 'taxable_allowance_total',
				'EXEMPT'  => 'exempt_allowance_total',
			) as $treatment => $total_field ) {
				$target        = Stockland_Money::minor( $totals[ $total_field ] );
				$actual        = 0;
				$keys          = array();
				$largest_key   = null;
				$largest_minor = -1;
				foreach ( $allowance_snapshots as $sig => $component ) {
					if ( strtoupper( (string) ( $component['tax_treatment'] ?? 'TAXABLE' ) ) !== $treatment ) {
						continue;
					}
					$amount_minor = Stockland_Money::minor( $component['amount'] ?? '0.00' );
					$actual      += $amount_minor;
					$keys[]       = $sig;
					if ( $amount_minor > $largest_minor ) {
						$largest_minor = $amount_minor;
						$largest_key   = $sig;}
				}
				$delta = $target - $actual;
				if ( 0 !== $delta ) {
					if ( null === $largest_key ) {
						throw new RuntimeException( 'Allowance audit snapshot is missing the ' . esc_html( (string) $treatment ) . ' component needed to reconcile ' . esc_html( (string) $employee['employee_id'] ) . '. Re-save compensation through Owner HR.' );
					}
					if ( abs( $delta ) > max( 5, count( $keys ) * 5 ) ) {
						throw new RuntimeException( 'Allowance component rounding differs from the statutory aggregate by more than a safe rounding tolerance for ' . esc_html( (string) $employee['employee_id'] ) . '. Re-save compensation.' );
					}
					$adjusted = Stockland_Money::minor( Stockland_Money::add( $allowance_snapshots[ $largest_key ]['amount'], Stockland_Money::decimal( $delta ) ) );
					if ( $adjusted < 0 ) {
						throw new RuntimeException( 'Allowance evidence snapshot could not be reconciled without a negative component for ' . esc_html( (string) $employee['employee_id'] ) . '.' );
					}
					$allowance_snapshots[ $largest_key ]['amount'] = Stockland_Money::decimal( $adjusted );
				}
			}
		}
		return $used ? array(
			'components'          => $totals,
			'notes'               => $notes,
			'days'                => $used,
			'days_in_month'       => $days_in_month,
			'allowance_breakdown' => array_values( $allowance_snapshots ),
		) : array();
	}

	/**
	 * Calculates payroll totals and statutory component summaries.
	 *
	 * @param int $period_id Payroll period ID.
	 * @return array|WP_Error Calculated payroll totals or a validation error.
	 */
	private function payroll_totals( $period_id ) {
		global $wpdb;
		$lines  = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE period_id=%d ORDER BY id', Stockland_DB::table( 'payroll_lines' ), $period_id ), ARRAY_A );
		$totals = array(
			'employee_count'         => count( $lines ),
			'gross_total'            => '0.00',
			'deduction_total'        => '0.00',
			'net_total'              => '0.00',
			'income_tax_total'       => '0.00',
			'employee_pension_total' => '0.00',
			'employer_pension_total' => '0.00',
			'other_deduction_total'  => '0.00',
		);
		foreach ( $lines as $line ) {
			foreach ( array(
				'gross_pay'             => 'gross_total',
				'deduction_total'       => 'deduction_total',
				'net_pay'               => 'net_total',
				'income_tax'            => 'income_tax_total',
				'employee_pension'      => 'employee_pension_total',
				'employer_pension'      => 'employer_pension_total',
				'other_deduction_total' => 'other_deduction_total',
			) as $field => $total ) {
				$totals[ $total ] = Stockland_Money::add( $totals[ $total ], $line[ $field ] ?? '0.00' );
			}
		}
		return $totals;
	}

	/**
	 * Saves calculated payroll totals for a draft payroll period.
	 *
	 * @param int $period_id Payroll period ID.
	 * @return mixed The HR/payroll operation result.
	 * @throws RuntimeException When payroll totals cannot be persisted.
	 */
	private function save_payroll_totals( $period_id ) {
		global $wpdb;
		$totals = $this->payroll_totals( $period_id );
		$ok     = Stockland_DB::update_record( Stockland_DB::table( 'payroll_periods' ), $totals, array( 'id' => (int) $period_id ) );
		if ( false === $ok ) {
			throw new RuntimeException( 'Payroll period totals could not be saved.' );
		}
		return $totals;
	}

	/**
	 * Generates draft payroll lines from eligible employee/compensation data.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When payroll generation validation or transaction processing fails.
	 * @throws RuntimeException When a database or payroll integrity invariant fails.
	 */
	public function payroll_generate( WP_REST_Request $r ) {
		if ( ! $this->payroll_manager() ) {
			return $this->deny( 'Only an active Owner or Management profile can prepare payroll.', 403 );
		}
		global $wpdb;
		$id = (int) $r['id'];
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start the payroll transaction.', array( 'status' => 500 ) );
		}
		try {
			$period = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'payroll_periods' ), $id ), ARRAY_A );
			if ( ! $period ) {
				throw new Exception( 'Payroll period not found.' );
			}
			if ( 'DRAFT' !== $period['status'] ) {
				throw new Exception( 'Only DRAFT payroll periods can be generated.' );
			}
			$month_start = substr( $period['start_date'], 0, 7 ) . '-01';
			if ( $period['start_date'] !== $month_start || gmdate( 'Y-m-t', strtotime( $period['start_date'] . ' 00:00:00 UTC' ) ) !== $period['end_date'] || substr( $period['start_date'], 0, 7 ) !== $period['period_key'] ) {
				throw new Exception( 'Payroll period must be a full calendar month with key YYYY-MM.' );
			}
			if ( $period['start_date'] < Stockland_Payroll_Statutory::EFFECTIVE_DATE ) {
				throw new Exception( 'This monthly statutory calculator starts on ' . Stockland_Payroll_Statutory::EFFECTIVE_DATE . '. The law applies from ' . Stockland_Payroll_Statutory::LAW_EFFECTIVE_DATE . '; July 2025 requires separately approved split-period treatment. Existing posted payroll must remain unchanged.' );
			}
			$existing = (int) Stockland_DB::read_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE period_id=%d FOR UPDATE', Stockland_DB::table( 'payroll_lines' ), $id ) );
			if ( $existing > 0 ) {
				throw new Exception( 'This payroll period already has lines. Update each DRAFT line with an explicit statutory component breakdown or use a new draft period; no duplicate lines were created.' );
			}
			$employees                  = Stockland_DB::read_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE status='ACTIVE' AND UPPER(COALESCE(employment_type,''))<>'RETAINER_SERVICE' AND (hire_date IS NULL OR hire_date<=%s) AND (termination_date IS NULL OR termination_date>=%s) ORDER BY full_name",
					Stockland_DB::table( 'employees' ),
					$period['end_date'],
					$period['start_date']
				),
				ARRAY_A
			);
			$excluded_service_contracts = Stockland_DB::read_results(
				$wpdb->prepare(
					"SELECT employee_id,full_name FROM %i WHERE status='ACTIVE' AND UPPER(COALESCE(employment_type,''))='RETAINER_SERVICE' AND (hire_date IS NULL OR hire_date<=%s) AND (termination_date IS NULL OR termination_date>=%s) ORDER BY employee_id",
					Stockland_DB::table( 'employees' ),
					$period['end_date'],
					$period['start_date']
				),
				ARRAY_A
			);
			$excluded_service_codes     = array_map(
				static function ( $e ) {
					return (string) $e['employee_id'];
				},
				(array) $excluded_service_contracts
			);
			$missing                    = array();
			$lines                      = array();
			foreach ( $employees as $employee ) {
				$schedule = $this->compensation_segments( $employee, $period );
				if ( ! $schedule ) {
					$missing[] = $employee['employee_id'] . ' — ' . $employee['full_name'] . ' (no effective compensation in the payroll month)';
					continue;}
				$c = $schedule['components'];
				try {
					$calc = Stockland_Payroll_Statutory::calculate( $c['base_salary'], $c['taxable_allowance_total'], $c['exempt_allowance_total'], $c['pensionable_salary'], $c['other_deduction_total'] );
				} catch ( Throwable $e ) {
					throw new RuntimeException( 'Payroll calculation failed for ' . $employee['employee_id'] . ': ' . $e->getMessage() );}
				$line    = array_merge(
					array(
						'period_id'                => $id,
						'employee_id'              => $employee['id'],
						'employee_code'            => $employee['employee_id'],
						'employee_name'            => $employee['full_name'],
						'branch_location_id'       => $employee['branch_location_id'],
						'notes'                    => 'Calculated from owner-classified effective compensation: ' . implode( ', ', $schedule['notes'] ) . '. ' . $this->compensation_notes( (int) $employee['id'], $period['end_date'] ),
						'allowance_breakdown_json' => wp_json_encode( $schedule['allowance_breakdown'] ?? array() ),
						'status'                   => 'DRAFT',
					),
					$calc
				);
				$lines[] = $line;
			}
			if ( $missing ) {
				throw new Exception( 'Payroll cannot be generated until these employee records are fixed: ' . implode( '; ', $missing ) );
			}
			if ( ! $lines ) {
				throw new Exception( 'No active employees are eligible for this payroll period.' );
			}
			foreach ( $lines as $line ) {
				if ( false === Stockland_DB::insert_record( Stockland_DB::table( 'payroll_lines' ), $line ) ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Payroll line insert failed.' );
				}
			}
			$totals = $this->save_payroll_totals( $id );
			if ( Stockland_Money::minor( $totals['gross_total'] ) <= 0 ) {
				throw new Exception( 'Payroll gross total must be greater than zero.' );
			}
			$exclusion_note = '';
			if ( $excluded_service_codes ) {
				$exclusion_note = ' Excluded RETAINER_SERVICE contracts from employee PAYE payroll: ' . implode( ', ', array_slice( $excluded_service_codes, 0, 12 ) ) . ( count( $excluded_service_codes ) > 12 ? ' and ' . ( count( $excluded_service_codes ) - 12 ) . ' more' : '' ) . '. Review the approved contract/payment procedure separately.';
				$prior          = trim( (string) $period['notes'] );
				$new_notes      = substr( trim( $prior . $exclusion_note ), 0, 500 );
				if ( false === Stockland_DB::update_record( Stockland_DB::table( 'payroll_periods' ), array( 'notes' => $new_notes ), array( 'id' => $id ) ) ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not record excluded service contracts on payroll period.' );
				}
			}
			$audit_detail = wp_json_encode(
				array(
					'tax_rule_version' => Stockland_Payroll_Statutory::RULE_VERSION,
					'excluded_retainer_service_employee_ids' => $excluded_service_codes,
					'other_notes'      => 'RETAINER_SERVICE is excluded from employee PAYE/pension payroll and requires its separately approved contract/payment treatment.',
				)
			);
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'PAYROLL_GENERATE', null, $totals['gross_total'], 'DRAFT', 'DRAFT', $audit_detail, 'PAY' . $id ) ) {
				throw new Exception( 'Audit failed; payroll was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll generation commit failed.' );
			}
			$detail                               = $this->payroll_detail( $id );
			$detail['excluded_service_contracts'] = $excluded_service_codes;
			return rest_ensure_response( $detail );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payroll_generate_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Returns permitted compensation notes and classifications.
	 *
	 * @param int   $employee_id Employee master ID.
	 * @param mixed $as_of Value for the as of argument.
	 * @return mixed The HR/payroll operation result.
	 */
	private function compensation_notes( $employee_id, $as_of ) {
		global $wpdb;
		$c = Stockland_DB::table( 'employee_compensation' );
		return (string) Stockland_DB::read_var( $wpdb->prepare( 'SELECT statutory_classification_note FROM %i WHERE employee_id=%d AND effective_from<=%s ORDER BY effective_from DESC,id DESC LIMIT 1', $c, $employee_id, $as_of ) );
	}

	/**
	 * Updates one editable draft payroll line with validated statutory values.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function payroll_line_update( WP_REST_Request $r ) {
		if ( ! $this->payroll_manager() ) {
			return $this->deny( 'Only active Stockland Management can edit amounts in a draft payroll line. The Owner uses the separate classification action.', 403 );
		}
		global $wpdb;
		$pid    = (int) $r['id'];
		$lid    = (int) $r['line_id'];
		$p      = $r->get_json_params();
		$period = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'payroll_periods' ), $pid ), ARRAY_A );
		if ( ! $period ) {
			return new WP_Error( 'not_found', 'Payroll period not found.', array( 'status' => 404 ) );
		}
		if ( 'DRAFT' !== $period['status'] ) {
			return new WP_Error( 'locked', 'Approved or paid payroll cannot be edited.', array( 'status' => 409 ) );
		}
		$line = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d AND period_id=%d', Stockland_DB::table( 'payroll_lines' ), $lid, $pid ), ARRAY_A );
		if ( ! $line ) {
			return new WP_Error( 'line_not_found', 'Payroll line not found.', array( 'status' => 404 ) );
		}
		$legacy = ( ( $line['statutory_status'] ?? 'LEGACY' ) !== 'CALCULATED' );
		if ( ! $legacy ) {
			$saved_components = json_decode( (string) ( $line['allowance_breakdown_json'] ?? '' ), true );
			if ( ! is_array( $saved_components ) ) {
				return new WP_Error( 'component_snapshot_incomplete', 'This line has no valid allowance component snapshot. Owner review is required before amounts can be edited.', array( 'status' => 409 ) );
			}
			$snapshot_taxable = '0.00';
			$snapshot_exempt  = '0.00';
			try {
				foreach ( $saved_components as $saved_component ) {
					if ( ! is_array( $saved_component ) ) {
						throw new InvalidArgumentException( 'Invalid allowance component.' );
					}
					$saved_amount = $this->money( $saved_component['amount'] ?? null );
					if ( strtoupper( (string) ( $saved_component['tax_treatment'] ?? 'TAXABLE' ) ) === 'EXEMPT' ) {
						$snapshot_exempt = Stockland_Money::add( $snapshot_exempt, $saved_amount );
					} else {
						$snapshot_taxable = Stockland_Money::add( $snapshot_taxable, $saved_amount );
					}
				}
			} catch ( Throwable $e ) {
				return new WP_Error( 'component_snapshot_invalid', 'This line has an invalid allowance component; no amount was changed.', array( 'status' => 409 ) );
			}
			if ( Stockland_Money::minor( $snapshot_taxable ) !== Stockland_Money::minor( $line['taxable_allowance_total'] ?? '0.00' ) || Stockland_Money::minor( $snapshot_exempt ) !== Stockland_Money::minor( $line['exempt_allowance_total'] ?? '0.00' ) ) {
				return new WP_Error( 'component_snapshot_mismatch', 'Allowance components do not reconcile to this calculated line. Owner classification review is required before further editing.', array( 'status' => 409 ) );
			}
		}
		if ( $legacy ) {
			foreach ( array( 'taxable_allowance_total', 'exempt_allowance_total', 'pensionable_salary', 'other_deduction_total' ) as $field ) {
				if ( ! array_key_exists( $field, $p ) || '' === $p[ $field ] ) {
					return new WP_Error( 'legacy_line_requires_classification', 'Legacy draft line has no auditable component split. Submit taxable allowance, exempt allowance, pensionable salary and other deductions explicitly before approving.', array( 'status' => 409 ) );
				}
			}
		}
		try {
			$taxable     = $this->money( $p['taxable_allowance_total'] ?? $line['taxable_allowance_total'] ?? '0.00' );
			$exempt      = $this->money( $p['exempt_allowance_total'] ?? $line['exempt_allowance_total'] ?? '0.00' );
			$pensionable = $this->money( $p['pensionable_salary'] ?? $line['pensionable_salary'] ?? $line['base_salary'] );
			$other       = $this->money( $p['other_deduction_total'] ?? $line['other_deduction_total'] ?? '0.00' );
			$calc        = Stockland_Payroll_Statutory::calculate( $line['base_salary'], $taxable, $exempt, $pensionable, $other );
		} catch ( Throwable $e ) {
			return new WP_Error( 'invalid_payroll_line', $e->getMessage(), array( 'status' => 400 ) );}
		$note                = sanitize_textarea_field( $p['notes'] ?? $line['notes'] ?? '' );
		$basis               = sanitize_textarea_field( $p['statutory_classification_note'] ?? '' );
		$evidence            = sanitize_text_field( $p['exemption_evidence_reference'] ?? '' );
		$exemption_confirmed = Stockland_Authority::role() === 'owner' && ! empty( $p['owner_confirmed_exemption'] );
		$refresh_exemption_snapshot = false;
		$current_exempt_minor = Stockland_Money::minor( $line['exempt_allowance_total'] ?? '0.00' );
		if ( Stockland_Authority::role() !== 'owner' && Stockland_Money::minor( $exempt ) !== $current_exempt_minor ) {
			return new WP_Error( 'owner_exemption_review_required', 'Only the Owner may change an allowance tax classification. Save other draft adjustments, then ask the Owner to review this exemption.', array( 'status' => 403 ) );
		}
		if ( Stockland_Money::minor( $exempt ) > 0 ) {
			$old_exempt      = Stockland_Money::minor( $line['exempt_allowance_total'] ?? '0.00' );
			$same_exempt     = Stockland_Money::minor( $exempt ) === $old_exempt;
			$saved_breakdown       = json_decode( (string) ( $line['allowance_breakdown_json'] ?? '' ), true );
			$saved_exempt_total    = '0.00';
			$verified_exempt_total = '0.00';
			foreach ( (array) $saved_breakdown as $saved ) {
				if ( strtoupper( (string) ( $saved['tax_treatment'] ?? '' ) ) !== 'EXEMPT' ) {
					continue;
				}
				try {
					$saved_amount = $this->money( $saved['amount'] ?? '0.00' );
				} catch ( Throwable $e ) {
					return new WP_Error( 'invalid_exemption_snapshot', 'Saved exemption evidence contains an invalid amount; the draft remains unchanged and requires authorized review.', array( 'status' => 409 ) );
				}
				$saved_exempt_total  = Stockland_Money::add( $saved_exempt_total, $saved_amount );
				$has_current_evidence = ! empty( $saved['owner_verified'] )
					&& strlen( trim( (string) ( $saved['legal_basis'] ?? '' ) ) ) >= 20
					&& strlen( trim( (string) ( $saved['evidence_reference'] ?? '' ) ) ) >= 4;
				if ( $has_current_evidence ) {
					$verified_exempt_total = Stockland_Money::add( $verified_exempt_total, $saved_amount );
				}
			}
			$saved_evidence = Stockland_Money::minor( $saved_exempt_total ) === Stockland_Money::minor( $exempt )
				&& Stockland_Money::minor( $verified_exempt_total ) === Stockland_Money::minor( $exempt );
			$refresh_exemption_snapshot = ! $same_exempt || ! $saved_evidence;
			if ( $refresh_exemption_snapshot ) {
				if ( strlen( trim( $basis ) ) < 20 || strlen( trim( $evidence ) ) < 4 || ! $exemption_confirmed ) {
					return new WP_Error( 'classification_basis_required', 'An exempt payroll adjustment requires a current legal clause, supporting evidence reference, and explicit Owner confirmation.', array( 'status' => 400 ) );
				}
			}
		}
		if ( Stockland_Money::minor( $pensionable ) !== Stockland_Money::minor( $line['base_salary'] ) && strlen( trim( $basis ) ) < 20 ) {
			return new WP_Error( 'classification_basis_required', 'A non-standard pensionable salary requires a documented legal/contract basis of at least 20 characters.', array( 'status' => 400 ) );
		}
		if ( '' !== $basis ) {
			$note = substr( $note . ' | Classification basis: ' . $basis . ( '' !== $evidence ? ' | Evidence: ' . $evidence : '' ), 0, 500 );
		}
		$line_snapshot = json_decode( (string) ( $line['allowance_breakdown_json'] ?? '' ), true );
		if ( ! is_array( $line_snapshot ) ) {
			$line_snapshot = array();
		}
		$snapshot_tax    = '0.00';
		$snapshot_exempt = '0.00';
		foreach ( $line_snapshot as $component ) {
			if ( strtoupper( (string) ( $component['tax_treatment'] ?? 'TAXABLE' ) ) === 'EXEMPT' ) {
				$snapshot_exempt = Stockland_Money::add( $snapshot_exempt, $component['amount'] ?? '0.00' );
			} else {
				$snapshot_tax = Stockland_Money::add( $snapshot_tax, $component['amount'] ?? '0.00' );
			}
		}
		if ( Stockland_Money::minor( $snapshot_tax ) !== Stockland_Money::minor( $taxable ) || Stockland_Money::minor( $snapshot_exempt ) !== Stockland_Money::minor( $exempt ) || $refresh_exemption_snapshot ) {
			$preserved_exempt_components = array();
			$same_saved_exemption        = Stockland_Money::minor( $exempt ) > 0
				&& Stockland_Money::minor( $exempt ) === Stockland_Money::minor( $line['exempt_allowance_total'] ?? '0.00' )
				&& Stockland_Money::minor( $snapshot_exempt ) === Stockland_Money::minor( $exempt );
			if ( $same_saved_exemption && $saved_evidence ) {
				foreach ( $line_snapshot as $component ) {
					if ( strtoupper( (string) ( $component['tax_treatment'] ?? 'TAXABLE' ) ) === 'EXEMPT' ) {
						$preserved_exempt_components[] = $component;
					}
				}
			}
			$line_snapshot = array();
			if ( Stockland_Money::minor( $taxable ) > 0 ) {
				$line_snapshot[] = array(
					'category'           => 'OTHER',
					'label'              => 'Draft line taxable adjustment',
					'amount'             => $taxable,
					'tax_treatment'      => 'TAXABLE',
					'legal_basis'        => '',
					'evidence_reference' => '',
					'owner_verified'     => false,
				);
			}
			if ( $preserved_exempt_components ) {
				$line_snapshot = array_merge( $line_snapshot, $preserved_exempt_components );
			} elseif ( Stockland_Money::minor( $exempt ) > 0 ) {
				$line_snapshot[] = array(
					'category'           => 'OTHER',
					'label'              => 'Draft line exempt adjustment',
					'amount'             => $exempt,
					'tax_treatment'      => 'EXEMPT',
					'legal_basis'        => $basis,
					'evidence_reference' => $evidence,
					'owner_verified'     => $exemption_confirmed,
					'verified_by'        => $exemption_confirmed ? (int) get_current_user_id() : null,
					'verified_at'        => $exemption_confirmed ? current_time( 'mysql' ) : null,
				);
			}
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll-line transaction.', array( 'status' => 500 ) );
		}
		try {
			$locked_period = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'payroll_periods' ), $pid ), ARRAY_A );
			if ( ! $locked_period || 'DRAFT' !== (string) $locked_period['status'] ) {
				throw new Exception( 'Payroll changed state while you were editing it. Refresh the period and try again; submitted payroll remains locked.' );
			}
			$locked_line = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d AND period_id=%d FOR UPDATE', Stockland_DB::table( 'payroll_lines' ), $lid, $pid ), ARRAY_A );
			if ( ! $locked_line ) {
				throw new Exception( 'Payroll line changed or was removed. Refresh the period before retrying.' );
			}
			foreach ( array( 'base_salary', 'taxable_allowance_total', 'exempt_allowance_total', 'pensionable_salary', 'other_deduction_total', 'statutory_status', 'tax_rule_version', 'allowance_breakdown_json' ) as $field ) {
				if ( (string) ( $locked_line[ $field ] ?? '' ) !== (string) ( $line[ $field ] ?? '' ) ) {
					throw new Exception( 'Payroll line changed while you were reviewing it. Refresh the review panel before retrying.' );
				}
			}
			$update = array_merge(
				$calc,
				array(
					'notes'                    => $note,
					'allowance_breakdown_json' => wp_json_encode( $line_snapshot ),
				)
			);
			if ( false === Stockland_DB::update_record(
				Stockland_DB::table( 'payroll_lines' ),
				$update,
				array(
					'id'        => $lid,
					'period_id' => $pid,
				)
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Payroll line could not be updated.' );
			}
			$totals = $this->save_payroll_totals( $pid );
			if ( ! Stockland_Audit::log( get_current_user_id(), $line['branch_location_id'], 'PAYROLL_LINE_UPDATE', null, $calc['net_pay'], $line['net_pay'], $calc['net_pay'], 'Statutory line re-calculated under ' . Stockland_Payroll_Statutory::RULE_VERSION . '; ' . $note, 'PAY' . $pid ) ) {
				throw new Exception( 'Audit failed; line update was rolled back.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll-line commit failed.' );
			}
			return rest_ensure_response( $this->payroll_detail( $pid ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payroll_line_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Returns the detail of a payroll period authorized for the current user.
	 *
	 * @param int $id ERP record ID.
	 * @return mixed The HR/payroll operation result.
	 */
	private function payroll_detail( $id ) {
		global $wpdb;
		$p = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'payroll_periods' ), $id ), ARRAY_A );
		if ( ! $p ) {
			return null;
		}$p['lines'] = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE period_id=%d ORDER BY employee_name', Stockland_DB::table( 'payroll_lines' ), $id ), ARRAY_A );
		return $p;}

	/**
	 * Reclassifies one allowance component in a DRAFT payroll line.
	 *
	 * This endpoint is Owner-only and changes only the tax treatment of an existing
	 * component. Amounts, other components and statutory rates remain unchanged.
	 *
	 * @param WP_REST_Request $r REST request containing the component classification.
	 * @return mixed Updated draft payroll detail.
	 */
	public function payroll_line_classification_update( WP_REST_Request $r ) {
		if ( ! Stockland_Authority::can_approve_payroll() || Stockland_Authority::role() !== 'owner' ) {
			return $this->deny( 'Only the active Stockland Owner may verify a payroll exemption or tax reclassification.', 403 );
		}
		global $wpdb;
		$period_id = (int) $r['id'];
		$line_id   = (int) $r['line_id'];
		$p         = (array) $r->get_json_params();
		$component_index = filter_var( $p['component_index'] ?? null, FILTER_VALIDATE_INT );
		if ( false === $component_index || $component_index < 0 ) {
			return new WP_Error( 'invalid_component_index', 'Select an existing allowance component to reclassify.', array( 'status' => 400 ) );
		}
		$tax_treatment = strtoupper( sanitize_text_field( (string) ( $p['tax_treatment'] ?? '' ) ) );
		if ( ! in_array( $tax_treatment, array( 'TAXABLE', 'EXEMPT' ), true ) ) {
			return new WP_Error( 'invalid_tax_treatment', 'Choose TAXABLE or EXEMPT for the selected component.', array( 'status' => 400 ) );
		}
		$basis        = sanitize_textarea_field( (string) ( $p['statutory_classification_note'] ?? '' ) );
		$evidence     = sanitize_text_field( (string) ( $p['exemption_evidence_reference'] ?? '' ) );
		$confirmed    = ! empty( $p['owner_confirmed_classification'] );
		$classification_note = sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) );
		if ( ! $confirmed || strlen( trim( $basis ) ) < 20 ) {
			return new WP_Error( 'classification_basis_required', 'Owner confirmation and a specific legal/contract basis or reason of at least 20 characters are required.', array( 'status' => 400 ) );
		}
		if ( 'EXEMPT' === $tax_treatment && strlen( trim( $evidence ) ) < 4 ) {
			return new WP_Error( 'classification_evidence_required', 'An EXEMPT component requires a supporting evidence reference of at least 4 characters.', array( 'status' => 400 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll classification transaction.', array( 'status' => 500 ) );
		}
		try {
			$period = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'payroll_periods' ), $period_id ), ARRAY_A );
			if ( ! $period ) {
				throw new Exception( 'Payroll period not found.' );
			}
			if ( 'DRAFT' !== $period['status'] ) {
				throw new Exception( 'Only DRAFT payroll can be classified. Submitted, approved and paid periods are locked.' );
			}
			$line = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d AND period_id=%d FOR UPDATE', Stockland_DB::table( 'payroll_lines' ), $line_id, $period_id ), ARRAY_A );
			if ( ! $line ) {
				throw new Exception( 'Payroll line not found in this period.' );
			}
			if ( 'CALCULATED' !== (string) ( $line['statutory_status'] ?? '' ) || Stockland_Payroll_Statutory::RULE_VERSION !== (string) ( $line['tax_rule_version'] ?? '' ) ) {
				throw new Exception( 'Recalculate this draft line under the current statutory rule before classifying an allowance.' );
			}
			$components = json_decode( (string) ( $line['allowance_breakdown_json'] ?? '' ), true );
			if ( ! is_array( $components ) || ! array_key_exists( $component_index, $components ) || ! is_array( $components[ $component_index ] ) ) {
				throw new Exception( 'The saved allowance component snapshot is missing or incomplete; no change was made.' );
			}
			$prior_taxable = '0.00';
			$prior_exempt  = '0.00';
			foreach ( $components as $saved_component ) {
				if ( ! is_array( $saved_component ) ) {
					throw new Exception( 'The saved allowance snapshot contains an invalid component; no change was made.' );
				}
				try {
					$saved_amount = $this->money( $saved_component['amount'] ?? null );
				} catch ( Throwable $e ) {
					throw new Exception( 'The saved allowance snapshot contains an invalid amount; no change was made.' );
				}
				$saved_treatment = strtoupper( (string) ( $saved_component['tax_treatment'] ?? 'TAXABLE' ) );
				if ( ! in_array( $saved_treatment, array( 'TAXABLE', 'EXEMPT' ), true ) ) {
					throw new Exception( 'The saved allowance snapshot contains an unknown tax treatment; no change was made.' );
				}
				if ( 'EXEMPT' === $saved_treatment ) {
					$prior_exempt = Stockland_Money::add( $prior_exempt, $saved_amount );
				} else {
					$prior_taxable = Stockland_Money::add( $prior_taxable, $saved_amount );
				}
			}
			if ( Stockland_Money::minor( $prior_taxable ) !== Stockland_Money::minor( $line['taxable_allowance_total'] ?? '0.00' ) || Stockland_Money::minor( $prior_exempt ) !== Stockland_Money::minor( $line['exempt_allowance_total'] ?? '0.00' ) ) {
				throw new Exception( 'Allowance components do not reconcile to the saved payroll line totals. No change was made; Management must resolve the draft line first.' );
			}
			$old_component = $components[ $component_index ];
			try {
				$amount = $this->money( $old_component['amount'] ?? null );
			} catch ( Throwable $e ) {
				throw new Exception( 'The selected component has an invalid saved amount; it requires authorized review before classification.' );
			}
			if ( Stockland_Money::minor( $amount ) <= 0 ) {
				throw new Exception( 'A zero-value component cannot be reclassified. Correct the draft compensation source instead.' );
			}
			$old_treatment = strtoupper( (string) ( $old_component['tax_treatment'] ?? 'TAXABLE' ) );
			if ( ! in_array( $old_treatment, array( 'TAXABLE', 'EXEMPT' ), true ) ) {
				throw new Exception( 'The selected component has an unknown saved tax treatment; no change was made.' );
			}
			$components[ $component_index ]['tax_treatment'] = $tax_treatment;
			if ( 'EXEMPT' === $tax_treatment ) {
				$components[ $component_index ]['legal_basis']        = substr( trim( $basis ), 0, 500 );
				$components[ $component_index ]['evidence_reference'] = substr( trim( $evidence ), 0, 190 );
				$components[ $component_index ]['owner_verified']     = true;
				$components[ $component_index ]['verified_by']        = (int) get_current_user_id();
				$components[ $component_index ]['verified_at']        = current_time( 'mysql' );
			} else {
				$components[ $component_index ]['legal_basis']        = '';
				$components[ $component_index ]['evidence_reference'] = '';
				$components[ $component_index ]['owner_verified']     = false;
				$components[ $component_index ]['verified_by']        = null;
				$components[ $component_index ]['verified_at']        = null;
			}
			$taxable = '0.00';
			$exempt  = '0.00';
			foreach ( $components as $component ) {
				if ( ! is_array( $component ) ) {
					throw new Exception( 'The allowance snapshot contains an invalid component; no change was made.' );
				}
				try {
					$component_amount = $this->money( $component['amount'] ?? null );
				} catch ( Throwable $e ) {
					throw new Exception( 'An allowance component contains an invalid amount; no change was made.' );
				}
				if ( strtoupper( (string) ( $component['tax_treatment'] ?? 'TAXABLE' ) ) === 'EXEMPT' ) {
					$exempt = Stockland_Money::add( $exempt, $component_amount );
				} else {
					$taxable = Stockland_Money::add( $taxable, $component_amount );
				}
			}
			if ( Stockland_Money::minor( $taxable ) === 0 && Stockland_Money::minor( $exempt ) === 0 && ! $components ) {
				throw new Exception( 'There are no allowance components to classify.' );
			}
			$calc = Stockland_Payroll_Statutory::calculate(
				$line['base_salary'],
				$taxable,
				$exempt,
				$line['pensionable_salary'] ?? $line['base_salary'],
				$line['other_deduction_total'] ?? '0.00'
			);
			$note = substr(
				trim(
					(string) ( $line['notes'] ?? '' ) . ' | Owner classification: ' . $classification_note .
					' | Component ' . ( (int) $component_index + 1 ) . ' ' . $old_treatment . ' → ' . $tax_treatment .
					' | Basis: ' . trim( $basis ) .
					( '' !== $evidence ? ' | Evidence: ' . trim( $evidence ) : '' )
				),
				0,
				500
			);
			if ( false === Stockland_DB::update_record(
				Stockland_DB::table( 'payroll_lines' ),
				array_merge(
					$calc,
					array(
						'notes'                    => $note,
						'allowance_breakdown_json' => wp_json_encode( $components ),
					)
				),
				array(
					'id'        => $line_id,
					'period_id' => $period_id,
				)
			) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Payroll classification could not be saved.' );
			}
			$totals = $this->save_payroll_totals( $period_id );
			$audit = wp_json_encode(
				array(
					'rule_version'     => Stockland_Payroll_Statutory::RULE_VERSION,
					'component_index'  => (int) $component_index,
					'old_treatment'    => $old_treatment,
					'new_treatment'    => $tax_treatment,
					'amount'           => $amount,
					'legal_basis'      => trim( $basis ),
					'evidence'         => trim( $evidence ),
					'classified_by'    => (int) get_current_user_id(),
				)
			);
			if ( ! Stockland_Audit::log( get_current_user_id(), $line['branch_location_id'], 'PAYROLL_EXEMPTION_VERIFY', null, $calc['net_pay'], $line['net_pay'], $calc['net_pay'], (string) $audit, 'PAY' . $period_id ) ) {
				throw new Exception( 'Audit failed; payroll classification was rolled back.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll classification commit failed.' );
			}
			return rest_ensure_response( $this->payroll_detail( $period_id ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payroll_classification_failed', $e->getMessage(), array( 'status' => 409 ) );
		}
	}

	/**
	 * Management submits a completed draft; only the Owner may approve/pay it.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function payroll_submit( WP_REST_Request $r ) {
		if ( ! $this->payroll_manager() ) {
			return $this->deny( 'Only active Stockland Management may prepare and submit payroll for Owner approval.', 403 );
		}
		global $wpdb;
		$id  = (int) $r['id'];
		$uid = get_current_user_id();
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll submission transaction.', array( 'status' => 500 ) );
		}
		try {
			$p = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'payroll_periods' ), $id ), ARRAY_A );
			if ( ! $p ) {
				throw new Exception( 'Payroll period not found.' );
			}
			if ( 'DRAFT' !== $p['status'] ) {
				throw new Exception( 'Only DRAFT payroll may be submitted.' );
			}
			if ( Stockland_Authority::role( $uid ) !== 'management' || (int) $p['created_by'] !== $uid ) {
				throw new Exception( 'Only the Management creator may submit its own draft payroll period.' );
			}
			$count = (int) Stockland_DB::read_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE period_id=%d', Stockland_DB::table( 'payroll_lines' ), $id ) );
			if ( $count < 1 ) {
				throw new Exception( 'Generate payroll lines before submission.' );
			}
			$wpdb->last_error = '';
			$unverified_lines = (int) Stockland_DB::read_var(
				$wpdb->prepare(
					' SELECT COUNT(*) FROM %i WHERE period_id=%d AND (COALESCE(statutory_status,%s)<>%s OR COALESCE(tax_rule_version,%s)<>%s)',
					Stockland_DB::table( 'payroll_lines' ),
					$id,
					'',
					'CALCULATED',
					'',
					Stockland_Payroll_Statutory::RULE_VERSION
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				throw new Exception( 'Could not verify payroll line classifications; submission was blocked.' );
			}
			if ( $unverified_lines > 0 ) {
				throw new Exception( 'Recalculate and review every payroll line under the current statutory rule before submission. No status change was made.' );
			}
			$totals = $this->payroll_totals( $id );
			if ( Stockland_Money::minor( $totals['gross_total'] ) <= 0 ) {
				throw new Exception( 'Payroll gross must be positive before submission.' );
			}
			if ( false === Stockland_DB::update_record( Stockland_DB::table( 'payroll_periods' ), array( 'status' => 'SUBMITTED' ), array( 'id' => $id ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not submit payroll.' );
			}
			if ( ! Stockland_Audit::log( $uid, null, 'PAYROLL_SUBMIT', null, $totals['gross_total'], 'DRAFT', 'SUBMITTED', 'Payroll submitted for independent Owner approval under ' . Stockland_Payroll_Statutory::RULE_VERSION, 'PAY' . $id ) ) {
				throw new Exception( 'Audit failed; payroll submission was rolled back.' );
			}
			if ( ! Stockland_Notifications::push_role( 'owner', null, 'payroll_approval', 'Payroll awaiting Owner approval', 'Payroll period ' . $p['period_key'] . ' has been submitted. Review the calculated statutory totals before approval.', 'payroll', $id, 'action' ) ) {
				throw new Exception( 'Owner notification could not be queued; submission was rolled back.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll submit commit failed.' );
			}
			return rest_ensure_response( $this->payroll_detail( $id ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payroll_submit_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Performs the configured final payroll approval checks.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function payroll_approve( WP_REST_Request $r ) {
		if ( ! $this->payroll_owner() ) {
			return $this->deny( 'Only the Owner can approve payroll.', 403 );
		}
		global $wpdb;
		$id = (int) $r['id'];
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll approval transaction.', array( 'status' => 500 ) );
		}
		try {
			$p = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'payroll_periods' ), $id ), ARRAY_A );
			if ( ! $p ) {
				throw new Exception( 'Payroll period not found.' );
			}
			$creator_role = Stockland_Authority::role( (int) $p['created_by'] );
			if ( 'SUBMITTED' !== $p['status'] || 'management' !== $creator_role ) {
				throw new Exception( 'Payroll must be submitted by Management and show SUBMITTED before Owner approval. Legacy Owner-created drafts require Management to review and submit before approval.' );
			}
			if ( get_current_user_id() === (int) $p['created_by'] ) {
				throw new Exception( 'The submitter cannot approve their own payroll.' );
			}
			$lines = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE period_id=%d FOR UPDATE', Stockland_DB::table( 'payroll_lines' ), $id ), ARRAY_A );
			if ( ! $lines ) {
				throw new Exception( 'Generate payroll before approval.' );
			}
			$totals = $this->payroll_totals( $id );
			foreach ( $lines as $line ) {
				if ( ( $line['statutory_status'] ?? '' ) !== 'CALCULATED' || ( $line['tax_rule_version'] ?? '' ) !== Stockland_Payroll_Statutory::RULE_VERSION ) {
					throw new Exception( 'Payroll approval blocked: every line must be calculated under the current approved rule version. Recalculate/review the DRAFT lines.' );
				}
				$line_exempt = Stockland_Money::decimal( Stockland_Money::minor( $line['exempt_allowance_total'] ?? '0.00' ) );
				if ( Stockland_Money::minor( $line_exempt ) > 0 ) {
					$components = json_decode( (string) ( $line['allowance_breakdown_json'] ?? '' ), true );
					if ( ! is_array( $components ) ) {
						throw new Exception( 'Payroll approval blocked: exempt allowance evidence snapshot is missing for ' . $line['employee_code'] . '.' );
					}
					$verified_total = '0.00';
					foreach ( $components as $component ) {
						if ( strtoupper( (string) ( $component['tax_treatment'] ?? '' ) ) !== 'EXEMPT' ) {
							continue;
						}if ( empty( $component['owner_verified'] ) || strlen( trim( (string) ( $component['legal_basis'] ?? '' ) ) ) < 20 || strlen( trim( (string) ( $component['evidence_reference'] ?? '' ) ) ) < 4 ) {
							throw new Exception( 'Payroll approval blocked: exemption evidence is incomplete for ' . $line['employee_code'] . '.' );
						}$verified_total = Stockland_Money::add( $verified_total, $component['amount'] ?? '0.00' );}
					if ( Stockland_Money::minor( $verified_total ) !== Stockland_Money::minor( $line_exempt ) ) {
						throw new Exception( 'Payroll approval blocked: exempt amount and allowance evidence do not reconcile for ' . $line['employee_code'] . '.' );
					}
				}
			}
			if ( Stockland_Money::minor( $totals['gross_total'] ) <= 0 ) {
				throw new Exception( 'Payroll gross total must be greater than zero.' );
			}
			$debits               = Stockland_Money::add( $totals['gross_total'], $totals['employer_pension_total'] );
			$deduction_reconciled = Stockland_Money::add( Stockland_Money::add( $totals['income_tax_total'], $totals['employee_pension_total'] ), $totals['other_deduction_total'] );
			$credits              = Stockland_Money::add( Stockland_Money::add( $totals['net_total'], $deduction_reconciled ), $totals['employer_pension_total'] );
			if ( Stockland_Money::minor( Stockland_Money::subtract( $debits, $credits ) ) !== 0 ) {
				throw new Exception( 'Payroll gross, tax, pension, other deductions and net pay do not reconcile to the cent.' );
			}
			if ( ! Stockland_Accounting::ensure_payroll_statutory_accounts() ) {
				throw new Exception( 'Required statutory payroll GL accounts are missing or inactive.' );
			}
			$journal_lines = array();
			$push          = function ( $code, $debit, $credit, $memo = '' ) use ( &$journal_lines ) {
				if ( Stockland_Money::minor( $debit ) > 0 || Stockland_Money::minor( $credit ) > 0 ) {
					$journal_lines[] = array(
						'account_code' => $code,
						'debit'        => $debit,
						'credit'       => $credit,
						'location_id'  => null,
						'memo'         => $memo,
					);
				}
			};
			$push( '6200', $totals['gross_total'], '0.00', 'Gross payroll and classified allowances' );
			$push( '6210', $totals['employer_pension_total'], '0.00', 'Employer pension contribution' );
			$push( '2020', '0.00', $totals['net_total'], 'Net salary payable to employees' );
			$push( '2031', '0.00', $totals['income_tax_total'], 'Employment income tax withheld' );
			$push( '2030', '0.00', $totals['employee_pension_total'], 'Employee pension contribution payable' );
			$push( '2032', '0.00', $totals['employer_pension_total'], 'Employer pension contribution payable' );
			$push( '2033', '0.00', $totals['other_deduction_total'], 'Other payroll deductions payable' );
			$jid = Stockland_Accounting::post_journal( $p['end_date'], 'PAYROLL', (string) $id, 'Payroll ' . $p['period_key'] . ' / ' . Stockland_Payroll_Statutory::RULE_VERSION, $journal_lines, get_current_user_id() );
			if ( ! $jid ) {
				throw new Exception( 'Payroll journal could not be posted or balanced.' );
			}
			$period_update = array_merge(
				$totals,
				array(
					'status'      => 'APPROVED',
					'journal_id'  => $jid,
					'approved_by' => get_current_user_id(),
					'approved_at' => current_time( 'mysql' ),
				)
			);
			$pok           = Stockland_DB::update_record( Stockland_DB::table( 'payroll_periods' ), $period_update, array( 'id' => $id ) );
			if ( false === $pok ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Payroll approval state could not be saved.' );
			}
			$audit_detail = wp_json_encode(
				array(
					'tax_rule_version'       => Stockland_Payroll_Statutory::RULE_VERSION,
					'income_tax_total'       => $totals['income_tax_total'],
					'employee_pension_total' => $totals['employee_pension_total'],
					'employer_pension_total' => $totals['employer_pension_total'],
					'other_deduction_total'  => $totals['other_deduction_total'],
					'journal_id'             => $jid,
				)
			);
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'PAYROLL_APPROVE', null, $totals['gross_total'], $p['status'], 'APPROVED', $audit_detail, 'PAY' . $id ) ) {
				throw new Exception( 'Audit failed; payroll approval was rolled back.' );
			}
			if ( ! Stockland_Notifications::push_role( 'management', null, 'payroll_decision', 'Payroll approved by Owner', 'Payroll period ' . $p['period_key'] . ' approved and posted to GL journal ' . $jid . '.', 'payroll', $id, 'info' ) ) {
				throw new Exception( 'Management notification could not be queued; payroll approval was rolled back.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll approval commit failed.' );
			}
			return rest_ensure_response( $this->payroll_detail( $id ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payroll_approve_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Records payroll payment under the approved workflow.
	 *
	 * @param WP_REST_Request $r REST request containing the HR/payroll parameters.
	 * @return mixed The HR/payroll operation result.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	public function payroll_pay( WP_REST_Request $r ) {
		if ( ! $this->payroll_owner() ) {
			return $this->deny( 'Only the Owner can pay payroll.', 403 );
		}global $wpdb;
		$id     = (int) $r['id'];
		$p      = $r->get_json_params();
		$method = strtoupper( sanitize_text_field( $p['method'] ?? 'BANK' ) );
		if ( ! in_array( $method, array( 'BANK', 'CASH' ), true ) ) {
			return new WP_Error( 'invalid', 'Payment method must be BANK or CASH.', array( 'status' => 400 ) );
		}$account = 'BANK' === $method ? '1010' : '1000';
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll payment transaction.', array( 'status' => 500 ) );
		}try {
			$period = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'payroll_periods' ), $id ), ARRAY_A );
			if ( ! $period ) {
				throw new Exception( 'Payroll period not found.' );
			}if ( 'APPROVED' !== $period['status'] ) {
				throw new Exception( 'Only approved payroll can be paid.' );
			}$amount = $this->money( $period['net_total'] );
			if ( $amount <= 0 ) {
				throw new Exception( 'Payroll net total must be greater than zero.' );
			}$jid = Stockland_Accounting::post_journal(
				current_time( 'Y-m-d' ),
				'PAYROLL_PAYMENT',
				(string) $id,
				'Payroll payment ' . $period['period_key'],
				array(
					array(
						'account_code' => '2020',
						'debit'        => $amount,
						'credit'       => 0,
						'location_id'  => null,
					),
					array(
						'account_code' => $account,
						'debit'        => 0,
						'credit'       => $amount,
						'location_id'  => null,
					),
				),
				get_current_user_id()
			);
			if ( ! $jid ) {
				throw new Exception( 'Payroll payment journal could not be posted.' );
			}$pok = Stockland_DB::update_record(
				Stockland_DB::table( 'payroll_periods' ),
				array(
					'status'         => 'PAID',
					'paid_by'        => get_current_user_id(),
					'paid_at'        => current_time( 'mysql' ),
					'payment_method' => $method,
				),
				array( 'id' => $id )
			);
			if ( false === $pok ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Payroll payment state could not be saved.' );
			}if ( ! Stockland_Audit::log( get_current_user_id(), null, 'PAYROLL_PAY', null, $amount, 'APPROVED', 'PAID', 'Owner posted payroll payment via ' . $method, 'PAY' . $id ) ) {
				throw new Exception( 'Audit failed; payment was rolled back.' );
			}if ( ! Stockland_Notifications::push_role( 'management', null, 'payroll_paid', 'Payroll payment completed', 'Payroll period ' . $period['period_key'] . ' paid by Owner via ' . $method . '.', 'payroll', $id, 'info' ) ) {
				throw new Exception( 'Management notification could not be queued; payroll payment was rolled back.' );
			}if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll payment commit failed.' );
			}return rest_ensure_response( $this->payroll_detail( $id ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'payroll_pay_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
