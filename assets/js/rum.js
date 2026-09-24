/*! SH Speed Optimizer — anonymous Core Web Vitals sampling (opt-in). No cookies, no identifiers. */
( function ( w, d ) {
	'use strict';
	try {
		var c = w.__shsoRum;
		if ( ! c || ! w.PerformanceObserver || ! navigator.sendBeacon || Math.random() >= c.rate ) {
			return;
		}
		var m = {};
		var sent = false;
		var sv = 0;
		var se = [];
		var inp = 0;
		var obs = function ( type, cb, opts ) {
			try {
				var po = new PerformanceObserver( function ( l ) {
					l.getEntries().forEach( cb );
				} );
				var o = opts || {};
				o.type = type;
				o.buffered = true;
				po.observe( o );
			} catch ( e ) {}
		};
		obs( 'largest-contentful-paint', function ( e ) {
			m.lcp = Math.round( e.startTime );
		} );
		obs( 'paint', function ( e ) {
			if ( 'first-contentful-paint' === e.name ) {
				m.fcp = Math.round( e.startTime );
			}
		} );
		obs( 'layout-shift', function ( e ) {
			if ( e.hadRecentInput ) {
				return;
			}
			var f = se[ 0 ];
			var l = se[ se.length - 1 ];
			if ( sv && l && e.startTime - l.startTime < 1000 && e.startTime - f.startTime < 5000 ) {
				sv += e.value;
				se.push( e );
			} else {
				sv = e.value;
				se = [ e ];
			}
			m.cls = Math.max( m.cls || 0, Math.round( sv * 10000 ) / 10000 );
		} );
		obs( 'event', function ( e ) {
			if ( e.interactionId && e.duration > inp ) {
				inp = e.duration;
				m.inp = Math.round( inp );
			}
		}, { durationThreshold: 40 } );
		try {
			var n = performance.getEntriesByType( 'navigation' )[ 0 ];
			if ( n && n.responseStart > 0 ) {
				m.ttfb = Math.round( n.responseStart );
			}
		} catch ( e ) {}
		var send = function () {
			if ( sent || 'hidden' !== d.visibilityState ) {
				return;
			}
			sent = true;
			if ( undefined === m.cls ) {
				m.cls = 0;
			}
			navigator.sendBeacon( c.url, new Blob( [ JSON.stringify( { m: m, t: c.t } ) ], { type: 'application/json' } ) );
		};
		d.addEventListener( 'visibilitychange', send, true );
		w.addEventListener( 'pagehide', function () {
			send();
		}, true );
	} catch ( e ) {}
} )( window, document );
