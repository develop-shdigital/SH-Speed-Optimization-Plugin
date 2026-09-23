/*!
 * SH Speed Optimizer - click-to-load videos and maps.
 *
 * Replaces a facade (.shso-facade) with the original iframe when the visitor
 * clicks it or activates its button with the keyboard (Enter/Space trigger a
 * native button click). The original iframe attributes are stored as JSON in
 * data-shso-iframe. Plain ES2017, no dependencies, never throws.
 */
( function () {
	'use strict';

	// Only these hosts may be loaded (the URLs were written by the server, this is defense in depth).
	var ALLOWED_HOST = /(^|\.)(youtube\.com|youtube-nocookie\.com|vimeo\.com|google\.[a-z]{2,3}(\.[a-z]{2})?)$/i;
	var SAFE_NAME = /^[a-z][a-z0-9_.:\-]*$/i;

	function safeSrc( src ) {
		try {
			var url = new URL( src, window.location.href );
			if ( 'https:' !== url.protocol || ! ALLOWED_HOST.test( url.hostname ) ) {
				return '';
			}
			return url.href;
		} catch ( e ) {
			return '';
		}
	}

	function readAttributes( facade ) {
		try {
			var parsed = JSON.parse( facade.getAttribute( 'data-shso-iframe' ) || '{}' );
			return parsed && 'object' === typeof parsed ? parsed : {};
		} catch ( e ) {
			return {};
		}
	}

	function activate( facade ) {
		try {
			if ( ! facade || '1' === facade.getAttribute( 'data-shso-active' ) || ! facade.parentNode ) {
				return;
			}
			var src = safeSrc( facade.getAttribute( 'data-shso-src' ) || '' );
			if ( ! src ) {
				return;
			}
			facade.setAttribute( 'data-shso-active', '1' );

			var attributes = readAttributes( facade );
			var iframe = document.createElement( 'iframe' );

			Object.keys( attributes ).forEach( function ( name ) {
				var lower = String( name ).toLowerCase();
				if ( ! SAFE_NAME.test( name ) || 'src' === lower || 'srcdoc' === lower || 0 === lower.indexOf( 'on' ) ) {
					return;
				}
				try {
					iframe.setAttribute( name, null === attributes[ name ] ? '' : String( attributes[ name ] ) );
				} catch ( e ) {
					// Invalid attribute name: skip it.
				}
			} );

			if ( 'google-maps' !== facade.getAttribute( 'data-shso-provider' ) ) {
				var allow = iframe.getAttribute( 'allow' ) || '';
				if ( ! /autoplay/i.test( allow ) ) {
					iframe.setAttribute( 'allow', ( allow ? allow.replace( /;?\s*$/, '; ' ) : '' ) + 'autoplay; fullscreen; picture-in-picture' );
				}
			}

			if ( ! iframe.getAttribute( 'title' ) && facade.getAttribute( 'data-shso-title' ) ) {
				iframe.setAttribute( 'title', facade.getAttribute( 'data-shso-title' ) );
			}

			iframe.removeAttribute( 'loading' );
			iframe.setAttribute( 'src', src );
			facade.parentNode.replaceChild( iframe, facade );

			try {
				iframe.focus( { preventScroll: true } );
			} catch ( e ) {
				iframe.focus();
			}
		} catch ( e ) {
			// Never break the page.
		}
	}

	function onClick( event ) {
		try {
			var target = event.target;
			if ( ! target || ! target.closest ) {
				return;
			}
			var facade = target.closest( '.shso-facade' );
			if ( ! facade || target.closest( 'a' ) ) {
				return; // Links ("Open in Google Maps") keep their normal behavior.
			}
			if ( facade.classList.contains( 'shso-facade--map' ) && ! target.closest( '.shso-facade__load' ) ) {
				return; // Maps load only through their button.
			}
			event.preventDefault();
			activate( facade );
		} catch ( e ) {
			// Never break the page.
		}
	}

	try {
		document.addEventListener( 'click', onClick );
	} catch ( e ) {
		// Very old browser: facades keep their no-JavaScript fallback links.
	}
}() );
