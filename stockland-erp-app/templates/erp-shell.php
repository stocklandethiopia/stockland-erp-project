<?php
/**
 * Dedicated standalone ERP shell template.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$frontend = class_exists( 'Stockland_Frontend' ) ? Stockland_Frontend::instance() : null;
if ( ! $frontend ) {
	status_header( 500 );
	wp_die( esc_html__( 'Stockland ERP frontend is not initialized.', 'stockland-inventory' ) );
}
$frontend->render_document_template();
