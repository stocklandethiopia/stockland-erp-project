<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stockland_Workflow {
	public static function event( $entity_type, $entity_id, $from, $to, $user_id, $location_id = null, $meta = array() ) {
		global $wpdb;
		$t  = Stockland_DB::table( 'workflow_events' );
		$ok = $wpdb->insert(
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
	public static function transition( $from, $to, $map ) {
		return in_array( $to, $map[ $from ] ?? array(), true ); }
}
