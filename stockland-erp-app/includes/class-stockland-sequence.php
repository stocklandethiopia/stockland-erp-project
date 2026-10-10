<?php
/**
 * Stockland ERP document sequence service.
 *
 * @package Stockland_ERP
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic ERP document numbering without adding another business table.
 * State is kept in the protected stock_settings table. The update statement
 * takes the row lock and LAST_INSERT_ID() returns the exact number allocated
 * by this connection, avoiding a race between increment and read-back.
 */
final class Stockland_Sequence {
	const KEY_PREFIX = '__stockland_sequence__';

	/**
	 * Allocate the next document number atomically.
	 *
	 * @param string      $prefix Document-number prefix.
	 * @param string|null $date   Optional date in a format accepted by strtotime().
	 * @return string Allocated document number.
	 * @throws Exception When the prefix, date, or sequence storage is invalid.
	 */
	public static function next( $prefix, $date = null ) {
		global $wpdb;

		$prefix = strtoupper( sanitize_key( trim( (string) $prefix, '-_' ) ) );
		if ( '' === $prefix ) {
			throw new Exception( 'Invalid document sequence prefix.' );
		}

		if ( empty( $date ) ) {
			$date = current_time( 'Y-m-d' );
		}
		$timestamp = strtotime( (string) $date );
		if ( false === $timestamp ) {
			throw new Exception( 'Invalid document sequence date.' );
		}

		$year  = gmdate( 'Y', $timestamp );
		$key   = self::KEY_PREFIX . $prefix . '__' . $year;
		$table = Stockland_DB::table( 'settings' );
		$user  = (int) get_current_user_id();

		// INSERT IGNORE is safe because setting_key is the primary key. The
		// following UPDATE atomically owns the row lock and records this exact
		// connection's allocated value in MySQL's session LAST_INSERT_ID().
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (setting_key, setting_value, updated_by) VALUES (%s, %s, %d)',,
				$table,
				$key,
				'0',
				$user
			)
		);
		if ( false === $inserted ) {
			throw new Exception( 'Could not initialize the document sequence.' );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET setting_value = LAST_INSERT_ID(CAST(setting_value AS UNSIGNED) + 1), updated_by = %d WHERE setting_key = %s',,
				$table,
				$user,
				$key
			)
		);
		if ( false === $updated || 1 !== (int) $updated ) {
			throw new Exception( 'Could not advance the document sequence.' );
		}

		$next = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ( $next <= 0 ) {
			throw new Exception( 'The document sequence returned an invalid value.' );
		}

		return $prefix . '-' . $year . '-' . str_pad( (string) $next, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {}
}
