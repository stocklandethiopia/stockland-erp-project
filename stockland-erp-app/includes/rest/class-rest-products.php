<?php
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fashion Product Master.
 *
 * Model: STYLE/PRODUCT -> VARIANT SKU -> COLOR + SIZE + BARCODE/GTIN.
 * Each stockable color/size combination is its own SKU row so the existing
 * ledger remains compatible while inventory becomes dimension-aware.
 */
class Stockland_REST_Products extends Stockland_REST_Base {
	/**
	 * Registers Product Master route callbacks.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) ); }

	/**
	 * Registers Product Master read, create, update and SKU-replacement routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/products/variants/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_variants' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_manage_products' );},
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_products' ),
					'permission_callback' => function () {
															return current_user_can( 'stockland_manage_products' );},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_product' ),
					'permission_callback' => function () {
										return current_user_can( 'stockland_manage_products' );},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/products/(?P<sku>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product' ),
					'permission_callback' => function () {
																	return current_user_can( 'stockland_manage_products' );},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_product' ),
					'permission_callback' => function () {
										return current_user_can( 'stockland_manage_products' );},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/products/(?P<variant_id>\d+)/replace-sku',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'replace_sku' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_manage_products' ) && ( Stockland_Authority::is_owner() || Stockland_Authority::is_management() );},
			)
		);
	}

	/**
	 * Sanitizes a scalar Product Master field as text.
	 *
	 * @param mixed $v Input value.
	 * @return string Sanitized text.
	 */
	private function clean( $v ) {
		return sanitize_text_field( (string) $v ); }
	/**
	 * Validates the ERP stock-keeping-unit format.
	 *
	 * @param string $sku Candidate SKU.
	 * @return bool Whether the SKU matches the permitted format.
	 */
	private function valid_sku( $sku ) {
		return (bool) preg_match( '/^STK-[A-Za-z0-9_-]{1,60}$/', (string) $sku ); }
	/** Keep audit columns inside the existing 255-byte varchar contract without changing the schema.
	 * The full source value remains represented by the event hash payload in Stockland_Audit.
	 */
	private function audit_value( $value ) {
		if ( null === $value ) {
			return null;
		}
		$text = is_string( $value ) ? $value : wp_json_encode( $value );
		$text = (string) $text;
		if ( strlen( $text ) <= 240 ) {
			return $text;
		}
		return 'sha256:' . hash( 'sha256', $text ) . ' len:' . strlen( $text );
	}
	/**
	 * Extracts and sanitizes Product Master variant dimensions from a request payload.
	 *
	 * @param array $p Product Master request payload.
	 * @return array Sanitized dimension and identifier fields.
	 */
	private function dimension_data( $p ) {
		return array(
			'parent_sku'        => $this->clean( $p['parent_sku'] ?? '' ),
			'style_code'        => $this->clean( $p['style_code'] ?? '' ),
			'brand'             => $this->clean( $p['brand'] ?? '' ),
			'department'        => $this->clean( $p['department'] ?? '' ),
			'sub_category'      => $this->clean( $p['sub_category'] ?? '' ),
			'collection_name'   => $this->clean( $p['collection_name'] ?? '' ),
			'season'            => $this->clean( $p['season'] ?? '' ),
			'material'          => $this->clean( $p['material'] ?? '' ),
			'fit'               => $this->clean( $p['fit'] ?? '' ),
			'country_of_origin' => $this->clean( $p['country_of_origin'] ?? '' ),
			'image_url'         => esc_url_raw( $p['image_url'] ?? '' ),
			'barcode'           => $this->clean( $p['barcode'] ?? '' ),
			'gtin'              => $this->clean( $p['gtin'] ?? '' ),
			'color'             => $this->clean( $p['color'] ?? '' ),
			'color_code'        => $this->clean( $p['color_code'] ?? '' ),
			'size'              => $this->clean( $p['size'] ?? '' ),
			'size_code'         => $this->clean( $p['size_code'] ?? '' ),
			'size_system'       => $this->clean( $p['size_system'] ?? '' ),
		);
	}
	/**
	 * Checks that a barcode/GTIN identifier is not assigned to another SKU.
	 *
	 * @param string $field Allowed identifier column.
	 * @param mixed  $value Identifier value.
	 * @param string $sku Current SKU to exclude during updates.
	 * @return WP_Error|null Duplicate-identifier error, or null when unique.
	 */
	private function check_identifier_unique( $field, $value, $sku = '' ) {
		if ( ! $value ) {
			return null;
		}
		global $wpdb;
		$table = Stockland_DB::table( 'products' );
		$hit   = Stockland_DB::read_var(
			$wpdb->prepare(
				'SELECT sku FROM %i WHERE %i = %s AND sku <> %s LIMIT 1',
				$table,
				$field,
				$value,
				$sku
			)
		);
		return $hit ? new WP_Error( 'duplicate_identifier', ucfirst( $field ) . ' is already assigned to SKU ' . $hit . '. Each stockable variant must have a unique identifier.', array( 'status' => 409 ) ) : null;
	}

	/**
	 * Lists Product Master records visible to the authorized current user.
	 *
	 * @param WP_REST_Request $request REST request with search and list filters.
	 * @return mixed REST response containing products or a WP_Error.
	 */
	public function list_products( WP_REST_Request $request ) {
		global $wpdb;
		try {
			$table = Stockland_DB::table( 'products' );
			if ( ! Stockland_DB::table_exists( $table ) ) {
				return new WP_Error( 'product_master_unavailable', 'Product Master table is not available. Complete the verified 49-table ERP setup first.', array( 'status' => 503 ) );
			}
			$can              = current_user_can( 'stockland_view_cost_and_profit' );
			$manage           = current_user_can( 'stockland_manage_products' );
			$include_inactive = $manage && (string) $request->get_param( 'include_inactive' ) === '1';

			// Product Master is the catalog authority. Do not make inventory status
			// or the transactional bootstrap list the source of catalog search.
			// STYLE rows are intentionally included here because they are valid
			// Product Master identities even though they are not stockable variants.
			// Inactive rows are visible only to users who manage Product Master, so
			// an authorized catalog administrator can find and repair an old SKU
			// without exposing inactive products to ordinary operational users.
			$q = trim( (string) $request->get_param( 'q' ) );
			if ( '' === $q ) {
				// In-place compatibility: accept both the newer q contract and the legacy search contract.
				$q = trim( (string) $request->get_param( 'search' ) );
			}

			$like            = '%' . $wpdb->esc_like( $q ) . '%';
			$requested_limit = $request->get_param( 'limit' );
			$limit           = min( 500, max( 1, (int) ( $requested_limit ? $requested_limit : 500 ) ) );

			/*
			 * Keep this SQL template static so every data value is bound in SQL
			 * placeholder order. The nine LIKE values precede the exact-SKU sort
			 * values; prepending the exact-SKU value breaks search parameter binding.
			 */
			$rows = Stockland_DB::read_results(
				$wpdb->prepare(
					'SELECT variant_id,sku,product_name,product_type,parent_sku,style_code,brand,department,sub_category,collection_name,season,material,fit,country_of_origin,image_url,barcode,gtin,color,color_code,size,size_code,size_system,category,gender,age_group,unit,CASE WHEN %d = 1 THEN purchase_cost ELSE NULL END AS purchase_cost,retail_price,wholesale_price,notes,branch_reorder_level,central_reorder_level,is_active
					FROM %i
					WHERE (%d = 1 OR is_active = 1)
						AND (%s = \'\' OR (sku LIKE %s OR product_name LIKE %s OR parent_sku LIKE %s OR style_code LIKE %s OR brand LIKE %s OR color LIKE %s OR size LIKE %s OR barcode LIKE %s OR gtin LIKE %s))
					ORDER BY CASE WHEN %s <> \'\' AND sku = %s THEN 0 ELSE 1 END, COALESCE(parent_sku,sku), color, size, sku
					LIMIT %d',
					array_merge(
						array(
							$can ? 1 : 0,
							$table,
							$include_inactive ? 1 : 0,
							$q,
						),
						array_fill( 0, 9, $like ),
						array(
							$q,
							$q,
							$limit,
						)
					)
				),
				ARRAY_A
			);
			if ( $wpdb->last_error ) {
				error_log( 'Stockland Product Master search DB error: ' . $wpdb->last_error );
				return new WP_Error( 'product_master_query_failed', 'Product Master search could not be completed. No ERP data was changed.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( is_array( $rows ) ? $rows : array() );
		} catch ( Throwable $e ) {
			error_log( 'Stockland Product Master search runtime error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			return new WP_Error( 'product_master_query_failed', 'Product Master search encountered a server error. No ERP data was changed.', array( 'status' => 500 ) );
		}
	}

	/**
	 * Returns the authoritative Product Master row for a SKU.
	 *
	 * @param WP_REST_Request $request REST request containing the SKU.
	 * @return mixed REST response containing the product or a WP_Error.
	 */
	public function get_product( WP_REST_Request $request ) {
		global $wpdb;
		$sku     = sanitize_text_field( (string) $request['sku'] );
		$manage  = current_user_can( 'stockland_manage_products' );
		$table   = Stockland_DB::table( 'products' );
		$product = $manage && (string) $request->get_param( 'include_inactive' ) === '1'
			? Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE sku=%s LIMIT 1', $table, $sku ), ARRAY_A )
			: Stockland_DB::get_product( $sku );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'SKU not found in Product Master.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'stockland_view_cost_and_profit' ) ) {
			unset( $product['purchase_cost'] );
		}
		return rest_ensure_response( $product );
	}

	/**
	 * Creates a Product Master record without posting opening inventory.
	 *
	 * @param WP_REST_Request $request REST request containing product fields.
	 * @return mixed REST response containing the saved product or a WP_Error.
	 * @throws Exception When a validation or persistence invariant fails.
	 */
	public function create_product( WP_REST_Request $request ) {
		global $wpdb;
		$table = Stockland_DB::table( 'products' );
		$p     = $request->get_json_params();
		if ( empty( $p['sku'] ) || empty( $p['product_name'] ) ) {
			return new WP_Error( 'missing_field', 'SKU and product_name are required.', array( 'status' => 400 ) );
		}
		$sku = $this->clean( $p['sku'] );
		if ( ! $this->valid_sku( $sku ) ) {
			return new WP_Error( 'invalid_sku', 'Stockland SKU must begin with STK- and use letters, numbers, hyphens or underscores (max 64 characters).', array( 'status' => 400 ) );
		}
		$type = strtoupper( $this->clean( $p['product_type'] ?? 'SIMPLE' ) );
		if ( ! in_array( $type, array( 'STYLE', 'VARIANT', 'SIMPLE' ), true ) ) {
			return new WP_Error( 'invalid_product_type', 'product_type must be STYLE, VARIANT or SIMPLE.', array( 'status' => 400 ) );
		}
		if ( 'VARIANT' === $type && empty( $p['parent_sku'] ) ) {
			return new WP_Error( 'missing_parent', 'A VARIANT must have a parent_sku (style).', array( 'status' => 400 ) );
		} if ( 'VARIANT' === $type ) {
			$parent = Stockland_DB::get_product( $this->clean( $p['parent_sku'] ) );
			if ( ! $parent || ( $parent['product_type'] ?? '' ) !== 'STYLE' ) {
				return new WP_Error( 'invalid_parent', 'parent_sku must reference an existing STYLE product.', array( 'status' => 400 ) );
			} if ( $this->clean( $p['color'] ) === '' || $this->clean( $p['size'] ) === '' ) {
				return new WP_Error( 'variant_dimensions_required', 'A stockable VARIANT requires both color and size.', array( 'status' => 400 ) );
			}
		}
		foreach ( array( 'barcode', 'gtin' ) as $field ) {
			$e = $this->check_identifier_unique( $field, $this->clean( $p[ $field ] ?? '' ) );
			if ( is_wp_error( $e ) ) {
				return $e;
			}
		}
		$new = array_merge(
			array(
				'sku'                   => $sku,
				'product_name'          => $this->clean( $p['product_name'] ),
				'category'              => $this->clean( $p['category'] ?? '' ),
				'gender'                => $this->clean( $p['gender'] ?? '' ),
				'age_group'             => $this->clean( $p['age_group'] ?? '' ),
				'unit'                  => $this->clean( $p['unit'] ?? 'PCS' ),
				'purchase_cost'         => null,
				'retail_price'          => null,
				'wholesale_price'       => null,
				'notes'                 => $this->clean( $p['notes'] ?? '' ),
				'branch_reorder_level'  => (int) ( $p['branch_reorder_level'] ?? Stockland_DB::get_setting( 'default_branch_reorder_target', 2 ) ),
				'central_reorder_level' => (int) ( $p['central_reorder_level'] ?? Stockland_DB::get_setting( 'default_central_reorder_target', 200 ) ),
				'is_active'             => 1,
			),
			$this->dimension_data( $p )
		);
		try {
			foreach ( array( 'purchase_cost', 'retail_price', 'wholesale_price' ) as $field ) {
				if ( isset( $p[ $field ] ) && '' !== $p[ $field ] ) {
					$new[ $field ] = Stockland_Money::decimal( Stockland_Money::minor( $p[ $field ] ) );
				}
			}
		} catch ( Throwable $e ) {
			return new WP_Error( 'invalid_price', 'Prices and costs must be valid decimal amounts.', array( 'status' => 400 ) );
		}
		$new['product_type'] = $type;
		foreach ( array( 'purchase_cost', 'retail_price', 'wholesale_price' ) as $field ) {
			if ( null !== $new[ $field ] && Stockland_Money::minor( $new[ $field ] ) < 0 ) {
				return new WP_Error( 'invalid_price', 'Prices and costs cannot be negative.', array( 'status' => 400 ) );
			}
		}
		if ( empty( $new['parent_sku'] ) ) {
			$new['parent_sku'] = 'SIMPLE' === $type ? $sku : $new['parent_sku'];
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start Product Master create transaction.', array( 'status' => 500 ) );
		}
		try {
			$ok = Stockland_DB::insert_record( $table, $new );
			if ( false === $ok ) {
				throw new Exception( 'Could not create product. SKU may already exist.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'PRODUCT_CREATE', $sku, null, null, $this->audit_value( $new ), 'Product Master product created', 'PRODUCT|' . $sku ) ) {
				throw new Exception( 'Audit event could not be recorded; product was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Product create commit failed.' );
			}
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'insert_failed', $e->getMessage(), array( 'status' => 409 ) );}
		$saved = Stockland_DB::get_product( $sku );
		if ( ! $saved ) {
			return new WP_Error( 'insert_state_unverified', 'Product was committed but could not be re-read from Product Master.', array( 'status' => 500 ) );
		}
		try {
			Stockland_Notifications::notify_product_master( 'CREATED', $saved );} catch ( Throwable $ignore ) {
				// Notification delivery is best-effort after the database transaction commits.
				do_action( 'stockland_erp_notification_delivery_failed', 'product_master_created' );
			}
			return rest_ensure_response( $saved );
	}

	/**
	 * Creates a color-by-size matrix from a Style template without duplicate combinations.
	 *
	 * @param WP_REST_Request $request REST request containing parent SKU, colors and sizes.
	 * @return mixed Created variant matrix or WP_Error.
	 * @throws Exception When validation or transaction processing fails.
	 */
	public function generate_variants( WP_REST_Request $request ) {
		global $wpdb;
		$p      = $request->get_json_params();
		$parent = $this->clean( $p['parent_sku'] ?? '' );
		if ( ! $parent ) {
			return new WP_Error( 'missing_parent', 'parent_sku is required.', array( 'status' => 400 ) );
		}
		$style = Stockland_DB::get_product( $parent );
		if ( ! $style ) {
			return new WP_Error( 'parent_not_found', 'Parent/style SKU not found.', array( 'status' => 404 ) );
		}
		if ( strtoupper( (string) ( $style['product_type'] ?? '' ) ) !== 'STYLE' ) {
			return new WP_Error( 'invalid_parent_type', 'Variant matrices can only be generated from a STYLE Product Master record.', array( 'status' => 400 ) );
		}
		$colors = is_array( $p['colors'] ?? null ) ? $p['colors'] : array();
		$sizes  = is_array( $p['sizes'] ?? null ) ? $p['sizes'] : array();
		if ( ! $colors || ! $sizes ) {
			return new WP_Error( 'matrix_required', 'Provide at least one color and one size.', array( 'status' => 400 ) );
		}
		$prefix = $this->clean( $p['sku_prefix'] ?? ( $style['style_code'] ? $style['style_code'] : $parent ) );
		$prefix = strtoupper( trim( preg_replace( '/-+/', '-', preg_replace( '/[^A-Za-z0-9_-]+/', '-', $prefix ) ), '-_' ) );
		if ( '' === $prefix ) {
			$prefix = strtoupper( $parent );
		}
		$created = array();
		$skipped = array();
		$used    = array();
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start variant matrix transaction.', array( 'status' => 500 ) );
		}
		try {
			foreach ( $colors as $c ) {
				$c     = is_array( $c ) ? $c : array(
					'name' => $c,
					'code' => '',
				);
				$cname = $this->clean( $c['name'] ?? '' );
				$ccode = $this->clean( $c['code'] ?? '' );
				if ( ! $cname ) {
					continue;
				}
				foreach ( $sizes as $z ) {
					$z     = is_array( $z ) ? $z : array(
						'name' => $z,
						'code' => $z,
					);
					$zname = $this->clean( $z['name'] ?? '' );
					$zcode = $this->clean( $z['code'] ?? $zname );
					if ( ! $zname ) {
						continue;
					}
					$safe_c = trim( preg_replace( '/-+/', '-', preg_replace( '/[^A-Za-z0-9]+/', '-', strtoupper( $ccode ? $ccode : $cname ) ) ), '-' );
					$safe_s = trim( preg_replace( '/-+/', '-', preg_replace( '/[^A-Za-z0-9]+/', '-', strtoupper( $zcode ) ) ), '-' );
					if ( '' === $safe_c || '' === $safe_s ) {
						continue;
					}
					$base = $prefix;
					if ( stripos( $base, 'STK-' ) !== 0 ) {
						$base = 'STK-' . $base;
					}
					$base = strtoupper( trim( preg_replace( '/[^A-Za-z0-9_-]+/', '-', preg_replace( '/-+/', '-', substr( $base, 0, 24 ) ) ), '-_' ) );
					if ( stripos( $base, 'STK-' ) !== 0 ) {
						$base = 'STK-' . ltrim( $base, '-_' );
					}
					$sku = substr( $base, 0, 24 ) . '-' . $safe_c . '-' . $safe_s;
					if ( strlen( $sku ) > 64 ) {
						$sku = substr( $sku, 0, 64 );
					}
					if ( isset( $used[ $sku ] ) ) {
						continue;
					} $used[ $sku ] = 1;
					$existing       = Stockland_DB::read_var( $wpdb->prepare( 'SELECT sku FROM %i WHERE sku=%s FOR UPDATE', Stockland_DB::table( 'products' ), $sku ) );
					if ( $existing ) {
						$skipped[] = $sku;
						continue;}
					$row = array(
						'sku'                   => $sku,
						'product_name'          => $style['product_name'],
						'product_type'          => 'VARIANT',
						'parent_sku'            => $parent,
						'style_code'            => $style['style_code'] ? $style['style_code'] : $parent,
						'brand'                 => $style['brand'] ? $style['brand'] : '',
						'department'            => $style['department'] ? $style['department'] : '',
						'sub_category'          => $style['sub_category'] ? $style['sub_category'] : '',
						'collection_name'       => $style['collection_name'] ? $style['collection_name'] : '',
						'season'                => $style['season'] ? $style['season'] : '',
						'material'              => $style['material'] ? $style['material'] : '',
						'fit'                   => $style['fit'] ? $style['fit'] : '',
						'country_of_origin'     => $style['country_of_origin'] ? $style['country_of_origin'] : '',
						'image_url'             => $style['image_url'] ? $style['image_url'] : '',
						'color'                 => $cname,
						'color_code'            => $ccode,
						'size'                  => $zname,
						'size_code'             => $zcode,
						'size_system'           => $p['size_system'] ?? ( $style['size_system'] ? $style['size_system'] : '' ),
						'category'              => $style['category'] ? $style['category'] : '',
						'gender'                => $style['gender'] ? $style['gender'] : '',
						'age_group'             => $style['age_group'] ? $style['age_group'] : '',
						'unit'                  => $style['unit'] ? $style['unit'] : 'PCS',
						'purchase_cost'         => $style['purchase_cost'],
						'retail_price'          => $style['retail_price'],
						'wholesale_price'       => $style['wholesale_price'],
						'notes'                 => $style['notes'] ? $style['notes'] : '',
						'branch_reorder_level'  => $style['branch_reorder_level'],
						'central_reorder_level' => $style['central_reorder_level'],
						'is_active'             => 1,
					);
					if ( false === Stockland_DB::insert_record( Stockland_DB::table( 'products' ), $row ) ) {
						throw new Exception( 'Could not create variant ' . $sku . '.' );
					}
					$created[] = $sku;
				}
			}
			if ( ! Stockland_Audit::log(
				get_current_user_id(),
				null,
				'VARIANT_MATRIX_GENERATE',
				$parent,
				count( $created ),
				null,
				$this->audit_value(
					array(
						'created' => $created,
						'skipped' => $skipped,
					)
				),
				'Product variant matrix generated',
				'VARIANT_MATRIX|' . $parent
			) ) {
				throw new Exception( 'Audit event could not be recorded; variant matrix was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Variant matrix commit failed.' );
			}
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'variant_create_failed', $e->getMessage(), array( 'status' => 409 ) );}
		if ( $created ) {
			try {
				Stockland_Notifications::notify_product_master(
					'VARIANT_MATRIX',
					array(
						'sku'           => $parent,
						'product_name'  => $style['product_name'] ?? $parent,
						'created_count' => count( $created ),
						'created_skus'  => $created,
					)
				);} catch ( Throwable $ignore ) {
				// Notification delivery is best-effort and must not undo committed variant data.
				do_action( 'stockland_erp_notification_delivery_failed', 'product_variants_created' );
				}
		}
		return rest_ensure_response(
			array(
				'parent_sku' => $parent,
				'created'    => $created,
				'skipped'    => $skipped,
				'count'      => count( $created ),
			)
		);
	}

	/**
	 * Updates a Product Master record while preserving immutable variant identity.
	 *
	 * @param WP_REST_Request $request REST request containing the SKU and changed fields.
	 * @return mixed REST response containing the updated product or a WP_Error.
	 * @throws Exception When validation, audit logging or persistence fails.
	 */
	public function update_product( WP_REST_Request $request ) {
		global $wpdb;
		$table = Stockland_DB::table( 'products' );
		$sku   = $request['sku'];
		$p     = $request->get_json_params();
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start Product Master update transaction.', array( 'status' => 500 ) );
		}
		try {
			$current = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE sku=%s FOR UPDATE', $table, $sku ), ARRAY_A );
			if ( ! $current ) {
				Stockland_DB::execute_query( 'ROLLBACK' );
				return new WP_Error( 'not_found', 'SKU not found.', array( 'status' => 404 ) );}
			foreach ( array( 'barcode', 'gtin' ) as $field ) {
				if ( isset( $p[ $field ] ) ) {
					$e = $this->check_identifier_unique( $field, $this->clean( $p[ $field ] ), $sku );
					if ( is_wp_error( $e ) ) {
						throw new Exception( $e->get_error_message() );}
				}
			}
			$next_type = strtoupper( $this->clean( $p['product_type'] ?? ( $current['product_type'] ?? 'SIMPLE' ) ) );
			if ( ! in_array( $next_type, array( 'STYLE', 'VARIANT', 'SIMPLE' ), true ) ) {
				throw new Exception( 'product_type must be STYLE, VARIANT or SIMPLE.' );
			}
			if ( 'VARIANT' === $next_type ) {
				$next_parent = $this->clean( $p['parent_sku'] ?? ( $current['parent_sku'] ?? '' ) );
				$next_color  = $this->clean( $p['color'] ?? ( $current['color'] ?? '' ) );
				$next_size   = $this->clean( $p['size'] ?? ( $current['size'] ?? '' ) );
				if ( '' === $next_parent ) {
					throw new Exception( 'A VARIANT must have a parent_sku (style).' );
				}
				$parent = Stockland_DB::get_product( $next_parent );
				if ( ! $parent || ( $parent['product_type'] ?? '' ) !== 'STYLE' ) {
					throw new Exception( 'parent_sku must reference an existing STYLE product.' );
				}
				if ( '' === $next_color || '' === $next_size ) {
					throw new Exception( 'A stockable VARIANT requires both color and size.' );
				}
			}
			$history = Stockland_DB::table( 'price_history' );
			$uid     = get_current_user_id();
			$update  = array();
			foreach ( array( 'product_name', 'category', 'gender', 'age_group', 'unit', 'notes', 'branch_reorder_level', 'central_reorder_level', 'is_active', 'product_type', 'parent_sku', 'style_code', 'brand', 'department', 'sub_category', 'collection_name', 'season', 'material', 'fit', 'country_of_origin', 'image_url', 'barcode', 'gtin', 'color', 'color_code', 'size', 'size_code', 'size_system' ) as $field ) {
				if ( isset( $p[ $field ] ) ) {
					if ( 'image_url' === $field ) {
						$update[ $field ] = esc_url_raw( $p[ $field ] );
					} elseif ( in_array( $field, array( 'branch_reorder_level', 'central_reorder_level' ), true ) ) {
						$update[ $field ] = (int) $p[ $field ];
					} elseif ( 'product_type' === $field ) {
						$update[ $field ] = $next_type;
					} else {
						$update[ $field ] = $this->clean( $p[ $field ] );
					}
				}
			}
			$price_changes = array();
			foreach ( array( 'purchase_cost', 'retail_price', 'wholesale_price' ) as $field ) {
				if ( isset( $p[ $field ] ) ) {
					$new_value = Stockland_Money::decimal( Stockland_Money::minor( $p[ $field ] ) );
					if ( Stockland_Money::minor( $new_value ) < 0 ) {
						throw new Exception( 'Prices and costs cannot be negative.' );
					}
					if ( Stockland_Money::minor( $new_value ) !== Stockland_Money::minor( $current[ $field ] ) ) {
						$price_changes[]  = array(
							'price_type' => $field,
							'old_value'  => $current[ $field ],
							'new_value'  => $new_value,
						);
						$update[ $field ] = $new_value;}
				}
			}
			if ( empty( $update ) ) {
				if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
					throw new Exception( 'No-change Product Master verification commit failed.' );
				} return rest_ensure_response( Stockland_DB::get_product( $sku ) ); }
			if ( false === Stockland_DB::update_record( $table, $update, array( 'sku' => $sku ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Product Master update failed.' );
			}
			foreach ( $price_changes as $change ) {
				if ( false === Stockland_DB::insert_record(
					$history,
					array(
						'variant_id' => (int) $current['variant_id'],
						'sku'        => $sku,
						'price_type' => $change['price_type'],
						'old_value'  => $change['old_value'],
						'new_value'  => $change['new_value'],
						'changed_by' => $uid,
					)
				) ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Price history could not be recorded.' );
				}
			}
			if ( ! Stockland_Audit::log( $uid, null, 'PRODUCT_UPDATE', $sku, null, $this->audit_value( $current ), $this->audit_value( $update ), 'Product Master product updated', 'PRODUCT|' . $sku ) ) {
				throw new Exception( 'Audit event could not be recorded; product update was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Product update commit failed.' );
			}
			$saved = Stockland_DB::get_product( $sku );
			if ( ! $saved ) {
				throw new Exception( 'Product update committed but the saved record could not be re-read.' );
			}
			try {
				Stockland_Notifications::notify_product_master( 'UPDATED', $saved );} catch ( Throwable $ignore ) {
				// Notification delivery is best-effort after the product update commits.
				do_action( 'stockland_erp_notification_delivery_failed', 'product_master_updated' );
				}
				return rest_ensure_response( $saved );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'product_update_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Replaces an SKU only after verifying there are no open operational references.
	 *
	 * @param WP_REST_Request $request REST request containing variant ID and new SKU.
	 * @return mixed REST response containing the replacement result or a WP_Error.
	 * @throws Exception When an operational reference or persistence check fails.
	 */
	public function replace_sku( WP_REST_Request $request ) {
		$p          = $request->get_json_params();
		$variant_id = (int) $request['variant_id'];
		$new_sku    = $this->clean( $p['new_sku'] ?? '' );
		if ( ! $this->valid_sku( $new_sku ) ) {
			return new WP_Error( 'invalid_sku', 'Stockland SKU must begin with STK- and use letters, numbers, hyphens or underscores (max 64 characters).', array( 'status' => 400 ) );
		}
		try {
			$row = Stockland_Product_Service::replace_sku( $variant_id, $new_sku, $this->clean( $p['reason'] ?? '' ), $this->clean( $p['reference'] ?? '' ), true );
		} catch ( Throwable $e ) {
			return new WP_Error( 'sku_replace_failed', $e->getMessage(), array( 'status' => 409 ) ); }
		return rest_ensure_response( $row );
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
