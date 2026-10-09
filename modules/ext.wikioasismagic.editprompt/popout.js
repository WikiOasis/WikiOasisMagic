( function () {
	'use strict';

	const icons = require( './icons.json' );
	const SVG_NS = 'http://www.w3.org/2000/svg';
	const GAP = 12;
	const MARGIN = 8;
	const ARROW_WIDTH = 16;
	const ARROW_INSET = 20;
	const LEAVE = 200;
	const DESCRIPTION_ID = 'wo-editprompt-text';
	const reducedMotion = !!window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function element( tag, className, text ) {
		const node = document.createElement( tag );
		node.className = className;
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function icon( name, className ) {
		const paths = icons[ name ];
		const span = element( 'span', 'cdx-icon ' + className );
		span.setAttribute( 'aria-hidden', 'true' );
		span.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">' +
			( typeof paths === 'string' ? paths : '' ) + '</svg>';
		return span;
	}

	function arrow() {
		const svg = document.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'class', 'wo-editprompt__arrow' );
		svg.setAttribute( 'width', String( ARROW_WIDTH ) );
		svg.setAttribute( 'height', '9' );
		svg.setAttribute( 'viewBox', '0 0 16 9' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		const path = document.createElementNS( SVG_NS, 'path' );
		path.setAttribute( 'd', 'M0 9 8 1 16 9' );
		svg.appendChild( path );
		return svg;
	}

	function isPinned( node ) {
		for ( let current = node; current && current !== document.body; current = current.parentElement ) {
			const position = window.getComputedStyle( current ).position;
			if ( position === 'fixed' || position === 'sticky' ) {
				return true;
			}
		}
		return false;
	}

	function getOrigin() {
		for ( const node of [ document.body, document.documentElement ] ) {
			if ( window.getComputedStyle( node ).position !== 'static' ) {
				const rect = node.getBoundingClientRect();
				return { left: rect.left + node.clientLeft, top: rect.top + node.clientTop };
			}
		}
		return { left: -window.scrollX, top: -window.scrollY };
	}

	function setDescribedBy( link, add ) {
		const ids = ( link.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ )
			.filter( ( id ) => id && id !== DESCRIPTION_ID );
		if ( add ) {
			ids.push( DESCRIPTION_ID );
		}
		if ( ids.length ) {
			link.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		} else {
			link.removeAttribute( 'aria-describedby' );
		}
	}

	function open( anchor, handlers ) {
		const pinned = isPinned( anchor.tab );
		const popout = element( 'div', 'wo-editprompt' );
		popout.setAttribute( 'role', 'dialog' );
		popout.setAttribute( 'aria-modal', 'false' );
		popout.setAttribute( 'aria-labelledby', 'wo-editprompt-title' );
		popout.setAttribute( 'aria-describedby', DESCRIPTION_ID );
		popout.tabIndex = -1;
		popout.style.position = pinned ? 'fixed' : 'absolute';

		const pointer = arrow();

		const header = element( 'div', 'wo-editprompt__header' );
		const title = element( 'div', 'wo-editprompt__title', mw.msg( 'wikioasismagic-editprompt-title' ) );
		title.id = 'wo-editprompt-title';
		const dismiss = element( 'button', 'cdx-button cdx-button--weight-quiet cdx-button--icon-only wo-editprompt__close' );
		dismiss.type = 'button';
		dismiss.title = mw.msg( 'wikioasismagic-editprompt-close' );
		dismiss.setAttribute( 'aria-label', mw.msg( 'wikioasismagic-editprompt-close' ) );
		dismiss.appendChild( icon( 'cdxIconClose', 'wo-editprompt__close-icon' ) );
		header.append( icon( 'cdxIconEdit', 'wo-editprompt__icon' ), title, dismiss );

		const text = element( 'p', 'wo-editprompt__text', mw.msg( 'wikioasismagic-editprompt-text' ) );
		text.id = DESCRIPTION_ID;

		const actions = element( 'div', 'wo-editprompt__actions' );
		const edit = element(
			'a',
			'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled ' +
				'cdx-button--action-progressive cdx-button--weight-primary',
			mw.msg( 'wikioasismagic-editprompt-edit' )
		);
		edit.href = anchor.link.href;
		const later = element( 'button', 'cdx-button', mw.msg( 'wikioasismagic-editprompt-later' ) );
		later.type = 'button';
		actions.append( edit, later );

		popout.append( pointer, header, text, actions );

		let closed = false;
		let frame = null;
		let observer = null;

		function place() {
			const viewport = document.documentElement.clientWidth;
			popout.style.maxWidth = Math.max( viewport - 2 * MARGIN, 0 ) + 'px';

			const rect = anchor.tab.getBoundingClientRect();
			const width = popout.offsetWidth;
			const height = popout.offsetHeight;
			const center = rect.left + rect.width / 2;
			const left = Math.max( MARGIN, Math.min( center - width / 2, viewport - width - MARGIN ) );
			const above = rect.bottom + GAP + height > window.innerHeight && rect.top - GAP - height >= 0;
			const top = above ? rect.top - GAP - height : rect.bottom + GAP;
			const origin = pinned ? { left: 0, top: 0 } : getOrigin();

			popout.style.left = Math.round( left - origin.left ) + 'px';
			popout.style.top = Math.round( top - origin.top ) + 'px';
			popout.classList.toggle( 'wo-editprompt--above', above );
			pointer.style.left = Math.round(
				Math.max( ARROW_INSET, Math.min( center - left, width - ARROW_INSET ) ) - ARROW_WIDTH / 2
			) + 'px';
		}

		function update() {
			frame = null;
			if ( closed ) {
				return;
			}
			const visible = handlers.isVisible( anchor.tab );
			popout.hidden = !visible;
			if ( visible ) {
				place();
			}
		}

		function schedule() {
			if ( frame === null ) {
				frame = window.requestAnimationFrame( update );
			}
		}

		function onKeydown( e ) {
			const active = document.activeElement;
			if (
				e.key === 'Escape' && !e.defaultPrevented && !popout.hidden &&
				( !active || active === document.body || active === anchor.link || popout.contains( active ) )
			) {
				e.preventDefault();
				handlers.onDismiss();
			}
		}

		function close() {
			if ( closed ) {
				return;
			}
			closed = true;

			const hadFocus = popout.contains( document.activeElement );
			window.removeEventListener( 'resize', schedule );
			window.removeEventListener( 'scroll', schedule );
			document.removeEventListener( 'keydown', onKeydown );
			if ( observer ) {
				observer.disconnect();
			}
			if ( frame !== null ) {
				window.cancelAnimationFrame( frame );
			}
			setDescribedBy( anchor.link, false );

			popout.classList.remove( 'wo-editprompt--open' );
			setTimeout( () => popout.remove(), reducedMotion ? 0 : LEAVE );
			if ( hadFocus && document.body.contains( anchor.link ) ) {
				anchor.link.focus( { preventScroll: true } );
			}
		}

		dismiss.addEventListener( 'click', () => handlers.onDismiss() );
		later.addEventListener( 'click', () => handlers.onDismiss() );
		edit.addEventListener( 'click', ( e ) => handlers.onEdit( e ) );

		document.body.appendChild( popout );
		place();
		setDescribedBy( anchor.link, true );
		window.addEventListener( 'resize', schedule );
		if ( pinned ) {
			window.addEventListener( 'scroll', schedule, { passive: true } );
		}
		document.addEventListener( 'keydown', onKeydown );
		if ( typeof window.ResizeObserver === 'function' ) {
			observer = new window.ResizeObserver( schedule );
			observer.observe( document.body );
		}
		window.requestAnimationFrame( () => {
			if ( !closed ) {
				popout.classList.add( 'wo-editprompt--open' );
			}
		} );

		return { close: close };
	}

	module.exports = { open: open };
}() );
