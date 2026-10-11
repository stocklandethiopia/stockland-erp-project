<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production import center.
 *
 * DAILY_SALES remains the controlled branch reconciliation path for an
 * external POS workbook. The same release also supports internal ERP POS
 * entry through class-rest-sales.php; both paths share one VAT/amount
 * calculator and final posting rules.
 * Product import is a separate management/owner operation.
 */
class Stockland_REST_Import_V16 {
	const NS            = 'stockland/v16';
	const DAILY_CHUNK   = 50;
	const PRODUCT_CHUNK = 100;
	// Shared-host safety: keep the validated/staged row materialization bounded.
	// A 5,000-row cap leaves headroom for WordPress, XML/shared-string buffers and DB payloads.
	const MAX_IMPORT_ROWS = 5000;

	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'routes' ) ); }
	private function deny( $code, $message, $status = 403 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) ); }
	public function capability_daily() {
		$p    = Stockland_Authority::profile();
		$role = is_array( $p ) ? (string) ( $p['role'] ?? '' ) : '';
		return ( in_array( $role, array( 'branch_manager', 'sales_employee' ), true ) && Stockland_Authority::can_daily_import() ) ? true : $this->deny( 'forbidden', 'Only an assigned Sales Employee or Branch Manager may upload and stage branch Daily POS Excel data; only a Branch Manager may release it.' ); }
	public function capability_product() {
		return Stockland_Authority::can_product_import() ? true : $this->deny( 'forbidden', 'Only Management or Owner may import the Product Master.' ); }

	public function capability_daily_commit() {
		return Stockland_Authority::can_daily_commit() ? true : $this->deny( 'forbidden', 'Only an assigned Branch Manager may post the branch daily POS import.' ); }
	public function capability_mappings() {
		return Stockland_Authority::can_manage_import_mappings() ? true : $this->deny( 'forbidden', 'Only Management or Owner may manage import mappings.' ); }
	private function header_signature( $headers ) {
		$n = array_map( array( $this, 'norm' ), (array) $headers );
		$n = array_values( array_unique( array_filter( $n ) ) );
		sort( $n, SORT_STRING );
		return hash( 'sha256', implode( '|', $n ) ); }
	private function aliases( $type ) {
		if ( $type === 'PRODUCT_MASTER' ) {
			return array(
				'sku'                   => array( 'sku', 'product_code', 'item_code', 'article_code', 'code' ),
				'product_name'          => array( 'product_name', 'product', 'name', 'item_name', 'description' ),
				'product_type'          => array( 'product_type', 'type' ),
				'parent_sku'            => array( 'parent_sku', 'parent_style_sku', 'style_sku' ),
				'style_code'            => array( 'style_code', 'style', 'style_no', 'style_number' ),
				'brand'                 => array( 'brand', 'brand_name' ),
				'department'            => array( 'department', 'dept' ),
				'sub_category'          => array( 'sub_category', 'subcategory', 'child_category', 'child' ),
				'collection_name'       => array( 'collection_name', 'collection' ),
				'season'                => array( 'season', 'season_name' ),
				'material'              => array( 'material', 'fabric', 'material_fabric' ),
				'fit'                   => array( 'fit' ),
				'country_of_origin'     => array( 'country_of_origin', 'origin', 'country' ),
				'image_url'             => array( 'image_url', 'image', 'image_link', 'photo_url' ),
				'barcode'               => array( 'barcode', 'ean', 'upc', 'bar_code' ),
				'gtin'                  => array( 'gtin', 'gtin_code' ),
				'color'                 => array( 'color', 'colour' ),
				'color_code'            => array( 'color_code', 'colour_code', 'colourcode' ),
				'size'                  => array( 'size', 'size_name' ),
				'size_code'             => array( 'size_code', 'size_no', 'size_number' ),
				'size_system'           => array( 'size_system', 'sizing_system', 'size_scale' ),
				'category'              => array( 'category', 'category_style', 'parent_category' ),
				'gender'                => array( 'gender' ),
				'age_group'             => array( 'age_group', 'age' ),
				'unit'                  => array( 'unit', 'uom', 'unit_of_measure' ),
				'purchase_cost'         => array( 'purchase_cost', 'purchase_cost_etb_management_only', 'cost_price', 'cost', 'unit_cost' ),
				'retail_price'          => array( 'retail_price', 'retail_price_etb', 'master_price', 'selling_price' ),
				'wholesale_price'       => array( 'wholesale_price', 'wholesale_price_etb', 'wholesale' ),
				'branch_reorder_level'  => array( 'branch_reorder_level', 'branch_reorder_level_pcs', 'branch_reorder', 'reorder_level' ),
				'central_reorder_level' => array( 'central_reorder_level', 'central_reorder_level_pcs', 'central_reorder' ),
				'notes'                 => array( 'notes', 'note' ),
				'source_default_value'  => array( 'default_value', 'default', 'default_amount' ),
				'source_default_tax'    => array( 'default_tax', 'default_vat', 'tax_default', 'tax_rate', 'vat_rate' ),
				'state'                 => array( 'state', 'status', 'active' ),
				'source_created_at'     => array( 'create_on', 'created_on', 'created_at', 'created' ),
			);
		}
		return array(
			'sku'           => array( 'sku', 'product_code', 'item_code', 'article_code', 'code', 'item', 'item_id', 'product_sku' ),
			'receipt'       => array( 'receipt_no', 'receipt', 'invoice_no', 'invoice', 'transaction_no', 'transaction_id', 'reference', 'ref_no', 'order_no', 'bill_no' ),
			'qty'           => array( 'qty', 'quantity', 'units', 'units_sold', 'qty_sold', 'sold_qty', 'sold_units' ),
			'selling_price' => array( 'selling_price', 'sale_price', 'unit_price', 'actual_price', 'price', 'rate' ),
			'line_total'    => array( 'sales_total', 'line_total', 'total', 'gross_sales', 'amount', 'revenue', 'net_total', 'total_amount' ),
			'vat_amount'    => array( 'vat_amount', 'vat', 'tax_amount', 'tax' ),
			'vat_mode'      => array( 'vat_mode', 'tax_mode', 'vat_basis' ),
			'date'          => array( 'date', 'sale_date', 'business_date', 'transaction_date', 'sold_date' ),
			'customer'      => array( 'customer', 'customer_name', 'client', 'buyer' ),
		);
	}
	private function auto_map_headers( $headers, $type ) {
		$aliases     = $this->aliases( $type );
		$normHeaders = array();
		foreach ( (array) $headers as $h ) {
			$normHeaders[ $this->norm( $h ) ] = (string) $h;}
		$mapping    = array();
		$confidence = array();
		foreach ( $aliases as $field => $list ) {
			$hit   = null;
			$score = 0;
			foreach ( $list as $a ) {
				$na = $this->norm( $a );
				if ( isset( $normHeaders[ $na ] ) ) {
					$hit   = $normHeaders[ $na ];
					$score = 100;
					break;}
			}
			if ( ! $hit ) {
				foreach ( $list as $a ) {
					$na          = $this->norm( $a );
					$aliasTokens = array_values( array_filter( explode( '_', $na ) ) );
					foreach ( $normHeaders as $nh => $original ) {
						$headerTokens = array_values( array_filter( explode( '_', $nh ) ) );
						// Fuzzy matching is deliberately conservative: multi-word aliases only match the same token set,
						// while single-word aliases must appear as a whole header token. This prevents VAT->VAT mode,
						// Name->Brand, etc. false positives.
						if ( count( $aliasTokens ) > 1 ) {
							$aTokens = $aliasTokens;
							$hTokens = $headerTokens;
							sort( $aTokens );
							sort( $hTokens );
							if ( $aTokens === $hTokens ) {
								$hit   = $original;
								$score = 90;
								break 2;}
						} elseif ( count( $aliasTokens ) === 1 && strlen( $aliasTokens[0] ) >= 3 && in_array( $aliasTokens[0], $headerTokens, true ) ) {
							$hit   = $original;
							$score = 80;
							break 2;
						}
					}
				}
			}
			if ( $hit ) {
				$mapping[ $field ]    = $hit;
				$confidence[ $field ] = $score;}
		}
		$reverse   = array();
		$conflicts = array();
		foreach ( $mapping as $field => $header ) {
			$key = $this->norm( $header );
			if ( $key === '' ) {
				continue;
			}
			if ( isset( $reverse[ $key ] ) && $reverse[ $key ] !== $field ) {
				$conflicts[] = array(
					'header' => $header,
					'fields' => array( $reverse[ $key ], $field ),
				);
			} else {
				$reverse[ $key ] = $field;
			}
		}
		return array(
			'mapping'    => $mapping,
			'confidence' => $confidence,
			'conflicts'  => $conflicts,
			'signature'  => $this->header_signature( $headers ),
			'headers'    => array_values( (array) $headers ),
		);
	}
	private function validate_mapping_contract( $found, $type ) {
		$mapping  = is_array( $found['mapping'] ?? null ) ? $found['mapping'] : array();
		$headers  = array_values( (array) ( $found['headers'] ?? array() ) );
		$required = $type === 'PRODUCT_MASTER' ? array( 'sku', 'product_name' ) : array( 'sku', 'qty' );
		foreach ( $required as $field ) {
			if ( empty( $mapping[ $field ] ) ) {
				throw new Exception( 'Required import field ' . $field . ' could not be mapped.' );
			}
		}
		if ( $type === 'DAILY_SALES' && empty( $mapping['selling_price'] ) && empty( $mapping['line_total'] ) ) {
			throw new Exception( 'Daily POS requires Selling Price or Line Total. The ERP will not invent a sale value.' );
		}
		$seen  = array();
		$dupes = array();
		foreach ( $mapping as $field => $header ) {
			$n = $this->norm( $header );
			if ( $n === '' ) {
				continue;
			}
			if ( isset( $seen[ $n ] ) && $seen[ $n ] !== $field ) {
				$dupes[] = array(
					'header' => $header,
					'fields' => array( $seen[ $n ], $field ),
				);
			} else {
				$seen[ $n ] = $field;
			}
		}
		if ( ! empty( $found['conflicts'] ) || $dupes ) {
			$all   = array_merge( (array) ( $found['conflicts'] ?? array() ), $dupes );
			$parts = array();
			foreach ( $all as $c ) {
				$parts[] = $c['header'] . ' → ' . implode( ', ', (array) $c['fields'] );}
			throw new Exception( 'Import mapping is ambiguous. One source column cannot serve multiple ERP fields: ' . implode( '; ', $parts ) );
		}
		// A Product Master source that contains physical stock is an explicit contract violation,
		// not an informational warning. It must never reach staging/commit.
		if ( $type === 'PRODUCT_MASTER' ) {
			foreach ( array( 'initial_stock', 'opening_stock', 'stock_quantity', 'quantity', 'qty', 'stock' ) as $forbidden ) {
				foreach ( $headers as $header ) {
					if ( $this->norm( $header ) === $this->norm( $forbidden ) ) {
						throw new Exception( 'Product Master import cannot contain physical stock field "' . $header . '". Use Warehouse Receiving for quantities.' );
					}
				}
			}
		}
		return true;
	}
	private function get_profile( $type, $source_system, $signature ) {
		global $wpdb;
		$t = Stockland_DB::table( 'import_profiles' );
		if ( ! Stockland_DB::table_exists( $t ) ) {
			return null;
		}$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE import_type=%s AND source_system=%s AND header_signature=%s AND is_active=1 LIMIT 1", $type, $source_system, $signature ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}$map = json_decode( (string) $row['mapping_json'], true );
		return is_array( $map ) ? array(
			'id'      => (int) $row['id'],
			'mapping' => $map,
		) : null;}
	private function save_profile( $type, $source_system, $headers, $mapping, $name = 'Learned mapping' ) {
		global $wpdb;
		$t = Stockland_DB::table( 'import_profiles' );
		if ( ! Stockland_DB::table_exists( $t ) ) {
			return null;
		}$sig     = $this->header_signature( $headers );
		$json     = wp_json_encode( $mapping );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE import_type=%s AND source_system=%s AND header_signature=%s LIMIT 1", $type, $source_system, $sig ) );
		$data     = array(
			'import_type'      => $type,
			'source_system'    => $source_system,
			'profile_name'     => sanitize_text_field( $name ),
			'header_signature' => $sig,
			'mapping_json'     => $json,
			'mapping_version'  => 1,
			'is_active'        => 1,
			'updated_by'       => get_current_user_id(),
		);
		if ( $existing ) {
			$wpdb->update( $t, $data, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}$data['created_by'] = get_current_user_id();
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;}
	private function scan_workbook( $xlsx, $type, $source_system ) {
		Stockland_Runtime_Diagnostics::stage( $type === 'PRODUCT_MASTER' ? 'product_master_scan' : 'daily_pos_scan' );
		foreach ( $xlsx->sheet_names() as $sheet ) {
			Stockland_Runtime_Diagnostics::stage( ( $type === 'PRODUCT_MASTER' ? 'product_master_sheet:' : 'daily_pos_sheet:' ) . substr( sanitize_key( (string) $sheet ), 0, 50 ) );
			$header    = null;
			$headers   = array();
			$mapping   = array();
			$profile   = null;
			$auto      = null;
			$buffer    = array();
			$out       = array();
			$data_rows = 0;
			foreach ( $xlsx->stream_rows( $sheet, Stockland_XLSX::MAX_PHYSICAL_ROW_NODES ) as $record ) {
				$row_number = (int) $record['row_number'];
				$values     = (array) $record['values'];
				$buffer[]   = $record;
				// Header discovery is limited to the first 10 physical worksheet rows.
				if ( $header === null && $row_number <= 10 ) {
					$candidate = array();
					$max_index = $values ? max( array_keys( $values ) ) : -1;
					for ( $i = 0;$i <= $max_index;$i++ ) {
						$candidate[ $i ] = isset( $values[ $i ] ) ? trim( (string) $values[ $i ] ) : '';
					}
					$candidate_headers = $candidate;
					if ( ! array_filter(
						$candidate,
						function ( $v ) {
							return $v !== '';
						}
					) ) {
						continue;
					}
					$candidate_auto    = $this->auto_map_headers( $candidate_headers, $type );
					$candidate_profile = $this->get_profile( $type, $source_system, $candidate_auto['signature'] );
					$candidate_mapping = $candidate_profile ? $candidate_profile['mapping'] : $candidate_auto['mapping'];
					$candidate_found   = array(
						'mapping'   => $candidate_mapping,
						'headers'   => $candidate_headers,
						'conflicts' => $candidate_auto['conflicts'] ?? array(),
					);
					try {
						$this->validate_mapping_contract( $candidate_found, $type );
						$ready = true;
					} catch ( Throwable $map_error ) {
						$ready = false;}
					if ( $ready ) {
						$header  = $row_number;
						$headers = $candidate_headers;
						$mapping = $candidate_mapping;
						$profile = $candidate_profile;
						$auto    = $candidate_auto;
						foreach ( $buffer as $prior ) {
							if ( (int) $prior['row_number'] <= $header ) {
								continue;
							}
							$r = $this->stream_assoc_row( $prior['values'], $headers );
							if ( $r !== null ) {
								$r['__excel_row'] = (int) $prior['row_number'];
								$out[]            = $r;
								++$data_rows;}
						}
						$buffer = array();
					}
					continue;
				}
				if ( $header !== null ) {
					$r = $this->stream_assoc_row( $values, $headers );
					if ( $r !== null ) {
						$r['__excel_row'] = $row_number;
						$out[]            = $r;
						++$data_rows;
						if ( $data_rows > self::MAX_IMPORT_ROWS ) {
							throw new Exception( ( $type === 'PRODUCT_MASTER' ? 'Product Master' : 'Daily POS' ) . ' file exceeds the ' . self::MAX_IMPORT_ROWS . '-row safety limit. Split the workbook.' );
						}
					}
				}
			}
			if ( $header !== null ) {
				$final_found = array(
					'mapping'   => $mapping,
					'headers'   => $headers,
					'conflicts' => $auto['conflicts'] ?? array(),
				);
				$this->validate_mapping_contract( $final_found, $type );
				return array(
					'sheet'      => $sheet,
					'header_row' => $header,
					'rows'       => $out,
					'headers'    => $headers,
					'mapping'    => $mapping,
					'auto'       => $auto,
					'conflicts'  => $auto['conflicts'] ?? array(),
					'profile_id' => $profile['id'] ?? null,
					'signature'  => $auto['signature'],
				);
			}
		}
		throw new Exception( $type === 'PRODUCT_MASTER' ? 'No Product Master worksheet could be mapped automatically. Required columns include Code/SKU and Name/Product Name.' : 'No daily POS worksheet could be mapped automatically. Required columns include SKU/product code and selling price or line total.' );
	}

	private function stream_assoc_row( $values, $headers ) {
		$has = false;
		$r   = array();
		foreach ( $headers as $i => $header ) {
			if ( $header === '' ) {
				continue;
			}
			$value        = array_key_exists( $i, $values ) ? $values[ $i ] : null;
			$r[ $header ] = $value;
			if ( $value !== null && trim( (string) $value ) !== '' ) {
				$has = true;
			}
		}
		return $has ? $r : null;
	}
	private function mapped_value( $row, $mapping, $field ) {
		if ( empty( $mapping[ $field ] ) ) {
			return null;
		}$h = $mapping[ $field ];
		return array_key_exists( $h, $row ) ? $row[ $h ] : null;}
	private function find_exact_source( $row, $wanted ) {
		$target = $this->norm( $wanted );
		foreach ( (array) $row as $k => $v ) {
			if ( $k === '__excel_row' ) {
				continue;
			}
			if ( $this->norm( $k ) === $target ) {
				return $v;
			}
		}
		return null;
	}
	private function parse_active_state( $v ) {
		if ( $v === null || trim( (string) $v ) === '' ) {
			return null;
		}$x = strtolower( trim( (string) $v ) );
		if ( in_array( $x, array( 'active', 'enabled', 'yes', 'y', '1', 'true', 'open' ), true ) ) {
			return 1;
		}if ( in_array( $x, array( 'inactive', 'disabled', 'no', 'n', '0', 'false', 'closed' ), true ) ) {
			return 0;
		}return null;}
	private function parse_datetime( $v ) {
		if ( $v === null || $v === '' ) {
			return null;
		}if ( is_numeric( $v ) && (float) $v > 20000 && (float) $v < 60000 ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '1899-12-30 + ' . ( (int) $v ) . ' days' ) );
		}$ts = strtotime( (string) $v );
		return $ts === false ? null : gmdate( 'Y-m-d H:i:s', $ts );}
	private function ignorable_row( $row, $mapping, $type ) {
		$values = array();
		foreach ( (array) $mapping as $field => $header ) {
			if ( array_key_exists( $header, $row ) ) {
				$values[ $field ] = trim( (string) $row[ $header ] );
			}
		}
		$sku     = trim( (string) ( $values['sku'] ?? '' ) );
		$receipt = trim( (string) ( $values['receipt'] ?? '' ) );
		if ( $type === 'PRODUCT_MASTER' && $sku !== '' && preg_match( '/^(count|total|subtotal|grand[ _-]?total|summary)[:\s_-]*/i', $sku ) ) {
			return true;
		}
		if ( $type === 'DAILY_SALES' && ( $sku !== '' || $receipt !== '' ) ) {
			$probe = $sku !== '' ? $sku : $receipt;
			if ( preg_match( '/^(total|subtotal|grand[ _-]?total|summary|vat|tax|net[ _-]?sales|gross[ _-]?sales)\b/i', $probe ) ) {
				return true;
			}
		}
		$nonempty = 0;
		foreach ( $values as $v ) {
			if ( $v !== '' ) {
				++$nonempty;
			}
		}
		return $nonempty === 0;
	}
	private function product_identifier_owners( $barcodes, $gtins ) {
		global $wpdb;
		$owners = array(
			'barcode' => array(),
			'gtin'    => array(),
		);
		foreach ( array( 'barcode', 'gtin' ) as $field ) {
			$values = $field === 'barcode' ? $barcodes : $gtins;
			$values = array_values(
				array_unique(
					array_filter(
						array_map( 'strval', (array) $values ),
						function ( $v ) {
							return $v !== '';
						}
					)
				)
			);
			foreach ( array_chunk( $values, 400 ) as $chunk ) {
				if ( ! $chunk ) {
					continue;
				}
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$sql          = "SELECT sku,{$field} FROM " . Stockland_DB::table( 'products' ) . " WHERE {$field} IN({$placeholders}) AND {$field}<>''";
				$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );
				if ( $wpdb->last_error ) {
					throw new Exception( 'Could not verify Product Master ' . $field . ' uniqueness.' );
				}
				foreach ( (array) $rows as $row ) {
					$value = (string) ( $row[ $field ] ?? '' );
					if ( $value !== '' ) {
						$owners[ $field ][ $value ] = (string) $row['sku'];
					}
				}
			}
		}
		return $owners;
	}

	private function resolve_external_code( $source, $external, $as_of_date = null ) {
		$live = Stockland_DB::resolve_live_sku( $source, $external, $as_of_date );
		return array( $live, $live && $live === $external ? 'LIVE_SKU' : ( $live ? 'CODE_MAP' : null ) );}

	public function routes() {
		register_rest_route(
			self::NS,
			'/import/daily-sales/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'daily_preview' ),
				'permission_callback' => array( $this, 'capability_daily' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/daily-sales/commit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'daily_commit' ),
				'permission_callback' => array( $this, 'capability_daily_commit' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/daily-sales/edit-row',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'daily_edit_row' ),
				'permission_callback' => array( $this, 'capability_daily_commit' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/daily-sales/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'daily_jobs' ),
				'permission_callback' => array( $this, 'capability_daily' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/daily-sales/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'job_detail' ),
				'permission_callback' => array( $this, 'capability_daily' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/products/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'product_preview' ),
				'permission_callback' => array( $this, 'capability_product' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/products/commit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'product_commit' ),
				'permission_callback' => array( $this, 'capability_product' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/mappings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'mapping_list' ),
				'permission_callback' => array( $this, 'capability_mappings' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/mappings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mapping_save' ),
				'permission_callback' => array( $this, 'capability_mappings' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/code-maps',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'code_map_list' ),
				'permission_callback' => array( $this, 'capability_mappings' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/code-maps',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'code_map_save' ),
				'permission_callback' => array( $this, 'capability_mappings' ),
			)
		);
		register_rest_route(
			self::NS,
			'/import/code-maps/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'code_map_delete' ),
				'permission_callback' => array( $this, 'capability_mappings' ),
			)
		);
	}

	private function uploaded_file( $key = 'file' ) {
		// Import endpoints are registered WordPress REST callbacks; REST auth/nonce and
			// permission checks run before this upload validator is called.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REST validates nonce/auth and the route capability; all consumed upload fields are type-checked, normalized and verified below.
		$upload = isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ? $_FILES[ $key ] : array();
		if ( empty( $upload ) ) {
			throw new Exception( 'Please select an .xlsx file.' );
		}
		$file_error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $file_error ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE   => 'The XLSX upload exceeds the server upload limit.',
				UPLOAD_ERR_FORM_SIZE  => 'The XLSX upload exceeds the form upload limit.',
				UPLOAD_ERR_PARTIAL    => 'The XLSX upload was incomplete. Please upload the file again.',
				UPLOAD_ERR_NO_FILE    => 'Please select an .xlsx file.',
				UPLOAD_ERR_NO_TMP_DIR => 'The hosting server has no temporary upload directory.',
				UPLOAD_ERR_CANT_WRITE => 'The hosting server could not write the uploaded file.',
				UPLOAD_ERR_EXTENSION  => 'The hosting server rejected the uploaded file.',
			);
			throw new Exception( $messages[ $file_error ] ?? 'The XLSX upload failed.' );
		}
		$path = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? sanitize_text_field( wp_unslash( $upload['tmp_name'] ) ) : '';
		if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new Exception( 'The uploaded XLSX file could not be read.' );
		}
		if ( function_exists( 'is_uploaded_file' ) && ! is_uploaded_file( $path ) ) {
			throw new Exception( 'The import file is not a valid HTTP upload. Please upload the workbook again.' );
		}
		$file_name_input = isset( $upload['name'] ) && is_string( $upload['name'] ) ? wp_unslash( $upload['name'] ) : '';
		$name = sanitize_file_name( $file_name_input );
		if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'xlsx' ) {
			throw new Exception( 'Only XLSX files are accepted.' );
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			throw new Exception( 'The uploaded XLSX file is empty.' );
		}
		if ( $size > Stockland_XLSX::MAX_FILE_BYTES ) {
			throw new Exception( 'XLSX file is too large. Maximum allowed size is 8 MB.' );
		}
		return array(
			'path'       => $path,
			'name'       => $name,
			'size_bytes' => $size,
		);
	}

	private function norm( $v ) {
		$v = strtolower( trim( (string) $v ) );
		$v = preg_replace( '/[^a-z0-9]+/', '_', $v );
		return trim( $v, '_' );
	}
	private function find( $row, $names ) {
		foreach ( $names as $n ) {
			$nn = $this->norm( $n );
			foreach ( $row as $k => $v ) {
				if ( $this->norm( $k ) === $nn || strpos( $this->norm( $k ), $nn ) !== false ) {
					return $v;
				}
			}
		}
		return null;
	}
	private function date_value( $v, $fallback ) {
		if ( is_numeric( $v ) && (float) $v > 20000 && (float) $v < 60000 ) {
			$ts = gmdate( 'Y-m-d', strtotime( '1899-12-30 + ' . ( (int) $v ) . ' days' ) );
			if ( $ts ) {
				return $ts;
			}
		}
		$v = trim( (string) $v );
		if ( $v && strtotime( $v ) !== false ) {
			return gmdate( 'Y-m-d', strtotime( $v ) );
		}
		return $fallback;
	}
	private function money( $v ) {
		if ( $v === null || $v === '' ) {
			return null;
		}
		$v = str_replace( array( ',', 'ETB', 'Birr' ), '', (string) $v );
		return is_numeric( $v ) ? Stockland_Money::decimal( Stockland_Money::minor( $v ) ) : null;
	}
	private function qty( $v ) {
		if ( $v === null || $v === '' ) {
			return null;
		}
		$v = str_replace( ',', '', (string) $v );
		return is_numeric( $v ) ? (float) $v : null;
	}
	private function map_vat_mode( $raw_mode, $basis ) {
		$raw = strtoupper( trim( (string) $raw_mode ) );
		if ( in_array( $raw, array( 'VAT_INCLUDED', 'INCLUSIVE', 'INCLUDED', 'VAT INCLUSIVE', 'TAX INCLUDED' ), true ) ) {
			return 'VAT_INCLUDED';
		}
		if ( in_array( $raw, array( 'WITHOUT_VAT', 'EXCLUSIVE', 'EXCL_VAT', 'VAT EXCLUSIVE', 'TAX EXCLUDED' ), true ) ) {
			return 'WITHOUT_VAT';
		}
		return strtoupper( (string) $basis ) === 'EXCLUSIVE' ? 'WITHOUT_VAT' : 'VAT_INCLUDED';
	}
	private function sheet_rows( $xlsx, $needles ) {
		$r = $xlsx->rows_by_headers( $needles, 10 );
		if ( empty( $r['rows'] ) ) {
			throw new Exception( 'No worksheet with the required headers was found. Required at least SKU/Product Code and a sales amount or selling price column.' );
		}
		return $r;
	}
	private function branch_for_user( $requested ) {
		$p = Stockland_Authority::profile();
		if ( ! $p ) {
			throw new Exception( 'No active Stockland profile.' );
		}
		$allowed    = array_values( array_unique( array_map( 'intval', Stockland_Authority::location_ids() ) ) );
		$is_company = Stockland_Authority::company_scope();
		if ( $is_company ) {
			$id = (int) $requested;
			if ( ! $id ) {
				throw new Exception( 'Select the branch represented by this POS file.' );
			}return $id;}
		if ( ! $allowed ) {
			throw new Exception( 'Your Stockland user must be assigned to at least one branch before importing sales.' );
		}
		if ( $requested !== null && $requested !== '' ) {
			$id = (int) $requested;
			if ( ! in_array( $id, $allowed, true ) ) {
				throw new Exception( 'Selected branch is outside your authorized operational scope.' );
			}return $id;}
		return (int) $allowed[0];
	}
	private function ensure_branch( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'locations' ) . ' WHERE id=%d AND is_active=1', $id ), ARRAY_A );
		if ( ! $row || $row['location_type'] !== 'Branch' ) {
			throw new Exception( 'The selected sales branch is not an active retail branch.' );
		} return $row;
	}
	private function qty_headers( $row ) {
		foreach ( array( 'qty', 'quantity', 'units', 'units_sold', 'qty_sold', 'sold_qty' ) as $n ) {
			$v = $this->find( $row, array( $n ) );
			if ( $v !== null && $v !== '' ) {
				return true;
			}
		} return false;
	}

	private function validate_staged_daily_row( $job, $row, $product ) {
		$q = $this->qty( $row['quantity'] ?? null );
		if ( $q === null || $q <= 0 ) {
			throw new Exception( 'Imported row #' . $row['row_number'] . ' has an invalid quantity.' );
		}
		$price = $this->money( $row['selling_price'] ?? null );
		if ( $price === null || $price <= 0 ) {
			throw new Exception( 'Imported row #' . $row['row_number'] . ' has an invalid selling price.' );
		}
		$mode = strtoupper( (string) ( $row['vat_mode'] ?? '' ) );
		if ( ! in_array( $mode, array( 'VAT_INCLUDED', 'WITHOUT_VAT' ), true ) ) {
			throw new Exception( 'Imported row #' . $row['row_number'] . ' has an invalid VAT mode.' );
		}
		$current_rate = (float) Stockland_DB::get_vat_rate();
		if ( abs( $current_rate - (float) $row['vat_rate'] ) > 0.000001 ) {
			throw new Exception( 'VAT rate changed after preview. Re-preview the Daily POS file before releasing row #' . $row['row_number'] . '.' );
		}
		$current_master = Stockland_Money::decimal( Stockland_Money::minor( $product['retail_price'] ) );
		if ( ! Stockland_Sale_Math::same_money( $current_master, $row['master_price'] ) ) {
			throw new Exception( 'Product Master retail price changed after preview for ' . $row['sku'] . '. Re-preview the file.' );
		}
		$current_cost = Stockland_Money::decimal( Stockland_Money::minor( $product['purchase_cost'] ) );
		if ( ! Stockland_Sale_Math::same_money( $current_cost, $row['cost_basis'] ) ) {
			throw new Exception( 'Product Master purchase cost changed after preview for ' . $row['sku'] . '. Re-preview the file.' );
		}

		$calc = Stockland_Sale_Math::calculate( $q, $price, $mode, $current_rate );
		if ( ! Stockland_Sale_Math::same_money( $calc['sales_total'], $row['sales_total'] ) ) {
			throw new Exception( 'Imported sales total does not match quantity × selling price × VAT rules on row #' . $row['row_number'] . '. Re-preview the file.' );
		}
		if ( ! Stockland_Sale_Math::same_money( $calc['vat_amount'], $row['vat_amount'] ) ) {
			throw new Exception( 'Imported VAT amount does not match the central VAT rule on row #' . $row['row_number'] . '. Re-preview the file.' );
		}

		return array(
			'quantity'      => $q,
			'selling_price' => $price,
			'calc'          => $calc,
			'cost_basis'    => $current_cost,
		);
	}

	public function daily_preview( WP_REST_Request $r ) {
		global $wpdb;
		try {
			$file          = $this->uploaded_file( 'file' );
			$business_date = sanitize_text_field( $r->get_param( 'business_date' ) ?: current_time( 'Y-m-d' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $business_date ) ) {
				throw new Exception( 'Business date must be YYYY-MM-DD.' );
			}
			$branch = $this->branch_for_user( $r->get_param( 'branch_location_id' ) );
			$this->ensure_branch( $branch );
			Stockland_Business_Calendar::assert_business_day( $business_date );
			if ( Stockland_DB::is_day_closed( $branch, $business_date ) ) {
				throw new Exception( 'This branch day is already closed.' );
			}$hash      = hash_file( 'sha256', $file['path'] );
			$import_key = hash( 'sha256', 'DAILY_POS|' . $branch . '|' . $business_date . '|' . $hash );
			$old        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'import_jobs' ) . ' WHERE import_key=%s LIMIT 1', $import_key ), ARRAY_A );
			if ( $old && $old['status'] !== 'CANCELLED' ) {
				throw new Exception( 'This exact POS file for this branch/date was already staged/imported as job #' . $old['id'] . '. Reuse that job instead of uploading it again.' );
			}
			$cross = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_location_id,business_date,status FROM ' . Stockland_DB::table( 'import_jobs' ) . " WHERE source_sha256=%s AND import_type='DAILY_SALES' AND status<>'CANCELLED' ORDER BY id DESC LIMIT 1", $hash ), ARRAY_A );
			if ( $cross && ( (int) $cross['branch_location_id'] !== $branch || (string) $cross['business_date'] !== $business_date ) ) {
				throw new Exception( 'This POS file hash is already associated with another branch/date (job #' . $cross['id'] . '). Verify the source file before reusing it.' );
			}
			$xlsx     = new Stockland_XLSX( $file['path'] );
			$found    = $this->scan_workbook( $xlsx, 'DAILY_SALES', 'THIRD_PARTY_POS' );
			$all_rows = $found['rows'];
			$rows     = array();
			foreach ( $all_rows as $src ) {
				if ( $this->ignorable_row( $src, $found['mapping'], 'DAILY_SALES' ) ) {
					continue;
				}$rows[] = $src;
			} $ignored_rows = count( $all_rows ) - count( $rows );
			if ( count( $rows ) > self::MAX_IMPORT_ROWS ) {
				throw new Exception( 'The daily POS file exceeds the ' . self::MAX_IMPORT_ROWS . '-row safety limit. Split it by business day/branch.' );
			}$map          = $found['mapping'];
			$has_qty       = ! empty( $map['qty'] );
			$vat_rate      = Stockland_DB::get_vat_rate();
			$basis         = Stockland_DB::get_vat_basis();
			$valid         = 0;
			$errors        = 0;
			$warnings      = array();
			$error_details = array();
			$resolve_cache = array();
			$product_cache = array();
			if ( $ignored_rows > 0 ) {
				$warnings[] = $ignored_rows . ' summary/footer row(s) were ignored because they are not sale lines.';
			}$staged        = array();
			$seen           = array();
			$units          = 0;
			$gross          = 0;
			$vat            = 0;
			$mapped_count   = 0;
			$unmapped_count = 0;
			$external_codes = array();
			foreach ( $rows as $src ) {
				$external_codes[] = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'sku' ) );
			}
			$bulk_resolution = $this->daily_resolution_bulk( $external_codes, $business_date );
			$resolve_map     = $bulk_resolution['map'];
			$product_cache   = $bulk_resolution['products'];
			foreach ( $rows as $i => $src ) {
				$rn         = (int) ( $src['__excel_row'] ?? ( $i + 1 ) );
				$external   = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'sku' ) );
				$receipt    = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'receipt' ) );
				$live_sku   = $resolve_map[ $external ][0] ?? null;
				$mapped_via = $resolve_map[ $external ][1] ?? null;
				$row_errors = array();
				if ( $external === '' ) {
					$row_errors[] = 'Missing SKU/Product Code.';}
				if ( ! $live_sku ) {
					$row_errors[] = 'Unknown POS code ' . $external . '. Management must add a POS-code → live-SKU mapping before this row can post.';
					++$unmapped_count;
				} else {
					++$mapped_count;}
				$product = $live_sku ? ( $product_cache[ $live_sku ] ?? null ) : null;
				if ( $live_sku && ! $product ) {
					$row_errors[] = 'Mapped live SKU ' . $live_sku . ' is not active.';
				} elseif ( $product && ( $product['product_type'] ?? 'SIMPLE' ) === 'STYLE' ) {
					$row_errors[] = 'SKU ' . $live_sku . ' is a STYLE template, not a stockable variant.';
				} elseif ( $product && ( $product['purchase_cost'] === null || $product['purchase_cost'] === '' ) ) {
					$row_errors[] = 'SKU ' . $live_sku . ' has no purchase cost in Product Master. Configure the real cost before posting sales.';
				}
				$qty = $this->qty( $this->mapped_value( $src, $map, 'qty' ) );
				if ( $qty === null ) {
					$row_errors[] = $has_qty ? 'Quantity is missing or not numeric.' : 'Required Quantity/Qty column is missing. The ERP will not invent a quantity.';
				} elseif ( $qty <= 0 ) {
					$row_errors[] = 'Quantity must be greater than zero.';
				}
				$price      = $this->money( $this->mapped_value( $src, $map, 'selling_price' ) );
				$line_total = $this->money( $this->mapped_value( $src, $map, 'line_total' ) );
				if ( $price === null && $line_total !== null && $qty > 0 ) {
					$price = Stockland_Money::divide( $line_total, $qty );
				}
				if ( $price === null ) {
					$row_errors[] = 'Actual selling price/line total is missing; ERP will not invent a sale price.';
				}
				$raw_vat_mode = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'vat_mode' ) );
				$mode         = $this->map_vat_mode( $raw_vat_mode, $basis );
				if ( $raw_vat_mode === '' ) {
					$warnings[] = 'POS VAT mode was not supplied; company VAT basis/rate was used for row ' . $rn . '.';
				}
				$calc = null;
				if ( ! $row_errors && $price !== null && $qty !== null && $qty > 0 ) {
					try {
						$calc = Stockland_Sale_Math::calculate( $qty, $price, $mode, $vat_rate );
					} catch ( Throwable $e ) {
						$row_errors[] = $e->getMessage();}
				}
				$supplied_vat = $this->money( $this->mapped_value( $src, $map, 'vat_amount' ) );
				if ( ! $row_errors && $calc ) {
					// The ERP calculation is authoritative. A supplied VAT amount is accepted only if it agrees exactly.
					if ( $supplied_vat !== null && ! Stockland_Sale_Math::same_money( $supplied_vat, $calc['vat_amount'] ) ) {
						$row_errors[] = 'Source VAT amount does not match the central VAT rule.';
					}
					if ( $line_total !== null ) {
						$expected_line_price = $calc['line_price_excl_vat'];
						if ( $mode === 'VAT_INCLUDED' && ! Stockland_Sale_Math::same_money( $line_total, $calc['sales_total'] ) ) {
							$row_errors[] = 'Source line total does not match quantity × VAT-inclusive selling price.';
						} elseif ( $mode === 'WITHOUT_VAT' && ! Stockland_Sale_Math::same_money( $line_total, $expected_line_price ) ) {
							$row_errors[] = 'Source line total does not match quantity × VAT-exclusive selling price.';
						}
					}
				}
				if ( $mapped_via === 'CODE_MAP' ) {
					$warnings[] = 'Row ' . $rn . ' POS code ' . $external . ' mapped to live SKU ' . $live_sku . '. Historical source code is preserved.';
				}
				$source_key = hash( 'sha256', $hash . '|' . $rn );
				if ( isset( $seen[ $source_key ] ) ) {
					$row_errors[] = 'Duplicate source row.';
				}
				$seen[ $source_key ] = 1;
				if ( $row_errors ) {
					++$errors;
					$status = 'ERROR';
					if ( count( $error_details ) < 100 ) {
						$error_details[] = array(
							'row_number'  => $rn,
							'source_code' => $external,
							'message'     => implode( ' ', $row_errors ),
						);
					}
				} else {
					++$valid;
					$status = 'VALID';
					$units += $qty;
					$gross  = Stockland_Money::add( $gross, $calc['sales_total'] );
					$vat    = Stockland_Money::add( $vat, $calc['vat_amount'] );}
					$staged[] = array(
						'row_number'          => $rn,
						'external_receipt_no' => $receipt,
						'source_code'         => $external,
						'sku'                 => $live_sku,
						'mapped_sku'          => $live_sku,
						'variant_id'          => $product ? (int) $product['variant_id'] : null,
						'mapped_via'          => $mapped_via,
						'quantity'            => $qty,
						'master_price'        => $product ? Stockland_Money::decimal( Stockland_Money::minor( $product['retail_price'] ) ) : null,
						'selling_price'       => $price,
						'vat_mode'            => $mode,
						'vat_rate'            => $vat_rate,
						'vat_amount'          => $calc['vat_amount'] ?? $supplied_vat ?? '0.00',
						'sales_total'         => $calc['sales_total'] ?? ( $line_total ?? '0.00' ),
						'cost_basis'          => $product ? Stockland_Money::decimal( Stockland_Money::minor( $product['purchase_cost'] ) ) : null,
						'cost_basis_source'   => 'PRODUCT_MASTER_PREVIEW',
						'source_key'          => $source_key,
						'payload'             => wp_json_encode( $src ),
						'status'              => $status,
						'error_message'       => implode( ' ', $row_errors ),
					);
			}
			$profile_id = $found['profile_id'];
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new Exception( 'Could not start Daily POS preview transaction.' );
			}if ( ! $profile_id && $errors === 0 ) {
				$profile_id = $this->save_profile( 'DAILY_SALES', 'THIRD_PARTY_POS', $found['headers'], $map, 'Third-party POS daily sales mapping' );
			}$ok = $wpdb->insert(
				Stockland_DB::table( 'import_jobs' ),
				array(
					'import_key'         => $import_key,
					'import_type'        => 'DAILY_SALES',
					'branch_location_id' => $branch,
					'business_date'      => $business_date,
					'source_filename'    => $file['name'],
					'source_sha256'      => $hash,
					'source_system'      => 'THIRD_PARTY_POS',
					'mapping_profile_id' => $profile_id,
					'mapping_signature'  => $found['signature'],
					'status'             => $errors ? 'REVIEW' : 'READY',
					'total_rows'         => count( $staged ),
					'valid_rows'         => $valid,
					'error_rows'         => $errors,
					'processed_rows'     => 0,
					'created_by'         => get_current_user_id(),
				)
			);
			if ( ! $ok ) {
				throw new Exception( 'Could not create import job.' );
			}$job_id = (int) $wpdb->insert_id;
			$this->stage_import_rows( $job_id, $staged, 'DAILY_SALES' );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Daily Sales preview commit failed.' );
			}
			return rest_ensure_response(
				array(
					'job_id'         => $job_id,
					'status'         => $errors ? 'REVIEW' : 'READY',
					'sheet'          => $found['sheet'],
					'header_row'     => $found['header_row'],
					'mapping'        => array(
						'mode'          => $found['profile_id'] ? 'REMEMBERED_PROFILE' : 'AUTO_MATCHED',
						'profile_id'    => $profile_id,
						'signature'     => $found['signature'],
						'fields'        => $map,
						'mapped_rows'   => $mapped_count,
						'unmapped_rows' => $unmapped_count,
					),
					'summary'        => array(
						'total_rows'              => count( $staged ),
						'valid_rows'              => $valid,
						'error_rows'              => $errors,
						'units'                   => round( $units, 2 ),
						'gross_sales'             => round( $gross, 2 ),
						'vat'                     => round( $vat, 2 ),
						'quantity_column_present' => $has_qty ? 'YES' : 'NO',
					),
					'errors'         => $error_details,
					'warnings'       => array_values( array_unique( $warnings ) ),
					'control_layers' => array(
						'layer_1_file_integrity'      => 'PASS',
						'layer_2_identity_mapping'    => $found['profile_id'] ? 'PASS_REMEMBERED_PROFILE' : 'PASS_AUTO_MAPPED',
						'layer_3_business_validation' => $errors ? 'BLOCKED_ERRORS_FOUND' : 'PASS',
						'layer_4_staging_commit_gate' => 'STAGED_ONLY_NO_AUTO_POST',
					),
					'message'        => $errors ? 'Resolve mapping/validation errors before posting.' : 'Mapping recognized. File staged and ready for Branch Manager posting in bounded chunks.',
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'daily_import_preview_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	public function daily_jobs( WP_REST_Request $r ) {
		global $wpdb;
		$company   = Stockland_Authority::company_scope();
		$scope_ids = array_values( array_unique( array_map( 'intval', Stockland_Authority::location_ids() ) ) );
		$where     = "j.import_type='DAILY_SALES' AND j.status IN('READY','PARTIAL','REVIEW','POSTED')";
		$args      = array();
		if ( ! $company ) {
			if ( ! $scope_ids ) {
				return rest_ensure_response( array() );
			}$ph    = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$where .= " AND j.branch_location_id IN({$ph})";
			$args   = $scope_ids;}
		$sql = 'SELECT j.id,j.branch_location_id,j.business_date,j.source_filename,j.status,j.total_rows,j.valid_rows,j.error_rows,j.processed_rows,j.created_by,j.created_at,j.updated_at,j.message,l.location_name,u.display_name created_by_name FROM ' . Stockland_DB::table( 'import_jobs' ) . ' j LEFT JOIN ' . Stockland_DB::table( 'locations' ) . ' l ON l.id=j.branch_location_id LEFT JOIN ' . $wpdb->users . " u ON u.ID=j.created_by WHERE {$where} ORDER BY j.created_at DESC LIMIT 50";
		return rest_ensure_response( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) );
	}

	public function job_detail( WP_REST_Request $r ) {
		global $wpdb;
		$id  = (int) $r['id'];
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT j.*,l.location_name FROM ' . Stockland_DB::table( 'import_jobs' ) . ' j LEFT JOIN ' . Stockland_DB::table( 'locations' ) . ' l ON l.id=j.branch_location_id WHERE j.id=%d', $id ), ARRAY_A );
		if ( ! $job ) {
			return $this->deny( 'not_found', 'Import job not found.', 404 );
		}
		if ( ! Stockland_Authority::company_scope() ) {
			$allowed = array_values( array_unique( array_map( 'intval', Stockland_Authority::location_ids() ) ) );
			if ( ! in_array( (int) $job['branch_location_id'], $allowed, true ) ) {
				return $this->deny( 'forbidden', 'This import job is outside your authorized operational scope.' );
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_row_number AS row_number,external_receipt_no,sku,mapped_sku,variant_id AS destination_variant_id,quantity,selling_price,sales_total,vat_amount,status,error_message,posted_sale_id FROM ' . Stockland_DB::table( 'import_rows' ) . ' WHERE job_id=%d ORDER BY source_row_number LIMIT 200', $id ), ARRAY_A );
		return rest_ensure_response(
			array(
				'job'  => $job,
				'rows' => $rows,
			)
		);
	}
	public function daily_commit( WP_REST_Request $r ) {
		global $wpdb;
		$id         = (int) ( $r->get_json_params()['job_id'] ?? 0 );
		$jobs       = Stockland_DB::table( 'import_jobs' );
		$rows_table = Stockland_DB::table( 'import_rows' );
		$sales      = Stockland_DB::table( 'sales' );
		$batches    = Stockland_DB::table( 'sale_batches' );
		$job        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE id=%d LIMIT 1", $id ), ARRAY_A );
		if ( ! $job ) {
			return $this->deny( 'not_found', 'Import job not found.', 404 );
		}
		if ( ! Stockland_Authority::company_scope() ) {
			$allowed = array_map( 'intval', Stockland_Authority::location_ids() );
			if ( ! in_array( (int) $job['branch_location_id'], $allowed, true ) ) {
				return $this->deny( 'branch_forbidden', 'Import job is outside your authorized operational scope.', 403 );
			}
		}
		if ( $job['import_type'] !== 'DAILY_SALES' ) {
			return $this->deny( 'invalid_job', 'Not a daily sales job.', 400 );
		}
		if ( in_array( $job['status'], array( 'POSTED', 'CANCELLED' ), true ) ) {
			return rest_ensure_response(
				array(
					'job'     => $job,
					'message' => 'Job already completed or cancelled.',
				)
			);
		}
		if ( (int) $job['error_rows'] > 0 ) {
			return $this->deny( 'job_not_ready', 'Resolve preview errors before committing this daily sales file.', 409 );
		}
		$lock = Stockland_DB::acquire_day_lock( (int) $job['branch_location_id'], $job['business_date'] );
		if ( ! $lock['ok'] ) {
			return $this->deny( 'busy', 'Another operation is already processing this branch/date.', 409 );
		}
		$chunk  = self::DAILY_CHUNK;
		$offset = (int) $job['processed_rows'];
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT *,source_row_number AS row_number FROM {$rows_table} WHERE job_id=%d AND status='VALID' ORDER BY source_row_number LIMIT %d", $id, $chunk ), ARRAY_A );
		if ( empty( $rows ) ) {
			$ok = $wpdb->update(
				$jobs,
				array(
					'status'       => 'POSTED',
					'completed_at' => current_time( 'mysql' ),
					'updated_at'   => current_time( 'mysql' ),
					'approved_by'  => get_current_user_id(),
				),
				array( 'id' => $id )
			);
			if ( false === $ok ) {
				Stockland_DB::release_day_lock( $lock );
				return new WP_Error( 'daily_import_state_failed', $wpdb->last_error ?: 'Could not finalize import job.', array( 'status' => 409 ) );}
			Stockland_DB::release_day_lock( $lock );
			$verify = $wpdb->get_row( $wpdb->prepare( "SELECT status,processed_rows,valid_rows FROM {$jobs} WHERE id=%d", $id ), ARRAY_A );
			if ( ! $verify || $verify['status'] !== 'POSTED' ) {
				return new WP_Error( 'daily_import_state_unverified', 'Import completion could not be verified after commit.', array( 'status' => 409 ) );
			}
			return rest_ensure_response(
				array(
					'job_id'         => $id,
					'status'         => 'POSTED',
					'processed_rows' => (int) $verify['processed_rows'],
					'remaining'      => max( 0, (int) $verify['valid_rows'] - (int) $verify['processed_rows'] ),
					'message'        => 'Daily sales import completed.',
				)
			);
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'daily_import_transaction', 'Could not start import transaction.', array( 'status' => 409 ) );}
		try {
			// Re-read and lock the job inside the transaction. The day-level named lock
			// serializes branch/date operations; this row lock also prevents stale job-state
			// reads from causing a second commit after another request advances the same job.
			$locked_job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE id=%d AND import_type='DAILY_SALES' LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
			if ( ! $locked_job ) {
				throw new Exception( 'Daily sales import job disappeared before commit.' );
			}
			if ( in_array( $locked_job['status'], array( 'POSTED', 'CANCELLED' ), true ) ) {
				$wpdb->query( 'ROLLBACK' );
				Stockland_DB::release_day_lock( $lock );
				return rest_ensure_response(
					array(
						'job_id'         => $id,
						'status'         => $locked_job['status'],
						'processed_rows' => (int) $locked_job['processed_rows'],
						'remaining'      => max( 0, (int) $locked_job['valid_rows'] - (int) $locked_job['processed_rows'] ),
						'message'        => $locked_job['status'] === 'POSTED' ? 'Daily sales import is already complete.' : 'Daily sales import was cancelled.',
					)
				);
			}
			if ( (int) $locked_job['error_rows'] > 0 ) {
				throw new Exception( 'Resolve preview errors before committing this daily sales file.' );
			}
			$job = $locked_job;
			if ( Stockland_DB::is_day_closed( (int) $job['branch_location_id'], $job['business_date'] ) ) {
				throw new Exception( 'The branch day is already closed.' );
			}
			$prod_cache = array();
			$available  = array();
			$tot        = array(
				'gross' => '0.00',
				'vat'   => '0.00',
				'net'   => '0.00',
				'cogs'  => '0.00',
				'units' => 0,
			);
			$batch_ids  = array();
			foreach ( $rows as $row ) {
				$sku = (string) $row['sku'];
				if ( $sku === '' ) {
					throw new Exception( 'Staged Daily POS row ' . $row['row_number'] . ' has no resolved live SKU.' );
				}
				$prod = $prod_cache[ $sku ] ?? Stockland_DB::get_product( $sku );
				if ( ! $prod ) {
					throw new Exception( 'Product ' . $sku . ' no longer exists or is inactive.' );
				}
				if ( ( $prod['product_type'] ?? 'SIMPLE' ) === 'STYLE' ) {
					throw new Exception( 'Product ' . $sku . ' is a STYLE template and cannot be sold.' );
				}
				if ( $prod['purchase_cost'] === null || $prod['purchase_cost'] === '' ) {
					throw new Exception( 'Product ' . $sku . ' has no Product Master purchase cost.' );
				}
				$prod_cache[ $sku ] = $prod;

				// The release step is a fresh transaction boundary. Re-check every business
				// value that can change after preview so staged JSON cannot become an
				// authority by itself.
				$check      = $this->validate_staged_daily_row( $job, $row, $prod );
				$q          = $check['quantity'];
				$price      = $check['selling_price'];
				$calc       = $check['calc'];
				$cost_basis = $check['cost_basis'];
				if ( ! isset( $available[ $sku ] ) ) {
					$available[ $sku ] = Stockland_DB::get_available_stock( (int) $job['branch_location_id'], $sku );
				}
				if ( $available[ $sku ] < $q ) {
					throw new Exception( 'Insufficient available stock for ' . $sku . ' on imported row ' . $row['row_number'] . '. Available ' . $available[ $sku ] . ', imported ' . $q . '.' );
				}

				$sale_id  = 'D' . $id . '-' . $row['row_number'];
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT sale_id FROM {$sales} WHERE sale_id=%s OR (source_job_id=%d AND source_row_number=%d) LIMIT 1", $sale_id, $id, (int) $row['row_number'] ) );
				if ( $existing ) {
					$existing_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sales} WHERE sale_id=%s LIMIT 1 FOR UPDATE", $existing ), ARRAY_A );
					if ( ! $existing_row || $existing_row['posting_status'] !== 'CLOSED' ) {
						throw new Exception( 'Existing sale for imported row ' . $row['row_number'] . ' is not a verified CLOSED transaction.' );
					}
					$ledger_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Stockland_DB::table( 'transactions' ) . " WHERE reference_type='sale' AND reference_id=%s AND source_line_id=%s AND movement_type='SALE' LIMIT 1", $existing, (string) $row['row_number'] ) );
					if ( ! $ledger_ok ) {
						throw new Exception( 'Existing sale ' . $existing . ' has no matching inventory ledger entry. The import was not silently marked complete.' );
					}
					$state_ok = $wpdb->update(
						$rows_table,
						array(
							'status'            => 'POSTED',
							'posted_sale_id'    => $existing,
							'posted_at'         => current_time( 'mysql' ),
							'cost_basis'        => $existing_row['cogs'] !== '' && $q > 0 ? Stockland_Money::divide( $existing_row['cogs'], $q ) : $cost_basis,
							'cost_basis_source' => 'VERIFIED_EXISTING_SALE',
						),
						array( 'id' => $row['id'] )
					);
					if ( false === $state_ok ) {
						throw new Exception( $wpdb->last_error ?: 'Could not finalize already-posted import row ' . $row['row_number'] . '.' );
					}
					$available[ $sku ] -= $q;
					continue;
				}

				$cogs             = Stockland_Money::multiply( $q, $cost_basis );
				$profit           = Stockland_Money::subtract( $calc['net_sales'], $cogs );
				$external_receipt = (string) ( $row['external_receipt_no'] ?? '' );
				$receipt          = $external_receipt !== '' ? $external_receipt : 'ROW-' . $row['row_number'];
				$receipt_key      = hash( 'sha256', 'THIRD_PARTY_POS|' . $job['branch_location_id'] . '|' . $job['business_date'] . '|' . $receipt );
				if ( $external_receipt !== '' ) {
					$cross_job_batch = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT id,job_id,status FROM {$batches} WHERE branch_location_id=%d AND business_date=%s AND source_system=%s AND external_receipt_no=%s LIMIT 1 FOR UPDATE",
							(int) $job['branch_location_id'],
							$job['business_date'],
							'THIRD_PARTY_POS',
							$external_receipt
						),
						ARRAY_A
					);
					if ( $cross_job_batch && (int) $cross_job_batch['job_id'] !== $id && in_array( (string) $cross_job_batch['status'], array( 'POSTING', 'POSTED' ), true ) ) {
						throw new Exception( 'External receipt ' . $external_receipt . ' is already posted for this branch and business date in import job #' . (int) $cross_job_batch['job_id'] . '. Verify the POS export before releasing this job.' );
					}
				}
				$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$batches} WHERE job_id=%d AND receipt_key=%s FOR UPDATE", $id, $receipt_key ), ARRAY_A );
				if ( ! $batch ) {
					$batch_ok = $wpdb->insert(
						$batches,
						array(
							'job_id'              => $id,
							'branch_location_id'  => $job['branch_location_id'],
							'business_date'       => $job['business_date'],
							'source_filename'     => $job['source_filename'],
							'external_receipt_no' => $row['external_receipt_no'] ?: null,
							'receipt_key'         => $receipt_key,
							'source_system'       => 'THIRD_PARTY_POS',
							'status'              => 'POSTING',
							'posted_by'           => get_current_user_id(),
						)
					);
					if ( false === $batch_ok ) {
						throw new Exception( $wpdb->last_error ?: 'Could not create receipt header for ' . $receipt . '.' );
					}
					$batch_id = (int) $wpdb->insert_id;
				} else {
					$batch_id = (int) $batch['id'];}

				if ( false === $wpdb->insert(
					$sales,
					array(
						'sale_id'              => $sale_id,
						'sale_date'            => $job['business_date'],
						'branch_location_id'   => (int) $job['branch_location_id'],
						'sku'                  => $sku,
						'variant_id'           => $prod['variant_id'],
						'quantity_sold'        => $q,
						'size'                 => $prod['size'],
						'color'                => $prod['color'],
						'barcode'              => $prod['barcode'] ?: $prod['gtin'],
						'master_price_at_sale' => $row['master_price'],
						'selling_price'        => $price,
						'price_overridden'     => Stockland_Sale_Math::same_money( $price, $row['master_price'] ) ? 0 : 1,
						'vat_mode'             => $calc['vat_mode'],
						'vat_rate_used'        => (float) Stockland_DB::get_vat_rate(),
						'sales_total'          => $calc['sales_total'],
						'vat_amount'           => $calc['vat_amount'],
						'net_sales_excl_vat'   => $calc['net_sales'],
						'cogs'                 => $cogs,
						'gross_profit'         => $profit,
						'posting_status'       => 'CLOSED',
						'staff_user_id'        => get_current_user_id(),
						'closed_at'            => current_time( 'mysql' ),
						'closed_by'            => get_current_user_id(),
						'external_receipt_no'  => $row['external_receipt_no'],
						'source_job_id'        => $id,
						'source_row_number'    => (int) $row['row_number'],
						'source_sale_group'    => (string) $batch_id,
					)
				) ) {
					throw new Exception( $wpdb->last_error ?: 'Could not create sale row ' . $row['row_number'] . '.' );
				}

				if ( false === Stockland_Ledger::post_sale_closed(
					array(
						'sale_id'            => $sale_id,
						'branch_location_id' => $job['branch_location_id'],
						'sku'                => $sku,
						'quantity_sold'      => $q,
						'sale_date'          => $job['business_date'],
						'source_job_id'      => $id,
						'source_row_number'  => $row['row_number'],
					),
					get_current_user_id()
				) ) {
					throw new Exception( 'Could not post inventory ledger for ' . $sku . ' row ' . $row['row_number'] . '.' );
				}
				if ( ! Stockland_Audit::log( get_current_user_id(), $job['branch_location_id'], 'DAILY_POS_SALE_IMPORT', $sku, $q, $row['master_price'], $price, 'Imported daily POS sale', $sale_id ) ) {
					throw new Exception( 'Audit event failed for row ' . $row['row_number'] . '.' );
				}
				if ( false === $wpdb->update(
					$rows_table,
					array(
						'status'            => 'POSTED',
						'posted_sale_id'    => $sale_id,
						'posted_at'         => current_time( 'mysql' ),
						'cost_basis'        => $cost_basis,
						'cost_basis_source' => 'VERIFIED_AT_RELEASE',
					),
					array( 'id' => $row['id'] )
				) ) {
					throw new Exception( $wpdb->last_error ?: 'Could not mark imported row ' . $row['row_number'] . ' as posted.' );
				}
				$batch_ids[ $batch_id ] = 1;
				$available[ $sku ]     -= $q;
				$tot['gross']           = Stockland_Money::add( $tot['gross'], $calc['sales_total'] );
				$tot['vat']             = Stockland_Money::add( $tot['vat'], $calc['vat_amount'] );
				$tot['net']             = Stockland_Money::add( $tot['net'], $calc['net_sales'] );
				$tot['cogs']            = Stockland_Money::add( $tot['cogs'], $cogs );
				$tot['units']          += $q;
			}
			if ( $tot['units'] > 0 ) {
				$journal_lines = array(
					array(
						'account_code' => '1020',
						'debit'        => $tot['gross'],
						'credit'       => 0,
						'location_id'  => $job['branch_location_id'],
					),
					array(
						'account_code' => '4000',
						'debit'        => 0,
						'credit'       => $tot['net'],
						'location_id'  => $job['branch_location_id'],
					),
					array(
						'account_code' => '2100',
						'debit'        => 0,
						'credit'       => $tot['vat'],
						'location_id'  => $job['branch_location_id'],
					),
					array(
						'account_code' => '5000',
						'debit'        => $tot['cogs'],
						'credit'       => 0,
						'location_id'  => $job['branch_location_id'],
					),
					array(
						'account_code' => '1200',
						'debit'        => 0,
						'credit'       => $tot['cogs'],
						'location_id'  => $job['branch_location_id'],
					),
				);
				$source_id     = $id . '-' . (int) $offset;
				if ( ! Stockland_Accounting::post_journal( $job['business_date'], 'DAILY_SALES_CHUNK', $source_id, 'Daily POS sales import ' . $job['source_filename'] . ' rows ' . ( $offset + 1 ) . '-' . ( $offset + count( $rows ) ), $journal_lines, get_current_user_id() ) ) {
					throw new Exception( 'Daily sales accounting journal failed. No partial chunk was committed.' );
				}
			}
			foreach ( array_keys( $batch_ids ) as $bid ) {
				$agg = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) row_count,COALESCE(SUM(quantity_sold),0) units,COALESCE(SUM(sales_total),0) gross_sales,COALESCE(SUM(vat_amount),0) vat_total,COALESCE(SUM(cogs),0) cogs FROM {$sales} WHERE source_sale_group=%s", (string) $bid ), ARRAY_A );
				if ( ! $agg ) {
					throw new Exception( 'Could not reconcile receipt header ' . $bid . '.' );
				}
				if ( false === $wpdb->update(
					$batches,
					array(
						'row_count'   => (int) $agg['row_count'],
						'units'       => (float) $agg['units'],
						'gross_sales' => Stockland_Money::decimal( Stockland_Money::minor( $agg['gross_sales'] ) ),
						'vat_total'   => Stockland_Money::decimal( Stockland_Money::minor( $agg['vat_total'] ) ),
						'cogs'        => Stockland_Money::decimal( Stockland_Money::minor( $agg['cogs'] ) ),
						'status'      => 'POSTED',
					),
					array( 'id' => $bid )
				) ) {
					throw new Exception( $wpdb->last_error ?: 'Could not finalize receipt header ' . $bid . '.' );
				}
			}
			$new_processed = $offset + count( $rows );
			$status        = $new_processed >= (int) $job['valid_rows'] ? 'POSTED' : 'PARTIAL';
			if ( false === $wpdb->update(
				$jobs,
				array(
					'status'         => $status,
					'processed_rows' => $new_processed,
					'approved_by'    => get_current_user_id(),
					'message'        => sprintf( 'Processed %d of %d rows.', $new_processed, (int) $job['valid_rows'] ),
					'completed_at'   => $status === 'POSTED' ? current_time( 'mysql' ) : null,
				),
				array( 'id' => $id )
			) ) {
				throw new Exception( $wpdb->last_error ?: 'Could not update import job state.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Daily sales import commit failed.' );
			}
			Stockland_DB::release_day_lock( $lock );
			$verify = $wpdb->get_row( $wpdb->prepare( "SELECT status,processed_rows,valid_rows FROM {$jobs} WHERE id=%d", $id ), ARRAY_A );
			if ( ! $verify || (string) $verify['status'] !== $status || (int) $verify['processed_rows'] !== $new_processed ) {
				return new WP_Error( 'daily_import_state_unverified', 'Import committed but its final state could not be verified.', array( 'status' => 409 ) );
			}
			return rest_ensure_response(
				array(
					'job_id'         => $id,
					'status'         => $status,
					'processed_rows' => $new_processed,
					'remaining'      => max( 0, (int) $verify['valid_rows'] - (int) $verify['processed_rows'] ),
					'message'        => $status === 'POSTED' ? 'Daily sales import posted to inventory and ledger.' : 'Chunk committed. Press Continue to post the next chunk.',
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'daily_import_commit_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	public function daily_edit_row( WP_REST_Request $r ) {
		global $wpdb;
		try {
			$p      = $r->get_json_params();
			$job_id = (int) ( $p['job_id'] ?? 0 );
			$row_id = (int) ( $p['row_id'] ?? 0 );
			if ( ! $job_id || ! $row_id ) {
				throw new Exception( 'job_id and row_id are required.' );
			}
			$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'import_jobs' ) . ' WHERE id=%d LIMIT 1', $job_id ), ARRAY_A );
			if ( ! $job ) {
				throw new Exception( 'Import job not found.' );
			}
			if ( $job['import_type'] !== 'DAILY_SALES' ) {
				throw new Exception( 'This is not a Daily POS job.' );
			}
			Stockland_Business_Calendar::assert_business_day( $job['business_date'] );
			if ( ! Stockland_Authority::company_scope() && ! in_array( (int) $job['branch_location_id'], array_map( 'intval', Stockland_Authority::location_ids() ), true ) ) {
				throw new Exception( 'Import job is outside your authorized branch scope.' );
			}
			if ( ! in_array( $job['status'], array( 'READY', 'PARTIAL' ), true ) || (int) $job['processed_rows'] > 0 ) {
				throw new Exception( 'Only unreleased Daily POS rows can be edited.' );
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'import_rows' ) . ' WHERE id=%d AND job_id=%d LIMIT 1', $row_id, $job_id ), ARRAY_A );
			if ( ! $row ) {
				throw new Exception( 'Import row not found.' );
			}
			if ( $row['status'] !== 'VALID' ) {
				throw new Exception( 'Only valid staged rows can be edited here. Correct the source file and re-upload rows that failed validation.' );
			}
			$sku     = (string) $row['sku'];
			$product = Stockland_DB::get_product( $sku );
			if ( ! $product ) {
				throw new Exception( 'SKU ' . $sku . ' no longer exists in Product Master.' );
			}
			$qty      = $this->qty( $p['quantity'] ?? $row['quantity'] );
			$price    = $this->money( $p['selling_price'] ?? $row['selling_price'] );
			$vat_mode = sanitize_text_field( (string) ( $p['vat_mode'] ?? $row['vat_mode'] ) );
			$receipt  = sanitize_text_field( (string) ( $p['external_receipt_no'] ?? $row['external_receipt_no'] ) );
			if ( $qty === null || $qty <= 0 ) {
				throw new Exception( 'Quantity must be greater than zero.' );
			}if ( $price === null || $price <= 0 ) {
				throw new Exception( 'Selling price must be greater than zero.' );
			}if ( $receipt === '' ) {
				throw new Exception( 'Receipt / transaction reference is required.' );
			}if ( ! in_array( $vat_mode, array( 'VAT_INCLUDED', 'WITHOUT_VAT' ), true ) ) {
				throw new Exception( 'Select a valid VAT mode.' );
			}
			$rate        = (float) Stockland_DB::get_vat_rate();
			$calc        = Stockland_Sale_Math::calculate( $qty, $price, $vat_mode, $rate );
			$vat_amount  = $calc['vat_amount'];
			$sales_total = $calc['sales_total'];
			$upd         = array(
				'external_receipt_no' => $receipt,
				'quantity'            => $qty,
				'selling_price'       => $price,
				'vat_mode'            => $vat_mode,
				'vat_rate'            => $rate,
				'vat_amount'          => $vat_amount,
				'sales_total'         => $sales_total,
				'master_price'        => Stockland_Money::decimal( Stockland_Money::minor( $product['retail_price'] ) ),
				'cost_basis'          => Stockland_Money::decimal( Stockland_Money::minor( $product['purchase_cost'] ) ),
				'cost_basis_source'   => 'PRODUCT_MASTER_EDIT',
			);
			if ( false === $wpdb->update(
				Stockland_DB::table( 'import_rows' ),
				$upd,
				array(
					'id'     => $row_id,
					'job_id' => $job_id,
				)
			) ) {
				throw new Exception( $wpdb->last_error ?: 'Could not save the staged row edit.' );
			}
			$agg = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) total_rows,SUM(status='VALID') valid_rows,SUM(status='ERROR') error_rows,SUM(COALESCE(quantity,0)) units,COALESCE(SUM(CASE WHEN status='VALID' THEN sales_total ELSE 0 END),0) gross,COALESCE(SUM(CASE WHEN status='VALID' THEN vat_amount ELSE 0 END),0) vat FROM " . Stockland_DB::table( 'import_rows' ) . ' WHERE job_id=%d', $job_id ), ARRAY_A );
			if ( false === $wpdb->update(
				Stockland_DB::table( 'import_jobs' ),
				array(
					'total_rows' => (int) $agg['total_rows'],
					'valid_rows' => (int) $agg['valid_rows'],
					'error_rows' => (int) $agg['error_rows'],
					'message'    => 'Staged Daily POS row edited. Branch Manager must RELEASE TO ERP.',
				),
				array( 'id' => $job_id )
			) ) {
				throw new Exception( $wpdb->last_error ?: 'Could not refresh Daily POS job summary.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $job['branch_location_id'], 'DAILY_POS_STAGE_EDIT', $sku, $qty, $row['selling_price'], $price, 'Staged Daily POS row edited before release', $job_id, get_current_user_id() ) ) {
				throw new Exception( 'Audit event could not be recorded.' );
			}
			return rest_ensure_response(
				array(
					'job_id'  => $job_id,
					'row_id'  => $row_id,
					'status'  => 'READY',
					'summary' => $agg,
					'message' => 'Staged row saved. Nothing has posted. RELEASE TO ERP when the Branch Manager has completed review.',
				)
			);
		} catch ( Throwable $e ) {
			return new WP_Error( 'daily_edit_row_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/** Batch-read Product Master identities so large imports avoid one SQL lookup per workbook row. */
	private function product_lookup_bulk( $skus ) {
		global $wpdb;
		$skus = array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $v ) {
							return sanitize_text_field( (string) $v );
						},
						(array) $skus
					)
				)
			)
		);
		$out  = array();
		foreach ( array_chunk( $skus, 400 ) as $chunk ) {
			if ( ! $chunk ) {
				continue;
			}
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql  = 'SELECT variant_id,sku,product_name,product_type,parent_sku,retail_price,purchase_cost,wholesale_price,is_active FROM ' . Stockland_DB::table( 'products' ) . " WHERE sku IN({$ph})";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );
			if ( $wpdb->last_error ) {
				throw new Exception( 'Could not batch-read Product Master identities.' );
			}
			foreach ( (array) $rows as $row ) {
				$out[ (string) $row['sku'] ] = $row;
			}
		}
		return $out;
	}

	/** Batch-resolve third-party POS codes and their live Product Master rows for mass uploads. */
	private function daily_resolution_bulk( $codes, $business_date ) {
		global $wpdb;
		$codes       = array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $v ) {
							return sanitize_text_field( (string) $v );
						},
						(array) $codes
					)
				)
			)
		);
		$resolved    = array();
		$mapped_skus = array();
		foreach ( $codes as $code ) {
			$resolved[ $code ] = array( null, null );
		}
		$maps = Stockland_DB::table( 'product_code_maps' );
		foreach ( array_chunk( $codes, 400 ) as $chunk ) {
			if ( ! $chunk ) {
				continue;
			}
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$args = array( 'THIRD_PARTY_POS' );
			foreach ( $chunk as $c ) {
				$args[] = $c;
			}$args[] = $business_date;
			$args[]  = $business_date;
			$sql     = "SELECT external_code,live_sku,effective_from,id FROM {$maps} WHERE source_system=%s AND external_code IN({$ph}) AND is_active=1 AND (effective_from IS NULL OR effective_from<=%s) AND (effective_to IS NULL OR effective_to>=%s) ORDER BY external_code ASC,effective_from DESC,id DESC";
			$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			if ( $wpdb->last_error ) {
				throw new Exception( 'Could not batch-read POS code mappings.' );
			}
			foreach ( (array) $rows as $row ) {
				$code = (string) $row['external_code'];
				if ( isset( $resolved[ $code ] ) && $resolved[ $code ][0] === null ) {
					$live              = (string) $row['live_sku'];
					$resolved[ $code ] = array( $live, 'CODE_MAP' );
					if ( $live !== '' ) {
						$mapped_skus[] = $live;
					}
				}
			}
		}
		$candidate = array_merge( $codes, $mapped_skus );
		$products  = $this->product_lookup_bulk( $candidate );
		foreach ( $codes as $code ) {
			$live = (string) ( $resolved[ $code ][0] ?? '' );
			if ( $live !== '' && ! empty( $products[ $live ] ) && (int) $products[ $live ]['is_active'] === 1 ) {
				continue;
			}
			if ( isset( $products[ $code ] ) && (int) $products[ $code ]['is_active'] === 1 ) {
				$resolved[ $code ] = array( $code, 'LIVE_SKU' );
			} else {
				$resolved[ $code ] = array( null, null );
			}
		}
		return array(
			'map'      => $resolved,
			'products' => $products,
		);
	}

	/** Stage validated import rows in bounded multi-row INSERTs to avoid one SQL round trip per row. */
	private function import_sql_literal( $value, $format = '%s' ) {
		global $wpdb;
		if ( $value === null ) {
			return 'NULL';
		}
		return $wpdb->prepare( $format, $value );
	}
	private function stage_import_rows( $job_id, $rows, $type ) {
		global $wpdb;
		$job_id = (int) $job_id;
		$rows   = array_values( (array) $rows );
		if ( ! $job_id || ! $rows ) {
			return true;
		}
		$table = Stockland_DB::table( 'import_rows' );
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				if ( $type === 'PRODUCT_MASTER' ) {
					$values[] = '(' . implode(
						',',
						array(
							$this->import_sql_literal( $job_id, '%d' ),
							$this->import_sql_literal( (int) $row['row_number'], '%d' ),
							$this->import_sql_literal( $row['sku'] ?: null, '%s' ),
							$this->import_sql_literal( $row['sku'] ?: null, '%s' ),
							$this->import_sql_literal( $row['sku'] ?: null, '%s' ),
							'NULL',
							$this->import_sql_literal( Stockland_Money::decimal( Stockland_Money::minor( $row['retail_price'] ) ), '%s' ),
							$this->import_sql_literal( Stockland_Money::decimal( Stockland_Money::minor( $row['retail_price'] ) ), '%s' ),
							$this->import_sql_literal( 'PRODUCT|' . $job_id . '|' . $row['row_number'], '%s' ),
							$this->import_sql_literal( $row['payload'], '%s' ),
							$this->import_sql_literal( $row['status'], '%s' ),
							$this->import_sql_literal( $row['error'] ?: null, '%s' ),
						)
					) . ')';
				} else {
					$values[] = '(' . implode(
						',',
						array(
							$this->import_sql_literal( $job_id, '%d' ),
							$this->import_sql_literal( (int) $row['row_number'], '%d' ),
							$this->import_sql_literal( $row['external_receipt_no'] ?: null, '%s' ),
							$this->import_sql_literal( $row['source_code'] ?: null, '%s' ),
							$this->import_sql_literal( $row['sku'] ?: null, '%s' ),
							$this->import_sql_literal( $row['mapped_sku'] ?: null, '%s' ),
							$this->import_sql_literal( $row['mapped_via'] ?: null, '%s' ),
							$this->import_sql_literal( $row['variant_id'] ? ( (int) $row['variant_id'] ) : null, '%d' ),
							$this->import_sql_literal( $row['quantity'] === null ? null : (float) $row['quantity'], '%f' ),
							$this->import_sql_literal( $row['master_price'] === null ? null : Stockland_Money::decimal( Stockland_Money::minor( $row['master_price'] ) ), '%s' ),
							$this->import_sql_literal( $row['selling_price'] === null ? null : Stockland_Money::decimal( Stockland_Money::minor( $row['selling_price'] ) ), '%s' ),
							$this->import_sql_literal( $row['vat_rate'] === null ? null : (float) $row['vat_rate'], '%f' ),
							$this->import_sql_literal( $row['vat_amount'] === null ? null : Stockland_Money::decimal( Stockland_Money::minor( $row['vat_amount'] ) ), '%s' ),
							$this->import_sql_literal( $row['vat_mode'] ?: null, '%s' ),
							$this->import_sql_literal( $row['sales_total'] === null ? null : Stockland_Money::decimal( Stockland_Money::minor( $row['sales_total'] ) ), '%s' ),
							$this->import_sql_literal( $row['cost_basis'] === null ? null : Stockland_Money::decimal( Stockland_Money::minor( $row['cost_basis'] ) ), '%s' ),
							$this->import_sql_literal( $row['cost_basis_source'] ?: null, '%s' ),
							$this->import_sql_literal( $row['source_key'], '%s' ),
							$this->import_sql_literal( $row['payload'], '%s' ),
							$this->import_sql_literal( $row['status'], '%s' ),
							$this->import_sql_literal( $row['error_message'] ?: null, '%s' ),
						)
					) . ')';
				}
			}
			if ( $type === 'PRODUCT_MASTER' ) {
				$sql = "INSERT INTO {$table} (job_id,source_row_number,source_code,mapped_sku,sku,quantity,master_price,selling_price,source_key,payload,status,error_message) VALUES " . implode( ',', $values );
			} else {
				$sql = "INSERT INTO {$table} (job_id,source_row_number,external_receipt_no,source_code,sku,mapped_sku,mapped_via,variant_id,quantity,master_price,selling_price,vat_rate,vat_amount,vat_mode,sales_total,cost_basis,cost_basis_source,source_key,payload,status,error_message) VALUES " . implode( ',', $values );
			}
			if ( false === $wpdb->query( $sql ) ) {
				throw new Exception( $wpdb->last_error ?: 'Could not stage import rows.' );
			}
		}
		return true;
	}

	private function product_rows( WP_REST_Request $r ) {
		Stockland_Runtime_Diagnostics::stage( 'product_master_upload_read' );
		$file  = $this->uploaded_file( 'file' );
		$xlsx  = new Stockland_XLSX( $file['path'] );
		$found = $this->scan_workbook( $xlsx, 'PRODUCT_MASTER', 'STOCKLAND_MASTER' );
		return array( $file, $found );}
	public function product_preview( WP_REST_Request $r ) {
		Stockland_Runtime_Diagnostics::stage( 'product_master_preview_entry' );
		global $wpdb;
		try {
			list($file, $found) = $this->product_rows( $r );
			$all_rows           = $found['rows'];
			$rows               = array_values(
				array_filter(
					$all_rows,
					function ( $src ) use ( $found ) {
						return ! $this->ignorable_row( $src, $found['mapping'], 'PRODUCT_MASTER' );
					}
				)
			);
			$ignored_rows       = count( $all_rows ) - count( $rows );
			if ( count( $rows ) > self::MAX_IMPORT_ROWS ) {
				throw new Exception( 'Product Master import exceeds ' . self::MAX_IMPORT_ROWS . ' rows. Split the workbook.' );
			}$hash    = hash_file( 'sha256', $file['path'] );
			$job_key  = hash( 'sha256', $hash . '|PRODUCT_MASTER' );
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'import_jobs' ) . ' WHERE import_key=%s LIMIT 1', $job_key ), ARRAY_A );
			if ( $existing && $existing['status'] !== 'CANCELLED' ) {
				return rest_ensure_response(
					array(
						'job_id'         => (int) $existing['id'],
						'job_key'        => $job_key,
						'status'         => $existing['status'],
						'summary'        => array(
							'rows'     => (int) $existing['total_rows'],
							'new'      => 0,
							'existing' => 0,
							'errors'   => (int) $existing['error_rows'],
						),
						'processed_rows' => (int) $existing['processed_rows'],
						'remaining'      => max( 0, (int) $existing['valid_rows'] - (int) $existing['processed_rows'] ),
						'message'        => 'This exact Product Master file already has an import job. Continue that job instead of staging it again.',
					)
				);
			}$map               = $found['mapping'];
			$new                = 0;
			$existing_count     = 0;
			$errors             = 0;
			$preview            = array();
			$staged             = array();
			$seen               = array();
			$product_cache      = array();
			$preflight_barcodes = array();
			$preflight_gtins    = array();
			$workbook_types     = array();
			$lookup_skus        = array();
			foreach ( $rows as $src ) {
				$psku              = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'sku' ) );
				$ptype             = strtoupper( sanitize_text_field( (string) $this->mapped_value( $src, $map, 'product_type' ) ) );
				$parent_for_lookup = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'parent_sku' ) );
				$old_for_lookup    = sanitize_text_field( (string) $this->find( $src, array( 'old_sku', 'previous_sku', 'replace_sku' ) ) );
				foreach ( array( $psku, $parent_for_lookup, $old_for_lookup ) as $look ) {
					if ( $look !== '' ) {
						$lookup_skus[] = $look;
					}
				}
				if ( $psku !== '' ) {
					$workbook_types[ $psku ] = $ptype !== '' ? $ptype : 'SIMPLE';
				}
				$bc = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'barcode' ) );
				if ( $bc !== '' ) {
					$preflight_barcodes[] = $bc;
				}
				$gi = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'gtin' ) );
				if ( $gi !== '' ) {
					$preflight_gtins[] = $gi;
				}
			}
			$identifier_owners = $this->product_identifier_owners( $preflight_barcodes, $preflight_gtins );
			$product_cache     = $this->product_lookup_bulk( $lookup_skus );
			$seen_barcodes     = array();
			$seen_gtins        = array();
			foreach ( $rows as $i => $src ) {
				$sku  = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'sku' ) );
				$name = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'product_name' ) );
				$old  = null;
				if ( $sku !== '' ) {
					$old = $product_cache[ $sku ] ?? null;
				}$err = array();
				if ( ! $sku ) {
					$err[] = 'Missing Code/SKU.';
				} elseif ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $sku ) ) {
					$err[] = 'Invalid Product Master SKU. Use 1–64 letters, numbers, dot, underscore or hyphen characters.';}
				foreach ( array( 'initial_stock', 'opening_stock', 'stock_quantity', 'quantity', 'qty', 'stock' ) as $qty_header ) {
					$raw_qty = $this->find_exact_source( $src, $qty_header );
					if ( $raw_qty !== null && trim( (string) $raw_qty ) !== '' ) {
						$err[] = 'Quantity field "' . $qty_header . '" is not permitted in Product Master import. Use Warehouse Receiving for physical stock.';
						break;
					}
				}if ( ! $name ) {
					$err[] = 'Missing Name/Product Name.';
				}if ( $sku && isset( $seen[ $sku ] ) ) {
					$err[] = 'Duplicate SKU in workbook';
				}if ( $sku ) {
					$seen[ $sku ] = 1;
				}$state_raw     = $this->mapped_value( $src, $map, 'state' );
				$state          = $this->parse_active_state( $state_raw );
				$old_sku        = sanitize_text_field( (string) $this->find( $src, array( 'old_sku', 'previous_sku', 'replace_sku' ) ) );
				$replace_reason = sanitize_text_field( (string) $this->find( $src, array( 'replace_reason', 'sku_change_reason', 'reason' ) ) );
				$ptype          = strtoupper( sanitize_text_field( (string) $this->mapped_value( $src, $map, 'product_type' ) ) );
				if ( $ptype === '' ) {
					$ptype = 'SIMPLE';
				}$parent = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'parent_sku' ) );
				$color   = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'color' ) );
				$size    = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'size' ) );
				$barcode = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'barcode' ) );
				$gtin    = sanitize_text_field( (string) $this->mapped_value( $src, $map, 'gtin' ) );
				if ( ! in_array( $ptype, array( 'STYLE', 'VARIANT', 'SIMPLE' ), true ) ) {
					$err[] = 'Invalid Product Type ' . $ptype . '. Use STYLE, VARIANT or SIMPLE.';
				}if ( $ptype === 'VARIANT' ) {
					if ( $parent === '' ) {
						$err[] = 'A VARIANT requires Parent SKU.';
					} else {
						$parent_product = $product_cache[ $parent ] ?? null;
						$parent_type    = $parent_product['product_type'] ?? ( $workbook_types[ $parent ] ?? '' );
						if ( $parent_type !== 'STYLE' ) {
							$err[] = 'Parent SKU ' . $parent . ' must reference a STYLE Product Master record.';
						}
					}if ( $color === '' || $size === '' ) {
						$err[] = 'A VARIANT requires both Color and Size.';
					}
				}if ( $barcode !== '' ) {
					if ( isset( $seen_barcodes[ $barcode ] ) ) {
						$err[] = 'Duplicate Barcode in workbook (also row ' . $seen_barcodes[ $barcode ] . ').';
					} else {
						$seen_barcodes[ $barcode ] = (int) ( $src['__excel_row'] ?? ( $i + 1 ) );
					}
					$owner = $identifier_owners['barcode'][ $barcode ] ?? null;
					if ( $owner && $owner !== $sku && $owner !== $old_sku ) {
						$err[] = 'Barcode ' . $barcode . ' is already assigned to SKU ' . $owner . '.';
					}
				}if ( $gtin !== '' ) {
					if ( isset( $seen_gtins[ $gtin ] ) ) {
						$err[] = 'Duplicate GTIN in workbook (also row ' . $seen_gtins[ $gtin ] . ').';
					} else {
						$seen_gtins[ $gtin ] = (int) ( $src['__excel_row'] ?? ( $i + 1 ) );
					}
					$owner = $identifier_owners['gtin'][ $gtin ] ?? null;
					if ( $owner && $owner !== $sku && $owner !== $old_sku ) {
						$err[] = 'GTIN ' . $gtin . ' is already assigned to SKU ' . $owner . '.';
					}
				}if ( $state_raw !== null && trim( (string) $state_raw ) !== '' && $state === null ) {
					$err[] = 'Invalid Active/Status value; use Active/Inactive or 1/0.';
				}
				foreach ( array(
					'purchase_cost'   => 'Purchase Cost',
					'retail_price'    => 'Retail Price',
					'wholesale_price' => 'Wholesale Price',
				) as $money_field => $label ) {
					$raw_money = $this->mapped_value( $src, $map, $money_field );
					if ( $raw_money !== null && trim( (string) $raw_money ) !== '' && $this->money( $raw_money ) === null ) {
						$err[] = $label . ' must be numeric.';
					}
					$money_value = $this->money( $raw_money );
					if ( $money_value !== null && $money_value < 0 ) {
						$err[] = $label . ' cannot be negative.';
					}
				}
				foreach ( array(
					'branch_reorder_level'  => 'Branch Reorder Level',
					'central_reorder_level' => 'Central Reorder Level',
				) as $qty_field => $label ) {
					$raw_reorder = $this->mapped_value( $src, $map, $qty_field );
					if ( $raw_reorder !== null && trim( (string) $raw_reorder ) !== '' && $this->qty( $raw_reorder ) === null ) {
						$err[] = $label . ' must be numeric.';
					}
					$qty_value = $this->qty( $raw_reorder );
					if ( $qty_value !== null && $qty_value < 0 ) {
						$err[] = $label . ' cannot be negative.';
					}
				}
				$item      = array(
					'row_number'            => (int) ( $src['__excel_row'] ?? ( $i + 1 ) ),
					'sku'                   => $sku,
					'old_sku'               => $old_sku,
					'replace_reason'        => $replace_reason,
					'product_name'          => $name,
					'product_type'          => $ptype,
					'parent_sku'            => $parent,
					'style_code'            => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'style_code' ) ),
					'department'            => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'department' ) ),
					'collection_name'       => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'collection_name' ) ),
					'season'                => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'season' ) ),
					'material'              => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'material' ) ),
					'fit'                   => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'fit' ) ),
					'country_of_origin'     => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'country_of_origin' ) ),
					'image_url'             => esc_url_raw( $this->mapped_value( $src, $map, 'image_url' ) ),
					'barcode'               => $barcode,
					'gtin'                  => $gtin,
					'color'                 => $color,
					'color_code'            => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'color_code' ) ),
					'size'                  => $size,
					'size_code'             => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'size_code' ) ),
					'size_system'           => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'size_system' ) ),
					'unit'                  => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'unit' ) ),
					'sub_category'          => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'sub_category' ) ),
					'category'              => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'category' ) ),
					'brand'                 => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'brand' ) ),
					'gender'                => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'gender' ) ),
					'age_group'             => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'age_group' ) ),
					'purchase_cost'         => $this->money( $this->mapped_value( $src, $map, 'purchase_cost' ) ),
					'retail_price'          => $this->money( $this->mapped_value( $src, $map, 'retail_price' ) ),
					'wholesale_price'       => $this->money( $this->mapped_value( $src, $map, 'wholesale_price' ) ),
					'branch_reorder_level'  => $this->qty( $this->mapped_value( $src, $map, 'branch_reorder_level' ) ),
					'central_reorder_level' => $this->qty( $this->mapped_value( $src, $map, 'central_reorder_level' ) ),
					'source_default_value'  => $this->money( $this->mapped_value( $src, $map, 'source_default_value' ) ),
					'source_default_tax'    => sanitize_text_field( (string) $this->mapped_value( $src, $map, 'source_default_tax' ) ),
					'source_created_at'     => $this->parse_datetime( $this->mapped_value( $src, $map, 'source_created_at' ) ),
					'is_active'             => $state,
					'error'                 => implode( '; ', $err ),
					'action'                => $old_sku !== '' && $old_sku !== $sku ? 'SKU_REPLACE' : ( $old ? 'UPDATE' : 'INSERT' ),
				);
				$preview[] = $item;
				if ( $old ) {
					++$existing_count;
				} else {
					++$new;
				}
				if ( $err ) {
					++$errors;
				}$staged[] = $item + array(
					'status'  => $err ? 'ERROR' : 'VALID',
					'payload' => wp_json_encode( $src ),
				);
			}if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new Exception( 'Could not start Product Master preview transaction.' );
			}if ( $errors === 0 && ! $found['profile_id'] ) {
				$found['profile_id'] = $this->save_profile( 'PRODUCT_MASTER', 'STOCKLAND_MASTER', $found['headers'], $map, 'Stockland Product Master mapping' );
			}$ok = $wpdb->insert(
				Stockland_DB::table( 'import_jobs' ),
				array(
					'import_key'         => $job_key,
					'import_type'        => 'PRODUCT_MASTER',
					'branch_location_id' => null,
					'business_date'      => null,
					'source_filename'    => $file['name'],
					'source_sha256'      => $hash,
					'source_system'      => 'STOCKLAND_MASTER',
					'mapping_profile_id' => $found['profile_id'],
					'mapping_signature'  => $found['signature'],
					'status'             => $errors ? 'ERROR' : 'PREVIEW',
					'total_rows'         => count( $rows ),
					'valid_rows'         => count( $rows ) - $errors,
					'error_rows'         => $errors,
					'processed_rows'     => 0,
					'created_by'         => get_current_user_id(),
					'message'            => $errors ? 'Fix validation errors before commit.' : 'Preview ready for controlled Product Master commit.',
				)
			);
			if ( ! $ok ) {
				throw new Exception( 'Could not create the Product Master import job.' );
			}$job_id = (int) $wpdb->insert_id;
			$this->stage_import_rows( $job_id, $staged, 'PRODUCT_MASTER' );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Product Master preview commit failed.' );
			}return rest_ensure_response(
				array(
					'job_id'         => $job_id,
					'job_key'        => $job_key,
					'status'         => $errors ? 'ERROR' : 'PREVIEW',
					'sheet'          => $found['sheet'],
					'header_row'     => $found['header_row'],
					'mapping'        => array(
						'profile_id' => $found['profile_id'],
						'signature'  => $found['signature'],
						'fields'     => $map,
						'mode'       => $found['profile_id'] ? 'REMEMBERED_PROFILE' : 'AUTO_MATCHED',
					),
					'summary'        => array(
						'rows'         => count( $preview ),
						'new'          => $new,
						'existing'     => $existing_count,
						'errors'       => $errors,
						'ignored_rows' => $ignored_rows,
					),
					'preview'        => array_slice( $preview, 0, 200 ),
					'processed_rows' => 0,
					'remaining'      => count( $rows ) - $errors,
					'control_layers' => array(
						'layer_1_file_integrity'      => 'PASS',
						'layer_2_identity_mapping'    => $found['profile_id'] ? 'PASS_REMEMBERED_PROFILE' : 'PASS_AUTO_MAPPED',
						'layer_3_business_validation' => $errors ? 'BLOCKED_ERRORS_FOUND' : 'PASS',
						'layer_4_staging_commit_gate' => 'STAGED_ONLY_NO_AUTO_POST',
					),
					'message'        => $errors ? 'Fix errors before commit.' : ( ( $ignored_rows > 0 ) ? ( $ignored_rows . ' summary/footer row(s) were ignored. ' ) : '' ) . 'Mapping recognized. Product Master changes are staged; nothing becomes live until Management/Owner commits.',
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'product_import_preview_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	private function destination_tracking_rows( $job_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_row_number AS row_number,COALESCE(mapped_sku,sku) AS sku,variant_id,status FROM ' . Stockland_DB::table( 'import_rows' ) . " WHERE job_id=%d AND status='POSTED' ORDER BY source_row_number LIMIT %d",
				(int) $job_id,
				self::MAX_IMPORT_ROWS
			),
			ARRAY_A
		);
	}

	public function product_commit( WP_REST_Request $r ) {
		if ( ! $this->capability_product() ) {
			return $this->deny( 'forbidden', 'Only Management or Owner may import Product Master.' );
		}
		global $wpdb;
		try {
			$job_id = (int) $r->get_param( 'job_id' );
			if ( ! $job_id ) {
				throw new Exception( 'Product import job ID is required. Preview the workbook first.' );
			}
			$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'import_jobs' ) . " WHERE id=%d AND import_type='PRODUCT_MASTER' LIMIT 1", $job_id ), ARRAY_A );
			if ( ! $job ) {
				throw new Exception( 'Product import job not found.' );
			}
			if ( (int) $job['error_rows'] > 0 ) {
				throw new Exception( 'This Product Master import has validation errors. Correct the workbook and create a new preview.' );
			}
			$lock_key = 'stockland_product_import_lock_' . $job_id;
			if ( get_transient( $lock_key ) ) {
				throw new Exception( 'Another Product Master import request is processing this job. Please retry.' );
			}
			set_transient( $lock_key, 1, 90 );
			$db_lock = Stockland_DB::acquire_named_lock( 'product_import_' . $job_id, 10 );
			if ( ! $db_lock['ok'] ) {
				delete_transient( $lock_key );
				throw new Exception( 'Another Product Master import operation is processing this job. Please retry.' );}
			if ( $job['status'] === 'POSTED' ) {
				$tracking = $this->destination_tracking_rows( $job_id );
				delete_transient( $lock_key );
				Stockland_DB::release_named_lock( $db_lock );
				return rest_ensure_response(
					array(
						'job_id'               => $job_id,
						'status'               => 'POSTED',
						'processed_rows'       => $job['processed_rows'],
						'remaining'            => 0,
						'destination_tracking' => array(
							'rows'    => $tracking,
							'message' => 'Persisted Product Master destination identities.',
						),
						'message'              => 'Product Master import is already complete.',
					)
				); }
			$mapping = array();
			if ( ! empty( $job['mapping_profile_id'] ) ) {
				$profile = $wpdb->get_row( $wpdb->prepare( 'SELECT mapping_json FROM ' . Stockland_DB::table( 'import_profiles' ) . ' WHERE id=%d LIMIT 1', (int) $job['mapping_profile_id'] ), ARRAY_A );
				if ( $profile && ! empty( $profile['mapping_json'] ) ) {
					$mapping = json_decode( (string) $profile['mapping_json'], true );
				}
				if ( ! is_array( $mapping ) ) {
					$mapping = array();
				}
			}
			$candidate_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT *,source_row_number AS row_number FROM ' . Stockland_DB::table( 'import_rows' ) . " WHERE job_id=%d AND status='VALID' ORDER BY source_row_number ASC LIMIT %d", $job_id, self::MAX_IMPORT_ROWS ), ARRAY_A );
			$rows           = array();
			$variant_rows   = array();
			foreach ( (array) $candidate_rows as $candidate ) {
				$payload     = json_decode( (string) $candidate['payload'], true );
				$mapped_type = '';
				if ( is_array( $payload ) && ! empty( $mapping['product_type'] ) && array_key_exists( $mapping['product_type'], $payload ) ) {
					$mapped_type = strtoupper( sanitize_text_field( (string) $payload[ $mapping['product_type'] ] ) );
				} elseif ( is_array( $payload ) ) {
					foreach ( array( 'Product Type', 'product_type', 'Type', 'type' ) as $k ) {
						if ( array_key_exists( $k, $payload ) ) {
							$mapped_type = strtoupper( sanitize_text_field( (string) $payload[ $k ] ) );
												break; }
					}
				}
				if ( $mapped_type === 'VARIANT' ) {
					$variant_rows[] = $candidate;
				} else {
					$rows[] = $candidate;
				}
				if ( count( $rows ) >= self::PRODUCT_CHUNK ) {
					break;
				}
			}
			if ( count( $rows ) < self::PRODUCT_CHUNK ) {
				foreach ( $variant_rows as $candidate ) {
					$rows[] = $candidate;
					if ( count( $rows ) >= self::PRODUCT_CHUNK ) {
						break;
					}
				}
			}
			if ( empty( $rows ) ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT *,source_row_number AS row_number FROM ' . Stockland_DB::table( 'import_rows' ) . " WHERE job_id=%d AND status='VALID' ORDER BY source_row_number ASC LIMIT %d", $job_id, self::PRODUCT_CHUNK ), ARRAY_A );
			}
			if ( empty( $rows ) ) {
				$wpdb->update(
					Stockland_DB::table( 'import_jobs' ),
					array(
						'status'         => 'POSTED',
						'processed_rows' => $job['valid_rows'],
						'approved_by'    => get_current_user_id(),
						'message'        => 'Product Master import completed.',
						'completed_at'   => current_time( 'mysql' ),
					),
					array( 'id' => $job_id )
				);
				delete_transient( $lock_key );
				Stockland_DB::release_named_lock( $db_lock );
				return rest_ensure_response(
					array(
						'job_id'               => $job_id,
						'status'               => 'POSTED',
						'processed_rows'       => $job['valid_rows'],
						'remaining'            => 0,
						'destination_tracking' => array(
							'rows'    => array(),
							'message' => 'No remaining staged Product Master rows.',
						),
						'message'              => 'Product Master import completed.',
					)
				);
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new Exception( 'Could not start Product Master import transaction.' );
			}
			$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'import_jobs' ) . " WHERE id=%d AND import_type='PRODUCT_MASTER' LIMIT 1 FOR UPDATE", $job_id ), ARRAY_A );
			if ( ! $job ) {
				throw new Exception( 'Product import job disappeared before commit.' );
			}
			if ( in_array( $job['status'], array( 'POSTED', 'CANCELLED', 'ERROR' ), true ) ) {
				if ( $job['status'] === 'POSTED' ) {
					if ( false === $wpdb->query( 'COMMIT' ) ) {
						throw new Exception( 'Product Master already-posted verification commit failed.' );
					} $tracking = $this->destination_tracking_rows( $job_id );
					delete_transient( $lock_key );
					Stockland_DB::release_named_lock( $db_lock );
					return rest_ensure_response(
						array(
							'job_id'               => $job_id,
							'status'               => 'POSTED',
							'processed_rows'       => $job['processed_rows'],
							'remaining'            => 0,
							'destination_tracking' => array(
								'rows'    => $tracking,
								'message' => 'Persisted Product Master destination identities.',
							),
							'message'              => 'Product Master import is already complete.',
						)
					); }
				throw new Exception( 'Product Master job is not commit-ready. Current status: ' . sanitize_text_field( $job['status'] ) . '.' );
			}
			$inserted       = 0;
			$updated        = 0;
			$committed_rows = array();
			foreach ( $rows as $row ) {
				$src = json_decode( (string) $row['payload'], true );
				if ( ! is_array( $src ) ) {
					throw new Exception( 'Invalid staged Product Master row #' . $row['row_number'] . '.' );
				}
				$mapped = function ( $field ) use ( $src, $mapping ) {
					if ( ! empty( $mapping[ $field ] ) && array_key_exists( $mapping[ $field ], $src ) ) {
						return $src[ $mapping[ $field ] ];
					}
					return null;
				};
				// New jobs carry an explicit mapping profile. Legacy staged jobs without one
				// are deliberately limited to canonical field names; never fuzzy-match here,
				// because e.g. "Color" and "Color Code" must remain distinct fields.
				$value          = function ( $field, $canonical = array() ) use ( $mapped, $src ) {
					$v = $mapped( $field );
					if ( $v !== null ) {
						return $v;
					}
					foreach ( $canonical as $name ) {
						if ( array_key_exists( $name, $src ) ) {
							return $src[ $name ];
						}
					}
					return null;
				};
				$sku            = sanitize_text_field( (string) $value( 'sku', array( 'SKU', 'sku', 'product_code', 'code', 'new_sku' ) ) );
				$name           = sanitize_text_field( (string) $value( 'product_name', array( 'Product Name', 'product_name', 'product', 'name' ) ) );
				$old_sku        = sanitize_text_field( (string) $value( 'old_sku', array( 'Old SKU', 'old_sku', 'previous_sku', 'replace_sku' ) ) );
				$replace_reason = sanitize_text_field( (string) $value( 'replace_reason', array( 'Replacement Reason', 'replace_reason', 'sku_change_reason', 'replacement_reason' ) ) );
				if ( ! $sku || ! $name ) {
					throw new Exception( 'Invalid Product Master row #' . $row['row_number'] . '.' );
				}
				if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $sku ) ) {
					throw new Exception( 'Invalid Product Master SKU ' . $sku . '. Use 1–64 letters, numbers, dot, underscore or hyphen characters.' );
				}
				if ( $old_sku !== '' && $old_sku !== $sku ) {
					$prior = Stockland_DB::get_product( $old_sku );
					if ( ! $prior ) {
						throw new Exception( 'SKU replacement source ' . $old_sku . ' was not found on Product Master row #' . $row['row_number'] . '.' );
					}
					Stockland_Product_Service::replace_sku( (int) $prior['variant_id'], $sku, $replace_reason ?: 'Product Master XLSX SKU replacement', 'PRODUCT_IMPORT|' . $job_id . '|' . $row['row_number'], false );
				}
				$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'products' ) . ' WHERE sku=%s LIMIT 1 FOR UPDATE', $sku ), ARRAY_A );
				if ( $wpdb->last_error ) {
					throw new Exception( 'Could not read existing Product Master row ' . $sku . '.' );
				}
				$destination_variant_id = (int) ( $old['variant_id'] ?? 0 );
				$destination_action     = $old ? 'UPDATE' : 'INSERT';
				// Defense-in-depth: the commit phase revalidates the staged payload instead of
				// trusting that a previously validated preview row is still semantically safe.
				foreach ( (array) $src as $source_key => $source_value ) {
					if ( $source_key === '__excel_row' || $source_value === null || trim( (string) $source_value ) === '' ) {
						continue;
					}
					if ( in_array( $this->norm( $source_key ), array( 'initial_stock', 'opening_stock', 'stock_quantity', 'quantity', 'qty', 'stock' ), true ) ) {
						throw new Exception( 'Product Master commit rejected physical stock field "' . sanitize_text_field( (string) $source_key ) . '". Use Warehouse Receiving for quantities.' );
					}
				}
				$data   = array(
					'sku'          => $sku,
					'product_name' => $name,
				);
				$fields = array(
					'product_type'          => array( 'product_type', 'type' ),
					'parent_sku'            => array( 'parent_sku', 'parent_style_sku', 'style_sku' ),
					'style_code'            => array( 'style_code', 'style', 'style_no', 'style_number' ),
					'brand'                 => array( 'brand', 'brand_name' ),
					'department'            => array( 'department', 'dept' ),
					'sub_category'          => array( 'sub_category', 'subcategory', 'child_category', 'child' ),
					'collection_name'       => array( 'collection_name', 'collection' ),
					'season'                => array( 'season', 'season_name' ),
					'material'              => array( 'material', 'fabric', 'material_fabric' ),
					'fit'                   => array( 'fit' ),
					'country_of_origin'     => array( 'country_of_origin', 'origin', 'country' ),
					'image_url'             => array( 'image_url', 'image', 'image_link', 'photo_url' ),
					'color'                 => array( 'color', 'colour' ),
					'color_code'            => array( 'color_code', 'colour_code', 'colourcode' ),
					'size'                  => array( 'size', 'size_name' ),
					'size_code'             => array( 'size_code', 'size_no', 'size_number' ),
					'size_system'           => array( 'size_system', 'sizing_system', 'size_scale' ),
					'barcode'               => array( 'barcode', 'ean', 'upc', 'bar_code' ),
					'gtin'                  => array( 'gtin', 'gtin_code' ),
					'category'              => array( 'category', 'category_style', 'parent_category' ),
					'gender'                => array( 'gender' ),
					'age_group'             => array( 'age_group', 'age' ),
					'unit'                  => array( 'unit', 'uom', 'unit_of_measure' ),
					'purchase_cost'         => array( 'purchase_cost', 'purchase_cost_etb_management_only', 'cost_price', 'cost', 'unit_cost' ),
					'retail_price'          => array( 'retail_price', 'retail_price_etb', 'master_price', 'selling_price' ),
					'wholesale_price'       => array( 'wholesale_price', 'wholesale_price_etb', 'wholesale' ),
					'branch_reorder_level'  => array( 'branch_reorder_level', 'branch_reorder_level_pcs', 'branch_reorder', 'reorder_level' ),
					'central_reorder_level' => array( 'central_reorder_level', 'central_reorder_level_pcs', 'central_reorder' ),
					'notes'                 => array( 'notes', 'note' ),
				);
				foreach ( $fields as $field => $aliases ) {
					$raw = $mapped( $field );
					if ( $raw === null ) {
						foreach ( $aliases as $alias ) {
							if ( array_key_exists( $alias, $src ) ) {
								$raw = $src[ $alias ];
								break; }
						}
					}
					if ( $raw === null || $raw === '' ) {
						continue;
					}
					if ( in_array( $field, array( 'purchase_cost', 'retail_price', 'wholesale_price' ), true ) ) {
						$value = $this->money( $raw );
						if ( $value === null ) {
							continue;
						}
					} elseif ( in_array( $field, array( 'branch_reorder_level', 'central_reorder_level' ), true ) ) {
						$value = (int) $this->qty( $raw ); } else {
										$value = sanitize_text_field( (string) $raw ); }
						$data[ $field ] = $value;
				}
				$effective_type = strtoupper( sanitize_text_field( (string) ( $data['product_type'] ?? ( $old['product_type'] ?? 'SIMPLE' ) ) ) );
				if ( ! in_array( $effective_type, array( 'STYLE', 'VARIANT', 'SIMPLE' ), true ) ) {
					throw new Exception( 'Invalid Product Type ' . $effective_type . ' for ' . $sku . '.' );
				}
				if ( $effective_type === 'VARIANT' ) {
					$effective_parent = sanitize_text_field( (string) ( $data['parent_sku'] ?? ( $old['parent_sku'] ?? '' ) ) );
					$effective_color  = sanitize_text_field( (string) ( $data['color'] ?? ( $old['color'] ?? '' ) ) );
					$effective_size   = sanitize_text_field( (string) ( $data['size'] ?? ( $old['size'] ?? '' ) ) );
					if ( $effective_parent === '' ) {
						throw new Exception( 'VARIANT ' . $sku . ' requires a Parent SKU.' );
					}
					$parent_product = Stockland_DB::get_product( $effective_parent );
					if ( ! $parent_product || ( $parent_product['product_type'] ?? '' ) !== 'STYLE' ) {
						throw new Exception( 'VARIANT ' . $sku . ' parent must reference an existing STYLE Product Master record.' );
					}
					if ( $effective_color === '' || $effective_size === '' ) {
						throw new Exception( 'VARIANT ' . $sku . ' requires both Color and Size.' );
					}
				}
				foreach ( array( 'barcode', 'gtin' ) as $identifier ) {
					$identifier_value = isset( $data[ $identifier ] ) ? sanitize_text_field( (string) $data[ $identifier ] ) : '';
					if ( $identifier_value === '' ) {
						continue;
					}
					$hit = $wpdb->get_row( $wpdb->prepare( 'SELECT sku,variant_id FROM ' . Stockland_DB::table( 'products' ) . " WHERE {$identifier}=%s AND sku<>%s LIMIT 1 FOR UPDATE", $identifier_value, $sku ), ARRAY_A );
					if ( $hit && (string) $hit['sku'] !== $old_sku ) {
						throw new Exception( ucfirst( $identifier ) . ' ' . $identifier_value . ' is already assigned to SKU ' . $hit['sku'] . '. Import aborted before committing this row.' );
					}
				}
				$dv = $value( 'source_default_value', array( 'Default Value', 'default_value', 'default', 'default_amount' ) );
				if ( $dv !== null && $dv !== '' ) {
					$data['source_default_value'] = $this->money( $dv );
				}
				$dtax = $value( 'source_default_tax', array( 'Default Tax', 'default_tax', 'default_vat', 'tax_default' ) );
				if ( $dtax !== null && $dtax !== '' ) {
					$data['source_default_tax'] = sanitize_text_field( (string) $dtax );
				}
				$created = $this->parse_datetime( $value( 'source_created_at', array( 'Created At', 'create_on', 'created_on', 'created_at', 'created' ) ) );
				if ( $created ) {
					$data['source_created_at'] = $created;
				}
				$state = $this->parse_active_state( $value( 'state', array( 'State', 'status', 'active' ) ) );
				if ( $state !== null ) {
					$data['is_active'] = $state;
				}
				// Do not fabricate omitted fields on an existing master row.
				// INSERT relies on the database defaults only when the workbook omits them.
				if ( $old ) {
					if ( false === $wpdb->update( Stockland_DB::table( 'products' ), $data, array( 'sku' => $sku ) ) ) {
						throw new Exception( 'Could not update ' . $sku . '.' );
					}
					foreach ( array( 'purchase_cost', 'retail_price', 'wholesale_price' ) as $field ) {
						if ( isset( $data[ $field ] ) && $data[ $field ] !== null && abs( (float) $data[ $field ] - (float) $old[ $field ] ) > 0.00001 ) {
							if ( false === $wpdb->insert(
								Stockland_DB::table( 'price_history' ),
								array(
									'variant_id' => (int) ( $old['variant_id'] ?? 0 ),
									'sku'        => $sku,
									'price_type' => $field,
									'old_value'  => $old[ $field ],
									'new_value'  => $data[ $field ],
									'changed_by' => get_current_user_id(),
								)
							) ) {
								throw new Exception( 'Could not record price history for ' . $sku . '.' );
							}
						}
					}
					++$updated;
					$action = 'PRODUCT_IMPORT_UPDATE';
				} else {
					if ( ! isset( $data['is_active'] ) ) {
						$data['is_active'] = 1;
					}
					if ( false === $wpdb->insert( Stockland_DB::table( 'products' ), $data ) ) {
						throw new Exception( 'Could not insert ' . $sku . '.' );
					}
					++$inserted;
					$destination_variant_id = (int) $wpdb->insert_id;
					$action                 = 'PRODUCT_IMPORT_INSERT';
				}
				if ( $destination_variant_id <= 0 ) {
					throw new Exception( 'Product Master row ' . $sku . ' has no authoritative destination variant_id after save.' );
				}
				if ( ! Stockland_Audit::log( get_current_user_id(), null, $action, $sku, null, $old ? 'existing' : 'none', 'imported', $job['source_filename'], 'PRODUCT_MASTER_IMPORT' ) ) {
					throw new Exception( 'Product import audit event failed.' );
				}
				$committed_rows[] = array(
					'row_number' => (int) $row['row_number'],
					'sku'        => $sku,
					'variant_id' => $destination_variant_id,
					'action'     => $destination_action,
				);
				if ( false === $wpdb->update(
					Stockland_DB::table( 'import_rows' ),
					array(
						'status'        => 'POSTED',
						'variant_id'    => $destination_variant_id,
						'mapped_sku'    => $sku,
						'posted_at'     => current_time( 'mysql' ),
						'error_message' => null,
					),
					array( 'id' => $row['id'] )
				) ) {
					throw new Exception( 'Could not mark Product Master import row #' . $row['row_number'] . ' as posted.' );
				}
			}
			$new_processed = (int) $job['processed_rows'] + count( $rows );
			$status        = $new_processed >= (int) $job['valid_rows'] ? 'POSTED' : 'PARTIAL';
			if ( false === $wpdb->update(
				Stockland_DB::table( 'import_jobs' ),
				array(
					'status'         => $status,
					'processed_rows' => $new_processed,
					'approved_by'    => get_current_user_id(),
					'message'        => sprintf( 'Processed %d of %d valid rows.', $new_processed, (int) $job['valid_rows'] ),
					'completed_at'   => $status === 'POSTED' ? current_time( 'mysql' ) : null,
				),
				array( 'id' => $job_id )
			) ) {
				throw new Exception( 'Could not update Product Master import job state.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Product Master import commit failed.' );
			}delete_transient( $lock_key );
			Stockland_DB::release_named_lock( $db_lock );
			return rest_ensure_response(
				array(
					'job_id'               => $job_id,
					'status'               => $status,
					'processed_rows'       => $new_processed,
					'remaining'            => max( 0, (int) $job['valid_rows'] - $new_processed ),
					'inserted'             => $inserted,
					'updated'              => $updated,
					'destination_tracking' => array(
						'rows'    => $committed_rows,
						'message' => 'Each committed row is linked to its authoritative Product Master variant_id.',
					),
					'message'              => $status === 'POSTED' ? 'Product Master import completed.' : 'Chunk committed. Continue to post the next chunk.',
				)
			);
		} catch ( Throwable $e ) {
			if ( isset( $wpdb ) ) {
				$wpdb->query( 'ROLLBACK' );
			}if ( isset( $lock_key ) ) {
				delete_transient( $lock_key );
			}if ( isset( $db_lock ) ) {
				Stockland_DB::release_named_lock( $db_lock );
			}return new WP_Error( 'product_import_commit_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	public function mapping_list( WP_REST_Request $r ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id,import_type,source_system,profile_name,header_signature,mapping_json,mapping_version,is_active,created_by,updated_by,created_at,updated_at FROM ' . Stockland_DB::table( 'import_profiles' ) . ' WHERE is_active=1 ORDER BY updated_at DESC LIMIT 100', ARRAY_A );
		foreach ( $rows as &$x ) {
			$x['mapping'] = json_decode( (string) $x['mapping_json'], true );
		}return rest_ensure_response( $rows );}
	public function mapping_save( WP_REST_Request $r ) {
		$p       = $r->get_json_params();
		$type    = sanitize_key( $p['import_type'] ?? 'DAILY_SALES' );
		$source  = sanitize_text_field( $p['source_system'] ?? 'THIRD_PARTY_POS' );
		$headers = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $p['headers'] ?? array() ) ) ) );
		$mapping = is_array( $p['mapping'] ?? null ) ? $p['mapping'] : array();
		if ( ! $headers || ! $mapping ) {
			return $this->deny( 'invalid_mapping', 'Headers and mapping are required.', 400 );
		}$id = $this->save_profile( $type, $source, $headers, $mapping, $p['profile_name'] ?? 'Management mapping' );
		return rest_ensure_response(
			array(
				'id'               => $id,
				'header_signature' => $this->header_signature( $headers ),
				'mapping'          => $mapping,
				'message'          => 'Import mapping saved. Future matching files will reuse this profile automatically.',
			)
		);}
	public function code_map_list( WP_REST_Request $r ) {
		global $wpdb;
		$source = sanitize_text_field( $r->get_param( 'source_system' ) ?: 'THIRD_PARTY_POS' );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT m.id,m.source_system,m.external_code,m.live_sku,m.source_name,m.mapping_note,m.effective_from,m.effective_to,m.is_active,p.product_name FROM ' . Stockland_DB::table( 'product_code_maps' ) . ' m LEFT JOIN ' . Stockland_DB::table( 'products' ) . ' p ON (p.variant_id=m.variant_id OR (m.variant_id IS NULL AND p.sku=m.live_sku)) WHERE m.source_system=%s ORDER BY m.external_code LIMIT 500', $source ), ARRAY_A );
		return rest_ensure_response( $rows );}
	public function code_map_save( WP_REST_Request $r ) {
		global $wpdb;
		$p        = $r->get_json_params();
		$source   = sanitize_text_field( $p['source_system'] ?? 'THIRD_PARTY_POS' );
		$external = sanitize_text_field( $p['external_code'] ?? '' );
		$live     = sanitize_text_field( $p['live_sku'] ?? '' );
		if ( $external === '' || $live === '' ) {
			return $this->deny( 'invalid_mapping', 'External code and live SKU are required.', 400 );
		}
		if ( ! Stockland_DB::get_product( $live ) ) {
			return $this->deny( 'invalid_live_sku', 'The selected live SKU does not exist or is inactive.', 409 );
		}
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['effective_from'] ?? '' ) ) ? $p['effective_from'] : null;
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['effective_to'] ?? '' ) ) ? $p['effective_to'] : null;
		if ( $from && $to && $from > $to ) {
			return $this->deny( 'invalid_mapping_dates', 'Effective-from date cannot be after effective-to date.', 400 );
		}
		$id   = (int) ( $p['id'] ?? 0 );
		$maps = Stockland_DB::table( 'product_code_maps' );
		// No two active mappings for the same source code may cover the same date.
		// This keeps historical POS code changes deterministic.
		$args = array( $source, $external );
		$sql  = "SELECT id,effective_from,effective_to FROM {$maps} WHERE source_system=%s AND external_code=%s AND is_active=1";
		if ( $id ) {
			$sql   .= ' AND id<>%d';
			$args[] = $id;}
		$existing_rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		foreach ( $existing_rows as $ex ) {
			$ex_from = $ex['effective_from'] ?: null;
			$ex_to   = $ex['effective_to'] ?: null;
			$before  = ( $to !== null && $ex_from !== null && $to < $ex_from );
			$after   = ( $ex_to !== null && $from !== null && $ex_to < $from );
			if ( ! $before && ! $after ) {
				return $this->deny( 'mapping_overlap', 'The external code already has an active mapping covering the same effective date range. Close the previous range before creating a new mapping.', 409 );
			}
		}
		$live_product = Stockland_DB::get_product( $live );
		if ( ! $live_product || empty( $live_product['variant_id'] ) ) {
			return $this->deny( 'invalid_live_sku', 'The selected live SKU has no immutable variant identity.', 409 );
		}
		$data = array(
			'source_system'  => $source,
			'external_code'  => $external,
			'live_sku'       => $live,
			'variant_id'     => (int) $live_product['variant_id'],
			'source_name'    => sanitize_text_field( $p['source_name'] ?? '' ),
			'mapping_note'   => sanitize_text_field( $p['mapping_note'] ?? '' ),
			'effective_from' => $from,
			'effective_to'   => $to,
			'is_active'      => isset( $p['is_active'] ) ? (int) (bool) $p['is_active'] : 1,
			'updated_by'     => get_current_user_id(),
		);
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start code-mapping transaction.', array( 'status' => 500 ) );
		}
		try {
			if ( $id ) {
				$current = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$maps} WHERE id=%d LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
				if ( ! $current ) {
					throw new Exception( 'Mapping not found.' );
				}
				$ok = $wpdb->update( $maps, $data, array( 'id' => $id ) );
				if ( $ok === false ) {
					throw new Exception( 'Could not update mapping.' );
				}$existing = $id;
			} else {
				$same_open = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$maps} WHERE source_system=%s AND external_code=%s AND effective_from IS NULL AND effective_to IS NULL AND is_active=1 LIMIT 1 FOR UPDATE", $source, $external ) );
				if ( $same_open ) {
					$ok = $wpdb->update( $maps, $data, array( 'id' => (int) $same_open ) );
					if ( $ok === false ) {
						throw new Exception( 'Could not update existing default mapping.' );
					}$existing = (int) $same_open;
				} else {
					$data['created_by'] = get_current_user_id();
					if ( false === $wpdb->insert( $maps, $data ) ) {
						throw new Exception( 'Could not save mapping.' );
					}$existing = (int) $wpdb->insert_id;}
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'PRODUCT_CODE_MAP', $live, null, $external, $live, 'External POS/ERP code mapped to live Stockland SKU', $source . '|' . $external, get_current_user_id() ) ) {
				throw new Exception( 'Audit event could not be recorded; code mapping was not committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Code mapping commit failed.' );
			}
			return rest_ensure_response( array( 'id' => (int) $existing ) + $data );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->deny( 'mapping_write_failed', $e->getMessage(), 409 );}
	}
	public function code_map_delete( WP_REST_Request $r ) {
		global $wpdb;
		$id = (int) $r['id'];
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start code-mapping deactivation.', array( 'status' => 500 ) );
		}try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Stockland_DB::table( 'product_code_maps' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
			if ( ! $row ) {
				throw new Exception( 'Mapping not found.' );
			}$ok = $wpdb->update(
				Stockland_DB::table( 'product_code_maps' ),
				array(
					'is_active'  => 0,
					'updated_by' => get_current_user_id(),
				),
				array( 'id' => $id )
			);
			if ( $ok === false ) {
				throw new Exception( 'Could not deactivate the mapping.' );
			}if ( ! Stockland_Audit::log( get_current_user_id(), null, 'PRODUCT_CODE_MAP_DEACTIVATE', $row['live_sku'], null, $row['external_code'], 'DEACTIVATED', 'External POS/ERP code mapping deactivated', $row['source_system'] . '|' . $row['external_code'], get_current_user_id() ) ) {
				throw new Exception( 'Audit event could not be recorded; mapping was not deactivated.' );
			}if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new Exception( 'Mapping deactivation commit failed.' );
			}return rest_ensure_response(
				array(
					'id'     => $id,
					'status' => 'DEACTIVATED',
				)
			);
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->deny( 'delete_failed', $e->getMessage(), 409 );}}
}
