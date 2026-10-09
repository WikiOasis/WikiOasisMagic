( function () {
	'use strict';

	const cfg = mw.config.get( 'wgWikiOasisEditPrompt' );
	if ( !cfg ) {
		return;
	}

	const events = require( 'ext.wikioasismagic.experiments.events' );
	const STORAGE_KEY = 'wikioasismagic-editprompt';
	const EXPIRY = 365 * 86400;
	const DELAY = 1500;
	const TABS = [ 'ca-ve-edit', 'ca-edit' ];
	const EDIT_ENTRIES = [
		'#ca-ve-edit',
		'#ca-edit',
		'#ca-more-ve-edit',
		'#ca-more-edit',
		'#ca-ve-edit-sticky-header',
		'#ca-edit-sticky-header',
		'.mw-editsection'
	].join( ',' );

	let editClicked = false;
	let popout = null;

	function record( metric ) {
		if ( cfg.track ) {
			events.record( cfg.experiment, metric );
		}
	}

	function readState() {
		try {
			const state = mw.storage.getObject( STORAGE_KEY );
			return state === false ? null : ( state || {} );
		} catch ( e ) {
			return null;
		}
	}

	function writeState( state ) {
		try {
			mw.storage.setObject( STORAGE_KEY, state, EXPIRY );
		} catch ( e ) {}
	}

	function canShow( state ) {
		return !!state && !state.done && ( !cfg.maxShows || ( state.shows || 0 ) < cfg.maxShows );
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

	function findAnchor() {
		for ( const id of TABS ) {
			const tab = document.getElementById( id );
			const link = tab && ( tab.matches( 'a[href]' ) ? tab : tab.querySelector( 'a[href]' ) );
			if ( link && isVisible( tab ) ) {
				return { tab: tab, link: link };
			}
		}
		return null;
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
		if ( cfg.prompt ) {
			const state = readState();
			if ( state && !state.done ) {
				state.done = true;
				writeState( state );
			}
		}
		if ( popout ) {
			popout.close();
			popout = null;
		}
	}

	document.addEventListener( 'click', ( e ) => {
		if ( e.button === 0 && isEditLink( e.target ) ) {
			recordEditClick();
			finish();
		}
	}, true );

	if ( !cfg.prompt || mw.config.get( 'wgWikiOasisWikiPrompt' ) || !canShow( readState() ) ) {
		return;
	}

	mw.hook( 've.activationStart' ).add( finish );

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

	function show() {
		if ( document.hidden ) {
			whenVisible( () => setTimeout( show, DELAY ) );
			return;
		}

		if ( editClicked || popout || isEditing() || !canShow( readState() ) ) {
			return;
		}

		const found = findAnchor();
		if ( !found || !isInViewport( found.tab ) ) {
			return;
		}

		mw.loader.using( 'ext.wikioasismagic.editprompt.popout' ).then( ( req ) => {
			const state = readState();
			const anchor = findAnchor();
			if ( editClicked || popout || isEditing() || !canShow( state ) || !anchor || !isInViewport( anchor.tab ) ) {
				return;
			}

			popout = req( 'ext.wikioasismagic.editprompt.popout' ).open( anchor, {
				isVisible: isVisible,
				onEdit: ( e ) => onEdit( e, anchor ),
				onDismiss: onDismiss
			} );
			state.shows = ( state.shows || 0 ) + 1;
			writeState( state );
			record( 'prompt_shown' );
		} );
	}

	whenVisible( () => setTimeout( show, DELAY ) );
}() );
