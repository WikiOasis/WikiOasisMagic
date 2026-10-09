( function () {
	'use strict';

	const cfg = mw.config.get( 'wgWikiOasisEditPrompt' );
	if ( !cfg ) {
		return;
	}

	const events = require( 'ext.wikioasismagic.experiments.events' );
	const STORAGE_KEY = 'wikioasismagic-editprompt';
	const ASSIGNMENT_KEY = 'wikioasismagic-editprompt-assignment';
	const EXPIRY = 365 * 86400;
	const ASSIGNMENT_EXPIRY = 3600;
	const DELAY = 1500;
	const PREVIEW_PARAMS = [ 'woexperiment', 'woexperimenttoken' ];
	const ANCHORS = [ 'ca-ve-edit', 'ca-edit', 'ca-ve-edit-sticky-header', 'ca-edit-sticky-header' ];
	const EDIT_ENTRIES = [
		'#ca-ve-edit',
		'#ca-edit',
		'#ca-more-ve-edit',
		'#ca-more-edit',
		'#ca-ve-edit-sticky-header',
		'#ca-edit-sticky-header',
		'.mw-editsection'
	].join( ',' );

	let assignment = null;
	let editClicked = false;
	let popout = null;
	let loading = false;
	let stopWatching = null;

	function record( metric ) {
		if ( assignment && assignment.track ) {
			events.record( cfg.experiment, metric );
		}
	}

	function readStorage( key ) {
		try {
			return mw.storage.getObject( key );
		} catch ( e ) {
			return false;
		}
	}

	function writeStorage( key, value, expiry ) {
		try {
			mw.storage.setObject( key, value, expiry );
		} catch ( e ) {}
	}

	function readState() {
		const state = readStorage( STORAGE_KEY );
		return state === false ? null : ( state || {} );
	}

	function canShow( state ) {
		return !!state && !state.done &&
			( !assignment.maxShows || ( state.shows || 0 ) < assignment.maxShows );
	}

	function getPreview() {
		const params = new URLSearchParams( location.search );
		const preview = {};
		for ( const name of PREVIEW_PARAMS ) {
			if ( params.has( name ) ) {
				preview[ name ] = params.get( name );
			}
		}
		return Object.keys( preview ).length ? preview : null;
	}

	function isAssignment( value ) {
		return !!value && typeof value === 'object' &&
			typeof value.prompt === 'boolean' && typeof value.track === 'boolean';
	}

	function loadAssignment() {
		const preview = getPreview();
		const cached = preview ? null : readStorage( ASSIGNMENT_KEY );
		if ( isAssignment( cached ) ) {
			return Promise.resolve( cached );
		}

		return Promise.resolve( new mw.Api().postWithToken( 'csrf', Object.assign( {
			action: 'wikioasiseditprompt',
			title: mw.config.get( 'wgPageName' ),
			formatversion: 2
		}, preview || {} ) ) ).then( ( data ) => {
			const result = data && data.wikioasiseditprompt;
			if ( !isAssignment( result ) ) {
				return null;
			}
			if ( !preview ) {
				writeStorage( ASSIGNMENT_KEY, result, ASSIGNMENT_EXPIRY );
			}
			return result;
		} );
	}

	function isVisible( node ) {
		if ( typeof node.checkVisibility === 'function' && !node.checkVisibility( {
			opacityProperty: true,
			visibilityProperty: true,
			checkOpacity: true,
			checkVisibilityCSS: true
		} ) ) {
			return false;
		}
		const rect = node.getBoundingClientRect();
		return rect.width > 0 && rect.height > 0;
	}

	function isInViewport( node ) {
		const rect = node.getBoundingClientRect();
		return rect.bottom > 0 && rect.right > 0 &&
			rect.top < window.innerHeight && rect.left < document.documentElement.clientWidth;
	}

	function getAnchor( id ) {
		const tab = document.getElementById( id );
		const link = tab && ( tab.matches( 'a[href]' ) ? tab : tab.querySelector( 'a[href]' ) );
		return link ? { tab: tab, link: link } : null;
	}

	function findAnchor() {
		for ( const id of ANCHORS ) {
			const anchor = getAnchor( id );
			if ( anchor && isVisible( anchor.tab ) && isInViewport( anchor.tab ) ) {
				return anchor;
			}
		}
		return null;
	}

	function hasAnchor() {
		return ANCHORS.some( ( id ) => getAnchor( id ) !== null );
	}

	function isEditing() {
		return document.documentElement.classList.contains( 've-activated' );
	}

	function isEditLink( target ) {
		const link = target instanceof Element ? target.closest( 'a[href]' ) : null;
		return !!link && !!link.closest( EDIT_ENTRIES );
	}

	function recordEditClick() {
		if ( !editClicked ) {
			record( 'edit_click' );
		}
		editClicked = true;
	}

	function finish() {
		if ( stopWatching ) {
			stopWatching();
		}
		if ( assignment && assignment.prompt ) {
			const state = readState();
			if ( state && !state.done ) {
				state.done = true;
				writeStorage( STORAGE_KEY, state, EXPIRY );
			}
		}
		if ( popout ) {
			popout.close();
			popout = null;
		}
	}

	function whenVisible( callback ) {
		if ( !document.hidden ) {
			callback();
			return;
		}
		document.addEventListener( 'visibilitychange', function onChange() {
			if ( !document.hidden ) {
				document.removeEventListener( 'visibilitychange', onChange );
				callback();
			}
		} );
	}

	function onEdit( e, anchor ) {
		record( 'prompt_click' );
		recordEditClick();
		finish();
		if ( e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0 ) {
			return;
		}
		e.preventDefault();
		anchor.link.click();
	}

	function onDismiss() {
		record( 'prompt_dismiss' );
		finish();
	}

	function isFinished() {
		return editClicked || !!popout || isEditing() || !canShow( readState() );
	}

	function open() {
		loading = true;
		mw.loader.using( 'ext.wikioasismagic.editprompt.popout' ).then( ( req ) => {
			loading = false;
			if ( isFinished() ) {
				return;
			}
			const current = findAnchor();
			if ( !current ) {
				watch();
				return;
			}

			popout = req( 'ext.wikioasismagic.editprompt.popout' ).open( current, {
				isVisible: isVisible,
				onEdit: ( e ) => onEdit( e, current ),
				onDismiss: onDismiss
			} );
			const state = readState();
			state.shows = ( state.shows || 0 ) + 1;
			writeStorage( STORAGE_KEY, state, EXPIRY );
			record( 'prompt_shown' );
		}, () => {
			loading = false;
		} );
	}

	function check() {
		if ( isFinished() ) {
			return true;
		}
		if ( !findAnchor() ) {
			return false;
		}
		open();
		return true;
	}

	function watch() {
		if ( loading || check() ) {
			return;
		}

		let frame = null;
		function onChange() {
			if ( frame === null ) {
				frame = window.requestAnimationFrame( () => {
					frame = null;
					if ( !loading && check() && stopWatching ) {
						stopWatching();
					}
				} );
			}
		}

		window.addEventListener( 'scroll', onChange, { passive: true } );
		window.addEventListener( 'resize', onChange );
		stopWatching = () => {
			window.removeEventListener( 'scroll', onChange );
			window.removeEventListener( 'resize', onChange );
			if ( frame !== null ) {
				window.cancelAnimationFrame( frame );
			}
			stopWatching = null;
		};
	}

	document.addEventListener( 'click', ( e ) => {
		if ( e.button === 0 && isEditLink( e.target ) ) {
			recordEditClick();
			finish();
		}
	}, true );

	loadAssignment().then( ( result ) => {
		assignment = result;
		if (
			!assignment || !assignment.prompt || editClicked || mw.config.get( 'wgWikiOasisWikiPrompt' ) ||
			!canShow( readState() ) || !hasAnchor()
		) {
			return;
		}

		record( 'prompt_ready' );
		mw.hook( 've.activationStart' ).add( finish );
		whenVisible( () => setTimeout( watch, DELAY ) );
	}, () => {} );
}() );
