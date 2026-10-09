( function () {
	'use strict';

	const cfg = mw.config.get( 'wgWikiOasisSignup' );
	if ( !cfg ) {
		return;
	}

	const MIN_LENGTH = 8;
	const icons = require( './icons.json' );

	function iconSvg( name ) {
		const paths = icons[ name ];
		return typeof paths === 'string' ?
			'<span class="cdx-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">' +
				paths + '</svg></span>' :
			'';
	}

	function addTabs( form ) {
		const tabs = document.createElement( 'nav' );
		tabs.className = 'wo-signup-tabs';
		tabs.setAttribute( 'aria-label', mw.msg( 'wikioasismagic-signup-tabs-label' ) );

		[
			[ 'login', cfg.loginUrl, 'wikioasismagic-signup-tab-login' ],
			[ 'create', cfg.createUrl, 'wikioasismagic-signup-tab-create' ]
		].forEach( ( tab ) => {
			const link = document.createElement( 'a' );
			link.href = tab[ 1 ];
			link.textContent = mw.msg( tab[ 2 ] );
			link.className = 'wo-signup-tabs__tab';
			if ( cfg.mode === tab[ 0 ] ) {
				link.classList.add( 'is-active' );
				link.setAttribute( 'aria-current', 'page' );
			}
			tabs.appendChild( link );
		} );

		form.parentNode.insertBefore( tabs, form );
	}

	function enhancePassword( password, retype, username ) {
		const control = password.closest( '.cdx-field__control' ) || password.parentNode;
		const api = new mw.Api();
		let timer = null;
		let seq = 0;
		let common = null;

		const wrap = password.closest( '.cdx-text-input' );
		if ( wrap ) {
			wrap.classList.add( 'wo-has-reveal' );
			const reveal = document.createElement( 'button' );
			reveal.type = 'button';
			reveal.className = 'cdx-button cdx-button--weight-quiet cdx-button--icon-only wo-reveal';
			reveal.setAttribute( 'aria-pressed', 'false' );
			reveal.setAttribute( 'aria-label', mw.msg( 'wikioasismagic-signup-show-password' ) );
			reveal.innerHTML = iconSvg( 'cdxIconEye' );
			reveal.addEventListener( 'click', () => {
				const show = password.type === 'password';
				password.type = show ? 'text' : 'password';
				reveal.setAttribute( 'aria-pressed', String( show ) );
				reveal.setAttribute( 'aria-label', mw.msg( show ? 'wikioasismagic-signup-hide-password' : 'wikioasismagic-signup-show-password' ) );
				reveal.innerHTML = iconSvg( show ? 'cdxIconEyeClosed' : 'cdxIconEye' );
			} );
			wrap.appendChild( reveal );
		}

		const strength = document.createElement( 'div' );
		strength.className = 'wo-strength';
		strength.setAttribute( 'aria-hidden', 'true' );
		strength.setAttribute( 'data-level', '0' );
		strength.innerHTML = '<span></span><span></span><span></span>';

		const rules = document.createElement( 'ul' );
		rules.className = 'wo-rules';
		rules.id = 'wo-password-rules';
		[
			[ 'len', mw.msg( 'wikioasismagic-signup-rule-length', mw.language.convertNumber( MIN_LENGTH ) ) ],
			[ 'user', mw.msg( 'wikioasismagic-signup-rule-username' ) ],
			[ 'common', mw.msg( 'wikioasismagic-signup-rule-common' ) ]
		].forEach( ( rule ) => {
			const li = document.createElement( 'li' );
			li.setAttribute( 'data-rule', rule[ 0 ] );
			li.innerHTML = iconSvg( 'cdxIconCheck' ) + '<span></span>';
			li.lastChild.textContent = rule[ 1 ];
			rules.appendChild( li );
		} );

		control.appendChild( strength );
		control.appendChild( rules );
		password.setAttribute( 'aria-describedby',
			( ( password.getAttribute( 'aria-describedby' ) || '' ) + ' wo-password-rules' ).trim() );

		function render( final ) {
			const p = password.value;
			const u = username ? username.value.trim().replace( /_/g, ' ' ).toLowerCase() : '';
			const result = {
				len: p.length >= MIN_LENGTH,
				user: !!p && p.toLowerCase() !== u,
				common: !!p && common !== false
			};

			rules.querySelectorAll( 'li' ).forEach( ( li ) => {
				const ok = result[ li.getAttribute( 'data-rule' ) ];
				const pending = li.getAttribute( 'data-rule' ) === 'common' && common === null;
				const fail = !!p && !ok && ( final || p.length >= MIN_LENGTH ) && !pending;
				li.classList.toggle( 'is-met', !!p && ok && !pending );
				li.classList.toggle( 'is-fail', fail );
				const icon = li.querySelector( '.cdx-icon' );
				if ( icon ) {
					icon.outerHTML = iconSvg( fail ? 'cdxIconClose' : 'cdxIconCheck' );
				}
			} );

			let level = 0;
			if ( p ) {
				level = 1;
				if ( result.len && result.user && common !== false ) {
					level = 2;
					if ( p.length >= 12 && /[^a-zA-Z]/.test( p ) ) {
						level = 3;
					}
				}
			}
			strength.setAttribute( 'data-level', String( level ) );
		}

		function checkCommon() {
			const p = password.value;
			const mine = ++seq;
			common = null;
			clearTimeout( timer );
			if ( p.length < MIN_LENGTH ) {
				render( false );
				return;
			}
			timer = setTimeout( () => {
				api.post( {
					action: 'validatepassword',
					password: p,
					user: username ? username.value.trim() : undefined,
					formatversion: 2
				} ).then( ( data ) => {
					if ( mine !== seq ) {
						return;
					}
					const messages = ( data.validatepassword && data.validatepassword.validitymessages ) || [];
					common = !messages.some( ( m ) => /common|popular/i.test( m.code || '' ) );
					render( false );
				}, () => {
					common = true;
					render( false );
				} );
			}, 400 );
			render( false );
		}

		password.addEventListener( 'input', () => {
			if ( retype ) {
				retype.value = password.value;
			}
			checkCommon();
		} );
		if ( username ) {
			username.addEventListener( 'input', () => render( false ) );
		}
		password.form.addEventListener( 'submit', () => {
			if ( retype ) {
				retype.value = password.value;
			}
			render( true );
		} );

		render( false );
	}

	$( () => {
		const form = document.querySelector( '#userloginForm form' ) || document.querySelector( '#userloginForm' );
		if ( !form ) {
			return;
		}

		addTabs( form );

		if ( cfg.mode !== 'create' ) {
			return;
		}

		const password = document.getElementById( 'wpPassword2' );
		const retype = document.getElementById( 'wpRetype' );
		const username = document.getElementById( 'wpName2' );
		if ( !password ) {
			return;
		}

		if ( retype ) {
			const field = retype.closest( '.cdx-field' ) || retype.parentNode;
			if ( field ) {
				field.classList.add( 'wo-signup-retype--hidden' );
				retype.value = password.value;
				retype.tabIndex = -1;
			}
		}

		enhancePassword( password, retype, username );
	} );
}() );
