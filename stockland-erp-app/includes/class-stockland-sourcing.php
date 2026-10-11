<?php
/**
 * Read-only stock sourcing suggestions built from ERP stock, sales and transfer data.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ERP-native stock sourcing service.
 *
 * Reads only authoritative Product Master, inventory ledger, active reservations,
 * branch sales and pending transfer receipts. It reports where stock can be
 * sought without creating or approving any ERP transaction.
 *
 * @package Stockland_ERP
 */
class Stockland_Sourcing {
	/**
	 * Proposes available sourcing locations for each requested stock item.
	 *
	 * This advisory service is read-only: it does not reserve, transfer, purchase
	 * or approve inventory.
	 *
	 * @param array $items Requested SKU/variant/quantity rows.
	 * @param int   $requesting_location_id Branch location requesting stock, or zero when unspecified.
	 * @return array Sourcing options and explanatory warnings for each item.
	 */
	public static function for_items( array $items, $requesting_location_id = 0 ) {
		global $wpdb;

		$requesting_location_id = (int) $requesting_location_id;
		if ( ! $items ) {
			return array();
		}

		$variant_ids = array();
		$skus        = array();
		foreach ( $items as $item ) {
			$variant_id = (int) ( $item['variant_id'] ?? 0 );
			$sku        = sanitize_text_field( (string) ( $item['sku'] ?? '' ) );
			if ( $variant_id > 0 ) {
				$variant_ids[] = $variant_id;
			}
			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}
		$variant_ids = array_values( array_unique( array_filter( $variant_ids ) ) );
		$skus        = array_values( array_unique( array_filter( $skus ) ) );
		if ( ! $variant_ids && ! $skus ) {
			return array();
		}

		$products_table  = Stockland_DB::table( 'products' );
		$locations_table = Stockland_DB::table( 'locations' );
		$tx_table        = Stockland_DB::table( 'transactions' );
		$res_table       = Stockland_DB::table( 'reservations' );
		$sales_table     = Stockland_DB::table( 'sales' );
		$transfer_table  = Stockland_DB::table( 'transfers' );

		$products = self::load_products( $products_table, $variant_ids, $skus );
		if ( null === $products ) {
			return self::unavailable_rows( $items, 'Product Master could not be read safely.' );
		}
		if ( ! $products ) {
			return self::identity_unresolved_rows( $items );
		}

		$locations = Stockland_DB::read_results(
			$wpdb->prepare(
				"SELECT id,location_name,location_type
                 FROM %i
                 WHERE is_active=1 AND location_type IN('Branch','Warehouse')
                 ORDER BY CASE WHEN location_type='Warehouse' THEN 0 ELSE 1 END,id",
				$locations_table
			),
			ARRAY_A
		);
		if ( $wpdb->last_error || ! $locations ) {
			return self::unavailable_rows( $items, 'Live stock-source locations could not be read safely.' );
		}

		$locations_by_id = array();
		$location_ids    = array();
		$central_id      = 0;
		foreach ( (array) $locations as $loc ) {
			$id                     = (int) $loc['id'];
			$locations_by_id[ $id ] = $loc;
			$location_ids[]         = $id;
			if ( 'Warehouse' === $loc['location_type'] && strcasecmp( (string) $loc['location_name'], 'Central Warehouse' ) === 0 ) {
				$central_id = $id;
			}
		}
		if ( ! $central_id ) {
			foreach ( (array) $locations as $loc ) {
				if ( 'Warehouse' === $loc['location_type'] ) {
					$central_id = (int) $loc['id'];
					break;
				}
			}
		}

		$physical = self::batch_location_variant_values(
			$tx_table,
			$products_table,
			$location_ids,
			$variant_ids,
			$skus,
			'quantity_signed',
			'physical_qty'
		);
		$reserved = self::batch_location_variant_values(
			$res_table,
			$products_table,
			$location_ids,
			$variant_ids,
			$skus,
			'quantity_reserved',
			'reserved_qty',
			" AND r.status='Active'"
		);
		if ( null === $physical || null === $reserved ) {
			return self::unavailable_rows( $items, 'Live stock availability could not be read safely.' );
		}

		$sales   = self::branch_sales_30d( $sales_table, $products_table, $requesting_location_id, $variant_ids, $skus );
		$pending = self::pending_branch_receipts( $transfer_table, $products_table, $requesting_location_id, $variant_ids, $skus );
		if ( null === $sales || null === $pending ) {
			return self::unavailable_rows( $items, 'Demand or pending-transfer data could not be read safely.' );
		}

		$coverage   = max( 1, (int) Stockland_DB::get_setting( 'advice_coverage_days', '14' ) );
		$safety_pct = max( 0, min( 2, (float) Stockland_DB::get_setting( 'advice_safety_pct', '0.20' ) ) );

		$product_for = static function ( $item ) use ( $products ) {
			$variant_id = (int) ( $item['variant_id'] ?? 0 );
			if ( $variant_id > 0 && isset( $products[ 'v:' . $variant_id ] ) ) {
				return $products[ 'v:' . $variant_id ];
			}
			$sku = sanitize_text_field( (string) ( $item['sku'] ?? '' ) );
			if ( '' !== $sku && isset( $products[ 's:' . $sku ] ) ) {
				return $products[ 's:' . $sku ];
			}
			return null;
		};

		$stock_for = static function ( $location_id, $product ) use ( $physical, $reserved ) {
			$key          = (int) $location_id . '|' . (int) $product['variant_id'];
			$physical_qty = (float) ( $physical[ $key ] ?? 0 );
			$reserved_qty = (float) ( $reserved[ $key ] ?? 0 );
			return max( 0, round( $physical_qty - $reserved_qty, 2 ) );
		};

		$out = array();
		foreach ( $items as $item ) {
			$product   = $product_for( $item );
			$requested = max( 0, (float) ( $item['quantity_requested'] ?? $item['quantity'] ?? 0 ) );

			if ( ! $product ) {
				$out[] = array(
					'item_id'   => (int) ( $item['id'] ?? 0 ),
					'sku'       => sanitize_text_field( (string) ( $item['sku'] ?? '' ) ),
					'requested' => $requested,
					'status'    => 'IDENTITY_UNRESOLVED',
					'sources'   => array(),
				);
				continue;
			}

			$variant_id       = (int) $product['variant_id'];
			$branch_available = $requesting_location_id > 0 ? $stock_for( $requesting_location_id, $product ) : 0;
			$pending_qty      = (float) ( $pending[ $variant_id ] ?? 0 );
			$sales_30d        = (float) ( $sales[ $variant_id ] ?? 0 );
			$avg_daily        = round( $sales_30d / 30, 4 );
			$reorder          = max( 0, (float) ( $product['branch_reorder_level'] ?? 0 ) );
			$coverage_target  = $avg_daily * $coverage * ( 1 + $safety_pct );
			$suggested        = max( 0, (int) ceil( max( $reorder, $coverage_target ) - $branch_available - $pending_qty ) );
			$need             = max( $requested, $suggested );

			$sources = array();
			foreach ( $locations_by_id as $location_id => $loc ) {
				if ( $location_id === $requesting_location_id ) {
					continue;
				}
				$available = $stock_for( $location_id, $product );
				if ( $available <= 0 ) {
					continue;
				}

				$type    = 'Warehouse' === $loc['location_type'] ? 'CENTRAL_WAREHOUSE' : 'PEER_BRANCH';
				$surplus = 'PEER_BRANCH' === $type ? max( 0, $available - $reorder ) : $available;
				if ( 'PEER_BRANCH' === $type && $reorder > 0 && $available <= $reorder ) {
					continue;
				}
				$claimable = min( $need, $surplus );
				if ( $claimable <= 0 ) {
					continue;
				}
				$sources[] = array(
					'location_id'       => $location_id,
					'location_name'     => $loc['location_name'],
					'location_type'     => $type,
					'available'         => $available,
					'surplus_available' => $surplus,
					'claimable'         => $claimable,
					'priority'          => 'CENTRAL_WAREHOUSE' === $type ? 1 : 2,
					'reason'            => 'CENTRAL_WAREHOUSE' === $type
						? 'Central Warehouse has live available stock for this SKU.'
						: 'Peer branch has stock above its Product Master reorder level; the system identifies it as a safer source for inter-branch seeking.',
				);
			}

			usort(
				$sources,
				static function ( $a, $b ) {
					if ( (int) $a['priority'] !== (int) $b['priority'] ) {
						return (int) $a['priority'] < (int) $b['priority'] ? -1 : 1;
					}
					if ( (float) $a['surplus_available'] !== (float) $b['surplus_available'] ) {
						return (float) $a['surplus_available'] > (float) $b['surplus_available'] ? -1 : 1;
					}
					if ( (float) $a['available'] !== (float) $b['available'] ) {
						return (float) $a['available'] > (float) $b['available'] ? -1 : 1;
					}
					return strcmp( (string) $a['location_name'], (string) $b['location_name'] );
				}
			);

			$central_available = 0;
			$peer_total        = 0;
			foreach ( $sources as $source ) {
				if ( (int) $source['location_id'] === $central_id ) {
					$central_available = (float) $source['available'];
				}
				if ( 'PEER_BRANCH' === $source['location_type'] ) {
					$peer_total += (float) $source['available'];
				}
			}

			$best   = $sources[0] ?? null;
			$status = $best
				? ( $best['claimable'] >= $need ? 'SUPPLY_AVAILABLE' : 'PARTIAL_SUPPLY' )
				: ( $need > 0 ? 'NO_EXTERNAL_STOCK' : 'STOCK_OK' );

			$out[] = array(
				'item_id'               => (int) ( $item['id'] ?? 0 ),
				'variant_id'            => $variant_id,
				'sku'                   => $product['sku'],
				'product_name'          => $product['product_name'],
				'requested'             => $requested,
				'branch'                => array(
					'location_id'   => $requesting_location_id,
					'available'     => $branch_available,
					'reorder_level' => $reorder,
				),
				'demand'                => array(
					'sales_30d'         => $sales_30d,
					'avg_daily_sales'   => $avg_daily,
					'pending_receipt'   => $pending_qty,
					'coverage_target'   => round( $coverage_target, 2 ),
					'suggested_request' => $suggested,
				),
				'central_available'     => $central_available,
				'peer_branch_available' => $peer_total,
				'recommended_source'    => $best,
				'sources'               => $sources,
				'status'                => $status,
				'model'                 => 'LIVE_RULE_BASED',
				'action_policy'         => 'INFORMATIONAL_ONLY. Existing transfer, reservation, approval and receiving workflow remains required for every stock movement.',
			);
		}

		return $out;
	}

	/**
	 * Loads Product Master records by immutable variant identity and SKU.
	 *
	 * @param string $products_table Prefixed Product Master table.
	 * @param array  $variant_ids Unique variant identifiers.
	 * @param array  $skus Candidate SKUs.
	 * @return array|null Product rows, or null when the authoritative read fails.
	 */
	private static function load_products( $products_table, array $variant_ids, array $skus ) {
		global $wpdb;
		$where = array();
		$args  = array();
		if ( $variant_ids ) {
			$where[] = 'p.variant_id IN(' . implode( ',', array_fill( 0, count( $variant_ids ), '%d' ) ) . ')';
			$args    = array_merge( $args, $variant_ids );
		}
		if ( $skus ) {
			$where[] = 'p.sku IN(' . implode( ',', array_fill( 0, count( $skus ), '%s' ) ) . ')';
			$args    = array_merge( $args, $skus );
		}
		if ( ! $where ) {
			return array();
		}

		$sql = "SELECT p.variant_id,p.sku,p.product_name,p.branch_reorder_level
                FROM %i p
                WHERE p.is_active=1 AND p.product_type<>'STYLE'
                  AND (" . implode( ' OR ', $where ) . ')';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query format contains only this bound table identifier and internally generated %d/%s placeholders; all variant IDs and SKUs are supplied as prepared values.
		$rows = Stockland_DB::read_results( $wpdb->prepare( $sql, array_merge( array( $products_table ), $args ) ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return null;
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ 'v:' . (int) $row['variant_id'] ] = $row;
			if ( ! empty( $row['sku'] ) ) {
				$out[ 's:' . $row['sku'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Aggregates a fixed numeric ledger/read-model field by location and variant.
	 *
	 * @param string $table Source table to aggregate.
	 * @param string $products_table Product Master table used for SKU compatibility.
	 * @param array  $location_ids Location IDs in the read scope.
	 * @param array  $variant_ids Immutable variant IDs in the requested scope.
	 * @param array  $skus Requested SKU fallbacks.
	 * @param string $sum_field Internal allow-listed numeric field to sum.
	 * @param string $output_field Output key for the aggregated value.
	 * @param string $extra Optional trusted SQL condition from fixed internal call sites.
	 * @return array Aggregated quantities by location and variant.
	 */
	private static function batch_location_variant_values( $table, $products_table, array $location_ids, array $variant_ids, array $skus, $sum_field, $output_field, $extra = '' ) {
		global $wpdb;
		if ( ! $location_ids || ( ! $variant_ids && ! $skus ) ) {
			return array();
		}

		$alias       = Stockland_DB::table( 'transactions' ) === $table ? 't' : 'r';
		$location_ph = implode( ',', array_fill( 0, count( $location_ids ), '%d' ) );
		$conditions  = array();
		$args        = $location_ids;

		if ( $variant_ids ) {
			$conditions[] = "{$alias}.variant_id IN(" . implode( ',', array_fill( 0, count( $variant_ids ), '%d' ) ) . ')';
			$args         = array_merge( $args, $variant_ids );
		}
		if ( $skus ) {
			$conditions[] = "({$alias}.variant_id IS NULL AND {$alias}.sku IN(" . implode( ',', array_fill( 0, count( $skus ), '%s' ) ) . '))';
			$args         = array_merge( $args, $skus );
		}

		$sql = "SELECT {$alias}.location_id,
                       COALESCE({$alias}.variant_id,p.variant_id) AS variant_id,
                       SUM({$alias}.{$sum_field}) AS {$output_field}
                FROM %i {$alias}
                LEFT JOIN %i p ON p.sku={$alias}.sku
                WHERE {$alias}.location_id IN({$location_ph})
                  {$extra}
                  AND (" . implode( ' OR ', $conditions ) . ")
                GROUP BY {$alias}.location_id,COALESCE({$alias}.variant_id,p.variant_id)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are bound separately; location/variant IDs and SKUs use generated placeholders with prepared values.
		$rows = Stockland_DB::read_results( $wpdb->prepare( $sql, array_merge( array( $table, $products_table ), $args ) ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$key         = (int) $row['location_id'] . '|' . (int) $row['variant_id'];
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + (float) $row[ $output_field ];
		}
		return $out;
	}

	/**
	 * Aggregates branch sales for the trailing 30-day sourcing window.
	 *
	 * @param string $sales_table Sales table.
	 * @param string $products_table Product Master table.
	 * @param int    $branch_id Branch location identifier.
	 * @param array  $variant_ids Requested immutable variant identifiers.
	 * @param array  $skus Requested SKU fallbacks.
	 * @return array Sold quantity by variant.
	 */
	private static function branch_sales_30d( $sales_table, $products_table, $branch_id, array $variant_ids, array $skus ) {
		global $wpdb;
		if ( $branch_id <= 0 || ( ! $variant_ids && ! $skus ) ) {
			return array();
		}

		$alias      = 's';
		$conditions = array();
		$args       = array(
			(int) $branch_id,
			gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' 00:00:00 UTC -29 days' ) ),
			current_time( 'Y-m-d' ),
		);
		if ( $variant_ids ) {
			$conditions[] = 's.variant_id IN(' . implode( ',', array_fill( 0, count( $variant_ids ), '%d' ) ) . ')';
			$args         = array_merge( $args, $variant_ids );
		}
		if ( $skus ) {
			$conditions[] = '(s.variant_id IS NULL AND s.sku IN(' . implode( ',', array_fill( 0, count( $skus ), '%s' ) ) . '))';
			$args         = array_merge( $args, $skus );
		}

		$sql = "SELECT COALESCE(s.variant_id,p.variant_id) AS variant_id,
                       SUM(s.quantity_sold) AS qty
                FROM %i s
                LEFT JOIN %i p ON p.sku=s.sku
                WHERE s.branch_location_id=%d
                  AND s.sale_date BETWEEN %s AND %s
                  AND s.posting_status='CLOSED'
                  AND (" . implode( ' OR ', $conditions ) . ')
                GROUP BY COALESCE(s.variant_id,p.variant_id)';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL shape is static except for generated IN placeholders; branch/date/variant/SKU values are bound and table names are bound identifiers.
		$rows = Stockland_DB::read_results( $wpdb->prepare( $sql, array_merge( array( $sales_table, $products_table ), $args ) ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return null;
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['variant_id'] ] = (float) $row['qty'];
		}
		return $out;
	}

	/**
	 * Aggregates in-flight quantities due to the branch from pending transfers.
	 *
	 * @param string $transfer_table Transfer table.
	 * @param string $products_table Product Master table.
	 * @param int    $branch_id Branch location identifier.
	 * @param array  $variant_ids Requested immutable variant identifiers.
	 * @param array  $skus Requested SKU fallbacks.
	 * @return array Pending inbound quantity by variant.
	 */
	private static function pending_branch_receipts( $transfer_table, $products_table, $branch_id, array $variant_ids, array $skus ) {
		global $wpdb;
		if ( $branch_id <= 0 || ( ! $variant_ids && ! $skus ) ) {
			return array();
		}

		$conditions = array();
		$args       = array( (int) $branch_id );
		if ( $variant_ids ) {
			$conditions[] = 't.variant_id IN(' . implode( ',', array_fill( 0, count( $variant_ids ), '%d' ) ) . ')';
			$args         = array_merge( $args, $variant_ids );
		}
		if ( $skus ) {
			$conditions[] = '(t.variant_id IS NULL AND t.sku IN(' . implode( ',', array_fill( 0, count( $skus ), '%s' ) ) . '))';
			$args         = array_merge( $args, $skus );
		}

		$sql = "SELECT COALESCE(t.variant_id,p.variant_id) AS variant_id,
                       SUM(GREATEST(COALESCE(t.dispatched_qty,t.quantity)-COALESCE(t.received_qty,0),0)) AS qty
                FROM %i t
                LEFT JOIN %i p ON p.sku=t.sku
                WHERE t.to_location_id=%d
                  AND t.status IN('Picked','In Transit')
                  AND (" . implode( ' OR ', $conditions ) . ')
                GROUP BY COALESCE(t.variant_id,p.variant_id)';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Transfer/product identifiers are bound separately; branch, variant, and SKU values remain prepared.
		$rows = Stockland_DB::read_results( $wpdb->prepare( $sql, array_merge( array( $transfer_table, $products_table ), $args ) ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return null;
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['variant_id'] ] = (float) $row['qty'];
		}
		return $out;
	}

	/**
	 * Returns a fail-closed advisory result when an authoritative read is unavailable.
	 *
	 * @param array  $items Requested sourcing items.
	 * @param string $message Safe user-facing reason no sourcing option was calculated.
	 * @return array Unavailable results, one per input item.
	 */
	private static function unavailable_rows( array $items, $message ) {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'item_id'   => (int) ( $item['id'] ?? 0 ),
				'sku'       => sanitize_text_field( (string) ( $item['sku'] ?? '' ) ),
				'requested' => max( 0, (float) ( $item['quantity_requested'] ?? $item['quantity'] ?? 0 ) ),
				'status'    => 'SOURCING_UNAVAILABLE',
				'sources'   => array(),
				'message'   => sanitize_text_field( (string) $message ),
			);
		}
		return $out;
	}

	/**
	 * Reports items whose identity cannot be resolved to an authoritative product.
	 *
	 * @param array $items Requested sourcing items.
	 * @param array $products Product rows resolved so far.
	 * @return array Identity-unresolved sourcing results.
	 */
	private static function identity_unresolved_rows( array $items, array $products = array() ) {
		$out = array();
		foreach ( $items as $item ) {
			$requested  = max( 0, (float) ( $item['quantity_requested'] ?? $item['quantity'] ?? 0 ) );
			$sku        = sanitize_text_field( (string) ( $item['sku'] ?? '' ) );
			$variant_id = (int) ( $item['variant_id'] ?? 0 );
			$resolved   = ( $variant_id > 0 && isset( $products[ 'v:' . $variant_id ] ) ) || ( '' !== $sku && isset( $products[ 's:' . $sku ] ) );
			if ( ! $resolved ) {
				$out[] = array(
					'item_id'   => (int) ( $item['id'] ?? 0 ),
					'sku'       => $sku,
					'requested' => $requested,
					'status'    => 'IDENTITY_UNRESOLVED',
					'sources'   => array(),
				);
			}
		}
		return $out;
	}
}
