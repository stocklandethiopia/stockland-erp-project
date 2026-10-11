<?php

define( 'ABSPATH', __DIR__ . '/../' );

define( 'STOCKLAND_PLUGIN_DIR', __DIR__ . '/../' );

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        $key = strtolower( (string) $key );
        return preg_replace( '/[^a-z0-9_\-]/', '', $key );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 7;
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) {
        return '2026-01-02';
    }
}

final class Stockland_Test_DB {
    public static function table( $name ) {
        return 'wp_stock_' . $name;
    }
}

if ( ! class_exists( 'Stockland_DB' ) ) {
    class_alias( 'Stockland_Test_DB', 'Stockland_DB' );
}

require_once __DIR__ . '/../includes/class-stockland-money.php';
require_once __DIR__ . '/../includes/class-stockland-sequence.php';
