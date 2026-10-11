<?php
/**
 * Stockland ERP database reads, product resolution, inventory availability, and transaction locks.
 *
 * @package Stockland_ERP
 */

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

	/**
	 * Builds the physical table name using the active WordPress database prefix.
	 *
	 * @param string $name Logical table name from the plugin's internal table contract.
	 * @return string The fully prefixed Stockland table name.
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'stock_' . $name;
	}


	/**
	 * Reads one scalar value through the ERP's uncached SQL gateway.
	 *
	 * Only constant SQL or SQL produced through wpdb::prepare() should be passed.
	 * The custom inventory, ledger, permission and payroll tables are transactional
	 * sources of truth; WordPress object caching is not transaction-aware and must
	 * not serve potentially stale values after a stock or financial write.
	 *
	 * @param string|null $query Static or prepared SQL query.
	 * @param int        $x Zero-based result column.
	 * @param int        $y Zero-based result row.
	 * @return mixed Scalar query result or null.
	 */
	public static function read_var( $query = null, $x = 0, $y = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Central gateway accepts already-prepared or constant SQL. Preparing twice would break identifier placeholders and values.
		return $wpdb->get_var( $query, $x, $y );
	}

	/**
	 * Reads rows through the ERP's uncached SQL gateway.
	 *
	 * Only constant SQL or SQL produced through wpdb::prepare() should be passed.
	 *
	 * @param string|null $query Static or prepared SQL query.
	 * @param string      $output Output shape supported by wpdb, such as ARRAY_A.
	 * @return mixed Query rows in the requested wpdb output shape.
	 */
	public static function read_results( $query = null, $output = OBJECT ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Executes caller-prepared relational queries without a stale cache or a second prepare pass.
		return $wpdb->get_results( $query, $output );
	}

	/**
	 * Reads one row through the ERP's uncached SQL gateway.
	 *
	 * Only constant SQL or SQL produced through wpdb::prepare() should be passed.
	 *
	 * @param string|null $query Static or prepared SQL query.
	 * @param string      $output Output shape supported by wpdb, such as ARRAY_A.
	 * @param int         $y Zero-based result row.
	 * @return mixed Single result row in the requested wpdb output shape.
	 */
	public static function read_row( $query = null, $output = OBJECT, $y = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Preserves caller-prepared permission, ledger and workflow queries without a second prepare pass.
		return $wpdb->get_row( $query, $output, $y );
	}

	/**
	 * Reads one result column through the ERP's uncached SQL gateway.
	 *
	 * Only constant SQL or SQL produced through wpdb::prepare() should be passed.
	 *
	 * @param string|null $query Static or prepared SQL query.
	 * @param int         $x Zero-based result column.
	 * @return array Query values from the selected column.
	 */
	public static function read_col( $query = null, $x = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Executes caller-prepared metadata and operational queries without a second prepare pass.
		return $wpdb->get_col( $query, $x );
	}



	/**
	 * Inserts one row into an ERP or supporting application table.
	 *
	 * Values and formats are delegated unchanged to wpdb's prepared CRUD API.
	 *
	 * @param string     $table Fully prefixed table name.
	 * @param array      $data Column values keyed by column name.
	 * @param array|null $format Optional wpdb value formats.
	 * @return int|false Insert ID or false on error.
	 */
	public static function insert_record( $table, $data, $format = null ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpdb's insert() prepares values for custom ERP tables; writes must remain transaction-current.
		return $wpdb->insert( $table, $data, $format );
	}

	/**
	 * Updates rows in an ERP or supporting application table.
	 *
	 * Values and formats are delegated unchanged to wpdb's prepared CRUD API.
	 *
	 * @param string     $table Fully prefixed table name.
	 * @param array      $data Column values keyed by column name.
	 * @param array      $where Row-selection criteria keyed by column name.
	 * @param array|null $format Optional value formats.
	 * @param array|null $where_format Optional WHERE value formats.
	 * @return int|false Number of affected rows, or false on error.
	 */
	public static function update_record( $table, $data, $where, $format = null, $where_format = null ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpdb's update() prepares values for custom ERP tables; writes must remain transaction-current.
		return $wpdb->update( $table, $data, $where, $format, $where_format );
	}

	/**
	 * Deletes rows from an ERP or supporting application table.
	 *
	 * Criteria and formats are delegated unchanged to wpdb's prepared CRUD API.
	 *
	 * @param string     $table Fully prefixed table name.
	 * @param array      $where Row-selection criteria keyed by column name.
	 * @param array|null $where_format Optional WHERE value formats.
	 * @return int|false Number of affected rows, or false on error.
	 */
	public static function delete_record( $table, $where, $where_format = null ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpdb's delete() prepares values for custom ERP tables; writes must remain transaction-current.
		return $wpdb->delete( $table, $where, $where_format );
	}

	/**
	 * Replaces one row in an ERP or supporting application table.
	 *
	 * Values and formats are delegated unchanged to wpdb's prepared CRUD API.
	 *
	 * @param string     $table Fully prefixed table name.
	 * @param array      $data Column values keyed by column name.
	 * @param array|null $format Optional wpdb value formats.
	 * @return int|false Insert ID or false on error.
	 */
	public static function replace_record( $table, $data, $format = null ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpdb's replace() prepares values for custom ERP tables; writes must remain transaction-current.
		return $wpdb->replace( $table, $data, $format );
	}

	/**
	 * Executes uncached SQL for live ERP transactional data and transaction control.
	 *
	 * Queries must be constant SQL or SQL produced by wpdb::prepare(). Schema-changing
	 * statements are routed to schema_query(), which accepts only DDL targeting a
	 * table inside the plugin-owned Stockland table namespace.
	 *
	 * @param string $query Static or prepared SQL statement.
	 * @return int|bool Number of affected rows or false on query failure.
	 */
	public static function execute_query( $query ) {
		global $wpdb;
		$sql = ltrim( (string) $query );
		if ( preg_match( '/^(ALTER|CREATE|DROP|TRUNCATE|RENAME|GRANT|REVOKE)\b/i', $sql ) ) {
			return self::schema_query( $query );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Executes caller-prepared DML/transaction statements or fixed SQL; preparing again would change semantics.
		return $wpdb->query( $query );
	}

	/**
	 * Executes DDL only against tables inside the plugin-owned Stockland namespace.
	 *
	 * The source migration routines still own the column/index definition allowlists;
	 * this boundary additionally prevents accidental schema changes to WordPress core
	 * or unrelated plugin tables.
	 *
	 * @param string $query Fixed-schema DDL statement with a prefixed table identifier.
	 * @return int|bool Database result, or false when the DDL/table is outside policy.
	 */
	private static function schema_query( $query ) {
		global $wpdb;
		$sql    = ltrim( (string) $query );
		$target = '';
		$patterns = array(
			'/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
			'/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i',
			'/^DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $sql, $matches ) ) {
				$target = $matches[1];
				break;
			}
		}
		if ( '' === $target || 0 !== strpos( $target, $wpdb->prefix . 'stock_' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Central fail-closed DDL gateway for caller-prepared fixed migrations; SQL structure cannot be value-bound, and only the prefixed Stockland namespace is allowed.
		return $wpdb->query( $query );
	}

	/**
	 * Looks up the database ID for a Stockland location name.
	 *
	 * @param string $location_name Stockland location name to resolve.
	 * @return int|string|null The matching location ID, or null when no match exists.
	 */
	public static function get_location_id( $location_name ) {
		global $wpdb;
		$table = self::table( 'locations' );
		return self::read_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE location_name = %s',
				$table,
				$location_name
			)
		);
	}

	/**
	 * Reads a centrally stored ERP setting and returns the supplied default when it is absent.
	 *
	 * @param string $key Setting key or advisory-lock key.
	 * @param mixed  $fallback Value returned if the setting is absent.
	 * @return mixed The stored setting value or supplied default.
	 */
	public static function get_setting( $key, $fallback = null ) {
		global $wpdb;
		$table = self::table( 'settings' );
		$value = self::read_var(
			$wpdb->prepare(
				'SELECT setting_value FROM %i WHERE setting_key = %s',
				$table,
				$key
			)
		);
		return null !== $value ? $value : $fallback;
	}

	/** Current VAT rate, e.g. 0.15. Centrally controlled — never sent by the client. */
	public static function get_vat_rate() {
		return (float) self::get_setting( 'vat_rate', '0.15' );
	}

	/**
	 * Returns the current VAT calculation basis.
	 *
	 * @return string The current VAT basis.
	 */
	public static function get_vat_basis() {
		return self::get_setting( 'vat_basis', 'INCLUSIVE' );
	}

	/**
	 * Current Product Master row. This is the DEFAULT price a new sale must start from (rule #3).
	 *
	 * @param string $sku Exact Stockland SKU.
	 */
	public static function get_product( $sku ) {
		global $wpdb;
		$table = self::table( 'products' );
		return self::read_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE sku = %s AND is_active = 1',
				$table,
				$sku
			),
			ARRAY_A
		);
	}

	/**
	 * Checks whether a table exists in the current WordPress database schema.
	 *
	 * @param string $table_name Fully qualified table name to test.
	 * @return bool Whether the table exists.
	 */
	public static function table_exists( $table_name ) {
		global $wpdb;
		return (bool) self::read_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
				DB_NAME,
				$table_name
			)
		);
	}

	/**
	 * Resolve an external POS/ERP code to the current live SKU.
	 *
	 * @param string $source_system Source-system identifier for external product codes.
	 * @param string $external_code External POS/ERP product identifier.
	 * @param string $as_of_date Optional effective date in YYYY-MM-DD form.
	 */
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
			$mapped = self::read_var(
				$wpdb->prepare(
					'SELECT live_sku FROM %i WHERE source_system=%s AND external_code=%s AND is_active=1
                 AND (effective_from IS NULL OR effective_from<=%s)
                 AND (effective_to IS NULL OR effective_to>=%s)
                 ORDER BY effective_from DESC, id DESC LIMIT 1',
					$maps,
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

	/**
	 * Resolves an external code and returns its live SKU if a safe mapping exists.
	 *
	 * @param string $source_system Source-system identifier for external product codes.
	 * @param string $external_code External POS/ERP product identifier.
	 * @param string $as_of_date Optional effective date in YYYY-MM-DD form.
	 * @return string|null The resolved active SKU, or null.
	 */
	public static function get_product_by_external_code( $source_system, $external_code, $as_of_date = null ) {
		$sku = self::resolve_live_sku( $source_system, $external_code, $as_of_date );
		return $sku ? $sku : null;
	}

	/**
	 * Physical stock is always re-derived from immutable ledger identity.
	 *
	 * @param int    $location_id Authorized operational location ID.
	 * @param string $sku Exact Stockland SKU.
	 */
	public static function get_physical_stock( $location_id, $sku ) {
		global $wpdb;
		$tx      = self::table( 'transactions' );
		$product = self::get_product( $sku );
		if ( $product && ! empty( $product['variant_id'] ) ) {
			return (float) self::read_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(quantity_signed),0) FROM %i WHERE location_id=%d AND (variant_id=%d OR (variant_id IS NULL AND sku=%s))',
					$tx,
					(int) $location_id,
					(int) $product['variant_id'],
					$sku
				)
			);
		}
		return (float) self::read_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(quantity_signed),0) FROM %i WHERE location_id=%d AND sku=%s',
				$tx,
				(int) $location_id,
				$sku
			)
		);
	}

	/**
	 * Return true when a branch/warehouse business day is explicitly closed.
	 *
	 * @param int    $location_id Authorized operational location ID.
	 * @param string $business_date Business date in YYYY-MM-DD form.
	 */
	public static function is_day_closed( $location_id, $business_date ) {
		global $wpdb;
		$table = self::table( 'daily_closings' );
		if ( (string) self::read_var(
			$wpdb->prepare(
				'SELECT status FROM %i WHERE branch_location_id=%d AND business_date=%s LIMIT 1',
				$table,
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

	/**
	 * Acquire a MySQL named lock without requiring SUPER/TRIGGER privileges.
	 *
	 * @param string $key Setting key or advisory-lock key.
	 * @param int    $timeout Maximum seconds to wait for the advisory lock.
	 */
	public static function acquire_named_lock( $key, $timeout = 10 ) {
		global $wpdb;
		$raw = sanitize_key( (string) $key );
		if ( '' === $raw ) {
			$raw = 'generic';
		}
		// GET_LOCK names are connection-scoped strings; hash keeps them short and stable.
		$name = 'stockland_' . substr( hash( 'sha256', $raw ), 0, 48 );
		$got  = self::read_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, max( 0, (int) $timeout ) ) );
		return array(
			'ok'   => ( '1' === (string) $got ),
			'name' => $name,
			'key'  => $raw,
		);
	}

	/**
	 * Releases a previously acquired MySQL advisory lock.
	 *
	 * @param array|null $lock Lock descriptor returned by an acquire method.
	 * @return void Releases the given lock when a valid lock name is present.
	 */
	public static function release_named_lock( $lock ) {
		global $wpdb;
		if ( is_array( $lock ) && ! empty( $lock['name'] ) ) {
			self::read_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $lock['name'] ) );
		}
	}

	/**
	 * Serialize financial operations for one location/business date.
	 *
	 * @param int    $location_id Authorized operational location ID.
	 * @param string $business_date Business date in YYYY-MM-DD form.
	 * @param int    $timeout Maximum seconds to wait for the advisory lock.
	 */
	public static function acquire_day_lock( $location_id, $business_date, $timeout = 10 ) {
		return self::acquire_named_lock( 'day_' . (int) $location_id . '_' . preg_replace( '/[^0-9]/', '', (string) $business_date ), $timeout );
	}

	/**
	 * Releases the advisory lock returned by acquire_day_lock().
	 *
	 * @param array|null $lock Lock descriptor returned by an acquire method.
	 * @return void Releases the location/date lock.
	 */
	public static function release_day_lock( $lock ) {
		self::release_named_lock( $lock );
	}

	/**
	 * Acquire one or more location/date locks in deterministic order to avoid deadlocks.
	 *
	 * @param int[]  $locations Location IDs to lock in numeric order.
	 * @param string $business_date Business date in YYYY-MM-DD form.
	 * @param int    $timeout Maximum seconds to wait for the advisory lock.
	 */
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
	/**
	 * Releases each advisory lock in a collection.
	 *
	 * @param mixed $locks Value for the locks argument.
	 * @return void Releases the provided locks.
	 */
	public static function release_day_locks( $locks ) {
		foreach ( (array) $locks as $lock ) {
			self::release_day_lock( $lock );
		}
	}

	/**
	 * Compatibility cleanup for accounting/procurement transaction locks.
	 *
	 * @param mixed $locks Value for the locks argument.
	 */
	public static function release_location_locks( $locks ) {
		self::release_day_locks( $locks );
	}

	/** Resolve an exact barcode or GTIN to a stockable product variant. */
	/**
	 * Resolve an active product by immutable internal variant identity.
	 *
	 * @param int $variant_id Immutable Product Master variant ID.
	 */
	public static function get_product_by_id( $variant_id ) {
		global $wpdb;
		$variant_id = (int) $variant_id;
		if ( $variant_id <= 0 ) {
			return null;
		}
		$table = self::table( 'products' );
		return self::read_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE variant_id=%d AND is_active=1 LIMIT 1',
				$table,
				$variant_id
			),
			ARRAY_A
		);
	}

	/**
	 * Resolves an exact barcode or GTIN to an active stockable product.
	 *
	 * @param string $code Barcode or GTIN identifier.
	 * @return array|false|null The matching active stockable product row, or null when invalid or not found.
	 */
	public static function get_product_by_identifier( $code ) {
		global $wpdb;
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code ) {
			return null;
		}
		$table = self::table( 'products' );
		return self::read_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE is_active=1 AND product_type<>'STYLE' AND (barcode=%s OR gtin=%s) LIMIT 1",
				$table,
				$code,
				$code
			),
			ARRAY_A
		);
	}

	/**
	 * Returns physical stock less active reservations, preserving legacy SKU-only reservation rows.
	 *
	 * @param int    $location_id Authorized operational location ID.
	 * @param string $sku Exact Stockland SKU.
	 * @return float Available quantity after active reservations, never below zero.
	 */
	public static function get_available_stock( $location_id, $sku ) {
		global $wpdb;
		$res      = self::table( 'reservations' );
		$product  = self::get_product( $sku );
		$physical = self::get_physical_stock( $location_id, $sku );
		if ( $product && ! empty( $product['variant_id'] ) ) {
			// Preserve both current variant-linked reservations and legacy SKU-only
			// reservations. A legacy row must still reduce live availability.
			$reserved = (float) self::read_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(quantity_reserved),0) FROM %i WHERE location_id=%d AND status='Active' AND (variant_id=%d OR (variant_id IS NULL AND sku=%s))",
					$res,
					(int) $location_id,
					(int) $product['variant_id'],
					(string) $product['sku']
				)
			);
		} else {
			$reserved = (float) self::read_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(quantity_reserved),0) FROM %i WHERE location_id=%d AND sku=%s AND status='Active'",
					$res,
					(int) $location_id,
					$sku
				)
			);
		}
		return max( 0.0, $physical - $reserved );
	}
}
