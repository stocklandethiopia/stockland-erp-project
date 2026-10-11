<?php
/**
 * Persists role-scoped operational notifications for Stockland ERP.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notification persistence and role/location delivery helpers.
 *
 * @package Stockland_ERP
 */
class Stockland_Notifications {
	/**
	 * Creates a direct notification for one WordPress user.
	 *
	 * @param int         $user_id Target user identifier.
	 * @param string      $type Notification type key.
	 * @param string      $title Notification title.
	 * @param string      $message Notification body.
	 * @param string|null $entity_type Optional related entity type.
	 * @param mixed       $entity_id Optional related entity identifier.
	 * @param string      $severity Notification severity.
	 * @return bool Whether the notification row was inserted.
	 */
	public static function push_user( $user_id, $type, $title, $message, $entity_type = null, $entity_id = null, $severity = 'info' ) {
		global $wpdb;
		if ( ! $user_id ) {
			return false;
		}
		return false !== $wpdb->insert(
			Stockland_DB::table( 'notifications' ),
			array(
				'user_id'           => (int) $user_id,
				'audience_role'     => null,
				'location_id'       => null,
				'notification_type' => sanitize_key( $type ),
				'title'             => sanitize_text_field( $title ),
				'message'           => wp_kses_post( $message ),
				'entity_type'       => $entity_type ? sanitize_key( $entity_type ) : null,
				'entity_id'         => null !== $entity_id ? sanitize_text_field( (string) $entity_id ) : null,
				'severity'          => sanitize_key( $severity ),
				'is_read'           => 0,
				'created_at'        => current_time( 'mysql' ),
			)
		);
	}
	/**
	 * Creates a notification for a role, optionally scoped to a location.
	 *
	 * @param string      $role Target ERP role.
	 * @param int|null    $location_id Optional target location identifier.
	 * @param string      $type Notification type key.
	 * @param string      $title Notification title.
	 * @param string      $message Notification body.
	 * @param string|null $entity_type Optional related entity type.
	 * @param mixed       $entity_id Optional related entity identifier.
	 * @param string      $severity Notification severity.
	 * @return bool Whether the notification row was inserted.
	 */
	public static function push_role( $role, $location_id, $type, $title, $message, $entity_type = null, $entity_id = null, $severity = 'info' ) {
		global $wpdb;
		return false !== $wpdb->insert(
			Stockland_DB::table( 'notifications' ),
			array(
				'user_id'           => null,
				'audience_role'     => sanitize_key( $role ),
				'location_id'       => $location_id ? (int) $location_id : null,
				'notification_type' => sanitize_key( $type ),
				'title'             => sanitize_text_field( $title ),
				'message'           => wp_kses_post( $message ),
				'entity_type'       => $entity_type ? sanitize_key( $entity_type ) : null,
				'entity_id'         => null !== $entity_id ? sanitize_text_field( (string) $entity_id ) : null,
				'severity'          => sanitize_key( $severity ),
				'is_read'           => 0,
				'created_at'        => current_time( 'mysql' ),
			)
		);
	}
	/**
	 * Lists unread notifications visible to a user's role and assigned locations.
	 *
	 * @param int|null $user_id User identifier; defaults to the current user.
	 * @param int      $limit Maximum number of rows to return, capped at 100.
	 * @return array Unread notification rows visible to the user.
	 */
	public static function unread( $user_id = null, $limit = 50 ) {
		global $wpdb;
		$user_id = $user_id ? $user_id : get_current_user_id();
		$limit   = max( 1, min( 100, (int) $limit ) );
		$profile = Stockland_Authority::profile( $user_id );
		if ( ! $profile ) {
			return array();
		}
		$role           = sanitize_key( $profile['role'] );
		$loc            = (int) ( $profile['branch_location_id'] ?? 0 );
		$assigned_table = $wpdb->prefix . 'stock_user_locations';
		$sql            = 'SELECT n.*,l.location_name FROM %i n LEFT JOIN %i l ON l.id=n.location_id WHERE n.is_read=0 AND (n.user_id=%d OR (n.audience_role=%s AND (n.location_id IS NULL OR n.location_id=%d OR EXISTS (SELECT 1 FROM %i sul WHERE sul.wp_user_id=%d AND sul.location_id=n.location_id AND sul.is_active=1 AND (sul.effective_from IS NULL OR sul.effective_from<=CURDATE()) AND (sul.effective_to IS NULL OR sul.effective_to>=CURDATE()))))) ORDER BY n.id DESC LIMIT %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed query template with placeholders for both tables, the capped LIMIT and every user/role/location value; execution always passes through prepare().
		return Stockland_DB::read_results( $wpdb->prepare( $sql, Stockland_DB::table( 'notifications' ), Stockland_DB::table( 'locations' ), (int) $user_id, $role, $loc, $assigned_table, (int) $user_id, $limit ), ARRAY_A );
	}
	/**
	 * Marks a notification read only when it is visible to the user.
	 *
	 * @param int      $id Notification identifier.
	 * @param int|null $user_id User identifier; defaults to the current user.
	 * @return bool Whether the scoped update succeeded.
	 */
	public static function mark_read( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ? $user_id : get_current_user_id();
		$profile = Stockland_Authority::profile( $user_id );
		if ( ! $profile ) {
			return false;
		}
		$role           = sanitize_key( $profile['role'] );
		$loc            = (int) ( $profile['branch_location_id'] ?? 0 );
		$assigned_table = $wpdb->prefix . 'stock_user_locations';
		$sql            = 'UPDATE %i AS n SET is_read=1,read_at=%s WHERE n.id=%d AND (n.user_id=%d OR (n.audience_role=%s AND (n.location_id IS NULL OR n.location_id=%d OR EXISTS (SELECT 1 FROM %i sul WHERE sul.wp_user_id=%d AND sul.location_id=n.location_id AND sul.is_active=1 AND (sul.effective_from IS NULL OR sul.effective_from<=CURDATE()) AND (sul.effective_to IS NULL OR sul.effective_to>=CURDATE())))))';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed UPDATE template; table identifiers, timestamp, notification ID, user, role, location and assignment table are all bound through prepare().
		return false !== Stockland_DB::execute_query( $wpdb->prepare( $sql, Stockland_DB::table( 'notifications' ), current_time( 'mysql' ), (int) $id, (int) $user_id, $role, $loc, $assigned_table, (int) $user_id ) );
	}
	/**
	 * Notifies Management and Owner that a requisition awaits review.
	 *
	 * @param mixed  $requisition_id Requisition identifier.
	 * @param int    $branch_location_id Requesting branch identifier.
	 * @param string $title Notification title.
	 * @param string $message Notification body.
	 * @return bool Whether notification dispatch was requested.
	 */
	public static function notify_approvers( $requisition_id, $branch_location_id, $title, $message ) {
		self::push_role( 'management', null, 'requisition', $title, $message, 'requisition', $requisition_id, 'action' );
		self::push_role( 'owner', null, 'requisition', $title, $message, 'requisition', $requisition_id, 'action' );
		return true;
	}
	/**
	 * Notifies warehouse staff about a requisition workflow action.
	 *
	 * @param int    $location_id Warehouse location identifier.
	 * @param mixed  $requisition_id Requisition identifier.
	 * @param string $title Notification title.
	 * @param string $message Notification body.
	 * @return bool Whether notification dispatch was requested.
	 */
	public static function notify_warehouse( $location_id, $requisition_id, $title, $message ) {
		self::push_role( 'warehouse_employee', $location_id, 'warehouse_requisition', $title, $message, 'requisition', $requisition_id, 'action' );
		self::push_role( 'warehouse_manager', $location_id, 'warehouse_requisition', $title, $message, 'requisition', $requisition_id, 'action' );
		return true;
	}
	/**
	 * Notifies branch staff about a requisition workflow action.
	 *
	 * @param int    $location_id Branch location identifier.
	 * @param mixed  $requisition_id Requisition identifier.
	 * @param string $title Notification title.
	 * @param string $message Notification body.
	 * @param string $severity Notification severity.
	 * @return bool Whether notification dispatch was requested.
	 */
	public static function notify_branch( $location_id, $requisition_id, $title, $message, $severity = 'action' ) {
		self::push_role( 'branch_manager', $location_id, 'requisition', $title, $message, 'requisition', $requisition_id, $severity );
		self::push_role( 'sales_employee', $location_id, 'requisition', $title, $message, 'requisition', $requisition_id, $severity );
		return true;
	}
	/**
	 * Sends a high-priority exception notice to Management and Owner.
	 *
	 * @param string      $title Notification title.
	 * @param string      $message Notification body.
	 * @param string|null $entity_type Optional related entity type.
	 * @param mixed       $entity_id Optional related entity identifier.
	 * @return bool Whether notification dispatch was requested.
	 */
	public static function notify_exception_owners( $title, $message, $entity_type = null, $entity_id = null ) {
		self::push_role( 'management', null, 'exception', $title, $message, $entity_type, $entity_id, 'danger' );
		self::push_role( 'owner', null, 'exception', $title, $message, $entity_type, $entity_id, 'danger' );
		return true;
	}
	/**
	 * Notifies Management and Owner after a committed Product Master change.
	 *
	 * Notification failures must never roll back the already committed product.
	 *
	 * @param string $action Product Master action code.
	 * @param array  $product Saved Product Master record and optional counts.
	 * @return bool Whether all applicable notifications were recorded.
	 */
	public static function notify_product_master( $action, $product ) {
		$sku  = sanitize_text_field( (string) ( $product['sku'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $product['product_name'] ?? $sku ) );
		if ( '' === $sku ) {
			return false;
		}
		$count = isset( $product['created_count'] ) ? (int) $product['created_count'] : 0;
		if ( 'VARIANT_MATRIX' === $action ) {
			$title   = 'Product variants created';
			$message = $count . ' new variant(s) were created under ' . $sku . ' — ' . ( $name ? $name : $sku ) . '. Review Product Master when needed.';
		} else {
			$verb    = 'UPDATED' === $action ? 'updated' : 'created';
			$title   = 'Product Master ' . $verb;
			$message = $name . ' (' . $sku . ') was ' . $verb . ' in Product Master. No inventory quantity was changed.';
		}
		$ok1 = self::push_role( 'management', null, 'product_master', $title, $message, 'product', $sku, 'info' );
		$ok2 = self::push_role( 'owner', null, 'product_master', $title, $message, 'product', $sku, 'info' );
		// The user who performed the action also gets a direct confirmation when their
		// current ERP role is not already covered by the management/owner audiences.
		$actor_id   = (int) get_current_user_id();
		$actor_role = '';
		try {
			$actor_profile = Stockland_Authority::profile( $actor_id );
			$actor_role    = sanitize_key( (string) ( $actor_profile['role'] ?? '' ) ); } catch ( Throwable $ignore ) {
				// A missing profile must not block confirmation of the committed Product Master action.
				$actor_role = '';
			}
			$ok3 = ( $actor_id > 0 && ! in_array( $actor_role, array( 'management', 'owner' ), true ) )
			? self::push_user( $actor_id, 'product_master', $title, $message, 'product', $sku, 'info' )
			: true;
			return $ok1 && $ok2 && $ok3;
	}
}
