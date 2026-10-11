<?php
/**
 * Compatibility bridge for the front-end ERP application.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Compatibility bridge. The actual Stockland business UI is front-end/PWA only. */
class Stockland_App {
	/**
	 * Retains the legacy class contract without registering admin-only UI.
	 *
	 * @return void
	 */
	public function __construct() {}
}
