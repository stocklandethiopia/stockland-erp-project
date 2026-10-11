<?php
/**
 * Records workflow transitions for auditable ERP operations.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists workflow state-transition history.
 *
 * @package Stockland_ERP
 */
class Stockland_Workflow {
	/**
	 * Record a workflow event.
	 *
	 * @param string   $entity_type Entity type being transitioned.
	 * @param int      $entity_id Entity identifier.
	 * @param string   $from Previous workflow status.
	 * @param string   $to New workflow status.
	 * @param int      $user_id User responsible for the transition.
	 * @param int|null $location_id Related operational location, when scoped.
	 * @param array    $meta Additional event metadata.
	 * @return int|false Inserted event identifier, or false when persistence fails.
	 */
	public static function event( $entity_type, $entity_id, $from, $to, $user_id, $location_id = null, $meta = array() ) {
		global $wpdb;
		$t  = Stockland_DB::table( 'workflow_events' );
		$ok = Stockland_DB::insert_record(
			$t,
			array(
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'from_status' => $from,
				'to_status'   => $to,
				'user_id'     => (int) $user_id,
				'location_id' => $location_id,
				'meta'        => wp_json_encode( $meta ),
			)
		);
		if ( false === $ok ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Determine whether a workflow transition is permitted.
	 *
	 * @param string $from Current workflow status.
	 * @param string $to Requested next status.
	 * @param array  $map Allowed destination statuses indexed by current status.
	 * @return bool True when the requested transition is explicitly allowed.
	 */
	public static function transition( $from, $to, $map ) {
		return in_array( $to, $map[ $from ] ?? array(), true );
	}
}
