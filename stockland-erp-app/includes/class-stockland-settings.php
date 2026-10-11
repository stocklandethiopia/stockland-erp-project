<?php
/**
 * Settings persistence with audit and configuration validation.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ONLY code path allowed to change centrally-controlled settings
 * (VAT rate above all — section 4: "Do NOT allow branch employees to
 * edit the company's VAT/tax rate"). Every REST route that touches
 * settings must require the stockland_manage_settings capability,
 * which only the Management role has (see Stockland_Roles).
 */
class Stockland_Settings {

	/**
	 * Update a centrally controlled setting after permission and audit checks.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value New setting value.
	 * @param int    $wp_user_id User performing the change.
	 * @return true|WP_Error True when committed, or a structured failure.
	 * @throws RuntimeException When persistence or audit invariants fail.
	 */
	public static function update( $key, $value, $wp_user_id ) {
		if ( ! user_can( $wp_user_id, 'stockland_manage_settings' ) ) {
			return new WP_Error( 'forbidden', 'Only Management may change centrally-controlled settings.', array( 'status' => 403 ) );
		}

		global $wpdb;
		$table = Stockland_DB::table( 'settings' );

		$old_value = Stockland_DB::get_setting( $key );

		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start the settings transaction.', array( 'status' => 500 ) );
		}

		try {
			$ok = Stockland_DB::replace_record(
				$table,
				array(
					'setting_key'   => $key,
					'setting_value' => $value,
					'updated_by'    => $wp_user_id,
				)
			);
			if ( false === $ok ) {
				throw new RuntimeException( $wpdb->last_error ? $wpdb->last_error : 'Could not save the setting.' );
			}
			if ( ! Stockland_Audit::log( $wp_user_id, null, 'SETTING_CHANGE', null, null, $old_value, $value, 'Management setting update', $key ) ) {
				throw new RuntimeException( 'Audit event could not be recorded; the setting was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Settings commit failed.' );
			}
			return true;
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'settings_update_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
