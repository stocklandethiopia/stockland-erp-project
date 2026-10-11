<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stockland ERP frontend bridge.
 *
 * Production rule for 16.1.44:
 * - The published WordPress Page + shortcode doorway is the authoritative 16.1.39 user-facing entry on shared WordPress hosting.
 * - The dedicated /stockland-erp/ path remains an additive direct alias/fallback.
 * - Both entry paths render the same standalone ERP document and load the same current release application
 *   application JavaScript, REST, permissions, workflow, accounting and HR/Payroll code.
 * - WordPress wp-admin remains native and is never replaced, hidden or redirected.
 */
class Stockland_Frontend {
	const SHORTCODE          = 'stockland_app';
	const ERP_PATH           = 'stockland-erp';
	const LEGACY_PATH        = 'stockland';
	const LEGACY_PAGE_OPTION = 'stockland_app_page_id';
	const ROUTE_VERSION      = '16.1.57';

	private static $instance  = null;
	private static $csp_nonce = null;

	public function __construct() {
		self::$instance = $this;
		// Proven frontend doorway.
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 1000 );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'init', array( $this, 'maybe_create_page' ) );
		add_action( 'init', array( $this, 'process_login' ) );

		// Dedicated direct alias/fallback. This is intentionally additive: it does not
		// replace the proven WordPress Page entry.
		add_action( 'init', array( $this, 'register_route' ), 0 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'template_include', array( $this, 'template_include' ), 0 );
		add_action( 'template_redirect', array( $this, 'serve_request' ), 0 );
	}

	/** Current frontend bridge instance used by template_include. */
	private static function csp_nonce() {
		if ( null === self::$csp_nonce ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 encodes the cryptographically random value required for a CSP nonce; it is not code obfuscation.
			self::$csp_nonce = base64_encode( function_exists( 'random_bytes' ) ? random_bytes( 18 ) : wp_generate_password( 24, false, false ) );
		}
		return self::$csp_nonce;
	}

	public static function instance() {
		return self::$instance;
	}

	/**
	 * Activation entry point. It establishes both the proven Page doorway and the
	 * direct alias, then flushes rewrite rules once. Creation is deliberately limited
	 * to the ERP's own entry page; an unrelated /stockland/ page is preserved.
	 */
	public static function activate_frontend() {
		$instance = self::$instance;
		if ( $instance ) {
			$instance->ensure_entry_page( true );
			$instance->register_rewrite_rule();
		} else {
			// Activation runs before normal plugin bootstrap. Ensure page/rewrite helpers
			// remain usable even if the frontend object is not instantiated yet.
			$instance = new self();
			$instance->ensure_entry_page( true );
			$instance->register_rewrite_rule();
		}
		update_option( 'stockland_frontend_route_version', self::ROUTE_VERSION, false );
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Idempotent release upgrade for existing installations. This runs once when the
	 * frontend bridge version changes, so a plugin ZIP update (which does not re-run
	 * the activation hook) still receives the deterministic ERP entry Page and rewrite
	 * registration. It never repurposes an unrelated WordPress page.
	 */
	public function sync_release_entry() {
		if ( get_option( 'stockland_frontend_route_version', '' ) === self::ROUTE_VERSION ) {
			return;
		}
		$this->ensure_entry_page( true );
		self::register_rewrite_rule();
		update_option( 'stockland_frontend_route_version', self::ROUTE_VERSION, false );
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Return the proven ERP entry URL. Existing legacy plugin-created ERP Page wins.
	 * A direct /stockland-erp/ alias is still available even if the Page was not created.
	 */
	public static function page_url() {
		$id = (int) get_option( self::LEGACY_PAGE_OPTION );
		if ( $id ) {
			$page = get_post( $id );
			if ( $page && 'page' === $page->post_type && 'publish' === $page->post_status && has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
				$url = get_permalink( $id );
				if ( $url ) {
					return $url;
				}
			}
		}

		foreach ( array( self::LEGACY_PATH, self::ERP_PATH . '-app' ) as $slug ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $existing && 'publish' === $existing->post_status && has_shortcode( (string) $existing->post_content, self::SHORTCODE ) ) {
				if ( is_admin() && current_user_can( 'manage_options' ) ) {
					update_option( self::LEGACY_PAGE_OPTION, (int) $existing->ID, false );
				}
				$url = get_permalink( $existing->ID );
				if ( $url ) {
					return $url;
				}
			}
		}

		return home_url( '/' . self::ERP_PATH . '-app/' );
	}

	/**
	 * Ensure an ERP entry page exists without hijacking an unrelated WordPress page.
	 * On older installations /stockland/ may already belong to another theme or page
	 * builder. In that case the plugin creates a separate deterministic ERP page.
	 */
	private function ensure_entry_page( $allow_create = false ) {
		$id = (int) get_option( self::LEGACY_PAGE_OPTION );
		if ( $id ) {
			$page = get_post( $id );
			if ( $page && 'page' === $page->post_type && 'publish' === $page->post_status && has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
				return $id;
			}
		}

		$existing = get_page_by_path( self::LEGACY_PATH, OBJECT, 'page' );
		if ( $existing && 'publish' === $existing->post_status && has_shortcode( (string) $existing->post_content, self::SHORTCODE ) ) {
			update_option( self::LEGACY_PAGE_OPTION, (int) $existing->ID, false );
			return (int) $existing->ID;
		}

		$alternate = get_page_by_path( self::ERP_PATH . '-app', OBJECT, 'page' );
		if ( $alternate && 'publish' === $alternate->post_status && has_shortcode( (string) $alternate->post_content, self::SHORTCODE ) ) {
			update_option( self::LEGACY_PAGE_OPTION, (int) $alternate->ID, false );
			return (int) $alternate->ID;
		}

		if ( ! $allow_create ) {
			return 0;
		}

		// Never overwrite or repurpose an existing ordinary page. WordPress will
		// generate a unique slug if the alternate name is already occupied.
		$id = wp_insert_post(
			array(
				'post_title'     => 'Stockland Ethiopia ERP',
				'post_name'      => self::ERP_PATH . '-app',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'post_content'   => '[' . self::SHORTCODE . ']',
				'comment_status' => 'closed',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_option( self::LEGACY_PAGE_OPTION, (int) $id, false );
		return (int) $id;
	}

	/** Called on normal administrator requests to repair only the ERP entry page pointer. */
	public function maybe_create_page() {
		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			$this->ensure_entry_page( true );
		}
	}

	/** Return a stable per-file build version while preserving the ERP release number.
	 * This prevents browsers/service workers from reusing an older release app.js/app.css after an in-place repair.
	 */
	private static function asset_version( $relative ) {
		$file        = STOCKLAND_PLUGIN_DIR . ltrim( $relative, '/' );
		$fingerprint = '0';
		if ( is_file( $file ) && is_readable( $file ) ) {
			$mtime = filemtime( $file );
			if ( $mtime ) {
				$fingerprint = $mtime;
			} else {
				$digest      = hash_file( 'sha256', $file );
				$fingerprint = false !== $digest ? substr( $digest, 0, 12 ) : '0';
			}
		}
		return STOCKLAND_VERSION . '-' . preg_replace( '/[^0-9A-Za-z_-]/', '', $fingerprint );
	}
	private static function asset_url( $relative ) {
		return add_query_arg( 'ver', self::asset_version( $relative ), STOCKLAND_PLUGIN_URL . ltrim( $relative, '/' ) );}

	/** Keep the old Page doorway clean if the direct standalone fallback is not engaged. */
	public function assets() {
		if ( ! is_page() ) {
			return;
		}
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}

		show_admin_bar( false );
		foreach ( array( 'dashicons', 'admin-bar', 'wp-block-library', 'global-styles', 'twentytwentyfive-style' ) as $h ) {
			wp_dequeue_style( $h );
			wp_deregister_style( $h );
		}
		wp_dequeue_script( 'admin-bar' );
		wp_enqueue_style(
			'stockland-bootstrap',
			self::asset_url( 'assets/bootstrap.min.css' ),
			array(),
			self::asset_version( 'assets/bootstrap.min.css' )
		);
		wp_enqueue_style(
			'stockland-app',
			self::asset_url( file_exists( STOCKLAND_PLUGIN_DIR . 'assets/app.min.css' ) ? 'assets/app.min.css' : 'assets/app.css' ),
			array( 'stockland-bootstrap' ),
			self::asset_version( file_exists( STOCKLAND_PLUGIN_DIR . 'assets/app.min.css' ) ? 'assets/app.min.css' : 'assets/app.css' )
		);
		wp_enqueue_script( 'stockland-app', self::asset_url( 'assets/app.js' ), array(), self::asset_version( 'assets/app.js' ), true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'stockland-app', 'strategy', 'defer' );
		}
		wp_enqueue_script( 'stockland-pwa', self::asset_url( 'assets/pwa.js' ), array( 'stockland-app' ), self::asset_version( 'assets/pwa.js' ), true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'stockland-pwa', 'strategy', 'defer' );
		}
		wp_localize_script( 'stockland-app', 'StocklandApp', $this->client_config( self::page_url() ) );
	}

	private function client_config( $page ) {
		return array(
			'api'            => esc_url_raw( rest_url( STOCKLAND_REST_NAMESPACE ) ),
			'api_v16'        => esc_url_raw( rest_url( 'stockland/v16' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'home'           => esc_url_raw( $page ),
			'logout'         => esc_url_raw( wp_logout_url( $page ) ),
			'wp_admin'       => current_user_can( 'manage_options' ) ? esc_url_raw( admin_url() ) : '',
			'sw'             => esc_url_raw(
				add_query_arg(
					array(
						'stockland_pwa_sw' => '1',
						'ver'              => self::asset_version( 'assets/pwa.js' ),
					),
					$page
				)
			),
			'manifest'       => esc_url_raw( add_query_arg( 'stockland_pwa_manifest', '1', $page ) ),
			'asset_versions' => array(
				'css'       => self::asset_version( 'assets/app.min.css' ),
				'js'        => self::asset_version( 'assets/app.js' ),
				'pwa'       => self::asset_version( 'assets/pwa.js' ),
				'bootstrap' => self::asset_version( 'assets/bootstrap.min.css' ),
			),
			'build'          => STOCKLAND_VERSION,
			'frontend_route' => self::ROUTE_VERSION,
			'count_template' => esc_url_raw( STOCKLAND_PLUGIN_URL . 'templates/Stockland_Physical_Count_Template.xlsx' ),
			'logo'           => esc_url_raw( STOCKLAND_PLUGIN_URL . 'assets/stockland-logo.jpg' ),
			'user'           => array(
				'id'        => get_current_user_id(),
				'name'      => wp_get_current_user()->display_name,
				'logged_in' => is_user_logged_in(),
			),
		);
	}

	public function query_vars( $vars ) {
		$vars[] = 'stockland_erp';
		return $vars;
	}

	/** Activation-safe route setup for the direct alias. */
	public static function activate_route() {
		self::register_rewrite_rule();
		update_option( 'stockland_frontend_route_version', self::ROUTE_VERSION, false );
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	public function register_route() {
		self::register_rewrite_rule();
		if ( get_option( 'stockland_frontend_route_version', '' ) !== self::ROUTE_VERSION ) {
			update_option( 'stockland_frontend_route_version', self::ROUTE_VERSION, false );
			if ( function_exists( 'flush_rewrite_rules' ) ) {
				flush_rewrite_rules( false );
			}
		}
	}

	private static function register_rewrite_rule() {
		add_rewrite_rule(
			'^' . self::ERP_PATH . '/?$',
			'index.php?stockland_erp=1',
			'top'
		);
	}

	/** Report healthy if either the direct route exists or the proven ERP Page exists. */
	public static function route_ready() {
		// Production health is based on the authoritative published WordPress Page + shortcode doorway.
		// A stale/missing option pointer must not report the entry as unavailable when the
		// published ERP page can be deterministically rediscovered by its supported slugs.
		if ( self::legacy_plugin_page_exists() ) {
			return true;
		}
		foreach ( array( self::LEGACY_PATH, self::ERP_PATH . '-app' ) as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $page && 'publish' === $page->post_status && has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
				return true;
			}
		}
		return false;
	}

	private static function legacy_plugin_page_exists() {
		$id = (int) get_option( self::LEGACY_PAGE_OPTION );
		if ( ! $id ) {
			return false;
		}
		$page = get_post( $id );
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			return false;
		}
		return strpos( (string) $page->post_content, '[' . self::SHORTCODE ) !== false;
	}

	private function is_shortcode_page() {
		if ( ! function_exists( 'is_page' ) || ! is_page() ) {
			return false;
		}
		global $post;
		return $post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/** Shared exact-path fallback for hosts where saved rewrite rules are unreliable. */
	private function is_direct_request_path() {
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base         = '/' . trim( (string) $home_path, '/' );
		$expected     = '/' . trim( $base . '/' . self::ERP_PATH, '/' );
		return untrailingslashit( (string) $request_path ) === untrailingslashit( $expected );
	}

	/** Serve only dynamic PWA assets here. The ERP document itself uses template_include. */
	public function serve_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This GET flag selects a public, read-only PWA manifest and performs no state change.
		if ( isset( $_GET['stockland_pwa_manifest'] ) ) {
			$this->serve_manifest();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This GET flag selects a public, read-only service-worker asset and performs no state change.
		if ( isset( $_GET['stockland_pwa_sw'] ) ) {
			$this->serve_service_worker();
		}
	}

	/**
	 * Use WordPress's template_include extension point rather than terminating execution
	 * from template_redirect. This is the clean WordPress mechanism for replacing a queried
	 * page template while keeping native WordPress administration untouched.
	 */
	public function template_include( $template ) {
		if ( is_admin() ) {
			return $template;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This sanitized GET flag selects the public ERP shell route and cannot mutate data.
		$direct_flag = isset( $_GET['stockland_erp'] ) ? sanitize_text_field( wp_unslash( $_GET['stockland_erp'] ) ) : '';
		$direct      = '1' === $direct_flag;
		$direct      = $direct || $this->is_direct_request_path();
		if ( $direct || $this->is_shortcode_page() ) {
			$shell = STOCKLAND_PLUGIN_DIR . 'templates/erp-shell.php';
			if ( file_exists( $shell ) ) {
				return $shell;
			}
		}
		return $template;
	}

	private function serve_manifest() {
		$page     = self::page_url();
		$manifest = array(
			'id'                          => untrailingslashit( $page ),
			'name'                        => 'Stockland Ethiopia ERP',
			'short_name'                  => 'Stockland ERP',
			'description'                 => 'Stockland Ethiopia operational ERP for sales, inventory, warehouse, finance and reporting.',
			'start_url'                   => './',
			'scope'                       => './',
			'display'                     => 'standalone',
			'display_override'            => array( 'window-controls-overlay', 'standalone' ),
			'background_color'            => '#0b1b33',
			'theme_color'                 => '#2563eb',
			'orientation'                 => 'any',
			'lang'                        => 'en',
			'categories'                  => array( 'business', 'productivity', 'finance' ),
			'prefer_related_applications' => false,
			'icons'                       => array(
				array(
					'src'     => STOCKLAND_PLUGIN_URL . 'assets/icon-192.png',
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => STOCKLAND_PLUGIN_URL . 'assets/icon-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => STOCKLAND_PLUGIN_URL . 'assets/icon-512-maskable.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private function serve_service_worker() {
		$page      = self::page_url();
		$path      = wp_parse_url( $page, PHP_URL_PATH );
		$path      = '/' . trim( (string) $path, '/' ) . '/';
		$assets    = array(
			self::asset_url( 'assets/bootstrap.min.css' ),
			self::asset_url( 'assets/app.min.css' ),
			self::asset_url( 'assets/app.js' ),
			self::asset_url( 'assets/pwa.js' ),
			STOCKLAND_PLUGIN_URL . 'assets/icon-180.png',
			STOCKLAND_PLUGIN_URL . 'assets/icon-192.png',
			STOCKLAND_PLUGIN_URL . 'assets/icon-512.png',
			STOCKLAND_PLUGIN_URL . 'assets/icon-512-maskable.png',
			STOCKLAND_PLUGIN_URL . 'assets/stockland-logo.jpg',
		);
		$cache_ver = self::asset_version( 'assets/bootstrap.min.css' ) . '-' . self::asset_version( 'assets/app.js' ) . '-' . self::asset_version( 'assets/app.min.css' ) . '-' . self::asset_version( 'assets/pwa.js' );
		$js        = "const CACHE='stockland-erp-" . sanitize_title( STOCKLAND_VERSION ) . '-' . sanitize_title( $cache_ver ) . "';\n" .
				'const ASSETS=' . wp_json_encode( array_values( $assets ), JSON_UNESCAPED_SLASHES ) . ";\n" .
				"self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)).then(()=>self.skipWaiting())));\n" .
				"self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('stockland-erp-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));\n" .
				"self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;const u=new URL(e.request.url);if(u.origin!==location.origin)return;const asset=e.request.destination==='script'||e.request.destination==='style'||e.request.destination==='image'||e.request.destination==='font';if(!asset)return;e.respondWith(caches.match(e.request).then(cached=>cached||fetch(e.request).then(r=>{if(r&&r.ok){const copy=r.clone();caches.open(CACHE).then(c=>c.put(e.request,copy)).catch(()=>{});}return r;})).catch(()=>caches.match(e.request)));});";
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . $path );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This endpoint returns JavaScript, not HTML; dynamic asset URLs are JSON-encoded above and cache/version components are sanitized.
		echo $js;
		exit;
	}

	public function document_title( $title ) {
		return $this->is_shortcode_page() ? 'Stockland Ethiopia ERP' : $title;
	}

	public function robots( $robots ) {
		if ( $this->is_shortcode_page() ) {
			$robots['noindex']   = true;
			$robots['nofollow']  = true;
			$robots['noarchive'] = true;
		}
		return $robots;
	}

	public function body_class( $classes ) {
		if ( $this->is_shortcode_page() ) {
			$classes[] = 'stockland-erp-page';
		}
		return $classes;
	}

	public function head() {
		if ( $this->is_shortcode_page() ) {
			echo '<link rel="manifest" href="' . esc_url( add_query_arg( 'stockland_pwa_manifest', '1', self::page_url() ) ) . '">';
			echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( STOCKLAND_PLUGIN_URL . 'assets/icon-180.png' ) . '">';
			echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url( STOCKLAND_PLUGIN_URL . 'assets/icon-192.png' ) . '">';
			echo '<meta name="theme-color" content="#2563eb">';
			echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
			echo '<meta name="apple-mobile-web-app-capable" content="yes">';
			echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
			echo '<meta name="robots" content="noindex,nofollow,noarchive">';
			echo '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; img-src \'self\' data: blob:; style-src \'self\' \'unsafe-inline\'; script-src \'self\' \'nonce-' . esc_attr( self::csp_nonce() ) . '\'; font-src \'self\' data:; connect-src \'self\'; frame-ancestors \'self\'; base-uri \'self\'; form-action \'self\';">';
		}
	}

	public function process_login() {
		if ( is_user_logged_in() || ! isset( $_POST['stockland_login'] ) ) {
			return;
		}
		$nonce = isset( $_POST['stockland_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['stockland_login_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'stockland_login' ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the ERP login page and try again.' ), esc_html__( 'Stockland ERP' ), array( 'response' => 403 ) );
		}
		$login = sanitize_user( wp_unslash( $_POST['log'] ?? '' ) );
		// Passwords must not be text-sanitized because that can mutate valid credentials; unslash only before authentication.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords are deliberately not sanitized; unslash preserves the user's exact credential for wp_signon().
		$pass     = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
		$remember = ! empty( $_POST['rememberme'] );
		if ( ! $login || ! $pass ) {
			wp_safe_redirect( add_query_arg( 'sl_login', 'missing', self::page_url() ) );
			exit;
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $pass,
				'remember'      => $remember,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( 'sl_login', 'failed', self::page_url() ) );
			exit;
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public function shortcode() {
		// This remains compatible for ordinary WordPress rendering, although template_redirect
		// normally upgrades the page into the same standalone document before the theme runs.
		if ( ! is_user_logged_in() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The sanitized GET value only selects a login status message; authentication is performed separately by the POST handler.
			$status = sanitize_key( wp_unslash( $_GET['sl_login'] ?? '' ) );
			ob_start(); ?>
			<div class="sl-login">
				<div class="sl-login-shell">
					<div class="sl-login-brand">
						<div class="sl-login-logo-wrap"><img src="<?php echo esc_url( STOCKLAND_PLUGIN_URL . 'assets/stockland-logo.jpg' ); ?>" class="sl-login-logo" alt="Stockland Ethiopia"></div>
						<div class="sl-login-kicker">STOCKLAND ETHIOPIA</div>
						<h1>Global Brand.<br><span>Local Access.</span></h1>
						<p>One operating space for sales, inventory, warehouse movement and business control.</p>
						<div class="sl-login-trust"><span></span> Secure business application</div>
					</div>
					<div class="sl-login-card">
						<div class="sl-login-card-head"><div class="sl-eyebrow">WELCOME BACK</div><h2>Sign in to Stockland</h2><p>Use your authorized ERP username and password to continue.</p></div>
						<?php
						if ( 'failed' === $status ) :
							?>
							<div class="sl-login-error">Incorrect username or password.</div>
							<?php
elseif ( 'missing' === $status ) :
	?>
							<div class="sl-login-error">Enter your username and password.</div><?php endif; ?>
						<form method="post" class="sl-login-form" autocomplete="on">
							<?php wp_nonce_field( 'stockland_login', 'stockland_login_nonce' ); ?>
							<label>Username</label>
							<input type="text" name="log" autocomplete="username" required>
							<label>Password</label>
							<input type="password" name="pwd" autocomplete="current-password" required>
							<label class="sl-login-remember"><input type="checkbox" name="rememberme" value="1"> Remember me</label>
							<button type="submit" name="stockland_login" value="1" class="sl-btn primary sl-login-submit">Sign in <span>→</span></button>
						</form>
						<small>Stockland ERP • Authorized users only</small>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
		return '<div id="stockland-app" class="sl-root"><div class="sl-loading"><div class="sl-spinner"></div><strong>Loading Stockland Ethiopia…</strong></div></div>';
	}

	/** Render the full standalone ERP shell for both proven and direct entry paths. */
	/** Template entry point used by templates/erp-shell.php. */
	public function render_document_template() {
		// One canonical production presentation. There is no legacy/preview CSS branch
		// in the release; business logic, REST routes, data hooks and object keys remain intact.
		$this->render_document();
	}

	private function render_document() {
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: same-origin' );
		header( "Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-" . self::csp_nonce() . "'; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';" );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );

		$css    = file_exists( STOCKLAND_PLUGIN_DIR . 'assets/app.min.css' ) ? 'assets/app.min.css' : 'assets/app.css';
		$page   = self::page_url();
		$config = $this->client_config( $page );
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<meta name="robots" content="noindex,nofollow,noarchive">
<link rel="manifest" href="<?php echo esc_url( add_query_arg( 'stockland_pwa_manifest', '1', $page ) ); ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( STOCKLAND_PLUGIN_URL . 'assets/icon-180.png' ); ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( STOCKLAND_PLUGIN_URL . 'assets/icon-192.png' ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( self::asset_url( 'assets/bootstrap.min.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( self::asset_url( $css ) ); ?>">
<title>Stockland Ethiopia ERP</title>
</head>
<body class="stockland-erp-standalone sl-modern-saas">
		<?php if ( ! is_user_logged_in() ) : ?>
<div class="sl-login">
	<div class="sl-login-shell">
		<div class="sl-login-brand">
			<div class="sl-login-logo-wrap"><img src="<?php echo esc_url( STOCKLAND_PLUGIN_URL . 'assets/stockland-logo.jpg' ); ?>" class="sl-login-logo" alt="Stockland Ethiopia"></div>
			<div class="sl-login-kicker">STOCKLAND ETHIOPIA</div>
			<h1>Global Brand.<br><span>Local Access.</span></h1>
			<p>One operating space for sales, inventory, warehouse movement and business control.</p>
			<div class="sl-login-trust"><span></span> Secure business application</div>
		</div>
		<div class="sl-login-card">
			<div class="sl-login-card-head"><div class="sl-eyebrow">WELCOME BACK</div><h2>Sign in to Stockland</h2><p>Use your authorized ERP username and password to continue.</p></div>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The sanitized GET value only selects a login status message; authentication is performed separately by the POST handler.
			$status = sanitize_key( wp_unslash( $_GET['sl_login'] ?? '' ) ); ?>
			<?php
			if ( 'failed' === $status ) :
				?>
				<div class="sl-login-error">Incorrect username or password.</div>
				<?php
elseif ( 'missing' === $status ) :
	?>
				<div class="sl-login-error">Enter your username and password.</div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( $page ); ?>" class="sl-login-form" autocomplete="on">
				<?php wp_nonce_field( 'stockland_login', 'stockland_login_nonce' ); ?>
				<label>Username</label>
				<input type="text" name="log" autocomplete="username" required>
				<label>Password</label>
				<input type="password" name="pwd" autocomplete="current-password" required>
				<label class="sl-login-remember"><input type="checkbox" name="rememberme" value="1"> Remember me</label>
				<button type="submit" name="stockland_login" value="1" class="sl-btn primary sl-login-submit">Sign in <span>→</span></button>
			</form>
			<small>Stockland ERP • Authorized users only</small>
		</div>
	</div>
</div>
		<?php else : ?>
<div id="stockland-app" class="sl-root">
	<div class="sl-loading"><div class="sl-spinner"></div><strong>Loading Stockland Ethiopia…</strong></div>
</div>
		<?php endif; ?>
<script nonce="<?php echo esc_attr( self::csp_nonce() ); ?>">window.StocklandApp=<?php echo wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
<script src="<?php echo esc_url( self::asset_url( 'assets/app.js' ) ); ?>" defer></script>
<script src="<?php echo esc_url( self::asset_url( 'assets/pwa.js' ) ); ?>" defer></script>
</body>
</html>
		<?php
	}
}
