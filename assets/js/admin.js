/**
 * SH Speed Optimizer – admin interface.
 *
 * Plain ES2017 in one IIFE: no framework, no jQuery, no build step.
 * Every number comes from the REST API (shso/v1). Values the API does not
 * provide are shown as unavailable – never estimated. Strings received from
 * the API are only ever inserted as text nodes (see h()), never as HTML.
 */
( function () {
	'use strict';

	const cfg = window.shsoAdmin || {};
	const wpGlobal = window.wp || {};
	const i18n = wpGlobal.i18n || {};
	const __ = 'function' === typeof i18n.__ ? i18n.__ : ( text ) => text;
	const _n = 'function' === typeof i18n._n ? i18n._n : ( single, plural, count ) => ( 1 === count ? single : plural );
	const sprintf = 'function' === typeof i18n.sprintf ? i18n.sprintf : fallbackSprintf;

	const NS = '/' + String( cfg.restNamespace || 'shso/v1' ).replace( /^\/+|\/+$/g, '' );
	const STEP_PAUSE = 300;
	const ACTIVE = [ 'queued', 'running', 'awaiting_browser' ];
	const TERMINAL = [ 'done', 'failed', 'cancelled' ];
	const STORE_ADVANCED = 'shso.advancedControls';

	// ------------------------------------------------------------------
	// Generic helpers
	// ------------------------------------------------------------------

	function fallbackSprintf( format, ...args ) {
		let index = 0;
		return String( format ).replace( /%(?:(\d+)\$)?([sd%])/g, ( match, pos, type ) => {
			if ( '%' === type ) {
				return '%';
			}
			const value = pos ? args[ Number( pos ) - 1 ] : args[ index++ ];
			if ( 'd' === type ) {
				return String( parseInt( value, 10 ) || 0 );
			}
			return undefined === value || null === value ? '' : String( value );
		} );
	}

	/**
	 * Escape a string for use in HTML. The UI builds DOM nodes with
	 * textContent; this helper exists for the rare string-building cases.
	 *
	 * @param {*} value Value.
	 * @return {string} Escaped string.
	 */
	function esc( value ) {
		const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String( null === value || undefined === value ? '' : value ).replace( /[&<>"']/g, ( c ) => map[ c ] );
	}

	const isObj = ( v ) => null !== v && 'object' === typeof v && ! Array.isArray( v );
	const obj = ( v ) => ( isObj( v ) ? v : {} );
	const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

	function str( value, fallback ) {
		if ( 'string' === typeof value && '' !== value ) {
			return value;
		}
		if ( 'number' === typeof value && isFinite( value ) ) {
			return String( value );
		}
		return undefined === fallback ? '' : fallback;
	}

	function num( value ) {
		if ( 'number' === typeof value && isFinite( value ) ) {
			return value;
		}
		if ( 'string' === typeof value && '' !== value.trim() && isFinite( Number( value ) ) ) {
			return Number( value );
		}
		return null;
	}

	function first( ...values ) {
		for ( let i = 0; i < values.length; i++ ) {
			if ( null !== values[ i ] && undefined !== values[ i ] ) {
				return values[ i ];
			}
		}
		return null;
	}

	function safeId( value ) {
		return String( value || '' ).replace( /[^A-Za-z0-9_-]/g, '-' );
	}

	function sleep( ms ) {
		return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	}

	function fmtNumber( value, digits ) {
		const n = num( value );
		if ( null === n ) {
			return '—';
		}
		try {
			return n.toLocaleString( undefined, { maximumFractionDigits: undefined === digits ? 0 : digits } );
		} catch ( e ) {
			return String( n );
		}
	}

	function fmtBytes( value ) {
		let n = num( value );
		if ( null === n ) {
			return '—';
		}
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let i = 0;
		while ( n >= 1024 && i < units.length - 1 ) {
			n /= 1024;
			i++;
		}
		return fmtNumber( n, 0 === i ? 0 : 1 ) + ' ' + units[ i ];
	}

	function fmtDate( value ) {
		if ( 'string' === typeof value && '' !== value && ! /^\d+$/.test( value ) ) {
			return value;
		}
		const n = num( value );
		if ( ! n ) {
			return '';
		}
		try {
			return new Date( n * 1000 ).toLocaleString();
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Percentage display of a ratio (0.42 → 42%).
	 *
	 * @param {*} value Ratio between 0 and 1.
	 * @return {string|null} Display value or null when unavailable.
	 */
	function fmtPercent( value ) {
		const n = num( value );
		if ( null === n ) {
			return null;
		}
		return fmtNumber( n * 100, 1 ) + '%';
	}

	function safeJson( value ) {
		try {
			return JSON.stringify( value, null, 2 );
		} catch ( e ) {
			return String( value );
		}
	}

	function errorMessage( err ) {
		if ( err && 'string' === typeof err.message && '' !== err.message ) {
			return err.message;
		}
		if ( 'string' === typeof err && '' !== err ) {
			return err;
		}
		return __( 'Something went wrong. Please try again.', 'sh-speed-optimizer' );
	}

	const store = {
		get( key ) {
			try {
				return window.localStorage.getItem( key );
			} catch ( e ) {
				return null;
			}
		},
		set( key, value ) {
			try {
				window.localStorage.setItem( key, value );
			} catch ( e ) {
				// Storage unavailable (private mode, blocked): the preference is simply not remembered.
			}
		},
	};

	// ------------------------------------------------------------------
	// REST
	// ------------------------------------------------------------------

	function api( path, method, data ) {
		if ( ! wpGlobal.apiFetch ) {
			return Promise.reject( { message: __( 'A required WordPress script could not be loaded. Please reload the page.', 'sh-speed-optimizer' ) } );
		}
		const options = { path: NS + path, method: method || 'GET' };
		if ( undefined !== data ) {
			options.data = data;
		}
		try {
			return Promise.resolve( wpGlobal.apiFetch( options ) );
		} catch ( e ) {
			return Promise.reject( e );
		}
	}

	// ------------------------------------------------------------------
	// DOM helpers
	// ------------------------------------------------------------------

	const PROPS = [ 'checked', 'disabled', 'value', 'selected', 'hidden', 'open', 'required', 'readOnly' ];

	function append( el, children ) {
		children.forEach( ( child ) => {
			if ( null === child || undefined === child || false === child || true === child ) {
				return;
			}
			if ( Array.isArray( child ) ) {
				append( el, child );
				return;
			}
			el.appendChild( child instanceof Node ? child : document.createTextNode( String( child ) ) );
		} );
		return el;
	}

	/**
	 * Create an element. Children that are not nodes become text nodes.
	 *
	 * @param {string} tag      Tag name.
	 * @param {Object} props    Attributes / properties / on* listeners.
	 * @param {...*}   children Children.
	 * @return {HTMLElement} Element.
	 */
	function h( tag, props, ...children ) {
		const el = document.createElement( tag );
		if ( props ) {
			Object.keys( props ).forEach( ( key ) => {
				const value = props[ key ];
				if ( null === value || undefined === value || false === value ) {
					return;
				}
				if ( 'class' === key ) {
					el.className = Array.isArray( value ) ? value.filter( Boolean ).join( ' ' ) : String( value );
				} else if ( 'text' === key ) {
					el.textContent = String( value );
				} else if ( 'on' === key.slice( 0, 2 ) && 'function' === typeof value ) {
					el.addEventListener( key.slice( 2 ).toLowerCase(), value );
				} else if ( -1 !== PROPS.indexOf( key ) ) {
					el[ key ] = value;
				} else {
					el.setAttribute( key, true === value ? '' : String( value ) );
				}
			} );
		}
		return append( el, children );
	}

	function clear( el ) {
		while ( el && el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		return el;
	}

	function fill( el, ...children ) {
		if ( ! el ) {
			return el;
		}
		clear( el );
		append( el, children );
		el.removeAttribute( 'aria-busy' );
		return el;
	}

	const $ = ( selector, root ) => ( root || document ).querySelector( selector );

	/**
	 * Render into a container; a rendering error only affects that container.
	 *
	 * @param {HTMLElement} el Container.
	 * @param {Function}    fn Returns the children.
	 */
	function renderInto( el, fn ) {
		if ( ! el ) {
			return;
		}
		try {
			fill( el, fn() );
		} catch ( e ) {
			if ( window.console ) {
				window.console.error( e ); // eslint-disable-line no-console
			}
			fill( el, banner( 'poor', __( 'This section could not be displayed. Please reload the page.', 'sh-speed-optimizer' ) ) );
		}
	}

	function announce( text ) {
		const live = document.getElementById( 'shso-live' );
		if ( ! live ) {
			return;
		}
		live.textContent = '';
		setTimeout( () => {
			live.textContent = String( text || '' );
		}, 60 );
	}

	function srText( text ) {
		return h( 'span', { class: 'screen-reader-text' }, text );
	}

	function pill( tone, text, extraClass ) {
		return h( 'span', { class: [ 'shso-pill', 'shso-pill--' + ( tone || 'unknown' ), extraClass ] }, text );
	}

	function mark( tone, symbol, label ) {
		return [
			h( 'span', { class: 'shso-mark shso-mark--' + ( tone || 'unknown' ), 'aria-hidden': 'true' }, symbol ),
			label ? srText( label ) : null,
		];
	}

	function dot( tone ) {
		return h( 'span', { class: 'shso-dot shso-dot--' + ( tone || 'unknown' ), 'aria-hidden': 'true' } );
	}

	const BANNER_ICONS = { good: '✓', attention: '!', poor: '!', info: 'i', unknown: 'i' };

	function banner( tone, text, actions, extra ) {
		return h(
			'div',
			Object.assign( { class: 'shso-banner shso-banner--' + tone }, extra || {} ),
			h( 'span', { class: 'shso-banner__icon', 'aria-hidden': 'true' }, BANNER_ICONS[ tone ] || 'i' ),
			h( 'p', { class: 'shso-banner__text' }, text ),
			actions && actions.length ? h( 'div', { class: 'shso-banner__actions' }, actions ) : null
		);
	}

	function noticeTone( type ) {
		return { success: 'good', error: 'poor', warning: 'attention', info: 'info' }[ type ] || 'info';
	}

	/**
	 * Show a page notice (result of an action).
	 *
	 * @param {string} type success|error|warning|info.
	 * @param {string} text Message.
	 */
	function notify( type, text ) {
		const area = document.getElementById( 'shso-notices' );
		if ( ! area || ! text ) {
			return;
		}
		const tone = noticeTone( type );
		const node = h(
			'div',
			{ class: 'shso-banner shso-banner--' + tone, role: 'error' === type ? 'alert' : null },
			h( 'span', { class: 'shso-banner__icon', 'aria-hidden': 'true' }, BANNER_ICONS[ tone ] ),
			h( 'p', { class: 'shso-banner__text' }, text )
		);
		node.appendChild(
			h( 'button', {
				type: 'button',
				class: 'shso-banner__dismiss',
				'aria-label': __( 'Dismiss this notice', 'sh-speed-optimizer' ),
				onclick: () => node.remove(),
			}, '×' )
		);
		area.appendChild( node );
		while ( area.children.length > 3 ) {
			area.removeChild( area.firstChild );
		}
	}

	function button( label, onClick, opts ) {
		const o = opts || {};
		return h( 'button', {
			type: 'button',
			class: [ 'button', o.primary ? 'button-primary' : null, o.large ? 'shso-btn-lg' : null, o.link ? 'button-link' : null, o.danger ? 'button-link-delete' : null, o.className ],
			id: o.id || null,
			disabled: !! o.disabled,
			'aria-describedby': o.describedby || null,
			onclick: ( e ) => {
				try {
					onClick( e.currentTarget, e );
				} catch ( err ) {
					notify( 'error', errorMessage( err ) );
				}
			},
		}, label );
	}

	function linkButton( label, href, opts ) {
		const o = opts || {};
		return h( 'a', { class: [ 'button', o.primary ? 'button-primary' : null ], href }, label );
	}

	function link( label, href ) {
		return h( 'a', { class: 'shso-link', href }, label );
	}

	function busy( btn, on, label ) {
		if ( ! btn ) {
			return;
		}
		if ( on ) {
			if ( ! btn.hasAttribute( 'data-label' ) ) {
				btn.setAttribute( 'data-label', btn.textContent );
			}
			btn.disabled = true;
			btn.setAttribute( 'aria-busy', 'true' );
			fill( btn, h( 'span', { class: 'shso-spinner', 'aria-hidden': 'true' } ), label || btn.getAttribute( 'data-label' ) );
		} else {
			btn.disabled = false;
			btn.removeAttribute( 'aria-busy' );
			if ( btn.hasAttribute( 'data-label' ) ) {
				btn.textContent = btn.getAttribute( 'data-label' );
				btn.removeAttribute( 'data-label' );
			}
		}
	}

	function card( title, children, opts ) {
		const o = opts || {};
		const titleId = o.id ? o.id + '-title' : null;
		return h(
			'section',
			{ class: [ 'shso-card', o.className ], id: o.id || null, 'aria-labelledby': titleId },
			title ? h( o.heading || 'h3', { class: 'shso-card__title', id: titleId }, title ) : null,
			children
		);
	}

	function cardFooter( ...children ) {
		return h( 'div', { class: 'shso-card__footer' }, children );
	}

	function loading( text ) {
		return h(
			'p',
			{ class: 'shso-loading-inline' },
			h( 'span', { class: 'shso-spinner', 'aria-hidden': 'true' } ),
			text || __( 'Loading…', 'sh-speed-optimizer' )
		);
	}

	function loadError( err, retry ) {
		return banner( 'poor', errorMessage( err ), retry ? [ button( __( 'Try again', 'sh-speed-optimizer' ), retry ) ] : null, { role: 'alert' } );
	}

	function pageUrl( id, hash ) {
		const urls = obj( cfg.adminUrls );
		return str( urls[ id ], '#' ) + ( hash || '' );
	}

	// ------------------------------------------------------------------
	// Shared vocabularies
	// ------------------------------------------------------------------

	const HEALTH = {
		excellent: { tone: 'good', label: __( 'Excellent', 'sh-speed-optimizer' ) },
		good: { tone: 'good', label: __( 'Good', 'sh-speed-optimizer' ) },
		attention: { tone: 'attention', label: __( 'Needs Attention', 'sh-speed-optimizer' ) },
		required: { tone: 'poor', label: __( 'Optimization Required', 'sh-speed-optimizer' ) },
		unknown: { tone: 'unknown', label: __( 'Not analyzed yet', 'sh-speed-optimizer' ) },
	};

	function healthInfo( health ) {
		const data = obj( health );
		return HEALTH[ data.status ] || HEALTH.unknown;
	}

	const METRIC_STATUS = {
		good: { tone: 'good', label: __( 'Good', 'sh-speed-optimizer' ) },
		'needs-improvement': { tone: 'attention', label: __( 'Needs Improvement', 'sh-speed-optimizer' ) },
		poor: { tone: 'poor', label: __( 'Poor', 'sh-speed-optimizer' ) },
		unknown: { tone: 'unknown', label: __( 'Unknown', 'sh-speed-optimizer' ) },
	};

	const METRICS = {
		lcp: { abbr: 'LCP', name: __( 'Largest Contentful Paint', 'sh-speed-optimizer' ), hint: __( 'Loading', 'sh-speed-optimizer' ) },
		inp: { abbr: 'INP', name: __( 'Interaction to Next Paint', 'sh-speed-optimizer' ), hint: __( 'Responsiveness', 'sh-speed-optimizer' ) },
		cls: { abbr: 'CLS', name: __( 'Cumulative Layout Shift', 'sh-speed-optimizer' ), hint: __( 'Visual stability', 'sh-speed-optimizer' ) },
		ttfb: { abbr: 'TTFB', name: __( 'Time to First Byte', 'sh-speed-optimizer' ), hint: __( 'Server response', 'sh-speed-optimizer' ) },
		fcp: { abbr: 'FCP', name: __( 'First Contentful Paint', 'sh-speed-optimizer' ), hint: __( 'First content', 'sh-speed-optimizer' ) },
		tbt: { abbr: 'TBT', name: __( 'Total Blocking Time', 'sh-speed-optimizer' ), hint: __( 'Busy browser', 'sh-speed-optimizer' ) },
		si: { abbr: 'SI', name: __( 'Speed Index', 'sh-speed-optimizer' ), hint: __( 'Visual progress', 'sh-speed-optimizer' ) },
		speed_index: { abbr: 'SI', name: __( 'Speed Index', 'sh-speed-optimizer' ), hint: __( 'Visual progress', 'sh-speed-optimizer' ) },
	};

	const METRIC_ORDER = [ 'lcp', 'inp', 'cls', 'ttfb', 'fcp', 'tbt', 'si', 'speed_index' ];

	function metricDisplay( key, metric ) {
		const m = obj( metric );
		if ( 'string' === typeof m.display && '' !== m.display ) {
			return m.display;
		}
		const value = num( m.value );
		if ( null === value ) {
			return '';
		}
		if ( 'cls' === key ) {
			return value.toFixed( 2 );
		}
		return value >= 1000 ? fmtNumber( value / 1000, 1 ) + ' s' : fmtNumber( value ) + ' ms';
	}

	function metricRow( key, metric ) {
		const meta = METRICS[ key ] || { abbr: String( key ).toUpperCase(), name: String( key ), hint: '' };
		const m = obj( metric );
		const display = metricDisplay( key, m );
		const status = display ? METRIC_STATUS[ m.status ] || METRIC_STATUS.unknown : METRIC_STATUS.unknown;
		return h(
			'li',
			{ class: 'shso-metric' },
			h( 'span', { class: 'shso-metric__name' },
				h( 'abbr', { title: meta.name }, meta.abbr ),
				srText( ' (' + meta.name + ')' ),
				meta.hint ? h( 'span', { class: 'shso-metric__hint' }, meta.hint ) : null
			),
			h( 'span', { class: 'shso-metric__value' }, display || [ h( 'span', { 'aria-hidden': 'true' }, '—' ), srText( __( 'Not measured', 'sh-speed-optimizer' ) ) ] ),
			pill( status.tone, status.label )
		);
	}

	function metricList( metrics, keys, small ) {
		const m = obj( metrics );
		const rows = keys.map( ( key ) => metricRow( key, m[ key ] ) );
		return rows.length ? h( 'ul', { class: [ 'shso-metrics', small ? 'shso-metrics--small' : null ] }, rows ) : null;
	}

	/**
	 * Metric keys that have a measured value, in a stable order.
	 *
	 * @param {Object} metrics Metrics map.
	 * @return {string[]} Keys.
	 */
	function measuredMetricKeys( metrics ) {
		const m = obj( metrics );
		const measured = ( key ) => isObj( m[ key ] ) && ( null !== num( m[ key ].value ) || '' !== str( m[ key ].display ) );
		const keys = METRIC_ORDER.filter( measured );
		Object.keys( m ).forEach( ( key ) => {
			if ( -1 === keys.indexOf( key ) && measured( key ) ) {
				keys.push( key );
			}
		} );
		return keys;
	}

	const CACHE_TONE = { active: 'good', fallback: 'good', external: 'info', inactive: 'unknown', unknown: 'unknown' };
	const CACHE_LABEL = {
		active: __( 'Active', 'sh-speed-optimizer' ),
		fallback: __( 'Active (standard delivery)', 'sh-speed-optimizer' ),
		external: __( 'Handled by another system', 'sh-speed-optimizer' ),
		inactive: __( 'Not active', 'sh-speed-optimizer' ),
		unknown: __( 'Unknown', 'sh-speed-optimizer' ),
	};

	const DB_ITEMS = [
		{ key: 'revisions', label: __( 'Post revisions', 'sh-speed-optimizer' ), def: true },
		{ key: 'auto_drafts', label: __( 'Auto drafts', 'sh-speed-optimizer' ), def: true },
		{ key: 'trashed_posts', label: __( 'Trashed posts', 'sh-speed-optimizer' ), def: false },
		{ key: 'spam_comments', label: __( 'Spam comments', 'sh-speed-optimizer' ), def: true },
		{ key: 'trashed_comments', label: __( 'Trashed comments', 'sh-speed-optimizer' ), def: true },
		{ key: 'expired_transients', label: __( 'Expired temporary data (transients)', 'sh-speed-optimizer' ), def: true },
		{ key: 'orphaned_postmeta', label: __( 'Orphaned post metadata (candidates)', 'sh-speed-optimizer' ), def: false },
		{ key: 'orphaned_commentmeta', label: __( 'Orphaned comment metadata (candidates)', 'sh-speed-optimizer' ), def: false },
		{ key: 'orphaned_termmeta', label: __( 'Orphaned term metadata (candidates)', 'sh-speed-optimizer' ), def: false },
	];

	function dbLabel( key ) {
		const item = DB_ITEMS.find( ( i ) => i.key === key );
		return item ? item.label : String( key );
	}

	const SEVERITY_TONE = { good: 'good', info: 'info', notice: 'attention', warning: 'attention', critical: 'poor' };

	// ------------------------------------------------------------------
	// Job summary (shared by the progress dialog and the Overview)
	// ------------------------------------------------------------------

	function appliedList( applied ) {
		return h( 'ul', { class: 'shso-list' }, applied.map( ( item ) => h(
			'li',
			{},
			mark( 'good', '✓', __( 'Applied:', 'sh-speed-optimizer' ) ),
			h( 'span', { class: 'shso-list__body' }, str( obj( item ).name, str( obj( item ).id ) ) )
		) ) );
	}

	function rolledBackList( rolled ) {
		return h( 'ul', { class: 'shso-list' }, rolled.map( ( item ) => {
			const i = obj( item );
			return h(
				'li',
				{},
				mark( 'attention', '↩', __( 'Rolled back:', 'sh-speed-optimizer' ) ),
				h( 'span', { class: 'shso-list__body' },
					h( 'strong', {}, str( i.name, str( i.id ) ) ),
					i.reason ? h( 'span', { class: 'shso-muted' }, ' — ' + str( i.reason ) ) : null
				)
			);
		} ) );
	}

	function issuesLine( count ) {
		return h( 'p', {},
			/* translators: %d: number of potential issues. */
			sprintf( __( 'Potential issues: %d', 'sh-speed-optimizer' ), count ),
			' ',
			link( __( 'View report', 'sh-speed-optimizer' ), pageUrl( 'diagnostics' ) )
		);
	}

	function healthChangeLine( summary ) {
		const before = num( summary.health_before );
		const after = num( summary.health_after );
		if ( null !== before && null !== after ) {
			/* translators: 1: SH Performance Health before, 2: after (0-100). */
			return h( 'p', { class: 'shso-muted' }, sprintf( __( 'SH Performance Health: %1$d → %2$d', 'sh-speed-optimizer' ), before, after ) );
		}
		if ( null !== after ) {
			/* translators: %d: SH Performance Health (0-100). */
			return h( 'p', { class: 'shso-muted' }, sprintf( __( 'SH Performance Health: %d/100', 'sh-speed-optimizer' ), after ) );
		}
		return null;
	}

	function jobSummary( job ) {
		const j = obj( job );
		const s = obj( j.summary );
		const out = [];
		const applied = arr( s.applied );
		const rolled = arr( s.rolled_back );
		const recommended = arr( s.recommended );

		if ( applied.length ) {
			out.push( h( 'p', {}, __( 'Your site is now using:', 'sh-speed-optimizer' ) ), appliedList( applied ) );
		} else if ( 'optimize' === j.type && 'done' === j.status ) {
			out.push( h( 'p', {}, __( 'No new optimizations were applied.', 'sh-speed-optimizer' ) ) );
		}
		if ( rolled.length ) {
			out.push( h( 'p', {}, __( 'Rolled back to keep your site working:', 'sh-speed-optimizer' ) ), rolledBackList( rolled ) );
		}
		if ( recommended.length ) {
			out.push(
				h( 'p', {}, __( 'Recommended, but not applied automatically:', 'sh-speed-optimizer' ) ),
				h( 'ul', { class: 'shso-plain-list' }, recommended.map( ( item ) => {
					const i = obj( item );
					return h( 'li', {}, h( 'strong', {}, str( i.name, str( i.id ) ) ), i.summary ? ' — ' + str( i.summary ) : null );
				} ) )
			);
		}
		const ready = num( s.ready );
		if ( 'scan' === j.type && null !== ready ) {
			out.push( h( 'p', {}, ready > 0
				/* translators: %d: number of optimizations. */
				? sprintf( _n( '%d safe optimization is ready to apply.', '%d safe optimizations are ready to apply.', ready, 'sh-speed-optimizer' ), ready )
				: __( 'No additional safe optimizations are needed right now.', 'sh-speed-optimizer' ) ) );
		}
		if ( 'db_clean' === j.type && isObj( s.items ) && Object.keys( s.items ).length ) {
			out.push(
				h( 'p', {}, __( 'Removed from the database:', 'sh-speed-optimizer' ) ),
				h( 'ul', { class: 'shso-plain-list' }, Object.keys( s.items ).map( ( key ) => {
					const deleted = num( obj( s.items[ key ] ).deleted );
					return h( 'li', {}, dbLabel( key ) + ': ' + fmtNumber( null === deleted ? 0 : deleted ) );
				} ) )
			);
			const freed = num( s.bytes_freed_estimate );
			if ( null !== freed && freed > 0 ) {
				out.push( h( 'p', { class: 'shso-muted' }, 'table_sizes' === s.bytes_freed_source
					/* translators: %s: size, e.g. 2.4 MB. */
					? sprintf( __( 'Database size reduced by %s (measured from the table sizes).', 'sh-speed-optimizer' ), fmtBytes( freed ) )
					/* translators: %s: size, e.g. 2.4 MB. */
					: sprintf( __( 'Size of the removed data: %s.', 'sh-speed-optimizer' ), fmtBytes( freed ) ) ) );
			}
			const backupRows = num( s.backup_rows );
			if ( backupRows ) {
				/* translators: %d: number of database rows. */
				out.push( h( 'p', { class: 'shso-muted' }, sprintf( _n( 'A backup of %d row was saved first. You can restore it below.', 'A backup of %d rows was saved first. You can restore it below.', backupRows, 'sh-speed-optimizer' ), backupRows ) ) );
			}
		}
		if ( isObj( s.cleaned ) && Object.keys( s.cleaned ).length ) {
			out.push(
				h( 'p', {}, __( 'Removed from the database:', 'sh-speed-optimizer' ) ),
				h( 'ul', { class: 'shso-plain-list' }, Object.keys( s.cleaned ).map( ( key ) => {
					const value = s.cleaned[ key ];
					const count = num( isObj( value ) ? first( value.count, value.rows ) : value );
					return h( 'li', {}, dbLabel( key ) + ': ' + ( null === count ? str( value, '—' ) : fmtNumber( count ) ) );
				} ) )
			);
		}
		const health = healthChangeLine( s );
		if ( health ) {
			out.push( health );
		}
		const issues = num( s.issues );
		if ( null !== issues && 'db_clean' !== j.type ) {
			out.push( issuesLine( issues ) );
		}
		return out;
	}

	// ------------------------------------------------------------------
	// Job progress dialog
	// ------------------------------------------------------------------

	const JOB_TITLES = {
		scan: __( 'Analyzing your site', 'sh-speed-optimizer' ),
		optimize: __( 'Optimizing your site', 'sh-speed-optimizer' ),
		verify: __( 'Checking your site', 'sh-speed-optimizer' ),
		db_clean: __( 'Cleaning the database', 'sh-speed-optimizer' ),
	};

	const JOB_DONE_TITLES = {
		scan: __( 'Analysis complete', 'sh-speed-optimizer' ),
		optimize: __( 'Optimization Complete', 'sh-speed-optimizer' ),
		verify: __( 'Check complete', 'sh-speed-optimizer' ),
		db_clean: __( 'Database cleanup complete', 'sh-speed-optimizer' ),
	};

	const MESSAGE_MARKS = {
		success: [ 'good', '✓' ],
		warning: [ 'attention', '!' ],
		error: [ 'poor', '!' ],
		info: [ 'info', '•' ],
	};

	const CANCELLED = { cancelled: true };

	const Jobs = {
		running: false,
		starting: false,
		els: null,
		onFinish: null,
		cancelPromise: null,
		signalCancel: null,
		cancelSignal: null,
		seen: null,
		lastFocus: null,

		isActive( job ) {
			return isObj( job ) && -1 !== ACTIVE.indexOf( job.status );
		},

		/**
		 * Start a job and follow it until it ends.
		 *
		 * @param {string}   type     scan|optimize|db_clean.
		 * @param {Object}   args     Job arguments.
		 * @param {Function} onFinish Called with the final job.
		 * @return {Promise} Resolves with the final job (or null).
		 */
		start( type, args, onFinish ) {
			if ( this.running || this.starting ) {
				this.show();
				return Promise.resolve( null );
			}
			this.starting = true;
			return api( '/jobs', 'POST', { type, args: args || {} } ).then( ( res ) => {
				this.starting = false;
				return this.run( obj( res ).job, onFinish );
			}, ( err ) => api( '/jobs/current' ).then( ( res ) => {
				// Most likely another job is running (409): follow that one instead.
				this.starting = false;
				const current = obj( res ).job;
				if ( this.isActive( current ) ) {
					notify( 'info', errorMessage( err ) );
					return this.run( current, onFinish );
				}
				notify( 'error', errorMessage( err ) );
				return null;
			}, () => {
				this.starting = false;
				notify( 'error', errorMessage( err ) );
				return null;
			} ) );
		},

		/**
		 * Resume the progress dialog when a job is already running.
		 *
		 * @param {Function} onFinish Callback.
		 * @return {Promise} Promise.
		 */
		resumeIfActive( onFinish ) {
			return api( '/jobs/current' ).then( ( res ) => {
				const job = obj( res ).job;
				if ( this.isActive( job ) ) {
					return this.run( job, onFinish );
				}
				return null;
			}, () => null );
		},

		async run( job, onFinish ) {
			if ( ! isObj( job ) || ! job.id ) {
				notify( 'error', __( 'The task could not be started.', 'sh-speed-optimizer' ) );
				return null;
			}
			if ( this.running ) {
				return null;
			}
			this.running = true;
			this.onFinish = onFinish || null;
			this.cancelPromise = null;
			this.resetCancelSignal();
			this.open( job );

			let current = job;
			let failures = 0;
			let ended = false;

			try {
				for ( ;; ) {
					this.update( current );
					if ( -1 !== TERMINAL.indexOf( current.status ) ) {
						break;
					}

					if ( this.cancelPromise ) {
						const cancelled = await this.cancelPromise;
						this.cancelPromise = null;
						if ( isObj( cancelled ) ) {
							current = cancelled;
						}
						if ( -1 === TERMINAL.indexOf( current.status ) ) {
							// Not cancelled (yet): keep following the job and allow another attempt.
							this.resetCancelSignal();
							if ( this.els ) {
								this.els.cancel.disabled = false;
							}
						}
						continue;
					}

					let res;
					try {
						if ( 'awaiting_browser' === current.status ) {
							const results = await this.runBrowser( current );
							if ( CANCELLED === results ) {
								continue;
							}
							res = await api( '/jobs/current/browser', 'POST', { job_id: current.id, results } );
						} else {
							await sleep( STEP_PAUSE );
							if ( this.cancelPromise ) {
								continue;
							}
							res = await api( '/jobs/current/step', 'POST' );
						}
						failures = 0;
					} catch ( err ) {
						failures++;
						if ( failures >= 4 ) {
							this.connectionLost( err );
							return null;
						}
						/* translators: %d: attempt number. */
						this.setStep( sprintf( __( 'Connection problem, trying again… (%d)', 'sh-speed-optimizer' ), failures ) );
						await sleep( 1000 * failures * failures );
						continue;
					}

					const next = obj( res ).job;
					if ( ! isObj( next ) ) {
						ended = true;
						break;
					}
					current = next;
				}
			} finally {
				this.running = false;
			}

			this.finish( current, ended );
			return current;
		},

		resetCancelSignal() {
			this.cancelSignal = new Promise( ( resolve ) => {
				this.signalCancel = resolve;
			} );
		},

		async runBrowser( job ) {
			const plan = arr( obj( job.browser ).plan );
			const harness = window.SHSOHarness;
			if ( ! harness || 'function' !== typeof harness.run ) {
				return { _unavailable: true };
			}

			this.showBrowser( true );
			this.browserProgress( 0, plan.length );

			let budget = 30000;
			plan.forEach( ( item ) => {
				budget += Math.max( 5000, num( obj( item ).timeout_ms ) || 20000 );
			} );
			budget = Math.min( budget * 1.5, 15 * 60 * 1000 );

			let timer = null;
			const timeout = new Promise( ( resolve ) => {
				timer = setTimeout( () => resolve( { _unavailable: true } ), budget );
			} );

			let work;
			try {
				work = Promise.resolve( harness.run( plan, {
					container: this.els.harness,
					onProgress: ( done, total ) => this.browserProgress( done, total ),
				} ) ).then(
					( results ) => ( isObj( results ) ? results : { _unavailable: true } ),
					() => ( { _unavailable: true } )
				);
			} catch ( e ) {
				work = Promise.resolve( { _unavailable: true } );
			}

			const result = await Promise.race( [ work, timeout, this.cancelSignal.then( () => CANCELLED ) ] );
			clearTimeout( timer );
			this.showBrowser( false );
			return result;
		},

		cancel() {
			if ( ! this.running || this.cancelPromise ) {
				return;
			}
			this.els.cancel.disabled = true;
			this.setStep( __( 'Cancelling…', 'sh-speed-optimizer' ) );
			this.cancelPromise = api( '/jobs/current/cancel', 'POST' ).then(
				( res ) => obj( res ).job || null,
				( err ) => {
					notify( 'error', errorMessage( err ) );
					return null;
				}
			);
			try {
				if ( window.SHSOHarness && 'function' === typeof window.SHSOHarness.abort ) {
					window.SHSOHarness.abort();
				}
			} catch ( e ) {
				// Ignore: the harness result is discarded anyway.
			}
			if ( this.signalCancel ) {
				this.signalCancel();
			}
		},

		build() {
			if ( this.els ) {
				return;
			}
			const titleId = 'shso-job-title';
			const els = {};
			els.title = h( 'h2', { class: 'shso-modal__title', id: titleId, tabindex: '-1' } );
			els.bar = h( 'div', { class: 'shso-progress__bar' } );
			els.progress = h( 'div', {
				class: 'shso-progress',
				role: 'progressbar',
				'aria-valuemin': '0',
				'aria-valuemax': '100',
				'aria-valuenow': '0',
				'aria-labelledby': titleId,
			}, els.bar );
			els.step = h( 'span', { class: 'shso-modal__steptext' } );
			els.percent = h( 'span', { class: 'shso-modal__percent', 'aria-hidden': 'true' } );
			els.stepRow = h( 'p', { class: 'shso-modal__step', 'aria-live': 'polite' }, els.step, els.percent );
			els.browserText = h( 'p', {}, __( 'Testing your pages in this browser…', 'sh-speed-optimizer' ) );
			els.harness = h( 'div', { class: 'shso-harness', 'aria-hidden': 'true' } );
			els.browser = h( 'div', { class: 'shso-browser', hidden: true }, els.browserText, els.harness );
			els.messages = h( 'ul', { class: 'shso-messages', role: 'log', 'aria-live': 'polite', 'aria-label': __( 'Progress messages', 'sh-speed-optimizer' ) } );
			els.summary = h( 'div', { class: 'shso-modal__summary', hidden: true } );
			els.note = h( 'p', { class: 'shso-modal__note' }, __( 'You can leave this page; the rest continues in the background (browser tests will be skipped).', 'sh-speed-optimizer' ) );
			els.cancel = button( __( 'Cancel', 'sh-speed-optimizer' ), () => this.cancel() );
			els.retry = button( __( 'Try again', 'sh-speed-optimizer' ), () => this.retry(), { className: 'shso-retry' } );
			els.retry.hidden = true;
			els.close = button( __( 'Close', 'sh-speed-optimizer' ), () => this.close(), { primary: true } );
			els.close.hidden = true;
			els.dialog = h(
				'div',
				{ class: 'shso-modal__dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId },
				els.title,
				els.progress,
				els.stepRow,
				els.browser,
				els.messages,
				els.summary,
				els.note,
				h( 'div', { class: 'shso-modal__actions' }, els.cancel, els.retry, els.close )
			);
			els.root = h( 'div', { class: 'shso-modal', id: 'shso-job-modal', hidden: true }, els.dialog );
			els.root.addEventListener( 'keydown', ( e ) => this.onKey( e ) );
			document.body.appendChild( els.root );
			this.els = els;
		},

		open( job ) {
			this.build();
			const els = this.els;
			this.seen = new Set();
			els.title.textContent = JOB_TITLES[ job.type ] || __( 'Working…', 'sh-speed-optimizer' );
			clear( els.messages );
			clear( els.summary );
			els.summary.hidden = true;
			els.progress.classList.remove( 'is-done', 'is-failed' );
			els.cancel.hidden = false;
			els.cancel.disabled = false;
			els.retry.hidden = true;
			els.close.hidden = true;
			els.note.hidden = false;
			els.browser.hidden = true;

			if ( els.root.hidden ) {
				this.lastFocus = document.activeElement;
				els.root.hidden = false;
				document.body.classList.add( 'shso-modal-open' );
				this.setBackgroundInert( true );
			}
			els.title.focus();
		},

		show() {
			if ( this.els && this.els.root.hidden ) {
				this.els.root.hidden = false;
				this.setBackgroundInert( true );
				this.els.title.focus();
			}
		},

		setBackgroundInert( on ) {
			const wrap = document.getElementById( 'wpwrap' );
			if ( wrap ) {
				wrap.inert = !! on;
				if ( on ) {
					wrap.setAttribute( 'aria-hidden', 'true' );
				} else {
					wrap.removeAttribute( 'aria-hidden' );
				}
			}
		},

		close() {
			if ( ! this.els || this.running ) {
				return;
			}
			this.els.root.hidden = true;
			document.body.classList.remove( 'shso-modal-open' );
			this.setBackgroundInert( false );
			clear( this.els.harness );
			const target = this.lastFocus && document.body.contains( this.lastFocus ) ? this.lastFocus : $( '.shso-header__title' );
			if ( target && 'function' === typeof target.focus ) {
				if ( ! target.hasAttribute( 'tabindex' ) && ! /^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test( target.tagName ) ) {
					target.setAttribute( 'tabindex', '-1' );
				}
				target.focus();
			}
		},

		setStep( text ) {
			if ( this.els ) {
				this.els.step.textContent = text;
			}
		},

		update( job ) {
			const els = this.els;
			if ( ! els ) {
				return;
			}
			const progress = Math.max( 0, Math.min( 100, Math.round( num( job.progress ) || 0 ) ) );
			els.bar.style.width = progress + '%';
			els.progress.setAttribute( 'aria-valuenow', String( progress ) );
			els.percent.textContent = progress + '%';

			let label = str( job.label );
			if ( ! label ) {
				label = 'queued' === job.status
					? __( 'Getting ready…', 'sh-speed-optimizer' )
					: 'awaiting_browser' === job.status
						? __( 'Testing your pages in this browser…', 'sh-speed-optimizer' )
						: __( 'Working…', 'sh-speed-optimizer' );
			}
			if ( ! this.cancelPromise ) {
				els.step.textContent = label;
			}
			if ( JOB_TITLES[ job.type ] && -1 === TERMINAL.indexOf( job.status ) ) {
				els.title.textContent = JOB_TITLES[ job.type ];
			}

			arr( job.messages ).forEach( ( message ) => {
				const m = obj( message );
				const text = str( m.text );
				const key = str( m.time ) + '|' + text;
				if ( ! text || this.seen.has( key ) ) {
					return;
				}
				this.seen.add( key );
				const look = MESSAGE_MARKS[ m.type ] || MESSAGE_MARKS.info;
				els.messages.appendChild( h( 'li', {}, mark( look[ 0 ], look[ 1 ] ), h( 'span', {}, text ) ) );
			} );
			els.messages.scrollTop = els.messages.scrollHeight;
		},

		showBrowser( on ) {
			if ( ! this.els ) {
				return;
			}
			this.els.browser.hidden = ! on;
			if ( ! on ) {
				clear( this.els.harness );
			}
		},

		browserProgress( done, total ) {
			if ( ! this.els ) {
				return;
			}
			const d = num( done );
			const t = num( total );
			let text = __( 'Testing your pages in this browser…', 'sh-speed-optimizer' );
			if ( null !== d && t ) {
				/* translators: 1: pages tested so far, 2: total pages. */
				text += ' ' + sprintf( __( '%1$d of %2$d', 'sh-speed-optimizer' ), d, t );
			}
			this.els.browserText.textContent = text;
		},

		finish( job, ended ) {
			const els = this.els;
			if ( ! els ) {
				return;
			}
			const status = job.status;
			const type = job.type;

			els.cancel.hidden = true;
			els.retry.hidden = true;
			els.close.hidden = false;
			els.note.hidden = true;
			els.browser.hidden = true;

			if ( ended && -1 === TERMINAL.indexOf( status ) ) {
				els.title.textContent = __( 'Finished', 'sh-speed-optimizer' );
				els.step.textContent = __( 'The task has ended. The page now shows the latest results.', 'sh-speed-optimizer' );
			} else if ( 'done' === status ) {
				els.title.textContent = JOB_DONE_TITLES[ type ] || __( 'Finished', 'sh-speed-optimizer' );
				els.progress.classList.add( 'is-done' );
				els.bar.style.width = '100%';
				els.progress.setAttribute( 'aria-valuenow', '100' );
				els.percent.textContent = '100%';
				els.step.textContent = str( job.label, __( 'Done.', 'sh-speed-optimizer' ) );
			} else if ( 'failed' === status ) {
				els.title.textContent = __( 'The task could not be completed', 'sh-speed-optimizer' );
				els.progress.classList.add( 'is-failed' );
				els.step.textContent = str( job.error, __( 'An error stopped the task. Details are in the messages below.', 'sh-speed-optimizer' ) );
			} else if ( 'cancelled' === status ) {
				els.title.textContent = __( 'Cancelled', 'sh-speed-optimizer' );
				els.step.textContent = __( 'The task was cancelled.', 'sh-speed-optimizer' );
			}

			const summary = jobSummary( job );
			if ( summary.length && 'done' === status ) {
				fill( els.summary, summary );
				els.summary.hidden = false;
			}

			announce( els.title.textContent + '. ' + els.step.textContent );
			els.close.focus();

			if ( 'function' === typeof this.onFinish ) {
				try {
					this.onFinish( job );
				} catch ( e ) {
					if ( window.console ) {
						window.console.error( e ); // eslint-disable-line no-console
					}
				}
			}
		},

		connectionLost( err ) {
			this.running = false;
			const els = this.els;
			els.title.textContent = __( 'Connection lost', 'sh-speed-optimizer' );
			els.step.textContent = __( 'We could not reach your site. The task continues in the background.', 'sh-speed-optimizer' ) + ' ' + errorMessage( err );
			els.browser.hidden = true;
			els.cancel.hidden = true;
			els.retry.hidden = false;
			els.close.hidden = false;
			els.retry.focus();
		},

		retry() {
			const onFinish = this.onFinish;
			api( '/jobs/current' ).then( ( res ) => {
				const job = obj( res ).job;
				if ( this.isActive( job ) ) {
					this.run( job, onFinish );
				} else {
					this.close();
					if ( 'function' === typeof onFinish ) {
						onFinish( job || {} );
					}
				}
			}, ( err ) => {
				this.els.step.textContent = errorMessage( err );
			} );
		},

		onKey( e ) {
			if ( 'Escape' === e.key ) {
				if ( ! this.running ) {
					e.preventDefault();
					this.close();
				}
				return;
			}
			if ( 'Tab' !== e.key ) {
				return;
			}
			const focusable = Array.prototype.filter.call(
				this.els.dialog.querySelectorAll( 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])' ),
				( el ) => ! el.disabled && ! el.hidden && null !== el.offsetParent
			);
			if ( ! focusable.length ) {
				e.preventDefault();
				return;
			}
			const firstEl = focusable[ 0 ];
			const lastEl = focusable[ focusable.length - 1 ];
			if ( e.shiftKey && ( document.activeElement === firstEl || document.activeElement === this.els.title ) ) {
				e.preventDefault();
				lastEl.focus();
			} else if ( ! e.shiftKey && document.activeElement === lastEl ) {
				e.preventDefault();
				firstEl.focus();
			}
		},
	};

	// ------------------------------------------------------------------
	// Generic value rendering (developer diagnostics, details)
	// ------------------------------------------------------------------

	function preBlock( value ) {
		return h( 'pre', { class: 'shso-pre' }, 'string' === typeof value ? value : safeJson( value ) );
	}

	function scalarText( value ) {
		if ( 'boolean' === typeof value ) {
			return value ? __( 'Yes', 'sh-speed-optimizer' ) : __( 'No', 'sh-speed-optimizer' );
		}
		return String( value );
	}

	function valueNode( value, depth ) {
		const d = depth || 0;
		if ( null === value || undefined === value || '' === value ) {
			return h( 'span', { class: 'shso-muted' }, '—' );
		}
		if ( 'object' !== typeof value ) {
			return document.createTextNode( scalarText( value ) );
		}
		if ( Array.isArray( value ) ) {
			if ( ! value.length ) {
				return h( 'span', { class: 'shso-muted' }, __( 'None', 'sh-speed-optimizer' ) );
			}
			if ( value.every( ( v ) => null === v || 'object' !== typeof v ) ) {
				return document.createTextNode( value.map( ( v ) => ( null === v ? '—' : scalarText( v ) ) ).join( ', ' ) );
			}
			if ( d >= 2 ) {
				return preBlock( value );
			}
			if ( value.every( isObj ) ) {
				return rowsTable( value );
			}
			return preBlock( value );
		}
		if ( ! Object.keys( value ).length ) {
			return h( 'span', { class: 'shso-muted' }, __( 'None', 'sh-speed-optimizer' ) );
		}
		if ( d >= 2 ) {
			return preBlock( value );
		}
		return kvTable( value, d + 1 );
	}

	function kvTable( value, depth ) {
		const o = obj( value );
		const keys = Object.keys( o );
		if ( ! keys.length ) {
			return h( 'p', { class: 'shso-empty' }, __( 'No data.', 'sh-speed-optimizer' ) );
		}
		return h( 'div', { class: 'shso-table-wrap' }, h( 'table', { class: 'shso-table' }, h( 'tbody', {}, keys.map( ( key ) => h(
			'tr',
			{},
			h( 'th', { scope: 'row' }, key ),
			h( 'td', {}, valueNode( o[ key ], depth || 0 ) )
		) ) ) ) );
	}

	function cellText( value ) {
		if ( null === value || undefined === value || '' === value ) {
			return '—';
		}
		if ( 'object' === typeof value ) {
			if ( Array.isArray( value ) && value.every( ( v ) => 'object' !== typeof v || null === v ) ) {
				return value.join( ', ' );
			}
			return safeJson( value );
		}
		return scalarText( value );
	}

	function rowsTable( rows, columns, labels ) {
		const list = arr( rows ).filter( isObj );
		if ( ! list.length ) {
			return h( 'p', { class: 'shso-empty' }, __( 'No data.', 'sh-speed-optimizer' ) );
		}
		let cols = columns;
		if ( ! cols ) {
			cols = [];
			list.forEach( ( row ) => Object.keys( row ).forEach( ( key ) => {
				if ( -1 === cols.indexOf( key ) && cols.length < 10 ) {
					cols.push( key );
				}
			} ) );
		}
		const names = labels || {};
		return h( 'div', { class: 'shso-table-wrap' }, h( 'table', { class: 'shso-table' },
			h( 'thead', {}, h( 'tr', {}, cols.map( ( col ) => h( 'th', { scope: 'col' }, names[ col ] || col ) ) ) ),
			h( 'tbody', {}, list.map( ( row ) => h( 'tr', {}, cols.map( ( col ) => {
				const v = row[ col ];
				const isNum = 'number' === typeof v;
				return h( 'td', { class: isNum ? 'is-num' : null }, isObj( v ) || Array.isArray( v ) ? h( 'span', { class: 'shso-code' }, cellText( v ) ) : cellText( v ) );
			} ) ) ) )
		) );
	}

	function downloadJson( data, filename ) {
		try {
			const blob = new Blob( [ safeJson( data ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );
			const a = h( 'a', { href: url, download: filename, hidden: true } );
			document.body.appendChild( a );
			a.click();
			a.remove();
			setTimeout( () => URL.revokeObjectURL( url ), 2000 );
		} catch ( e ) {
			notify( 'error', __( 'The file could not be created in this browser.', 'sh-speed-optimizer' ) );
		}
	}

	function copyText( text, btn ) {
		const done = () => {
			announce( __( 'Copied to the clipboard.', 'sh-speed-optimizer' ) );
			if ( btn ) {
				const label = btn.textContent;
				btn.textContent = __( 'Copied', 'sh-speed-optimizer' );
				setTimeout( () => {
					btn.textContent = label;
				}, 2000 );
			}
		};
		const fallback = () => {
			try {
				const area = h( 'textarea', { class: 'screen-reader-text', readOnly: true } );
				area.value = text;
				document.body.appendChild( area );
				area.select();
				const ok = document.execCommand( 'copy' );
				area.remove();
				if ( ok ) {
					done();
					return;
				}
			} catch ( e ) {
				// Fall through to the notice below.
			}
			notify( 'warning', __( 'Copying is not available in this browser. Please select the text and copy it manually.', 'sh-speed-optimizer' ) );
		};
		try {
			if ( navigator.clipboard && 'function' === typeof navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, fallback );
				return;
			}
		} catch ( e ) {
			// Use the fallback.
		}
		fallback();
	}

	// ------------------------------------------------------------------
	// Overview
	// ------------------------------------------------------------------

	const Overview = {
		root: null,
		status: null,
		lastJob: null,

		init() {
			this.root = document.getElementById( 'shso-app' );
			this.load( true );
		},

		load( firstLoad ) {
			return api( '/status' ).then( ( res ) => {
				this.status = obj( res );
				const job = obj( this.status.job );
				if ( firstLoad && 'optimize' === job.type && 'done' === job.status && isObj( job.summary ) ) {
					this.lastJob = job; // Finished moments ago (the API only returns recent jobs).
				}
				this.render();
				if ( firstLoad && Jobs.isActive( this.status.job ) ) {
					Jobs.run( this.status.job, ( finished ) => this.onJobDone( finished ) );
				}
			}, ( err ) => {
				fill( this.root, loadError( err, () => this.load( firstLoad ) ) );
			} );
		},

		onJobDone( job ) {
			const j = obj( job );
			if ( 'optimize' === j.type && 'done' === j.status ) {
				this.lastJob = j;
			}
			return this.load( false );
		},

		startScan() {
			return Jobs.start( 'scan', { browser: true }, ( job ) => this.onJobDone( job ) );
		},

		startOptimize() {
			return Jobs.start( 'optimize', { browser: true }, ( job ) => this.onJobDone( job ) );
		},

		render() {
			renderInto( this.root, () => {
				const s = this.status;
				const out = [];
				const shown = new Set();
				const serverBanner = document.getElementById( 'shso-emergency-banner' );
				const emergencyText = __( 'Emergency Safe Mode is active (SHSO_SAFE_MODE in wp-config.php). All optimizations are bypassed.', 'sh-speed-optimizer' );
				if ( serverBanner ) {
					shown.add( serverBanner.textContent.trim() );
					shown.add( emergencyText );
				}

				if ( s.emergency_safe_mode && ! serverBanner ) {
					shown.add( emergencyText );
					out.push( banner( 'poor', emergencyText, null, { role: 'alert' } ) );
				}
				if ( s.safe_mode && ! s.emergency_safe_mode ) {
					out.push( banner( 'attention', __( 'Safe Mode is active. Only the safest optimizations are running.', 'sh-speed-optimizer' ), [
						button( __( 'Turn off Safe Mode', 'sh-speed-optimizer' ), ( btn ) => this.turnOffSafeMode( btn ) ),
					] ) );
				}
				arr( s.notices ).forEach( ( notice ) => {
					const n = obj( notice );
					const text = str( n.text );
					if ( text && ! shown.has( text.trim() ) ) {
						shown.add( text.trim() );
						out.push( banner( noticeTone( n.type ), text ) );
					}
				} );
				const conflicts = arr( s.conflicts ).map( ( c ) => str( obj( c ).name ) ).filter( Boolean );
				if ( conflicts.length ) {
					/* translators: %s: comma separated plugin names. */
					out.push( banner( 'info', sprintf( __( 'Another optimization system is active: %s. SH Speed Optimizer adapts and skips overlapping features.', 'sh-speed-optimizer' ), conflicts.join( ', ' ) ) ) );
				}

				if ( this.lastJob ) {
					out.push( this.completeCard( this.lastJob ) );
				}

				if ( 'new' === s.onboarding && ! this.lastJob ) {
					out.push( this.welcomeCard() );
					return out;
				}

				const scanned = 'scanned' === s.onboarding && ! this.lastJob;
				if ( scanned ) {
					out.push( this.readyCard() );
				}
				out.push( this.hero( ! scanned ) );
				out.push( h( 'div', { class: 'shso-grid' },
					this.healthCard(),
					this.cwvCard(),
					this.optimizationCard(),
					this.cacheCard(),
					this.scanCard()
				) );
				return out;
			} );
		},

		turnOffSafeMode( btn ) {
			busy( btn, true );
			api( '/safe-mode', 'POST', { enabled: false } ).then( () => {
				notify( 'success', __( 'Safe Mode is off.', 'sh-speed-optimizer' ) );
				return this.load( false );
			}, ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		welcomeCard() {
			return h(
				'section',
				{ class: 'shso-card shso-card--feature', 'aria-labelledby': 'shso-welcome-title' },
				h( 'h2', { id: 'shso-welcome-title' }, __( 'Welcome to SH Speed Optimizer', 'sh-speed-optimizer' ) ),
				h( 'p', {}, __( 'Let\'s analyze your website and safely optimize it.', 'sh-speed-optimizer' ) ),
				h( 'p', { class: 'shso-muted' }, __( 'The analysis only looks at your pages. Nothing on your site is changed until you decide.', 'sh-speed-optimizer' ) ),
				h( 'div', { class: 'shso-row' }, button( __( 'Analyze My Site', 'sh-speed-optimizer' ), () => this.startScan(), { primary: true, large: true, id: 'shso-analyze' } ) )
			);
		},

		readyCard() {
			const s = this.status;
			const ready = obj( s.ready );
			const count = num( ready.count ) || 0;
			const items = arr( ready.items );
			const perms = obj( s.permissions );
			let checkbox = null;

			const children = [
				h( 'h2', { id: 'shso-ready-title' }, count > 0
					/* translators: %d: number of optimizations. */
					? sprintf( _n( 'Your website is ready for %d safe optimization.', 'Your website is ready for %d safe optimizations.', count, 'sh-speed-optimizer' ), count )
					: __( 'Your website does not need any additional safe optimizations right now.', 'sh-speed-optimizer' ) ),
			];

			if ( items.length ) {
				children.push( h( 'ul', { class: 'shso-plain-list' }, items.map( ( item ) => h( 'li', {}, str( obj( item ).name, str( obj( item ).id ) ) ) ) ) );
			}

			if ( count > 0 ) {
				if ( perms.server_config_available && ! perms.server_config_allowed ) {
					checkbox = h( 'input', { type: 'checkbox', id: 'shso-allow-server-config' } );
					children.push( h( 'label', { class: 'shso-row', for: 'shso-allow-server-config' }, checkbox,
						h( 'span', {}, __( 'Also let SH Speed add browser caching rules to your server configuration (.htaccess). You can undo this anytime.', 'sh-speed-optimizer' ) ) ) );
				}
				children.push( h( 'div', { class: 'shso-row' },
					button( __( 'Apply Safe Optimizations', 'sh-speed-optimizer' ), ( btn ) => this.applySafe( btn, checkbox ), { primary: true, large: true, id: 'shso-apply' } )
				) );
			}

			return h( 'section', { class: 'shso-card shso-card--feature', 'aria-labelledby': 'shso-ready-title' }, children );
		},

		applySafe( btn, checkbox ) {
			busy( btn, true );
			const before = checkbox && checkbox.checked
				? api( '/settings', 'POST', { settings: { allow_server_config: true } } )
				: Promise.resolve();
			before.then( () => {
				busy( btn, false );
				return this.startOptimize();
			}, ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		completeCard( job ) {
			const s = obj( job.summary );
			const applied = arr( s.applied );
			const rolled = arr( s.rolled_back );
			const issues = num( s.issues );
			const cardEl = h(
				'section',
				{ class: 'shso-card shso-card--feature', 'aria-labelledby': 'shso-complete-title' },
				h( 'div', { class: 'shso-card__head' },
					h( 'h2', { id: 'shso-complete-title' }, __( 'Optimization Complete', 'sh-speed-optimizer' ) ),
					h( 'button', {
						type: 'button',
						class: 'shso-banner__dismiss',
						'aria-label': __( 'Dismiss', 'sh-speed-optimizer' ),
						onclick: () => {
							this.lastJob = null;
							this.render();
						},
					}, '×' )
				),
				applied.length
					? [ h( 'p', {}, __( 'Your site is now using:', 'sh-speed-optimizer' ) ), appliedList( applied ) ]
					: h( 'p', {}, __( 'No new optimizations were applied.', 'sh-speed-optimizer' ) ),
				rolled.length ? [ h( 'p', {}, __( 'Rolled back to keep your site working:', 'sh-speed-optimizer' ) ), rolledBackList( rolled ) ] : null,
				healthChangeLine( s ),
				null !== issues ? issuesLine( issues ) : null
			);
			return cardEl;
		},

		hero( showPrimary ) {
			const info = healthInfo( this.status.health );
			return h(
				'section',
				{ class: 'shso-hero shso-hero--' + info.tone, 'aria-labelledby': 'shso-status-label' },
				h( 'div', {},
					h( 'p', { class: 'shso-hero__label', id: 'shso-status-label' }, __( 'Performance Status', 'sh-speed-optimizer' ) ),
					h( 'p', { class: 'shso-hero__status' }, dot( info.tone ), info.label )
				),
				showPrimary ? h( 'div', { class: 'shso-hero__actions' },
					button( __( 'Optimize My Site', 'sh-speed-optimizer' ), () => this.startOptimize(), { primary: true, large: true, id: 'shso-optimize' } )
				) : null
			);
		},

		healthCard() {
			const health = obj( this.status.health );
			const score = num( health.score );
			const info = healthInfo( health );
			const improvements = num( health.improvements );
			return card( __( 'Performance Health', 'sh-speed-optimizer' ), [
				null !== score
					? [
						h( 'div', { class: 'shso-score shso-score--' + info.tone, 'aria-hidden': 'true' },
							h( 'span', { class: 'shso-score__value' }, fmtNumber( score ) ),
							h( 'span', { class: 'shso-score__max' }, '/100' )
						),
						/* translators: %d: score 0-100. */
						srText( sprintf( __( 'SH Performance Health: %d out of 100', 'sh-speed-optimizer' ), score ) ),
						h( 'div', { class: 'shso-meter shso-meter--' + info.tone, 'aria-hidden': 'true' },
							h( 'div', { class: 'shso-meter__bar', style: 'width:' + Math.max( 0, Math.min( 100, score ) ) + '%' } ) ),
					]
					: h( 'div', { class: 'shso-score shso-score--unknown' }, h( 'span', { class: 'shso-score__value' }, __( 'Not measured yet', 'sh-speed-optimizer' ) ) ),
				h( 'p', {}, h( 'strong', {}, __( 'SH Performance Health', 'sh-speed-optimizer' ) ), h( 'br' ),
					h( 'span', { class: 'shso-muted shso-small' }, __( 'Based on SH Speed Optimizer\'s own checks of your pages.', 'sh-speed-optimizer' ) ) ),
				/* translators: %d: number of potential improvements. */
				null !== improvements ? h( 'p', {}, sprintf( __( 'Potential improvements: %d', 'sh-speed-optimizer' ), improvements ) ) : null,
				cardFooter( link( __( 'View report', 'sh-speed-optimizer' ), pageUrl( 'diagnostics' ) ) ),
			], { id: 'shso-card-health' } );
		},

		cwvCard() {
			const cwv = obj( this.status.cwv );
			const body = [];
			if ( cwv.field_available ) {
				const m = obj( cwv.metrics );
				body.push( metricList( m, [ 'lcp', 'inp', 'cls' ] ) );
				const secondary = [ 'ttfb', 'fcp' ].filter( ( key ) => isObj( m[ key ] ) );
				if ( secondary.length ) {
					body.push( metricList( m, secondary, true ) );
				}
				/* translators: %s: where the measurements come from. */
				body.push( h( 'p', { class: 'shso-source' }, sprintf( __( 'Source: %s', 'sh-speed-optimizer' ), str( cwv.source_label, __( 'Unknown', 'sh-speed-optimizer' ) ) ) ) );
			} else {
				body.push( h( 'p', {}, h( 'strong', {}, __( 'Field data unavailable', 'sh-speed-optimizer' ) ) ) );
				body.push( h( 'p', { class: 'shso-muted shso-small' },
					__( 'Measurements from real visitors are not available yet.', 'sh-speed-optimizer' ), ' ',
					link( __( 'Measurement settings', 'sh-speed-optimizer' ), pageUrl( 'settings', '#shso-section-measurement' ) )
				) );
				const lab = cwv.lab;
				if ( isObj( lab ) ) {
					const keys = measuredMetricKeys( lab.metrics );
					body.push( h( 'p', { class: 'shso-subhead' }, __( 'Lab measurements', 'sh-speed-optimizer' ) ) );
					body.push( keys.length ? metricList( lab.metrics, keys, true ) : h( 'p', { class: 'shso-empty' }, __( 'Not measured yet', 'sh-speed-optimizer' ) ) );
					/* translators: %s: where the measurements come from. */
					body.push( h( 'p', { class: 'shso-source' }, sprintf( __( 'Source: %s', 'sh-speed-optimizer' ), str( lab.source_label, __( 'Unknown', 'sh-speed-optimizer' ) ) ) ) );
				}
			}
			return card( __( 'Core Web Vitals', 'sh-speed-optimizer' ), body, { id: 'shso-card-cwv' } );
		},

		optimizationCard() {
			const s = this.status;
			const on = !! s.auto_optimize;
			const count = num( obj( s.active ).count );
			return card( __( 'Optimization Status', 'sh-speed-optimizer' ), [
				h( 'p', { class: 'shso-row' },
					h( 'strong', {}, __( 'Automatic Optimization', 'sh-speed-optimizer' ) ),
					pill( on ? 'good' : 'unknown', on ? __( 'ON', 'sh-speed-optimizer' ) : __( 'OFF', 'sh-speed-optimizer' ) )
				),
				h( 'p', { class: 'shso-muted' }, on
					? __( 'Safe optimizations are automatically detected and applied.', 'sh-speed-optimizer' )
					: __( 'Automatic optimization is off. Use “Optimize My Site” to apply safe optimizations yourself.', 'sh-speed-optimizer' ) ),
				/* translators: %d: number of active optimizations. */
				null !== count ? h( 'p', {}, h( 'strong', {}, sprintf( _n( '%d active optimization', '%d active optimizations', count, 'sh-speed-optimizer' ), count ) ) ) : null,
				cardFooter( link( __( 'Manage optimizations', 'sh-speed-optimizer' ), pageUrl( 'optimization' ) ) ),
			], { id: 'shso-card-optimization' } );
		},

		cacheCard() {
			const cache = obj( this.status.cache );
			const rows = [
				[ 'page', __( 'Page Cache', 'sh-speed-optimizer' ) ],
				[ 'browser', __( 'Browser Cache', 'sh-speed-optimizer' ) ],
				[ 'object', __( 'Object Cache', 'sh-speed-optimizer' ) ],
			].map( ( pair ) => {
				const entry = obj( cache[ pair[ 0 ] ] );
				const state = CACHE_TONE[ entry.state ] ? entry.state : 'unknown';
				return h( 'li', { class: 'shso-statusrow' },
					dot( CACHE_TONE[ state ] ),
					h( 'span', {}, h( 'span', { class: 'shso-statusrow__title' }, pair[ 1 ] ), ': ', str( entry.label, CACHE_LABEL[ state ] ) ),
					str( entry.detail ) ? h( 'span', { class: 'shso-statusrow__detail' }, str( entry.detail ) ) : null
				);
			} );
			return card( __( 'Cache Status', 'sh-speed-optimizer' ), [
				h( 'ul', { class: 'shso-statusrows' }, rows ),
				cardFooter( link( __( 'Cache details', 'sh-speed-optimizer' ), pageUrl( 'cache' ) ) ),
			], { id: 'shso-card-cache' } );
		},

		scanCard() {
			const s = this.status;
			const human = str( obj( s.last_scan ).human );
			const issues = num( obj( s.issues ).count );
			return card( __( 'Last Scan', 'sh-speed-optimizer' ), [
				/* translators: %s: time of the last analysis, e.g. "2 hours ago". */
				h( 'p', {}, sprintf( __( 'Last analysis: %s', 'sh-speed-optimizer' ), human || __( 'Never', 'sh-speed-optimizer' ) ) ),
				human && null !== issues ? h( 'p', {},
					/* translators: %d: number of potential issues. */
					sprintf( _n( '%d potential issue found.', '%d potential issues found.', issues, 'sh-speed-optimizer' ), issues ), ' ',
					link( __( 'View report', 'sh-speed-optimizer' ), pageUrl( 'diagnostics' ) )
				) : null,
				cardFooter( button( __( 'Run Performance Scan', 'sh-speed-optimizer' ), () => this.startScan(), { id: 'shso-scan' } ) ),
			], { id: 'shso-card-scan' } );
		},
	};

	// ------------------------------------------------------------------
	// Optimization page
	// ------------------------------------------------------------------

	const CATEGORY_LOOK = {
		optimized: { tone: 'good', symbol: '✓' },
		partial: { tone: 'info', symbol: '✓' },
		attention: { tone: 'attention', symbol: '!' },
		handled: { tone: 'info', symbol: 'i' },
		none: { tone: 'unknown', symbol: '–' },
	};

	const ITEM_STATE = {
		active: { tone: 'good', label: __( 'Active', 'sh-speed-optimizer' ) },
		inactive: { tone: 'unknown', label: __( 'Not active', 'sh-speed-optimizer' ) },
		disabled: { tone: 'attention', label: __( 'Rolled back', 'sh-speed-optimizer' ) },
		handled: { tone: 'info', label: __( 'Handled by another plugin', 'sh-speed-optimizer' ) },
		not_applicable: { tone: 'unknown', label: __( 'Not needed', 'sh-speed-optimizer' ) },
	};

	const RISK_LABELS = {
		safe: __( 'Safe', 'sh-speed-optimizer' ),
		low: __( 'Low risk', 'sh-speed-optimizer' ),
		moderate: __( 'Moderate risk', 'sh-speed-optimizer' ),
		high: __( 'High risk', 'sh-speed-optimizer' ),
	};

	const BENEFIT_LABELS = {
		none: __( 'No benefit', 'sh-speed-optimizer' ),
		low: __( 'Low benefit', 'sh-speed-optimizer' ),
		medium: __( 'Medium benefit', 'sh-speed-optimizer' ),
		high: __( 'High benefit', 'sh-speed-optimizer' ),
	};

	const HISTORY_LOOK = {
		applied: [ 'good', '✓' ],
		manual_on: [ 'good', '✓' ],
		rolled_back: [ 'attention', '↩' ],
		manual_off: [ 'attention', '↩' ],
		restored: [ 'info', '↩' ],
	};

	function riskText( risk, item ) {
		if ( 'string' === typeof risk && RISK_LABELS[ risk ] ) {
			return RISK_LABELS[ risk ];
		}
		const n = num( risk );
		if ( null !== n ) {
			return n >= 60 ? RISK_LABELS.high : n >= 35 ? RISK_LABELS.moderate : n >= 15 ? RISK_LABELS.low : RISK_LABELS.safe;
		}
		return str( obj( item ).risk_label );
	}

	function benefitText( benefit ) {
		if ( 'string' === typeof benefit && BENEFIT_LABELS[ benefit ] ) {
			return BENEFIT_LABELS[ benefit ];
		}
		const n = num( benefit );
		if ( null !== n ) {
			return n >= 60 ? BENEFIT_LABELS.high : n >= 30 ? BENEFIT_LABELS.medium : n > 0 ? BENEFIT_LABELS.low : BENEFIT_LABELS.none;
		}
		return '';
	}

	function handledBy( item ) {
		const name = str( item.handled_by );
		if ( name ) {
			return name;
		}
		const source = str( item.source );
		return source && -1 === [ 'auto', 'manual', 'none', 'default' ].indexOf( source ) ? source : '';
	}

	function isVerified( value ) {
		return true === value || ( 'string' === typeof value && '' !== value && 'none' !== value );
	}

	const OptimizationPage = {
		data: null,
		open: {},
		advanced: false,
		hashHandled: false,

		init() {
			this.slots = {
				opts: document.getElementById( 'shso-optimizations' ),
				db: document.getElementById( 'shso-database' ),
				history: document.getElementById( 'shso-history' ),
			};
			this.advanced = '1' === store.get( STORE_ADVANCED );
			const toggle = document.getElementById( 'shso-advanced-toggle' );
			if ( toggle ) {
				toggle.checked = this.advanced;
				toggle.disabled = false;
				toggle.addEventListener( 'change', () => {
					this.advanced = toggle.checked;
					store.set( STORE_ADVANCED, this.advanced ? '1' : '0' );
					this.renderOpts();
				} );
			}
			this.loadOpts();
			this.loadHistory();
			this.loadDb();
			Jobs.resumeIfActive( () => this.refreshAll() );
		},

		refreshAll() {
			this.loadOpts();
			this.loadHistory();
			this.loadDb();
		},

		loadOpts() {
			return api( '/optimizations' ).then( ( res ) => {
				this.data = obj( res );
				this.renderOpts();
			}, ( err ) => fill( this.slots.opts, loadError( err, () => this.loadOpts() ) ) );
		},

		renderOpts() {
			if ( ! this.data ) {
				return;
			}
			renderInto( this.slots.opts, () => {
				const categories = arr( this.data.categories ).filter( isObj );
				if ( ! categories.length ) {
					return h( 'p', { class: 'shso-empty' }, __( 'No optimization data yet. Run a performance scan from the Overview first.', 'sh-speed-optimizer' ) );
				}
				return h( 'div', { class: 'shso-grid' }, categories.map( ( cat ) => this.categoryCard( cat ) ) );
			} );
			this.handleHash();
		},

		handleHash() {
			if ( this.hashHandled || ! /^#shso-opt-/.test( window.location.hash ) ) {
				return;
			}
			this.hashHandled = true;
			const target = document.getElementById( window.location.hash.slice( 1 ) );
			if ( ! target ) {
				return;
			}
			const list = target.closest( '.shso-opt-list' );
			const cardEl = target.closest( '.shso-category' );
			const toggleBtn = cardEl ? $( '.shso-toggle-btn', cardEl ) : null;
			if ( list && list.hidden && toggleBtn ) {
				toggleBtn.click();
			}
			target.setAttribute( 'tabindex', '-1' );
			target.focus();
		},

		categoryCard( cat ) {
			const id = str( cat.id, 'category' );
			const look = CATEGORY_LOOK[ cat.status ] || CATEGORY_LOOK.none;
			const items = arr( cat.items ).filter( ( item ) => isObj( item ) && ( this.data.advanced || ! item.experimental ) );
			const listId = 'shso-cat-' + safeId( id );
			const isOpen = !! this.open[ id ];

			const list = h( 'ul', { class: 'shso-opt-list', id: listId, hidden: ! isOpen }, items.map( ( item ) => this.optItem( item ) ) );
			const label = h( 'span', {}, isOpen ? __( 'Hide Details', 'sh-speed-optimizer' ) : __( 'View Details', 'sh-speed-optimizer' ) );
			const toggleBtn = h( 'button', {
				type: 'button',
				class: 'button shso-toggle-btn',
				'aria-expanded': isOpen ? 'true' : 'false',
				'aria-controls': listId,
			}, label, h( 'span', { class: 'shso-chevron', 'aria-hidden': 'true' }, '▾' ) );

			const titleId = 'shso-cat-title-' + safeId( id );
			const cardEl = h(
				'section',
				{ class: [ 'shso-card', 'shso-category', isOpen ? 'is-open' : null ], 'aria-labelledby': titleId },
				h( 'h3', { class: 'shso-card__title', id: titleId }, str( cat.label, id ) ),
				h( 'p', { class: 'shso-category__status' }, mark( look.tone, look.symbol ), str( cat.status_label ) ),
				str( cat.summary ) ? h( 'p', { class: 'shso-category__summary' }, str( cat.summary ) ) : null,
				items.length ? cardFooter( toggleBtn ) : null,
				items.length ? list : null
			);

			toggleBtn.addEventListener( 'click', () => {
				const nowOpen = 'true' !== toggleBtn.getAttribute( 'aria-expanded' );
				this.open[ id ] = nowOpen;
				toggleBtn.setAttribute( 'aria-expanded', nowOpen ? 'true' : 'false' );
				label.textContent = nowOpen ? __( 'Hide Details', 'sh-speed-optimizer' ) : __( 'View Details', 'sh-speed-optimizer' );
				list.hidden = ! nowOpen;
				cardEl.classList.toggle( 'is-open', nowOpen );
			} );

			return cardEl;
		},

		optItem( item ) {
			const state = ITEM_STATE[ item.state ] || ITEM_STATE.inactive;
			let stateLabel = state.label;
			if ( 'handled' === item.state && handledBy( item ) ) {
				/* translators: %s: name of another plugin or system. */
				stateLabel = sprintf( __( 'Handled by %s', 'sh-speed-optimizer' ), handledBy( item ) );
			}
			const decision = isObj( item.decision ) ? item.decision : null;

			const meta = [];
			if ( decision ) {
				const confidence = num( decision.confidence );
				if ( null !== confidence ) {
					/* translators: %d: confidence percentage. */
					meta.push( sprintf( __( 'Confidence %d%%', 'sh-speed-optimizer' ), Math.round( confidence ) ) );
				}
				const risk = riskText( decision.risk, item );
				if ( risk ) {
					meta.push( risk );
				}
				const benefit = benefitText( decision.benefit );
				if ( benefit ) {
					meta.push( benefit );
				}
			} else if ( str( item.risk_label ) ) {
				meta.push( str( item.risk_label ) );
			}
			if ( 'active' === item.state && num( item.since ) ) {
				/* translators: %s: date. */
				meta.push( sprintf( __( 'Active since %s', 'sh-speed-optimizer' ), fmtDate( item.since ) ) );
			}

			const details = obj( item.details );
			const detailKeys = Object.keys( details );
			const reasons = decision ? arr( decision.reasons ).map( ( r ) => str( r ) ).filter( Boolean ) : [];
			const titleId = 'shso-opt-title-' + safeId( item.id );

			return h(
				'li',
				{ class: 'shso-opt', id: 'shso-opt-' + safeId( item.id ) },
				h( 'div', { class: 'shso-opt__head' },
					h( 'h4', { class: 'shso-opt__name', id: titleId }, str( item.name, str( item.id ) ) ),
					pill( state.tone, stateLabel ),
					isVerified( item.verified ) ? pill( 'good', __( 'Verified', 'sh-speed-optimizer' ), 'shso-pill--plain' ) : null
				),
				item.experimental ? h( 'p', { class: 'shso-experimental' }, __( 'Experimental — disabled by default', 'sh-speed-optimizer' ) ) : null,
				str( item.description ) ? h( 'p', {}, str( item.description ) ) : null,
				decision && str( decision.summary ) ? h( 'p', { class: 'shso-opt__decision' }, str( decision.summary ) ) : null,
				meta.length ? h( 'p', { class: 'shso-opt__meta' }, meta.join( ' · ' ) ) : null,
				detailKeys.length ? h( 'dl', { class: 'shso-kv' }, detailKeys.map( ( key ) => [
					h( 'dt', {}, key ),
					h( 'dd', {}, valueNode( details[ key ], 2 ) ),
				] ) ) : null,
				reasons.length ? h( 'ul', { class: 'shso-opt__reasons' }, reasons.map( ( r ) => h( 'li', {}, r ) ) ) : null,
				this.advanced ? this.modeControl( item ) : null
			);
		},

		modeControl( item ) {
			const current = 'on' === item.override || 'off' === item.override ? item.override : 'auto';
			const name = 'shso-mode-' + safeId( item.id );
			const group = h( 'fieldset', { class: 'shso-segmented' },
				/* translators: %s: optimization name. */
				h( 'legend', {}, sprintf( __( 'Mode for %s', 'sh-speed-optimizer' ), str( item.name, str( item.id ) ) ) )
			);
			[
				[ 'auto', __( 'Auto', 'sh-speed-optimizer' ) ],
				[ 'on', __( 'On', 'sh-speed-optimizer' ) ],
				[ 'off', __( 'Off', 'sh-speed-optimizer' ) ],
			].forEach( ( pair ) => {
				const inputId = name + '-' + pair[ 0 ];
				group.appendChild( h( 'input', { type: 'radio', name, id: inputId, value: pair[ 0 ], checked: pair[ 0 ] === current } ) );
				group.appendChild( h( 'label', { for: inputId }, pair[ 1 ] ) );
			} );
			const feedback = h( 'span', { class: 'shso-opt__feedback', 'aria-live': 'polite' } );
			group.addEventListener( 'change', ( e ) => {
				if ( e.target && 'radio' === e.target.type ) {
					this.setMode( item, e.target.value, group, feedback, current );
				}
			} );
			return h( 'div', { class: 'shso-opt__controls' },
				group,
				feedback,
				h( 'span', { class: 'shso-muted shso-small' }, __( 'Auto lets SH Speed Optimizer decide.', 'sh-speed-optimizer' ) )
			);
		},

		setMode( item, mode, group, feedback, previous ) {
			const name = 'shso-mode-' + safeId( item.id );
			const radios = Array.prototype.slice.call( group.querySelectorAll( 'input' ) );
			radios.forEach( ( r ) => {
				r.disabled = true;
			} );
			feedback.classList.remove( 'is-error' );
			feedback.textContent = __( 'Saving…', 'sh-speed-optimizer' );
			api( '/optimizations/' + encodeURIComponent( str( item.id ) ), 'POST', { mode } ).then( ( res ) => {
				const r = obj( res );
				feedback.textContent = str( r.message, __( 'Saved.', 'sh-speed-optimizer' ) );
				if ( false === r.ok ) {
					feedback.classList.add( 'is-error' );
				}
				if ( isObj( r.job ) ) {
					return Jobs.run( r.job, () => this.refreshAll() );
				}
				const focusId = name + '-' + mode;
				this.loadHistory();
				return this.loadOpts().then( () => {
					const again = document.getElementById( focusId );
					if ( again ) {
						again.focus();
					}
				} );
			}, ( err ) => {
				radios.forEach( ( r ) => {
					r.disabled = false;
					r.checked = r.value === previous;
				} );
				feedback.classList.add( 'is-error' );
				feedback.textContent = errorMessage( err );
			} );
		},

		// History ------------------------------------------------------

		loadHistory() {
			return api( '/history' ).then( ( res ) => this.renderHistory( obj( res ) ), ( err ) => fill( this.slots.history, loadError( err, () => this.loadHistory() ) ) );
		},

		renderHistory( data ) {
			renderInto( this.slots.history, () => {
				const entries = arr( data.entries ).filter( isObj );
				const snapshots = arr( data.snapshots ).filter( isObj );
				const limit = 25;
				const listWrap = h( 'div', { class: 'shso-stack' } );

				const renderEntries = ( all ) => {
					clear( listWrap );
					const shown = all ? entries : entries.slice( 0, limit );
					const groups = [];
					shown.forEach( ( entry ) => {
						const day = str( entry.day_label, __( 'Earlier', 'sh-speed-optimizer' ) );
						let group = groups.length ? groups[ groups.length - 1 ] : null;
						if ( ! group || group.day !== day ) {
							group = { day, items: [] };
							groups.push( group );
						}
						group.items.push( entry );
					} );
					groups.forEach( ( group ) => {
						listWrap.appendChild( h( 'div', { class: 'shso-history-day' },
							h( 'h4', { class: 'shso-history-day__title' }, group.day ),
							h( 'ul', { class: 'shso-list' }, group.items.map( ( entry ) => {
								const look = HISTORY_LOOK[ entry.event ] || [ 'info', '•' ];
								return h( 'li', {},
									mark( look[ 0 ], look[ 1 ] ),
									h( 'span', { class: 'shso-history-entry__time' }, str( entry.time_label ) ),
									h( 'span', { class: 'shso-list__body' }, str( entry.message, str( entry.event ) ) )
								);
							} ) )
						) );
					} );
					if ( ! all && entries.length > limit ) {
						/* translators: %d: number of history entries. */
						listWrap.appendChild( h( 'div', {}, button( sprintf( __( 'Show all (%d)', 'sh-speed-optimizer' ), entries.length ), () => {
							renderEntries( true );
							listWrap.setAttribute( 'tabindex', '-1' );
							listWrap.focus();
						}, { link: true } ) ) );
					}
				};

				if ( entries.length ) {
					renderEntries( false );
				} else {
					listWrap.appendChild( h( 'p', { class: 'shso-empty' }, __( 'No changes yet.', 'sh-speed-optimizer' ) ) );
				}

				const historyCard = card( null, [
					h( 'div', { class: 'shso-card__head' },
						h( 'p', { class: 'shso-muted' }, __( 'Every change SH Speed Optimizer made to your site.', 'sh-speed-optimizer' ) ),
						button( __( 'Undo Last Optimization', 'sh-speed-optimizer' ), ( btn ) => this.undo( btn ), { disabled: ! entries.length && ! snapshots.length, id: 'shso-undo' } )
					),
					listWrap,
				] );

				const snapshotCard = card( __( 'Restore points', 'sh-speed-optimizer' ), [
					h( 'p', { class: 'shso-muted' }, __( 'Saved configurations you can go back to.', 'sh-speed-optimizer' ) ),
					snapshots.length
						? h( 'ul', { class: 'shso-list' }, snapshots.map( ( snap ) => h( 'li', {},
							h( 'span', { class: 'shso-list__body' },
								h( 'strong', {}, str( snap.label, __( 'Restore point', 'sh-speed-optimizer' ) ) ),
								str( snap.time_label, fmtDate( snap.time ) ) ? h( 'span', { class: 'shso-muted' }, ' — ' + str( snap.time_label, fmtDate( snap.time ) ) ) : null
							),
							button( __( 'Restore', 'sh-speed-optimizer' ), ( btn ) => this.restoreSnapshot( snap, btn ) )
						) ) )
						: h( 'p', { class: 'shso-empty' }, __( 'No restore points yet.', 'sh-speed-optimizer' ) ),
				] );

				return [ historyCard, snapshotCard ];
			} );
		},

		undo( btn ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Undo the last optimization change? SH Speed Optimizer will go back to the previous configuration.', 'sh-speed-optimizer' ) ) ) {
				return;
			}
			busy( btn, true );
			api( '/undo', 'POST' ).then( ( res ) => this.afterAction( res, btn ), ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		restoreSnapshot( snap, btn ) {
			/* translators: %s: name of the restore point. */
			const question = sprintf( __( 'Restore “%s”? Your current optimization configuration will be replaced by this restore point.', 'sh-speed-optimizer' ), str( snap.label, str( snap.time_label ) ) );
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( question ) ) {
				return;
			}
			busy( btn, true );
			api( '/snapshots/' + encodeURIComponent( str( snap.id ) ) + '/restore', 'POST' ).then( ( res ) => this.afterAction( res, btn ), ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		afterAction( res, btn ) {
			const r = obj( res );
			busy( btn, false );
			notify( false === r.ok ? 'error' : 'success', str( r.message, __( 'Done.', 'sh-speed-optimizer' ) ) );
			if ( isObj( r.job ) ) {
				return Jobs.run( r.job, () => this.refreshAll() );
			}
			this.refreshAll();
			return null;
		},

		// Database -----------------------------------------------------

		loadDb() {
			return api( '/database' ).then( ( res ) => this.renderDb( obj( res ) ), ( err ) => fill( this.slots.db, loadError( err, () => this.loadDb() ) ) );
		},

		/**
		 * Count of removable rows for a cleanup item (revisions: removable
		 * with the default "keep" setting).
		 *
		 * @param {Object} analysis Analysis.
		 * @param {string} key      Item key.
		 * @return {number|null} Count.
		 */
		dbCount( analysis, key ) {
			const a = obj( analysis );
			const counts = isObj( a.counts ) ? a.counts : a;
			let value = counts[ key ];
			if ( isObj( value ) ) {
				value = first( value.removable, value.count, value.total, value.rows );
			}
			return num( value );
		},

		autoloadBytes( analysis ) {
			const a = obj( analysis );
			const auto = a.autoload;
			return num( first(
				a.autoload_bytes,
				a.autoload_size,
				isObj( auto ) ? first( auto.bytes, auto.size, auto.total_bytes ) : auto
			) );
		},

		renderDb( data ) {
			renderInto( this.slots.db, () => {
				const analysis = obj( data.analysis );
				const findings = arr( data.findings ).filter( isObj );
				const backups = arr( data.backups ).filter( isObj );

				const stats = DB_ITEMS.map( ( item ) => ( { item, count: this.dbCount( analysis, item.key ) } ) ).filter( ( s ) => null !== s.count );
				const autoload = this.autoloadBytes( analysis );
				const revisions = obj( analysis.revisions );
				const tables = obj( analysis.tables );
				const tableBytes = false !== tables.available ? num( tables.total_bytes ) : null;
				const stat = ( label, value, hint ) => h( 'div', { class: 'shso-stat' },
					h( 'dt', {}, label ),
					h( 'dd', {}, value, hint ? h( 'span', { class: 'shso-small shso-muted', style: 'display:block;font-weight:400' }, hint ) : null )
				);

				const statGrid = stats.length || null !== autoload || null !== tableBytes
					? h( 'dl', { class: 'shso-stat-grid' },
						null !== tableBytes ? stat( __( 'Database size', 'sh-speed-optimizer' ), fmtBytes( tableBytes ) ) : null,
						null !== autoload ? stat( __( 'Autoloaded options size', 'sh-speed-optimizer' ), fmtBytes( autoload ), __( 'Loaded on every page view', 'sh-speed-optimizer' ) ) : null,
						stats.map( ( s ) => {
							if ( 'revisions' === s.item.key && null !== num( revisions.total ) ) {
								/* translators: 1: removable revisions, 2: revisions kept per post. */
								return stat( s.item.label, fmtNumber( revisions.total ), sprintf( __( '%1$s removable when keeping %2$d per post', 'sh-speed-optimizer' ), fmtNumber( s.count ), num( revisions.keep ) || 5 ) );
							}
							return stat( s.item.label, fmtNumber( s.count ) );
						} )
					)
					: h( 'p', { class: 'shso-empty' }, __( 'Database analysis is not available yet.', 'sh-speed-optimizer' ) );

				const findingList = findings.length ? h( 'ul', { class: 'shso-list' }, findings.map( ( f ) => h( 'li', {},
					mark( SEVERITY_TONE[ f.severity ] || 'info', 'good' === f.severity ? '✓' : '!' ),
					h( 'span', { class: 'shso-list__body' },
						h( 'span', { class: 'shso-finding__title' }, str( f.title ) ),
						str( f.description ) ? h( 'span', { class: 'shso-finding__text', style: 'display:block' }, str( f.description ) ) : null
					)
				) ) ) : null;

				const formWrap = h( 'div', { id: 'shso-db-form', hidden: true } );
				const openBtn = h( 'button', {
					type: 'button',
					class: 'button',
					id: 'shso-db-clean',
					'aria-expanded': 'false',
					'aria-controls': 'shso-db-form',
				}, __( 'Clean Database', 'sh-speed-optimizer' ) );
				openBtn.addEventListener( 'click', () => {
					const show = formWrap.hidden;
					if ( show && ! formWrap.firstChild ) {
						formWrap.appendChild( this.cleanForm( analysis, () => {
							formWrap.hidden = true;
							openBtn.setAttribute( 'aria-expanded', 'false' );
							openBtn.focus();
						} ) );
					}
					formWrap.hidden = ! show;
					openBtn.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
					if ( show ) {
						const firstInput = $( 'input', formWrap );
						if ( firstInput ) {
							firstInput.focus();
						}
					}
				} );

				return [
					card( null, [ statGrid, findingList, cardFooter( openBtn ), formWrap ] ),
					this.backupsCard( backups ),
				];
			} );
		},

		cleanForm( analysis, onCancel ) {
			const checks = DB_ITEMS.map( ( item ) => {
				const count = this.dbCount( analysis, item.key );
				const input = h( 'input', { type: 'checkbox', name: 'shso_db_items', value: item.key, checked: item.def } );
				return {
					input,
					node: h( 'label', {}, input, h( 'span', {}, item.label, null !== count ? h( 'span', { class: 'shso-muted' }, ' (' + fmtNumber( count ) + ')' ) : null ) ),
				};
			} );
			const keep = h( 'input', { type: 'number', min: '0', max: '50', step: '1', value: '5', class: 'small-text', id: 'shso-keep-revisions' } );
			const submit = h( 'button', { type: 'submit', class: 'button button-primary' }, __( 'Start cleanup', 'sh-speed-optimizer' ) );

			const form = h( 'form', { class: 'shso-form', novalidate: true },
				h( 'fieldset', {},
					h( 'legend', {}, __( 'What should be removed?', 'sh-speed-optimizer' ) ),
					h( 'div', { class: 'shso-checks' }, checks.map( ( c ) => c.node ) )
				),
				h( 'p', { class: 'shso-inline' },
					h( 'label', { for: 'shso-keep-revisions' }, __( 'Keep the most recent revisions per post:', 'sh-speed-optimizer' ) ),
					keep
				),
				h( 'p', { class: 'shso-muted' }, __( 'A backup of everything removed is created first.', 'sh-speed-optimizer' ) ),
				h( 'div', { class: 'shso-row' }, submit, button( __( 'Cancel', 'sh-speed-optimizer' ), () => onCancel(), { link: true } ) )
			);

			form.addEventListener( 'submit', ( e ) => {
				e.preventDefault();
				const items = checks.filter( ( c ) => c.input.checked ).map( ( c ) => c.input.value );
				if ( ! items.length ) {
					notify( 'error', __( 'Select at least one item to clean.', 'sh-speed-optimizer' ) );
					return;
				}
				let keepRevisions = parseInt( keep.value, 10 );
				if ( isNaN( keepRevisions ) || keepRevisions < 0 || keepRevisions > 50 ) {
					notify( 'error', __( 'Enter a number of revisions between 0 and 50.', 'sh-speed-optimizer' ) );
					keep.focus();
					return;
				}
				keepRevisions = Math.round( keepRevisions );
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( __( 'Remove the selected items from your database? A backup is created first, so you can restore them later.', 'sh-speed-optimizer' ) ) ) {
					return;
				}
				Jobs.start( 'db_clean', { items, keep_revisions: keepRevisions }, () => this.loadDb() );
			} );

			return form;
		},

		backupsCard( backups ) {
			return card( __( 'Database backups', 'sh-speed-optimizer' ), backups.length
				? h( 'div', { class: 'shso-table-wrap' }, h( 'table', { class: 'shso-table' },
					h( 'thead', {}, h( 'tr', {},
						h( 'th', { scope: 'col' }, __( 'Created', 'sh-speed-optimizer' ) ),
						h( 'th', { scope: 'col' }, __( 'Contents', 'sh-speed-optimizer' ) ),
						h( 'th', { scope: 'col', class: 'is-num' }, __( 'Rows', 'sh-speed-optimizer' ) ),
						h( 'th', { scope: 'col', class: 'is-num' }, __( 'Size', 'sh-speed-optimizer' ) ),
						h( 'th', { scope: 'col' }, h( 'span', { class: 'screen-reader-text' }, __( 'Actions', 'sh-speed-optimizer' ) ) )
					) ),
					h( 'tbody', {}, backups.map( ( b ) => h( 'tr', {},
						h( 'td', {}, str( b.created_label, fmtDate( b.created ) || '—' ) ),
						h( 'td', {}, arr( b.items ).map( ( key ) => dbLabel( key ) ).join( ', ' ) || '—' ),
						h( 'td', { class: 'is-num' }, fmtNumber( b.rows ) ),
						h( 'td', { class: 'is-num' }, fmtBytes( b.bytes ) ),
						h( 'td', {}, h( 'span', { class: 'shso-row' },
							false !== b.restorable ? button( __( 'Restore', 'sh-speed-optimizer' ), ( btn ) => this.restoreBackup( b, btn ) ) : null,
							button( __( 'Delete', 'sh-speed-optimizer' ), ( btn ) => this.deleteBackup( b, btn ), { link: true, danger: true } )
						) )
					) ) )
				) )
				: h( 'p', { class: 'shso-empty' }, __( 'No database backups.', 'sh-speed-optimizer' ) ) );
		},

		restoreBackup( backup, btn ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Restore the items from this backup into your database?', 'sh-speed-optimizer' ) ) ) {
				return;
			}
			busy( btn, true );
			api( '/database/restore', 'POST', { id: backup.id } ).then( ( res ) => {
				const r = obj( res );
				notify( false === r.ok ? 'error' : 'success', str( r.message, __( 'Backup restored.', 'sh-speed-optimizer' ) ) );
				this.loadDb();
			}, ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		deleteBackup( backup, btn ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Delete this backup permanently? The removed items can then no longer be restored.', 'sh-speed-optimizer' ) ) ) {
				return;
			}
			busy( btn, true );
			api( '/database/backups/' + encodeURIComponent( str( backup.id ) ), 'DELETE' ).then( () => {
				notify( 'success', __( 'Backup deleted.', 'sh-speed-optimizer' ) );
				this.loadDb();
			}, ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},
	};

	// ------------------------------------------------------------------
	// Diagnostics page
	// ------------------------------------------------------------------

	const PSI_CATEGORIES = [
		[ 'performance', __( 'Performance', 'sh-speed-optimizer' ) ],
		[ 'accessibility', __( 'Accessibility', 'sh-speed-optimizer' ) ],
		[ 'best-practices', __( 'Best Practices', 'sh-speed-optimizer' ) ],
		[ 'seo', __( 'SEO', 'sh-speed-optimizer' ) ],
	];

	function psiScoreTone( score ) {
		if ( null === score ) {
			return 'unknown';
		}
		return score >= 90 ? 'good' : score >= 50 ? 'attention' : 'poor';
	}

	const DiagnosticsPage = {
		data: null,
		devData: null,
		devLoading: false,

		init() {
			this.slots = {
				report: document.getElementById( 'shso-report' ),
				psi: document.getElementById( 'shso-pagespeed' ),
				dev: document.getElementById( 'shso-developer-body' ),
			};
			const details = document.getElementById( 'shso-developer' );
			if ( details ) {
				details.addEventListener( 'toggle', () => {
					if ( details.open && ! this.devData && ! this.devLoading ) {
						this.loadDev();
					}
				} );
			}
			this.load();
			Jobs.resumeIfActive( () => this.load() );
		},

		load() {
			return api( '/diagnostics' ).then( ( res ) => {
				this.data = obj( res );
				this.renderReport();
				this.renderPsi();
				if ( this.devData ) {
					this.loadDev();
				}
			}, ( err ) => {
				fill( this.slots.report, loadError( err, () => this.load() ) );
				fill( this.slots.psi );
			} );
		},

		findingItem( finding, kind ) {
			const f = obj( finding );
			const tone = 'good' === kind ? 'good' : 'attention' === kind ? SEVERITY_TONE[ f.severity ] || 'attention' : 'info';
			const symbol = 'good' === kind ? '✓' : 'attention' === kind ? '!' : '↗';
			const optId = str( f.optimization );
			return h( 'li', {},
				mark( tone, symbol ),
				h( 'div', { class: 'shso-list__body' },
					h( 'p', { class: 'shso-finding__title' }, str( f.title, str( f.id ) ) ),
					str( f.description ) ? h( 'p', { class: 'shso-finding__text' }, str( f.description ) ) : null,
					'good' !== kind && str( f.recommendation ) ? h( 'p', { class: 'shso-finding__rec' }, str( f.recommendation ) ) : null,
					'good' !== kind && optId ? h( 'p', { class: 'shso-small' }, link( __( 'See optimization', 'sh-speed-optimizer' ), pageUrl( 'optimization', '#shso-opt-' + safeId( optId ) ) ) ) : null
				)
			);
		},

		findingColumn( title, findings, kind, empty ) {
			const list = arr( findings ).filter( isObj );
			const look = { good: [ 'good', '✓' ], attention: [ 'attention', '!' ], improvements: [ 'info', '↗' ] }[ kind ];
			return card( null, [
				h( 'h3', { class: 'shso-card__title shso-row' }, mark( look[ 0 ], look[ 1 ] ), title, h( 'span', { class: 'shso-muted' }, '(' + list.length + ')' ) ),
				list.length
					? h( 'ul', { class: 'shso-list' }, list.map( ( f ) => this.findingItem( f, kind ) ) )
					: h( 'p', { class: 'shso-empty' }, empty ),
			] );
		},

		renderReport() {
			renderInto( this.slots.report, () => {
				const d = this.data;
				const health = obj( d.health );
				const info = healthInfo( health );
				const score = num( health.score );
				const scan = obj( d.scan );
				const pages = arr( scan.pages );
				const report = obj( d.report );
				const human = str( scan.human );

				const head = h( 'div', { class: 'shso-report-head' },
					h( 'span', { class: 'shso-report-head__score' }, null !== score
						/* translators: %d: score 0-100. */
						? sprintf( __( 'SH Performance Health %d/100', 'sh-speed-optimizer' ), score )
						: __( 'SH Performance Health: not measured yet', 'sh-speed-optimizer' ) ),
					pill( info.tone, info.label )
				);

				const meta = h( 'p', { class: 'shso-muted' },
					human
						/* translators: %s: time of the last analysis. */
						? sprintf( __( 'Last analysis: %s', 'sh-speed-optimizer' ), human )
						: __( 'Your site has not been analyzed yet.', 'sh-speed-optimizer' ),
					/* translators: %d: number of pages. */
					pages.length ? ' · ' + sprintf( _n( '%d page analyzed', '%d pages analyzed', pages.length, 'sh-speed-optimizer' ), pages.length ) : null
				);

				const scanBtn = button( __( 'Run Performance Scan', 'sh-speed-optimizer' ), () => Jobs.start( 'scan', { browser: true }, () => this.load() ), { id: 'shso-scan' } );

				return [
					card( null, [ head, meta, h( 'div', { class: 'shso-row' }, scanBtn ) ] ),
					h( 'div', { class: 'shso-report-cols' },
						this.findingColumn( __( 'Good', 'sh-speed-optimizer' ), report.good, 'good', __( 'Nothing to report yet.', 'sh-speed-optimizer' ) ),
						this.findingColumn( __( 'Attention', 'sh-speed-optimizer' ), report.attention, 'attention', __( 'Nothing needs your attention.', 'sh-speed-optimizer' ) ),
						this.findingColumn( __( 'Potential Improvements', 'sh-speed-optimizer' ), report.improvements, 'improvements', __( 'No further improvements detected.', 'sh-speed-optimizer' ) )
					),
				];
			} );
		},

		renderPsi() {
			renderInto( this.slots.psi, () => {
				const p = obj( this.data.pagespeed );
				if ( ! p.configured ) {
					return card( null, h( 'p', {},
						__( 'Optional: connect a PageSpeed Insights API key in Settings to see Google\'s measurements.', 'sh-speed-optimizer' ), ' ',
						link( __( 'Open Settings', 'sh-speed-optimizer' ), pageUrl( 'settings', '#shso-section-measurement' ) )
					) );
				}

				const statusEl = h( 'span', { class: 'shso-muted shso-small', 'aria-live': 'polite' } );
				const runBtn = button( __( 'Run PageSpeed test (mobile)', 'sh-speed-optimizer' ), ( btn ) => this.runPsi( btn, statusEl ), { id: 'shso-psi-run' } );
				const out = [ h( 'div', { class: 'shso-row' }, runBtn, statusEl ) ];

				const latest = isObj( p.latest ) ? p.latest : null;
				if ( latest ) {
					const when = str( latest.human, fmtDate( latest.fetched_at ) );
					const strategy = 'desktop' === latest.strategy ? __( 'desktop', 'sh-speed-optimizer' ) : __( 'mobile', 'sh-speed-optimizer' );
					/* translators: 1: time of the test, 2: mobile or desktop. */
					out.push( h( 'p', { class: 'shso-muted' }, sprintf( __( 'Latest test: %1$s (%2$s)', 'sh-speed-optimizer' ), when || '—', strategy ) ) );

					const cats = obj( latest.categories );
					out.push( h( 'ul', { class: 'shso-psi-scores' }, PSI_CATEGORIES.map( ( c ) => {
						const v = num( cats[ c[ 0 ] ] );
						const tone = psiScoreTone( v );
						return h( 'li', { class: 'shso-psi-score' },
							h( 'span', { class: 'shso-psi-score__value' }, null === v ? '—' : fmtNumber( v ) ),
							h( 'span', { class: 'shso-psi-score__label' }, c[ 1 ] ),
							null === v ? pill( 'unknown', __( 'Unknown', 'sh-speed-optimizer' ) ) : pill( tone, METRIC_STATUS[ { good: 'good', attention: 'needs-improvement', poor: 'poor' }[ tone ] ].label )
						);
					} ) ) );

					out.push( h( 'p', { class: 'shso-subhead' }, 'origin' === latest.field_scope
						? __( 'Field data (real visitors of your whole site, collected by Google)', 'sh-speed-optimizer' )
						: __( 'Field data (real visitors of this page, collected by Google)', 'sh-speed-optimizer' ) ) );
					const fieldKeys = measuredMetricKeys( latest.field );
					out.push( isObj( latest.field ) && fieldKeys.length
						? metricList( latest.field, fieldKeys, true )
						: h( 'p', { class: 'shso-empty' }, __( 'Field data unavailable', 'sh-speed-optimizer' ) ) );

					out.push( h( 'p', { class: 'shso-subhead' }, __( 'Lab data (simulated test by Google)', 'sh-speed-optimizer' ) ) );
					const labKeys = measuredMetricKeys( latest.lab );
					out.push( labKeys.length ? metricList( latest.lab, labKeys, true ) : h( 'p', { class: 'shso-empty' }, __( 'Not measured yet', 'sh-speed-optimizer' ) ) );
				} else {
					out.push( h( 'p', { class: 'shso-empty' }, __( 'No PageSpeed test has been run yet.', 'sh-speed-optimizer' ) ) );
				}

				const pairs = arr( p.before_after ).filter( isObj );
				if ( pairs.length ) {
					out.push( h( 'p', { class: 'shso-subhead' }, __( 'Before / After', 'sh-speed-optimizer' ) ) );
					const sourceLine = ( source, date ) => {
						const parts = [ str( source ), str( date, fmtDate( date ) ) ].filter( Boolean );
						return parts.length ? h( 'span', { class: 'shso-muted shso-small', style: 'display:block' }, parts.join( ' · ' ) ) : null;
					};
					out.push( h( 'div', { class: 'shso-table-wrap' }, h( 'table', { class: 'shso-table' },
						h( 'thead', {}, h( 'tr', {},
							h( 'th', { scope: 'col' }, __( 'Metric', 'sh-speed-optimizer' ) ),
							h( 'th', { scope: 'col' }, __( 'Before', 'sh-speed-optimizer' ) ),
							h( 'th', { scope: 'col' }, __( 'After', 'sh-speed-optimizer' ) )
						) ),
						h( 'tbody', {}, pairs.map( ( row ) => h( 'tr', {},
							h( 'th', { scope: 'row' }, str( row.label, str( row.metric ) ) ),
							h( 'td', {}, cellText( row.before ), sourceLine( row.before_source, row.before_date ) ),
							h( 'td', {}, cellText( row.after ), sourceLine( row.after_source, row.after_date ) )
						) ) )
					) ) );
				}

				return card( null, out );
			} );
		},

		runPsi( btn, statusEl ) {
			busy( btn, true, __( 'Running test…', 'sh-speed-optimizer' ) );
			statusEl.textContent = __( 'Google is testing your home page. This can take up to a minute.', 'sh-speed-optimizer' );
			api( '/pagespeed', 'POST', { strategy: 'mobile' } ).then( ( res ) => {
				const r = obj( res );
				notify( false === r.ok ? 'error' : 'success', str( r.message, __( 'PageSpeed test finished.', 'sh-speed-optimizer' ) ) );
				return this.load();
			}, ( err ) => {
				busy( btn, false );
				statusEl.textContent = '';
				notify( 'error', errorMessage( err ) );
			} );
		},

		loadDev() {
			this.devLoading = true;
			fill( this.slots.dev, loading( __( 'Loading developer diagnostics…', 'sh-speed-optimizer' ) ) );
			return api( '/diagnostics?developer=1' ).then( ( res ) => {
				this.devLoading = false;
				this.devData = obj( res );
				this.renderDev();
			}, ( err ) => {
				this.devLoading = false;
				fill( this.slots.dev, loadError( err, () => this.loadDev() ) );
			} );
		},

		rawSection( title, value ) {
			if ( ! isObj( value ) && ! Array.isArray( value ) ) {
				return null;
			}
			return h( 'details', { class: 'shso-details shso-details--inner' },
				h( 'summary', { class: 'shso-details__summary' }, h( 'span', { class: 'shso-details__title' }, title ) ),
				h( 'div', { class: 'shso-details__body' }, preBlock( value ) )
			);
		},

		devSection( title, content ) {
			return h( 'div', { class: 'shso-dev-section' }, h( 'h3', { class: 'shso-dev-title' }, title ), content );
		},

		assetTable( entry ) {
			const e = obj( entry );
			const rows = [];
			[ [ 'scripts', 'script' ], [ 'styles', 'style' ] ].forEach( ( pair ) => {
				arr( e[ pair[ 0 ] ] ).forEach( ( asset ) => {
					const a = obj( asset );
					let location = str( a.location );
					if ( ! location && 'boolean' === typeof a.in_footer ) {
						location = a.in_footer ? 'footer' : 'head';
					}
					const blocking = first( a.render_blocking, a.blocking );
					const deps = first( a.dependencies, a.deps );
					rows.push( {
						handle: str( a.handle, '—' ),
						type: str( a.type, pair[ 1 ] ),
						source: str( first( a.src, a.source, a.url ), '—' ),
						size: fmtBytes( first( a.size, a.bytes ) ),
						location: 'footer' === location ? __( 'Footer', 'sh-speed-optimizer' ) : 'head' === location ? __( 'Head', 'sh-speed-optimizer' ) : location || '—',
						blocking: 'boolean' === typeof blocking ? scalarText( blocking ) : '—',
						deps: Array.isArray( deps ) ? deps.join( ', ' ) || __( 'None', 'sh-speed-optimizer' ) : str( deps, '—' ),
					} );
				} );
			} );
			if ( ! rows.length ) {
				return h( 'p', { class: 'shso-empty' }, __( 'No assets recorded.', 'sh-speed-optimizer' ) );
			}
			return rowsTable( rows, [ 'handle', 'type', 'source', 'size', 'location', 'blocking', 'deps' ], {
				handle: __( 'Handle', 'sh-speed-optimizer' ),
				type: __( 'Type', 'sh-speed-optimizer' ),
				source: __( 'Source', 'sh-speed-optimizer' ),
				size: __( 'Size', 'sh-speed-optimizer' ),
				location: __( 'Location', 'sh-speed-optimizer' ),
				blocking: __( 'Render-blocking', 'sh-speed-optimizer' ),
				deps: __( 'Dependencies', 'sh-speed-optimizer' ),
			} );
		},

		perUrl( map, renderer, summaryFn ) {
			const m = obj( map );
			const urls = Object.keys( m );
			if ( ! urls.length ) {
				return h( 'p', { class: 'shso-empty' }, __( 'No data recorded. Run a performance scan first.', 'sh-speed-optimizer' ) );
			}
			return h( 'div', { class: 'shso-stack' }, urls.map( ( url ) => h( 'details', { class: 'shso-details shso-details--inner' },
				h( 'summary', { class: 'shso-details__summary' },
					h( 'span', { class: 'shso-details__title shso-code' }, url ),
					summaryFn ? h( 'span', { class: 'shso-details__hint' }, summaryFn( m[ url ] ) ) : null
				),
				h( 'div', { class: 'shso-details__body' }, renderer( m[ url ] ) )
			) ) );
		},

		renderDev() {
			renderInto( this.slots.dev, () => {
				const d = this.devData;
				const dev = obj( d.developer );
				const pages = arr( obj( d.scan ).pages ).filter( isObj );

				const download = button( __( 'Download diagnostics JSON', 'sh-speed-optimizer' ), () => {
					const date = new Date().toISOString().slice( 0, 10 );
					downloadJson( d, 'shso-diagnostics-' + date + '.json' );
				}, { id: 'shso-download-json' } );

				const pageRows = pages.map( ( p ) => {
					const req = obj( p.requests );
					const bytes = obj( p.bytes );
					return {
						url: str( p.url, '—' ),
						template: str( p.template, '—' ),
						status: first( p.status, '—' ),
						ttfb: null !== num( p.ttfb_ms ) ? fmtNumber( p.ttfb_ms ) + ' ms' : '—',
						generation: null !== num( p.generation_ms ) ? fmtNumber( p.generation_ms ) + ' ms' : '—',
						html: fmtBytes( p.html_bytes ),
						css: fmtBytes( bytes.css ),
						js: fmtBytes( bytes.js ),
						requests: [ 'scripts', 'styles', 'images', 'iframes', 'third_party' ]
							.filter( ( k ) => null !== num( req[ k ] ) )
							.map( ( k ) => k + ': ' + req[ k ] ).join( ', ' ) || '—',
					};
				} );

				const log = arr( dev.log ).filter( isObj );

				return [
					h( 'div', { class: 'shso-row' }, download, h( 'span', { class: 'shso-muted shso-small' }, __( 'The file is created in your browser; nothing is stored on the server.', 'sh-speed-optimizer' ) ) ),
					this.devSection( __( 'Environment', 'sh-speed-optimizer' ), kvTable( dev.environment ) ),
					this.devSection( __( 'Compatibility', 'sh-speed-optimizer' ), kvTable( dev.compatibility ) ),
					this.devSection( __( 'Pages analyzed', 'sh-speed-optimizer' ), pageRows.length
						? rowsTable( pageRows, [ 'url', 'template', 'status', 'ttfb', 'generation', 'html', 'css', 'js', 'requests' ], {
							url: __( 'URL', 'sh-speed-optimizer' ),
							template: __( 'Template', 'sh-speed-optimizer' ),
							status: __( 'Status', 'sh-speed-optimizer' ),
							ttfb: 'TTFB',
							generation: __( 'Generation', 'sh-speed-optimizer' ),
							html: 'HTML',
							css: 'CSS',
							js: 'JS',
							requests: __( 'Requests', 'sh-speed-optimizer' ),
						} )
						: h( 'p', { class: 'shso-empty' }, __( 'No pages analyzed yet.', 'sh-speed-optimizer' ) ) ),
					this.devSection( __( 'Assets per page', 'sh-speed-optimizer' ), this.perUrl( dev.assets, ( entry ) => this.assetTable( entry ), ( entry ) => {
						const e = obj( entry );
						/* translators: 1: number of scripts, 2: number of stylesheets. */
						return sprintf( __( '%1$d scripts, %2$d stylesheets', 'sh-speed-optimizer' ), arr( e.scripts ).length, arr( e.styles ).length );
					} ) ),
					this.devSection( __( 'Database queries per page', 'sh-speed-optimizer' ), this.perUrl( dev.queries, ( entry ) => kvTable( entry ), ( entry ) => {
						const count = num( obj( entry ).count );
						/* translators: %d: number of database queries. */
						return null !== count ? sprintf( _n( '%d query', '%d queries', count, 'sh-speed-optimizer' ), count ) : '';
					} ) ),
					this.devSection( __( 'Cache status', 'sh-speed-optimizer' ), isObj( dev.cache ) || Array.isArray( dev.cache ) ? preBlock( dev.cache ) : h( 'p', { class: 'shso-empty' }, __( 'No data.', 'sh-speed-optimizer' ) ) ),
					isObj( dev.constants ) ? this.devSection( __( 'Constants', 'sh-speed-optimizer' ), kvTable( dev.constants ) ) : null,
					this.rawSection( __( 'Optimization decisions', 'sh-speed-optimizer' ), dev.decisions ),
					this.rawSection( __( 'Engine state', 'sh-speed-optimizer' ), dev.state ),
					this.rawSection( __( 'Browser measurements per template', 'sh-speed-optimizer' ), dev.page_data ),
					this.devSection( __( 'Debug log', 'sh-speed-optimizer' ), log.length
						? rowsTable( log )
						: h( 'p', { class: 'shso-empty' }, __( 'The debug log is empty. Turn on debug logging in Settings to record technical details.', 'sh-speed-optimizer' ) ) ),
				];
			} );
		},
	};

	// ------------------------------------------------------------------
	// Cache page
	// ------------------------------------------------------------------

	const CachePage = {
		data: null,

		init() {
			this.slots = {
				page: document.getElementById( 'shso-cache-page' ),
				browser: document.getElementById( 'shso-cache-browser' ),
				object: document.getElementById( 'shso-cache-object' ),
			};
			this.load();
			Jobs.resumeIfActive( () => this.load() );
		},

		load() {
			return api( '/cache' ).then( ( res ) => {
				this.data = obj( res );
				this.renderPage();
				this.renderBrowser();
				this.renderObject();
			}, ( err ) => {
				fill( this.slots.page, loadError( err, () => this.load() ) );
				fill( this.slots.browser );
				fill( this.slots.object );
			} );
		},

		renderPage() {
			renderInto( this.slots.page, () => {
				const pc = obj( this.data.page_cache );
				const stats = obj( this.data.stats );
				const preload = obj( this.data.preload );
				const out = [];

				if ( 'external' === pc.mode ) {
					out.push( h( 'p', { class: 'shso-row' }, pill( 'info', __( 'Handled by another system', 'sh-speed-optimizer' ) ) ) );
				} else {
					out.push( h( 'p', { class: 'shso-row' }, pill( pc.active ? 'good' : 'unknown', pc.active ? __( 'Active', 'sh-speed-optimizer' ) : __( 'Not active', 'sh-speed-optimizer' ) ) ) );
				}

				switch ( pc.mode ) {
					case 'dropin':
						out.push( h( 'p', {}, h( 'strong', {}, __( 'Fast delivery (advanced-cache.php)', 'sh-speed-optimizer' ) ) ),
							h( 'p', { class: 'shso-muted' }, __( 'Cached pages are delivered before most of WordPress loads.', 'sh-speed-optimizer' ) ) );
						break;
					case 'fallback':
						out.push(
							h( 'p', {}, h( 'strong', {}, __( 'Standard delivery', 'sh-speed-optimizer' ) ) ),
							h( 'p', { class: 'shso-muted' }, __( 'Cached pages are delivered by WordPress. Faster delivery is possible.', 'sh-speed-optimizer' ) ),
							h( 'div', { class: 'shso-row' }, button( __( 'Enable faster cache delivery', 'sh-speed-optimizer' ), ( btn ) => this.enableEarly( btn ), { id: 'shso-enable-early', describedby: 'shso-early-help' } ) ),
							h( 'p', { class: 'shso-muted shso-small', id: 'shso-early-help' }, __( 'Adds define( \'WP_CACHE\', true ); to wp-config.php. A backup is made first.', 'sh-speed-optimizer' ) )
						);
						break;
					case 'external':
						/* translators: %s: name of another plugin or the hosting company. */
						out.push( h( 'p', {}, h( 'strong', {}, sprintf( __( 'Handled by %s', 'sh-speed-optimizer' ), str( pc.handled_by, __( 'another plugin or your host', 'sh-speed-optimizer' ) ) ) ) ) );
						break;
					case 'off':
						out.push( h( 'p', {}, __( 'The page cache is turned off.', 'sh-speed-optimizer' ), ' ', link( __( 'Cache settings', 'sh-speed-optimizer' ), pageUrl( 'settings', '#shso-section-cache' ) ) ) );
						break;
					default:
						break;
				}

				const hitRate = fmtPercent( stats.hit_rate );
				out.push( h( 'dl', { class: 'shso-stat-grid' },
					h( 'div', { class: 'shso-stat' }, h( 'dt', {}, __( 'Cached pages', 'sh-speed-optimizer' ) ), h( 'dd', {}, fmtNumber( pc.files ) ) ),
					h( 'div', { class: 'shso-stat' }, h( 'dt', {}, __( 'Cache size', 'sh-speed-optimizer' ) ), h( 'dd', {}, fmtBytes( pc.bytes ) ) ),
					h( 'div', { class: 'shso-stat' }, h( 'dt', {}, __( 'Hit rate', 'sh-speed-optimizer' ) ), h( 'dd', {}, hitRate || h( 'span', { class: 'shso-small shso-muted' }, __( 'Not enough data yet', 'sh-speed-optimizer' ) ) ) )
				) );

				const urlInput = h( 'input', { type: 'text', inputmode: 'url', id: 'shso-purge-url', class: 'regular-text', placeholder: str( cfg.siteUrl, '/' ), autocomplete: 'off', spellcheck: 'false' } );
				const purgeForm = h( 'form', { class: 'shso-inline', novalidate: true },
					h( 'label', { for: 'shso-purge-url' }, __( 'Purge a single page:', 'sh-speed-optimizer' ) ),
					urlInput,
					h( 'button', { type: 'submit', class: 'button' }, __( 'Purge page', 'sh-speed-optimizer' ) )
				);
				purgeForm.addEventListener( 'submit', ( e ) => {
					e.preventDefault();
					const url = urlInput.value.trim();
					if ( ! /^(https?:\/\/|\/)/i.test( url ) ) {
						notify( 'error', __( 'Enter the address of a page on this site, e.g. https://example.com/about/ or /about/.', 'sh-speed-optimizer' ) );
						urlInput.focus();
						return;
					}
					this.purge( { scope: 'url', url }, $( 'button', purgeForm ) );
				} );

				out.push( h( 'div', { class: 'shso-row' },
					button( __( 'Purge entire cache', 'sh-speed-optimizer' ), ( btn ) => this.purge( { scope: 'all' }, btn ), { id: 'shso-purge-all' } )
				), purgeForm );

				// Preloading.
				const done = num( preload.done );
				const queued = num( preload.queued );
				let preloadText;
				if ( preload.running ) {
					preloadText = null !== done && null !== queued
						/* translators: 1: pages preloaded, 2: pages waiting. */
						? sprintf( __( 'Preloading in progress: %1$d done, %2$d waiting.', 'sh-speed-optimizer' ), done, queued )
						: __( 'Preloading in progress.', 'sh-speed-optimizer' );
				} else if ( fmtDate( preload.last_run ) ) {
					/* translators: %s: date of the last preload. */
					preloadText = sprintf( __( 'Last preload: %s', 'sh-speed-optimizer' ), fmtDate( preload.last_run ) );
				} else {
					preloadText = __( 'The cache has not been preloaded yet.', 'sh-speed-optimizer' );
				}
				out.push(
					h( 'p', { class: 'shso-subhead' }, __( 'Preloading', 'sh-speed-optimizer' ) ),
					h( 'div', { class: 'shso-row' },
						h( 'span', {}, preloadText ),
						button( __( 'Preload now', 'sh-speed-optimizer' ), ( btn ) => this.preload( btn ), { id: 'shso-preload', disabled: !! preload.running } )
					)
				);
				return out;
			} );
		},

		renderBrowser() {
			renderInto( this.slots.browser, () => {
				const b = obj( this.data.browser_cache );
				const out = [ h( 'p', { class: 'shso-row' }, pill( b.active ? 'good' : 'unknown', b.active ? __( 'Active', 'sh-speed-optimizer' ) : __( 'Not active', 'sh-speed-optimizer' ) ) ) ];
				if ( b.configured_by_server ) {
					out.push( h( 'p', {}, __( 'Your server already tells browsers to keep files such as images, CSS and JavaScript.', 'sh-speed-optimizer' ) ) );
				} else if ( b.rules_installed ) {
					out.push( h( 'p', {}, __( 'SH Speed added browser caching rules to your .htaccess file.', 'sh-speed-optimizer' ) ) );
				} else if ( ! b.active ) {
					out.push( h( 'p', { class: 'shso-muted' }, __( 'Browsers may download files such as images, CSS and JavaScript again more often than needed.', 'sh-speed-optimizer' ) ) );
				}
				if ( str( b.server ) ) {
					/* translators: %s: web server software, e.g. Apache. */
					out.push( h( 'p', { class: 'shso-muted shso-small' }, sprintf( __( 'Web server: %s', 'sh-speed-optimizer' ), str( b.server ) ) ) );
				}
				if ( b.can_apply ) {
					out.push(
						h( 'div', { class: 'shso-row' }, button( __( 'Allow and apply browser caching rules', 'sh-speed-optimizer' ), ( btn ) => this.applyBrowserCache( btn ), { primary: true, id: 'shso-apply-browser-cache', describedby: 'shso-browser-help' } ) ),
						h( 'p', { class: 'shso-muted shso-small', id: 'shso-browser-help' }, __( 'Adds browser caching rules to your .htaccess file. You can undo this anytime.', 'sh-speed-optimizer' ) )
					);
				}
				const snippet = str( b.nginx_snippet );
				if ( snippet && /nginx/i.test( str( b.server ) ) ) {
					const copyBtn = button( __( 'Copy', 'sh-speed-optimizer' ), ( btn ) => copyText( snippet, btn ) );
					out.push( h( 'details', { class: 'shso-details shso-details--inner' },
						h( 'summary', { class: 'shso-details__summary' }, h( 'span', { class: 'shso-details__title' }, __( 'Nginx configuration snippet', 'sh-speed-optimizer' ) ) ),
						h( 'div', { class: 'shso-details__body' },
							h( 'p', { class: 'shso-muted' }, __( 'Nginx does not read .htaccess files. Ask your host to add this to your site\'s configuration.', 'sh-speed-optimizer' ) ),
							preBlock( snippet ),
							h( 'div', {}, copyBtn )
						)
					) );
				}
				return out;
			} );
		},

		renderObject() {
			renderInto( this.slots.object, () => {
				const o = obj( this.data.object_cache );
				let label = __( 'Not active', 'sh-speed-optimizer' );
				if ( o.active ) {
					/* translators: %s: object cache type, e.g. Redis. */
					label = str( o.type ) ? sprintf( __( 'Active (%s)', 'sh-speed-optimizer' ), str( o.type ) ) : __( 'Active', 'sh-speed-optimizer' );
				}
				return [
					h( 'p', { class: 'shso-row' }, pill( o.active ? 'good' : 'unknown', label ) ),
					str( o.recommendation ) ? h( 'p', {}, str( o.recommendation ) ) : null,
				];
			} );
		},

		purge( data, btn ) {
			busy( btn, true );
			api( '/cache/purge', 'POST', data ).then( ( res ) => {
				const r = obj( res );
				busy( btn, false );
				notify( false === r.ok ? 'error' : 'success', str( r.message, __( 'Cache purged.', 'sh-speed-optimizer' ) ) );
				this.load();
			}, ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		preload( btn ) {
			busy( btn, true );
			api( '/cache/preload', 'POST' ).then( ( res ) => {
				const r = obj( res );
				busy( btn, false );
				notify( false === r.ok ? 'error' : 'success', str( r.message, __( 'Preloading started.', 'sh-speed-optimizer' ) ) );
				this.load();
			}, ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		enableEarly( btn ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'SH Speed will add define( \'WP_CACHE\', true ); to wp-config.php. A backup of wp-config.php is made first. Continue?', 'sh-speed-optimizer' ) ) ) {
				return;
			}
			busy( btn, true );
			api( '/cache/enable-early-delivery', 'POST' ).then( ( res ) => {
				const r = obj( res );
				busy( btn, false );
				notify( false === r.ok ? 'error' : 'success', str( r.message, __( 'Faster cache delivery is enabled.', 'sh-speed-optimizer' ) ) );
				this.load();
			}, ( err ) => {
				busy( btn, false );
				notify( 'error', errorMessage( err ) );
			} );
		},

		applyBrowserCache( btn ) {
			busy( btn, true );
			api( '/settings', 'POST', { settings: { allow_server_config: true } } )
				.then( () => api( '/optimizations/browser_cache', 'POST', { mode: 'on' } ) )
				.then( ( res ) => {
					const r = obj( res );
					busy( btn, false );
					if ( isObj( r.job ) ) {
						return Jobs.run( r.job, () => this.load() );
					}
					notify( false === r.ok ? 'error' : 'success', str( r.message, __( 'Browser caching rules applied.', 'sh-speed-optimizer' ) ) );
					return this.load();
				} )
				.catch( ( err ) => {
					busy( btn, false );
					notify( 'error', errorMessage( err ) );
				} );
		},
	};

	// ------------------------------------------------------------------
	// Settings page
	// ------------------------------------------------------------------

	const SETTINGS_SECTIONS = [
		{
			id: 'general',
			title: __( 'General', 'sh-speed-optimizer' ),
			fields: [
				{ key: 'auto_optimize', type: 'toggle', label: __( 'Automatic Optimization', 'sh-speed-optimizer' ), help: __( 'Safe optimizations are detected and applied automatically.', 'sh-speed-optimizer' ) },
				{ key: 'safe_mode', type: 'toggle', label: __( 'Safe Mode', 'sh-speed-optimizer' ), help: __( 'Runs only the safest optimizations. Turn this on if something on your site looks wrong.', 'sh-speed-optimizer' ) },
			],
		},
		{
			id: 'cache',
			title: __( 'Cache', 'sh-speed-optimizer' ),
			fields: [
				{ key: 'page_cache', type: 'toggle', label: __( 'Page Cache', 'sh-speed-optimizer' ), help: __( 'Stores ready-made copies of your pages so visitors get them faster.', 'sh-speed-optimizer' ) },
				{ key: 'cache_lifespan', type: 'number', min: 1, max: 720, suffix: __( 'hours', 'sh-speed-optimizer' ), label: __( 'Cache Lifespan', 'sh-speed-optimizer' ), help: __( 'How long a cached page is kept before it is rebuilt.', 'sh-speed-optimizer' ) },
				{
					key: 'cache_mobile',
					type: 'select',
					label: __( 'Mobile cache', 'sh-speed-optimizer' ),
					options: [
						[ 'auto', __( 'Automatic', 'sh-speed-optimizer' ) ],
						[ 'on', __( 'Always separate', 'sh-speed-optimizer' ) ],
						[ 'off', __( 'Never', 'sh-speed-optimizer' ) ],
					],
					help: __( 'Automatic keeps a separate copy for phones only when your theme needs it.', 'sh-speed-optimizer' ),
				},
				{ key: 'preload', type: 'toggle', label: __( 'Cache preloading', 'sh-speed-optimizer' ), help: __( 'Builds the cache in the background so the first visitor also gets a fast page.', 'sh-speed-optimizer' ) },
				{ key: 'preload_limit', type: 'number', min: 0, max: 500, suffix: __( 'pages', 'sh-speed-optimizer' ), label: __( 'Preload page limit', 'sh-speed-optimizer' ), help: __( 'The maximum number of pages that are preloaded.', 'sh-speed-optimizer' ) },
			],
		},
		{
			id: 'optimization',
			title: __( 'Optimization', 'sh-speed-optimizer' ),
			fields: [
				{ key: 'safe_optimizations', type: 'toggle', label: __( 'Safe Optimizations', 'sh-speed-optimizer' ), help: __( 'Allows optimizations that were checked to be safe for your site.', 'sh-speed-optimizer' ) },
				{ key: 'advanced_optimizations', type: 'toggle', label: __( 'Advanced Optimizations', 'sh-speed-optimizer' ), warning: __( 'Enables experimental optimizations that are never applied automatically.', 'sh-speed-optimizer' ) },
			],
		},
		{
			id: 'exclusions',
			title: __( 'Exclusions', 'sh-speed-optimizer' ),
			intro: __( 'One entry per line.', 'sh-speed-optimizer' ),
			fields: [
				{ key: 'exclude_urls', type: 'lines', label: __( 'URL exclusions', 'sh-speed-optimizer' ), help: __( 'Pages that are never cached or optimized, e.g. /checkout/ or /landing/*.', 'sh-speed-optimizer' ) },
				{ key: 'exclude_css', type: 'lines', label: __( 'CSS exclusions', 'sh-speed-optimizer' ), help: __( 'Stylesheets (file name or part of the address) that are never changed.', 'sh-speed-optimizer' ) },
				{ key: 'exclude_js', type: 'lines', label: __( 'JS exclusions', 'sh-speed-optimizer' ), help: __( 'Scripts (file name or part of the address) that are never deferred, delayed or minified.', 'sh-speed-optimizer' ) },
				{ key: 'exclude_cookies', type: 'lines', label: __( 'Cookie exclusions', 'sh-speed-optimizer' ), help: __( 'Visitors who have one of these cookies never get cached pages.', 'sh-speed-optimizer' ) },
			],
		},
		{
			id: 'measurement',
			title: __( 'Measurement', 'sh-speed-optimizer' ),
			fields: [
				{ key: 'psi_api_key', type: 'apikey', label: __( 'PageSpeed Insights API key', 'sh-speed-optimizer' ), help: __( 'Optional. Shows Google\'s PageSpeed measurements on the Diagnostics page.', 'sh-speed-optimizer' ) },
				{ key: 'rum', type: 'toggle', label: __( 'Real-user Core Web Vitals', 'sh-speed-optimizer' ), help: __( 'Collects anonymous page speed measurements from a small sample of visitors. No cookies, no personal data.', 'sh-speed-optimizer' ) },
			],
		},
		{
			id: 'permissions',
			title: __( 'Permissions', 'sh-speed-optimizer' ),
			fields: [
				{ key: 'allow_server_config', type: 'toggle', label: __( 'Allow browser caching rules in .htaccess', 'sh-speed-optimizer' ), help: __( 'SH Speed may add browser caching rules to your server configuration. You can undo this anytime.', 'sh-speed-optimizer' ) },
				{ key: 'localize_fonts', type: 'toggle', label: __( 'Allow downloading Google Fonts to your server', 'sh-speed-optimizer' ), help: __( 'Serves Google Fonts from your own site, so visitors\' browsers do not need to contact Google for them.', 'sh-speed-optimizer' ) },
			],
		},
		{
			id: 'developer',
			title: __( 'Developer', 'sh-speed-optimizer' ),
			fields: [
				{ key: 'debug', type: 'toggle', label: __( 'Debug logging', 'sh-speed-optimizer' ), help: __( 'Records technical details for troubleshooting. They appear under Diagnostics → Developer Diagnostics.', 'sh-speed-optimizer' ) },
				{ type: 'note', text: __( 'Developers can also turn on debug logging with define( \'SHSO_DEBUG\', true ); in wp-config.php. In an emergency, define( \'SHSO_SAFE_MODE\', true ); bypasses all optimizations and the page cache.', 'sh-speed-optimizer' ) },
			],
		},
	];

	const SettingsPage = {
		data: null,
		settings: {},
		locked: [],
		meta: {},
		inputs: {},
		clearKey: false,
		saving: false,

		init() {
			this.form = document.getElementById( 'shso-settings-form' );
			this.slot = document.getElementById( 'shso-settings' );
			if ( this.form ) {
				this.form.addEventListener( 'submit', ( e ) => {
					e.preventDefault();
					this.save();
				} );
				this.form.addEventListener( 'input', () => this.updateDirty() );
				this.form.addEventListener( 'change', () => this.updateDirty() );
			}
			window.addEventListener( 'beforeunload', ( e ) => {
				if ( this.isDirty() && ! this.saving ) {
					e.preventDefault();
					e.returnValue = '';
				}
			} );
			this.load();
		},

		load() {
			return api( '/settings' ).then( ( res ) => this.setData( res ), ( err ) => fill( this.slot, loadError( err, () => this.load() ) ) );
		},

		setData( res ) {
			const d = obj( res );
			this.data = d;
			this.settings = obj( d.settings );
			this.locked = arr( d.locked ).map( String );
			this.meta = obj( d.meta );
			this.clearKey = false;
			this.render();
		},

		fields() {
			const list = [];
			SETTINGS_SECTIONS.forEach( ( section ) => section.fields.forEach( ( field ) => {
				if ( field.key && this.hasField( field ) ) {
					list.push( field );
				}
			} ) );
			return list;
		},

		hasField( field ) {
			return 'apikey' === field.type || Object.prototype.hasOwnProperty.call( this.settings, field.key );
		},

		isLocked( key ) {
			return -1 !== this.locked.indexOf( key );
		},

		initial( field ) {
			const value = this.settings[ field.key ];
			switch ( field.type ) {
				case 'toggle':
					return !! value;
				case 'number':
					return num( value );
				case 'lines':
					if ( 'string' === typeof value ) {
						return value.split( /\r\n|\r|\n/ ).map( ( line ) => line.trim() ).filter( Boolean );
					}
					return arr( value ).map( String );
				case 'select':
					return str( value );
				default:
					return '';
			}
		},

		current( field ) {
			const el = this.inputs[ field.key ];
			if ( ! el ) {
				return this.initial( field );
			}
			switch ( field.type ) {
				case 'toggle':
					return !! el.checked;
				case 'number': {
					const n = parseInt( el.value, 10 );
					return isNaN( n ) ? null : n;
				}
				case 'lines':
					return el.value.split( /\r\n|\r|\n/ ).map( ( line ) => line.trim() ).filter( Boolean );
				case 'select':
					return el.value;
				case 'apikey':
					return el.value.trim();
				default:
					return el.value;
			}
		},

		changed( field ) {
			if ( this.isLocked( field.key ) ) {
				return false;
			}
			if ( 'apikey' === field.type ) {
				return '' !== this.current( field ) || this.clearKey;
			}
			return JSON.stringify( this.current( field ) ) !== JSON.stringify( this.initial( field ) );
		},

		isDirty() {
			if ( ! this.data ) {
				return false;
			}
			return this.fields().some( ( field ) => this.changed( field ) );
		},

		updateDirty() {
			if ( ! this.stateEl ) {
				return;
			}
			const dirty = this.isDirty();
			this.stateEl.textContent = dirty ? __( 'Unsaved changes', 'sh-speed-optimizer' ) : __( 'No unsaved changes', 'sh-speed-optimizer' );
			this.stateEl.classList.toggle( 'is-dirty', dirty );
			if ( this.saveBtn && ! this.saving ) {
				this.saveBtn.disabled = ! dirty;
			}
		},

		fieldNode( field ) {
			const id = 'shso-setting-' + field.key;
			const helpId = id + '-help';
			const locked = this.isLocked( field.key );
			const describedBy = [ field.help || field.warning ? helpId : null, locked ? id + '-locked' : null ].filter( Boolean ).join( ' ' ) || null;
			const help = field.help ? h( 'p', { class: 'shso-field__help', id: helpId }, field.help ) : field.warning ? h( 'p', { class: 'shso-field__warning', id: helpId }, field.warning ) : null;
			const lockNote = locked ? h( 'p', { class: 'shso-field__locked', id: id + '-locked' }, __( 'Set by your network administrator', 'sh-speed-optimizer' ) ) : null;
			let control;

			switch ( field.type ) {
				case 'toggle': {
					const input = h( 'input', { type: 'checkbox', role: 'switch', class: 'shso-switch__input', id, checked: !! this.initial( field ), disabled: locked, 'aria-describedby': describedBy } );
					this.inputs[ field.key ] = input;
					control = h( 'label', { class: 'shso-switch', for: id }, input, h( 'span', { class: 'shso-switch__track', 'aria-hidden': 'true' } ), h( 'span', { class: 'shso-switch__label' }, field.label ) );
					return h( 'div', { class: 'shso-field' }, control, help, lockNote );
				}
				case 'number': {
					const value = this.initial( field );
					const input = h( 'input', { type: 'number', id, min: String( field.min ), max: String( field.max ), step: '1', required: true, value: null === value ? '' : String( value ), disabled: locked, 'aria-describedby': describedBy } );
					this.inputs[ field.key ] = input;
					control = h( 'span', { class: 'shso-inline' }, input, field.suffix ? h( 'span', { class: 'shso-muted' }, field.suffix ) : null );
					break;
				}
				case 'select': {
					const input = h( 'select', { id, disabled: locked, 'aria-describedby': describedBy }, field.options.map( ( o ) => h( 'option', { value: o[ 0 ] }, o[ 1 ] ) ) );
					input.value = this.initial( field ) || field.options[ 0 ][ 0 ];
					this.inputs[ field.key ] = input;
					control = input;
					break;
				}
				case 'lines': {
					const input = h( 'textarea', { id, rows: '4', spellcheck: 'false', disabled: locked, 'aria-describedby': describedBy } );
					input.value = this.initial( field ).join( '\n' );
					this.inputs[ field.key ] = input;
					control = input;
					break;
				}
				case 'apikey':
					control = this.apiKeyControl( field, id, describedBy, locked );
					break;
				default:
					return null;
			}

			return h( 'div', { class: 'shso-field' },
				h( 'label', { class: 'shso-field__label', for: id }, field.label ),
				control,
				help,
				lockNote
			);
		},

		apiKeyControl( field, id, describedBy, locked ) {
			const isSet = !! this.meta.psi_api_key_set;
			const input = h( 'input', {
				type: 'password',
				id,
				class: 'shso-key-input',
				autocomplete: 'new-password',
				spellcheck: 'false',
				placeholder: isSet ? __( 'A key is saved. Enter a new key to replace it.', 'sh-speed-optimizer' ) : __( 'Paste your API key', 'sh-speed-optimizer' ),
				disabled: locked,
				'aria-describedby': describedBy,
			} );
			this.inputs[ field.key ] = input;
			const toggle = h( 'button', { type: 'button', class: 'button', 'aria-pressed': 'false', 'aria-controls': id, disabled: locked }, __( 'Show', 'sh-speed-optimizer' ) );
			toggle.addEventListener( 'click', () => {
				const show = 'password' === input.type;
				input.type = show ? 'text' : 'password';
				toggle.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
				toggle.textContent = show ? __( 'Hide', 'sh-speed-optimizer' ) : __( 'Show', 'sh-speed-optimizer' );
			} );
			const status = h( 'span', { class: 'shso-muted shso-small', 'aria-live': 'polite' }, isSet ? __( 'A key is saved.', 'sh-speed-optimizer' ) : __( 'No key saved.', 'sh-speed-optimizer' ) );
			const removeBtn = isSet ? button( __( 'Remove key', 'sh-speed-optimizer' ), ( btn ) => {
				this.clearKey = true;
				input.value = '';
				btn.disabled = true;
				status.textContent = __( 'The key will be removed when you save.', 'sh-speed-optimizer' );
				this.updateDirty();
			}, { link: true, danger: true, disabled: locked } ) : null;
			return h( 'div', { class: 'shso-stack' },
				h( 'span', { class: 'shso-inline' }, input, toggle ),
				h( 'span', { class: 'shso-inline' }, status, removeBtn )
			);
		},

		render() {
			this.inputs = {};
			renderInto( this.slot, () => {
				const sections = SETTINGS_SECTIONS.map( ( section ) => {
					const nodes = section.fields.map( ( field ) => {
						if ( 'note' === field.type ) {
							return h( 'p', { class: 'shso-field shso-field__help' }, field.text );
						}
						return this.hasField( field ) ? this.fieldNode( field ) : null;
					} ).filter( Boolean );
					if ( ! nodes.filter( ( n ) => ! n.classList.contains( 'shso-field__help' ) ).length ) {
						return null;
					}
					return h( 'fieldset', { class: 'shso-settings__section', id: 'shso-section-' + section.id },
						h( 'legend', {}, section.title ),
						section.intro ? h( 'p', { class: 'shso-field__help' }, section.intro ) : null,
						nodes
					);
				} ).filter( Boolean );

				this.stateEl = h( 'span', { class: 'shso-savebar__state', 'aria-live': 'polite' } );
				this.saveBtn = h( 'button', { type: 'submit', class: 'button button-primary shso-btn-lg', id: 'shso-settings-save' }, __( 'Save Settings', 'sh-speed-optimizer' ) );
				return [ sections, h( 'div', { class: 'shso-savebar' }, this.stateEl, this.saveBtn ) ];
			} );
			this.updateDirty();
			if ( /^#shso-section-/.test( window.location.hash ) && ! this.scrolled ) {
				this.scrolled = true;
				const target = document.getElementById( window.location.hash.slice( 1 ) );
				if ( target ) {
					target.scrollIntoView();
				}
			}
		},

		save() {
			if ( this.saving || ! this.data ) {
				return;
			}
			const changes = {};
			let invalid = null;
			this.fields().forEach( ( field ) => {
				if ( ! this.changed( field ) ) {
					return;
				}
				const el = this.inputs[ field.key ];
				if ( 'number' === field.type ) {
					const value = this.current( field );
					if ( null === value || value < field.min || value > field.max ) {
						invalid = invalid || { el, field };
						return;
					}
				}
				if ( 'apikey' === field.type ) {
					const key = this.current( field );
					if ( key ) {
						changes.psi_api_key = key;
					}
					return;
				}
				changes[ field.key ] = this.current( field );
			} );

			if ( invalid ) {
				/* translators: 1: setting name, 2: minimum, 3: maximum. */
				notify( 'error', sprintf( __( '%1$s must be a number between %2$d and %3$d.', 'sh-speed-optimizer' ), invalid.field.label, invalid.field.min, invalid.field.max ) );
				if ( invalid.el ) {
					invalid.el.focus();
				}
				return;
			}

			const payload = { settings: changes };
			if ( this.clearKey && ! changes.psi_api_key ) {
				payload.clear_psi_api_key = true;
			}
			if ( ! Object.keys( changes ).length && ! payload.clear_psi_api_key ) {
				this.updateDirty();
				return;
			}

			this.saving = true;
			busy( this.saveBtn, true, __( 'Saving…', 'sh-speed-optimizer' ) );
			api( '/settings', 'POST', payload ).then( ( res ) => {
				this.saving = false;
				const r = obj( res );
				notify( 'success', __( 'Settings saved.', 'sh-speed-optimizer' ) );
				if ( isObj( r.settings ) ) {
					this.setData( r );
				} else {
					this.load();
				}
				if ( this.saveBtn ) {
					this.saveBtn.focus();
				}
			}, ( err ) => {
				this.saving = false;
				busy( this.saveBtn, false );
				this.updateDirty();
				notify( 'error', errorMessage( err ) );
			} );
		},
	};

	// ------------------------------------------------------------------
	// Boot
	// ------------------------------------------------------------------

	const PAGES = {
		overview: Overview,
		optimization: OptimizationPage,
		diagnostics: DiagnosticsPage,
		cache: CachePage,
		settings: SettingsPage,
	};

	function boot() {
		const wrap = $( '.shso-wrap' );
		const page = str( cfg.page, wrap ? str( wrap.getAttribute( 'data-shso-page' ) ) : '' );
		const controller = PAGES[ page ];
		if ( ! controller ) {
			return;
		}
		if ( ! wpGlobal.apiFetch ) {
			const app = document.getElementById( 'shso-app' );
			if ( app ) {
				fill( app, banner( 'poor', __( 'A required WordPress script could not be loaded. Please reload the page.', 'sh-speed-optimizer' ), null, { role: 'alert' } ) );
			}
			return;
		}
		try {
			controller.init();
		} catch ( e ) {
			if ( window.console ) {
				window.console.error( e ); // eslint-disable-line no-console
			}
			notify( 'error', __( 'This page could not be displayed. Please reload the page.', 'sh-speed-optimizer' ) );
		}
	}

	// Small read-only API for tests and support tooling.
	window.SHSOAdmin = { version: str( cfg.version ), esc, jobs: Jobs };

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
