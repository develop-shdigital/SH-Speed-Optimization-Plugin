/*!
 * SH Speed Optimizer - critical CSS generator.
 *
 * Runs inside the page (the engine loads it into a same-origin iframe whose
 * viewport is e.g. 1350×900 or 412×915) and extracts the CSS needed to render
 * the first viewport:
 *
 *   var result = window.SHSOCritical.generate( iframeWindow, { max_bytes: 61440 } );
 *   // { css, rules_total, rules_kept, sheets_skipped, fold, width, truncated, sheets[], items[] }
 *   var merged = window.SHSOCritical.merge( [ desktopResult, mobileResult ] );
 *   // { css, rules_kept, truncated }
 *
 * Kept: style rules whose selector (without pseudo-elements and user-action
 * pseudo-classes such as :hover) matches an element intersecting the first
 * viewport, rules on :root/html/body (custom properties), @font-face rules for
 * fonts used above the fold, @keyframes used above the fold, matching @media /
 * @supports blocks (recursively, with only their matching children), @layer
 * order statements, @property and @counter-style rules. Cross-origin
 * stylesheets cannot be read and are counted in sheets_skipped. Relative url()
 * references are made absolute. Output is capped (default 60 KB).
 *
 * Dependency-free ES2017; never throws (returns { error } instead).
 */
( function ( root ) {
	'use strict';

	var DEFAULT_MAX = 61440;
	var PSEUDO_ELEMENTS = /::?(?:before|after|first-line|first-letter|selection|placeholder|marker|backdrop|file-selector-button|spelling-error|grammar-error)(?![\w-])|::(?:part|slotted|highlight|cue)\([^)]*\)|::?-(?:webkit|moz|ms|o)-[\w-]+(?:\([^)]*\))?|::cue(?![\w-])/gi;
	var USER_ACTIONS = /:(?:hover|focus-within|focus-visible|focus|active|visited|target-within|target|link|any-link)(?![\w-])/gi;

	function normalizeFamily( family ) {
		return String( family || '' ).trim().replace( /^["']|["']$/g, '' ).toLowerCase();
	}

	// Split a selector list at top-level commas (not inside (), [] or strings).
	function splitSelectors( text ) {
		var out = [];
		var depth = 0;
		var quote = '';
		var start = 0;
		for ( var i = 0; i < text.length; i++ ) {
			var c = text[ i ];
			if ( quote ) {
				if ( '\\' === c ) {
					i++;
				} else if ( c === quote ) {
					quote = '';
				}
				continue;
			}
			if ( '"' === c || "'" === c ) {
				quote = c;
			} else if ( '\\' === c ) {
				i++;
			} else if ( '(' === c || '[' === c ) {
				depth++;
			} else if ( ')' === c || ']' === c ) {
				depth--;
			} else if ( ',' === c && 0 === depth ) {
				out.push( text.slice( start, i ).trim() );
				start = i + 1;
			}
		}
		out.push( text.slice( start ).trim() );
		return out.filter( function ( s ) {
			return '' !== s;
		} );
	}

	// Selector usable with querySelectorAll: without pseudo-elements and user-action states.
	function matchable( selector ) {
		var s = selector.replace( PSEUDO_ELEMENTS, '' ).replace( USER_ACTIONS, '' );
		s = s.replace( /:(?:not|is|where|has|matches)\(\s*\)/g, '' ).trim();
		s = s.replace( /([>+~])\s*(?=[>+~]|$)/g, '$1 *' ).replace( /^([>+~])/, '* $1' ).trim();
		return '' === s ? '*' : s;
	}

	function absolutize( text, base ) {
		if ( ! base || -1 === text.indexOf( 'url(' ) ) {
			return text;
		}
		return text.replace( /url\(\s*(?:"((?:[^"\\]|\\.)*)"|'((?:[^'\\]|\\.)*)'|([^'")\s]+))\s*\)/g, function ( match, dq, sq, uq ) {
			var url = undefined !== dq ? dq : ( undefined !== sq ? sq : uq );
			if ( ! url || /^(?:data:|#|[a-z][a-z0-9+.\-]*:|\/)/i.test( url ) ) {
				return match;
			}
			try {
				return 'url("' + new URL( url, base ).href.replace( /"/g, '%22' ) + '")';
			} catch ( e ) {
				return match;
			}
		} );
	}

	function compareLeaves( a, b ) {
		var n = Math.min( a.path.length, b.path.length );
		for ( var i = 0; i < n; i++ ) {
			if ( a.path[ i ] !== b.path[ i ] ) {
				return a.path[ i ] - b.path[ i ];
			}
		}
		return a.path.length - b.path.length;
	}

	// Serialize leaves (sorted by source position), opening/closing wrapper blocks as needed.
	function build( leaves, max ) {
		var css = '';
		var open = [];
		var kept = 0;
		var truncated = false;
		leaves = leaves.slice().sort( compareLeaves );
		for ( var i = 0; i < leaves.length; i++ ) {
			var leaf = leaves[ i ];
			var common = 0;
			while ( common < open.length && common < leaf.wrappers.length && open[ common ] === leaf.wrappers[ common ] ) {
				common++;
			}
			var chunk = '';
			for ( var c = open.length; c > common; c-- ) {
				chunk += '}';
			}
			for ( var o = common; o < leaf.wrappers.length; o++ ) {
				chunk += leaf.wrappers[ o ] + '{';
			}
			chunk += leaf.text;
			var closing = leaf.wrappers.length;
			if ( css.length + chunk.length + closing > max ) {
				truncated = true;
				break;
			}
			css += chunk;
			open = leaf.wrappers.slice();
			kept++;
		}
		for ( var k = 0; k < open.length; k++ ) {
			css += '}';
		}
		return { css: css, kept: kept, truncated: truncated };
	}

	function generate( win, options ) {
		try {
			win = win || root;
			options = options || {};
			var doc = win.document;
			var max = options.max_bytes || DEFAULT_MAX;
			var deadline = Date.now() + ( options.time_budget_ms || 15000 );
			var exclude = options.exclude || [];
			var fold = win.innerHeight;
			var width = win.innerWidth;
			var visible = new Set();
			var fonts = {};
			var animations = {};
			var matchCache = {};
			var leaves = [];
			var sheetsUsed = [];
			var stats = { total: 0, skipped: 0 };

			// Elements intersecting the first viewport.
			var all = doc.querySelectorAll( '*' );
			for ( var i = 0; i < all.length; i++ ) {
				var el = all[ i ];
				if ( el === doc.documentElement || el === doc.body ) {
					visible.add( el );
					continue;
				}
				if ( ! el.getClientRects || 0 === el.getClientRects().length ) {
					continue;
				}
				var r = el.getBoundingClientRect();
				if ( r.bottom >= 0 && r.top < fold && r.right >= 0 && r.left < width ) {
					visible.add( el );
				}
			}
			visible.forEach( function ( node ) {
				var cs = win.getComputedStyle( node );
				String( cs.fontFamily || '' ).split( ',' ).forEach( function ( f ) {
					fonts[ normalizeFamily( f ) ] = true;
				} );
				String( cs.animationName || '' ).split( ',' ).forEach( function ( a ) {
					animations[ a.trim() ] = true;
				} );
				[ '::before', '::after' ].forEach( function ( pseudo ) {
					var ps = win.getComputedStyle( node, pseudo );
					if ( ps && ps.content && 'none' !== ps.content && 'normal' !== ps.content ) {
						String( ps.fontFamily || '' ).split( ',' ).forEach( function ( f ) {
							fonts[ normalizeFamily( f ) ] = true;
						} );
					}
				} );
			} );

			var selectorMatches = function ( selector ) {
				var key = matchable( selector );
				if ( Object.prototype.hasOwnProperty.call( matchCache, key ) ) {
					return matchCache[ key ];
				}
				var result = false;
				var list = null;
				try {
					list = doc.querySelectorAll( key );
				} catch ( e ) {
					try {
						list = doc.querySelectorAll( selector );
					} catch ( e2 ) {
						list = null;
					}
				}
				if ( list ) {
					for ( var n = 0; n < list.length; n++ ) {
						if ( visible.has( list[ n ] ) ) {
							result = true;
							break;
						}
					}
				}
				matchCache[ key ] = result;
				return result;
			};

			var walk = function ( rules, path, wrappers, base ) {
				for ( var j = 0; j < rules.length; j++ ) {
					if ( Date.now() > deadline ) {
						throw new Error( 'timeout' );
					}
					var rule = rules[ j ];
					var here = path.concat( j );
					var type = rule.constructor ? rule.constructor.name : '';

					if ( 'CSSStyleRule' === type || 1 === rule.type ) {
						stats.total++;
						var selectors = splitSelectors( rule.selectorText || '' );
						var matched = selectors.filter( selectorMatches );
						if ( ! matched.length ) {
							continue;
						}
						var text = ( rule.cssRules && rule.cssRules.length ) || matched.length === selectors.length
							? rule.cssText
							: matched.join( ',' ) + '{' + rule.style.cssText + '}';
						leaves.push( { path: here, wrappers: wrappers, text: absolutize( text, base ) } );
					} else if ( 'CSSMediaRule' === type || 4 === rule.type ) {
						var media = rule.media ? rule.media.mediaText : '';
						if ( ! media || win.matchMedia( media ).matches ) {
							walk( rule.cssRules, here, wrappers.concat( '@media ' + media ), base );
						}
					} else if ( 'CSSSupportsRule' === type || 12 === rule.type ) {
						var condition = rule.conditionText || '';
						var ok = false;
						try {
							ok = win.CSS && win.CSS.supports( condition );
						} catch ( e ) {
							ok = false;
						}
						if ( ok ) {
							walk( rule.cssRules, here, wrappers.concat( '@supports ' + condition ), base );
						}
					} else if ( 'CSSFontFaceRule' === type || 5 === rule.type ) {
						if ( fonts[ normalizeFamily( rule.style.getPropertyValue( 'font-family' ) ) ] ) {
							leaves.push( { path: here, wrappers: wrappers, text: absolutize( rule.cssText, base ) } );
						}
					} else if ( 'CSSKeyframesRule' === type || 7 === rule.type ) {
						if ( animations[ rule.name ] ) {
							leaves.push( { path: here, wrappers: wrappers, text: rule.cssText } );
						}
					} else if ( 'CSSImportRule' === type || 3 === rule.type ) {
						var importMedia = rule.media ? rule.media.mediaText : '';
						if ( importMedia && ! win.matchMedia( importMedia ).matches ) {
							continue;
						}
						var imported = null;
						try {
							imported = rule.styleSheet ? rule.styleSheet.cssRules : null;
						} catch ( e ) {
							stats.skipped++;
						}
						if ( imported ) {
							walk( imported, here, importMedia && 'all' !== importMedia ? wrappers.concat( '@media ' + importMedia ) : wrappers, rule.styleSheet.href || base );
						}
					} else if ( 'CSSLayerBlockRule' === type || 'CSSContainerRule' === type || 'CSSScopeRule' === type ) {
						var prelude = String( rule.cssText ).split( '{' )[ 0 ].trim();
						walk( rule.cssRules, here, wrappers.concat( prelude ), base );
					} else if ( 'CSSLayerStatementRule' === type || 'CSSPropertyRule' === type || 'CSSCounterStyleRule' === type ) {
						leaves.push( { path: here, wrappers: wrappers, text: rule.cssText } );
					}
				}
			};

			var sheets = doc.styleSheets;
			for ( var s = 0; s < sheets.length; s++ ) {
				var sheet = sheets[ s ];
				var owner = sheet.ownerNode;
				if ( sheet.disabled || ! owner || 'shso-critical-css' === owner.id ) {
					continue;
				}
				var isLink = 'LINK' === owner.nodeName;
				if ( ! isLink && ! options.include_inline ) {
					continue; // Inline <style> blocks stay in the page anyway.
				}
				if ( isLink && /(^|\s)alternate(\s|$)/i.test( owner.getAttribute( 'rel' ) || '' ) ) {
					continue;
				}
				var href = sheet.href || '';
				if ( href && exclude.some( function ( part ) {
					return -1 !== href.indexOf( part );
				} ) ) {
					continue;
				}
				var sheetMedia = sheet.media ? sheet.media.mediaText : '';
				if ( sheetMedia && ! win.matchMedia( sheetMedia ).matches ) {
					continue;
				}
				var rules = null;
				try {
					rules = sheet.cssRules;
				} catch ( e ) {
					stats.skipped++; // Cross-origin stylesheet.
					continue;
				}
				if ( ! rules ) {
					stats.skipped++;
					continue;
				}
				if ( href ) {
					sheetsUsed.push( href );
				}
				var wrappers = sheetMedia && 'all' !== sheetMedia ? [ '@media ' + sheetMedia ] : [];
				walk( rules, [ s ], wrappers, href || doc.baseURI );
			}

			var built = build( leaves, max );
			return {
				css: built.css,
				rules_total: stats.total,
				rules_kept: built.kept,
				sheets_skipped: stats.skipped,
				fold: fold,
				width: width,
				truncated: built.truncated,
				sheets: sheetsUsed,
				items: leaves
			};
		} catch ( e ) {
			return { error: String( e && e.message ? e.message : e ) };
		}
	}

	// Merge results of several viewports (union of rules, original source order).
	function merge( results, options ) {
		try {
			var seen = {};
			var leaves = [];
			( results || [] ).forEach( function ( result ) {
				( result && result.items ? result.items : [] ).forEach( function ( leaf ) {
					var key = leaf.path.join( '.' );
					if ( ! seen[ key ] ) {
						seen[ key ] = true;
						leaves.push( leaf );
					}
				} );
			} );
			var built = build( leaves, ( options && options.max_bytes ) || DEFAULT_MAX );
			return { css: built.css, rules_kept: built.kept, truncated: built.truncated };
		} catch ( e ) {
			return { error: String( e && e.message ? e.message : e ) };
		}
	}

	try {
		root.SHSOCritical = { generate: generate, merge: merge, version: 1 };
	} catch ( e ) {}
}( 'undefined' !== typeof window ? window : this ) );
