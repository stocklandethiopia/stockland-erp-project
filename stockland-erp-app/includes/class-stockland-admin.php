<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin integration.
 *
 * Stockland ERP is a normal WordPress plugin. It MUST NOT redirect or block
 * wp-admin, remove the Dashboard, or take ownership of WordPress administration.
 * Operational users may use the dedicated ERP frontend, while administrators
 * retain full WordPress backend control.
 */
class Stockland_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		// ERP adds only its own menu entries. It deliberately does not redirect, replace,
		// remove, hide or filter native wp-admin Dashboard/admin pages. Plugin/theme/user
		// administration therefore remains available through normal WordPress controls.
	}



	public function admin_menu() {
		add_menu_page(
			'Stockland ERP',
			'Stockland ERP',
			'read',
			'stockland-erp',
			array( $this, 'erp_page' ),
			'dashicons-store',
			25
		);
		add_submenu_page( 'stockland-erp', 'ERP Dashboard', 'ERP Dashboard', 'read', 'stockland-erp', array( $this, 'erp_page' ) );
		add_submenu_page( 'stockland-erp', 'System Control', 'System Control', 'manage_options', 'stockland-system-control', array( $this, 'system_control' ) );
		add_submenu_page( 'stockland-erp', 'WordPress Dashboard', 'WordPress Dashboard', 'manage_options', 'index.php' );
	}

	public function admin_bar( $bar ) {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'stockland-erp',
				'title' => 'Stockland ERP',
				'href'  => esc_url( self::erp_url() ),
			)
		);
	}

	private static function erp_url() {
		return Stockland_Frontend::page_url();
	}

	public function erp_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to access Stockland ERP.', 'stockland-inventory' ) );
		}
		// The WordPress menu is only a launcher. The operational ERP always opens
		// on its dedicated frontend so users never operate the ERP inside wp-admin.
		wp_safe_redirect( self::erp_url() );
		exit;
	}

	public function system_control() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access system control.', 'stockland-inventory' ) );
		}

		if ( isset( $_POST['stockland_force_accounting_install'] ) && check_admin_referer( 'stockland_force_accounting_install' ) && current_user_can( 'stockland_install_schema' ) ) {
			update_option( 'stockland_accounting_pending', 1, false );
			Stockland_Activator::maybe_install_accounting( true );
			echo '<div class="notice notice-info is-dismissible"><p>Accounting module install attempted. Status below is refreshed.</p></div>';
		}

		if ( isset( $_POST['stockland_repair_core_schema'] ) && check_admin_referer( 'stockland_repair_core_schema' ) && current_user_can( 'stockland_install_schema' ) ) {
			if ( get_transient( 'stockland_core_repair_backoff' ) ) {
				echo '<div class="notice notice-warning is-dismissible"><p>Core setup recently failed. Please retry in a few seconds.</p></div>';
			} else {
				try {
					$core_missing_now = Stockland_Activator::missing_core_tables();
					if ( empty( $core_missing_now ) ) {
						delete_option( 'stockland_setup_error' );
						echo '<div class="notice notice-success is-dismissible"><p><strong>Core schema is already physically complete (29/29).</strong> No Core repair request was executed.</p></div>';
						$progress = array(
							'complete'            => true,
							'installed_batch'     => 0,
							'missing_core_tables' => array(),
							'verified'            => true,
						);
					} else {
						$progress = Stockland_Activator::activate( 10 );
					}
					if ( ! empty( $progress['complete'] ) ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Core schema is complete.</strong> The ERP is ready to continue protected module setup.</p></div>';
					} else {
						echo '<div class="notice notice-info is-dismissible"><p><strong>Core setup continued in a protected batch.</strong> Remaining Core tables: ' . esc_html( implode( ', ', (array) ( $progress['missing_core_tables'] ?? array() ) ) ) . '</p></div>';
						if ( ! empty( $progress['errors'] ) ) {
							echo '<div class="notice notice-error is-dismissible"><p><strong>Database/upgrade detail:</strong> ' . esc_html( implode( ' | ', (array) $progress['errors'] ) ) . '</p></div>';
						}
					}
				} catch ( Throwable $e ) {
					set_transient( 'stockland_core_repair_backoff', 1, 30 );
					echo '<div class="notice notice-error is-dismissible"><p><strong>Core schema repair failed:</strong> ' . esc_html( $e->getMessage() ) . '</p></div>';
				}
			}
		}

		global $wpdb;
		$db  = get_option( 'stockland_db_version', 'Not initialized' );
		$app = Stockland_Frontend::page_url();

		$activation_error   = get_option( 'stockland_activation_error' );
		$boot_error         = get_option( 'stockland_boot_error' );
		$accounting_error   = get_option( 'stockland_accounting_error' );
		$accounting_pending = (bool) get_option( 'stockland_accounting_pending' );

		$expected_core       = Stockland_Activator::core_table_keys();
		$expected_accounting = array(
			'suppliers',
			'customers',
			'purchase_orders',
			'purchase_order_items',
			'purchase_receipts',
			'purchase_receipt_items',
			'supplier_invoices',
			'supplier_invoice_items',
			'customer_invoices',
			'invoice_items',
			'expenses',
			'payments',
			'payment_allocations',
			'gl_accounts',
			'gl_journals',
			'gl_lines',
		);

		$like        = $wpdb->esc_like( $wpdb->prefix . 'stock_' ) . '%';
		$found       = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$prefix_len  = strlen( $wpdb->prefix . 'stock_' );
		$found_short = array_map(
			function ( $t ) use ( $prefix_len ) {
				return substr( $t, $prefix_len );
			},
			(array) $found
		);

		$core_missing         = array_values( array_diff( $expected_core, $found_short ) );
		$accounting_missing   = array_values( array_diff( $expected_accounting, $found_short ) );
		$finance_upgrade_gaps = class_exists( 'Stockland_Accounting' ) ? Stockland_Accounting::upgrade_gaps() : array();
		if ( empty( $core_missing ) ) {
			delete_option( 'stockland_setup_error' );
			$activation_error = null; }
		if ( empty( $accounting_missing ) && empty( $finance_upgrade_gaps ) ) {
			delete_option( 'stockland_accounting_error' );
			delete_option( 'stockland_accounting_pending' );
			$accounting_error = null; }

		echo '<div class="wrap"><h1>Stockland System Control</h1>';
		echo '<div class="notice notice-success"><p><strong>WordPress backend is preserved.</strong> Stockland does not block, replace, or redirect the WordPress Dashboard.</p></div>';

		foreach ( array(
			'Activation error'        => $activation_error,
			'Startup error'           => $boot_error,
			'Accounting module error' => $accounting_error,
		) as $label => $err ) {
			if ( is_array( $err ) && ! empty( $err['message'] ) ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $err['message'] );
				if ( ! empty( $err['time'] ) ) {
					echo ' <em>(' . esc_html( $err['time'] ) . ')</em>';
				}
				echo '</p></div>';
			}
		}

		$full_expected = array_values( array_unique( array_merge( $expected_core, $expected_accounting, array( 'employees', 'employee_compensation', 'payroll_periods', 'payroll_lines' ) ) ) );
		$full_missing  = array_values( array_diff( $full_expected, $found_short ) );

		echo '<h2>Core system</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><td><strong>ERP Version</strong></td><td>' . esc_html( STOCKLAND_VERSION ) . '</td></tr>';
		echo '<tr><td><strong>Database Version</strong></td><td>' . esc_html( $db ) . '</td></tr>';
		echo '<tr><td><strong>Core inventory/POS/ledger/authorization tables</strong></td><td>';
		if ( empty( $core_missing ) ) {
			echo '<span style="color:#00a32a">&#10003; all ' . count( $expected_core ) . ' present</span>';
		} else {
			echo '<span style="color:#d63638">&#9888; ' . ( count( $expected_core ) - count( $core_missing ) ) . ' of ' . count( $expected_core ) . ' present — missing: ' . esc_html( implode( ', ', $core_missing ) ) . '</span>';
		}
		echo '</td></tr>';
		echo '<tr><td><strong>Full ERP schema</strong></td><td>' . ( empty( $full_missing ) ? '<span style="color:#00a32a">&#10003; all ' . count( $full_expected ) . ' application tables present</span>' : '<span style="color:#d63638">&#9888; ' . ( count( $full_expected ) - count( $full_missing ) ) . ' of ' . count( $full_expected ) . ' present — missing: ' . esc_html( implode( ', ', $full_missing ) ) . '</span>' ) . '</td></tr>';
		$expected_hr = array( 'employees', 'employee_compensation', 'payroll_periods', 'payroll_lines' );
		$hr_missing  = array_values( array_diff( $expected_hr, $found_short ) );
		echo '<tr><td><strong>HR / Payroll schema</strong></td><td>' . ( empty( $hr_missing ) ? '<span style="color:#00a32a">&#10003; all ' . count( $expected_hr ) . ' present</span>' : '<span style="color:#d63638">&#9888; ' . ( count( $expected_hr ) - count( $hr_missing ) ) . ' of ' . count( $expected_hr ) . ' present — missing: ' . esc_html( implode( ', ', $hr_missing ) ) . '</span>' ) . '</td></tr>';
		echo '<tr><td><strong>Core installation state</strong></td><td>' . ( empty( $core_missing ) ? '<span style="color:#00a32a">&#10003; Core setup complete</span>' : '<span style="color:#dba617">Resumable protected setup — no heavy schema work runs during plugin activation</span>' ) . '</td></tr>';
		if ( current_user_can( 'stockland_install_schema' ) ) {
			echo '<tr><td><strong>Core schema setup</strong></td><td>';
			if ( empty( $core_missing ) ) {
				echo '<span style="color:#00a32a;font-weight:600">Verified complete — no Core setup action is required.</span>';
			} else {
				echo '<form method="post" style="display:inline">';
				wp_nonce_field( 'stockland_repair_core_schema' );
				echo '<button type="submit" name="stockland_repair_core_schema" value="1" class="button button-primary">Continue Core setup (one verified table per request)</button></form>';
			}
			echo '</td></tr>';
		}
		echo '<tr><td><strong>ERP Frontend</strong></td><td><a href="' . esc_url( $app ) . '" target="_blank" rel="noopener">Open Stockland ERP</a></td></tr>';
		echo '<tr><td><strong>WordPress Admin</strong></td><td><a href="' . esc_url( admin_url() ) . '">Open Dashboard</a></td></tr>';
		echo '</tbody></table>';

		echo '<h2 style="margin-top:24px">Accounting / procurement module</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><td><strong>Status</strong></td><td>';
		if ( empty( $accounting_missing ) && empty( $accounting_upgrade_gaps ) && ! $accounting_pending ) {
			echo '<span style="color:#00a32a">&#10003; installed — all ' . count( $expected_accounting ) . ' tables present and structural upgrade checks passed</span>';
		} elseif ( empty( $accounting_missing ) && ! empty( $accounting_upgrade_gaps ) ) {
			echo '<span style="color:#dba617">&#9888; all ' . count( $expected_accounting ) . ' tables present, but compatibility repair is pending: ' . esc_html( implode( ', ', $accounting_upgrade_gaps ) ) . '</span>';
		} elseif ( $accounting_pending && empty( $core_missing ) ) {
			echo '<span style="color:#dba617">On-demand setup — this module is completed during the protected administrator setup flow. ' . ( count( $expected_accounting ) - count( $accounting_missing ) ) . ' of ' . count( $expected_accounting ) . ' tables present so far.</span>';
		} else {
			echo '<span style="color:#d63638">Not yet complete — ' . ( count( $expected_accounting ) - count( $accounting_missing ) ) . ' of ' . count( $expected_accounting ) . ' tables present. Missing: ' . esc_html( implode( ', ', $accounting_missing ) ) . '</span>';
		}
		echo '</td></tr>';
		echo '</tbody></table>';
		if ( empty( $accounting_missing ) && empty( $accounting_upgrade_gaps ) && ! $accounting_pending ) {
			echo '<p style="margin-top:10px;color:#00a32a;font-weight:600">Accounting / Procurement verified complete — no installation action is required.</p>';
		} else {
			echo '<form method="post" style="margin-top:10px">';
			wp_nonce_field( 'stockland_force_accounting_install' );
			echo '<button type="submit" name="stockland_force_accounting_install" value="1" class="button">Install / continue Accounting + Procurement (one verified table per request)</button>';
			echo '<p class="description">Protected administrator action. It advances only a small bounded schema batch and verifies the physical database after the change; it does not require Cron, a worker or a background service.</p>';
			echo '</form>';
		}

		echo '<h2 style="margin-top:24px">Server environment</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><td><strong>PHP version</strong></td><td>' . esc_html( phpversion() ) . ' (plugin requires 8.4+)</td></tr>';
		echo '<tr><td><strong>MySQL/MariaDB version</strong></td><td>' . esc_html( $wpdb->db_version() ) . '</td></tr>';
		echo '<tr><td><strong>memory_limit</strong></td><td>' . esc_html( ini_get( 'memory_limit' ) ) . '</td></tr>';
		echo '<tr><td><strong>max_execution_time</strong></td><td>' . esc_html( ini_get( 'max_execution_time' ) ) . ' seconds</td></tr>';
		echo '</tbody></table>';

		echo '<p style="margin-top:20px">Use the Stockland ERP menu for business operations. Use normal WordPress menus for plugin, user, theme, media and site administration.</p>';
		echo '</div>';
	}
}
