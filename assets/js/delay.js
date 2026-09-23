/*!
 * SH Speed Optimizer - delayed script loader (readable source).
 *
 * The PHP side inlines assets/js/delay.min.js (a minified copy of this file)
 * near the start of <head>. Scripts rewritten to
 *   <script type="shso/delay" data-shso-src="…" data-shso-delay="tp|all">
 * are executed strictly in document order on the first real user interaction,
 * or after an idle timeout for third-party scripts ("tp"), so tracking still
 * happens for visitors who never interact.
 *
 * Configuration (attributes of this script element):
 *   data-timeout      ms after window load before "tp" scripts run (0 = never)
 *   data-timeout-all  ms after window load before all scripts run (0 = interaction only)
 *   data-replay       1 = call DOMContentLoaded/load listeners added by delayed scripts
 *
 * Public API: window.SHSODelay = { trigger(), state: 'waiting'|'running'|'done', count }
 * and the document event "shso:delay-done" once every delayed script ran (or failed).
 * Plain ES5/ES2017-compatible code, no dependencies, never throws.
 */
( function ( w, d ) {
	'use strict';
	if ( w.SHSODelay ) {
		return;
	}
	try {
		var me = d.currentScript;
		var attr = function ( name, fallback ) {
			var value = me ? me.getAttribute( name ) : null;
			return null === value || '' === value ? fallback : parseInt( value, 10 ) || 0;
		};
		var tpTimeout = attr( 'data-timeout', 8000 );
		var allTimeout = attr( 'data-timeout-all', 0 );
		var replay = 1 === attr( 'data-replay', 0 );
		var SELECTOR = 'script[type="shso/delay"]';
		var EVENTS = [ 'click', 'keydown', 'touchstart', 'pointerdown', 'wheel', 'scroll', 'mousemove' ];
		var OPTIONS = { capture: true, passive: true };
		var queue = [];
		var started = 0;
		var running = false;
		var finished = false;
		var pending = null;
		var current = null;
		var loadedAt = 0;
		var startY = w.pageYOffset || 0;
		var mouseX = null;
		var mouseY = null;
		var patched = false;

		var api = {
			state: 'waiting',
			trigger: function () {
				request( true );
			}
		};
		Object.defineProperty( api, 'count', {
			get: function () {
				return started + d.querySelectorAll( SELECTOR ).length;
			}
		} );
		w.SHSODelay = api;

		var unbind = function () {
			for ( var i = 0; i < EVENTS.length; i++ ) {
				w.removeEventListener( EVENTS[ i ], onEvent, OPTIONS );
			}
		};

		// Only real interactions: synthetic mousemove on load and scroll restoration are ignored.
		var onEvent = function ( e ) {
			if ( 'mousemove' === e.type && ! ( e.movementX || e.movementY ) ) {
				if ( null === mouseX ) {
					mouseX = e.clientX;
					mouseY = e.clientY;
					return;
				}
				if ( Math.abs( e.clientX - mouseX ) + Math.abs( e.clientY - mouseY ) < 5 ) {
					return;
				}
			}
			if ( 'scroll' === e.type ) {
				var y = w.pageYOffset || 0;
				if ( ! loadedAt || Date.now() - loadedAt < 500 ) {
					startY = y;
					return;
				}
				if ( Math.abs( y - startY ) < 10 ) {
					return;
				}
			}
			request( true );
		};

		var request = function ( all ) {
			if ( all ) {
				unbind();
			}
			if ( 'loading' === d.readyState ) {
				pending = pending || all;
				return;
			}
			run( all );
		};

		var patch = function () {
			if ( patched ) {
				return;
			}
			patched = true;
			// document.write after parsing would wipe the page: insert the markup instead.
			d.write = d.writeln = function () {
				try {
					var html = Array.prototype.join.call( arguments, '' );
					if ( current && current.parentNode ) {
						current.insertAdjacentHTML( 'afterend', html );
					}
				} catch ( e ) {}
			};
			if ( ! replay ) {
				return;
			}
			var wrap = function ( target ) {
				var native = target.addEventListener;
				target.addEventListener = function ( type, fn, opts ) {
					var late = fn && ( ( 'DOMContentLoaded' === type && 'loading' !== d.readyState ) || ( 'load' === type && target === w && 'complete' === d.readyState ) );
					if ( ! late ) {
						return native.apply( this, arguments );
					}
					setTimeout( function () {
						var ev;
						try {
							ev = new Event( type );
						} catch ( e ) {
							ev = d.createEvent( 'Event' );
							ev.initEvent( type, false, false );
						}
						if ( 'function' === typeof fn ) {
							fn.call( target, ev );
						} else if ( fn && 'function' === typeof fn.handleEvent ) {
							fn.handleEvent( ev );
						}
					}, 0 );
				};
			};
			wrap( d );
			wrap( w );
		};

		var run = function ( all ) {
			patch();
			var list = d.querySelectorAll( SELECTOR );
			for ( var i = 0; i < list.length; i++ ) {
				var s = list[ i ];
				if ( s.shsoQueued || ( ! all && 'tp' !== s.getAttribute( 'data-shso-delay' ) ) ) {
					continue;
				}
				s.shsoQueued = 1;
				queue.push( s );
			}
			if ( ! running ) {
				next();
			}
		};

		var next = function () {
			var s = queue.shift();
			if ( ! s ) {
				running = false;
				if ( d.querySelector( SELECTOR ) ) {
					api.state = 'waiting';
				} else {
					done();
				}
				return;
			}
			running = true;
			api.state = 'running';
			started++;
			exec( s, next );
		};

		var exec = function ( old, callback ) {
			var called = false;
			var onload = w.onload;
			var finish = function () {
				if ( called ) {
					return;
				}
				called = true;
				if ( replay && w.onload !== onload && 'function' === typeof w.onload && 'complete' === d.readyState ) {
					var fn = w.onload;
					setTimeout( function () {
						fn.call( w, new Event( 'load' ) );
					}, 0 );
				}
				setTimeout( callback, 0 );
			};
			try {
				var n = d.createElement( 'script' );
				var attrs = old.attributes;
				for ( var i = 0; i < attrs.length; i++ ) {
					var name = attrs[ i ].name;
					if ( 'type' !== name && 'data-shso-src' !== name && 'data-shso-type' !== name && 'data-shso-delay' !== name ) {
						n.setAttribute( name, attrs[ i ].value );
					}
				}
				var type = old.getAttribute( 'data-shso-type' );
				if ( type ) {
					n.setAttribute( 'type', type );
				}
				if ( old.nonce ) {
					n.nonce = old.nonce;
				}
				var src = old.getAttribute( 'data-shso-src' );
				current = n;
				if ( src ) {
					if ( n.hasAttribute( 'nomodule' ) && 'noModule' in n ) {
						old.parentNode.replaceChild( n, old );
						finish();
						return;
					}
					n.async = false;
					n.addEventListener( 'load', finish );
					n.addEventListener( 'error', finish );
					setTimeout( finish, 20000 );
					n.src = src;
					old.parentNode.replaceChild( n, old );
				} else {
					n.text = old.text || old.textContent || '';
					old.parentNode.replaceChild( n, old );
					finish();
				}
			} catch ( e ) {
				finish();
			}
		};

		var done = function () {
			if ( finished ) {
				return;
			}
			finished = true;
			api.state = 'done';
			var after = w.SHSODelayAfter || [];
			for ( var i = 0; i < after.length; i++ ) {
				try {
					after[ i ]();
				} catch ( e ) {}
			}
			var ev;
			try {
				ev = new CustomEvent( 'shso:delay-done' );
			} catch ( e ) {
				ev = d.createEvent( 'Event' );
				ev.initEvent( 'shso:delay-done', true, true );
			}
			d.dispatchEvent( ev );
		};

		var idle = function ( fn ) {
			if ( w.requestIdleCallback ) {
				w.requestIdleCallback( fn, { timeout: 2000 } );
			} else {
				setTimeout( fn, 1 );
			}
		};

		for ( var i = 0; i < EVENTS.length; i++ ) {
			w.addEventListener( EVENTS[ i ], onEvent, OPTIONS );
		}

		d.addEventListener( 'DOMContentLoaded', function () {
			if ( null !== pending ) {
				run( pending );
			}
		} );

		w.addEventListener( 'load', function () {
			loadedAt = Date.now();
			startY = w.pageYOffset || 0;
			if ( tpTimeout > 0 ) {
				setTimeout( function () {
					idle( function () {
						request( false );
					} );
				}, tpTimeout );
			}
			if ( allTimeout > 0 ) {
				setTimeout( function () {
					idle( function () {
						request( true );
					} );
				}, allTimeout );
			}
		} );
	} catch ( e ) {}
}( window, document ) );
