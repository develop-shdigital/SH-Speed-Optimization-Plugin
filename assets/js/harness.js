/*!
 * SH Speed Optimizer — browser test harness (admin only).
 *
 * Loads pages of this site in sandboxed, same-origin iframes (scaled down in
 * a visible container) and collects the probe results. Used to verify
 * optimizations in a real browser before they are kept.
 *
 * API: window.SHSOHarness.run( plan, { container, onProgress } ) → Promise<results>
 *   plan item: { key, url, purpose: 'analyze'|'verify'|'critical_css', viewport: { w, h }, timeout_ms, nonce }
 */
( function () {
	'use strict';

	var config = window.shsoHarnessConfig || {};

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	function loadCriticalGenerator( win ) {
		return new Promise( function ( resolve ) {
			try {
				if ( win.SHSOCritical ) {
					resolve( true );
					return;
				}
				var doc = win.document;
				var script = doc.createElement( 'script' );
				script.src = config.criticalCssUrl;
				script.onload = function () {
					resolve( !! win.SHSOCritical );
				};
				script.onerror = function () {
					resolve( false );
				};
				( doc.head || doc.documentElement ).appendChild( script );
			} catch ( e ) {
				resolve( false );
			}
		} );
	}

	function runItem( item, container ) {
		return new Promise( function ( resolve ) {
			var viewport = item.viewport || { w: 1350, h: 900 };
			var timeout = item.timeout_ms || 30000;
			var width = container ? Math.max( 200, container.clientWidth || 360 ) : 360;
			var scale = Math.min( 1, width / viewport.w );

			var frameBox = document.createElement( 'div' );
			frameBox.className = 'shso-harness-frame';
			frameBox.style.cssText = 'position:relative;overflow:hidden;width:' + Math.round( viewport.w * scale ) + 'px;height:' + Math.round( viewport.h * scale ) + 'px;border:1px solid #dcdcde;border-radius:4px;background:#fff;';

			var iframe = document.createElement( 'iframe' );
			iframe.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-forms' );
			iframe.setAttribute( 'aria-hidden', 'true' );
			iframe.setAttribute( 'tabindex', '-1' );
			iframe.setAttribute( 'title', 'SH Speed Optimizer test' );
			iframe.style.cssText = 'position:absolute;top:0;left:0;border:0;width:' + viewport.w + 'px;height:' + viewport.h + 'px;transform:scale(' + scale + ');transform-origin:0 0;pointer-events:none;';

			var settled = false;
			var timer = null;

			function finish( result ) {
				if ( settled ) {
					return;
				}
				settled = true;
				clearTimeout( timer );
				window.removeEventListener( 'message', onMessage );
				var done = function ( final ) {
					try {
						iframe.src = 'about:blank';
						frameBox.parentNode && frameBox.parentNode.removeChild( frameBox );
					} catch ( e ) {}
					resolve( final );
				};

				if ( 'critical_css' === item.purpose && result && ! result.timeout ) {
					var win = iframe.contentWindow;
					loadCriticalGenerator( win ).then( function ( ok ) {
						try {
							result.critical_css = ok ? win.SHSOCritical.generate( win, {} ) : { error: 'generator unavailable' };
						} catch ( e ) {
							result.critical_css = { error: String( e && e.message ) };
						}
						done( result );
					} );
					return;
				}
				done( result );
			}

			function onMessage( event ) {
				if ( event.origin !== window.location.origin || event.source !== iframe.contentWindow ) {
					return;
				}
				var data = event.data || {};
				if ( 'shso-probe' === data.type && data.nonce === item.nonce && data.result ) {
					finish( data.result );
				}
			}

			window.addEventListener( 'message', onMessage );
			timer = setTimeout( function () {
				var partial = null;
				try {
					partial = iframe.contentWindow && iframe.contentWindow.__shsoProbeResult;
				} catch ( e ) {}
				finish( partial ? partial : { timeout: true, nonce: item.nonce } );
			}, timeout );

			frameBox.appendChild( iframe );
			if ( container ) {
				container.appendChild( frameBox );
			} else {
				frameBox.style.position = 'fixed';
				frameBox.style.left = '-10000px';
				document.body.appendChild( frameBox );
			}
			iframe.src = item.url;
		} );
	}

	var aborted = false;

	window.SHSOHarness = {
		abort: function () {
			aborted = true;
		},
		run: function ( plan, options ) {
			options = options || {};
			aborted = false;
			var results = {};
			var list = Array.isArray( plan ) ? plan : [];
			var total = list.length;
			var index = 0;

			function next() {
				if ( aborted ) {
					return Promise.resolve( { _unavailable: true } );
				}
				if ( index >= total ) {
					return Promise.resolve( results );
				}
				var item = list[ index ];
				return runItem( item, options.container ).then( function ( result ) {
					results[ item.key ] = result;
					index++;
					try {
						options.onProgress && options.onProgress( index, total, item );
					} catch ( e ) {}
					return wait( 150 ).then( next );
				} );
			}

			return next();
		}
	};
} )();
