<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stockland_Runtime_Diagnostics {
	const OPTION          = 'stockland_runtime_fatal_v1';
	const MAX_TEXT        = 1200;
	private static $stage = 'plugin_boot';

	public static function boot() {
		register_shutdown_function( array( __CLASS__, 'capture_fatal' ) );
	}
	public static function stage( $name ) {
		$name        = sanitize_key( (string) $name );
		self::$stage = substr( $name !== '' ? $name : 'unknown', 0, 80 );
	}
	public static function capture_fatal() {
		$last = error_get_last();
		if ( ! is_array( $last ) ) {
			return;
		}
		$fatal_types = array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR );
		if ( ! in_array( (int) ( $last['type'] ?? 0 ), $fatal_types, true ) ) {
			return;
		}
		$message = self::clean( (string) ( $last['message'] ?? 'unknown fatal error' ) );
		$file    = (string) ( $last['file'] ?? 'unknown' );
		$line    = (int) ( $last['line'] ?? 0 );
		// Store the path only. Query strings commonly contain WP REST nonces,
		// setup cache-busters, and application-specific tokens; they are never
		// required to identify a failing route.
		// This is diagnostic-only request metadata; never retain query strings or nonces.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$uri         = self::request_path( $request_uri );
		$entry       = array(
			'reference'    => 'FATAL-' . gmdate( 'YmdHis' ) . '-' . substr( hash( 'sha256', $message . '|' . $file . '|' . $line . '|' . $uri ), 0, 8 ),
			'time_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			'type'         => (int) ( $last['type'] ?? 0 ),
			'message'      => $message,
			'file'         => basename( $file ),
			'line'         => $line,
			'stage'        => self::$stage,
			'method'       => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'request_path' => $uri,
			'memory_usage' => function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : null,
			'memory_limit' => function_exists( 'ini_get' ) ? (string) ini_get( 'memory_limit' ) : '',
		);
		try {
			update_option( self::OPTION, $entry, false );} catch ( Throwable $e ) {
			}
			error_log( 'Stockland ERP FATAL [' . $entry['reference'] . '] ' . $message . ' @ ' . $file . ':' . $line . ' stage=' . self::$stage . ' uri=' . $uri );
	}
	public static function last_fatal() {
		$v = get_option( self::OPTION, array() );
		if ( ! is_array( $v ) ) {
			return array();
		}
		// Redact legacy records written by earlier releases too, not just new
		// records. This avoids returning stored query secrets after an upgrade.
		if ( isset( $v['request_path'] ) ) {
			$v['request_path'] = self::request_path( (string) $v['request_path'] );
		}
		foreach ( array( 'reference', 'message', 'file', 'stage', 'method' ) as $key ) {
			if ( isset( $v[ $key ] ) && is_string( $v[ $key ] ) ) {
				$v[ $key ] = self::clean( $v[ $key ], $key === 'message' ? self::MAX_TEXT : 300 );
			}
		}
		return $v;
	}
	/** Return a bounded path without query or fragment data. */
	private static function request_path( $uri ) {
		$path = parse_url( (string) $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			$path = '/';
		}
		$path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		return self::clean( $path, 300 );
	}
	public static function environment() {
		global $wpdb;
		$e = array(
			'php_version'            => PHP_VERSION,
			'wordpress_version'      => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
			'memory_limit'           => (string) ini_get( 'memory_limit' ),
			'max_execution_time'     => (string) ini_get( 'max_execution_time' ),
			'upload_max_filesize'    => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'          => (string) ini_get( 'post_max_size' ),
			'zip_archive'            => class_exists( 'ZipArchive' ),
			'xml_reader'             => class_exists( 'XMLReader' ),
			'xml_reader_from_stream' => class_exists( 'XMLReader' ) && method_exists( 'XMLReader', 'fromStream' ),
			'simplexml'              => class_exists( 'SimpleXMLElement' ),
			'db_version'             => ( $wpdb && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : '',
			'db_select_ok'           => false,
		);
		if ( $wpdb ) {
			try {
				$e['db_select_ok'] = ( (string) $wpdb->get_var( 'SELECT 1' ) === '1' );} catch ( Throwable $x ) {
				}
		}
		return $e;
	}
	private static function clean( $s, $max = self::MAX_TEXT ) {
		$s = str_replace( array( "\0", "\r", "\n" ), ' ', (string) $s );
		if ( defined( 'ABSPATH' ) && ABSPATH !== '' ) {
			$s = str_replace( ABSPATH, '[ABSPATH]/', $s );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}
}
