<?php
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

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	private function can_setup() {
		return is_user_logged_in() && current_user_can( 'stockland_install_schema' );
	}

	private function can_view_status() {
		return is_user_logged_in();
	}

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

	private function frontend_user_gate() {
		return is_user_logged_in() && ( current_user_can( 'manage_options' ) || Stockland_Authority::can_manage_users() );
	}

	private function access_controller() {
		// class-rest-app.php is always included by the plugin bootstrap. We instantiate
		// it only for these lightweight access operations while the application schema
		// is incomplete; operational ERP routes remain locked behind the 49/49 gate.
		return new Stockland_REST_App();
	}

	private function application_ready_for_app_routes() {
		return empty( Stockland_Activator::missing_core_tables() )
			&& empty( Stockland_Accounting::missing_tables() )
			&& empty( Stockland_Accounting::upgrade_gaps() )
			&& empty( Stockland_HR::missing_tables() );
	}

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
		}
	}

	private function snapshot() {
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
		if ( empty( $core ) && is_array( $err ) && ( ( $err['stage'] ?? '' ) === 'CORE' ) ) {
			delete_option( 'stockland_setup_error' );
			$err = null; }
		if ( empty( $fin ) && empty( $finance_gaps ) ) {
			$accounting_error = get_option( 'stockland_accounting_error' );
			if ( is_array( $accounting_error ) ) {
				delete_option( 'stockland_accounting_error' ); }
			delete_option( 'stockland_accounting_pending' );
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
		if ( ! $this->can_setup() ) {
			foreach ( array( 'core', 'finance', 'hr' ) as $m ) {
				$details[ $m ]['missing'] = array();}
			$details['total']['missing']        = array();
			$details['finance']['upgrade_gaps'] = array();
			$details['hr']['upgrade_gaps']      = array();
		}
		return $details;
	}

	public function status() {
		return $this->no_cache( rest_ensure_response( $this->snapshot() ) );
	}

	public function advance( WP_REST_Request $request ) {
		if ( 'POST' !== $request->get_method() ) {
			return new WP_Error( 'method_not_allowed', 'This endpoint only supports POST.', array( 'status' => 405 ) );
		}
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
			error_log( 'Stockland ERP setup error: ' . $e->getMessage() );
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
