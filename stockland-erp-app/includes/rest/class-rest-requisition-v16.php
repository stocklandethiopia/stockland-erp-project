<?php
/**
 * REST controller source for Stockland ERP operational workflows.
 *
 * @package Stockland_ERP
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
/**
 * Branch-scoped requisition REST endpoints and warehouse hand-off helpers.
 *
 * @package Stockland_ERP
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requisition workflow API for the v16 ERP frontend.
 *
 * @package Stockland_ERP
 */
class Stockland_REST_Requisition_V16 {
	const NS = 'stockland/v16';
	/**
	 * Registers requisition REST callbacks.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'routes' ) );}
	/**
	 * Registers requisition, notification and warehouse-action routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/requisitions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/requisitions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => function () {
					return current_user_can( 'stockland_request_stock' );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/requisitions/source-preview/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'source_preview' ),
				'permission_callback' => function () {
					if ( ! current_user_can( 'stockland_request_stock' ) ) {
						return new WP_Error( 'forbidden', 'Stock-request permission is required to preview stock sources.', array( 'status' => 403 ) );
					}
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/requisitions/(?P<id>[A-Za-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'detail' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/requisitions/(?P<id>[A-Za-z0-9\-]+)/decision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decision' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/requisitions/(?P<id>[A-Za-z0-9\-]+)/receive',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive_info' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/requisitions/(?P<id>[A-Za-z0-9\-]+)/warehouse-action',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'warehouse_action' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return rest_ensure_response( Stockland_Notifications::unread() );
				},
				'permission_callback' => function () {
						return Stockland_Authority::assert_profile();
				},
			)
		);
		register_rest_route(
			self::NS,
			'/notifications/(?P<id>\d+)/read',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $r ) {
					return rest_ensure_response( array( 'read' => Stockland_Notifications::mark_read( (int) $r['id'] ) ) );
				},
				'permission_callback' => function () {
						return Stockland_Authority::assert_profile();
				},
			)
		);
	}
	/**
	 * Builds a standardized REST error response.
	 *
	 * @param string $code Stable error code.
	 * @param string $msg Human-readable error message.
	 * @param int    $status HTTP status code.
	 * @return WP_Error Error response.
	 */
	private function deny( $code, $msg, $status = 403 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );}
	/**
	 * Loads a requisition with its branch/location name.
	 *
	 * @param string $id Requisition identifier.
	 * @return array|null Requisition row, or null when not found.
	 */
	private function req( $id ) {
		global $wpdb;
		return Stockland_DB::read_row( $wpdb->prepare( 'SELECT r.*,l.location_name FROM %i r JOIN %i l ON l.id=r.branch_location_id WHERE r.requisition_id=%s', Stockland_DB::table( 'requisitions' ), Stockland_DB::table( 'locations' ), $id ), ARRAY_A );}
	/**
	 * Verifies that a requisition is in a location/state visible to the current role.
	 *
	 * @param array $req Requisition row with status and branch location.
	 * @return bool Whether the requisition is within the current user's view scope.
	 */
	private function scope_ok( $req ) {
		if ( Stockland_Authority::company_scope() ) {
			return true;
		}if ( Stockland_Authority::can_warehouse_operate_requisition() ) {
			return in_array( $req['status'], array( 'Approved', 'Reserved', 'Picked', 'Received', 'Completed' ), true );
		}return Stockland_Authority::can_access_location( (int) $req['branch_location_id'] );}
	/**
	 * Calculates central-warehouse availability and claimable quantities by item.
	 *
	 * @param array $items Requisition item rows.
	 * @return array Availability, shortage and reorder-warning details.
	 */
	private function availability( $items ) {
		$wh  = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$out = array();
		foreach ( $items as $i ) {
			$sku     = $i['sku'];
			$product = ! empty( $i['variant_id'] ) ? Stockland_DB::get_product_by_id( (int) $i['variant_id'] ) : null;
			if ( ! $product ) {
				$product = Stockland_DB::get_product( $sku );
			}$live_sku = $product['sku'] ?? $sku;
			$available = Stockland_DB::get_available_stock( $wh, $live_sku );
			$reorder   = (float) ( $product['central_reorder_level'] ?? 0 );
			$requested = (float) $i['quantity_requested'];
			$after     = $available - $requested;
			$out[]     = array(
				'item_id'                 => (int) $i['id'],
				'sku'                     => $sku,
				'current_sku'             => $live_sku,
				'product_name'            => $product['product_name'] ?? $sku,
				'requested'               => $requested,
				'available'               => max( 0, $available ),
				'max_approvable'          => min( $requested, max( 0, $available ) ),
				'shortage'                => max( 0, $requested - $available ),
				'warehouse_reorder_alert' => ( $after < $reorder ),
				'reorder_level'           => $reorder,
			);}
		return $out;
	}
	/**
	 * Lists requisitions visible to the current role and assigned locations.
	 *
	 * @return mixed REST response containing requisitions or a WP_Error.
	 */
	public function list() {
		global $wpdb;
		$p     = Stockland_Authority::profile();
		$where = '';
		$args  = array();
		if ( ! Stockland_Authority::company_scope() && ! Stockland_Authority::can_warehouse_operate_requisition() ) {
			$allowed = Stockland_Authority::location_ids();
			if ( empty( $allowed ) ) {
				return $this->deny( 'no_branch', 'This user is not assigned to an operational branch.' );
			}
			$ph    = implode( ',', array_fill( 0, count( $allowed ), '%d' ) );
			$where = " AND r.branch_location_id IN({$ph})";
			$args  = array_merge( $args, $allowed );
		}
		if ( Stockland_Authority::can_warehouse_operate_requisition() ) {
			$where = " AND r.status IN('Approved','Reserved','Picked','Received','Completed')";}
		$sql  = "SELECT r.requisition_id,r.branch_location_id,r.requested_by,r.date_requested,r.status,r.notes,r.reviewed_at,r.management_decision,r.owner_decision,r.approval_mode,r.created_at,l.location_name,u.display_name requester_name,COUNT(ri.id) item_count,COALESCE(SUM(ri.quantity_requested),0) requested_units,COALESCE(SUM(ri.quantity_approved),0) approved_units FROM %i r JOIN %i l ON l.id=r.branch_location_id LEFT JOIN %i u ON u.ID=r.requested_by LEFT JOIN %i ri ON ri.requisition_id=r.requisition_id WHERE 1=1 {$where} GROUP BY r.requisition_id ORDER BY r.created_at DESC LIMIT 300";
		$args = array_merge( array( Stockland_DB::table( 'requisitions' ), Stockland_DB::table( 'locations' ), $wpdb->users, Stockland_DB::table( 'requisition_items' ) ), $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query uses fixed SELECT/JOIN fragments, an allow-listed role/status clause and generated integer placeholders; table identifiers and location IDs are bound through prepare().
		$rows = Stockland_DB::read_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['notes'] = Stockland_Requisition_Service::display_notes( $row['notes'] ?? '' );
			$actions      = array();
			$st           = $row['status'];
			$pending      = '';
			if ( 'Requested' === $st ) {
				if ( Stockland_Authority::can_review_requisition() ) {
					$actions[] = 'REVIEW';
				} else {
					$pending = 'Branch Manager';
				}
			} elseif ( 'Reviewed' === $st ) {
				$mg = Stockland_Authority::can_approve_requisition( 'management' );
				$ow = Stockland_Authority::can_approve_requisition( 'owner' );
				if ( $mg && empty( $row['management_decision'] ) ) {
					$actions[] = 'MANAGEMENT_APPROVE';
				}
				if ( $ow && empty( $row['owner_decision'] ) ) {
					$actions[] = 'OWNER_APPROVE';
				}
				if ( ! $actions ) {
					$pending = 'Owner or Management';
				}
			} elseif ( 'Approved' === $st ) {
				// Immediate picking is an approved option; reservation and pickup scheduling remain optional.
				if ( Stockland_Authority::can_pick_requisition() ) {
					$actions[] = 'PICK';
				}
				if ( Stockland_Authority::can_reserve_requisition() ) {
					$actions[] = 'RESERVE';
				}
				if ( ! $actions ) {
					$pending = 'Central Warehouse Manager or Warehouse Employee';
				}
			} elseif ( 'Reserved' === $st ) {
				if ( Stockland_Authority::can_pick_requisition() ) {
					$actions[] = 'PICK';
				} else {
					$pending = 'Central Warehouse Manager or Warehouse Employee';
				}
			} elseif ( 'Picked' === $st ) {
				if ( Stockland_Authority::can_receive_requisition() ) {
					$actions[] = 'RECEIVE';
				} else {
					$pending = 'Branch Manager';
				}
			}
			$row['available_actions']    = $actions;
			$row['pending_action_owner'] = $pending;
			$row['next_action']          = $actions[0] ?? 'VIEW';
		}unset( $row );
		return rest_ensure_response( $rows );
	}
	/**
	 * Creates a branch-scoped requisition through the canonical service.
	 *
	 * @param WP_REST_Request $r REST request containing the requisition payload.
	 * @return mixed REST response or a WP_Error.
	 */
	public function create( WP_REST_Request $r ) {
		return Stockland_Requisition_Service::create( $r->get_json_params(), get_current_user_id() ); }

	/**
	 * Returns a requisition detail view after checking branch and role scope.
	 *
	 * @param WP_REST_Request $r REST request containing the requisition identifier.
	 * @return mixed REST response or a WP_Error.
	 */
	/**
	 * Returns read-only, live branch and warehouse sourcing for a requisition draft.
	 * This endpoint never creates a requisition, reservation, transfer, or ledger entry.
	 *
	 * @param WP_REST_Request $r Request containing branch, SKU and quantity.
	 * @return mixed Live sourcing advice or a validation error.
	 */
	public function source_preview( WP_REST_Request $r ) {
		global $wpdb;
		$branch_id = absint( $r->get_param( 'location_id' ) );
		$sku       = sanitize_text_field( (string) $r->get_param( 'sku' ) );
		$raw_qty   = $r->get_param( 'quantity' );
		$quantity  = is_numeric( $raw_qty ) ? (float) $raw_qty : 0.0;
		if ( $branch_id <= 0 || '' === $sku || $quantity <= 0 ) {
			return $this->deny( 'invalid_source_preview', 'Choose an authorized branch, a live Product Master SKU and a positive quantity.', 400 );
		}
		if ( ! Stockland_Authority::can_access_location( $branch_id ) ) {
			return $this->deny( 'branch_forbidden', 'This stock-source preview is outside your assigned branch scope.', 403 );
		}
		$branch = Stockland_DB::read_row(
			$wpdb->prepare( 'SELECT id,location_type,is_active FROM %i WHERE id=%d', Stockland_DB::table( 'locations' ), $branch_id ),
			ARRAY_A
		);
		if ( ! $branch || 'Branch' !== (string) $branch['location_type'] || 0 === (int) $branch['is_active'] ) {
			return $this->deny( 'invalid_branch', 'Select an active branch location to preview stock sources.', 400 );
		}
		$product = Stockland_DB::get_product( $sku );
		if ( ! $product || empty( $product['variant_id'] ) || 'STYLE' === ( $product['product_type'] ?? 'SIMPLE' ) ) {
			return $this->deny( 'invalid_sku', 'Select an active, stockable Product Master variant. Create missing variations in Product Master before requesting or receiving stock.', 400 );
		}
		$preview = Stockland_Sourcing::for_items(
			array(
				array(
					'sku'                => $product['sku'],
					'variant_id'         => (int) $product['variant_id'],
					'quantity_requested' => $quantity,
				),
			),
			$branch_id
		);
		if ( ! is_array( $preview ) || empty( $preview[0] ) ) {
			return new WP_Error( 'source_preview_unavailable', 'Live stock-source advice could not be calculated. No ERP data was changed.', array( 'status' => 503 ) );
		}
		return rest_ensure_response(
			array(
				'checked_at' => current_time( 'mysql' ),
				'branch_id'  => $branch_id,
				'sku'        => $product['sku'],
				'sourcing'   => $preview[0],
				'read_only'  => true,
			)
		);
	}

	/**
	 * Returns the selected requisition and current source availability.
	 *
	 * @param WP_REST_Request $r Request containing the requisition ID.
	 * @return mixed Authorized requisition detail or a REST error.
	 */
	public function detail( WP_REST_Request $r ) {
		global $wpdb;
		$req = $this->req( sanitize_text_field( $r['id'] ) );
		if ( ! $req ) {
			return $this->deny( 'not_found', 'Requisition not found.', 404 );
		}if ( ! $this->scope_ok( $req ) ) {
			return $this->deny( 'forbidden', 'This requisition is outside your branch scope.' );
		}
		$req['notes'] = Stockland_Requisition_Service::display_notes( $req['notes'] ?? '' );
		$items        = Stockland_DB::read_results( $wpdb->prepare( 'SELECT ri.*,p.product_name,p.brand,p.color,p.size FROM %i ri LEFT JOIN %i p ON (p.variant_id=ri.variant_id OR (ri.variant_id IS NULL AND p.sku=ri.sku)) WHERE ri.requisition_id=%s ORDER BY ri.id', Stockland_DB::table( 'requisition_items' ), Stockland_DB::table( 'products' ), $req['requisition_id'] ), ARRAY_A );
		$availability = $this->availability( $items );
		$map          = array();
		foreach ( $availability as $g ) {
			$map[ $g['item_id'] ] = $g;
		}foreach ( $items as &$i ) {
			$i['availability'] = $map[ (int) $i['id'] ] ?? null;
		}unset( $i );
		// System-native stock sourcing reads live ledger state without changing the requisition workflow.
		$sourcing = Stockland_Sourcing::for_items( $items, (int) $req['branch_location_id'] );
		return rest_ensure_response(
			array(
				'requisition'             => $req,
				'items'                   => $items,
				'approval_mode'           => 'ONE_OF',
				'pickup_date'             => $req['pickup_date'] ?? null,
				'availability_checked_at' => current_time( 'mysql' ),
				'availability'            => $availability,
				'sourcing_advice'         => $sourcing,
				'approval_lines'          => array(
					array(
						'stage'       => 'management',
						'label'       => 'Management',
						'decision'    => $req['management_decision'],
						'approved_by' => $req['management_approved_by'],
						'approved_at' => $req['management_approved_at'],
						'notes'       => $req['management_approval_notes'],
						'can_act'     => Stockland_Authority::can_approve_requisition( 'management' ),
					),
					array(
						'stage'       => 'owner',
						'label'       => 'Owner',
						'decision'    => $req['owner_decision'],
						'approved_by' => $req['owner_approved_by'],
						'approved_at' => $req['owner_approved_at'],
						'notes'       => $req['owner_approval_notes'],
						'can_act'     => Stockland_Authority::can_approve_requisition( 'owner' ),
					),
				),

				'transfers'               => Stockland_DB::read_results(
					$wpdb->prepare(
						'SELECT t.*,l.location_name from_location_name,to_l.location_name to_location_name FROM %i t JOIN %i l ON l.id=t.from_location_id JOIN %i to_l ON to_l.id=t.to_location_id WHERE t.requisition_id=%s ORDER BY t.created_at',
						Stockland_DB::table( 'transfers' ),
						Stockland_DB::table( 'locations' ),
						Stockland_DB::table( 'locations' ),
						$req['requisition_id']
					),
					ARRAY_A
				),
			)
		);
	}
	/**
	 * Applies a role-authorized requisition review or approval decision.
	 *
	 * @param WP_REST_Request $r REST request containing decision and notes.
	 * @return mixed REST response or a WP_Error.
	 * @throws Exception When a guarded workflow transaction fails.
	 */
	public function decision( WP_REST_Request $r ) {
		global $wpdb;
		$id       = sanitize_text_field( $r['id'] );
		$payload  = $r->get_json_params();
		$stage    = sanitize_key( $payload['stage'] ?? '' );
		$decision = strtoupper( sanitize_text_field( $payload['decision'] ?? '' ) );
		$note     = sanitize_text_field( $payload['note'] ?? '' );
		$req      = $this->req( $id );
		if ( ! $req ) {
			return $this->deny( 'not_found', 'Requisition not found.', 404 );
		}
		if ( ! $this->scope_ok( $req ) ) {
			return $this->deny( 'forbidden', 'This requisition is outside your branch scope.' );
		}

		if ( 'review' === $stage ) {
			if ( ! Stockland_Authority::can_review_requisition() ) {
				return $this->deny( 'forbidden', 'Branch Manager review authority is required.' );
			}
			if ( Stockland_Authority::role() === Stockland_Authority::BRANCH_MANAGER && ! Stockland_Authority::can_access_location( (int) $req['branch_location_id'] ) ) {
				return $this->deny( 'forbidden', 'Only a manager assigned to the requesting branch may review this requisition.' );
			}
			if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
				return new WP_Error( 'transaction_failed', 'Could not start the requisition review transaction.', array( 'status' => 500 ) );
			}
			try {
				$locked = Stockland_DB::read_row( $wpdb->prepare( 'SELECT r.*,l.location_name FROM %i r JOIN %i l ON l.id=r.branch_location_id WHERE r.requisition_id=%s FOR UPDATE', Stockland_DB::table( 'requisitions' ), Stockland_DB::table( 'locations' ), $id ), ARRAY_A );
				if ( ! $locked ) {
					throw new Exception( 'Requisition disappeared during review.' );
				}
				if ( 'Requested' !== $locked['status'] ) {
					throw new Exception( 'This requisition has already been reviewed.' );
				}
				$ok = Stockland_DB::update_record(
					Stockland_DB::table( 'requisitions' ),
					array(
						'status'       => 'Reviewed',
						'reviewed_by'  => get_current_user_id(),
						'reviewed_at'  => current_time( 'mysql' ),
						'review_notes' => $note ? $note : $locked['notes'],
					),
					array( 'requisition_id' => $id )
				);
				if ( false === $ok ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Requisition review could not be saved.' );
				}
				if ( ! Stockland_Workflow::event( 'requisition', $id, 'Requested', 'Reviewed', get_current_user_id(), $locked['branch_location_id'], array( 'review_note' => $note ) ) ) {
					throw new Exception( 'Workflow event could not be recorded.' );
				}
				if ( ! Stockland_Audit::log( get_current_user_id(), $locked['branch_location_id'], 'REQUISITION_REVIEW', null, null, 'Requested', 'Reviewed', $note, $id ) ) {
					throw new Exception( 'Audit event could not be recorded.' );
				}
				if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
					throw new Exception( 'Requisition review commit failed.' );
				}
				Stockland_Notifications::notify_approvers( $id, $locked['branch_location_id'], 'Requisition ready for approval', $locked['location_name'] . ' requisition ' . $id . ' was reviewed and is ready for Management/Owner approval.' );
				return rest_ensure_response( $this->detail( new WP_REST_Request( 'GET', '/stockland/v16/requisitions/' . $id ) ) );
			} catch ( Throwable $e ) {
				Stockland_DB::execute_query( 'ROLLBACK' );
				return new WP_Error( 'requisition_review_failed', $e->getMessage(), array( 'status' => 409 ) );}
		}
		if ( ! in_array( $stage, array( 'management', 'owner' ), true ) ) {
			return $this->deny( 'invalid_stage', 'Unsupported requisition decision stage.', 400 );
		}
		if ( ! Stockland_Authority::can_approve_requisition( $stage ) ) {
			return $this->deny( 'forbidden', 'You do not hold approval authority for this approval line.' );
		}
		if ( 'Reviewed' !== $req['status'] ) {
			return $this->deny( 'invalid_state', 'Only a reviewed requisition can be approved.', 409 );
		}
		if ( ! in_array( $decision, array( 'APPROVE', 'PARTIAL', 'REJECT' ), true ) ) {
			return $this->deny( 'invalid_decision', 'Decision must be APPROVE, PARTIAL or REJECT.', 400 );
		}
		if ( in_array( $decision, array( 'PARTIAL', 'REJECT' ), true ) && '' === $note ) {
			return $this->deny( 'reason_required', 'A comment/reason is required for a partial approval or rejection.', 400 );
		}
		$mode    = 'ONE_OF';
		$wh      = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$wh_lock = Stockland_DB::acquire_day_lock( $wh, current_time( 'Y-m-d' ) );
		if ( ! $wh_lock['ok'] ) {
			return $this->deny( 'busy', 'Central Warehouse is processing another stock approval. Please retry.', 409 );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $wh_lock );
			return new WP_Error( 'transaction_failed', 'Could not start the requisition approval transaction.', array( 'status' => 500 ) );}
		try {
			// Serialize approval decisions on the requisition itself. The prior build
			// checked status before opening the transaction and could therefore approve
			// stale Reviewed state from two concurrent requests.
			$locked_req = Stockland_DB::read_row( $wpdb->prepare( 'SELECT r.*,l.location_name FROM %i r JOIN %i l ON l.id=r.branch_location_id WHERE r.requisition_id=%s FOR UPDATE', Stockland_DB::table( 'requisitions' ), Stockland_DB::table( 'locations' ), $id ), ARRAY_A );
			if ( ! $locked_req ) {
				throw new Exception( 'Requisition disappeared during approval.' );
			}
			if ( 'Reviewed' !== $locked_req['status'] ) {
				throw new Exception( 'Only a reviewed requisition can be approved. Current status: ' . $locked_req['status'] . '.' );
			}
			$scope_req = $this->scope_ok( $locked_req );
			if ( ! $scope_req ) {
				throw new Exception( 'This requisition is outside your branch scope.' );
			}
			if ( 'management' === $stage && ! empty( $locked_req['management_decision'] ) ) {
				throw new Exception( 'The Management approval line has already been decided.' );
			}
			if ( 'owner' === $stage && ! empty( $locked_req['owner_decision'] ) ) {
				throw new Exception( 'The Owner approval line has already been decided.' );
			}
			$req  = $locked_req;
			$mode = 'ONE_OF';

			$items        = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE requisition_id=%s FOR UPDATE', Stockland_DB::table( 'requisition_items' ), $id ), ARRAY_A );
			$availability = $this->availability( $items );
			$by           = array();
			foreach ( $availability as $x ) {
				$by[ $x['item_id'] ] = $x;
			}
			$approved = array();
			$input    = is_array( $payload['approved_items'] ?? null ) ? $payload['approved_items'] : array();
			foreach ( $items as $item ) {
				$requested = (float) $item['quantity_requested'];
				$available = (float) ( $by[ $item['id'] ]['available'] ?? 0 );
				$q         = isset( $input[ $item['id'] ] ) ? (float) $input[ $item['id'] ] : ( 'APPROVE' === $decision ? $requested : 0 );
				if ( 'REJECT' === $decision ) {
					$q = 0;
				}if ( $q < 0 || $q > $requested ) {
					throw new Exception( 'Invalid approved quantity for ' . $item['sku'] . '. Requested ' . $requested . '.' );
				}if ( $q > $available ) {
					throw new Exception( 'Insufficient live Central Warehouse availability for ' . $item['sku'] . '. Requested ' . $q . ', available ' . $available . ', shortfall ' . max( 0, $q - $available ) . '. Choose APPROVE FULL only when fully available, or APPROVE PARTIAL with a quantity up to ' . $available . '.' );
				}$approved[ $item['id'] ] = $q;}
			if ( 'REJECT' !== $decision && array_sum( $approved ) <= 0 ) {
				throw new Exception( 'Approval must include at least one unit. Use Reject when no stock is to be supplied.' );
			}
			$field = 'management' === $stage ? array(
				'management_decision'       => $decision,
				'management_approved_by'    => get_current_user_id(),
				'management_approved_at'    => current_time( 'mysql' ),
				'management_approval_notes' => $note,
			) : array(
				'owner_decision'       => $decision,
				'owner_approved_by'    => get_current_user_id(),
				'owner_approved_at'    => current_time( 'mysql' ),
				'owner_approval_notes' => $note,
			);
			if ( false === Stockland_DB::update_record( Stockland_DB::table( 'requisitions' ), $field, array( 'requisition_id' => $id ) ) ) {
				throw new Exception( 'Approval could not be saved.' );
			}
			if ( 'REJECT' !== $decision ) {
				foreach ( $items as $item ) {
					$item_ok = Stockland_DB::update_record( Stockland_DB::table( 'requisition_items' ), array( 'quantity_approved' => $approved[ $item['id'] ] ), array( 'id' => $item['id'] ) );
					if ( false === $item_ok ) {
						throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Approved quantity could not be saved for ' . $item['sku'] . '.' );
					}
				}
			}
			$req2   = $this->req( $id );
			$mg     = ( 'APPROVE' === $req2['management_decision'] || 'PARTIAL' === $req2['management_decision'] );
			$ow     = ( 'APPROVE' === $req2['owner_decision'] || 'PARTIAL' === $req2['owner_decision'] );
			$reject = ( 'REJECT' === $req2['management_decision'] || 'REJECT' === $req2['owner_decision'] );
			$final  = ( 'REJECT' !== $decision );
			if ( $reject && 'REJECT' === $decision && 'ONE_OF' === $mode ) {
				$final = true;
			}
			if ( $final ) {
				if ( $reject ) {
					$rej_ok = Stockland_DB::update_record(
						Stockland_DB::table( 'requisitions' ),
						array(
							'status'      => 'Rejected',
							'approved_by' => get_current_user_id(),
							'approved_at' => current_time( 'mysql' ),
						),
						array( 'requisition_id' => $id )
					);
					if ( false === $rej_ok ) {
						throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Requisition rejection state could not be saved.' );
					}if ( ! Stockland_Workflow::event(
						'requisition',
						$id,
						'Reviewed',
						'Rejected',
						get_current_user_id(),
						$req['branch_location_id'],
						array(
							'decision_stage' => $stage,
							'note'           => $note,
						)
					) ) {
						throw new Exception( 'Workflow event could not be recorded.' );
					}if ( ! Stockland_Audit::log( get_current_user_id(), $req['branch_location_id'], 'REQUISITION_REJECT', null, null, 'Reviewed', 'Rejected', $note, $id ) ) {
						throw new Exception( 'Audit event could not be recorded.' );
					}
				} else {
					$lines_to_create = Stockland_DB::read_results( $wpdb->prepare( 'SELECT ri.*,p.product_name,p.size,p.color,p.barcode,p.gtin,p.variant_id FROM %i ri JOIN %i p ON (p.variant_id=ri.variant_id OR (ri.variant_id IS NULL AND p.sku=ri.sku)) WHERE ri.requisition_id=%s AND ri.quantity_approved>0', Stockland_DB::table( 'requisition_items' ), Stockland_DB::table( 'products' ), $id ), ARRAY_A );
					foreach ( $lines_to_create as $item ) {
						$q = (float) $item['quantity_approved'];
						if ( $q <= 0 ) {
							continue;
						}$exists = Stockland_DB::read_var( $wpdb->prepare( "SELECT transfer_id FROM %i WHERE requisition_item_id=%d AND status NOT IN('Rejected','Cancelled','Completed') LIMIT 1", Stockland_DB::table( 'transfers' ), (int) $item['id'] ) );
						if ( $exists ) {
							continue;
						}$transfer_id = Stockland_Sequence::next( 'TRF-', current_time( 'Y-m-d' ) );
						if ( false === Stockland_DB::insert_record(
							Stockland_DB::table( 'transfers' ),
							array(
								'transfer_id'         => $transfer_id,
								'transfer_date'       => current_time( 'Y-m-d' ),
								'from_location_id'    => $wh,
								'to_location_id'      => $req['branch_location_id'],
								'sku'                 => $item['sku'],
								'variant_id'          => $item['variant_id'],
								'size'                => $item['size'] ?? null,
								'color'               => $item['color'] ?? null,
								'barcode'             => $item['barcode'] ?? ( $item['gtin'] ?? null ),
								'quantity'            => $q,
								'status'              => 'Approved',
								'requested_by'        => $req['requested_by'],
								'approved_by'         => get_current_user_id(),
								'requisition_id'      => $id,
								'requisition_item_id' => $item['id'],
							)
						) ) {
							throw new Exception( 'Could not create warehouse supply line for ' . $item['sku'] . '.' );
						}
					}
					$app_ok = Stockland_DB::update_record(
						Stockland_DB::table( 'requisitions' ),
						array(
							'status'      => 'Approved',
							'approved_by' => get_current_user_id(),
							'approved_at' => current_time( 'mysql' ),
						),
						array( 'requisition_id' => $id )
					);
					if ( false === $app_ok ) {
						throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Requisition approval state could not be saved.' );
					}if ( ! Stockland_Workflow::event(
						'requisition',
						$id,
						'Reviewed',
						'Approved',
						get_current_user_id(),
						$req['branch_location_id'],
						array(
							'approval_stage' => $stage,
							'decision'       => $decision,
						)
					) ) {
						throw new Exception( 'Workflow event could not be recorded.' );
					}if ( ! Stockland_Audit::log( get_current_user_id(), $req['branch_location_id'], 'REQUISITION_APPROVE', null, null, 'Reviewed', 'Approved', $note, $id, get_current_user_id() ) ) {
						throw new Exception( 'Audit event could not be recorded.' );
					}
				}
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Requisition approval commit failed.' );
			}
			Stockland_DB::release_day_lock( $wh_lock );
			if ( $final && ! $reject ) {
				$lines   = Stockland_DB::read_results( $wpdb->prepare( 'SELECT ri.sku,ri.quantity_approved,p.product_name FROM %i ri JOIN %i p ON (p.variant_id=ri.variant_id OR (ri.variant_id IS NULL AND p.sku=ri.sku)) WHERE ri.requisition_id=%s AND ri.quantity_approved>0', Stockland_DB::table( 'requisition_items' ), Stockland_DB::table( 'products' ), $id ), ARRAY_A );
				$summary = array_map(
					function ( $x ) {
								return $x['sku'] . ' × ' . $x['quantity_approved'] . ' — ' . $x['product_name'];
					},
					$lines
				);
				Stockland_Notifications::push_role( 'warehouse_manager', $wh, 'warehouse_requisition', 'Approved requisition — RESERVE required', 'Requisition ' . $id . ' from ' . $req['location_name'] . ' is approved. Next action: Store/Warehouse Manager must RESERVE the approved quantities. ' . implode( '; ', $summary ), 'requisition', $id, 'action' );
				Stockland_Notifications::notify_branch( (int) $req['branch_location_id'], $id, 'Requisition approved', 'Requisition ' . $id . ' is approved. Central Warehouse will reserve and prepare the requested items.', 'info' );
			} elseif ( ! $final ) {
				$waiting = ( 'management' === $stage ? 'Owner' : 'Management' );
				Stockland_Notifications::notify_approvers( $id, $req['branch_location_id'], 'Requisition awaiting ' . $waiting . ' approval', $req['location_name'] . ' requisition ' . $id . ' has one approval line completed and is awaiting ' . $waiting . '.' );}
				return rest_ensure_response( $this->detail( new WP_REST_Request( 'GET', '/stockland/v16/requisitions/' . $id ) ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $wh_lock );
			return new WP_Error( 'requisition_decision_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Applies a warehouse reservation or picking transition.
	 *
	 * @param WP_REST_Request $r REST request with requisition identifier and action.
	 * @return mixed REST response or a WP_Error.
	 * @throws Exception When a guarded warehouse transaction fails.
	 */
	public function warehouse_action( WP_REST_Request $r ) {
		global $wpdb;
		$id      = sanitize_text_field( $r['id'] );
		$payload = $r->get_json_params();
		$action  = sanitize_key( $payload['action'] ?? '' );
		if ( ! in_array( $action, array( 'reserve', 'pick' ), true ) ) {
			return $this->deny( 'invalid_action', 'Warehouse action must be reserve or pick.', 400 );
		}
		if ( 'reserve' === $action && ! Stockland_Authority::can_reserve_requisition() ) {
			return $this->deny( 'forbidden', 'Only the Store/Warehouse Manager can reserve an approved requisition.', 403 );
		}
		if ( 'pick' === $action && ! Stockland_Authority::can_pick_requisition() ) {
			return $this->deny( 'forbidden', 'Only the Central Warehouse Manager or a Warehouse Employee may pick a reserved requisition.', 403 );
		}
		$req = $this->req( $id );
		if ( ! $req ) {
			return $this->deny( 'not_found', 'Requisition not found.', 404 );
		}if ( ! in_array( $req['status'], array( 'Approved', 'Reserved', 'Picked' ), true ) ) {
			return $this->deny( 'invalid_state', 'This requisition is not ready for warehouse action.', 409 );
		}
		$wh   = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$lock = Stockland_DB::acquire_day_lock( $wh, current_time( 'Y-m-d' ) );
		if ( ! $lock['ok'] ) {
			return $this->deny( 'busy', 'Central Warehouse is processing another requisition. Please retry.', 409 );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'transaction_failed', 'Could not start warehouse requisition action.', array( 'status' => 500 ) );}
		try {
			$req   = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE requisition_id=%s FOR UPDATE', Stockland_DB::table( 'requisitions' ), $id ), ARRAY_A );
			$items = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE requisition_id=%s ORDER BY id', Stockland_DB::table( 'requisition_items' ), $id ), ARRAY_A );
			if ( ! $req || ! is_array( $items ) || empty( $items ) ) {
				throw new Exception( 'The requisition header or approved items could not be loaded for warehouse action.' );
			}
			$pickup      = isset( $payload['pickup_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $payload['pickup_date'] ) ? $payload['pickup_date'] : ( $req['pickup_date'] ?? null );
			$direct_pick = 'pick' === $action && 'Approved' === $req['status'];
			if ( $direct_pick ) {
				$pickup   = current_time( 'Y-m-d' );
				$prepared = 0;
				foreach ( $items as $item ) {
					$transfer = Stockland_DB::read_row(
						$wpdb->prepare( "SELECT * FROM %i WHERE requisition_item_id=%d AND requisition_id=%s AND status='Approved' ORDER BY created_at LIMIT 1 FOR UPDATE", Stockland_DB::table( 'transfers' ), (int) $item['id'], $id ),
						ARRAY_A
					);
					if ( ! $transfer ) {
						throw new Exception( 'Approved warehouse transfer record is missing for requisition item ' . $item['id'] . '.' );
					}
					$qty = (float) $transfer['quantity'];
					if ( $qty <= 0 || $qty > (float) $item['quantity_approved'] ) {
						throw new Exception( 'The approved transfer quantity is invalid for ' . $item['sku'] . '.' );
					}
					$available = Stockland_DB::get_available_stock( $wh, (string) $item['sku'] );
					if ( $available < $qty ) {
						throw new Exception( 'Insufficient available Central Warehouse stock for ' . $item['sku'] . '. Available ' . $available . ', required ' . $qty . '.' );
					}
					$variant_id = (int) ( $item['variant_id'] ?? ( Stockland_DB::get_product( $item['sku'] )['variant_id'] ?? 0 ) );
					$res_ok     = Stockland_DB::insert_record(
						Stockland_DB::table( 'reservations' ),
						array(
							'location_id'       => $wh,
							'sku'               => (string) $item['sku'],
							'variant_id'        => $variant_id,
							'requisition_id'    => $id,
							'quantity_reserved' => $qty,
							'status'            => 'Active',
							'transfer_id'       => $transfer['transfer_id'],
							'created_at'        => current_time( 'mysql' ),
						)
					);
					if ( false === $res_ok ) {
						throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Immediate-pick reservation could not be saved.' );
					}
					$tr_ok = Stockland_DB::update_record(
						Stockland_DB::table( 'transfers' ),
						array(
							'status'      => 'Reserved',
							'pickup_date' => $pickup,
							'reserved_by' => get_current_user_id(),
							'reserved_at' => current_time( 'mysql' ),
						),
						array( 'transfer_id' => $transfer['transfer_id'] )
					);
					if ( false === $tr_ok ) {
						throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Immediate-pick transfer could not be prepared.' );
					}
					++$prepared;
				}
				if ( 0 === $prepared ) {
					throw new Exception( 'No approved quantity is available to pick.' );
				}
				if ( false === Stockland_DB::update_record(
					Stockland_DB::table( 'requisitions' ),
					array(
						'status'                => 'Reserved',
						'pickup_date'           => $pickup,
						'warehouse_reserved_by' => get_current_user_id(),
						'warehouse_reserved_at' => current_time( 'mysql' ),
					),
					array( 'requisition_id' => $id )
				) ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Immediate-pick requisition state could not be prepared.' );
				}
				if ( ! Stockland_Workflow::event(
					'requisition',
					$id,
					'Approved',
					'Reserved',
					get_current_user_id(),
					$req['branch_location_id'],
					array(
						'pickup_date' => $pickup,
						'mode'        => 'immediate_pick',
					)
				) ) {
					throw new Exception( 'Immediate-pick reservation workflow event could not be recorded.' );
				}
				if ( ! Stockland_Audit::log( get_current_user_id(), $req['branch_location_id'], 'WAREHOUSE_RESERVE', null, null, 'Approved', 'Reserved', 'Reservation created inside the authorized immediate-pick transaction.', $id ) ) {
					throw new Exception( 'Immediate-pick reservation audit event could not be recorded.' );
				}
				$req['status'] = 'Reserved';
			}
			if ( 'reserve' === $action ) {
				if ( ! $pickup ) {
					throw new Exception( 'Warehouse pickup date is required when reserving a branch requisition.' );
				}
				foreach ( $items as $item ) {
					$qty = (float) $item['quantity_approved'];
					if ( $qty <= 0 ) {
						continue;
					}
					$transfer = Stockland_DB::read_row( $wpdb->prepare( "SELECT * FROM %i WHERE requisition_item_id=%d AND requisition_id=%s AND status NOT IN('Rejected','Cancelled','Completed') ORDER BY created_at LIMIT 1", Stockland_DB::table( 'transfers' ), (int) $item['id'], $id ), ARRAY_A );
					if ( ! $transfer ) {
						throw new Exception( 'Required warehouse transfer record is missing for requisition item ' . $item['id'] . '. The requisition cannot advance.' );
					}
					if ( 'Approved' === $transfer['status'] ) {
						$available = Stockland_DB::get_available_stock( $wh, $item['sku'] );
						if ( $available < $qty ) {
							throw new Exception( 'Insufficient available warehouse stock for ' . $item['sku'] . '. Available ' . $available . ', approved ' . $qty . '.' );
						}
						$res_ok = Stockland_DB::insert_record(
							Stockland_DB::table( 'reservations' ),
							array(
								'location_id'       => $wh,
								'sku'               => $item['sku'],
								'variant_id'        => (int) ( $item['variant_id'] ?? ( Stockland_DB::get_product( $item['sku'] )['variant_id'] ?? 0 ) ),
								'requisition_id'    => $id,
								'quantity_reserved' => $qty,
								'status'            => 'Active',
								'transfer_id'       => $transfer['transfer_id'],
								'created_at'        => current_time( 'mysql' ),
							)
						);
						if ( false === $res_ok ) {
							throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Warehouse reservation could not be saved.' );
						}
						$tr_ok = Stockland_DB::update_record(
							Stockland_DB::table( 'transfers' ),
							array(
								'status'      => 'Reserved',
								'pickup_date' => $pickup,
								'reserved_by' => get_current_user_id(),
								'reserved_at' => current_time( 'mysql' ),
							),
							array( 'transfer_id' => $transfer['transfer_id'] )
						);
						if ( false === $tr_ok ) {
							throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Transfer reservation state could not be saved.' );
						}
					} elseif ( 'Reserved' === $transfer['status'] ) {
						$tr_pickup_ok = Stockland_DB::update_record( Stockland_DB::table( 'transfers' ), array( 'pickup_date' => $pickup ), array( 'transfer_id' => $transfer['transfer_id'] ) );
						if ( false === $tr_pickup_ok ) {
							throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Transfer pickup date could not be saved.' );
						}
					}
				}
				$res_req_ok = Stockland_DB::update_record(
					Stockland_DB::table( 'requisitions' ),
					array(
						'status'                => 'Reserved',
						'pickup_date'           => $pickup,
						'warehouse_reserved_by' => get_current_user_id(),
						'warehouse_reserved_at' => current_time( 'mysql' ),
					),
					array( 'requisition_id' => $id )
				);
				if ( false === $res_req_ok ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Requisition reservation state could not be saved.' );
				}
				if ( ! Stockland_Workflow::event( 'requisition', $id, $req['status'], 'Reserved', get_current_user_id(), $req['branch_location_id'], array( 'pickup_date' => $pickup ) ) ) {
					throw new Exception( 'Workflow event could not be recorded.' );
				}
				if ( ! Stockland_Audit::log( get_current_user_id(), $req['branch_location_id'], 'WAREHOUSE_RESERVE', null, null, $req['status'], 'Reserved', 'Warehouse reserved approved requisition; pickup ' . $pickup, $id ) ) {
					throw new Exception( 'Audit event could not be recorded.' );
				}
				Stockland_Notifications::notify_branch( (int) $req['branch_location_id'], $id, 'Warehouse reservation confirmed', 'Requisition ' . $id . ' is reserved. Pickup appointment: ' . $pickup . '. Please bring the approved receiving representative.', 'info' );
				Stockland_Notifications::push_role( 'warehouse_manager', $wh, 'warehouse_requisition', 'Reserved requisition — PICK required', 'Requisition ' . $id . ' is reserved. Next action: the Central Warehouse Manager or a Warehouse Employee may PICK the approved quantities. Pickup appointment: ' . $pickup . '.', 'requisition', $id, 'action' );
				Stockland_Notifications::push_role( 'warehouse_employee', $wh, 'warehouse_requisition', 'Reserved requisition — PICK required', 'Requisition ' . $id . ' is reserved. Next action: the Central Warehouse Manager or a Warehouse Employee may PICK the approved quantities. Pickup appointment: ' . $pickup . '.', 'requisition', $id, 'action' );
			} else {
				if ( ! in_array( $req['status'], array( 'Reserved', 'Picked' ), true ) ) {
					throw new Exception( 'Reserve the requisition before picking.' );
				}
				foreach ( $items as $item ) {
					$transfer = Stockland_DB::read_row( $wpdb->prepare( "SELECT * FROM %i WHERE requisition_item_id=%d AND requisition_id=%s AND status='Reserved' LIMIT 1", Stockland_DB::table( 'transfers' ), (int) $item['id'], $id ), ARRAY_A );
					if ( ! $transfer ) {
						throw new Exception( 'Required warehouse transfer record is missing for requisition item ' . $item['id'] . '. The requisition cannot advance.' );
					}$active = (float) Stockland_DB::read_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity_reserved),0) FROM %i WHERE transfer_id=%s AND status='Active'", Stockland_DB::table( 'reservations' ), $transfer['transfer_id'] ) );
					if ( $active < (float) $transfer['quantity'] ) {
						throw new Exception( 'Active reservation missing for ' . $transfer['sku'] . '.' );
					}$dispatch = (float) $transfer['quantity'];
					$cur       = $transfer;
					if ( false === Stockland_Ledger::post_transfer_dispatched( $cur, get_current_user_id() ) ) {
						throw new Exception( 'Warehouse ledger could not post the pick for ' . $transfer['sku'] . '.' );
					}$released = Stockland_DB::execute_query( $wpdb->prepare( "UPDATE %i SET status='Released',released_at=%s WHERE transfer_id=%s AND status='Active'", Stockland_DB::table( 'reservations' ), current_time( 'mysql' ), $transfer['transfer_id'] ) );
					if ( false === $released ) {
						throw new Exception( 'Warehouse reservation could not be released.' );
					}$tr_pick_ok = Stockland_DB::update_record(
						Stockland_DB::table( 'transfers' ),
						array(
							'status'         => 'Picked',
							'dispatched_qty' => $dispatch,
							'dispatched_by'  => get_current_user_id(),
							'dispatched_at'  => current_time( 'mysql' ),
							'picked_by'      => get_current_user_id(),
							'picked_at'      => current_time( 'mysql' ),
						),
						array( 'transfer_id' => $transfer['transfer_id'] )
					);
					if ( false === $tr_pick_ok ) {
						throw new Exception( 'Transfer pick state could not be saved.' );
					}$ri_pick_ok = Stockland_DB::update_record( Stockland_DB::table( 'requisition_items' ), array( 'quantity_dispatched' => $dispatch ), array( 'id' => (int) $item['id'] ) );
					if ( false === $ri_pick_ok ) {
						throw new Exception( 'Requisition dispatched quantity could not be saved.' );
					}
				}
				$pick_req_ok = Stockland_DB::update_record(
					Stockland_DB::table( 'requisitions' ),
					array(
						'status'              => 'Picked',
						'warehouse_picked_by' => get_current_user_id(),
						'warehouse_picked_at' => current_time( 'mysql' ),
					),
					array( 'requisition_id' => $id )
				);
				if ( false === $pick_req_ok ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Requisition pick state could not be saved.' );
				}
				if ( ! Stockland_Workflow::event( 'requisition', $id, $req['status'], 'Picked', get_current_user_id(), $req['branch_location_id'], array( 'pickup_date' => $req['pickup_date'] ) ) ) {
					throw new Exception( 'Workflow event could not be recorded.' );
				}
				if ( ! Stockland_Audit::log( get_current_user_id(), $req['branch_location_id'], 'WAREHOUSE_PICK', null, null, $req['status'], 'Picked', 'Warehouse picked approved requisition', $id ) ) {
					throw new Exception( 'Audit event could not be recorded.' );
				}
				Stockland_Notifications::notify_branch( (int) $req['branch_location_id'], $id, 'Stock ready — RECEIVE required', 'Requisition ' . $id . ' has been PICKED. Next action: Branch Manager must RECEIVE the picked stock on ' . $req['pickup_date'] . '.', 'action' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Warehouse requisition action commit failed.' );
			}Stockland_DB::release_day_lock( $lock );
			return rest_ensure_response( $this->detail( $r ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_day_lock( $lock );
			return new WP_Error( 'warehouse_action_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}

	/**
	 * Returns receipt/availability information without posting stock directly.
	 *
	 * @param WP_REST_Request $r REST request containing the requisition identifier.
	 * @return mixed REST response or a WP_Error.
	 * @throws Exception When receipt quantities, transfer state or ledger invariants fail.
	 */
	public function receive_info( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! Stockland_Authority::can_receive_requisition() ) {
			return $this->deny( 'forbidden', 'Only the requesting Branch Manager can receive a picked requisition.', 403 );
		}
		$id  = sanitize_text_field( $r['id'] );
		$req = $this->req( $id );
		if ( ! $req ) {
			return $this->deny( 'not_found', 'Requisition not found.', 404 );
		}
		if ( ! Stockland_Authority::can_access_location( (int) $req['branch_location_id'] ) ) {
			return $this->deny( 'forbidden', 'This requisition is outside your authorized branch scope.', 403 );
		}
		if ( 'Picked' !== $req['status'] ) {
			return $this->deny( 'invalid_state', 'Only a Picked requisition can be received. No In Transit stage is used.', 409 );
		}
		$payload   = $r->get_json_params();
		$requested = is_array( $payload['receipts'] ?? null ) ? $payload['receipts'] : array();
		$by        = array();
		foreach ( $requested as $line ) {
			$tid = sanitize_text_field( $line['transfer_id'] ?? '' );
			if ( $tid ) {
				$by[ $tid ] = array(
					'received_qty' => isset( $line['received_qty'] ) ? (float) $line['received_qty'] : null,
					'reason'       => sanitize_text_field( $line['reason'] ?? '' ),
				);
			}
		}
		$lock  = (int) Stockland_DB::get_location_id( 'Central Warehouse' );
		$locks = Stockland_DB::acquire_location_locks( array( $lock, (int) $req['branch_location_id'] ), current_time( 'Y-m-d' ) );
		if ( ! $locks['ok'] ) {
			return $this->deny( 'busy', 'A stock operation is already being processed for this requisition. Please retry.', 409 );
		}
		if ( false === Stockland_DB::execute_query( 'START TRANSACTION' ) ) {
			Stockland_DB::release_location_locks( $locks['locks'] );
			return new WP_Error( 'transaction_failed', 'Could not start the branch receipt transaction.', array( 'status' => 500 ) );}
		try {
			$locked = Stockland_DB::read_row( $wpdb->prepare( 'SELECT * FROM %i WHERE requisition_id=%s FOR UPDATE', Stockland_DB::table( 'requisitions' ), $id ), ARRAY_A );
			if ( ! $locked ) {
				throw new Exception( 'Requisition disappeared during receipt.' );
			}if ( 'Picked' !== $locked['status'] ) {
				throw new Exception( 'Only a Picked requisition can be received. Current state: ' . $locked['status'] . '.' );
			}
			$transfers = Stockland_DB::read_results( $wpdb->prepare( 'SELECT * FROM %i WHERE requisition_id=%s ORDER BY created_at FOR UPDATE', Stockland_DB::table( 'transfers' ), $id ), ARRAY_A );
			if ( ! $transfers ) {
				throw new Exception( 'No warehouse supply lines exist for this requisition.' );
			}
			$seen           = array();
			$total_received = 0;
			$variance_count = 0;
			foreach ( $transfers as $t ) {
				if ( ( $t['status'] ?? '' ) !== 'Picked' ) {
					throw new Exception( 'Every requisition supply line must be Picked before branch receipt. Transfer ' . $t['transfer_id'] . ' is ' . $t['status'] . '.' );
				}
				$dispatch = (float) $t['dispatched_qty'];
				if ( $dispatch <= 0 ) {
					throw new Exception( 'Transfer ' . $t['transfer_id'] . ' has no dispatched quantity.' );
				}
				$opt      = $by[ $t['transfer_id'] ] ?? array(
					'received_qty' => null,
					'reason'       => '',
				);
				$received = null === $opt['received_qty'] ? $dispatch : (float) $opt['received_qty'];
				$reason   = trim( (string) $opt['reason'] );
				if ( $received < 0 || $received > $dispatch + 0.00001 ) {
					throw new Exception( 'Received quantity for ' . $t['sku'] . ' must be between 0 and ' . $dispatch . '.' );
				}
				if ( abs( $received - $dispatch ) > 0.00001 && '' === $reason ) {
					throw new Exception( 'A receiving variance for ' . $t['sku'] . ' requires a reason.' );
				}
				$cur                 = $t;
				$cur['received_qty'] = $received;
				if ( $received > 0 && false === Stockland_Ledger::post_transfer_received( $cur, get_current_user_id() ) ) {
					throw new Exception( 'Branch stock ledger could not post receipt for ' . $t['sku'] . '.' );
				}
				$var = $received - $dispatch;
				if ( abs( $var ) > 0.00001 ) {
					++$variance_count;
				}
				if ( false === Stockland_DB::update_record(
					Stockland_DB::table( 'transfers' ),
					array(
						'status'         => 'Received',
						'received_qty'   => $received,
						'variance_qty'   => $var,
						'received_by'    => get_current_user_id(),
						'received_at'    => current_time( 'mysql' ),
						'receipt_reason' => $reason,
					),
					array( 'transfer_id' => $t['transfer_id'] )
				) ) {
					throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not save receipt for ' . $t['sku'] . '.' );
				}
				$ri = (int) $t['requisition_item_id'];
				if ( $ri ) {
					$prior = (float) Stockland_DB::read_var( $wpdb->prepare( 'SELECT quantity_received FROM %i WHERE id=%d FOR UPDATE', Stockland_DB::table( 'requisition_items' ), $ri ) );
					$ok    = Stockland_DB::update_record( Stockland_DB::table( 'requisition_items' ), array( 'quantity_received' => $prior + $received ), array( 'id' => $ri ) );
					if ( false === $ok ) {
						throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Could not update requisition received quantity.' );
					}
				}
				$seen[ $t['transfer_id'] ] = true;
				$total_received           += $received;
			}
			if ( count( $seen ) !== count( $transfers ) ) {
				throw new Exception( 'All requisition supply lines must be processed.' );
			}
			if ( false === Stockland_DB::update_record( Stockland_DB::table( 'requisitions' ), array( 'status' => 'Received' ), array( 'requisition_id' => $id ) ) ) {
				throw new Exception( $wpdb->last_error ? $wpdb->last_error : 'Requisition receipt state could not be saved.' );
			}
			if ( ! Stockland_Workflow::event(
				'requisition',
				$id,
				'Picked',
				'Received',
				get_current_user_id(),
				$req['branch_location_id'],
				array(
					'receipt_variance_lines' => $variance_count,
					'received_units'         => $total_received,
				)
			) ) {
				throw new Exception( 'Workflow event could not be recorded.' );
			}
			if ( ! Stockland_Audit::log( get_current_user_id(), $req['branch_location_id'], 'REQUISITION_RECEIVE', null, $total_received, 'Picked', 'Received', $variance_count ? 'Branch receipt included one or more variance lines' : 'Branch received picked requisition', $id ) ) {
				throw new Exception( 'Audit event could not be recorded.' );
			}
			if ( false === Stockland_DB::execute_query( 'COMMIT' ) ) {
				throw new Exception( 'Requisition receipt commit failed.' );
			}
			Stockland_DB::release_location_locks( $locks['locks'] );
			Stockland_Notifications::push_role( 'management', null, 'requisition_received', 'Requisition received', 'Requisition ' . $id . ' was received by ' . $req['location_name'] . '.', 'requisition', $id, $variance_count ? 'warning' : 'info' );
			Stockland_Notifications::push_role( 'owner', null, 'requisition_received', 'Requisition received', 'Requisition ' . $id . ' was received by ' . $req['location_name'] . '.', 'requisition', $id, $variance_count ? 'warning' : 'info' );
			if ( ! empty( $req['requested_by'] ) ) {
				Stockland_Notifications::push_user( (int) $req['requested_by'], 'requisition_received', 'Requisition received', 'Your requisition ' . $id . ' has been received by ' . $req['location_name'] . '.', 'requisition', $id, $variance_count ? 'warning' : 'info' );
			}
			return rest_ensure_response( $this->detail( new WP_REST_Request( 'GET', '/stockland/v16/requisitions/' . $id ) ) );
		} catch ( Throwable $e ) {
			Stockland_DB::execute_query( 'ROLLBACK' );
			Stockland_DB::release_location_locks( $locks['locks'] );
			return new WP_Error( 'requisition_receive_failed', $e->getMessage(), array( 'status' => 409 ) );}
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
