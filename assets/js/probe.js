/*!
 * SH Speed Optimizer — verification probe.
 *
 * Injected only into pages requested with a signed verification token, inside
 * a same-origin iframe opened by the administrator's dashboard. Collects
 * JavaScript errors, failed resources, layout metrics and above-the-fold
 * information, then reports to the parent window. Never runs for visitors.
 */
( function () {
	'use strict';

	var cfg = window.__shsoProbeConfig || {};
	var started = Date.now();
	var R = {
		v: 1,
		nonce: cfg.nonce || '',
		mode: cfg.mode || '',
		purpose: cfg.purpose || 'verify',
		template: cfg.template || '',
		url: String( location.href ).replace( /([?&])(shso_verify|shso_nc)=[^&#]*/g, '$1' ).replace( /[?&]+$/, '' ),
		viewport: { w: window.innerWidth, h: window.innerHeight, dpr: window.devicePixelRatio || 1 },
		errors: [],
		resource_errors: [],
		console_errors: 0,
		rejections: 0,
		cls: 0,
		lcp: null,
		fcp_ms: null,
		longtasks: { count: 0, total_ms: 0 },
		dom: null,
		dom_after: null,
		images: null,
		fonts: null,
		css: null,
		resources: null,
		delayed: null,
		timing: {}
	};

	function clip( value, max ) {
		value = String( value === undefined || value === null ? '' : value );
		return value.length > max ? value.slice( 0, max ) : value;
	}

	function push( list, item, max ) {
		if ( list.length < max ) {
			list.push( item );
		}
	}

	// ---------------------------------------------------------------- errors
	window.addEventListener( 'error', function ( e ) {
		try {
			var t = e.target;
			if ( t && t !== window && t.tagName ) {
				push( R.resource_errors, { url: clip( t.currentSrc || t.src || t.href || '', 300 ), tag: t.tagName.toLowerCase() }, 50 );
				return;
			}
			push( R.errors, { msg: clip( e.message, 300 ), src: clip( e.filename, 300 ), line: e.lineno || 0 }, 50 );
		} catch ( x ) {}
	}, true );

	window.addEventListener( 'unhandledrejection', function ( e ) {
		try {
			R.rejections++;
			var reason = e.reason && ( e.reason.message || e.reason );
			push( R.errors, { msg: clip( 'Unhandled promise rejection: ' + reason, 300 ), src: '', line: 0, rejection: true }, 50 );
		} catch ( x ) {}
	} );

	try {
		var originalError = console.error;
		console.error = function () {
			R.console_errors++;
			try {
				return originalError.apply( console, arguments );
			} catch ( x ) {}
		};
	} catch ( x ) {}

	// --------------------------------------------------------------- metrics
	var lcpEntry = null;
	var sessionValue = 0;
	var sessionEntries = [];

	function observe( type, callback ) {
		try {
			var po = new PerformanceObserver( function ( list ) {
				list.getEntries().forEach( callback );
			} );
			po.observe( { type: type, buffered: true } );
			return po;
		} catch ( x ) {
			return null;
		}
	}

	var lcpObserver = observe( 'largest-contentful-paint', function ( entry ) {
		lcpEntry = entry;
	} );

	observe( 'layout-shift', function ( entry ) {
		if ( entry.hadRecentInput ) {
			return;
		}
		var first = sessionEntries[ 0 ];
		var last = sessionEntries[ sessionEntries.length - 1 ];
		if ( sessionValue && last && entry.startTime - last.startTime < 1000 && entry.startTime - first.startTime < 5000 ) {
			sessionValue += entry.value;
			sessionEntries.push( entry );
		} else {
			sessionValue = entry.value;
			sessionEntries = [ entry ];
		}
		if ( sessionValue > R.cls ) {
			R.cls = Math.round( sessionValue * 10000 ) / 10000;
		}
	} );

	observe( 'paint', function ( entry ) {
		if ( 'first-contentful-paint' === entry.name ) {
			R.fcp_ms = Math.round( entry.startTime );
		}
	} );

	observe( 'longtask', function ( entry ) {
		R.longtasks.count++;
		R.longtasks.total_ms += Math.round( entry.duration );
	} );

	// --------------------------------------------------------------- helpers
	function selectorFor( el ) {
		try {
			var parts = [];
			var depth = 0;
			while ( el && 1 === el.nodeType && depth < 4 ) {
				var part = el.tagName.toLowerCase();
				if ( el.id && /^[A-Za-z][\w-]*$/.test( el.id ) ) {
					parts.unshift( part + '#' + el.id );
					break;
				}
				var cls = ( 'string' === typeof el.className ? el.className : '' ).trim().split( /\s+/ ).filter( function ( c ) {
					return /^[A-Za-z_][\w-]*$/.test( c );
				} ).slice( 0, 2 );
				if ( cls.length ) {
					part += '.' + cls.join( '.' );
				}
				parts.unshift( part );
				el = el.parentElement;
				depth++;
			}
			return clip( parts.join( ' > ' ), 200 );
		} catch ( x ) {
			return '';
		}
	}

	function inViewport( rect ) {
		return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight && rect.left < window.innerWidth;
	}

	function isVisible( el, rect ) {
		if ( ! rect.width || ! rect.height ) {
			return false;
		}
		var style = window.getComputedStyle( el );
		return 'hidden' !== style.visibility && 'none' !== style.display && '0' !== style.opacity;
	}

	function absolute( url, base ) {
		try {
			return new URL( url, base || location.href ).href;
		} catch ( x ) {
			return '';
		}
	}

	function sameOriginSheets() {
		var sheets = [];
		try {
			for ( var i = 0; i < document.styleSheets.length; i++ ) {
				var sheet = document.styleSheets[ i ];
				try {
					if ( sheet.cssRules ) {
						sheets.push( sheet );
					}
				} catch ( x ) {}
			}
		} catch ( x ) {}
		return sheets;
	}

	// ------------------------------------------------------------ collectors
	function collectDom() {
		var out = { elements: 0, visible: 0, height: 0, width: 0, forms: 0, inputs: 0, buttons: 0, links: 0, images: 0, iframes: 0, markers: {} };
		try {
			var all = document.body ? document.body.getElementsByTagName( '*' ) : [];
			out.elements = all.length;
			var limit = Math.min( all.length, 4000 );
			for ( var i = 0; i < limit; i++ ) {
				var r = all[ i ].getBoundingClientRect();
				if ( r.width > 0 && r.height > 0 ) {
					out.visible++;
				}
			}
			out.height = Math.round( Math.max( document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0 ) );
			out.width = Math.round( document.documentElement.scrollWidth );
			out.forms = document.forms.length;
			out.inputs = document.querySelectorAll( 'input:not([type=hidden]), select, textarea' ).length;
			out.buttons = document.querySelectorAll( 'button, input[type=submit], [role=button]' ).length;
			out.links = document.links.length;
			out.images = document.images.length;
			out.iframes = document.getElementsByTagName( 'iframe' ).length + document.querySelectorAll( '.shso-facade' ).length;
			out.media_in_view = { video: false, map: false };
			var frames = document.querySelectorAll( 'iframe, .shso-facade' );
			for ( var f = 0; f < frames.length && f < 50; f++ ) {
				var frameRect = frames[ f ].getBoundingClientRect();
				if ( ! inViewport( frameRect ) ) {
					continue;
				}
				var frameSrc = frames[ f ].getAttribute( 'src' ) || frames[ f ].getAttribute( 'data-shso-src' ) || '';
				if ( /youtube|vimeo/i.test( frameSrc ) ) {
					out.media_in_view.video = true;
				}
				if ( /google\.[^/]+\/maps|maps\.google\./i.test( frameSrc ) ) {
					out.media_in_view.map = true;
				}
			}
			var markers = [ 'header', 'nav', 'main', 'footer', 'form', '.elementor', '.elementor-widget', '.woocommerce', '.wc-block-grid', '.wp-block-navigation', '.menu', '.swiper', '[class*="slider"]', '.wpcf7', '.site-header', '.site-footer' ];
			markers.forEach( function ( sel ) {
				try {
					var nodes = document.querySelectorAll( sel );
					var visible = 0;
					for ( var j = 0; j < nodes.length && j < 200; j++ ) {
						var rect = nodes[ j ].getBoundingClientRect();
						if ( isVisible( nodes[ j ], rect ) ) {
							visible++;
						}
					}
					if ( nodes.length ) {
						out.markers[ sel ] = { count: nodes.length, visible: visible };
					}
				} catch ( x ) {}
			} );
		} catch ( x ) {}
		return out;
	}

	function collectLcp() {
		if ( ! lcpEntry ) {
			return null;
		}
		var el = lcpEntry.element;
		var type = 'other';
		var url = lcpEntry.url || '';
		if ( el ) {
			var tag = el.tagName.toLowerCase();
			if ( 'img' === tag ) {
				type = 'img';
				url = el.currentSrc || el.src || url;
			} else if ( 'video' === tag ) {
				type = 'video';
			} else if ( url ) {
				type = 'background';
			} else {
				type = 'text';
			}
		}
		var rect = el ? el.getBoundingClientRect() : null;
		return {
			ms: Math.round( lcpEntry.startTime ),
			size: lcpEntry.size || 0,
			type: type,
			url: clip( url ? absolute( url ) : '', 500 ),
			selector: el ? selectorFor( el ) : '',
			in_viewport: rect ? inViewport( rect ) : false
		};
	}

	function collectImages() {
		var out = { above_fold: [], distorted: [], oversized: [], lazy_above_fold: [] };
		try {
			var imgs = document.images;
			for ( var i = 0; i < imgs.length && i < 400; i++ ) {
				var img = imgs[ i ];
				var rect = img.getBoundingClientRect();
				var src = img.currentSrc || img.src || '';
				if ( ! src || 0 === src.indexOf( 'data:' ) ) {
					continue;
				}
				if ( inViewport( rect ) && isVisible( img, rect ) ) {
					push( out.above_fold, clip( src, 500 ), 12 );
					if ( 'lazy' === img.getAttribute( 'loading' ) ) {
						push( out.lazy_above_fold, clip( src, 500 ), 12 );
					}
				}
				if ( img.naturalWidth > 0 && img.naturalHeight > 0 && rect.width > 20 && rect.height > 20 ) {
					var fit = window.getComputedStyle( img ).objectFit;
					var natural = img.naturalWidth / img.naturalHeight;
					var rendered = rect.width / rect.height;
					if ( ( ! fit || 'fill' === fit ) && Math.abs( rendered - natural ) / natural > 0.05 ) {
						push( out.distorted, { src: clip( src, 500 ), natural: [ img.naturalWidth, img.naturalHeight ], rendered: [ Math.round( rect.width ), Math.round( rect.height ) ] }, 20 );
					}
					var needed = rect.width * ( window.devicePixelRatio || 1 );
					if ( img.naturalWidth > needed * 1.5 && img.naturalWidth - needed > 400 ) {
						push( out.oversized, { src: clip( src, 500 ), natural: [ img.naturalWidth, img.naturalHeight ], rendered: [ Math.round( rect.width ), Math.round( rect.height ) ] }, 20 );
					}
				}
			}
		} catch ( x ) {}
		return out;
	}

	function fontFaceUrls() {
		var faces = [];
		sameOriginSheets().forEach( function ( sheet ) {
			var walk = function ( rules ) {
				for ( var i = 0; i < rules.length && faces.length < 200; i++ ) {
					var rule = rules[ i ];
					if ( rule.cssRules && ! ( rule instanceof CSSFontFaceRule ) ) {
						try {
							walk( rule.cssRules );
						} catch ( x ) {}
						continue;
					}
					if ( 'undefined' !== typeof CSSFontFaceRule && rule instanceof CSSFontFaceRule ) {
						var style = rule.style;
						var src = style.getPropertyValue( 'src' ) || '';
						var match = src.match( /url\(\s*["']?([^"')]+\.woff2[^"')]*)["']?\s*\)/i );
						faces.push( {
							family: ( style.getPropertyValue( 'font-family' ) || '' ).replace( /["']/g, '' ).trim(),
							weight: ( style.getPropertyValue( 'font-weight' ) || '400' ).trim(),
							style: ( style.getPropertyValue( 'font-style' ) || 'normal' ).trim(),
							url: match ? absolute( match[ 1 ], sheet.href || location.href ) : ''
						} );
					}
				}
			};
			try {
				walk( sheet.cssRules );
			} catch ( x ) {}
		} );
		return faces;
	}

	function normalizeWeight( w ) {
		if ( 'normal' === w ) {
			return '400';
		}
		if ( 'bold' === w ) {
			return '700';
		}
		return String( w );
	}

	function collectFonts() {
		var out = { loaded: [], used_above_fold: [], preload_candidates: [] };
		try {
			var used = {};
			var nodes = document.body ? document.body.querySelectorAll( 'h1,h2,h3,h4,p,a,span,li,button,div,label' ) : [];
			var checked = 0;
			for ( var i = 0; i < nodes.length && checked < 400; i++ ) {
				var el = nodes[ i ];
				if ( ! el.firstChild || 3 !== el.firstChild.nodeType || ! el.textContent.trim() ) {
					continue;
				}
				var rect = el.getBoundingClientRect();
				if ( ! inViewport( rect ) ) {
					continue;
				}
				checked++;
				var cs = window.getComputedStyle( el );
				var family = ( cs.fontFamily || '' ).split( ',' )[ 0 ].replace( /["']/g, '' ).trim();
				var key = family + '|' + normalizeWeight( cs.fontWeight ) + '|' + cs.fontStyle;
				used[ key ] = { family: family, weight: normalizeWeight( cs.fontWeight ), style: cs.fontStyle };
			}
			Object.keys( used ).forEach( function ( k ) {
				push( out.used_above_fold, used[ k ], 20 );
			} );
			if ( document.fonts && document.fonts.forEach ) {
				document.fonts.forEach( function ( ff ) {
					if ( 'loaded' === ff.status ) {
						push( out.loaded, { family: ff.family.replace( /["']/g, '' ), weight: normalizeWeight( ff.weight ), style: ff.style }, 40 );
					}
				} );
			}
			var faces = fontFaceUrls();
			out.preload_candidates = [];
			out.used_above_fold.forEach( function ( u ) {
				faces.forEach( function ( f ) {
					if ( f.url && f.family === u.family && normalizeWeight( f.weight ) === u.weight && f.style === u.style ) {
						var exists = out.preload_candidates.some( function ( c ) {
							return c.url === f.url;
						} );
						if ( ! exists ) {
							push( out.preload_candidates, { url: clip( f.url, 500 ), family: f.family, weight: u.weight, style: u.style, type: 'font/woff2' }, 4 );
						}
					}
				} );
			} );
		} catch ( x ) {}
		return out;
	}

	function collectCss() {
		var out = { sheets: [], cross_origin: 0 };
		try {
			var total = 0;
			for ( var i = 0; i < document.styleSheets.length; i++ ) {
				var sheet = document.styleSheets[ i ];
				var rules;
				try {
					rules = sheet.cssRules;
				} catch ( x ) {
					out.cross_origin++;
					continue;
				}
				if ( ! sheet.href || ! rules ) {
					continue;
				}
				var count = 0;
				var used = 0;
				var walk = function ( list ) {
					for ( var j = 0; j < list.length && total < 6000; j++ ) {
						var rule = list[ j ];
						if ( rule.selectorText ) {
							count++;
							total++;
							var sel = rule.selectorText.replace( /::?[a-zA-Z-]+(\([^)]*\))?/g, '' ).trim() || '*';
							try {
								if ( document.querySelector( sel ) ) {
									used++;
								}
							} catch ( x ) {
								used++; // Unknown selector syntax: assume used.
							}
						} else if ( rule.cssRules ) {
							try {
								walk( rule.cssRules );
							} catch ( x ) {}
						}
					}
				};
				walk( rules );
				push( out.sheets, { href: clip( sheet.href, 500 ), rules: count, used: used }, 60 );
			}
		} catch ( x ) {}
		return out;
	}

	function collectResources() {
		var out = { count: 0, third_party: 0, bytes: { script: 0, css: 0, img: 0, font: 0, other: 0 }, transfer_total: 0, failed_known: 0 };
		try {
			var entries = performance.getEntriesByType( 'resource' );
			out.count = entries.length;
			entries.forEach( function ( e ) {
				var size = e.transferSize || e.encodedBodySize || 0;
				out.transfer_total += size;
				var type = e.initiatorType;
				var bucket = 'script' === type ? 'script' : ( 'link' === type || 'css' === type ) ? 'css' : ( 'img' === type || 'image' === type ) ? 'img' : ( /\.(woff2?|ttf|otf)(\?|$)/.test( e.name ) ? 'font' : 'other' );
				out.bytes[ bucket ] += size;
				try {
					if ( new URL( e.name ).origin !== location.origin ) {
						out.third_party++;
					}
				} catch ( x ) {}
			} );
		} catch ( x ) {}
		return out;
	}

	// ------------------------------------------------------------- lifecycle
	var reported = false;

	function report() {
		if ( reported ) {
			return;
		}
		reported = true;
		R.timing.total_ms = Date.now() - started;
		try {
			var nav = performance.getEntriesByType( 'navigation' )[ 0 ];
			if ( nav ) {
				R.timing.ttfb_ms = Math.round( nav.responseStart - nav.requestStart );
				R.timing.dom_content_loaded_ms = Math.round( nav.domContentLoadedEventEnd );
				R.timing.load_ms = Math.round( nav.loadEventEnd );
			}
		} catch ( x ) {}
		window.__shsoProbeResult = R;
		try {
			if ( window.parent && window.parent !== window ) {
				window.parent.postMessage( { type: 'shso-probe', nonce: R.nonce, result: R }, location.origin );
			}
		} catch ( x ) {}
	}

	function afterInteraction() {
		try {
			R.dom_after = collectDom();
			if ( window.SHSODelay ) {
				R.delayed = { state: window.SHSODelay.state || '', count: window.SHSODelay.count || 0 };
			}
			window.scrollTo( 0, 0 );
		} catch ( x ) {}
		setTimeout( report, 400 );
	}

	function interact() {
		try {
			// Real scroll events (trusted) trigger interaction-delayed scripts.
			window.scrollTo( 0, Math.min( 600, Math.max( 0, document.documentElement.scrollHeight - window.innerHeight ) ) );
			if ( window.SHSODelay && 'function' === typeof window.SHSODelay.trigger ) {
				window.SHSODelay.trigger();
			}
		} catch ( x ) {}
		var done = false;
		var finish = function () {
			if ( ! done ) {
				done = true;
				setTimeout( afterInteraction, 1200 );
			}
		};
		document.addEventListener( 'shso:delay-done', finish );
		setTimeout( finish, 3500 );
	}

	function firstPhase() {
		try {
			if ( lcpObserver ) {
				lcpObserver.takeRecords && lcpObserver.takeRecords().forEach( function ( e ) {
					lcpEntry = e;
				} );
			}
			R.lcp = collectLcp();
			R.dom = collectDom();
			R.images = collectImages();
			R.fonts = collectFonts();
			if ( 'analyze' === R.purpose ) {
				R.css = collectCss();
			}
			R.resources = collectResources();
		} catch ( x ) {
			push( R.errors, { msg: 'probe: ' + clip( x && x.message, 200 ), src: 'probe', line: 0, probe: true }, 50 );
		}
		interact();
	}

	function onLoad() {
		setTimeout( firstPhase, 1500 );
	}

	if ( 'complete' === document.readyState ) {
		onLoad();
	} else {
		window.addEventListener( 'load', onLoad );
	}

	// Hard stop so the dashboard never waits forever.
	setTimeout( report, 25000 );
} )();
