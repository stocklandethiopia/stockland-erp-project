<?php
/**
 * Stockland ERP read-only integrity and foreign-key diagnostics.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-demand application integrity diagnostics. No schema change and no repair write.
 * Reports orphaned relational references so the application can fail closed or alert
 * before a damaged relationship becomes an operational transaction.
 */
final class Stockland_Integrity {
	/**
	 * Run read-only relational, ledger, and procurement consistency checks.
	 *
	 * @return array Summary with check counts, unknown checks, and FK metadata.
	 */
	public static function scan() {
		global $wpdb;
		$checks  = array();
		$unknown = array();
		$t       = static function ( $name ) use ( $wpdb ) {
			return $wpdb->prepare( '%i', Stockland_DB::table( $name ) );
		};
		$users   = $wpdb->prepare( '%i', $wpdb->prefix . 'stock_users' );

		// Every query below is SELECT-only. A SQL error is reported as UNKNOWN,
		// never silently converted to zero and never repaired automatically.
		$queries = array(
			'gl_lines_without_journal'                     => "SELECT COUNT(*) FROM {$t('gl_lines')} l LEFT JOIN {$t('gl_journals')} j ON j.id=l.journal_id WHERE j.id IS NULL",
			'gl_lines_without_account'                     => "SELECT COUNT(*) FROM {$t('gl_lines')} l LEFT JOIN {$t('gl_accounts')} a ON a.account_code=l.account_code WHERE a.account_code IS NULL",
			'gl_lines_without_supplier'                    => "SELECT COUNT(*) FROM {$t('gl_lines')} l LEFT JOIN {$t('suppliers')} s ON s.id=l.supplier_id WHERE l.supplier_id IS NOT NULL AND s.id IS NULL",
			'gl_lines_without_customer'                    => "SELECT COUNT(*) FROM {$t('gl_lines')} l LEFT JOIN {$t('customers')} c ON c.id=l.customer_id WHERE l.customer_id IS NOT NULL AND c.id IS NULL",
			'gl_lines_without_location'                    => "SELECT COUNT(*) FROM {$t('gl_lines')} l LEFT JOIN {$t('locations')} loc ON loc.id=l.location_id WHERE l.location_id IS NOT NULL AND loc.id IS NULL",
			'posted_journals_unbalanced_or_too_few_lines'  => "SELECT COUNT(*) FROM {$t('gl_journals')} j LEFT JOIN (SELECT journal_id, COUNT(*) line_count, COALESCE(SUM(debit),0) debits, COALESCE(SUM(credit),0) credits FROM {$t('gl_lines')} GROUP BY journal_id) x ON x.journal_id=j.id WHERE j.status='POSTED' AND (COALESCE(x.line_count,0)<2 OR ABS(COALESCE(x.debits,0)-COALESCE(x.credits,0))>0.01)",
			'reservations_without_product'                 => "SELECT COUNT(*) FROM {$t('reservations')} r LEFT JOIN {$t('products')} p ON p.sku=r.sku WHERE p.variant_id IS NULL",
			'reservations_without_location'                => "SELECT COUNT(*) FROM {$t('reservations')} r LEFT JOIN {$t('locations')} l ON l.id=r.location_id WHERE l.id IS NULL",
			'transfers_bad_locations'                      => "SELECT COUNT(*) FROM {$t('transfers')} tr LEFT JOIN {$t('locations')} s ON s.id=tr.from_location_id LEFT JOIN {$t('locations')} d ON d.id=tr.to_location_id WHERE s.id IS NULL OR d.id IS NULL",
			'sales_without_product'                        => "SELECT COUNT(*) FROM {$t('sales')} s LEFT JOIN {$t('products')} p ON p.sku=s.sku WHERE p.variant_id IS NULL",
			'sales_without_location'                       => "SELECT COUNT(*) FROM {$t('sales')} s LEFT JOIN {$t('locations')} l ON l.id=s.branch_location_id WHERE l.id IS NULL",
			'requisition_items_without_header'             => "SELECT COUNT(*) FROM {$t('requisition_items')} ri LEFT JOIN {$t('requisitions')} r ON r.id=ri.requisition_id WHERE r.id IS NULL",
			'count_lines_without_batch'                    => "SELECT COUNT(*) FROM {$t('count_lines')} cl LEFT JOIN {$t('count_batches')} cb ON cb.id=cl.batch_id WHERE cb.id IS NULL",
			'purchase_orders_without_supplier'             => "SELECT COUNT(*) FROM {$t('purchase_orders')} po LEFT JOIN {$t('suppliers')} s ON s.id=po.supplier_id WHERE s.id IS NULL",
			'purchase_orders_without_location'             => "SELECT COUNT(*) FROM {$t('purchase_orders')} po LEFT JOIN {$t('locations')} l ON l.id=po.location_id WHERE l.id IS NULL",
			'active_purchase_orders_not_central_warehouse' => "SELECT COUNT(*) FROM {$t('purchase_orders')} po LEFT JOIN {$t('locations')} l ON l.id=po.location_id WHERE po.status NOT IN ('DRAFT','CANCELLED','REJECTED') AND (l.id IS NULL OR LOWER(l.location_name)<>'central warehouse')",
			'purchase_orders_bad_authority_evidence'       => "SELECT COUNT(*) FROM {$t('purchase_orders')} po LEFT JOIN {$users} creator ON creator.wp_user_id=po.created_by LEFT JOIN {$users} approver ON approver.wp_user_id=po.approved_by WHERE po.status NOT IN ('DRAFT','CANCELLED','REJECTED') AND (creator.wp_user_id IS NULL OR creator.is_active<>1 OR creator.role<>'management' OR LOWER(COALESCE((SELECT l.location_name FROM {$t('locations')} l WHERE l.id=po.location_id LIMIT 1),''))<>'central warehouse' OR po.status='APPROVED' OR (po.status IN ('OWNER_APPROVED','PARTIALLY_RECEIVED','RECEIVED','CLOSED') AND (po.approved_by IS NULL OR po.approved_by=po.created_by OR po.approved_at IS NULL OR approver.wp_user_id IS NULL OR approver.is_active<>1 OR approver.role<>'owner')))",
			'purchase_order_items_without_header'          => "SELECT COUNT(*) FROM {$t('purchase_order_items')} i LEFT JOIN {$t('purchase_orders')} po ON po.id=i.po_id WHERE po.id IS NULL",
			'purchase_receipts_bad_po_supplier_or_location' => "SELECT COUNT(*) FROM {$t('purchase_receipts')} r LEFT JOIN {$t('purchase_orders')} po ON po.id=r.po_id LEFT JOIN {$t('suppliers')} s ON s.id=r.supplier_id LEFT JOIN {$t('locations')} l ON l.id=r.location_id WHERE po.id IS NULL OR s.id IS NULL OR l.id IS NULL OR po.supplier_id<>r.supplier_id OR po.location_id<>r.location_id",
			'purchase_receipt_items_bad_relations'         => "SELECT COUNT(*) FROM {$t('purchase_receipt_items')} ri LEFT JOIN {$t('purchase_receipts')} r ON r.id=ri.receipt_id LEFT JOIN {$t('purchase_order_items')} poi ON poi.id=ri.po_item_id WHERE r.id IS NULL OR poi.id IS NULL OR (r.id IS NOT NULL AND poi.id IS NOT NULL AND (r.po_id<>poi.po_id OR ri.sku<>poi.sku))",
			'purchase_order_received_qty_mismatch'         => "SELECT COUNT(*) FROM {$t('purchase_order_items')} poi LEFT JOIN (SELECT ri.po_item_id, SUM(ri.quantity_received) qty FROM {$t('purchase_receipt_items')} ri INNER JOIN {$t('purchase_receipts')} r ON r.id=ri.receipt_id WHERE r.status='POSTED' GROUP BY ri.po_item_id) sums ON sums.po_item_id=poi.id WHERE ABS(poi.quantity_received-COALESCE(sums.qty,0))>0.01",
			'purchase_order_over_received_quantity'        => "SELECT COUNT(*) FROM {$t('purchase_order_items')} poi WHERE poi.quantity_received< -0.01 OR poi.quantity_ordered<=0 OR poi.quantity_received>poi.quantity_ordered+0.01",
			'received_status_pos_with_unreceived_qty'      => "SELECT COUNT(DISTINCT po.id) FROM {$t('purchase_orders')} po INNER JOIN {$t('purchase_order_items')} poi ON poi.po_id=po.id WHERE po.status='RECEIVED' AND poi.quantity_ordered>poi.quantity_received+0.01",
			'posted_receipts_without_items'                => "SELECT COUNT(*) FROM (SELECT r.id FROM {$t('purchase_receipts')} r LEFT JOIN {$t('purchase_receipt_items')} ri ON ri.receipt_id=r.id WHERE r.status='POSTED' GROUP BY r.id HAVING COUNT(ri.receipt_id)=0) empty_receipts",
			'purchase_receipt_items_wrong_value'           => "SELECT COUNT(*) FROM {$t('purchase_receipt_items')} ri WHERE ABS(ri.line_value-ROUND(ri.quantity_received*ri.unit_cost,2))>0.01 OR ri.quantity_received<=0 OR ri.unit_cost<0",
			'purchase_receipt_items_without_inventory_move' => "SELECT COUNT(*) FROM {$t('purchase_receipt_items')} ri INNER JOIN {$t('purchase_receipts')} r ON r.id=ri.receipt_id AND r.status='POSTED' LEFT JOIN {$t('transactions')} tx ON tx.reference_type='po_receipt' AND tx.reference_id=CASE WHEN CHAR_LENGTH(CONCAT('GRN',r.id,'I',ri.po_item_id))>30 THEN CONCAT('G',r.id,'I',ri.po_item_id) ELSE CONCAT('GRN',r.id,'I',ri.po_item_id) END AND tx.movement_type='PURCHASE_RECEIPT' AND tx.location_id=r.location_id AND tx.sku=ri.sku AND ABS(tx.quantity_signed-ri.quantity_received)<0.01 WHERE tx.id IS NULL",
			'posted_receipt_journal_amount_mismatch'       => "SELECT COUNT(*) FROM (SELECT r.id,COALESCE(SUM(ri.line_value),0) subtotal,COALESCE(SUM(ROUND(ri.quantity_received*ri.unit_cost*poi.tax_rate,2)),0) input_tax FROM {$t('purchase_receipts')} r LEFT JOIN {$t('purchase_receipt_items')} ri ON ri.receipt_id=r.id LEFT JOIN {$t('purchase_order_items')} poi ON poi.id=ri.po_item_id WHERE r.status='POSTED' GROUP BY r.id) totals LEFT JOIN {$t('gl_journals')} j ON j.source_type='PURCHASE_RECEIPT' AND j.source_id=CAST(totals.id AS CHAR) AND j.status='POSTED' LEFT JOIN (SELECT journal_id,SUM(CASE WHEN account_code='1200' THEN debit ELSE 0 END) stock_debit,SUM(CASE WHEN account_code='1300' THEN debit ELSE 0 END) tax_debit,SUM(CASE WHEN account_code='2010' THEN credit ELSE 0 END) grni_credit FROM {$t('gl_lines')} GROUP BY journal_id) gl ON gl.journal_id=j.id WHERE j.id IS NULL OR ABS(COALESCE(gl.stock_debit,0)-totals.subtotal)>0.01 OR ABS(COALESCE(gl.tax_debit,0)-totals.input_tax)>0.01 OR ABS(COALESCE(gl.grni_credit,0)-(totals.subtotal+totals.input_tax))>0.01",
			'supplier_invoices_without_supplier'           => "SELECT COUNT(*) FROM {$t('supplier_invoices')} i LEFT JOIN {$t('suppliers')} s ON s.id=i.supplier_id WHERE s.id IS NULL",
			'supplier_invoices_bad_po_supplier_or_approval' => "SELECT COUNT(*) FROM {$t('supplier_invoices')} i LEFT JOIN {$t('purchase_orders')} po ON po.id=i.po_id WHERE i.po_id IS NOT NULL AND (po.id IS NULL OR po.supplier_id<>i.supplier_id OR po.location_id NOT IN (SELECT id FROM {$t('locations')} WHERE LOWER(location_name) IN ('central warehouse','central warehouse (main)')) OR po.status NOT IN ('OWNER_APPROVED','PARTIALLY_RECEIVED','RECEIVED','CLOSED') OR po.approved_by IS NULL OR po.approved_at IS NULL)",
			'closed_purchase_orders_with_uninvoiced_received_qty' => "SELECT COUNT(DISTINCT po.id) FROM {$t('purchase_orders')} po INNER JOIN {$t('purchase_order_items')} poi ON poi.po_id=po.id WHERE po.status='CLOSED' AND poi.quantity_received>COALESCE((SELECT SUM(sii.quantity) FROM {$t('supplier_invoice_items')} sii INNER JOIN {$t('supplier_invoices')} si ON si.id=sii.invoice_id WHERE sii.po_item_id=poi.id AND si.po_id=po.id AND si.status='POSTED'),0)+0.01",
			'supplier_invoice_items_without_header'        => "SELECT COUNT(*) FROM {$t('supplier_invoice_items')} ii LEFT JOIN {$t('supplier_invoices')} i ON i.id=ii.invoice_id WHERE i.id IS NULL",
			'supplier_invoice_items_bad_po_line'           => "SELECT COUNT(*) FROM {$t('supplier_invoice_items')} ii INNER JOIN {$t('supplier_invoices')} i ON i.id=ii.invoice_id LEFT JOIN {$t('purchase_order_items')} poi ON poi.id=ii.po_item_id WHERE ii.po_item_id IS NOT NULL AND (poi.id IS NULL OR i.po_id IS NULL OR poi.po_id<>i.po_id OR (ii.sku IS NOT NULL AND ii.sku<>poi.sku))",
			'supplier_invoice_line_totals_mismatch'        => "SELECT COUNT(*) FROM {$t('supplier_invoices')} i INNER JOIN (SELECT invoice_id, COALESCE(SUM(line_subtotal),0) subtotal, COALESCE(SUM(line_tax),0) tax_total, COALESCE(SUM(line_total),0) total FROM {$t('supplier_invoice_items')} GROUP BY invoice_id) x ON x.invoice_id=i.id WHERE ABS(i.subtotal-x.subtotal)>0.01 OR ABS(i.tax_amount-x.tax_total)>0.01 OR ABS(i.total_amount-x.total)>0.01",
			'supplier_payments_without_supplier'           => "SELECT COUNT(*) FROM {$t('payments')} p LEFT JOIN {$t('suppliers')} s ON s.id=p.supplier_id WHERE p.payment_type='SUPPLIER' AND (p.supplier_id IS NULL OR s.id IS NULL)",
			'customer_payments_without_customer'           => "SELECT COUNT(*) FROM {$t('payments')} p LEFT JOIN {$t('customers')} c ON c.id=p.customer_id WHERE p.payment_type='CUSTOMER' AND (p.customer_id IS NULL OR c.id IS NULL)",
			'payment_allocations_without_payment'          => "SELECT COUNT(*) FROM {$t('payment_allocations')} a LEFT JOIN {$t('payments')} p ON p.id=a.payment_id WHERE p.id IS NULL",
			'payment_allocations_bad_invoice'              => "SELECT COUNT(*) FROM {$t('payment_allocations')} a LEFT JOIN {$t('supplier_invoices')} si ON a.invoice_type='AP' AND si.id=a.invoice_id LEFT JOIN {$t('customer_invoices')} ci ON a.invoice_type='AR' AND ci.id=a.invoice_id WHERE (a.invoice_type='AP' AND si.id IS NULL) OR (a.invoice_type='AR' AND ci.id IS NULL) OR a.invoice_type NOT IN ('AP','AR')",
			'supplier_payments_without_allocations'        => "SELECT COUNT(*) FROM {$t('payments')} p LEFT JOIN {$t('payment_allocations')} a ON a.payment_id=p.id WHERE p.payment_type='SUPPLIER' AND p.status='POSTED' AND a.payment_id IS NULL",
			'supplier_payment_allocation_total_mismatch'   => "SELECT COUNT(*) FROM {$t('payments')} p LEFT JOIN (SELECT payment_id,SUM(amount) allocated FROM {$t('payment_allocations')} WHERE invoice_type='AP' GROUP BY payment_id) a ON a.payment_id=p.id WHERE p.payment_type='SUPPLIER' AND p.status='POSTED' AND ABS(p.amount-COALESCE(a.allocated,0))>0.01",
			'posted_receipts_without_journal'              => "SELECT COUNT(*) FROM {$t('purchase_receipts')} r LEFT JOIN {$t('gl_journals')} j ON j.source_type='PURCHASE_RECEIPT' AND j.source_id=CAST(r.id AS CHAR) AND j.status='POSTED' WHERE r.status='POSTED' AND j.id IS NULL",
			'posted_supplier_invoices_without_journal'     => "SELECT COUNT(*) FROM {$t('supplier_invoices')} i LEFT JOIN {$t('gl_journals')} j ON j.source_type='SUPPLIER_INVOICE' AND j.source_id=CAST(i.id AS CHAR) AND j.status='POSTED' WHERE i.status='POSTED' AND j.id IS NULL",
			'posted_supplier_payments_without_journal'     => "SELECT COUNT(*) FROM {$t('payments')} p LEFT JOIN {$t('gl_journals')} j ON j.source_type='PAYMENT' AND j.source_id=CAST(p.id AS CHAR) AND j.status='POSTED' WHERE p.payment_type='SUPPLIER' AND p.status='POSTED' AND j.id IS NULL",
			'posted_expenses_without_journal'              => "SELECT COUNT(*) FROM {$t('expenses')} e LEFT JOIN {$t('gl_journals')} j ON j.source_type='EXPENSE' AND j.source_id=CAST(e.id AS CHAR) AND j.status='POSTED' WHERE e.status='POSTED' AND j.id IS NULL",
			'pending_expenses_with_journal'                => "SELECT COUNT(*) FROM {$t('expenses')} e INNER JOIN {$t('gl_journals')} j ON j.source_type='EXPENSE' AND j.source_id=CAST(e.id AS CHAR) AND j.status='POSTED' WHERE e.status IN ('PENDING','REJECTED')",
			'posted_expense_gl_amount_mismatch'            => "SELECT COUNT(*) FROM {$t('expenses')} e LEFT JOIN {$t('gl_journals')} j ON j.source_type='EXPENSE' AND j.source_id=CAST(e.id AS CHAR) AND j.status='POSTED' LEFT JOIN (SELECT journal_id,SUM(CASE WHEN account_code='6000' THEN debit ELSE 0 END) exp_debit,SUM(CASE WHEN account_code='1300' THEN debit ELSE 0 END) tax_debit,SUM(CASE WHEN account_code IN ('1000','1010') THEN credit ELSE 0 END) cash_credit FROM {$t('gl_lines')} GROUP BY journal_id) g ON g.journal_id=j.id WHERE e.status='POSTED' AND (j.id IS NULL OR ABS(COALESCE(g.exp_debit,0)-e.amount)>0.01 OR ABS(COALESCE(g.tax_debit,0)-e.tax_amount)>0.01 OR ABS(COALESCE(g.cash_credit,0)-(e.amount+e.tax_amount))>0.01)",
			'pending_supplier_invoices_with_journal'       => "SELECT COUNT(*) FROM {$t('supplier_invoices')} i INNER JOIN {$t('gl_journals')} j ON j.source_type='SUPPLIER_INVOICE' AND j.source_id=CAST(i.id AS CHAR) AND j.status='POSTED' WHERE i.status IN ('PENDING','REJECTED')",
			'posted_supplier_invoice_grni_ap_gl_mismatch'  => "SELECT COUNT(*) FROM {$t('supplier_invoices')} i LEFT JOIN {$t('gl_journals')} j ON j.source_type='SUPPLIER_INVOICE' AND j.source_id=CAST(i.id AS CHAR) AND j.status='POSTED' LEFT JOIN (SELECT journal_id,SUM(CASE WHEN account_code='2010' THEN debit ELSE 0 END) grni_debit,SUM(CASE WHEN account_code='2000' THEN credit ELSE 0 END) ap_credit,SUM(CASE WHEN account_code='1300' THEN debit ELSE 0 END) duplicate_tax_debit FROM {$t('gl_lines')} GROUP BY journal_id) g ON g.journal_id=j.id WHERE i.status='POSTED' AND (j.id IS NULL OR ABS(COALESCE(g.grni_debit,0)-i.total_amount)>0.01 OR ABS(COALESCE(g.ap_credit,0)-i.total_amount)>0.01 OR COALESCE(g.duplicate_tax_debit,0)>0.01)",
			'pending_payments_with_journal'                => "SELECT COUNT(*) FROM {$t('payments')} p INNER JOIN {$t('gl_journals')} j ON j.source_type='PAYMENT' AND j.source_id=CAST(p.id AS CHAR) AND j.status='POSTED' WHERE p.status IN ('PENDING','REJECTED')",
			'posted_payments_without_journal'              => "SELECT COUNT(*) FROM {$t('payments')} p LEFT JOIN {$t('gl_journals')} j ON j.source_type='PAYMENT' AND j.source_id=CAST(p.id AS CHAR) AND j.status='POSTED' WHERE p.status='POSTED' AND j.id IS NULL",
			'supplier_payment_allocations_bad_party'       => "SELECT COUNT(*) FROM {$t('payment_allocations')} a INNER JOIN {$t('payments')} p ON p.id=a.payment_id LEFT JOIN {$t('supplier_invoices')} i ON a.invoice_type='AP' AND i.id=a.invoice_id WHERE a.invoice_type='AP' AND (p.payment_type<>'SUPPLIER' OR i.id IS NULL OR i.supplier_id<>p.supplier_id)",
			'customer_payment_allocations_bad_party'       => "SELECT COUNT(*) FROM {$t('payment_allocations')} a INNER JOIN {$t('payments')} p ON p.id=a.payment_id LEFT JOIN {$t('customer_invoices')} i ON a.invoice_type='AR' AND i.id=a.invoice_id WHERE a.invoice_type='AR' AND (p.payment_type<>'CUSTOMER' OR i.id IS NULL OR i.customer_id<>p.customer_id)",
			'supplier_invoice_receipt_grni_reconciliation' => "SELECT IF(ABS(COALESCE((SELECT SUM(l.credit-l.debit) FROM {$t('gl_lines')} l INNER JOIN {$t('gl_journals')} j ON j.id=l.journal_id WHERE j.status='POSTED' AND l.account_code='2010'),0) - (COALESCE((SELECT SUM(ri.line_value+ROUND(ri.quantity_received*ri.unit_cost*poi.tax_rate,2)) FROM {$t('purchase_receipt_items')} ri INNER JOIN {$t('purchase_receipts')} r ON r.id=ri.receipt_id AND r.status='POSTED' INNER JOIN {$t('purchase_order_items')} poi ON poi.id=ri.po_item_id),0) - COALESCE((SELECT SUM(i.total_amount) FROM {$t('supplier_invoices')} i WHERE i.status='POSTED' AND i.po_id IS NOT NULL),0)))>0.01,1,0)",
		);

		foreach ( $queries as $name => $sql ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Internal read-only audit templates; table identifiers use %i.
			$value = $wpdb->get_var( $sql );
			if ( '' !== (string) $wpdb->last_error || null === $value ) {
				$checks[ $name ] = null;
				$unknown[]       = $name;
			} else {
				$checks[ $name ] = (int) $value;
			}
		}

		$total               = array_sum(
			array_map(
				static function ( $value ) {
					return is_numeric( $value ) ? (int) $value : 0;
				},
				$checks
			)
		);
		$foreign_keys        = self::foreign_key_inventory( $wpdb );
		$orphan_scan_healthy = empty( $unknown ) && 0 === $total;
		$overall_status      = ! empty( $unknown ) ? 'SCAN_INCOMPLETE' : ( 0 !== $total ? 'ORPHANS_OR_INCONSISTENCIES_FOUND' : ( 'UNKNOWN' === $foreign_keys['status'] ? 'FK_METADATA_UNKNOWN' : 'FK_REVIEW_REQUIRED' ) );
		return array(
			'healthy'               => $orphan_scan_healthy,
			'orphan_scan_healthy'   => $orphan_scan_healthy,
			'overall_status'        => $overall_status,
			'total_orphan_rows'     => $total,
			'checks'                => $checks,
			'unknown_checks'        => $unknown,
			'read_only'             => true,
			'database_foreign_keys' => $foreign_keys,
			'checked_at'            => current_time( 'mysql' ),
		);
	}

	/**
	 * Read-only inventory of actual InnoDB FK metadata for this plugin's tables.
	 * This intentionally never runs ALTER TABLE: legacy ERP data must be orphan-
	 * checked and migration-tested on a staging copy before any constraints are added.
	 */
	/**
	 * Read actual InnoDB foreign-key metadata without modifying the schema.
	 *
	 * @param wpdb $wpdb WordPress database connection.
	 * @return array Foreign-key inventory status and observed metadata.
	 */
	private static function foreign_key_inventory( $wpdb ) {
		$prefix = (string) $wpdb->prefix . 'stock_';
		try {
			$wpdb->last_error = '';
			$like             = $wpdb->esc_like( $prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FK metadata must be read directly from the current database schema.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
					$like
				),
				ARRAY_A
			);
			if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
				return array(
					'status'                    => 'UNKNOWN',
					'observed_constraint_count' => null,
					'observed_fk_column_count'  => null,
					'observed_constraints'      => array(),
					'note'                      => 'Database metadata could not be read; this is not proof that constraints exist.',
				);
			}
			$items = array();
			foreach ( $rows as $row ) {
				$items[] = array(
					'table'             => (string) ( $row['TABLE_NAME'] ?? '' ),
					'column'            => (string) ( $row['COLUMN_NAME'] ?? '' ),
					'constraint'        => (string) ( $row['CONSTRAINT_NAME'] ?? '' ),
					'referenced_table'  => (string) ( $row['REFERENCED_TABLE_NAME'] ?? '' ),
					'referenced_column' => (string) ( $row['REFERENCED_COLUMN_NAME'] ?? '' ),
				);
			}
			$constraint_ids = array();
			foreach ( $items as $item ) {
				$constraint_ids[ $item['table'] . '#' . $item['constraint'] ] = true;
			}
			return array(
				'status'                    => empty( $items ) ? 'NOT_ENFORCED' : 'DECLARED',
				'observed_constraint_count' => count( $constraint_ids ),
				'observed_fk_column_count'  => count( $items ),
				'observed_constraints'      => array_slice( $items, 0, 200 ),
				'note'                      => empty( $items )
					? 'No database-level foreign-key constraints were observed for Stockland tables. Application-level orphan checks do not replace SQL constraints; perform a full orphan scan and staged migration review before adding constraints.'
					: 'Observed constraints are listed for review. The list is metadata evidence only and does not certify every logical relationship.',
				'automatic_migration'       => false,
			);
		} catch ( Throwable $e ) {
			return array(
				'status'                    => 'UNKNOWN',
				'observed_constraint_count' => null,
				'observed_fk_column_count'  => null,
				'observed_constraints'      => array(),
				'note'                      => 'Database metadata query failed; no schema changes were attempted.',
			);
		}
	}
}
