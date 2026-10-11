<?php
/**
 * Safe deactivation lifecycle for Stockland ERP.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation intentionally does NOT drop any wp_stock_* tables or data.
 * Per section 15 ("Never destructively delete financial or inventory
 * transactions"), only an explicit, separate uninstall action taken by
 * Management should ever remove data, and even then it should be an
 * export-first, confirmed operation — not a side effect of deactivating
 * the plugin.
 */
class Stockland_Deactivator {
	/**
	 * Flushes rewrite rules without deleting ERP data or business tables.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally empty. Flush rewrite rules only, in case any were
		// registered for the PWA app-shell route.
		flush_rewrite_rules();
	}
}
