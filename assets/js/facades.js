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

	// Only these embeds may be loaded. Facade markup could also come from post content
	// (class and data-* attributes pass the HTML filter), so everything is checked again here.
	var VIDEO_HOST = /^(www\.|m\.)?(youtube\.com|youtube-nocookie\.com)$|^player\.vimeo\.com$/i;
	var MAP_HOST = /^((www|maps)\.)?google\.[a-z]{2,3}(\.[a-z]{2})?$/i;
	// Iframe attributes that may be restored; everything else is dropped.
	var ALLOWED_ATTRIBUTES = [ 'title', 'width', 'height', 'allow', 'allowfullscreen', 'referrerpolicy', 'frameborder', 'sandbox', 'style', 'class', 'id', 'name', 'aria-label', 'aria-hidden' ];
	var DATA_ATTRIBUTE = /^data-[a-z0-9_.\-]+$/;
	var ALLOWED_FEATURES = [ 'accelerometer', 'autoplay', 'clipboard-write', 'encrypted-media', 'fullscreen', 'gyroscope', 'picture-in-picture', 'web-share' ];

	function safeSrc( src ) {
		try {
			var url = new URL( src, window.location.href );
			if ( 'https:' !== url.protocol ) {
				return '';
			}
			if ( VIDEO_HOST.test( url.hostname ) || ( MAP_HOST.test( url.hostname ) && 0 === url.pathname.indexOf( '/maps' ) ) ) {
				return url.href;
			}
			return '';
		} catch ( e ) {
			return '';
		}
	}

	// Keep only harmless permissions in an allow attribute ("autoplay; fullscreen").
	function safeAllow( value ) {
		return String( value ).split( ';' ).map( function ( part ) {
			return part.trim();
		} ).filter( function ( part ) {
			return -1 !== ALLOWED_FEATURES.indexOf( part.split( /\s+/ )[ 0 ].toLowerCase() );
		} ).join( '; ' );
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
				if ( -1 === ALLOWED_ATTRIBUTES.indexOf( lower ) && ! DATA_ATTRIBUTE.test( lower ) ) {
					return;
				}
				var value = null === attributes[ name ] ? '' : String( attributes[ name ] );
				iframe.setAttribute( lower, 'allow' === lower ? safeAllow( value ) : value );
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
