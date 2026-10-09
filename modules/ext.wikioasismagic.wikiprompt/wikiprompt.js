( function () {
	'use strict';

	const cfg = mw.config.get( 'wgWikiOasisWikiPrompt' );
	const STORAGE_KEY = 'wikioasismagic-wikiprompt';
	const DELAY = 1200;
	const LEAVE = 250;

	if ( !cfg || !cfg.url || ( !cfg.preview && mw.storage.get( STORAGE_KEY ) ) ) {
		return;
	}

	const events = require( 'ext.wikioasismagic.experiments.events' );
	const icons = require( './icons.json' );
	const reducedMotion = !!window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	let card = null;
	let returnFocus = null;

	function element( tag, className, text ) {
		const node = document.createElement( tag );
		node.className = className;
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function icon( name ) {
		const span = element( 'span', 'cdx-icon cdx-icon--medium' );
		const paths = icons[ name ];
		span.setAttribute( 'aria-hidden', 'true' );
		span.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">' +
			( typeof paths === 'string' ? paths : '' ) + '</svg>';
		return span;
	}

	function record( metric ) {
		events.record( cfg.experiment, metric );
	}

	function onKeydown( e ) {
		const active = document.activeElement;
		if ( e.key === 'Escape' && card && ( !active || active === document.body || card.contains( active ) ) ) {
			e.preventDefault();
			close( 'prompt_dismiss' );
		}
	}

	function close( metric ) {
		if ( !card ) {
			return;
		}

		const closing = card;
		const hadFocus = closing.contains( document.activeElement );
		card = null;
		document.removeEventListener( 'keydown', onKeydown );
		if ( metric ) {
			record( metric );
		}

		closing.classList.remove( 'is-open' );
		setTimeout( () => closing.remove(), reducedMotion ? 0 : LEAVE );
		if ( hadFocus && returnFocus && document.body.contains( returnFocus ) ) {
			returnFocus.focus( { preventScroll: true } );
		}
	}

	function build() {
		const dialog = element( 'div', 'wo-wikiprompt' );
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'false' );
		dialog.setAttribute( 'aria-labelledby', 'wo-wikiprompt-title' );
		dialog.setAttribute( 'aria-describedby', 'wo-wikiprompt-text' );
		dialog.tabIndex = -1;

		const dismiss = element( 'button', 'cdx-button cdx-button--weight-quiet cdx-button--icon-only wo-wikiprompt__close' );
		dismiss.type = 'button';
		dismiss.title = mw.msg( 'wikioasismagic-wikiprompt-close' );
		dismiss.setAttribute( 'aria-label', mw.msg( 'wikioasismagic-wikiprompt-close' ) );
		dismiss.appendChild( icon( 'cdxIconClose' ) );
		dismiss.addEventListener( 'click', () => close( 'prompt_dismiss' ) );

		const badge = element( 'span', 'wo-wikiprompt__badge' );
		badge.appendChild( icon( 'cdxIconAdd' ) );

		const title = element( 'h2', 'wo-wikiprompt__title', mw.msg( 'wikioasismagic-wikiprompt-title' ) );
		title.id = 'wo-wikiprompt-title';

		const text = element( 'p', 'wo-wikiprompt__text', mw.msg( 'wikioasismagic-wikiprompt-text' ) );
		text.id = 'wo-wikiprompt-text';

		const start = element(
			'a',
			'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--action-progressive cdx-button--weight-primary wo-wikiprompt__start',
			mw.msg( 'wikioasismagic-wikiprompt-start' )
		);
		start.href = cfg.url;
		start.addEventListener( 'click', () => {
			record( 'prompt_click' );
			close();
		} );

		const later = element( 'button', 'cdx-button cdx-button--weight-quiet wo-wikiprompt__later', mw.msg( 'wikioasismagic-wikiprompt-later' ) );
		later.type = 'button';
		later.addEventListener( 'click', () => close( 'prompt_dismiss' ) );

		const actions = element( 'div', 'wo-wikiprompt__actions' );
		actions.append( start, later );

		const content = element( 'div', 'wo-wikiprompt__content' );
		content.append( title, text, actions );

		dialog.append( badge, content, dismiss );
		return dialog;
	}

	function show() {
		if ( card || document.querySelector( '.wo-wikiprompt' ) ) {
			return;
		}

		const active = document.activeElement;
		returnFocus = active && active !== document.body ? active : null;

		card = build();
		document.body.appendChild( card );
		document.addEventListener( 'keydown', onKeydown );
		if ( !cfg.preview ) {
			mw.storage.set( STORAGE_KEY, '1' );
		}
		record( 'prompt_shown' );

		const opening = card;
		requestAnimationFrame( () => requestAnimationFrame( () => {
			if ( card !== opening ) {
				return;
			}
			opening.classList.add( 'is-open' );
			if ( !returnFocus ) {
				opening.focus( { preventScroll: true } );
			}
		} ) );
	}

	function schedule() {
		if ( document.hidden ) {
			document.addEventListener( 'visibilitychange', schedule, { once: true } );
			return;
		}
		setTimeout( show, DELAY );
	}

	schedule();
}() );
