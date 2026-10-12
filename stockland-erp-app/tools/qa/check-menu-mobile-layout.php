<?php
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit; }
$root    = dirname( __DIR__, 2 );
$fail    = array();
$pass    = 0;
$ok      = function ( $cond, $label ) use ( &$fail, &$pass ) {
	if ( $cond ) {
		++$pass;
		echo "PASS {$label}\n";
	} else {
		$fail[] = $label;
		echo "FAIL {$label}\n";
	}};
$read    = function ( $p ) use ( $root ) {
	$f = $root . '/' . $p;
	return is_file( $f ) ? file_get_contents( $f ) : '';
};
$css     = $read( 'assets/css/05-responsive-layout.css' );
$overlay = $read( 'assets/css/04-overlays-workflows.css' );
$js      = $read( 'assets/app.js' );
$shell   = $read( 'includes/class-stockland-frontend.php' );
$guards  = $read( 'assets/css/06-operational-guards.css' );
$ok( str_contains( $css, '@media (min-width:576px) and (max-width:991.98px)' ), 'tablet has an explicit 576–991.98px header layout' );
$ok( str_contains( $css, 'grid-template-rows:40px 44px' ) && str_contains( $css, 'overflow-x:auto;overflow-y:hidden;flex-wrap:nowrap' ), 'tablet header separates identity/actions and prevents stacked/overlapping action buttons' );
$ok( str_contains( $css, '--sl-header-h:116px' ) && str_contains( $css, 'height:116px' ) && str_contains( $css, 'grid-template-columns:minmax(0,1fr)' ), 'tablet header height matches the shell row and retains content width' );
$ok( str_contains( $overlay, 'overscroll-behavior:contain' ) && str_contains( $overlay, 'touch-action:pan-y' ) && str_contains( $overlay, '-webkit-overflow-scrolling:touch' ), 'mobile navigation panel can scroll without blocking touch gestures' );
$ok( str_contains( $js, 'aria-expanded="false"' ) && str_contains( $js, 'aria-controls="sl-mobile-navigation"' ), 'menu trigger exposes correct initial accessibility state' );
$ok( str_contains( $js, 'function closeMobileMenu(restoreFocus=true)' ) && str_contains( $js, "document.body.classList.remove('sl-mobile-menu-open')" ), 'single close path always releases the body scroll lock' );
$ok( str_contains( $js, "if(document.querySelector('.sl-mobile-menu'))closeMobileMenu(true)" ) && str_contains( $js, 'e.key===\'Escape\'' ), 'Escape uses the same cleanup path as the close button' );
$ok( str_contains( $js, 'focusable.length-1' ) && str_contains( $js, 'document.activeElement===first' ), 'mobile menu traps keyboard focus while open' );
$ok( str_contains( $js, "trigger.setAttribute('aria-expanded','true')" ) && str_contains( $js, "trigger.setAttribute('aria-expanded','false')" ), 'menu ARIA expanded state follows open/close' );
$ok( str_contains( $js, "matchMedia('(min-width: 992px)')" ) && str_contains( $js, 'closeMobileMenu(false)' ), 'resizing back to desktop safely dismisses mobile overlay' );
$ok( str_contains( $js, 'closeMobileMenu(false);render();document.getElementById(\'menu\')?.focus()' ), 'choosing a menu item navigates, closes the overlay and leaves predictable focus' );
$ok( 1 === preg_match( '/ROUTE_VERSION\\s*=\\s*[\'\"]16\\.1\\.63[\'\"]/', $shell ), 'frontend cache bridge release identity updated' );
$ok( str_contains( $guards, '#stockland-app .sl-quick-actions{display:block!important' ) && str_contains( $guards, '.sl-quick-actions>.sl-quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))' ), 'desktop dashboard shortcut panel keeps heading above readable two-column actions' );
$ok( str_contains( $guards, 'a.sl-exit{background:var(--sl-navy)!important' ) && str_contains( $guards, 'a.sl-exit:hover' ), 'EXIT action has a contrasting, readable background and hover state' );
$ok( str_contains( $guards, '@media (max-width:575.98px)' ) && str_contains( $guards, '.sl-quick-actions>.sl-quick-grid{grid-template-columns:1fr' ), 'mobile quick actions become a clean single column' );
if ( $fail ) {
	fwrite( STDERR, 'Menu/mobile layout QA FAILED: ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}echo "Menu/mobile layout QA PASS: {$pass} assertions.\n";
