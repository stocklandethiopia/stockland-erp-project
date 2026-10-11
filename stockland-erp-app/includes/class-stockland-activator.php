<?php
/**
 * Stockland ERP schema creation, validation, and idempotent upgrade routines.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the wp_stock_* schema with normalized DDL. dbDelta remains the safe, idempotent
 * upgrade/finalization mechanism for existing structures; protected browser repair of missing
 * tables uses direct CREATE + physical verification. The reporting VIEWs
 * that mirror the workbook's formula-only sheets (COMPANY DASHBOARD, REPORT,
 * REORDER ACTIONS).
 *
 * NOTE: dbDelta does not reliably parse FOREIGN KEY constraints, so
 * referential integrity between tables (sku -> wp_stock_products.sku,
 * location_id -> wp_stock_locations.id, etc.) is enforced in the REST/model
 * layer rather than at the InnoDB constraint level. Indexes are still
 * created on every foreign-key-like column for query performance.
 */
class Stockland_Activator {

	/** Canonical core schema inventory. Keep this list as the single source of truth. */
	public static function core_table_keys() {
		return array(
			'locations',
			'settings',
			'products',
			'price_history',
			'product_sku_history',
			'users',
			'transactions',
			'receipts',
			'transfers',
			'requisitions',
			'requisition_items',
			'reservations',
			'sales',
			'wholesale_sales',
			'returns',
			'adjustments',
			'count_batches',
			'count_lines',
			'workflow_events',
			'daily_closings',
			'import_jobs',
			'import_rows',
			'notifications',
			'sale_batches',
			'balances',
			'import_profiles',
			'product_code_maps',
			'audit_logs',
			'user_locations',
		);
	}

	/** Return actually missing core tables using one SHOW TABLES query for shared-host efficiency. */
	public static function missing_core_tables() {
		global $wpdb;
		$prefix     = $wpdb->prefix . 'stock_';
		$like       = $wpdb->esc_like( $prefix ) . '%';
		$found      = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$found_keys = array();
		foreach ( (array) $found as $table ) {
			if ( strpos( $table, $prefix ) === 0 ) {
				$found_keys[] = substr( $table, strlen( $prefix ) );
			}
		}
		return array_values( array_diff( self::core_table_keys(), $found_keys ) );
	}

	/**
	 * Complete or bounded schema installation.
	 *
	 * $batch_size=0 completes the full schema/release finalization. A positive
	 * value installs only that many currently-missing Core tables, then returns
	 * progress. This keeps first-run requests short on constrained shared hosting.

	 * @param int $batch_size Maximum number of schema objects to process in this activation batch.
	 * @throws RuntimeException When a required schema object cannot be created or verified.
	 */
	public static function activate( $batch_size = 0 ) {
		$batch_size = max( 0, (int) $batch_size );
		$batch_mode = $batch_size > 0;
		global $wpdb;

		// A Core repair request is a repair operation, not a general migration trigger.
		// When all 29 Core tables already exist, return the verified physical state
		// immediately instead of entering the historical finalization path. This makes
		// the manual repair button idempotent and removes the possibility of stale/old
		// runtime call paths surfacing as a Core repair failure.
		if ( $batch_mode && empty( self::missing_core_tables() ) ) {
			return array(
				'complete'            => true,
				'installed_batch'     => 0,
				'verified_tables'     => array(),
				'missing_core_tables' => array(),
				'errors'              => array(),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix; // Usually `wp_`.

		// Migration helpers must exist before the legacy finalization section uses them.
		// A previous release defined these closures only inside apply_schema_1600_migrations(),
		// but activate() also called them while repairing returns/count lines. In PHP an
		// undefined variable containing a callback is not callable and produced the live
		// error: "Value of type null is not callable". Keep the helpers local to this
		// activation scope so both bounded and full in-place repair paths are safe.
		$has_col   = function ( $table, $column ) use ( $wpdb ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s',
					DB_NAME,
					$table,
					$column
				)
			);
		};
		$has_index = function ( $table, $index ) use ( $wpdb ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s',
					DB_NAME,
					$table,
					$index
				)
			);
		};

		// Detect a genuinely fresh ERP namespace once. Fresh installs use the
		// final CREATE TABLE statements directly so a shared host does not pay
		// dbDelta's comparison/parsing cost for tables that cannot exist yet.
		// Existing/partial installs continue through dbDelta so upgrades remain
		// non-destructive and schema-diff aware.
		$stock_prefix          = $p . 'stock_';
		$like_stock            = $wpdb->esc_like( $stock_prefix ) . '%';
		$existing_stock_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like_stock ) );
		$fresh_namespace       = empty( $existing_stock_tables );

		$tables = array();

		// 1. Locations (SETTINGS sheet rows 10-16)
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_locations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_name VARCHAR(80) NOT NULL,
            branch_code VARCHAR(30) NULL,
            location_type VARCHAR(20) NOT NULL,
            role_description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY location_name (location_name),
            UNIQUE KEY branch_code (branch_code)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 2. Settings (VAT rate, FX rate, reorder defaults, business info)
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_settings (
            setting_key VARCHAR(100) NOT NULL,
            setting_value LONGTEXT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (setting_key)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 3. Product Master
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_products (
            variant_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(64) NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            product_type VARCHAR(20) NOT NULL DEFAULT 'SIMPLE',
            parent_sku VARCHAR(64) NULL,
            style_code VARCHAR(50) NULL,
            brand VARCHAR(100) NULL,
            department VARCHAR(50) NULL,
            sub_category VARCHAR(100) NULL,
            collection_name VARCHAR(100) NULL,
            season VARCHAR(50) NULL,
            material VARCHAR(150) NULL,
            fit VARCHAR(50) NULL,
            country_of_origin VARCHAR(80) NULL,
            image_url VARCHAR(500) NULL,
            barcode VARCHAR(64) NULL,
            gtin VARCHAR(64) NULL,
            color VARCHAR(50) NULL,
            color_code VARCHAR(30) NULL,
            size VARCHAR(50) NULL,
            size_code VARCHAR(30) NULL,
            size_system VARCHAR(20) NULL,
            category VARCHAR(100) NULL,
            gender VARCHAR(20) NULL,
            age_group VARCHAR(20) NULL,
            unit VARCHAR(10) NOT NULL DEFAULT 'PCS',
            purchase_cost DECIMAL(12,2) NULL,
            retail_price DECIMAL(12,2) NULL,
            wholesale_price DECIMAL(12,2) NULL,
            notes VARCHAR(255) NULL,
            source_default_value DECIMAL(12,4) NULL,
            source_default_tax VARCHAR(64) NULL,
            source_created_at DATETIME NULL,
            branch_reorder_level INT NULL DEFAULT NULL,
            central_reorder_level INT NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (variant_id),
            UNIQUE KEY sku (sku),
            KEY parent_sku (parent_sku),
            KEY style_code (style_code),
            KEY barcode (barcode),
            KEY color_size (color,size),
            KEY active_name (is_active,product_name),
            KEY active_brand (is_active,brand)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 3b. Price history
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_price_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            variant_id BIGINT UNSIGNED NULL,
            sku VARCHAR(64) NOT NULL,
            price_type VARCHAR(20) NOT NULL,
            old_value DECIMAL(12,2) NULL,
            new_value DECIMAL(12,2) NOT NULL,
            changed_by BIGINT UNSIGNED NOT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY variant_id (variant_id),
            KEY sku (sku)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 3c. Immutable Product Identity / SKU History. A SKU may change in the master,
		// but the internal variant_id never changes and historical transactions are not rewritten.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_product_sku_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            variant_id BIGINT UNSIGNED NOT NULL,
            old_sku VARCHAR(64) NOT NULL,
            new_sku VARCHAR(64) NOT NULL,
            effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reason VARCHAR(255) NOT NULL,
            changed_by BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY variant_id (variant_id),
            KEY old_sku (old_sku),
            KEY new_sku (new_sku),
            KEY effective_from (effective_from)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 4. Users (role + branch assignment, individual identity per section 14)
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(30) NOT NULL,
            branch_location_id BIGINT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY branch_location_id (branch_location_id)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 4b. Future-proof operational scope assignments. The existing primary
		// branch_location_id remains for backward compatibility, while this table
		// allows one user to be explicitly assigned to one or more authorized
		// operational locations without granting company-wide access.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_user_locations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            effective_from DATE NULL,
            effective_to DATE NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_location (wp_user_id,location_id),
            KEY location_user (location_id,wp_user_id),
            KEY user_active (wp_user_id,is_active)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 5. Stock ledger (the engine room)
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            size VARCHAR(50) NULL,
            color VARCHAR(50) NULL,
            barcode VARCHAR(64) NULL,
            movement_type VARCHAR(30) NOT NULL,
            quantity_signed DECIMAL(12,2) NOT NULL,
            reference_type VARCHAR(30) NOT NULL,
            reference_id VARCHAR(80) NOT NULL,
            source_line_id VARCHAR(80) NOT NULL DEFAULT '',
            transaction_date DATE NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY location_sku (location_id, sku),
            KEY location_variant (location_id, variant_id),
            KEY location_variant_date (location_id, variant_id, transaction_date),
            KEY location_date (location_id, transaction_date),
            KEY sku_date (sku, transaction_date),
            KEY variant_id (variant_id),
            KEY reference (reference_type, reference_id),
            KEY source_line (source_line_id),
            UNIQUE KEY movement_reference (reference_type, reference_id, source_line_id, movement_type)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 6. Warehouse receipts
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_receipts (
            receipt_id VARCHAR(20) NOT NULL,
            receipt_date DATE NOT NULL,
            supplier_source VARCHAR(150) NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            size VARCHAR(50) NULL,
            color VARCHAR(50) NULL,
            barcode VARCHAR(64) NULL,
            quantity_received DECIMAL(12,2) NOT NULL,
            actual_quantity_received DECIMAL(12,2) NULL,
            received_by BIGINT UNSIGNED NULL,
            received_at DATETIME NULL,
            variance_reason VARCHAR(255) NULL,
            unit_cost DECIMAL(12,2) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (receipt_id),
            KEY sku (sku)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 7. Transfers
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_transfers (
            transfer_id VARCHAR(20) NOT NULL,
            transfer_date DATE NOT NULL,
            from_location_id BIGINT UNSIGNED NOT NULL,
            to_location_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            size VARCHAR(50) NULL,
            color VARCHAR(50) NULL,
            barcode VARCHAR(64) NULL,
            quantity DECIMAL(12,2) NOT NULL,
            dispatched_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            received_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            variance_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            dispatched_by BIGINT UNSIGNED NULL,
            dispatched_at DATETIME NULL,
            received_by BIGINT UNSIGNED NULL,
            received_at DATETIME NULL,
            receipt_reason VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Requested',
            requested_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            requisition_id VARCHAR(20) NULL,
            requisition_item_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (transfer_id),
            KEY sku (sku),
            KEY from_location_id (from_location_id),
            KEY to_location_id (to_location_id),
            KEY requisition_id (requisition_id),
            KEY status_date (status, transfer_date),
            KEY from_status (from_location_id, status),
            KEY to_status (to_location_id, status)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 8. Requisitions + items
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_requisitions (
            requisition_id VARCHAR(20) NOT NULL,
            branch_location_id BIGINT UNSIGNED NOT NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            date_requested DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Requested',
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (requisition_id),
            KEY branch_location_id (branch_location_id),
            KEY status_date (status, date_requested)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_requisition_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requisition_id VARCHAR(20) NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            quantity_requested DECIMAL(12,2) NOT NULL,
            quantity_approved DECIMAL(12,2) NULL,
            quantity_dispatched DECIMAL(12,2) NULL,
            quantity_received DECIMAL(12,2) NULL,
            PRIMARY KEY  (id),
            KEY requisition_id (requisition_id),
            KEY sku (sku),
            KEY variant_id (variant_id)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 9. Reservations
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_reservations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            requisition_id VARCHAR(20) NULL,
            quantity_reserved DECIMAL(12,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            transfer_id VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            released_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY location_sku (location_id, sku),
            KEY location_variant (location_id, variant_id),
            KEY location_status_variant (location_id, status, variant_id),
            KEY variant_id (variant_id),
            KEY requisition_id (requisition_id),
            KEY transfer_id (transfer_id)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 10. Retail sales (legacy archive + all DAILY branch sheets, unified)
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_sales (
            sale_id VARCHAR(20) NOT NULL,
            sale_date DATE NOT NULL,
            branch_location_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            quantity_sold DECIMAL(12,2) NOT NULL,
            size VARCHAR(50) NULL,
            color VARCHAR(50) NULL,
            barcode VARCHAR(64) NULL,
            master_price_at_sale DECIMAL(12,2) NOT NULL,
            selling_price DECIMAL(12,2) NOT NULL,
            price_overridden TINYINT(1) NOT NULL DEFAULT 0,
            vat_mode VARCHAR(20) NOT NULL,
            vat_rate_used DECIMAL(6,4) NOT NULL,
            sales_total DECIMAL(14,2) NOT NULL,
            vat_amount DECIMAL(14,2) NOT NULL,
            net_sales_excl_vat DECIMAL(14,2) NOT NULL,
            cogs DECIMAL(14,2) NOT NULL,
            gross_profit DECIMAL(14,2) NOT NULL,
            posting_status VARCHAR(10) NOT NULL DEFAULT 'OPEN',
            staff_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            closed_by BIGINT UNSIGNED NULL,
            external_receipt_no VARCHAR(80) NULL,
            source_job_id BIGINT UNSIGNED NULL,
            source_row_number INT UNSIGNED NULL,
            source_sale_group VARCHAR(64) NULL,
            PRIMARY KEY  (sale_id),
            KEY branch_sku (branch_location_id, sku),
            KEY branch_variant_date (branch_location_id, variant_id, sale_date),
            KEY posting_status (posting_status),
            KEY source_job_row (source_job_id,source_row_number),
            KEY source_group (source_sale_group)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 11. Wholesale sales
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_wholesale_sales (
            sale_id VARCHAR(20) NOT NULL,
            sale_date DATE NOT NULL,
            customer VARCHAR(150) NOT NULL,
            sku VARCHAR(64) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            wholesale_price DECIMAL(12,2) NOT NULL,
            sales_value DECIMAL(14,2) NOT NULL,
            payment_status VARCHAR(30) NOT NULL DEFAULT 'Unpaid',
            vat_amount DECIMAL(14,2) NOT NULL,
            net_sales_excl_vat DECIMAL(14,2) NOT NULL,
            cogs DECIMAL(14,2) NOT NULL,
            gross_profit DECIMAL(14,2) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (sale_id),
            KEY sku (sku)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 12. Returns & adjustments
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_returns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            return_date DATE NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            return_type VARCHAR(20) NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            quantity DECIMAL(12,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            reference_sale_id VARCHAR(20) NULL,
            created_by BIGINT UNSIGNED NULL,
            approver_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY location_sku (location_id, sku),
            KEY location_variant (location_id, variant_id),
            KEY variant_id (variant_id)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 12b. Inventory reconciliation / adjustment approvals
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_adjustments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            adjustment_id VARCHAR(30) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            size VARCHAR(50) NULL,
            color VARCHAR(50) NULL,
            barcode VARCHAR(64) NULL,
            counted_qty DECIMAL(12,2) NOT NULL,
            system_qty DECIMAL(12,2) NOT NULL,
            variance_qty DECIMAL(12,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            requested_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            rejection_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY adjustment_id (adjustment_id),
            KEY location_sku (location_id, sku), KEY status (status), KEY location_status (location_id, status)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 12c. Physical stock-count sessions and immutable count lines.
		// A manager uploads what was physically found (brand/product/size/color/qty);
		// the system resolves each line to the Product Master SKU and creates a
		// controlled pending reconciliation. Approval, not the upload itself, changes stock.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_count_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id VARCHAR(30) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            count_date DATE NOT NULL,
            cutoff_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            uploaded_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            source_file VARCHAR(255) NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), UNIQUE KEY batch_id (batch_id), KEY location_status (location_id,status), KEY count_date (count_date)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_count_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id VARCHAR(30) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            brand VARCHAR(100) NULL,
            product_name VARCHAR(150) NULL,
            size VARCHAR(50) NULL,
            color VARCHAR(50) NULL,
            barcode VARCHAR(64) NULL,
            counted_qty DECIMAL(12,2) NOT NULL,
            system_qty DECIMAL(12,2) NOT NULL,
            variance_qty DECIMAL(12,2) NOT NULL,
            adjustment_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), KEY batch_id (batch_id), KEY location_sku (location_id,sku), KEY location_variant (location_id,variant_id), KEY variant_id (variant_id), KEY adjustment_id (adjustment_id)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 12d. Immutable workflow timeline
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_workflow_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(30) NOT NULL,
            entity_id VARCHAR(40) NOT NULL,
            from_status VARCHAR(30) NULL,
            to_status VARCHAR(30) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id), KEY entity (entity_type,entity_id), KEY created_at (created_at)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 13. Daily open/close control
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_daily_closings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_location_id BIGINT UNSIGNED NOT NULL,
            business_date DATE NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'OPEN',
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            closed_by BIGINT UNSIGNED NULL,
            total_sales DECIMAL(14,2) NULL,
            total_vat DECIMAL(14,2) NULL,
            total_gross_profit DECIMAL(14,2) NULL,
            notes VARCHAR(255) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY branch_day (branch_location_id, business_date)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 14. Import job master. The source file is treated as an input document;
		// staged rows remain immutable until the job is committed in bounded chunks.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_import_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_key CHAR(64) NOT NULL,
            import_type VARCHAR(30) NOT NULL,
            branch_location_id BIGINT UNSIGNED NULL,
            business_date DATE NULL,
            source_filename VARCHAR(255) NOT NULL,
            source_sha256 CHAR(64) NOT NULL,
            source_system VARCHAR(60) NOT NULL DEFAULT 'THIRD_PARTY_POS',
            mapping_profile_id BIGINT UNSIGNED NULL,
            mapping_signature CHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'PREVIEW',
            total_rows INT UNSIGNED NOT NULL DEFAULT 0,
            valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
            error_rows INT UNSIGNED NOT NULL DEFAULT 0,
            processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            message VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY import_key (import_key),
            KEY type_status (import_type,status),
            KEY branch_date (branch_location_id,business_date),
            KEY sha256 (source_sha256),
            KEY source_system_signature (source_system,mapping_signature)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 15. Immutable staged import rows. JSON keeps the original normalized
		// source fields available for audit while indexed columns drive posting.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_import_rows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            source_row_number INT UNSIGNED NOT NULL,
            external_receipt_no VARCHAR(80) NULL,
            source_code VARCHAR(128) NULL,
            sku VARCHAR(64) NULL,
            mapped_sku VARCHAR(64) NULL,
            variant_id BIGINT UNSIGNED NULL,
            mapped_via VARCHAR(30) NULL,
            quantity DECIMAL(12,2) NULL,
            master_price DECIMAL(12,2) NULL,
            selling_price DECIMAL(12,2) NULL,
            vat_mode VARCHAR(20) NULL,
            vat_rate DECIMAL(6,4) NULL,
            vat_amount DECIMAL(14,2) NULL,
            sales_total DECIMAL(14,2) NULL,
            cost_basis DECIMAL(12,2) NULL,
            cost_basis_source VARCHAR(40) NULL,
            source_key VARCHAR(190) NULL,
            payload LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'VALID',
            error_message VARCHAR(500) NULL,
            posted_sale_id VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            posted_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_row (job_id,source_row_number),
            KEY job_status (job_id,status),
            KEY source_key (source_key),
            KEY sku (sku),
            KEY receipt (external_receipt_no)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 16. Persistent application notifications. Notifications are not
		// WordPress admin notices and survive navigation/refresh.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            audience_role VARCHAR(40) NULL,
            location_id BIGINT UNSIGNED NULL,
            notification_type VARCHAR(40) NOT NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            entity_type VARCHAR(40) NULL,
            entity_id VARCHAR(80) NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_read (user_id,is_read,id),
            KEY role_location (audience_role,location_id,is_read,id),
            KEY entity (entity_type,entity_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 17. One business-level batch record allows daily imported POS
		// receipts to be reconciled without pretending the ERP is the POS.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_sale_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            branch_location_id BIGINT UNSIGNED NOT NULL,
            business_date DATE NOT NULL,
            source_filename VARCHAR(255) NOT NULL,
            external_receipt_no VARCHAR(120) NULL,
            receipt_key CHAR(64) NOT NULL,
            source_system VARCHAR(80) NOT NULL DEFAULT 'POS',
            receipt_count INT UNSIGNED NOT NULL DEFAULT 1,
            row_count INT UNSIGNED NOT NULL DEFAULT 0,
            units DECIMAL(14,2) NOT NULL DEFAULT 0,
            gross_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
            vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            cogs DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
            posted_by BIGINT UNSIGNED NOT NULL,
            posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY job_receipt (job_id,receipt_key),
            KEY branch_date (branch_location_id,business_date),
            KEY source_receipt (source_system,external_receipt_no),
            KEY status (status)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 18. Fast current-balance read model. The ledger remains authoritative;
		// this table is rebuilt incrementally from immutable movements for fast
		// mobile/dashboard/report reads.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_balances (
            location_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            physical_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (location_id,variant_id),
            KEY sku (sku),
            KEY location_sku (location_id,sku)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 19. Reusable import mapping profiles. Header/order changes in a POS
		// export do not force the branch to re-format its file. Once a mapping
		// is confidently recognized it is stored and reused.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_import_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_type VARCHAR(30) NOT NULL,
            source_system VARCHAR(60) NOT NULL,
            profile_name VARCHAR(120) NOT NULL,
            header_signature CHAR(64) NOT NULL,
            mapping_json LONGTEXT NOT NULL,
            mapping_version INT UNSIGNED NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            updated_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY import_profile_unique (import_type,source_system,header_signature),
            KEY type_source (import_type,source_system,is_active)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 20. External-to-live product code cross-reference. Historical ERP/POS
		// codes can point to the current Stockland live SKU without rewriting
		// historical ledger rows.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_product_code_maps (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            variant_id BIGINT UNSIGNED NULL,
            source_system VARCHAR(60) NOT NULL,
            external_code VARCHAR(128) NOT NULL,
            live_sku VARCHAR(64) NOT NULL,
            source_name VARCHAR(150) NULL,
            mapping_note VARCHAR(255) NULL,
            effective_from DATE NULL,
            effective_to DATE NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            updated_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY source_external_effective (source_system,external_code,effective_from),
            KEY source_external_active (source_system,external_code,is_active),
            KEY variant_id (variant_id),
            KEY live_sku (live_sku),
            KEY source_active (source_system,is_active)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 18. Audit log
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta needs a complete CREATE TABLE string; the WordPress prefix and schema definitions are fixed, plugin-owned DDL.
		$tables[] = "CREATE TABLE {$p}stock_audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            branch_location_id BIGINT UNSIGNED NULL,
            action_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            action VARCHAR(50) NOT NULL,
            sku VARCHAR(64) NULL,
            quantity DECIMAL(12,2) NULL,
            old_value VARCHAR(255) NULL,
            new_value VARCHAR(255) NULL,
            reason VARCHAR(255) NULL,
            reference VARCHAR(50) NULL,
            approver_id BIGINT UNSIGNED NULL,
            previous_hash CHAR(64) NULL,
            event_hash CHAR(64) NULL,
            hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY branch_location_id (branch_location_id),
            KEY event_hash (event_hash),
            KEY action_time (action_time),
            KEY hash_version (hash_version)
        ) ENGINE=InnoDB $charset_collate;";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// In bounded setup mode, execute only the first N currently-missing Core
		// tables in canonical order. Existing tables are left untouched until the
		// finalization request, where the full upgrade/migration pass runs.
		if ( $batch_mode ) {
			$missing_before = self::missing_core_tables();
			if ( ! empty( $missing_before ) ) {
				$wanted = array_slice( $missing_before, 0, $batch_size );
				$tables = array_values(
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
			} else {
				// All Core tables already exist; skip DDL and continue into the
				// idempotent finalization/migration section below.
				$tables = array();
			}
		}

		// ROW_NUMBER became a reserved MariaDB identifier. Upgrade any pre-v16
		// install before dbDelta/DDL touches the staged import table. Fresh installs use
		// source_row_number directly, while this compatibility path preserves old data.
		$import_rows_table = Stockland_DB::table( 'import_rows' );
		$table_exists      = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
				DB_NAME,
				$import_rows_table
			)
		);
		if ( $table_exists ) {
			$legacy_col = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='row_number'",
					DB_NAME,
					$import_rows_table
				)
			);
			$new_col    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='source_row_number'",
					DB_NAME,
					$import_rows_table
				)
			);
			if ( $legacy_col && ! $new_col ) {
				$renamed = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE COLUMN %i %i INT UNSIGNED NOT NULL', $import_rows_table, 'row_number', 'source_row_number' ) );
				if ( false === $renamed ) {
					throw new RuntimeException( 'Could not migrate import_rows.row_number to source_row_number.' );
				}
			}
		}

		$delta_errors  = array();
		$batch_results = array();
		foreach ( $tables as $sql ) {
			$table_key  = '';
			$full_table = '';
			if ( preg_match( '/^\s*CREATE TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
				$full_table = trim( $m[1], '`' );
				$table_key  = preg_replace( '/^' . preg_quote( $p . 'stock_', '/' ) . '/', '', $full_table );
			}

			$wpdb->last_error = '';
			if ( $batch_mode ) {
				// In the browser-resumable repair path the target tables are known to be
				// missing. Execute the final DDL directly instead of sending it through
				// dbDelta(), whose parser is intentionally strict and whose result is not
				// proof that the physical table exists. Each table is verified immediately.
				$direct_sql = preg_replace( '/^\s*CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CREATE TABLE DDL is built from the fixed Core schema array and the trusted WordPress prefix; SQL structure cannot be bound as a data value.
				$result     = $wpdb->query( $direct_sql );
				$db_error   = (string) $wpdb->last_error;
				if ( false === $result ) {
					$detail = 'Core table ' . esc_html( $table_key ? $table_key : 'unknown' ) . ' could not be created';
					if ( '' !== $db_error ) {
						$detail .= ': ' . esc_html( $db_error );
					}
					throw new RuntimeException( esc_html( $detail ) );
				}
				if ( '' === $table_key || ! self::table_exists_static( $p . 'stock_' . $table_key ) ) {
					$detail = 'Core table ' . esc_html( $table_key ? $table_key : 'unknown' ) . ' was not physically verified after CREATE TABLE.';
					if ( '' !== $db_error ) {
						$detail .= ' Database error: ' . esc_html( $db_error );
					}
					throw new RuntimeException( esc_html( $detail ) );
				}
				$batch_results[] = array(
					'table'    => $table_key,
					'verified' => true,
				);
			} elseif ( $fresh_namespace ) {
				// Fresh-install path: execute the already-normalized final DDL directly.
				$result = $wpdb->query( $sql );
				if ( false === $result && ! empty( $wpdb->last_error ) ) {
					$delta_errors[] = $wpdb->last_error;
				}
			} else {
				// Upgrade existing tables with dbDelta. Final physical schema is verified
				// after the dbDelta pass below.
				dbDelta( $sql );
				if ( ! empty( $wpdb->last_error ) ) {
					$delta_errors[] = $wpdb->last_error;
				}
			}
		}

		$missing_core_tables = self::missing_core_tables();
		if ( $missing_core_tables ) {
			foreach ( $tables as $sql ) {
				if ( ! preg_match( '/^\s*CREATE TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
					continue;
				}
				$full_table = trim( $m[1], '`' );
				$table_key  = preg_replace( '/^' . preg_quote( $p . 'stock_', '/' ) . '/', '', $full_table );
				if ( ! in_array( $table_key, $missing_core_tables, true ) ) {
					continue;
				}

				// Direct fallback for a truly missing table. This specifically protects
				// fresh installations from a dbDelta edge case on shared hosting; it does
				// not replace dbDelta for upgrades.
				$fallback_sql     = preg_replace( '/^\s*CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fallback CREATE TABLE DDL is derived from the same fixed Core schema allowlist; no request data is included.
				$result           = $wpdb->query( $fallback_sql );
				if ( false === $result && ! empty( $wpdb->last_error ) ) {
					$delta_errors[] = $wpdb->last_error;
				}
			}
			$missing_core_tables = self::missing_core_tables();
		}

		if ( $missing_core_tables ) {
			if ( $batch_mode ) {
				return array(
					'complete'            => false,
					'installed_batch'     => count( $batch_results ),
					'verified_tables'     => $batch_results,
					'missing_core_tables' => $missing_core_tables,
					'errors'              => array_values( array_unique( $delta_errors ) ),
				);
			}
			$detail = 'Core ERP schema is incomplete: ' . esc_html( implode( ', ', $missing_core_tables ) );
			if ( $delta_errors ) {
				$detail .= ' | Database error: ' . esc_html( implode( ' | ', array_unique( $delta_errors ) ) );
			}
			throw new RuntimeException( esc_html( $detail ) );
		}

		if ( ! $fresh_namespace ) {
			// Safe schema upgrade for the physical-count link. Never run an
			// unconditional ALTER because activation is intentionally idempotent.
			$has_count_batch = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='count_batch_id'",
					DB_NAME,
					$p . 'stock_adjustments'
				)
			);
			if ( ! $has_count_batch ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i VARCHAR(30) NULL AFTER %i', $p . 'stock_adjustments', 'count_batch_id', 'rejection_reason' ) );
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i)', $p . 'stock_adjustments', 'count_batch_id', 'count_batch_id' ) );
			}

			// Requisition review/approval audit fields — additive and idempotent.
			$req_fields = array(
				'reviewed_by'               => 'BIGINT UNSIGNED NULL AFTER status',
				'reviewed_at'               => 'DATETIME NULL AFTER reviewed_by',
				'review_notes'              => 'VARCHAR(255) NULL AFTER reviewed_at',
				'approved_by'               => 'BIGINT UNSIGNED NULL AFTER review_notes',
				'approved_at'               => 'DATETIME NULL AFTER approved_by',
				'approval_mode'             => "VARCHAR(20) NOT NULL DEFAULT 'ONE_OF' AFTER approved_at",
				'management_decision'       => 'VARCHAR(20) NULL AFTER approval_mode',
				'management_approved_by'    => 'BIGINT UNSIGNED NULL AFTER management_decision',
				'management_approved_at'    => 'DATETIME NULL AFTER management_approved_by',
				'management_approval_notes' => 'VARCHAR(255) NULL AFTER management_approved_at',
				'owner_decision'            => 'VARCHAR(20) NULL AFTER management_approval_notes',
				'owner_approved_by'         => 'BIGINT UNSIGNED NULL AFTER owner_decision',
				'owner_approved_at'         => 'DATETIME NULL AFTER owner_approved_by',
				'owner_approval_notes'      => 'VARCHAR(255) NULL AFTER owner_approved_at',
			);
			foreach ( $req_fields as $field => $definition ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s', DB_NAME, $p . 'stock_requisitions', $field ) );
				if ( ! $exists ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL type/default fragment comes from the fixed required-columns allowlist; table and column identifiers are bound below.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i ' . $definition, $p . 'stock_requisitions', $field ) );}
			}
			// Warehouse-controlled reservation / picking + branch pickup appointment.
			$req_fields_1301 = array(
				'pickup_date'           => 'DATE NULL AFTER owner_approval_notes',
				'warehouse_reserved_by' => 'BIGINT UNSIGNED NULL AFTER pickup_date',
				'warehouse_reserved_at' => 'DATETIME NULL AFTER warehouse_reserved_by',
				'warehouse_picked_by'   => 'BIGINT UNSIGNED NULL AFTER warehouse_reserved_at',
				'warehouse_picked_at'   => 'DATETIME NULL AFTER warehouse_picked_by',
			);
			foreach ( $req_fields_1301 as $field => $definition ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s', DB_NAME, $p . 'stock_requisitions', $field ) );
				if ( ! $exists ) {
					$wpdb->query( "ALTER TABLE {$p}stock_requisitions ADD COLUMN {$field} {$definition}" );}
			}
			$transfer_fields_1301 = array(
				'pickup_date' => 'DATE NULL AFTER requisition_item_id',
				'reserved_by' => 'BIGINT UNSIGNED NULL AFTER pickup_date',
				'reserved_at' => 'DATETIME NULL AFTER reserved_by',
				'picked_by'   => 'BIGINT UNSIGNED NULL AFTER reserved_at',
				'picked_at'   => 'DATETIME NULL AFTER picked_by',
			);
			foreach ( $transfer_fields_1301 as $field => $definition ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s', DB_NAME, $p . 'stock_transfers', $field ) );
				if ( ! $exists ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL type/default fragment comes from the fixed required-columns allowlist; table and column identifiers are bound below.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i ' . $definition, $p . 'stock_transfers', $field ) );}
			}
			$expense_loc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='location_id'", DB_NAME, $p . 'stock_expenses' ) );
			if ( ! $expense_loc ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i BIGINT UNSIGNED NULL AFTER %i', $p . 'stock_expenses', 'location_id', 'expense_date' ) );
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i,%i)', $p . 'stock_expenses', 'location_date', 'location_id', 'expense_date' ) );}

			// v16: recover import_rows for any partial/legacy installation in which
			// the staging table or its legacy indexes are incomplete. The canonical
			// CREATE TABLE above is the source of truth; live business rows are never
			// fabricated here.
			if ( self::table_exists_static( $p . 'stock_import_rows' ) ) {
				$legacy_indexes = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME IN('job','branch_date')",
						DB_NAME,
						$p . 'stock_import_rows'
					)
				);
				foreach ( (array) $legacy_indexes as $legacy ) {
					$safe = preg_replace( '/[^A-Za-z0-9_]/', '', $legacy );
					if ( $safe ) {
						$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $p . 'stock_import_rows', $safe ) );
					}
				}
			}

			// 15.0: reservation identity follows immutable variant_id. Existing rows are
			// backfilled from the current Product Master without rewriting historical quantities.
			$has_res_variant = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='variant_id'",
					DB_NAME,
					$p . 'stock_reservations'
				)
			);
			if ( ! $has_res_variant ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i BIGINT UNSIGNED NULL AFTER %i', $p . 'stock_reservations', 'variant_id', 'sku' ) );
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i,%i)', $p . 'stock_reservations', 'location_variant', 'location_id', 'variant_id' ) );
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i)', $p . 'stock_reservations', 'variant_id', 'variant_id' ) );
			}
			if ( self::table_exists_static( $p . 'stock_reservations' ) && self::table_exists_static( $p . 'stock_products' ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i r INNER JOIN %i p ON p.sku=r.sku SET r.variant_id=p.variant_id WHERE r.variant_id IS NULL AND p.variant_id IS NOT NULL', $p . 'stock_reservations', $p . 'stock_products' ) );
			}

			// Historical returns and physical-count lines carry immutable variant identity
			// so reporting remains stable after a Product Master SKU replacement.
			foreach ( array(
				$p . 'stock_returns'     => array(
					'after' => 'sku',
					'index' => 'variant_id',
				),
				$p . 'stock_count_lines' => array(
					'after' => 'sku',
					'index' => 'variant_id',
				),
			) as $table => $cfg ) {
				if ( ! self::table_exists_static( $table ) ) {
					continue;
				}
				if ( ! $has_col( $table, 'variant_id' ) ) {
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i BIGINT UNSIGNED NULL AFTER %i', $table, 'variant_id', $cfg['after'] ) );
				}
				if ( ! $has_index( $table, $cfg['index'] ) ) {
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i)', $table, 'variant_id', 'variant_id' ) );
				}
				if ( self::table_exists_static( $p . 'stock_products' ) ) {
					$wpdb->query( $wpdb->prepare( 'UPDATE %i r INNER JOIN %i p ON p.sku=r.sku SET r.variant_id=p.variant_id WHERE r.variant_id IS NULL AND p.variant_id IS NOT NULL', $table, $p . 'stock_products' ) );
				}
			}

			// 10.5: historical audit rows are preserved as hash_version=1. New events use
			// canonical v2 hashing; no historical audit hash is rewritten.
			$has_hash_version = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='hash_version'",
					DB_NAME,
					$p . 'stock_audit_logs'
				)
			);
			if ( ! $has_hash_version ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER %i', $p . 'stock_audit_logs', 'hash_version', 'event_hash' ) );
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i)', $p . 'stock_audit_logs', 'hash_version', 'hash_version' ) );
			}

			// 9.0: every stockable catalog row receives an immutable numeric variant identity.
			// Existing SKUs are backfilled by MySQL AUTO_INCREMENT; this does not alter stock quantities.

			// v16: migrate the legacy primary assignment into the explicit scope table.
			// This is additive and idempotent; no user loses their existing branch.
			if ( self::table_exists_static( $p . 'stock_user_locations' ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (wp_user_id,location_id,is_primary,is_active,created_by) SELECT wp_user_id,branch_location_id,1,is_active,NULL FROM %i WHERE branch_location_id IS NOT NULL',
						$p . 'stock_user_locations',
						$p . 'stock_users'
					)
				);
			}
		}

		// v16: application-wide schema convergence. Never fabricate business
		// data; only add fields/indexes or rebuild the derived balance read model
		// from immutable ledger rows.
		self::apply_schema_1600_migrations( $p );

		// No MySQL VIEW privilege is required; runtime derives balances from indexed ledger tables.
		self::seed_locations( $p );
		// Seeded locations created after migration also receive stable codes immediately.
		self::backfill_location_codes( $p );
		self::seed_settings( $p );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (setting_key,setting_value) VALUES (%s,%s)", $p . 'stock_settings', 'requisition_approval_mode', 'ONE_OF' ) );

		// Roles must exist before accounting/procurement grants are applied.
		Stockland_Roles::register_roles();
		Stockland_Roles::sync_roles();

		// Accounting/procurement (16 more tables + seed rows) is deliberately NOT
		// installed inside this activation request. The Stockland PWA setup controller
		// continues it automatically in short browser requests, which avoids long
		// activation-time execution on constrained shared hosting. The legacy admin
		// repair method remains available only as an explicit fallback.
		update_option( 'stockland_accounting_pending', 1, false );

		update_option( 'stockland_core_schema_version', STOCKLAND_CORE_SCHEMA_VERSION );
		update_option( 'stockland_core_schema_version', STOCKLAND_CORE_SCHEMA_VERSION, false );
		// Global DB version is set only by stockland_init_plugin after all 49 application tables are verified.
		update_option( 'stockland_db_version', get_option( 'stockland_db_version', '0.0.0' ), false );
		delete_option( 'stockland_activation_pending' );
		delete_option( 'stockland_activation_redirect' );

		// WordPress administrators can operate the Stockland app immediately.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array(
				'stockland_view_all_branches',
				'stockland_manage_products',
				'stockland_manage_settings',
				'stockland_view_cost_and_profit',
				'stockland_approve_transfers',
				'stockland_approve_requisitions',
				'stockland_receive_warehouse',
				'stockland_close_day',
				'stockland_record_return',
				'stockland_import_excel',
				'stockland_view_audit_log',
				'stockland_view_warehouse',
				'stockland_manage_reservations',
				'stockland_manage_transfers',
				'stockland_review_requisitions',
				'stockland_request_stock',
				'stockland_view_branch_reports',
				'stockland_request_adjustment',
			) as $cap ) {
				$admin->add_cap( $cap ); }
		}

		// Give the current administrator a company-wide Stockland profile so the
		// first login works without a separate onboarding/configuration step.
		$uid = get_current_user_id();
		if ( $uid ) {
			global $wpdb;
			$existing_profile = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE wp_user_id=%d LIMIT 1', $p . 'stock_users', $uid ) );
			if ( ! $existing_profile ) {
				$wpdb->query(
					$wpdb->prepare(
						$wpdb->prepare( "INSERT INTO %i (wp_user_id,role,branch_location_id,is_active) VALUES (%d,'owner',NULL,1)", $p . 'stock_users', $uid ),
						$uid
					)
				);
			}
		}

		// Product master is intentionally empty on a fresh install. Real catalog data is loaded only
		// through the controlled import preview/approval workflow.

		// Product and stock data are imported only after management reviews the import preview.
		// Historical operational fields are intentionally NOT rewritten during activation.
		// System Health reports any legacy gaps so management can review them explicitly.
	}

	/**
	 * Reporting VIEWs — dbDelta cannot create these, so raw queries.
	 * Mirrors COMPANY DASHBOARD / REPORT / REORDER ACTIONS: those sheets
	 * are formula-only, so these are read-only derived views, never
	 * written to directly by the application.
	 */
	/**
	 * Seed the six real Stockland locations (SETTINGS sheet). Uses
	 * INSERT IGNORE so re-running activation never duplicates or
	 * overwrites rows management may have edited (name/role_description).
	 */
	public static function application_missing_tables() {
		return array_values(
			array_unique(
				array_merge(
					self::missing_core_tables(),
					class_exists( 'Stockland_Accounting' ) ? Stockland_Accounting::missing_tables() : array(),
					class_exists( 'Stockland_HR' ) ? Stockland_HR::missing_tables() : array()
				)
			)
		);
	}

	/**
	 * Returns the authoritative manifest of application-owned tables and their schema requirements.
	 *
	 * @return array The ordered application-table schema manifest.
	 */
	public static function application_table_manifest() {
		return array_values(
			array_unique(
				array_merge(
					self::core_table_keys(),
					class_exists( 'Stockland_Accounting' ) ? Stockland_Accounting::table_keys() : array(),
					class_exists( 'Stockland_HR' ) ? Stockland_HR::table_keys() : array()
				)
			)
		);
	}

	/** Mark a fresh activation as pending without doing heavy schema work inside
	 * WordPress's activation request. The protected Stockland PWA setup controller
	 * completes the schema in small browser-resumable batches after activation.
	 */
	public static function begin_activation() {
		update_option( 'stockland_activation_pending', 1, false );
		update_option( 'stockland_accounting_pending', 1, false );
		update_option( 'stockland_db_version', '0.0.0', false );
		update_option( 'stockland_core_schema_version', '0.0.0', false );
		update_option( 'stockland_accounting_schema_version', '0.0.0', false );
		update_option( 'stockland_accounting_db_version', '0.0.0', false );
		update_option( 'stockland_hr_schema_version', '0.0.0', false );
		update_option( 'stockland_hr_db_version', '0.0.0', false );
		delete_option( 'stockland_activation_error' );
		delete_option( 'stockland_boot_error' );
		Stockland_Roles::register_roles();
		Stockland_Roles::sync_roles();
		// The frontend integration creates/repairs only the ERP's known entry Page and
		// registers the direct alias. It never overwrites an unrelated WordPress page.
		if ( class_exists( 'Stockland_Frontend' ) ) {
			Stockland_Frontend::activate_frontend();
		}
		update_option( 'stockland_setup_state', 'ACTIVATED', false );
	}

	/**
	 * Checks whether a validated database table exists on the current WordPress connection.
	 *
	 * @param string $table_name Allow-listed table name to check.
	 * @return bool Whether the table exists.
	 */
	private static function table_exists_static( $table_name ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
				DB_NAME,
				$table_name
			)
		);
	}

	/**
	 * Ensures the required Stockland operational locations exist without duplicating existing locations.
	 *
	 * @param wpdb $p WordPress database connection used by the protected installer.
	 * @return void Ensures required operational locations exist.
	 */
	private static function seed_locations( $p ) {
		global $wpdb;

		$locations = array(
			array( 'Central Warehouse', 'Warehouse', 'Main inventory control point — receives stock, supplies branches and wholesale' ),
			array( 'Goro', 'Branch', 'Retail shop — sells to walk-in customers' ),
			array( 'Gerji', 'Branch', 'Retail shop — sells to walk-in customers' ),
			array( 'Mexico', 'Branch', 'Retail shop — sells to walk-in customers' ),
			array( 'Megenagna', 'Branch', 'Retail shop — sells to walk-in customers' ),
			array( 'Wholesale', 'Sales channel', 'Bulk sales direct from Central Warehouse to wholesale customers' ),
		);

		foreach ( $locations as $loc ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (location_name, location_type, role_description) VALUES (%s,%s,%s)',
					$p . 'stock_locations',
					$loc[0],
					$loc[1],
					$loc[2]
				)
			);
		}
	}

	/**
	 * Seed default settings taken directly from the SETTINGS sheet. These
	 * are starting values Management already entered in the workbook —
	 * nothing here is invented, and Management can change any of them
	 * post-activation via the settings screen (VAT rate, reorder levels).

	 * @param wpdb $p WordPress database connection used by the protected installer.
	 */
	private static function backfill_location_codes( $p ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,location_name,location_type,branch_code FROM %i ORDER BY id', $p . 'stock_locations' ), ARRAY_A );
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $row ) {
			if ( ! empty( $row['branch_code'] ) ) {
				continue;
			}
			$prefix    = 'Warehouse' === $row['location_type'] ? 'WH' : ( 'Sales channel' === $row['location_type'] ? 'SC' : 'BR' );
			$candidate = $prefix . str_pad( (string) ( (int) $row['id'] ), 3, '0', STR_PAD_LEFT );
			$exists    = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE branch_code=%s AND id<>%d LIMIT 1', $p . 'stock_locations', $candidate, (int) $row['id'] ) );
			if ( $exists ) {
				$candidate = $prefix . str_pad( (string) ( (int) $row['id'] ), 4, '0', STR_PAD_LEFT );
			}
			$wpdb->update( $p . 'stock_locations', array( 'branch_code' => $candidate ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
		}
	}

	/**
	 * Ensures required Stockland system settings have their safe default values.
	 *
	 * @param wpdb $p WordPress database connection used by the protected installer.
	 * @return void Ensures required settings exist.
	 */
	private static function seed_settings( $p ) {
		global $wpdb;

		$defaults = array(
			'vat_rate'                       => '0.15',
			'vat_basis'                      => 'INCLUSIVE', // selling prices are VAT-inclusive; VAT extracted, not added on top
			'branch_reorder_alert_level'     => '2',
			'central_reorder_alert_level'    => '200',
			'default_branch_reorder_target'  => '5',
			'default_central_reorder_target' => '400',
			'business_name'                  => 'Stockland Ethiopia',
			'tagline'                        => 'Brand Apparel — Central Warehouse & Branch Retail',
			'business_calendar_v1'           => '{"version":1,"weekend_days":[],"holidays":{},"open_overrides":{}}',
		);

		foreach ( $defaults as $key => $value ) {
			$wpdb->query(
				$wpdb->prepare(
					$wpdb->prepare( 'INSERT IGNORE INTO %i (setting_key, setting_value) VALUES (%s,%s)', $p . 'stock_settings', $key, $value ),
					$key,
					$value
				)
			);
		}
	}

	/**
	 * Installs the Accounting/Procurement module (16 additional tables, default
	 * chart of accounts, walk-in customer, role capabilities) OUTSIDE the main
	 * activation request. Runs once automatically on the first wp-admin page
	 * load by a user who can manage_options after activation, and can be
	 * re-run safely at any time from Stockland ERP -> System Control ->
	 * "Install / retry accounting module". Existing table creation is bounded;
	 * compatibility upgrades use explicit idempotent ALTER operations and physical
	 * verification so the shared-host dbDelta parser is not used for that migration.
	 *
	 * If this still fails on a given host (e.g. it also times out), the core
	 * inventory/POS/ledger/workflow system installed by activate() above is
	 * completely unaffected and keeps working; only the accounting screens
	 * stay unavailable until this succeeds.

	 * @param bool $force Whether the controlled installer should force the supported accounting setup path.
	 */
	public static function maybe_install_accounting( $force = false ) {
		// Schema installation is never a public-request operation. Keep it behind
		// an authenticated management/admin context on shared hosting.
		if ( ! is_user_logged_in() || ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! current_user_can( 'stockland_install_schema' ) ) {
			return;
		}
		if ( ! empty( self::missing_core_tables() ) ) {
			return;
		}
		if ( ! class_exists( 'Stockland_Accounting' ) ) {
			return;
		}
		// Short error-only backoff. Successful/progressing requests are never throttled.
		if ( ! $force && get_transient( 'stockland_accounting_repair_backoff' ) ) {
			return;
		}
		$missing = Stockland_Accounting::missing_tables();
		if ( ! get_option( 'stockland_accounting_pending' ) && get_option( 'stockland_accounting_schema_version' ) === STOCKLAND_ACCOUNTING_SCHEMA_VERSION && empty( $missing ) ) {
			return;
		}

		$lock_key = 'stockland_accounting_install_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 90 );

		try {
			$progress = Stockland_Accounting::install( 1 );
			if ( ! empty( $progress['complete'] ) || empty( Stockland_Accounting::missing_tables() ) ) {
				delete_option( 'stockland_accounting_pending' );
			} else {
				update_option( 'stockland_accounting_pending', 1, false );
				return;
			}
			delete_option( 'stockland_accounting_pending' );
			delete_option( 'stockland_accounting_error' );
			delete_option( 'stockland_accounting_attempts' );
			delete_option( 'stockland_accounting_last_attempt' );
		} catch ( Throwable $e ) {
			$attempts = (int) get_option( 'stockland_accounting_attempts', 0 ) + 1;
			update_option( 'stockland_accounting_attempts', $attempts, false );
			update_option(
				'stockland_accounting_error',
				array(
					'message'  => $e->getMessage(),
					'time'     => current_time( 'mysql' ),
					'attempts' => $attempts,
				),
				false
			);
			set_transient( 'stockland_accounting_repair_backoff', 1, 30 );
			error_log( 'Stockland ERP accounting install error: ' . $e->getMessage() );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Applies the controlled variant-first product identity migration while preserving historical ledger records.
	 *
	 * @param wpdb $p WordPress database connection used by the protected installer.
	 * @return void Applies the idempotent product identity migration.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	private static function upgrade_variant_first_product_identity( $p ) {
		global $wpdb;
		$table = $p . 'stock_products';
		if ( ! self::table_exists_static( $table ) ) {
			return;
		}
		$pk            = $wpdb->get_results( $wpdb->prepare( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX", DB_NAME, $table ), ARRAY_A );
		$is_variant_pk = ( is_array( $pk ) && count( $pk ) === 1 && ( ( $pk[0]['COLUMN_NAME'] ?? '' ) === 'variant_id' ) );
		if ( $is_variant_pk ) {
			return;
		}
		$dup_sku = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i GROUP BY sku HAVING COUNT(*)>1 LIMIT 1', $table ) );
		if ( $dup_sku > 0 ) {
			throw new RuntimeException( 'Cannot enforce variant-first Product Master identity: duplicate SKU values exist. Resolve duplicates before continuing.' );
		}
		$dup_vid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT variant_id FROM %i GROUP BY variant_id HAVING COUNT(*)>1) x', $table ) );
		if ( $dup_vid > 0 ) {
			throw new RuntimeException( 'Cannot enforce variant-first Product Master identity: duplicate variant_id values exist.' );
		}
		$has_sku_unique = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME='sku' AND NON_UNIQUE=0", DB_NAME, $table ) );
		if ( false === $has_sku_unique ) {
			$has_sku_unique = false; }
		$sku_clause       = $has_sku_unique ? '' : ', ADD UNIQUE KEY sku (sku)';
		$wpdb->last_error = '';
		// Perform the identity transition as one DDL statement after duplicate checks.
		// MariaDB/MySQL DDL is still not transactional, but this removes avoidable
		// intermediate states between dropping the legacy key and adding the invariant key.
		$sql = $wpdb->prepare( 'ALTER TABLE %i MODIFY variant_id BIGINT UNSIGNED NOT NULL, DROP PRIMARY KEY', $table ) . $sku_clause . ', ADD PRIMARY KEY (variant_id)';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The only unbound fragment is a fixed internal index clause selected from schema metadata; the table identifier is validated by the plugin-owned Product Master schema.
		if ( false === $wpdb->query( $sql ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Could not enforce immutable variant_id primary key.' );
		}
	}

	/**
	 * v16 convergence: make every required module/schema line up under one clean production contract.
	 *
	 * @param wpdb $p WordPress database connection used by the protected installer.
	 * @throws Exception When validation, migration or transaction processing fails.
	 */
	private static function apply_schema_1600_migrations( $p ) {
		global $wpdb;
		$has_col   = function ( $table, $column ) use ( $wpdb ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s',
					DB_NAME,
					$table,
					$column
				)
			);
		};
		$has_index = function ( $table, $index ) use ( $wpdb ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME=%s',
					DB_NAME,
					$table,
					$index
				)
			);
		};

		$tx = $p . 'stock_transactions';
		if ( self::table_exists_static( $tx ) ) {
			if ( ! $has_col( $tx, 'source_line_id' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i VARCHAR(80) NOT NULL DEFAULT %s AFTER %i', $tx, 'source_line_id', '', 'reference_id' ) );
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i=%i WHERE %i=%s', $tx, 'source_line_id', 'reference_id', 'source_line_id', '' ) );
			}
			if ( $has_col( $tx, 'reference_id' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY %i VARCHAR(80) NOT NULL', $tx, 'reference_id' ) );
			}
			if ( $has_index( $tx, 'movement_reference' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $tx, 'movement_reference' ) );
			}
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (%i,%i,%i,%i)', $tx, 'movement_reference', 'reference_type', 'reference_id', 'source_line_id', 'movement_type' ) );
		}

		$ir = $p . 'stock_import_rows';
		if ( self::table_exists_static( $ir ) && ! $has_col( $ir, 'cost_basis' ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i DECIMAL(12,2) NULL AFTER %i', $ir, 'cost_basis', 'sales_total' ) );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i VARCHAR(40) NULL AFTER %i', $ir, 'cost_basis_source', 'cost_basis' ) );
		}

		$sb = $p . 'stock_sale_batches';
		if ( self::table_exists_static( $sb ) ) {
			foreach ( array(
				array( 'external_receipt_no', 'VARCHAR(120) NULL' ),
				array( 'receipt_key', 'CHAR(64) NOT NULL DEFAULT \'\'' ),
				array( 'source_system', 'VARCHAR(80) NOT NULL DEFAULT \'POS\'' ),
			) as $c ) {
				if ( ! $has_col( $sb, $c[0] ) ) {
					$wpdb->query( "ALTER TABLE {$sb} ADD COLUMN {$c[0]} {$c[1]}" );
				}
			}
			if ( $has_index( $sb, 'job' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $sb, 'job' ) );
			}
			if ( ! $has_index( $sb, 'job_receipt' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (%i,%i)', $sb, 'job_receipt', 'job_id', 'receipt_key' ) );
			}
			if ( ! $has_index( $sb, 'source_receipt' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i,%i)', $sb, 'source_receipt', 'source_system', 'external_receipt_no' ) );
			}
		}

		// Derived balance model is a rebuildable read model. For legacy SKU-keyed
		// installs, never clear the live balance table first. Build a verified staging
		// table, then perform a single cutover by RENAME TABLE; keep the old table as
		// a rollback snapshot until an explicit cleanup operation removes it.
		$bal = $p . 'stock_balances';
		if ( self::table_exists_static( $bal ) ) {
			// Legacy installations can contain stock_balances without the current
			// physical_qty/updated_at columns. The health endpoint legitimately reads
			// those columns, so the schema repair must converge them before the ERP is
			// marked ready. Preserve a legacy quantity column when one is available.
			// Migration order is intentional: legacy tables may be missing BOTH
			// variant_id and physical_qty. Never use `AFTER variant_id` until the
			// identity column has been guaranteed. This is an in-place schema repair;
			// it does not reset or reseed business data.
			if ( ! $has_col( $bal, 'variant_id' ) ) {
				$wpdb->last_error = '';
				$ok               = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i BIGINT UNSIGNED NULL AFTER %i', $bal, 'variant_id', 'sku' ) );
				if ( false === $ok || $wpdb->last_error ) {
					throw new RuntimeException( 'Could not add variant_id to stock_balances.' );
				}
			}
			if ( ! $has_col( $bal, 'physical_qty' ) ) {
				$legacy_qty = null;
				foreach ( array( 'quantity', 'qty', 'current_qty', 'stock_qty' ) as $candidate ) {
					if ( $has_col( $bal, $candidate ) ) {
						$legacy_qty = $candidate;
						break; }
				}
				$wpdb->last_error = '';
				// Do not depend on column order on legacy MariaDB tables.
				$ok = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i DECIMAL(14,2) NOT NULL DEFAULT 0', $bal, 'physical_qty' ) );
				if ( false === $ok || $wpdb->last_error ) {
					throw new RuntimeException( 'Could not add physical_qty to stock_balances.' );
				}
				if ( $legacy_qty ) {
					$wpdb->last_error = '';
					$ok               = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET physical_qty=COALESCE(%i,0)', $bal, $legacy_qty ) );
					if ( false === $ok || $wpdb->last_error ) {
						throw new RuntimeException( 'Could not preserve legacy balance quantity.' );
					}
				}
			}
			if ( ! $has_col( $bal, 'updated_at' ) ) {
				$wpdb->last_error = '';
				$ok               = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $bal, 'updated_at' ) );
				if ( false === $ok || $wpdb->last_error ) {
					throw new RuntimeException( 'Could not add updated_at to stock_balances.' );
				}
			}
			$unresolved = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i b LEFT JOIN %i p ON p.sku=b.sku WHERE b.variant_id IS NULL AND p.variant_id IS NULL', $bal, $p . 'stock_products' ) );
			if ( $unresolved > 0 ) {
				throw new RuntimeException( 'Cannot migrate stock_balances: ' . esc_html( (string) $unresolved ) . ' derived balance row(s) have no matching Product Master variant.' );
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE %i b INNER JOIN %i p ON p.sku=b.sku SET b.variant_id=p.variant_id WHERE b.variant_id IS NULL', $bal, $p . 'stock_products' ) );
			$pk_cols        = $wpdb->get_results( $wpdb->prepare( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX", DB_NAME, $bal ), ARRAY_A );
			$has_variant_pk = ( is_array( $pk_cols ) && count( $pk_cols ) === 2 && ( ( $pk_cols[0]['COLUMN_NAME'] ?? '' ) === 'location_id' ) && ( ( $pk_cols[1]['COLUMN_NAME'] ?? '' ) === 'variant_id' ) );
			if ( ! $has_variant_pk ) {
				$suffix = gmdate( 'YmdHis' );
				$stage  = $bal . '_v161_stage';
				$backup = $bal . '_v161_backup_' . $suffix;
				// A previous interrupted upgrade may have left only the disposable staging
				// table behind. Remove that stale stage before rebuilding it; the live table
				// and timestamped rollback snapshot are never touched by this cleanup.
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $stage ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Could not clear stale balance migration stage.' );
				}
				$wpdb->query(
					$wpdb->prepare( "CREATE TABLE %i (
                    location_id BIGINT UNSIGNED NOT NULL,
                    sku VARCHAR(64) NOT NULL,
                    variant_id BIGINT UNSIGNED NOT NULL,
                    physical_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY  (location_id,variant_id), KEY sku (sku), KEY location_sku (location_id,sku)
                ) ENGINE=InnoDB {$wpdb->get_charset_collate()}", $stage )
				);
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Could not create staged balance read model.' );
				}
				$wpdb->query(
					$wpdb->prepare( 'INSERT INTO %i(location_id,sku,variant_id,physical_qty,updated_at) SELECT b.location_id,MAX(p.sku),p.variant_id,SUM(b.physical_qty),MAX(b.updated_at) FROM %i b JOIN %i p ON p.variant_id=b.variant_id OR p.sku=b.sku GROUP BY b.location_id,p.variant_id', $stage, $bal, $p . 'stock_products' )
				);
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Could not populate staged balance read model.' );
				}
				$source_total = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(physical_qty),0) FROM %i', $bal ) );
				$stage_total  = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(physical_qty),0) FROM %i', $stage ) );
				if ( abs( $source_total - $stage_total ) > 0.00001 ) {
					throw new RuntimeException( 'Derived balance reconciliation failed before cutover. Live read model was preserved.' );
				}
				// Atomic metadata cutover; the old read model remains as a rollback snapshot.
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $bal, $backup, $stage, $bal ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Could not cut over staged balance read model.' );
				}
				update_option( 'stockland_balance_rollback_snapshot', $backup, false );
			}
			if ( ! $has_index( $bal, 'location_sku' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i,%i)', $bal, 'location_sku', 'location_id', 'sku' ) );
			}
		}

		self::upgrade_variant_first_product_identity( $p );
		update_option( 'stockland_core_schema_version', STOCKLAND_CORE_SCHEMA_VERSION, false );
	}
}
