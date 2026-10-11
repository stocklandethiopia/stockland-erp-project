<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stockland Accounting & Procurement engine.
 *
 * Additive 10.5.1 layer. Existing inventory/POS tables are never dropped,
 * rewritten, or reseeded. All financial journals are append-only after posting.
 */
class Stockland_Accounting {
	const DB_VERSION = STOCKLAND_ACCOUNTING_SCHEMA_VERSION;

	public static function table_keys() {
		return array( 'suppliers', 'customers', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'supplier_invoices', 'supplier_invoice_items', 'customer_invoices', 'invoice_items', 'expenses', 'payments', 'payment_allocations', 'gl_accounts', 'gl_journals', 'gl_lines' );
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

	public static function install( $batch_size = 0 ) {
		$batch_size = max( 0, (int) $batch_size );
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p  = $wpdb->prefix;
		$cc = $wpdb->get_charset_collate();

		$tables = array();

		$tables[] = "CREATE TABLE {$p}stock_suppliers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_code VARCHAR(40) NOT NULL,
            supplier_name VARCHAR(180) NOT NULL,
            phone VARCHAR(60) NULL,
            email VARCHAR(190) NULL,
            address VARCHAR(255) NULL,
            tax_id VARCHAR(80) NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
            credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            notes VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY supplier_code (supplier_code), KEY supplier_name (supplier_name), KEY active (is_active)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_customers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_code VARCHAR(40) NOT NULL,
            customer_name VARCHAR(180) NOT NULL,
            customer_type VARCHAR(30) NOT NULL DEFAULT 'RETAIL',
            phone VARCHAR(60) NULL,
            email VARCHAR(190) NULL,
            address VARCHAR(255) NULL,
            tax_id VARCHAR(80) NULL,
            credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            notes VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY customer_code (customer_code), KEY customer_name (customer_name), KEY active (is_active)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_purchase_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_number VARCHAR(40) NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            order_date DATE NOT NULL,
            expected_date DATE NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            notes VARCHAR(500) NULL,
            status_note VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY po_number (po_number), KEY supplier_id (supplier_id), KEY status (status), KEY order_date (order_date)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_purchase_order_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            description VARCHAR(180) NULL,
            quantity_ordered DECIMAL(12,2) NOT NULL,
            quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
            line_subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_tax DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id), KEY po_id (po_id), KEY sku (sku), KEY variant_id (variant_id), UNIQUE KEY po_sku (po_id,sku)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_purchase_receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_number VARCHAR(40) NOT NULL,
            po_id BIGINT UNSIGNED NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            receipt_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
            notes VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY receipt_number (receipt_number), KEY po_id (po_id), KEY supplier_id (supplier_id), KEY receipt_date (receipt_date)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_purchase_receipt_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_id BIGINT UNSIGNED NOT NULL,
            po_item_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            quantity_received DECIMAL(12,2) NOT NULL,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id), KEY receipt_id (receipt_id), KEY po_item_id (po_item_id), KEY sku (sku), KEY variant_id (variant_id),
            UNIQUE KEY receipt_item (receipt_id,po_item_id)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_supplier_invoices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number VARCHAR(60) NOT NULL,
            supplier_id BIGINT UNSIGNED NOT NULL,
            po_id BIGINT UNSIGNED NULL,
            invoice_date DATE NOT NULL,
            due_date DATE NULL,
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
            notes VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY supplier_invoice (invoice_number,supplier_id), KEY supplier_id (supplier_id), KEY po_id (po_id), KEY due_date (due_date)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_supplier_invoice_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            po_item_id BIGINT UNSIGNED NULL,
            sku VARCHAR(64) NULL,
            description VARCHAR(180) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
            line_subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_tax DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id), KEY invoice_id (invoice_id), KEY po_item_id (po_item_id), KEY sku (sku)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_customer_invoices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number VARCHAR(60) NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            invoice_date DATE NOT NULL,
            due_date DATE NULL,
            location_id BIGINT UNSIGNED NULL,
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
            notes VARCHAR(500) NULL,
            source_type VARCHAR(30) NULL,
            source_id VARCHAR(40) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY invoice_number (invoice_number), KEY customer_id (customer_id), KEY due_date (due_date), KEY source (source_type,source_id)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_invoice_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NULL,
            description VARCHAR(180) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
            unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
            line_subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_tax DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id), KEY invoice_id (invoice_id), KEY sku (sku)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_expenses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            expense_number VARCHAR(40) NOT NULL,
            expense_date DATE NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            category VARCHAR(100) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'CASH',
            reference VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY expense_number (expense_number), KEY expense_date (expense_date), KEY category (category)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_number VARCHAR(40) NOT NULL,
            payment_date DATE NOT NULL,
            payment_type VARCHAR(20) NOT NULL,
            supplier_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            amount DECIMAL(14,2) NOT NULL,
            method VARCHAR(30) NOT NULL DEFAULT 'CASH',
            reference VARCHAR(100) NULL,
            notes VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY payment_number (payment_number), KEY payment_date (payment_date), KEY supplier_id (supplier_id), KEY customer_id (customer_id)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_payment_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            invoice_type VARCHAR(10) NOT NULL,
            invoice_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            PRIMARY KEY  (id), UNIQUE KEY allocation (payment_id,invoice_type,invoice_id), KEY invoice (invoice_type,invoice_id)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_gl_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_code VARCHAR(20) NOT NULL,
            account_name VARCHAR(120) NOT NULL,
            account_type VARCHAR(30) NOT NULL,
            parent_code VARCHAR(20) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id), UNIQUE KEY account_code (account_code), KEY account_type (account_type)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_gl_journals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            journal_number VARCHAR(40) NOT NULL,
            journal_date DATE NOT NULL,
            source_type VARCHAR(30) NOT NULL,
            source_id VARCHAR(60) NOT NULL,
            memo VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            posted_at DATETIME NULL,
            PRIMARY KEY  (id), UNIQUE KEY journal_number (journal_number), UNIQUE KEY source (source_type,source_id), KEY journal_date (journal_date), KEY source_type (source_type)
        ) ENGINE=InnoDB $cc;";

		$tables[] = "CREATE TABLE {$p}stock_gl_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            journal_id BIGINT UNSIGNED NOT NULL,
            account_code VARCHAR(20) NOT NULL,
            debit DECIMAL(14,2) NOT NULL DEFAULT 0,
            credit DECIMAL(14,2) NOT NULL DEFAULT 0,
            supplier_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NULL,
            memo VARCHAR(255) NULL,
            PRIMARY KEY  (id), KEY journal_id (journal_id), KEY account_code (account_code), KEY supplier_id (supplier_id), KEY customer_id (customer_id), KEY location_id (location_id)
        ) ENGINE=InnoDB $cc;";

		$required   = self::table_keys();
		$batch_mode = $batch_size > 0;
		if ( $batch_mode ) {
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
		$batch_results = array();
		foreach ( $tables as $sql ) {
			$table_key = '';
			if ( preg_match( '/^\s*CREATE TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
				$full      = trim( $m[1], '`' );
				$table_key = preg_replace( '/^' . preg_quote( $p . 'stock_', '/' ) . '/', '', $full );}
			$wpdb->last_error = '';
			if ( $batch_mode ) {
				$direct_sql = preg_replace( '/^\s*CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
				$result     = // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CREATE TABLE DDL is generated only from this class's plugin-owned schema array; table keys are checked against the internal required-table allowlist above and no request values enter the statement.
				$result     = $wpdb->query( $direct_sql );
				if ( false === $result ) {
					$msg = 'Accounting/procurement table ' . ( $table_key ? $table_key : 'unknown' ) . ' could not be created';
					if ( ! empty( $wpdb->last_error ) ) {
						$msg .= ': ' . $wpdb->last_error;
					}throw new RuntimeException( $msg );}
				$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $p . 'stock_' . $table_key ) );
				if ( ! $table_key || ! $exists ) {
					throw new RuntimeException( 'Accounting/procurement table ' . ( $table_key ? $table_key : 'unknown' ) . ' was not physically verified after CREATE TABLE.' );
				}
				$batch_results[] = array(
					'table'    => $table_key,
					'verified' => true,
				);
			} else {
				dbDelta( $sql );
				if ( ! empty( $wpdb->last_error ) ) {
					throw new RuntimeException( 'Accounting/procurement schema statement failed: ' . $wpdb->last_error );
				}
			}
		}
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
				}
				$full = trim( $m[1], '`' );
				$key  = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/', '', $full );
				if ( ! in_array( $key, $missing, true ) ) {
					continue;
				}
				$fallback         = preg_replace( '/^\s*CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CREATE TABLE DDL is generated only from this class's plugin-owned schema array; the table key was matched against the internal required-table allowlist above.
				$wpdb->query( $fallback );
				if ( ! empty( $wpdb->last_error ) ) {
					throw new RuntimeException( 'Accounting/procurement table ' . $key . ' could not be created: ' . $wpdb->last_error );
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
			if ( $batch_mode ) {
				return array(
					'complete'        => false,
					'installed_batch' => count( $batch_results ),
					'verified_tables' => $batch_results,
					'missing_tables'  => $missing,
				);
			}
			throw new RuntimeException( 'Accounting/procurement schema is incomplete: ' . implode( ', ', $missing ) );
		}
		self::upgrade_variant_identity_columns( $p, $cc );
		self::seed_accounts( $p );
		self::ensure_opening_balance_journals();
		self::seed_walkin_customer( $p );
		self::grant_capabilities();
		update_option( 'stockland_accounting_schema_version', self::DB_VERSION, false );
		update_option( 'stockland_accounting_db_version', self::DB_VERSION, false );
		delete_option( 'stockland_accounting_error' );
		delete_option( 'stockland_accounting_pending' );
	}

	/** Keep supplier/customer opening balances represented in their GL control accounts. */
	public static function sync_opening_balance_journal( $party_type, $party_id, $old_balance, $new_balance, $user_id, $journal_date = null ) {
		$party_type = strtoupper( (string) $party_type );
		$party_id   = (int) $party_id;
		if ( ! in_array( $party_type, array( 'SUPPLIER', 'CUSTOMER' ), true ) || $party_id <= 0 ) {
			return false;
		}
		$old_balance = Stockland_Money::decimal( Stockland_Money::minor( $old_balance ) );
		$new_balance = Stockland_Money::decimal( Stockland_Money::minor( $new_balance ) );
		$delta       = Stockland_Money::subtract( $new_balance, $old_balance );
		if ( Stockland_Money::minor( $delta ) === 0 ) {
			return 0;
		}
		$journal_date = $journal_date ? $journal_date : current_time( 'Y-m-d' );
		$token        = substr( hash( 'sha256', uniqid( 'stockland_ob_', true ) ), 0, 10 );
		$source_type  = 'SUPPLIER' === $party_type ? 'SUPPLIER_OPENING_BALANCE' : 'CUSTOMER_OPENING_BALANCE';
		$source_id    = ( 'SUPPLIER' === $party_type ? 'SUP-OB-' : 'CUS-OB-' ) . $party_id . '-' . $token;
		$amount       = abs( $delta );
		$lines        = array();
		if ( 'SUPPLIER' === $party_type ) {
			if ( $delta > 0 ) {
				$lines = array(
					array(
						'account_code' => '3000',
						'debit'        => $amount,
						'credit'       => 0,
					),
					array(
						'account_code' => '2000',
						'debit'        => 0,
						'credit'       => $amount,
						'supplier_id'  => $party_id,
					),
				);} else {
				$lines = array(
					array(
						'account_code' => '2000',
						'debit'        => $amount,
						'credit'       => 0,
						'supplier_id'  => $party_id,
					),
					array(
						'account_code' => '3000',
						'debit'        => 0,
						'credit'       => $amount,
					),
				);}
				$memo = 'Supplier opening balance adjustment ' . $party_id . ' (' . $old_balance . ' -> ' . $new_balance . ')';
		} else {
			if ( $delta > 0 ) {
				$lines = array(
					array(
						'account_code' => '1100',
						'debit'        => $amount,
						'credit'       => 0,
						'customer_id'  => $party_id,
					),
					array(
						'account_code' => '3000',
						'debit'        => 0,
						'credit'       => $amount,
					),
				);} else {
				$lines = array(
					array(
						'account_code' => '3000',
						'debit'        => $amount,
						'credit'       => 0,
					),
					array(
						'account_code' => '1100',
						'debit'        => 0,
						'credit'       => $amount,
						'customer_id'  => $party_id,
					),
				);}
				$memo = 'Customer opening balance adjustment ' . $party_id . ' (' . $old_balance . ' -> ' . $new_balance . ')';
		}
		return self::post_journal( $journal_date, $source_type, $source_id, $memo, $lines, (int) $user_id );
	}

	/** Synchronize legacy/non-journaled opening balances without altering posted business transactions. */
	public static function ensure_opening_balance_journals( $limit = 2000 ) {
		global $wpdb;
		$limit = max( 1, (int) $limit );
		if ( ! self::account_exists( '3000' ) || ! self::account_exists( '2000' ) || ! self::account_exists( '1100' ) ) {
			return false;
		}
		$sup       = Stockland_DB::table( 'suppliers' );
		$cus       = Stockland_DB::table( 'customers' );
		$gll       = Stockland_DB::table( 'gl_lines' );
		$glj       = Stockland_DB::table( 'gl_journals' );
		$processed = 0;
		$suppliers = $wpdb->get_results( $wpdb->prepare( 'SELECT id,opening_balance FROM %i WHERE ABS(COALESCE(opening_balance,0))>0.004 ORDER BY id LIMIT %d', $sup, (int) $limit ), ARRAY_A );
		foreach ( $suppliers as $row ) {
			$id      = (int) $row['id'];
			$desired = Stockland_Money::decimal( Stockland_Money::minor( $row['opening_balance'] ) );
			$posted  = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(l.credit-l.debit),0) FROM %i l JOIN %i j ON j.id=l.journal_id WHERE j.status='POSTED' AND l.account_code='2000' AND l.supplier_id=%d AND j.source_type='SUPPLIER_OPENING_BALANCE'", $gll, $glj, $id ) ) ) );
			if ( Stockland_Money::minor( Stockland_Money::subtract( $desired, $posted ) ) !== 0 ) {
				$jid = self::sync_opening_balance_journal( 'SUPPLIER', $id, $posted, $desired, get_current_user_id() );
				if ( false === $jid ) {
					throw new RuntimeException( 'Could not synchronize supplier opening balance ' . $id . ' to Accounts Payable.' );
				}
			}
			++$processed;
			if ( $processed >= $limit ) {
				return true;
			}
		}
		$remaining = max( 0, $limit - $processed );
		if ( 0 === $remaining ) {
			return true;
		}
		$customers = $wpdb->get_results( $wpdb->prepare( 'SELECT id,opening_balance FROM %i WHERE ABS(COALESCE(opening_balance,0))>0.004 ORDER BY id LIMIT %d', $cus, $remaining ), ARRAY_A );
		foreach ( $customers as $row ) {
			$id      = (int) $row['id'];
			$desired = Stockland_Money::decimal( Stockland_Money::minor( $row['opening_balance'] ) );
			$posted  = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(l.debit-l.credit),0) FROM %i l JOIN %i j ON j.id=l.journal_id WHERE j.status='POSTED' AND l.account_code='1100' AND l.customer_id=%d AND j.source_type='CUSTOMER_OPENING_BALANCE'", $gll, $glj, $id ) ) ) );
			if ( Stockland_Money::minor( Stockland_Money::subtract( $desired, $posted ) ) !== 0 ) {
				$jid = self::sync_opening_balance_journal( 'CUSTOMER', $id, $posted, $desired, get_current_user_id() );
				if ( false === $jid ) {
					throw new RuntimeException( 'Could not synchronize customer opening balance ' . $id . ' to Accounts Receivable.' );
				}
			}
		}
		return true;
	}

	/**
	 * Upgrade the two purchasing line tables without dbDelta.
	 *
	 * The previous implementation fed a compact CREATE TABLE statement with
	 * multiple KEY definitions on one line into dbDelta(). WordPress' dbDelta
	 * parser is line-oriented for field/index definitions, and on the target
	 * host this produced the live error: "Key column 'KEY' doesn't exist in
	 * table". Existing data is preserved here: only a missing nullable
	 * variant_id column and its supporting index are added, then existing rows
	 * are backfilled only where an exact active Product Master SKU match exists.
	 */
	private static function upgrade_variant_identity_columns( $p, $cc ) {
		global $wpdb;

		$targets = array(
			array(
				'table' => $p . 'stock_purchase_order_items',
				'after' => 'sku',
				'index' => 'variant_id',
			),
			array(
				'table' => $p . 'stock_purchase_receipt_items',
				'after' => 'sku',
				'index' => 'variant_id',
			),
		);

		foreach ( $targets as $target ) {
			$table = $target['table'];

			$table_exists = (string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
					DB_NAME,
					$table
				)
			);
			if ( ! $table_exists ) {
				throw new RuntimeException( 'Accounting schema upgrade expected table is missing: ' . $table );
			}

			$has_sku = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='sku'",
					DB_NAME,
					$table
				)
			);
			if ( ! $has_sku ) {
				throw new RuntimeException( 'Accounting schema upgrade cannot add variant_id because ' . $table . '.sku is missing.' );
			}

			$has_variant = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='variant_id'",
					DB_NAME,
					$table
				)
			);
			if ( ! $has_variant ) {
				$wpdb->last_error = '';
				if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i BIGINT UNSIGNED NULL AFTER %i', $table, 'variant_id', $target['after'] ) ) ) {
					throw new RuntimeException( 'Accounting schema upgrade could not add ' . $table . '.variant_id' . ( $wpdb->last_error ? ': ' . $wpdb->last_error : '.' ) );
				}
			}

			$has_variant = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='variant_id'",
					DB_NAME,
					$table
				)
			);
			if ( ! $has_variant ) {
				throw new RuntimeException( 'Accounting schema upgrade could not physically verify ' . $table . '.variant_id.' );
			}

			$has_variant_index = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='variant_id'",
					DB_NAME,
					$table
				)
			);
			if ( ! $has_variant_index ) {
				$wpdb->last_error = '';
				if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i)', $table, 'variant_id', 'variant_id' ) ) ) {
					throw new RuntimeException( 'Accounting schema upgrade could not add the variant_id index to ' . $table . ( $wpdb->last_error ? ': ' . $wpdb->last_error : '.' ) );
				}
			}

			$has_variant_index = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='variant_id'",
					DB_NAME,
					$table
				)
			);
			if ( ! $has_variant_index ) {
				throw new RuntimeException( 'Accounting schema upgrade could not physically verify the variant_id index on ' . $table . '.' );
			}
		}

		// Backfill only where a stable Product Master match exists. Never change
		// SKU, quantity, cost or receipt values in this compatibility migration.
		$prod        = $p . 'stock_products';
		$prod_exists = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
				DB_NAME,
				$prod
			)
		);
		if ( $prod_exists ) {
			foreach ( $targets as $target ) {
				$wpdb->last_error = '';
				$wpdb->query( $wpdb->prepare( 'UPDATE %i i INNER JOIN %i p ON p.sku=i.sku SET i.variant_id=p.variant_id WHERE i.variant_id IS NULL AND p.is_active=1', $target['table'], $prod ) );
				if ( ! empty( $wpdb->last_error ) ) {
					throw new RuntimeException( 'Accounting variant identity backfill failed for ' . $target['table'] . ': ' . $wpdb->last_error );
				}
			}
		}
	}

	/**
	 * Report remaining physical accounting upgrade gaps. This is intentionally
	 * separate from table existence so a 16/16 table count cannot mask an
	 * incomplete compatibility migration.
	 */
	public static function upgrade_gaps() {
		global $wpdb;
		$p       = $wpdb->prefix;
		$gaps    = array();
		$targets = array( $p . 'stock_purchase_order_items', $p . 'stock_purchase_receipt_items' );
		foreach ( $targets as $table ) {
			$exists = (string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
					DB_NAME,
					$table
				)
			);
			if ( ! $exists ) {
				continue; }
			$column = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='variant_id'",
					DB_NAME,
					$table
				)
			);
			if ( ! $column ) {
				$gaps[] = $table . '.variant_id';
				continue; }
			$index = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='variant_id'",
					DB_NAME,
					$table
				)
			);
			if ( ! $index ) {
				$gaps[] = $table . '.KEY(variant_id)'; }
		}
		return $gaps;
	}

	private static function seed_accounts( $p ) {
		global $wpdb;
		$accounts = array(
			array( '1000', 'Cash', 'ASSET' ),
			array( '1010', 'Bank', 'ASSET' ),
			array( '1020', 'POS Clearing', 'ASSET' ),
			array( '1100', 'Accounts Receivable', 'ASSET' ),
			array( '1200', 'Inventory', 'ASSET' ),
			array( '1300', 'Input VAT', 'ASSET' ),
			array( '2000', 'Accounts Payable', 'LIABILITY' ),
			array( '2010', 'Goods Received Not Invoiced', 'LIABILITY' ),
			array( '2100', 'VAT Payable', 'LIABILITY' ),
			array( '3000', 'Owner Equity', 'EQUITY' ),
			array( '4000', 'Retail Sales Revenue', 'REVENUE' ),
			array( '4100', 'Wholesale Sales Revenue', 'REVENUE' ),
			array( '5000', 'Cost of Goods Sold', 'EXPENSE' ),
			array( '6000', 'Operating Expenses', 'EXPENSE' ),
			array( '6100', 'Other Expenses', 'EXPENSE' ),
			array( '6200', 'Payroll Expense', 'EXPENSE' ),
			array( '6210', 'Employer Pension Expense', 'EXPENSE' ),
			array( '2020', 'Payroll Payable', 'LIABILITY' ),
			array( '2030', 'Employee Pension Payable', 'LIABILITY' ),
			array( '2031', 'Income Tax Withheld Payable', 'LIABILITY' ),
			array( '2032', 'Employer Pension Payable', 'LIABILITY' ),
			array( '2033', 'Other Payroll Deductions Payable', 'LIABILITY' ),
		);
		foreach ( $accounts as $a ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (account_code,account_name,account_type,is_system) VALUES (%s,%s,%s,1)", $p . 'stock_gl_accounts', $a[0], $a[1], $a[2] ) );
		}
	}

	private static function grant_capabilities() {
		// Finance and procurement authority is company-level only. Warehouse users
		// may receive/inspect purchasing documents through their operational caps,
		// but they must not inherit GL/AP/expense/payment powers. Branch users must
		// never receive financial write capabilities from the accounting installer.
		$management = array( 'stockland_view_financials', 'stockland_manage_suppliers', 'stockland_manage_customers', 'stockland_manage_purchase_orders', 'stockland_approve_purchase_orders', 'stockland_manage_payables', 'stockland_manage_receivables', 'stockland_manage_expenses', 'stockland_manage_payments', 'stockland_manage_gl' );
		foreach ( array(
			Stockland_Roles::ROLE_OWNER      => $management,
			Stockland_Roles::ROLE_MANAGEMENT => $management,
		) as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
		// Explicitly strip any historical accounting capabilities from operational roles.
		$operational = array( Stockland_Roles::ROLE_BRANCH_MANAGER, Stockland_Roles::ROLE_SALES_EMPLOYEE, Stockland_Roles::ROLE_WAREHOUSE_EMPLOYEE, Stockland_Roles::ROLE_WAREHOUSE_MANAGER, Stockland_Roles::ROLE_AUDITOR );
		foreach ( $operational as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $management as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
		$auditor = get_role( Stockland_Roles::ROLE_AUDITOR );
		if ( $auditor ) {
			$auditor->add_cap( 'stockland_view_financials' );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $management as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	private static function seed_walkin_customer( $p ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (customer_code,customer_name,customer_type,is_active,created_by) VALUES (%s,%s,%s,1,%d)", $p . 'stock_customers', 'WALK-IN', 'Walk-in Customer', 'RETAIL', get_current_user_id() ) );
	}

	/** Idempotently ensure statutory payroll accounts exist during an Owner-approved payroll posting. */
	public static function ensure_payroll_statutory_accounts() {
		global $wpdb;
		$table    = Stockland_DB::table( 'gl_accounts' );
		$accounts = array(
			array( '6200', 'Payroll Expense', 'EXPENSE' ),
			array( '6210', 'Employer Pension Expense', 'EXPENSE' ),
			array( '2020', 'Payroll Payable', 'LIABILITY' ),
			array( '2030', 'Employee Pension Payable', 'LIABILITY' ),
			array( '2031', 'Income Tax Withheld Payable', 'LIABILITY' ),
			array( '2032', 'Employer Pension Payable', 'LIABILITY' ),
			array( '2033', 'Other Payroll Deductions Payable', 'LIABILITY' ),
		);
		foreach ( $accounts as $a ) {
			$wpdb->last_error = '';
			$ok               = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (account_code,account_name,account_type,is_system) VALUES (%s,%s,%s,1)', $table, $a[0], $a[1], $a[2] ) );
			if ( false === $ok || ! empty( $wpdb->last_error ) ) {
				return false;
			}
		}
		// INSERT IGNORE never repairs a conflicting account already in the ledger.
		// Verify each code's active state and account class before permitting statutory posting.
		foreach ( $accounts as $a ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT account_type,is_active FROM %i WHERE account_code=%s LIMIT 1', $table, $a[0] ), ARRAY_A );
			if ( ! $row || strtoupper( (string) $row['account_type'] ) !== $a[2] || 1 !== (int) $row['is_active'] ) {
				return false;
			}
		}
		return true;
	}

	public static function account_exists( $code ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE account_code=%s AND is_active=1', Stockland_DB::table( 'gl_accounts' ), $code ) );
	}

	/**
	 * Canonical debit/credit composition independent of input line order or memo text.
	 * Integer minor-unit arithmetic prevents decimal comparison drift.
	 */
	private static function canonical_journal_lines( $lines ) {
		$map = array();
		foreach ( (array) $lines as $line ) {
			$code = sanitize_text_field( (string) ( $line['account_code'] ?? '' ) );
			if ( '' === $code ) {
				return false;
			}
			$location = array_key_exists( 'location_id', $line ) && null !== $line['location_id'] ? (int) $line['location_id'] : null;
			$supplier = array_key_exists( 'supplier_id', $line ) && null !== $line['supplier_id'] ? (int) $line['supplier_id'] : null;
			$customer = array_key_exists( 'customer_id', $line ) && null !== $line['customer_id'] ? (int) $line['customer_id'] : null;
			$key      = wp_json_encode( array( $code, $location, $supplier, $customer ) );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array(
					'account_code' => $code,
					'location_id'  => $location,
					'supplier_id'  => $supplier,
					'customer_id'  => $customer,
					'debit_minor'  => 0,
					'credit_minor' => 0,
				);
			}
			$map[ $key ]['debit_minor']  += Stockland_Money::minor( $line['debit'] ?? 0 );
			$map[ $key ]['credit_minor'] += Stockland_Money::minor( $line['credit'] ?? 0 );
		}
		ksort( $map, SORT_STRING );
		return array_values( $map );
	}

	/** Existing source keys are idempotent only for identical posted journal content. */
	private static function existing_journal_matches( $header, $date, $lines ) {
		global $wpdb;
		if ( ! $header || strtoupper( (string) ( $header['status'] ?? '' ) ) !== 'POSTED' || (string) ( $header['journal_date'] ?? '' ) !== (string) $date ) {
			return false;
		}
		$existing = $wpdb->get_results( $wpdb->prepare( 'SELECT account_code,debit,credit,location_id,supplier_id,customer_id FROM %i WHERE journal_id=%d ORDER BY id', Stockland_DB::table( 'gl_lines' ), (int) $header['id'] ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $existing ) || ! $existing ) {
			return false;
		}
		$want = self::canonical_journal_lines( $lines );
		$have = self::canonical_journal_lines( $existing );
		if ( false === $want || false === $have || count( $want ) !== count( $have ) ) {
			return false;
		}
		foreach ( $want as $i => $row ) {
			$got = $have[ $i ] ?? null;
			if ( ! $got ) {
				return false;
			}
			foreach ( array( 'account_code', 'location_id', 'supplier_id', 'customer_id', 'debit_minor', 'credit_minor' ) as $field ) {
				if ( ( $row[ $field ] ?? null ) !== ( $got[ $field ] ?? null ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function post_journal( $date, $source_type, $source_id, $memo, $lines, $user_id ) {
		global $wpdb;
		$sum_d = '0.00';
		$sum_c = '0.00';
		foreach ( (array) $lines as $line ) {
			$d = Stockland_Money::decimal( Stockland_Money::minor( $line['debit'] ?? 0 ) );
			$c = Stockland_Money::decimal( Stockland_Money::minor( $line['credit'] ?? 0 ) );
			if ( $d < 0 || $c < 0 || ( $d > 0 && $c > 0 ) || ! self::account_exists( $line['account_code'] ) ) {
				return false;
			}
			$sum_d = Stockland_Money::add( $sum_d, $d );
			$sum_c = Stockland_Money::add( $sum_c, $c );
		}
		if ( Stockland_Money::minor( Stockland_Money::subtract( $sum_d, $sum_c ) ) !== 0 || Stockland_Money::minor( $sum_d ) <= 0 ) {
			return false;
		}

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,journal_date,status FROM %i WHERE source_type=%s AND source_id=%s LIMIT 1', Stockland_DB::table( 'gl_journals' ), $source_type, $source_id ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return false;
		}
		if ( $existing ) {
			return self::existing_journal_matches( $existing, $date, $lines ) ? (int) $existing['id'] : false;
		}

		// post_journal can be called from an already-open inventory transaction.
		// MySQL START TRANSACTION would implicitly commit that outer transaction,
		// breaking all-or-nothing behavior. Detect the transaction state and use a
		// savepoint when an outer transaction is already active.
		$in_transaction = (int) $wpdb->get_var( 'SELECT @@session.in_transaction' ) === 1;
		$savepoint      = 'stockland_gl_' . wp_rand( 100000, 999999 );
		if ( $in_transaction ) {
			if ( false === $wpdb->query( "SAVEPOINT {$savepoint}" ) ) {
				return false;
			}
		} elseif ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		try {
			$num = Stockland_Sequence::next( 'JRN-', $date );
			$ok  = $wpdb->insert(
				Stockland_DB::table( 'gl_journals' ),
				array(
					'journal_number' => $num,
					'journal_date'   => $date,
					'source_type'    => $source_type,
					'source_id'      => $source_id,
					'memo'           => sanitize_text_field( $memo ),
					'status'         => 'POSTED',
					'created_by'     => (int) $user_id,
					'posted_at'      => current_time( 'mysql' ),
				)
			);
			if ( ! $ok ) {
				throw new Exception( 'Journal header failed' );
			}
			$jid = (int) $wpdb->insert_id;
			foreach ( $lines as $line ) {
				$ok = $wpdb->insert(
					Stockland_DB::table( 'gl_lines' ),
					array(
						'journal_id'   => $jid,
						'account_code' => sanitize_text_field( $line['account_code'] ),
						'debit'        => Stockland_Money::decimal( Stockland_Money::minor( $line['debit'] ?? 0 ) ),
						'credit'       => Stockland_Money::decimal( Stockland_Money::minor( $line['credit'] ?? 0 ) ),
						'supplier_id'  => isset( $line['supplier_id'] ) ? (int) $line['supplier_id'] : null,
						'customer_id'  => isset( $line['customer_id'] ) ? (int) $line['customer_id'] : null,
						'location_id'  => isset( $line['location_id'] ) ? (int) $line['location_id'] : null,

						'memo'         => isset( $line['memo'] ) ? sanitize_text_field( $line['memo'] ) : null,
					)
				);
				if ( ! $ok ) {
					throw new Exception( 'Journal line failed' );
				}
			}
			if ( ! Stockland_Audit::log( $user_id, null, 'GL_POST', null, $sum_d, null, null, $memo, $num ) ) {
				throw new Exception( 'Audit failed' );}
			if ( $in_transaction ) {
				if ( false === $wpdb->query( "RELEASE SAVEPOINT {$savepoint}" ) ) {
					throw new Exception( 'Could not release accounting savepoint.' );
				}
			} elseif ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Journal commit failed' );
			}
			return $jid;
		} catch ( Throwable $e ) {
			if ( $in_transaction ) {
				$wpdb->query( "ROLLBACK TO SAVEPOINT {$savepoint}" );
				$wpdb->query( "RELEASE SAVEPOINT {$savepoint}" );
			} else {
				$wpdb->query( 'ROLLBACK' );
			}
			return false;
		}
	}

	public static function supplier_balance( $id ) {
		global $wpdb;
		$id      = (int) $id;
		$opening = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( 'SELECT opening_balance FROM %i WHERE id=%d', Stockland_DB::table( 'suppliers' ), $id ) ) ) );
		$inv     = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM %i WHERE supplier_id=%d AND status='POSTED'", Stockland_DB::table( 'supplier_invoices' ), $id ) ) ) );
		$pay     = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE supplier_id=%d AND payment_type='SUPPLIER' AND status='POSTED'", Stockland_DB::table( 'payments' ), $id ) ) ) );
		return Stockland_Money::subtract( Stockland_Money::add( $opening, $inv ), $pay );
	}
	public static function customer_balance( $id ) {
		global $wpdb;
		$id      = (int) $id;
		$opening = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( 'SELECT opening_balance FROM %i WHERE id=%d', Stockland_DB::table( 'customers' ), $id ) ) ) );
		$inv     = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM %i WHERE customer_id=%d AND status='POSTED'", Stockland_DB::table( 'customer_invoices' ), $id ) ) ) );
		$pay     = Stockland_Money::decimal( Stockland_Money::minor( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE customer_id=%d AND payment_type='CUSTOMER' AND status='POSTED'", Stockland_DB::table( 'payments' ), $id ) ) ) );
		return Stockland_Money::subtract( Stockland_Money::add( $opening, $inv ), $pay );
	}
}
