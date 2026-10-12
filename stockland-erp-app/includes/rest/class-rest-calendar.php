<?php
/**
 * REST controller source for Stockland ERP operational workflows.
 *
 * @package Stockland_ERP
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for viewing and maintaining the Stockland business calendar.
 *
 * @package Stockland_ERP
 */
final class Stockland_REST_Calendar extends Stockland_REST_Base {
	/**
	 * Registers the business-calendar REST route hook.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'stockland_register_rest_routes', array( $this, 'routes' ) ); }

	/**
	 * Checks whether the current user may change calendar rules.
	 *
	 * @return true|WP_Error True when the user has the settings capability.
	 */
	private function manage() {
		return current_user_can( 'stockland_manage_settings' )
			? true
			: new WP_Error( 'forbidden', 'Only Management or Owner may change the Business Calendar.', array( 'status' => 403 ) );
	}

	/**
	 * Registers calendar read and management endpoints.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/business-calendar',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'month' ),
				'permission_callback' => function () {
					return Stockland_Authority::assert_profile(); },
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/business-calendar/date',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_date' ),
				'permission_callback' => function () {
					return $this->manage(); },
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/business-calendar/weekends',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_weekends' ),
				'permission_callback' => function () {
					return $this->manage(); },
			)
		);
	}

	/**
	 * Returns the configured business-calendar status for the requested month.
	 *
	 * @param WP_REST_Request $r REST request containing an optional month.
	 * @return WP_REST_Response|WP_Error Calendar data or a validation error.
	 */
	public function month( WP_REST_Request $r ) {
		try {
			$requested_month = $r->get_param( 'month' );
			$month           = sanitize_text_field( $requested_month ? $requested_month : current_time( 'Y-m' ) );
			return rest_ensure_response( Stockland_Business_Calendar::month( $month ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'calendar_read_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Adds or resets one Management-controlled date exception.
	 *
	 * @param WP_REST_Request $r REST request containing date, status and label.
	 * @return WP_REST_Response|WP_Error Updated calendar data or a settings error.
	 */
	public function save_date( WP_REST_Request $r ) {
		$p   = $r->get_json_params();
		$out = Stockland_Business_Calendar::save_exception( sanitize_text_field( $p['date'] ?? '' ), $p['status'] ?? '', $p['label'] ?? '', get_current_user_id() );
		return is_wp_error( $out ) ? $out : rest_ensure_response( Stockland_Business_Calendar::month( substr( sanitize_text_field( $p['date'] ?? current_time( 'Y-m-d' ) ), 0, 7 ) ) );
	}

	/**
	 * Saves the weekly closed-day policy selected by Management.
	 *
	 * @param WP_REST_Request $r REST request containing weekend day numbers.
	 * @return WP_REST_Response|WP_Error Updated calendar data or a settings error.
	 */
	public function save_weekends( WP_REST_Request $r ) {
		$p   = $r->get_json_params();
		$out = Stockland_Business_Calendar::save_weekends( $p['weekend_days'] ?? array(), get_current_user_id() );
		return is_wp_error( $out ) ? $out : rest_ensure_response( Stockland_Business_Calendar::month( current_time( 'Y-m' ) ) );
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
