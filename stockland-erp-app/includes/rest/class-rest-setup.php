<?php
/**
 * REST controller source for Stockland ERP operational workflows.
 *
 * @package Stockland_ERP
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
/**
 * Protected, resumable Stockland schema setup and repair endpoints.
 *
 * @package Stockland_ERP
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protected browser-driven installation/repair controller.
 *
 * The browser advances one small schema batch at a time. The database itself is
 * the only source of truth for progress: every response is built from SHOW TABLES
 * and every newly-created table is physically verified before the request reports
 * progress. This path repairs an existing partial installation; it never drops,
 * truncates, or reseeds existing business data.
 */
class Stockland_REST_Setup {
	const NS                 = STOCKLAND_REST_NAMESPACE;
	const LOCK               = 'stockland_setup_install_lock';
	const CORE_BATCH_SIZE    = 1;
	const FINANCE_BATCH_SIZE = 1;

	/**
	 * Registers schema setup REST routes.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Checks whether the current user may advance a schema setup batch.
	 *
	 * @return bool Whether schema setup/repair is authorized.
	 */
	private function can_setup() {
		return is_user_logged_in() && current_user_can( 'stockland_install_schema' );
	}

	/**
	 * Checks whether the current session may view setup progress.
	 *
	 * @return bool Whether the current user is authenticated.
	 */
	private function can_view_status() {
		return is_user_logged_in();
	}

	/**
	 * Applies no-cache headers to live setup progress responses.
	 *
	 * @param mixed $response REST response to mark as non-cacheable.
	 * @return mixed The response with no-cache headers when supported.
	 */
	private function no_cache( $response ) {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
		}
		return $response;
	}

	/**
	 * Checks whether a technical administrator or authorized manager may administer users.
	 *
	 * @return bool Whether frontend user administration is allowed.
	 */
	private function frontend_user_gate() {
		return is_user_logged_in() && ( current_user_can( 'manage_options' ) || Stockland_Authority::can_manage_users() );
	}

	/**
	 * Creates the application controller for the pre-schema access bridge.
	 *
	 * @return Stockland_REST_App ERP application controller.
	 */
	private function access_controller() {
		// class-rest-app.php is always included by the plugin bootstrap. We instantiate
		// it only for these lightweight access operations while the application schema
		// is incomplete; operational ERP routes remain locked behind the 49/49 gate.
		return new Stockland_REST_App();
	}

	/**
	 * Determines whether the complete core, accounting and HR schema is ready.
	 *
	 * @return bool Whether operational ERP routes may be enabled.
	 */
	private function application_ready_for_app_routes() {
		try {
			return empty( Stockland_Activator::missing_core_tables() )
				&& empty( Stockland_Accounting::missing_tables() )
				&& empty( Stockland_Accounting::upgrade_gaps() )
				&& empty( Stockland_HR::missing_tables() )
				&& empty( Stockland_HR::upgrade_gaps() );
		} catch ( Throwable $e ) {
			// If any schema check is unknown, keep operational controllers closed but
			// leave the protected setup and diagnostic routes available.
			return false;
		}
	}

	/**
	 * Returns minimal access/onboarding state while schema setup is incomplete.
	 *
	 * @return mixed REST response describing authentication, profile and schema readiness.
	 */
	public function pre_schema_access_bootstrap() {
		$uid        = (int) get_current_user_id();
		$current    = wp_get_current_user();
		$core_ready = empty( Stockland_Activator::missing_core_tables() );
		$profile    = $core_ready ? Stockland_Roles::get_profile( $uid ) : null;
		$can_manage = (bool) ( current_user_can( 'manage_options' ) || Stockland_Authority::can_manage_users() );
		$result     = array(
			'authenticated'     => is_user_logged_in(),
			'wp_user_id'        => $uid,
			'display_name'      => (string) ( $current->display_name ?? '' ),
			'user_login'        => (string) ( $current->user_login ?? '' ),
			'profile_active'    => (bool) $profile,
			'role'              => $profile ? (string) $profile['role'] : '',
			'can_manage_users'  => $can_manage,
			'is_owner_eligible' => (bool) ( current_user_can( 'manage_options' ) || current_user_can( 'stockland_owner' ) ),
			'locations'         => array(),
			'accounts'          => array(),
			'schema_core_ready' => $core_ready,
		);
		if ( $core_ready && $can_manage ) {
			$app    = $this->access_controller();
			$access = $app->access_bootstrap();
			if ( $access instanceof WP_REST_Response ) {
				return $access;
			}
		}
		return $this->no_cache( rest_ensure_response( $result ) );
	}

	/**
	 * Registers setup status, batch advancement and pre-schema access routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/setup/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => function () {
					return $this->can_view_status(); },
			)
		);
		register_rest_route(
			self::NS,
			'/setup/advance',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'advance' ),
				'permission_callback' => function () {
					return $this->can_setup(); },
			)
		);

		// Pre-schema access bridge. This is deliberately available before 49/49 so a
		// native WordPress administrator/authorized manager can activate ERP profiles
		// from the ERP frontend without being forced into wp-admin user-role assignment.
		// Once the full schema is ready, Stockland_REST_App owns these routes and this
		// controller does not register duplicates.
		if ( ! $this->application_ready_for_app_routes() ) {
			register_rest_route(
				self::NS,
				'/app/access/bootstrap',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'pre_schema_access_bootstrap' ),
					'permission_callback' => function () {
						return is_user_logged_in(); },
				)
			);
			if ( empty( Stockland_Activator::missing_core_tables() ) ) {
				register_rest_route(
					self::NS,
					'/app/users',
					array(
						'methods'             => array( 'GET', 'POST' ),
						'callback'            => function ( $r ) {
							$app = $this->access_controller();
							return $r->get_method() === 'POST' ? $app->create_user( $r ) : $app->users( $r );
						},
						'permission_callback' => function () {
							return $this->frontend_user_gate(); },
					)
				);
				register_rest_route(
					self::NS,
					'/app/users/(?P<id>\d+)',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $r ) {
									return $this->access_controller()->update_user( $r ); },
						'permission_callback' => function () {
							return $this->frontend_user_gate(); },
					)
				);
				register_rest_route(
					self::NS,
					'/app/users/available',
					array(
						'methods'             => 'GET',
						'callback'            => function () {
									return $this->access_controller()->available_wp_users(); },
						'permission_callback' => function () {
							return $this->frontend_user_gate(); },
					)
				);
				register_rest_route(
					self::NS,
					'/app/users/activate-existing',
					array(
						'methods'             => 'POST',
						'callback'            => function ( $r ) {
									return $this->access_controller()->activate_existing_user( $r ); },
						'permission_callback' => function () {
							return $this->frontend_user_gate(); },
					)
				);
			}
			// The operational controller is intentionally absent until every schema
			// upgrade is verified. Keep its health URL available during repair.
			register_rest_route(
				self::NS,
				'/system/health',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'schema_gate_health' ),
					'permission_callback' => function () {
						return Stockland_Authority::can_view_system_diagnostics();
					},
				)
			);
		}
	}

	/**
	 * Read-only diagnostics for schema-gated startup. A setup response must never
	 * claim that a deep ledger/integrity audit ran or passed.
	 *
	 * @return mixed Safe schema/setup diagnostics for an authorized administrator.
	 */
	public function schema_gate_health() {
		$snapshot = $this->snapshot( true, true );
		$checks   = array(
			'db'                              => false,
			'core_schema'                     => empty( $snapshot['core']['missing'] ),
			'full_schema'                     => empty( $snapshot['total']['missing'] ),
			'schema_contract_exact_49'        => (int) ( $snapshot['total']['present'] ?? 0 ) === (int) ( $snapshot['total']['total'] ?? 49 ),
			'accounting_schema_upgrade_ready' => empty( $snapshot['finance']['upgrade_gaps'] ),
			'hr_schema_upgrade_ready'         => empty( $snapshot['hr']['upgrade_gaps'] ),
			'operational_routes_available'    => false,
		);
		try {
			global $wpdb;
			$wpdb->last_error = '';
			$checks['db']     = ( '1' === (string) Stockland_DB::read_var( 'SELECT 1' ) ) && empty( $wpdb->last_error );
		} catch ( Throwable $e ) {
			$checks['db'] = false;
		}
		$failed = array();
		foreach ( $checks as $key => $value ) {
			if ( ! $value ) {
				$failed[] = $key;
			}
		}
		$diagnostics          = array(
			'health_mode'           => 'setup',
			'setup_state'           => $snapshot['state'] ?? 'UNKNOWN',
			'core_schema_present'   => $snapshot['core']['present'] ?? 0,
			'core_schema_total'     => $snapshot['core']['total'] ?? 29,
			'full_schema_present'   => $snapshot['total']['present'] ?? 0,
			'full_schema_total'     => $snapshot['total']['total'] ?? 49,
			'schema_upgrade_gaps'   => array(
				'accounting' => $snapshot['finance']['upgrade_gaps'] ?? array(),
				'hr'         => $snapshot['hr']['upgrade_gaps'] ?? array(),
			),
			'deep_integrity_status' => 'DEFERRED_NOT_RUN',
			'deferred_checks'       => array(
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
			),
			'live_ready_rule'       => 'This setup response identifies schema gates preventing business endpoints from registering. Full integrity scans have not run in setup mode.',
		);
		$checks['live_ready'] = false;
		$checks['ok']         = false;
		return $this->no_cache(
			rest_ensure_response(
				array(
					'ok'                  => false,
					'checks'              => $checks,
					'diagnostics'         => $diagnostics,
					'php'                 => PHP_VERSION,
					'wordpress'           => get_bloginfo( 'version' ),
					'plugin'              => defined( 'STOCKLAND_VERSION' ) ? STOCKLAND_VERSION : 'unknown',
					'db_version'          => get_option( 'stockland_db_version', 'unknown' ),
					'audit'               => array(
						'ok'      => null,
						'checked' => 0,
						'reason'  => 'The full integrity audit has not run while schema repair is pending.',
					),
					'missing_tables'      => $snapshot['total']['missing'] ?? array(),
					'missing_core_tables' => $snapshot['core']['missing'] ?? array(),
					'failed_checks'       => $failed,
					'data_policy'         => 'Read-only schema-gate diagnostics; no business data is changed.',
				)
			)
		);
	}
	/**
	 * Builds a setup snapshot from physically verified schema state.
	 *
	 * @param bool $read_only Whether to avoid clearing stale setup error options.
	 * @return array Current setup state, missing tables and schema gaps.
	 */
	private function snapshot( $read_only = false, $include_schema_details = false ) {
		$core           = Stockland_Activator::missing_core_tables();
		$fin            = Stockland_Accounting::missing_tables();
		$hr             = Stockland_HR::missing_tables();
		$finance_gaps   = Stockland_Accounting::upgrade_gaps();
		$hr_gaps        = Stockland_HR::upgrade_gaps();
		$core_total     = count( Stockland_Activator::core_table_keys() );
		$fin_total      = count( Stockland_Accounting::table_keys() );
		$hr_total       = count( Stockland_HR::table_keys() );
		$total_expected = $core_total + $fin_total + $hr_total;
		$missing        = array_values( array_unique( array_merge( $core, $fin, $hr ) ) );
		$present        = $total_expected - count( $missing );
		$ready          = empty( $missing ) && empty( $finance_gaps ) && empty( $hr_gaps );
		$state          = 'LIVE';
		if ( $missing ) {
			if ( $core ) {
				$state = 'CORE';
			} elseif ( $fin || $finance_gaps ) {
				$state = 'FINANCE';
			} else {
				$state = 'HR';
			}
		} elseif ( $finance_gaps ) {
			$state = 'FINANCE';
		} elseif ( $hr_gaps ) {
			$state = 'HR';
		}
		$err = get_option( 'stockland_setup_error' );
		if ( ! $read_only ) {
			if ( empty( $core ) && is_array( $err ) && ( ( $err['stage'] ?? '' ) === 'CORE' ) ) {
				delete_option( 'stockland_setup_error' );
				$err = null;
			}
			if ( empty( $fin ) && empty( $finance_gaps ) ) {
				$accounting_error = get_option( 'stockland_accounting_error' );
				if ( is_array( $accounting_error ) ) {
					delete_option( 'stockland_accounting_error' );
				}
				delete_option( 'stockland_accounting_pending' );
			}
		}
		$details = array(
			'ready'              => $ready,
			'state'              => $state,
			'core'               => array(
				'present' => $core_total - count( $core ),
				'total'   => $core_total,
				'missing' => $core,
			),
			'finance'            => array(
				'present'      => $fin_total - count( $fin ),
				'total'        => $fin_total,
				'missing'      => $fin,
				'upgrade_gaps' => $finance_gaps,
				'ready'        => empty( $fin ) && empty( $finance_gaps ),
			),
			'hr'                 => array(
				'present'      => $hr_total - count( $hr ),
				'total'        => $hr_total,
				'missing'      => $hr,
				'upgrade_gaps' => $hr_gaps,
				'ready'        => empty( $hr ) && empty( $hr_gaps ),
			),
			'total'              => array(
				'present' => $present,
				'total'   => $total_expected,
				'missing' => $missing,
			),
			'error'              => $this->can_setup() && is_array( $err ) ? $err : null,
			'can_advance'        => $this->can_setup() && ( ! $ready ),
			'execution_model'    => 'SERVER_VERIFIED_BROWSER_BATCHES',
			'server_observed_at' => current_time( 'mysql', true ),
			'pwa'                => array(
				'url'       => Stockland_Frontend::page_url(),
				'available' => true,
			),
		);
		if ( ! $this->can_setup() && ! $include_schema_details ) {
			foreach ( array( 'core', 'finance', 'hr' ) as $m ) {
				$details[ $m ]['missing'] = array();}
			$details['total']['missing']        = array();
			$details['finance']['upgrade_gaps'] = array();
			$details['hr']['upgrade_gaps']      = array();
		}
		return $details;
	}

	/**
	 * Returns the current resumable schema setup status.
	 *
	 * @return mixed REST response containing verified setup progress.
	 */
	public function status() {
		return $this->no_cache( rest_ensure_response( $this->snapshot() ) );
	}

	/**
	 * Advances one protected core, accounting or HR schema batch.
	 *
	 * @param WP_REST_Request $request REST request containing batch parameters.
	 * @return mixed REST response containing physically verified batch progress.
	 * @throws RuntimeException When a required schema invariant cannot be satisfied.
	 */
	public function advance( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress REST callback signature retains WP_REST_Request for consistent route dispatch.
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'setup_busy', 'Another Stockland setup request is currently running. Please retry in a few seconds.', array( 'status' => 409 ) );
		}
		set_transient( self::LOCK, 1, 60 );
		try {
			$before = $this->snapshot();
			if ( $before['ready'] ) {
				return $this->no_cache( rest_ensure_response( $before ) );
			}
			delete_option( 'stockland_setup_error' );

			if ( $before['core']['missing'] ) {
				$progress = Stockland_Activator::activate( self::CORE_BATCH_SIZE );
			} elseif ( $before['finance']['missing'] || ! empty( $before['finance']['upgrade_gaps'] ) ) {
				$progress = Stockland_Accounting::install( self::FINANCE_BATCH_SIZE );
				if ( null === $progress ) {
					$progress = array( 'complete' => empty( Stockland_Accounting::missing_tables() ) && empty( Stockland_Accounting::upgrade_gaps() ) );
				}
			} else {
				$progress = Stockland_HR::install( 1 );
				if ( ! is_array( $progress ) ) {
					$progress = array(); }
				$progress['complete']        = empty( Stockland_HR::missing_tables() ) && empty( Stockland_HR::upgrade_gaps() );
				$progress['installed_batch'] = 1;
				$progress['upgrade_gaps']    = Stockland_HR::upgrade_gaps();
			}

			// NEVER trust a client-side progress counter or a declarative dbDelta
			// result. Re-read the physical schema after the mutation request.
			$after               = $this->snapshot();
			$before_present      = (int) $before['total']['present'];
			$after_present       = (int) $after['total']['present'];
			$verified_gain       = max( 0, $after_present - $before_present );
			$before_finance_gaps = count( (array) ( $before['finance']['upgrade_gaps'] ?? array() ) );
			$after_finance_gaps  = count( (array) ( $after['finance']['upgrade_gaps'] ?? array() ) );
			$upgrade_gain        = max( 0, $before_finance_gaps - $after_finance_gaps );
			$before_hr_gaps      = count( (array) ( $before['hr']['upgrade_gaps'] ?? array() ) );
			$after_hr_gaps       = count( (array) ( $after['hr']['upgrade_gaps'] ?? array() ) );
			$hr_upgrade_gain     = max( 0, $before_hr_gaps - $after_hr_gaps );
			$upgrade_gain       += $hr_upgrade_gain;
			if ( ! $after['ready'] && 0 === $verified_gain && 0 === $upgrade_gain && ( 'CORE' === $after['state'] || 'FINANCE' === $after['state'] || 'HR' === $after['state'] ) ) {
				$stage  = $after['state'];
				$detail = 'No verified database progress was made in ' . $stage . ' setup. Remaining tables: ' . implode( ', ', (array) $after['total']['missing'] );
				if ( is_array( $progress ) && ! empty( $progress['errors'] ) ) {
					$detail .= ' Database errors: ' . implode( ' | ', array_map( 'strval', (array) $progress['errors'] ) );
				}
				throw new RuntimeException( $detail );
			}

			if ( $after['ready'] ) {
				update_option( 'stockland_setup_state', 'INVARIANTS_PENDING', false );
				update_option( 'stockland_application_schema_ready', 1, false );
				update_option( 'stockland_db_version', STOCKLAND_DB_VERSION, false );
				Stockland_Roles::register_roles();
				Stockland_Roles::sync_roles();
				update_option( 'stockland_setup_state', 'LIVE_SCHEMA_READY', false );
				delete_option( 'stockland_setup_error' );
			}

			$payload = array_merge(
				$after,
				array(
					'progress'          => is_array( $progress ) ? $progress : array( 'complete' => false ),
					'advanced_from'     => $before['state'],
					'verified_progress' => array(
						'before_present'           => $before_present,
						'after_present'            => $after_present,
						'gained'                   => $verified_gain,
						'finance_gaps_before'      => $before_finance_gaps,
						'finance_gaps_after'       => $after_finance_gaps,
						'hr_gaps_before'           => $before_hr_gaps,
						'hr_gaps_after'            => $after_hr_gaps,
						'hr_upgrade_gaps_resolved' => $hr_upgrade_gain,
						'upgrade_gaps_resolved'    => $upgrade_gain,
					),
				)
			);
			return $this->no_cache( rest_ensure_response( $payload ) );
		} catch ( Throwable $e ) {
			$fresh = $this->snapshot();
			$err   = array(
				'message'   => $e->getMessage(),
				'time'      => current_time( 'mysql' ),
				'stage'     => $fresh['state'],
				'remaining' => $fresh['total']['missing'],
			);
			update_option( 'stockland_setup_error', $err, false );
			Stockland_Runtime_Diagnostics::log_error( 'Stockland ERP setup error: ' . $e->getMessage() );
			return new WP_Error(
				'setup_failed',
				$e->getMessage(),
				array(
					'status' => 500,
					'setup'  => $fresh,
				)
			);
		} finally {
			delete_transient( self::LOCK ); }
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
