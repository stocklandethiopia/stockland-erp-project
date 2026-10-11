<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin helper around $wpdb for the wp_stock_* tables. Centralizing table
 * names and the handful of cross-table lookups (current Master Price,
 * current VAT rate, location id by name) in one place means the two
 * "never overwrite history" rules (Master Price, VAT rate) are read from
 * exactly one place and then frozen onto the sale row — see
 * Stockland_REST_Sales::create_sale().
 */
class Stockland_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'stock_' . $name;
	}

	public static function get_location_id( $location_name ) {
		global $wpdb;
		$table = self::table( 'locations' );
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE location_name = %s",
				$table,
				$location_name
			)
		);
	}

	public static function get_setting( $key, $default_value = null ) {
		global $wpdb;
		$table = self::table( 'settings' );
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT setting_value FROM {$table} WHERE setting_key = %s",
				$key
			)
		);
		return null !== $value ? $value : $default_value;
	}

	/** Current VAT rate, e.g. 0.15. Centrally controlled — never sent by the client. */
	public static function get_vat_rate() {
		return (float) self::get_setting( 'vat_rate', '0.15' );
	}

	public static function get_vat_basis() {
		return self::get_setting( 'vat_basis', 'INCLUSIVE' );
	}

	/** Current Product Master row. This is the DEFAULT price a new sale must start from (rule #3). */
	public static function get_product( $sku ) {
		global $wpdb;
		$table = self::table( 'products' );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sku = %s AND is_active = 1",
				$sku
			),
			ARRAY_A
		);
	}

	public static function table_exists( $table_name ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
				DB_NAME,
				$table_name
			)
		);
	}

	/** Resolve an external POS/ERP code to the current live SKU. */
	public static function resolve_live_sku( $source_system, $external_code, $as_of_date = null ) {
		global $wpdb;
		$source_system = sanitize_text_field( (string) $source_system );
		$external_code = sanitize_text_field( (string) $external_code );
		if ( '' === $external_code ) {
			return null;
		}

		$as_of = $as_of_date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $as_of_date )
			? sanitize_text_field( (string) $as_of_date )
			: current_time( 'Y-m-d' );

		$maps = self::table( 'product_code_maps' );
		if ( self::table_exists( $maps ) ) {
			$mapped = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT live_sku FROM {$maps} WHERE source_system=%s AND external_code=%s AND is_active=1
                 AND (effective_from IS NULL OR effective_from<=%s)
                 AND (effective_to IS NULL OR effective_to>=%s)
                 ORDER BY effective_from DESC, id DESC LIMIT 1",
					$source_system,
					$external_code,
					$as_of,
					$as_of
				)
			);
			if ( $mapped ) {
				$product = self::get_product( $mapped );
				if ( $product ) {
					return $mapped;
				}
			}
		}
		return self::get_product( $external_code ) ? $external_code : null;
	}

	public static function get_product_by_external_code( $source_system, $external_code, $as_of_date = null ) {
		$sku = self::resolve_live_sku( $source_system, $external_code, $as_of_date );
		return $sku ? $sku : null;
	}

	/** Physical stock is always re-derived from immutable ledger identity. */
	public static function get_physical_stock( $location_id, $sku ) {
		global $wpdb;
		$tx      = self::table( 'transactions' );
		$product = self::get_product( $sku );
		if ( $product && ! empty( $product['variant_id'] ) ) {
			return (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(quantity_signed),0) FROM {$tx} WHERE location_id=%d AND (variant_id=%d OR (variant_id IS NULL AND sku=%s))",
					(int) $location_id,
					(int) $product['variant_id'],
					$sku
				)
			);
		}
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(quantity_signed),0) FROM {$tx} WHERE location_id=%d AND sku=%s",
				(int) $location_id,
				$sku
			)
		);
	}

	/** Return true when a branch/warehouse business day is explicitly closed. */
	public static function is_day_closed( $location_id, $business_date ) {
		global $wpdb;
		$table = self::table( 'daily_closings' );
		if ( (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE branch_location_id=%d AND business_date=%s LIMIT 1",
				(int) $location_id,
				$business_date
			)
		) === 'CLOSED' ) {
			return true;
		}

		if ( class_exists( 'Stockland_Business_Calendar' ) ) {
			try {
				return ! Stockland_Business_Calendar::is_business_day( $business_date );
			} catch ( Throwable $e ) {
				// Invalid dates are handled by the caller; a malformed calendar must
				// never silently make a trading day appear open.
				return true;
			}
		}
		return false;
	}

	/** Acquire a MySQL named lock without requiring SUPER/TRIGGER privileges. */
	public static function acquire_named_lock( $key, $timeout = 10 ) {
		global $wpdb;
		$raw = sanitize_key( (string) $key );
		if ( '' === $raw ) {
			$raw = 'generic';
		}
		// GET_LOCK names are connection-scoped strings; hash keeps them short and stable.
		$name = 'stockland_' . substr( hash( 'sha256', $raw ), 0, 48 );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, max( 0, (int) $timeout ) ) );
		return array(
			'ok'   => ( '1' === (string) $got ),
			'name' => $name,
			'key'  => $raw,
		);
	}

	public static function release_named_lock( $lock ) {
		global $wpdb;
		if ( is_array( $lock ) && ! empty( $lock['name'] ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $lock['name'] ) );
		}
	}

	/** Serialize financial operations for one location/business date. */
	public static function acquire_day_lock( $location_id, $business_date, $timeout = 10 ) {
		return self::acquire_named_lock( 'day_' . (int) $location_id . '_' . preg_replace( '/[^0-9]/', '', (string) $business_date ), $timeout );
	}

	public static function release_day_lock( $lock ) {
		self::release_named_lock( $lock );
	}

	/** Acquire one or more location/date locks in deterministic order to avoid deadlocks. */
	public static function acquire_location_locks( $locations, $business_date, $timeout = 10 ) {
		$ids = array_values( array_unique( array_map( 'intval', (array) $locations ) ) );
		sort( $ids, SORT_NUMERIC );
		$locks = array();
		foreach ( $ids as $location_id ) {
			$lock = self::acquire_day_lock( $location_id, $business_date, $timeout );
			if ( ! $lock['ok'] ) {
				self::release_day_locks( $locks );
				return array(
					'ok'    => false,
					'locks' => array(),
				); }
			$locks[] = $lock;
		}
		return array(
			'ok'    => true,
			'locks' => $locks,
		);
	}
	public static function release_day_locks( $locks ) {
		foreach ( (array) $locks as $lock ) {
			self::release_day_lock( $lock );
		}
	}

	/** Compatibility cleanup for accounting/procurement transaction locks. */
	public static function release_location_locks( $locks ) {
		self::release_day_locks( $locks );
	}

	/** Resolve an exact barcode or GTIN to a stockable product variant. */
	/** Resolve an active product by immutable internal variant identity. */
	public static function get_product_by_id( $variant_id ) {
		global $wpdb;
		$variant_id = (int) $variant_id;
		if ( $variant_id <= 0 ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'products' ) . ' WHERE variant_id=%d AND is_active=1 LIMIT 1',
				$variant_id
			),
			ARRAY_A
		);
	}

	public static function get_product_by_identifier( $code ) {
		global $wpdb;
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code ) {
			return null;
		}
		$table = self::table( 'products' );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_active=1 AND product_type<>'STYLE' AND (barcode=%s OR gtin=%s) LIMIT 1",
				$code,
				$code
			),
			ARRAY_A
		);
	}

	public static function get_available_stock( $location_id, $sku ) {
		global $wpdb;
		$res      = self::table( 'reservations' );
		$product  = self::get_product( $sku );
		$physical = self::get_physical_stock( $location_id, $sku );
		if ( $product && ! empty( $product['variant_id'] ) ) {
			// Preserve both current variant-linked reservations and legacy SKU-only
			// reservations. A legacy row must still reduce live availability.
			$reserved = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(quantity_reserved),0) FROM {$res} WHERE location_id=%d AND status='Active' AND (variant_id=%d OR (variant_id IS NULL AND sku=%s))",
					(int) $location_id,
					(int) $product['variant_id'],
					(string) $product['sku']
				)
			);
		} else {
			$reserved = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(quantity_reserved),0) FROM {$res} WHERE location_id=%d AND sku=%s AND status='Active'",
					(int) $location_id,
					$sku
				)
			);
		}
		return max( 0.0, $physical - $reserved );
	}
}
