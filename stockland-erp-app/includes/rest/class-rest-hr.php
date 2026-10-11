<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stockland_REST_HR extends Stockland_REST_Base {
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) ); }

	private function owner() {
		return Stockland_Authority::can_manage_hr(); }
	private function payroll_manager() {
		return Stockland_Authority::can_manage_payroll(); }
	private function payroll_owner() {
		return Stockland_Authority::can_approve_payroll(); }
	private function deny( $message = 'You do not have HR authority.', $status = 403 ) {
		return new WP_Error( 'forbidden', $message, array( 'status' => $status ) ); }
	private function money( $v ) {
		return Stockland_Money::decimal( Stockland_Money::minor( $v ) ); }
	private function valid_date( $v, $fallback = null ) {
		$v = sanitize_text_field( (string) $v );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $fallback;
		} return $v; }

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

	private function employee_rows( $with_comp = false ) {
		global $wpdb;
		$e = Stockland_DB::table( 'employees' );
		$l = Stockland_DB::table( 'locations' );
		if ( ! $with_comp ) {
			return $wpdb->get_results( "SELECT e.*,l.location_name FROM {$e} e LEFT JOIN {$l} l ON l.id=e.branch_location_id ORDER BY e.status DESC,e.full_name", ARRAY_A );
		}
		$c = Stockland_DB::table( 'employee_compensation' );
		// Do not break the whole HR page during an add-only upgrade. Until the owner runs
		// the protected HR schema migration, return a NULL alias and let Save explain the gap.
		$gaps             = class_exists( 'Stockland_HR' ) ? Stockland_HR::upgrade_gaps() : array( 'employee_compensation.allowance_breakdown_json' );
		$breakdown_select = in_array( 'employee_compensation.allowance_breakdown_json', $gaps, true ) ? 'NULL current_allowance_breakdown_json' : 'c.allowance_breakdown_json current_allowance_breakdown_json';
		$sql              = "SELECT e.*,l.location_name,COALESCE(c.base_salary,0) current_base_salary,COALESCE(c.allowance_total,0) current_allowance_total,COALESCE(c.standard_deduction_total,0) current_deduction_total,c.taxable_allowance_total current_taxable_allowance_total,c.exempt_allowance_total current_exempt_allowance_total,c.pensionable_salary current_pensionable_salary,c.statutory_classification_status current_statutory_classification_status,c.statutory_classification_note current_statutory_classification_note,{$breakdown_select},c.effective_from compensation_effective_from FROM {$e} e LEFT JOIN {$l} l ON l.id=e.branch_location_id LEFT JOIN {$c} c ON c.id=(SELECT c2.id FROM {$c} c2 WHERE c2.employee_id=e.id AND c2.effective_from<=%s ORDER BY c2.effective_from DESC,c2.id DESC LIMIT 1) ORDER BY e.status DESC,e.full_name";
		return $wpdb->get_results( $wpdb->prepare( $sql, current_time( 'Y-m-d' ) ), ARRAY_A );
	}
	private function payroll_rows() {
		global $wpdb;
		$p = Stockland_DB::table( 'payroll_periods' );
		return $wpdb->get_results( "SELECT p.*,u.display_name approved_by_name FROM {$p} p LEFT JOIN {$wpdb->users} u ON u.ID=p.approved_by ORDER BY p.end_date DESC,p.id DESC LIMIT 100", ARRAY_A );
	}

	public function employees( WP_REST_Request $r ) {
		return rest_ensure_response( $this->employee_rows( Stockland_Authority::can_view_payroll() ) ); }

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
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'employees' ) . ' WHERE employee_id=%s', $code ) ) ) {
			return new WP_Error( 'duplicate', 'Employee ID already exists.', array( 'status' => 409 ) );
		}
		$branch = isset( $p['branch_location_id'] ) && '' !== $p['branch_location_id'] ? (int) $p['branch_location_id'] : null;
		if ( $branch && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE id=%d AND is_active=1', $branch ) ) ) {
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
			'status'             => in_array( strtoupper( $p['status'] ?? 'ACTIVE' ), array( 'ACTIVE', 'INACTIVE', 'TERMINATED' ), true ) ? strtoupper( $p['status'] ) : 'ACTIVE',
			'phone'              => sanitize_text_field( $p['phone'] ?? '' ),
			'email'              => sanitize_email( $p['email'] ?? '' ),
			'notes'              => sanitize_textarea_field( $p['notes'] ?? '' ),
			'created_by'         => get_current_user_id(),
			'updated_by'         => get_current_user_id(),
		);
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start employee transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( false === $wpdb->insert( Stockland_DB::table( 'employees' ), $row ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not create employee.' );
			}
			$id = (int) $wpdb->insert_id;
			if ( ! Stockland_Audit::log( get_current_user_id(), $branch, 'HR_EMPLOYEE_CREATE', null, null, null, $code, $name, 'EMP' . $id ) ) {
				throw new Exception( 'Audit event could not be recorded; employee was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Employee commit failed.' );
			}
			return rest_ensure_response( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'employees' ) . ' WHERE id=%d', $id ), ARRAY_A ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	public function employee_update( WP_REST_Request $r ) {
		if ( ! $this->owner() ) {
			return $this->deny();
		} global $wpdb;
		$id  = (int) $r['id'];
		$cur = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'employees' ) . ' WHERE id=%d', $id ), ARRAY_A );
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
			if ( $b && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE id=%d AND is_active=1', $b ) ) ) {
				return new WP_Error( 'location', 'Active location not found.', array( 'status' => 400 ) );
			}$data['branch_location_id'] = $b; }
		if ( array_key_exists( 'status', $p ) ) {
			$data['status'] = in_array( strtoupper( $p['status'] ), array( 'ACTIVE', 'INACTIVE', 'TERMINATED' ), true ) ? strtoupper( $p['status'] ) : $cur['status'];
		}
		if ( array_key_exists( 'hire_date', $p ) ) {
			$data['hire_date'] = $this->valid_date( $p['hire_date'], null );
		}if ( array_key_exists( 'termination_date', $p ) ) {
			$data['termination_date'] = $this->valid_date( $p['termination_date'], null );
		}
		if ( ! $data ) {
			return rest_ensure_response( $cur );
		}$data['updated_by'] = get_current_user_id();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start employee update transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( false === $wpdb->update( Stockland_DB::table( 'employees' ), $data, array( 'id' => $id ) ) ) {
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
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Employee update commit failed.' );
			}
			return rest_ensure_response( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'employees' ) . ' WHERE id=%d', $id ), ARRAY_A ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	public function compensation_create( WP_REST_Request $r ) {
		if ( ! $this->owner() ) {
			return $this->deny();
		}
		global $wpdb;
		$id        = (int) $r['id'];
		$employees = Stockland_DB::table( 'employees' );
		$employee  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$employees} WHERE id=%d", $id ), ARRAY_A );
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
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start compensation transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( false === $wpdb->insert( Stockland_DB::table( 'employee_compensation' ), $row ) ) {
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
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Compensation commit failed.' );
			}
			$row['id'] = (int) $wpdb->insert_id;
			return rest_ensure_response( $row );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $e->getMessage(), array( 'status' => 500 ) ); }
	}

	public function payroll_list( WP_REST_Request $r ) {
		global $wpdb;
		$rows = $this->payroll_rows();
		if ( ! $rows ) {
			return rest_ensure_response( array() );
		}
		$ids   = array_map(
			static function ( $x ) {
				return (int) $x['id'];
			},
			$rows
		);
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_lines' ) . " WHERE period_id IN({$ph}) ORDER BY period_id,employee_name", $ids ), ARRAY_A );
		$by    = array();
		foreach ( $lines as $line ) {
			$by[ (int) $line['period_id'] ][] = $line;}
		foreach ( $rows as &$row ) {
			$row['lines'] = $by[ (int) $row['id'] ] ?? array();
		}unset( $row );
		return rest_ensure_response( $rows );
	}

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
			return new WP_Error( 'historical_tax_rule_unavailable', 'This statutory rule set must not be applied to historical periods before ' . Stockland_Payroll_Statutory::EFFECTIVE_DATE . '. Preserve prior payroll and use the historically applicable certified rule.', array( 'status' => 400 ) );
		}if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE period_key=%s', $key ) ) ) {
			return new WP_Error( 'duplicate', 'Payroll period already exists.', array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll-period transaction.', array( 'status' => 500 ) );
		}
		try {
			$ok = $wpdb->insert(
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
			}if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll-period commit failed.' );
			}return rest_ensure_response( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE id=%d', $id ), ARRAY_A ) );
		} catch ( Throwable $x ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'save_failed', $x->getMessage(), array( 'status' => 500 ) );}
	}

	private function compensation_segments( $employee, $period ) {
		global $wpdb;
		$table = Stockland_DB::table( 'employee_compensation' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE employee_id=%d AND effective_from<=%s ORDER BY effective_from ASC,id ASC", (int) $employee['id'], $period['end_date'] ), ARRAY_A );
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
		$days_in_month       = (int) date( 't', strtotime( $period_start ) );
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
				$next_day   = date( 'Y-m-d', strtotime( $next_start . ' -1 day' ) );
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
				throw new RuntimeException( 'Compensation effective ' . $c['effective_from'] . ' for ' . $employee['employee_id'] . ' is not owner-classified. Open HR → Set Compensation and explicitly classify taxable/exempt allowance and pensionable salary.' );
			}
			$exempt           = Stockland_Money::decimal( Stockland_Money::minor( $c['exempt_allowance_total'] ) );
			$basis            = trim( (string) ( $c['statutory_classification_note'] ?? '' ) );
			$saved_components = json_decode( (string) ( $c['allowance_breakdown_json'] ?? '' ), true );
			if ( ! is_array( $saved_components ) ) {
				$saved_components = array();
			}
			if ( Stockland_Money::minor( $exempt ) > 0 ) {
				if ( strlen( $basis ) < 20 ) {
					throw new RuntimeException( 'Exempt allowance classification for ' . $employee['employee_id'] . ' requires a current 20+ character legal basis note.' );
				}
				$saved_exempt = '0.00';
				foreach ( $saved_components as $component ) {
					if ( strtoupper( (string) ( $component['tax_treatment'] ?? '' ) ) !== 'EXEMPT' ) {
						continue;
					}
					if ( empty( $component['owner_verified'] ) || strlen( trim( (string) ( $component['legal_basis'] ?? '' ) ) ) < 20 || strlen( trim( (string) ( $component['evidence_reference'] ?? '' ) ) ) < 4 ) {
						throw new RuntimeException( 'Exempt allowance for ' . $employee['employee_id'] . ' lacks verified legal-basis/evidence snapshot. Re-save compensation through the Owner allowance editor.' );
					}
					$saved_exempt = Stockland_Money::add( $saved_exempt, $component['amount'] ?? '0.00' );
				}
				if ( Stockland_Money::minor( $saved_exempt ) !== Stockland_Money::minor( $exempt ) ) {
					throw new RuntimeException( 'Exempt allowance snapshot does not match the amount for ' . $employee['employee_id'] . '. Re-save compensation through the Owner allowance editor.' );
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
				throw new RuntimeException( 'Allowance component breakdown does not match saved compensation for ' . $employee['employee_id'] . '. Re-save compensation from Owner HR before payroll generation.' );
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
						throw new RuntimeException( 'Allowance audit snapshot is missing the ' . $treatment . ' component needed to reconcile ' . $employee['employee_id'] . '. Re-save compensation through Owner HR.' );
					}
					if ( abs( $delta ) > max( 5, count( $keys ) * 5 ) ) {
						throw new RuntimeException( 'Allowance component rounding differs from the statutory aggregate by more than a safe rounding tolerance for ' . $employee['employee_id'] . '. Re-save compensation.' );
					}
					$adjusted = Stockland_Money::minor( Stockland_Money::add( $allowance_snapshots[ $largest_key ]['amount'], Stockland_Money::decimal( $delta ) ) );
					if ( $adjusted < 0 ) {
						throw new RuntimeException( 'Allowance evidence snapshot could not be reconciled without a negative component for ' . $employee['employee_id'] . '.' );
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

	private function payroll_totals( $period_id ) {
		global $wpdb;
		$lines  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_lines' ) . ' WHERE period_id=%d ORDER BY id', $period_id ), ARRAY_A );
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

	private function save_payroll_totals( $period_id ) {
		global $wpdb;
		$totals = $this->payroll_totals( $period_id );
		$ok     = $wpdb->update( Stockland_DB::table( 'payroll_periods' ), $totals, array( 'id' => (int) $period_id ) );
		if ( false === $ok ) {
			throw new RuntimeException( $wpdb->last_error ? $wpdb->last_error : 'Payroll period totals could not be saved.' );
		}
		return $totals;
	}

	public function payroll_generate( WP_REST_Request $r ) {
		if ( ! $this->payroll_manager() ) {
			return $this->deny( 'Only an active Owner or Management profile can prepare payroll.', 403 );
		}
		global $wpdb;
		$id = (int) $r['id'];
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start the payroll transaction.', array( 'status' => 500 ) );
		}
		try {
			$period = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
			if ( ! $period ) {
				throw new Exception( 'Payroll period not found.' );
			}
			if ( 'DRAFT' !== $period['status'] ) {
				throw new Exception( 'Only DRAFT payroll periods can be generated.' );
			}
			$month_start = substr( $period['start_date'], 0, 7 ) . '-01';
			if ( $period['start_date'] !== $month_start || date( 'Y-m-t', strtotime( $period['start_date'] ) ) !== $period['end_date'] || substr( $period['start_date'], 0, 7 ) !== $period['period_key'] ) {
				throw new Exception( 'Payroll period must be a full calendar month with key YYYY-MM.' );
			}
			if ( $period['start_date'] < Stockland_Payroll_Statutory::EFFECTIVE_DATE ) {
				throw new Exception( 'The configured tax schedule must not be applied to historical periods before ' . Stockland_Payroll_Statutory::EFFECTIVE_DATE . '.' );
			}
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Stockland_DB::table( 'payroll_lines' ) . ' WHERE period_id=%d FOR UPDATE', $id ) );
			if ( $existing > 0 ) {
				throw new Exception( 'This payroll period already has lines. Update each DRAFT line with an explicit statutory component breakdown or use a new draft period; no duplicate lines were created.' );
			}
			$employees                  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . Stockland_DB::table( 'employees' ) . " WHERE status='ACTIVE' AND UPPER(COALESCE(employment_type,''))<>'RETAINER_SERVICE' AND (hire_date IS NULL OR hire_date<=%s) AND (termination_date IS NULL OR termination_date>=%s) ORDER BY full_name",
					$period['end_date'],
					$period['start_date']
				),
				ARRAY_A
			);
			$excluded_service_contracts = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT employee_id,full_name FROM ' . Stockland_DB::table( 'employees' ) . " WHERE status='ACTIVE' AND UPPER(COALESCE(employment_type,''))='RETAINER_SERVICE' AND (hire_date IS NULL OR hire_date<=%s) AND (termination_date IS NULL OR termination_date>=%s) ORDER BY employee_id",
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
				if ( false === $wpdb->insert( Stockland_DB::table( 'payroll_lines' ), $line ) ) {
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
				if ( false === $wpdb->update( Stockland_DB::table( 'payroll_periods' ), array( 'notes' => $new_notes ), array( 'id' => $id ) ) ) {
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
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll generation commit failed.' );
			}
			$detail                               = $this->payroll_detail( $id );
			$detail['excluded_service_contracts'] = $excluded_service_codes;
			return rest_ensure_response( $detail );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'payroll_generate_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	private function compensation_notes( $employee_id, $as_of ) {
		global $wpdb;
		$c = Stockland_DB::table( 'employee_compensation' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT statutory_classification_note FROM {$c} WHERE employee_id=%d AND effective_from<=%s ORDER BY effective_from DESC,id DESC LIMIT 1", $employee_id, $as_of ) );
	}

	public function payroll_line_update( WP_REST_Request $r ) {
		if ( ! $this->payroll_manager() ) {
			return $this->deny( 'Only an active Owner or Management profile can edit draft payroll.', 403 );
		}
		global $wpdb;
		$pid    = (int) $r['id'];
		$lid    = (int) $r['line_id'];
		$p      = $r->get_json_params();
		$period = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE id=%d', $pid ), ARRAY_A );
		if ( ! $period ) {
			return new WP_Error( 'not_found', 'Payroll period not found.', array( 'status' => 404 ) );
		}
		if ( 'DRAFT' !== $period['status'] ) {
			return new WP_Error( 'locked', 'Approved or paid payroll cannot be edited.', array( 'status' => 409 ) );
		}
		$line = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_lines' ) . ' WHERE id=%d AND period_id=%d', $lid, $pid ), ARRAY_A );
		if ( ! $line ) {
			return new WP_Error( 'line_not_found', 'Payroll line not found.', array( 'status' => 404 ) );
		}
		$legacy = ( ( $line['statutory_status'] ?? 'LEGACY' ) !== 'CALCULATED' );
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
		if ( Stockland_Money::minor( $exempt ) > 0 ) {
			$old_exempt      = Stockland_Money::minor( $line['exempt_allowance_total'] ?? '0.00' );
			$same_exempt     = Stockland_Money::minor( $exempt ) === $old_exempt;
			$saved_breakdown = json_decode( (string) ( $line['allowance_breakdown_json'] ?? '' ), true );
			$saved_evidence  = false;
			foreach ( (array) $saved_breakdown as $saved ) {
				if ( strtoupper( (string) ( $saved['tax_treatment'] ?? '' ) ) === 'EXEMPT' && ! empty( $saved['owner_verified'] ) && strlen( (string) ( $saved['legal_basis'] ?? '' ) ) >= 20 && strlen( (string) ( $saved['evidence_reference'] ?? '' ) ) >= 4 ) {
					$saved_evidence = true;
					break;}
			}
			if ( ! $same_exempt || ! $saved_evidence ) {
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
		if ( Stockland_Money::minor( $snapshot_tax ) !== Stockland_Money::minor( $taxable ) || Stockland_Money::minor( $snapshot_exempt ) !== Stockland_Money::minor( $exempt ) ) {
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
			if ( Stockland_Money::minor( $exempt ) > 0 ) {
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
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll-line transaction.', array( 'status' => 500 ) );
		}
		try {
			$update = array_merge(
				$calc,
				array(
					'notes'                    => $note,
					'allowance_breakdown_json' => wp_json_encode( $line_snapshot ),
				)
			);
			if ( false === $wpdb->update(
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
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll-line commit failed.' );
			}
			return rest_ensure_response( $this->payroll_detail( $pid ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'payroll_line_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	private function payroll_detail( $id ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( ! $p ) {
			return null;
		}$p['lines'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_lines' ) . ' WHERE period_id=%d ORDER BY employee_name', $id ), ARRAY_A );
		return $p;}

	/** Management submits a completed draft; only the Owner may approve/pay it. */
	public function payroll_submit( WP_REST_Request $r ) {
		if ( ! $this->payroll_manager() ) {
			return $this->deny( 'Only active Stockland Management may prepare and submit payroll for Owner approval.', 403 );
		}
		global $wpdb;
		$id  = (int) $r['id'];
		$uid = get_current_user_id();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll submission transaction.', array( 'status' => 500 ) );
		}
		try {
			$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
			if ( ! $p ) {
				throw new Exception( 'Payroll period not found.' );
			}
			if ( 'DRAFT' !== $p['status'] ) {
				throw new Exception( 'Only DRAFT payroll may be submitted.' );
			}
			if ( Stockland_Authority::role( $uid ) !== 'management' || (int) $p['created_by'] !== $uid ) {
				throw new Exception( 'Only the Management creator may submit its own draft payroll period.' );
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Stockland_DB::table( 'payroll_lines' ) . ' WHERE period_id=%d', $id ) );
			if ( $count < 1 ) {
				throw new Exception( 'Generate payroll lines before submission.' );
			}
			$totals = $this->payroll_totals( $id );
			if ( Stockland_Money::minor( $totals['gross_total'] ) <= 0 ) {
				throw new Exception( 'Payroll gross must be positive before submission.' );
			}
			if ( false === $wpdb->update( Stockland_DB::table( 'payroll_periods' ), array( 'status' => 'SUBMITTED' ), array( 'id' => $id ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not submit payroll.' );
			}
			if ( ! Stockland_Audit::log( $uid, null, 'PAYROLL_SUBMIT', null, $totals['gross_total'], 'DRAFT', 'SUBMITTED', 'Payroll submitted for independent Owner approval under ' . Stockland_Payroll_Statutory::RULE_VERSION, 'PAY' . $id ) ) {
				throw new Exception( 'Audit failed; payroll submission was rolled back.' );
			}
			if ( ! Stockland_Notifications::push_role( 'owner', null, 'payroll_approval', 'Payroll awaiting Owner approval', 'Payroll period ' . $p['period_key'] . ' has been submitted. Review the calculated statutory totals before approval.', 'payroll', $id, 'action' ) ) {
				throw new Exception( 'Owner notification could not be queued; submission was rolled back.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll submit commit failed.' );
			}
			return rest_ensure_response( $this->payroll_detail( $id ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'payroll_submit_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	public function payroll_approve( WP_REST_Request $r ) {
		if ( ! $this->payroll_owner() ) {
			return $this->deny( 'Only the Owner can approve payroll.', 403 );
		}
		global $wpdb;
		$id = (int) $r['id'];
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll approval transaction.', array( 'status' => 500 ) );
		}
		try {
			$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
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
			$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_lines' ) . ' WHERE period_id=%d FOR UPDATE', $id ), ARRAY_A );
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
			$pok           = $wpdb->update( Stockland_DB::table( 'payroll_periods' ), $period_update, array( 'id' => $id ) );
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
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll approval commit failed.' );
			}
			return rest_ensure_response( $this->payroll_detail( $id ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'payroll_approve_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

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
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start payroll payment transaction.', array( 'status' => 500 ) );
		}try {
			$period = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'payroll_periods' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
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
			}$pok = $wpdb->update(
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
			}if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Payroll payment commit failed.' );
			}return rest_ensure_response( $this->payroll_detail( $id ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'payroll_pay_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}
}
