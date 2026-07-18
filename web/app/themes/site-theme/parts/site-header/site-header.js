/**
 * Mobile nav toggle for the site header. Small vanilla IIFE, no build step
 * and no framework — enqueued directly (see
 * SiteTheme\Bootstrap\ThemeBootstrap::enqueue_assets()).
 */
( function () {
	'use strict';

	var toggle = document.querySelector( '.site-header__toggle' );
	var nav = document.getElementById( 'site-header-nav' );

	if ( ! toggle || ! nav ) {
		return;
	}

	function closeNav() {
		nav.classList.remove( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'false' );
	}

	function openNav() {
		nav.classList.add( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'true' );
	}

	toggle.addEventListener( 'click', function () {
		if ( nav.classList.contains( 'is-open' ) ) {
			closeNav();
		} else {
			openNav();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && nav.classList.contains( 'is-open' ) ) {
			closeNav();
			toggle.focus();
		}
	} );
} )();
