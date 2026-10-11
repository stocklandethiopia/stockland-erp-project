<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stockland HR + Payroll data layer.
 *
 * HR is owner-controlled. Payroll is append-only after approval/payment: a
 * posted period cannot be edited in place. Statutory tax formulas are not
 * invented here; allowances and deductions are explicit configured amounts.
 */
class Stockland_HR {
	const DB_VERSION = STOCKLAND_HR_SCHEMA_VERSION;

	public static function table_keys() {
		return array( 'employees', 'employee_compensation', 'payroll_periods', 'payroll_lines' );
	}

	/** Add-only HR/payroll columns required by the component statutory model. */
	public static function required_columns() {
		return array(
			'employee_compensation' => array(
				'taxable_allowance_total'         => 'DECIMAL(14,2) NULL DEFAULT NULL AFTER allowance_total',
				'exempt_allowance_total'          => 'DECIMAL(14,2) NULL DEFAULT NULL AFTER taxable_allowance_total',
				'pensionable_salary'              => 'DECIMAL(14,2) NULL DEFAULT NULL AFTER exempt_allowance_total',
				'statutory_classification_status' => "VARCHAR(20) NOT NULL DEFAULT 'REVIEW_REQUIRED' AFTER pensionable_salary",
				'statutory_classification_note'   => 'VARCHAR(255) NULL AFTER statutory_classification_status',
				'allowance_breakdown_json'        => 'LONGTEXT NULL AFTER statutory_classification_note',
			),
			'payroll_periods'       => array(
				'income_tax_total'       => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER deduction_total',
				'employee_pension_total' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER income_tax_total',
				'employer_pension_total' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employee_pension_total',
				'other_deduction_total'  => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employer_pension_total',
			),
			'payroll_lines'         => array(
				'taxable_allowance_total'  => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER allowance_total',
				'exempt_allowance_total'   => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER taxable_allowance_total',
				'other_deduction_total'    => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER deduction_total',
				'taxable_income'           => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER gross_pay',
				'income_tax'               => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER taxable_income',
				'income_tax_rate'          => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER income_tax',
				'pensionable_salary'       => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER income_tax_rate',
				'employee_pension_rate'    => 'DECIMAL(6,4) NOT NULL DEFAULT 0.0700 AFTER pensionable_salary',
				'employer_pension_rate'    => 'DECIMAL(6,4) NOT NULL DEFAULT 0.1100 AFTER employee_pension_rate',
				'employee_pension'         => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employer_pension_rate',
				'employer_pension'         => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employee_pension',
				'tax_rule_version'         => 'VARCHAR(40) NULL AFTER employer_pension',
				'statutory_status'         => "VARCHAR(20) NOT NULL DEFAULT 'LEGACY' AFTER tax_rule_version",
				'employer_cost_total'      => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER statutory_status',
				'allowance_breakdown_json' => 'LONGTEXT NULL AFTER net_pay',
			),
		);
	}

	/** Read-only inventory of missing HR columns; no table or business data is changed. */
	public static function upgrade_gaps() {
		global $wpdb;
		$gaps   = array();
		$prefix = $wpdb->prefix . 'stock_';
		foreach ( self::required_columns() as $key => $columns ) {
			$table            = $prefix . $key;
			$wpdb->last_error = '';
			$exists           = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
					DB_NAME,
					$table
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				$gaps[] = $key . '.__table_metadata_query_failed__';
				continue; }
			if ( ! $exists ) {
				continue; }
			$wpdb->last_error = '';
			$known            = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', 0 );
			if ( ! empty( $wpdb->last_error ) || ! is_array( $known ) ) {
				$gaps[] = $key . '.__columns_metadata_query_failed__';
				continue; }
			foreach ( $columns as $name => $definition ) {
				if ( ! in_array( $name, $known, true ) ) {
					$gaps[] = $key . '.' . $name; }
			}
		}
		return $gaps;
	}

	/** Apply missing columns to one HR table per protected setup batch (or all tables for manual repair). */
	private static function upgrade_columns( $limit_tables = 0 ) {
		global $wpdb;
		$prefix    = $wpdb->prefix . 'stock_';
		$processed = 0;
		$errors    = array();
		foreach ( self::required_columns() as $key => $columns ) {
			$table            = $prefix . $key;
			$wpdb->last_error = '';
			$exists           = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
					DB_NAME,
					$table
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				throw new RuntimeException( 'Could not inspect HR table metadata for ' . $key . ': ' . $wpdb->last_error ); }
			if ( ! $exists ) {
				continue; }
			$wpdb->last_error = '';
			$known            = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', 0 );
			if ( ! empty( $wpdb->last_error ) || ! is_array( $known ) ) {
				throw new RuntimeException( 'Could not inspect HR columns for ' . $key . ': ' . ( $wpdb->last_error ?: 'metadata query failed' ) ); }
			$missing = array_diff_key( $columns, array_flip( $known ) );
			if ( ! $missing ) {
				continue; }
			// One ALTER TABLE per HR table minimizes shared-host request time and
			// avoids repeatedly rebuilding the same table for each new component.
			// All column names/definitions originate from this class's fixed allowlist.
			$alter_parts = array();
			foreach ( $missing as $name => $definition ) {
				$alter_parts[] = 'ADD COLUMN `' . esc_sql( $name ) . '` ' . $definition;
			}
			$wpdb->last_error = '';
			$result           = $wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ' . implode( ', ', $alter_parts ) );
			if ( false === $result || ! empty( $wpdb->last_error ) ) {
				$message  = $wpdb->last_error ?: 'ALTER TABLE failed';
				$errors[] = $key . ': ' . $message;
				throw new RuntimeException( 'HR schema migration failed for ' . $key . ': ' . $message );
			}
			++$processed;
			if ( $limit_tables > 0 && $processed >= $limit_tables ) {
				break; }
		}
		return array(
			'processed_tables' => $processed,
			'errors'           => $errors,
			'upgrade_gaps'     => self::upgrade_gaps(),
		);
	}

	public static function missing_tables() {
		global $wpdb;
		$prefix     = $wpdb->prefix . 'stock_';
		$found      = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$found_keys = array();
		foreach ( (array) $found as $table ) {
			if ( strpos( $table, $prefix ) === 0 ) {
				$found_keys[] = substr( $table, strlen( $prefix ) );
			}
		}
		return array_values( array_diff( self::table_keys(), $found_keys ) );
	}

	public static function maybe_install() {
		// Schema installation is never a public/front-end operation. It is an
		// Owner/Administrator setup/repair action, matching the shared-hosting model.
		if ( ! is_user_logged_in() || ! is_admin() || wp_doing_ajax() || ! current_user_can( 'stockland_install_schema' ) ) {
			return;
		}
		// Short error-only backoff; normal successful setup/progress is never throttled.
		if ( get_transient( 'stockland_hr_repair_backoff' ) ) {
			return;
		}
		$missing = self::missing_tables();
		if ( get_option( 'stockland_hr_schema_version' ) === self::DB_VERSION && empty( $missing ) && empty( self::upgrade_gaps() ) ) {
			return;
		}
		$lock_key = 'stockland_hr_install_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 60 );
		try {
			self::install();
			delete_option( 'stockland_hr_install_error' );
		} catch ( Throwable $e ) {
			update_option(
				'stockland_hr_install_error',
				array(
					'message' => $e->getMessage(),
					'time'    => current_time( 'mysql' ),
				),
				false
			);
			set_transient( 'stockland_hr_repair_backoff', 1, 30 );
			error_log( 'Stockland HR install error: ' . $e->getMessage() );
		}
		delete_transient( $lock_key );
	}

	public static function install( $batch_size = 0 ) {
		$batch_size = max( 0, (int) $batch_size );
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p      = $wpdb->prefix;
		$cc     = $wpdb->get_charset_collate();
		$tables = array();

		$tables[] = "CREATE TABLE {$p}stock_employees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id VARCHAR(40) NOT NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            full_name VARCHAR(180) NOT NULL,
            branch_location_id BIGINT UNSIGNED NULL,
            position_title VARCHAR(120) NULL,
            employment_type VARCHAR(40) NOT NULL DEFAULT 'FULL_TIME',
            hire_date DATE NULL,
            termination_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            phone VARCHAR(60) NULL,
            email VARCHAR(190) NULL,
            notes VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            updated_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY employee_id (employee_id), KEY wp_user_id (wp_user_id), KEY branch_status (branch_location_id,status), KEY status (status)
          ) ENGINE=InnoDB {$cc};";

		$tables[] = "CREATE TABLE {$p}stock_employee_compensation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            base_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
            allowance_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            standard_deduction_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
            notes VARCHAR(500) NULL,
            taxable_allowance_total DECIMAL(14,2) NULL DEFAULT NULL,
            exempt_allowance_total DECIMAL(14,2) NULL DEFAULT NULL,
            pensionable_salary DECIMAL(14,2) NULL DEFAULT NULL,
            statutory_classification_status VARCHAR(20) NOT NULL DEFAULT 'REVIEW_REQUIRED',
            statutory_classification_note VARCHAR(255) NULL,
            allowance_breakdown_json LONGTEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), KEY employee_effective (employee_id,effective_from), KEY effective_from (effective_from), KEY statutory_status (statutory_classification_status)
          ) ENGINE=InnoDB {$cc};";

		$tables[] = "CREATE TABLE {$p}stock_payroll_periods (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_key VARCHAR(40) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
            employee_count INT UNSIGNED NOT NULL DEFAULT 0,
            gross_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            deduction_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            income_tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            employee_pension_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            employer_pension_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            other_deduction_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            net_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            journal_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            paid_by BIGINT UNSIGNED NULL,
            paid_at DATETIME NULL,
            payment_method VARCHAR(20) NULL,
            notes VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY period_key (period_key), KEY status (status), KEY end_date (end_date)
          ) ENGINE=InnoDB {$cc};";

		$tables[] = "CREATE TABLE {$p}stock_payroll_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            employee_code VARCHAR(40) NOT NULL,
            employee_name VARCHAR(180) NOT NULL,
            branch_location_id BIGINT UNSIGNED NULL,
            base_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
            allowance_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            deduction_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            taxable_allowance_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            exempt_allowance_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            other_deduction_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            gross_pay DECIMAL(14,2) NOT NULL DEFAULT 0,
            taxable_income DECIMAL(14,2) NOT NULL DEFAULT 0,
            income_tax DECIMAL(14,2) NOT NULL DEFAULT 0,
            income_tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            pensionable_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
            employee_pension_rate DECIMAL(6,4) NOT NULL DEFAULT 0.0700,
            employer_pension_rate DECIMAL(6,4) NOT NULL DEFAULT 0.1100,
            employee_pension DECIMAL(14,2) NOT NULL DEFAULT 0,
            employer_pension DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_rule_version VARCHAR(40) NULL,
            statutory_status VARCHAR(20) NOT NULL DEFAULT 'LEGACY',
            employer_cost_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            net_pay DECIMAL(14,2) NOT NULL DEFAULT 0,
            allowance_breakdown_json LONGTEXT NULL,
            notes VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY period_employee (period_id,employee_id), KEY employee_id (employee_id), KEY branch_location_id (branch_location_id), KEY status (status)
          ) ENGINE=InnoDB {$cc};";

		if ( $batch_size > 0 ) {
			$missing_now = self::missing_tables();
			$wanted      = array_slice( $missing_now, 0, $batch_size );
			$tables      = array_values(
				array_filter(
					$tables,
					static function ( $sql ) use ( $p, $wanted ) {
						if ( ! preg_match( '/^\s*CREATE TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
							return false;
						}
						$full = trim( $m[1], '`' );
						$key  = preg_replace( '/^' . preg_quote( $p . 'stock_', '/' ) . '/', '', $full );
						return in_array( $key, $wanted, true );
					}
				)
			);
		}
		foreach ( $tables as $sql ) {
			$table_key = '';
			if ( preg_match( '/^\s*CREATE TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
				$full      = trim( $m[1], '`' );
				$table_key = preg_replace( '/^' . preg_quote( $p . 'stock_', '/' ) . '/', '', $full );}
			$wpdb->last_error = '';
			$direct_sql       = preg_replace( '/^\s*CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
			$result           = $wpdb->query( $direct_sql );
			if ( false === $result ) {
				$msg = 'HR table ' . ( $table_key ?: 'unknown' ) . ' could not be created';
				if ( ! empty( $wpdb->last_error ) ) {
					$msg .= ': ' . $wpdb->last_error;
				}throw new RuntimeException( $msg );}
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $p . 'stock_' . $table_key ) );
			if ( ! $table_key || ! $exists ) {
				throw new RuntimeException( 'HR table ' . ( $table_key ?: 'unknown' ) . ' was not physically verified after CREATE TABLE.' );
			}
		}
		$required   = self::table_keys();
		$prefix     = $p . 'stock_';
		$found      = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$found_keys = array();
		foreach ( (array) $found as $table ) {
			if ( strpos( $table, $prefix ) === 0 ) {
				$found_keys[] = substr( $table, strlen( $prefix ) );
			}
		}
		$missing = array_values( array_diff( $required, $found_keys ) );
		if ( $missing ) {
			foreach ( $tables as $sql ) {
				if ( ! preg_match( '/^\s*CREATE TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
					continue;
				}$full = trim( $m[1], '`' );
				$key   = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/', '', $full );
				if ( ! in_array( $key, $missing, true ) ) {
					continue;
				}$fallback        = preg_replace( '/^\s*CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
				$wpdb->last_error = '';
				$wpdb->query( $fallback );
				if ( ! empty( $wpdb->last_error ) ) {
					throw new RuntimeException( 'HR table ' . $key . ' could not be created: ' . $wpdb->last_error );
				}
			}
			$found      = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			$found_keys = array();
			foreach ( (array) $found as $table ) {
				if ( strpos( $table, $prefix ) === 0 ) {
					$found_keys[] = substr( $table, strlen( $prefix ) );
				}
			}$missing = array_values( array_diff( $required, $found_keys ) );
		}
		if ( $missing ) {
			if ( $batch_size > 0 ) {
				return array(
					'complete'        => false,
					'installed_batch' => 1,
					'missing_tables'  => $missing,
					'upgrade_gaps'    => self::upgrade_gaps(),
				);
			}
			throw new RuntimeException( 'HR schema is incomplete: ' . implode( ', ', $missing ) );
		}
		$migration = self::upgrade_columns( $batch_size > 0 ? 1 : 0 );
		$gaps      = self::upgrade_gaps();
		if ( $gaps ) {
			if ( $batch_size > 0 ) {
				return array(
					'complete'        => false,
					'installed_batch' => 1,
					'missing_tables'  => array(),
					'upgrade_gaps'    => $gaps,
					'migration'       => $migration,
				);
			}
			throw new RuntimeException( 'HR schema columns are incomplete: ' . implode( ', ', $gaps ) );
		}
		update_option( 'stockland_hr_schema_version', self::DB_VERSION, false );
		update_option( 'stockland_hr_db_version', self::DB_VERSION, false );
		return array(
			'complete'        => true,
			'installed_batch' => 0,
			'missing_tables'  => array(),
			'upgrade_gaps'    => array(),
			'migration'       => $migration,
		);
	}
}
