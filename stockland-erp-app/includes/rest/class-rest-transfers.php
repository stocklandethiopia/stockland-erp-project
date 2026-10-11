<?php
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorized inventory-transfer REST workflows.
 *
 * @package Stockland_ERP
 */
class Stockland_REST_Transfers extends Stockland_REST_Base {
	const VALID_STATUSES = array( 'Requested', 'Approved', 'Reserved', 'Picked', 'In Transit', 'Received', 'Completed', 'Rejected', 'Cancelled' );

	/**
	 * Registers transfer route callbacks.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers transfer listing, creation and status-transition routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/transfers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_transfers' ),
					'permission_callback' => function () {
																return Stockland_Authority::assert_profile();},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_transfer' ),
					'permission_callback' => function () {
								return Stockland_Authority::can_warehouse_operate_requisition();
					},
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/transfers/(?P<transfer_id>[a-zA-Z0-9\-]+)/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_manage_transfers' ) ||
						current_user_can( 'stockland_approve_transfers' ) ||
						current_user_can( 'stockland_receive_transfer' );
				},
			)
		);
	}

	/**
	 * Loads one transfer with its source and destination location names.
	 *
	 * @param int $id Transfer identifier.
	 * @return array|null Transfer row or null when it does not exist.
	 */
	private function row( $id ) {
		global $wpdb;
		return Stockland_DB::read_row(
			$wpdb->prepare(
				'SELECT t.*,f.location_name from_location_name,to_l.location_name to_location_name,
                    COALESCE(p.product_name,t.sku) product_name,r.branch_location_id AS requisition_branch_location_id
             FROM %i t
             JOIN %i f ON f.id=t.from_location_id
             JOIN %i to_l ON to_l.id=t.to_location_id
             LEFT JOIN %i p ON p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku)
             LEFT JOIN %i r ON r.requisition_id=t.requisition_id
             WHERE t.transfer_id=%s',
				Stockland_DB::table( 'transfers' ),
				Stockland_DB::table( 'locations' ),
				Stockland_DB::table( 'locations' ),
				Stockland_DB::table( 'products' ),
				Stockland_DB::table( 'requisitions' ),
				$id
			),
			ARRAY_A
		);
	}

	/**
	 * Lists transfers within the current user's authorized location scope.
	 *
	 * @param WP_REST_Request $r REST request containing optional filters.
	 * @return mixed REST response with transfer rows or a WP_Error.
	 */
	public function list_transfers( WP_REST_Request $r ) {
		global $wpdb;
		$profile = Stockland_Roles::get_profile( get_current_user_id() );
		$sql     = 'SELECT t.*,f.location_name from_location_name,to_l.location_name to_location_name,
                     COALESCE(p.product_name,t.sku) product_name
              FROM %i t
              JOIN %i f ON f.id=t.from_location_id
              JOIN %i to_l ON to_l.id=t.to_location_id
              LEFT JOIN %i p ON p.variant_id=t.variant_id OR (t.variant_id IS NULL AND p.sku=t.sku)
              WHERE 1=1';
		$args    = array(
			Stockland_DB::table( 'transfers' ),
			Stockland_DB::table( 'locations' ),
			Stockland_DB::table( 'locations' ),
			Stockland_DB::table( 'products' ),
		);
		if ( ! current_user_can( 'stockland_view_all_branches' ) ) {
			$allowed = array_values( array_unique( array_map( 'intval', Stockland_Authority::location_ids() ) ) );
			if ( empty( $allowed ) ) {
				return rest_ensure_response( array() );
			}
			$ph   = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
			$sql .= ' AND (t.from_location_id IN (' . $ph . ') OR t.to_location_id IN (' . $ph . '))';
			foreach ( $allowed as $id ) {
				$args[] = $id;}
			foreach ( $allowed as $id ) {
				$args[] = $id;}
		}
		$sql .= ' ORDER BY t.created_at DESC LIMIT 500';
		return rest_ensure_response( Stockland_DB::read_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) );
	}

	/**
	 * Creates a transfer request after validating source, destination and quantities.
	 *
	 * @param WP_REST_Request $r REST request containing transfer header and lines.
	 * @return mixed REST response or a WP_Error.
	 * @throws Exception When the guarded transfer transaction cannot complete.
	 */
	public function create_transfer( WP_REST_Request $r ) {
		$p           = $r->get_json_params();
		$from        = (int) ( $p['from_location_id'] ?? 0 );
		$to          = (int) ( $p['to_location_id'] ?? 0 );
		$sku         = sanitize_text_field( $p['sku'] ?? '' );
		$q           = (float) ( $p['quantity'] ?? 0 );
		$req_id      = sanitize_text_field( $p['requisition_id'] ?? '' );
		$req_item_id = isset( $p['requisition_item_id'] ) ? (int) $p['requisition_item_id'] : null;

		if ( ! $from || ! $to || $to === $from || ! $sku || $q <= 0 ) {
			return new WP_Error( 'invalid', 'Invalid transfer.', array( 'status' => 400 ) );
		}

		if ( (int) Stockland_DB::get_location_id( 'Central Warehouse' ) === $to ) {
			return new WP_Error( 'invalid_destination', 'Transfers cannot be received back into Central Warehouse through a branch transfer.', array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'stockland_view_all_branches' ) && (int) Stockland_DB::get_location_id( 'Central Warehouse' ) !== $from ) {
			return new WP_Error( 'forbidden', 'Branch users cannot move stock out of arbitrary locations.', array( 'status' => 403 ) );
		}

		$product = Stockland_DB::get_product( $sku );
		if ( ! $product || ( $product['product_type'] ?? 'SIMPLE' ) === 'STYLE' ) {
			return new WP_Error( 'not_found', 'Select a stockable variant/SKU, not a style template.', array( 'status' => 404 ) );
		}

		if ( $req_id ) {
			global $wpdb;
			$exists = Stockland_DB::read_var(
				$wpdb->prepare(
					'SELECT requisition_id FROM %i WHERE requisition_id=%s',
					Stockland_DB::table( 'requisitions' ),
					$req_id
				)
			);
			if ( ! $exists ) {
				return new WP_Error( 'requisition_not_found', 'Linked requisition not found.', array( 'status' => 404 ) );
			}
			return new WP_Error( 'requisition_transfer_managed', 'Requisition-linked warehouse supply lines are created and advanced only by the requisition workflow. Create the branch requisition first.', array( 'status' => 409 ) );
		}

		global $wpdb;
		$id = Stockland_Sequence::next( 'TRF-', current_time( 'Y-m-d' ) );
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', 'Could not start transfer transaction.', array( 'status' => 500 ) );
		}
		try {
			$ok = Stockland_DB::insert_record(
				Stockland_DB::table( 'transfers' ),
				array(
					'transfer_id'         => $id,
					'transfer_date'       => current_time( 'Y-m-d' ),
					'from_location_id'    => $from,
					'to_location_id'      => $to,
					'sku'                 => $sku,
					'variant_id'          => (int) $product['variant_id'],
					'size'                => $product['size'] ?? null,
					'color'               => $product['color'] ?? null,
					'barcode'             => $product['barcode'] ?? ( $product['gtin'] ?? null ),
					'quantity'            => $q,
					'status'              => 'Requested',
					'requested_by'        => get_current_user_id(),
					'requisition_id'      => $req_id ? $req_id : null,
					'requisition_item_id' => $req_item_id,
				)
			);
			if ( false === $ok ) {
				throw new Exception( 'Could not create transfer.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'TRANSFER_CREATE', $sku, $q, null, 'Requested', $req_id ? $req_id : null, $id ) ) {
				throw new Exception( 'Audit event could not be recorded; transfer was not committed.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Transfer creation commit failed.' );
			}
			return rest_ensure_response( $this->row( $id ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			return new WP_Error( 'insert_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}

	/**
	 * Checks the permitted transfer-state transition map.
	 *
	 * @param string $from Current transfer status.
	 * @param string $to Requested next status.
	 * @return bool Whether the transition is allowed.
	 */
	private function valid_transition( $from, $to ) {
		$map = array(
			'Requested'  => array( 'Approved', 'Rejected', 'Cancelled' ),
			'Approved'   => array( 'Reserved', 'Rejected', 'Cancelled' ),
			'Reserved'   => array( 'Picked', 'Cancelled' ),
			'Picked'     => array( 'In Transit' ),
			'In Transit' => array( 'Received' ),
			'Received'   => array( 'Completed' ),
			'Completed'  => array(),
			'Rejected'   => array(),
			'Cancelled'  => array(),
		);
		return in_array( $to, $map[ $from ] ?? array(), true );
	}

	/**
	 * Checks whether the current ERP role may receive a transfer at its destination.
	 *
	 * @param array $t Transfer row containing destination location and status.
	 * @return bool Whether receipt is permitted.
	 */
	private function can_receive( $t ) {
		if ( ! current_user_can( 'stockland_receive_transfer' ) ) {
			return false;
		}
		return Stockland_Authority::can_access_location( (int) $t['to_location_id'] );
	}

	/**
	 * Applies a role-authorized transfer state transition.
	 *
	 * @param WP_REST_Request $r REST request containing transfer ID and target status.
	 * @return mixed REST response or a WP_Error.
	 * @throws Exception When a validated transition cannot complete its transaction.
	 */
	public function update_status( WP_REST_Request $r ) {
		global $wpdb;
		$id = sanitize_text_field( $r['transfer_id'] );
		$t  = $this->row( $id );
		if ( ! $t ) {
			return new WP_Error( 'not_found', 'Transfer not found.', array( 'status' => 404 ) );
		}
		if ( ! empty( $t['requisition_id'] ) ) {
			return new WP_Error( 'requisition_transfer_managed', 'Requisition-linked warehouse supply lines are advanced only through the requisition workflow. This endpoint cannot change their state.', array( 'status' => 409 ) );
		}
		$p   = $r->get_json_params();
		$new = sanitize_text_field( $p['status'] ?? '' );
		if ( ! $this->valid_transition( $t['status'], $new ) ) {
			return new WP_Error( 'invalid_transition', 'Invalid transfer status transition from ' . $t['status'] . ' to ' . $new . '.', array( 'status' => 409 ) );
		}
		$management        = current_user_can( 'stockland_view_all_branches' );
		$warehouse_control = current_user_can( 'stockland_manage_transfers' ) || current_user_can( 'stockland_approve_transfers' );
		if ( in_array( $new, array( 'Received', 'Completed' ), true ) && ! $this->can_receive( $t ) ) {
			return new WP_Error( 'forbidden', 'Only an authorized destination branch receiver can receive this transfer.', array( 'status' => 403 ) );
		}
		if ( 'Approved' === $new ) {
			if ( ! current_user_can( 'stockland_approve_transfers' ) || ! Stockland_Authority::company_scope() ) {
				return new WP_Error( 'forbidden', 'Only Management or Owner can approve a standalone transfer request.', array( 'status' => 403 ) );
			}
			if ( ! empty( $t['requisition_id'] ) ) {
				return new WP_Error( 'invalid_transition', 'Requisition-linked transfers are approved by the requisition approval workflow.', array( 'status' => 409 ) );
			}
		}
		if ( 'Reserved' === $new && ! Stockland_Authority::can_reserve_requisition() ) {
			return new WP_Error( 'forbidden', 'Only the Store/Warehouse Manager can reserve a transfer.', array( 'status' => 403 ) );
		}
		if ( in_array( $new, array( 'Picked', 'In Transit' ), true ) && ! Stockland_Authority::can_pick_requisition() ) {
			return new WP_Error( 'forbidden', 'Only Store/Warehouse personnel can pick this transfer.', array( 'status' => 403 ) );
		}
		$user = get_current_user_id();
		// Resolve the source before acquiring the warehouse lock. The previous build
		// referenced $source before assignment, silently disabling this concurrency
		// guard for Central Warehouse transfers.
		$source         = (int) $t['from_location_id'];
		$destination    = (int) $t['to_location_id'];
		$lock_locations = array();
		if ( in_array( $new, array( 'Reserved', 'Picked', 'In Transit' ), true ) ) {
			$lock_locations[] = $source;
		}
		if ( 'Received' === $new ) {
			$lock_locations[] = $destination;
		}
		if ( 'Completed' === $new && $destination ) {
			$lock_locations[] = $destination;
		}
		$location_locks = Stockland_DB::acquire_location_locks( $lock_locations, current_time( 'Y-m-d' ) );
		if ( ! $location_locks['ok'] ) {
			return new WP_Error( 'busy', 'A stock operation is already being processed for this location. Please retry.', array( 'status' => 409 ) );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_location_locks( $location_locks['locks'] );
			return new WP_Error( 'transaction_failed', 'Could not start transfer state transaction.', array( 'status' => 500 ) );}
		try {
			$current = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE transfer_id=%s FOR UPDATE', Stockland_DB::table( 'transfers' ), $id ), ARRAY_A );
			if ( ! $current ) {
				throw new Exception( 'Transfer disappeared during processing.' );
			}
			$qty    = (float) $current['quantity'];
			$source = (int) $current['from_location_id'];
			$sku    = $current['sku'];
			if ( 'Reserved' === $new ) {
				if ( Stockland_DB::get_available_stock( $source, $sku ) < $qty ) {
					throw new Exception( 'Insufficient available source stock for ' . $sku . '. Available quantity is ' . Stockland_DB::get_available_stock( $source, $sku ) . '.' );
				}
				$existing = Stockland_DB::read_var( $wpdb->prepare( "SELECT id FROM %i WHERE transfer_id=%s AND status='Active' LIMIT 1", Stockland_DB::table( 'reservations' ), $id ) );
				if ( ! $existing ) {
					$res_ok = Stockland_DB::insert_record(
						Stockland_DB::table( 'reservations' ),
						array(
							'location_id'       => $source,
							'sku'               => $sku,
							'variant_id'        => (int) ( $current['variant_id'] ?? ( Stockland_DB::get_product( $sku )['variant_id'] ?? 0 ) ),
							'requisition_id'    => $current['requisition_id'] ? $current['requisition_id'] : null,
							'quantity_reserved' => $qty,
							'status'            => 'Active',
							'transfer_id'       => $id,
							'created_at'        => current_time( 'mysql' ),
						)
					);
					if ( false === $res_ok ) {
						throw new Exception( 'Could not create the warehouse reservation.' );
					}$tr_ok = Stockland_DB::update_record(
						Stockland_DB::table( 'transfers' ),
						array(
							'reserved_by' => $user,
							'reserved_at' => current_time( 'mysql' ),
							'pickup_date' => $p['pickup_date'] ?? $current['pickup_date'],
						),
						array( 'transfer_id' => $id )
					);
					if ( false === $tr_ok ) {
						throw new Exception( 'Could not save transfer reservation state.' );
					}
				}
			}
			if ( 'Picked' === $new ) {
				$dispatch = isset( $p['dispatched_qty'] ) && '' !== $p['dispatched_qty'] ? (float) $p['dispatched_qty'] : $qty;
				if ( $dispatch <= 0 || $dispatch > $qty ) {
					throw new Exception( 'Dispatched quantity must be greater than zero and cannot exceed requested quantity (' . $qty . ').' );
				}
				$active_reserved = (float) Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity_reserved),0) FROM %i WHERE transfer_id=%s AND status='Active'", Stockland_DB::table( 'reservations' ), $id ) );
				if ( $active_reserved + 0.00001 < $dispatch ) {
					throw new Exception( 'Active warehouse reservation is missing or smaller than the dispatch quantity. Reserve the transfer before picking.' );
				}
				$available_for_this_transfer = Stockland_DB::get_available_stock( $source, $sku ) + $active_reserved;
				if ( $available_for_this_transfer < $dispatch ) {
					throw new Exception( 'Insufficient source stock for ' . $sku . '. Available for this transfer: ' . $available_for_this_transfer . '.' );
				}
				$cur                   = $current;
				$cur['dispatched_qty'] = $dispatch;
				if ( false === Stockland_Ledger::post_transfer_dispatched( $cur, $user ) ) {
					throw new Exception( 'Source stock ledger could not be posted.' );
				}
				$released = Stockland_DB::execute_query( $wpdb->prepare( "UPDATE %i SET status='Released',released_at=%s WHERE transfer_id=%s AND status='Active'", Stockland_DB::table( 'reservations' ), current_time( 'mysql' ), $id ) );
				if ( false === $released ) {
					throw new Exception( 'Could not release the active warehouse reservation.' );
				}
				if ( false === Stockland_DB::update_record(
					Stockland_DB::table( 'transfers' ),
					array(
						'status'         => 'Picked',
						'dispatched_qty' => $dispatch,
						'dispatched_by'  => $user,
						'dispatched_at'  => current_time( 'mysql' ),
					),
					array( 'transfer_id' => $id )
				) ) {
					throw new Exception( 'Could not save transfer dispatch.' );
				}
			} elseif ( 'In Transit' === $new ) {
				if ( 'Picked' !== $current['status'] ) {
					throw new Exception( 'A transfer must be Picked before it can enter In Transit.' );
				}
				if ( (float) $current['dispatched_qty'] <= 0 ) {
					throw new Exception( 'A transfer cannot enter In Transit before a dispatched quantity is recorded.' );
				}
				if ( false === Stockland_DB::update_record( Stockland_DB::table( 'transfers' ), array( 'status' => 'In Transit' ), array( 'transfer_id' => $id ) ) ) {
					throw new Exception( 'Could not save transfer In Transit state.' );
				}
			} elseif ( 'Received' === $new ) {
				$dispatch = (float) $current['dispatched_qty'];
				if ( $dispatch <= 0 ) {
					throw new Exception( 'Transfer must be dispatched before it can be received.' );
				}
				$received = isset( $p['received_qty'] ) && '' !== $p['received_qty'] ? (float) $p['received_qty'] : $dispatch;
				if ( $received < 0 ) {
					throw new Exception( 'Received quantity cannot be negative.' );
				}
				if ( $received > $dispatch ) {
					throw new Exception( 'Received quantity cannot exceed dispatched quantity. Management/Owner must resolve an over-receipt exception before the branch receives more than dispatched.' );
				}
				if ( abs( $received - $dispatch ) > 0.00001 && empty( trim( (string) ( $p['reason'] ?? '' ) ) ) ) {
					throw new Exception( 'A receiving deviation requires a comment.' );
				}
				$cur                 = $current;
				$cur['received_qty'] = $received;
				if ( $received > 0 && false === Stockland_Ledger::post_transfer_received( $cur, $user ) ) {
					throw new Exception( 'Destination stock ledger could not be posted.' );
				}
				$variance = $received - $dispatch;
				if ( false === Stockland_DB::update_record(
					Stockland_DB::table( 'transfers' ),
					array(
						'status'         => 'Received',
						'received_qty'   => $received,
						'variance_qty'   => $variance,
						'received_by'    => $user,
						'received_at'    => current_time( 'mysql' ),
						'receipt_reason' => sanitize_text_field( $p['reason'] ?? '' ),
					),
					array( 'transfer_id' => $id )
				) ) {
					throw new Exception( 'Could not save transfer receipt.' );
				}
				if ( ! empty( $current['requisition_id'] ) ) {
					$items = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Stockland_DB::table( 'requisition_items' ), (int) $current['requisition_item_id'] ), ARRAY_A );
					if ( $items ) {
						$item        = $items[0];
						$newreceived = (float) $item['quantity_received'] + $received;
						$ri_ok       = Stockland_DB::update_record( Stockland_DB::table( 'requisition_items' ), array( 'quantity_received' => $newreceived ), array( 'id' => (int) $item['id'] ) );
						if ( false === $ri_ok ) {
							throw new Exception( 'Could not update requisition received quantity.' );
						}
					}
				}
			} elseif ( 'Completed' === $new ) {
				if ( 'Received' !== $current['status'] ) {
					throw new Exception( 'A transfer must be Received before it can be Completed.' );
				}
				$done_ok = Stockland_DB::update_record( Stockland_DB::table( 'transfers' ), array( 'status' => 'Completed' ), array( 'transfer_id' => $id ) );
				if ( false === $done_ok ) {
					throw new Exception( 'Could not complete the transfer.' );
				}
			} else {
				if ( in_array( $new, array( 'Rejected', 'Cancelled' ), true ) ) {
					$released = Stockland_DB::execute_query( $wpdb->prepare( "UPDATE %i SET status='Released',released_at=%s WHERE transfer_id=%s AND status='Active'", Stockland_DB::table( 'reservations' ), current_time( 'mysql' ), $id ) );
					if ( false === $released ) {
						throw new Exception( 'Could not release the active warehouse reservation.' );
					}
				}
				$tr_status_ok = Stockland_DB::update_record( Stockland_DB::table( 'transfers' ), array( 'status' => $new ), array( 'transfer_id' => $id ) );
				if ( false === $tr_status_ok ) {
					throw new Exception( 'Could not save transfer status.' );
				}
				if ( 'Approved' === $new ) {
					$tr_approve_ok = Stockland_DB::update_record( Stockland_DB::table( 'transfers' ), array( 'approved_by' => $user ), array( 'transfer_id' => $id ) );
					if ( false === $tr_approve_ok ) {
						throw new Exception( 'Could not save transfer approval.' );
					}
				}
			}
			if ( class_exists( 'Stockland_Workflow' ) ) {
				if ( ! Stockland_Workflow::event(
					'transfer',
					$id,
					$current['status'],
					$new,
					$user,
					(int) $current['to_location_id'],
					array(
						'dispatched_qty' => $p['dispatched_qty'] ?? $current['dispatched_qty'],
						'received_qty'   => $p['received_qty'] ?? $current['received_qty'],
					)
				) ) {
					throw new Exception( 'Transfer workflow event could not be recorded.' );
				}
			}
			$audit_qty = 'Received' === $new ? ( isset( $received ) ? $received : $current['received_qty'] ) : $qty;
			if ( ! Stockland_Audit::log( $user, (int) $current['to_location_id'], 'TRANSFER_STATUS_CHANGE', $sku, $audit_qty, $current['status'], $new, $current['requisition_id'] ? $current['requisition_id'] : null, $id ) ) {
				throw new Exception( 'Audit event could not be recorded; transfer was not committed.' );
			}
			if ( ! empty( $current['requisition_id'] ) ) {
				$this->sync_requisition( $current['requisition_id'] );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Transfer commit failed.' );
			}
			Stockland_DB::release_day_locks( $location_locks['locks'] );
			if ( 'Received' === $new ) {
				$variance_value = isset( $variance ) ? (float) $variance : 0.0;
				$reason         = sanitize_text_field( (string) ( $p['reason'] ?? '' ) );
				if ( abs( $variance_value ) > 0.00001 ) {
					Stockland_Notifications::notify_exception_owners(
						'Receiving variance on ' . $id,
						'Transfer ' . $id . ' for SKU ' . $sku . ' was received at ' . number_format( $received, 2 ) . ' against dispatched ' . number_format( $dispatch, 2 ) . '. Variance: ' . number_format( $variance_value, 2 ) . '.' . ( $reason ? ' Comment: ' . $reason : '' ),
						'transfer',
						$id
					);
				} else {
					Stockland_Notifications::push_role( 'management', null, 'transfer_received', 'Transfer received', 'Transfer ' . $id . ' for SKU ' . $sku . ' was received and recorded.', 'transfer', $id, 'info' );
					Stockland_Notifications::push_role( 'owner', null, 'transfer_received', 'Transfer received', 'Transfer ' . $id . ' for SKU ' . $sku . ' was received and recorded.', 'transfer', $id, 'info' );
				}
			}
			return rest_ensure_response( $this->row( $id ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_day_locks( $location_locks['locks'] );
			return new WP_Error( 'transfer_failed', $e->getMessage(), array( 'status' => 409 ) ); }
	}

	/**
	 * Synchronizes the linked requisition state after a transfer transition.
	 *
	 * @param string $requisition_id Requisition identifier.
	 * @return bool Whether a linked requisition was updated or no link existed.
	 */
	private function sync_requisition( $requisition_id ) {
		global $wpdb;
		$statuses = Stockland_DB::read_col(
			$wpdb->prepare(
				'SELECT status FROM %i WHERE requisition_id=%s',
				Stockland_DB::table( 'transfers' ),
				$requisition_id
			)
		);
		if ( empty( $statuses ) ) {
			return;
		}
		$new = null;
		if ( count( array_unique( $statuses ) ) === 1 ) {
			$new = $statuses[0];
		}
		if ( ! $new ) {
			return;
		}
		$req = Stockland_DB::read_row(
			$wpdb->prepare(
				'SELECT status FROM %i WHERE requisition_id=%s',
				Stockland_DB::table( 'requisitions' ),
				$requisition_id
			),
			ARRAY_A
		);
		if ( ! $req || $req['status'] === $new || 'Completed' === $req['status'] ) {
			return;
		}
		$allowed = array( 'Reserved', 'Picked', 'Received', 'Completed', 'Cancelled' );
		if ( in_array( $new, $allowed, true ) ) {
			$req_sync_ok = Stockland_DB::update_record(
				Stockland_DB::table( 'requisitions' ),
				array(
					'status'     => $new,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'requisition_id' => $requisition_id )
			);
			if ( false === $req_sync_ok ) {
				throw new Exception( 'Could not synchronize the requisition state.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), null, 'REQUISITION_AUTO_SYNC', null, null, $req['status'], $new, null, $requisition_id ) ) {
				throw new Exception( 'Audit event could not be recorded; requisition synchronization was not committed.' );
			}
		}
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
