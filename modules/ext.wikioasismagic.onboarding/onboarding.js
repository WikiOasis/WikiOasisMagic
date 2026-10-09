( function () {
	'use strict';

	const cfg = mw.config.get( 'wgWikiOasisOnboarding' );
	const root = document.getElementById( 'wo-onboarding' );
	if ( !cfg || !root ) {
		return;
	}

	const icons = require( './icons.json' );
	const api = new mw.Api();
	const $ = ( selector, scope ) => ( scope || root ).querySelector( selector );
	const $$ = ( selector, scope ) => Array.prototype.slice.call( ( scope || root ).querySelectorAll( selector ) );

	const WIKI_STEPS = [ 'wiki-name', 'wiki-purpose', 'wiki-settings', 'wiki-review' ];
	const RAIL_LABELS = {
		account: 'wikioasismagic-onboarding-step-account',
		survey: 'wikioasismagic-onboarding-step-survey',
		'wiki-name': 'wikioasismagic-onboarding-step-wiki-name',
		'wiki-purpose': 'wikioasismagic-onboarding-step-wiki-purpose',
		'wiki-settings': 'wikioasismagic-onboarding-step-wiki-settings',
		'wiki-review': 'wikioasismagic-onboarding-step-wiki-review',
		'wiki-blocked': 'wikioasismagic-onboarding-step-wiki-blocked',
		done: 'wikioasismagic-onboarding-step-done',
		finish: 'wikioasismagic-onboarding-step-finish'
	};
	const FIELD_STEPS = {
		subdomain: 'wiki-name',
		sitename: 'wiki-name',
		language: 'wiki-name',
		category: 'wiki-name',
		purpose: 'wiki-purpose',
		reason: 'wiki-purpose',
		nsfwtext: 'wiki-settings',
		sourceurl: 'wiki-settings',
		agreement: 'wiki-review'
	};

	const state = {
		current: null,
		enteredAt: 0,
		subTouched: false,
		completed: false,
		request: null
	};

	function iconSvg( name ) {
		const paths = icons[ name ];
		if ( typeof paths !== 'string' ) {
			return '';
		}
		return '<span class="cdx-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">' +
			paths + '</svg></span>';
	}

	/**
	 * @param {HTMLElement|null} el
	 * @param {string} kind
	 * @param {string} [html]
	 */
	function setStatus( el, kind, html ) {
		if ( !el ) {
			return;
		}
		el.className = 'wo-status' + ( kind ? ' is-' + kind : '' );
		if ( !kind ) {
			el.innerHTML = '';
		} else if ( kind === 'wait' ) {
			el.innerHTML = '<span class="wo-spin" aria-hidden="true"></span><span>' + html + '</span>';
		} else {
			el.innerHTML = iconSvg( kind === 'ok' ? 'cdxIconSuccess' : 'cdxIconAlert' ) + '<span>' + html + '</span>';
		}
	}

	function esc( text ) {
		return mw.html.escape( String( text ) );
	}

	function fieldError( input, on ) {
		if ( !input ) {
			return;
		}
		if ( input.tagName === 'SELECT' ) {
			input.classList.toggle( 'cdx-select--status-error', !!on );
			return;
		}
		const wrap = input.closest( '.cdx-text-input, .cdx-text-area' );
		if ( wrap ) {
			wrap.classList.toggle(
				wrap.classList.contains( 'cdx-text-area' ) ? 'cdx-text-area--status-error' : 'cdx-text-input--status-error',
				!!on
			);
		}
	}

	function post( params ) {
		return api.postWithToken( 'csrf', Object.assign( {
			action: 'wikioasisonboarding',
			formatversion: 2,
			errorformat: 'html'
		}, params ) );
	}

	function track( params ) {
		post( Object.assign( { do: 'event' }, params ) ).catch( () => {} );
	}

	function beacon( params ) {
		const data = new FormData();
		const all = Object.assign( {
			action: 'wikioasisonboarding',
			do: 'event',
			format: 'json',
			token: mw.user.tokens.get( 'csrfToken' )
		}, params );
		Object.keys( all ).forEach( ( key ) => data.append( key, all[ key ] ) );
		if ( !navigator.sendBeacon || !navigator.sendBeacon( mw.util.wikiScript( 'api' ), data ) ) {
			track( params );
		}
	}

	function stepElement( step ) {
		return $( '.wo-step[data-step="' + step + '"]' );
	}

	function getAnswers() {
		const answers = {};
		const freeText = {};
		$$( '#wo-st-survey fieldset[data-question]' ).forEach( ( fieldset ) => {
			const id = fieldset.getAttribute( 'data-question' );
			const picked = $$( 'input:checked', fieldset ).map( ( input ) => input.value );
			if ( picked.length ) {
				answers[ id ] = picked;
			}
			const other = $( '.wo-other input', fieldset );
			if ( other && other.value.trim() ) {
				freeText[ id ] = other.value.trim();
			}
		} );
		return { answers: answers, freeText: freeText };
	}

	function pickedOptions() {
		return $$( '#wo-st-survey fieldset[data-question] input:checked' );
	}

	function wantsWiki() {
		return pickedOptions().some( ( input ) => {
			const action = input.getAttribute( 'data-action' );
			return action === 'createwiki' || action === 'importwiki';
		} );
	}

	function wantsImport() {
		return pickedOptions().some( ( input ) => input.getAttribute( 'data-action' ) === 'importwiki' );
	}

	function updateSurvey() {
		$$( '#wo-st-survey .wo-other' ).forEach( ( other ) => {
			const option = $( 'input[value="' + other.getAttribute( 'data-other-for' ) + '"]', other.closest( 'fieldset' ) );
			other.hidden = !( option && option.checked );
		} );

		root.setAttribute( 'data-expertise', pickedOptions().some( ( input ) => input.hasAttribute( 'data-tips' ) ) ? 'new' : 'other' );

		const next = $( '#wo-survey-next' );
		if ( next ) {
			next.textContent = mw.msg( wantsWiki() && ( cfg.canRequestWiki || cfg.wikiBlocked ) ?
				'wikioasismagic-onboarding-continue-wiki' :
				'wikioasismagic-onboarding-continue' );
		}
		renderRail();
	}

	function afterSurvey() {
		if ( wantsWiki() && cfg.canRequestWiki ) {
			if ( wantsImport() ) {
				const migrate = $( '#wo-migrate' );
				if ( migrate && !migrate.checked ) {
					migrate.checked = true;
					updateReveals();
				}
			}
			go( 'wiki-name' );
		} else if ( wantsWiki() && cfg.wikiBlocked ) {
			go( 'wiki-blocked' );
		} else {
			go( 'finish' );
		}
	}

	function initSurvey() {
		const form = $( '#wo-st-survey' );
		if ( !form ) {
			return;
		}

		$$( 'input', form ).forEach( ( input ) => input.addEventListener( 'change', ( e ) => {
			updateSurvey();
			if ( e.target.checked && e.target.hasAttribute( 'data-freetext' ) ) {
				const other = $( '.wo-other input', e.target.closest( 'fieldset' ) );
				if ( other ) {
					other.focus();
				}
			}
		} ) );

		let lastButton = null;
		$$( 'button[type="submit"]', form ).forEach( ( button ) => button.addEventListener( 'click', () => {
			lastButton = button;
		} ) );

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const button = e.submitter || lastButton;
			lastButton = null;
			const skipped = !!( button && button.hasAttribute( 'data-skip' ) );
			const response = getAnswers();
			post( {
				do: 'submit',
				answers: JSON.stringify( skipped ? {} : response.answers ),
				freetext: JSON.stringify( skipped ? {} : response.freeText ),
				skipped: skipped ? 1 : undefined
			} ).catch( () => {} );

			if ( skipped ) {
				$$( 'input:checked', form ).forEach( ( input ) => {
					input.checked = false;
				} );
				updateSurvey();
				go( 'finish' );
			} else {
				afterSurvey();
			}
		} );

		updateSurvey();
	}

	function slug( text ) {
		return text.toLowerCase()
			.normalize( 'NFKD' ).replace( /[̀-ͯ]/g, '' )
			.replace( /\bwiki\b/g, '' )
			.replace( /[^a-z0-9]/g, '' );
	}

	let subdomainTimer = null;
	let subdomainSeq = 0;

	/**
	 * @param {boolean} final
	 * @return {jQuery.Promise|Promise}
	 */
	function checkSubdomain( final ) {
		const input = $( '#wo-subdomain' );
		const status = $( '#wo-subdomain-status' );
		const value = input.value;
		clearTimeout( subdomainTimer );
		const seq = ++subdomainSeq;

		let problem = null;
		if ( !value ) {
			problem = final ? mw.message( 'wikioasismagic-onboarding-subdomain-required' ).escaped() : '';
		} else if ( /[^a-z0-9]/.test( value ) ) {
			problem = mw.message( 'wikioasismagic-onboarding-subdomain-chars' ).escaped();
		} else if ( value.length < 3 ) {
			problem = mw.message( 'wikioasismagic-onboarding-subdomain-short' ).escaped();
		}

		if ( problem !== null ) {
			fieldError( input, problem !== '' );
			setStatus( status, problem ? 'bad' : '', problem );
			return Promise.resolve( false );
		}

		fieldError( input, false );
		setStatus( status, 'wait', mw.message( 'wikioasismagic-onboarding-subdomain-checking' ).escaped() );

		return new Promise( ( resolve ) => {
			subdomainTimer = setTimeout( () => {
				post( { do: 'checksubdomain', subdomain: value } ).then( ( data ) => {
					if ( seq !== subdomainSeq ) {
						return resolve( false );
					}
					const result = data.wikioasisonboarding;
					if ( result.result === 'available' ) {
						setStatus( status, 'ok', mw.message(
							'wikioasismagic-onboarding-subdomain-available',
							value + cfg.subdomainSuffix
						).escaped() );
						resolve( true );
					} else {
						fieldError( input, true );
						setStatus( status, 'bad', result.message );
						resolve( false );
					}
				}, () => {
					setStatus( status, '', '' );
					resolve( true );
				} );
			}, final ? 0 : 350 );
		} );
	}

	function initWikiName() {
		const form = stepElement( 'wiki-name' );
		if ( !form ) {
			return;
		}
		const sitename = $( '#wo-sitename' );
		const subdomain = $( '#wo-subdomain' );
		const category = $( '#wo-category' );

		sitename.addEventListener( 'input', () => {
			if ( !state.subTouched ) {
				subdomain.value = slug( sitename.value ).slice( 0, Number( subdomain.maxLength ) || 40 );
				if ( subdomain.value ) {
					checkSubdomain( false );
				} else {
					setStatus( $( '#wo-subdomain-status' ), '', '' );
				}
			}
			if ( sitename.value.trim() ) {
				fieldError( sitename, false );
				setStatus( $( '#wo-sitename-status' ), '', '' );
			}
			renderPreview();
		} );

		subdomain.addEventListener( 'input', () => {
			const cleaned = subdomain.value.toLowerCase().replace( /\s/g, '' );
			if ( cleaned !== subdomain.value ) {
				subdomain.value = cleaned;
			}
			state.subTouched = true;
			checkSubdomain( false );
			renderPreview();
		} );

		$( '#wo-language' ).addEventListener( 'change', renderPreview );

		if ( category ) {
			category.addEventListener( 'change', () => {
				fieldError( category, false );
				setStatus( $( '#wo-category-status' ), '', '' );
				renderPreview();
			} );
		}

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const nameOk = !!sitename.value.trim();
			fieldError( sitename, !nameOk );
			setStatus( $( '#wo-sitename-status' ), nameOk ? '' : 'bad',
				nameOk ? '' : mw.message( 'wikioasismagic-onboarding-err-sitename' ).escaped() );

			const categoryOk = !category || !!category.value;
			if ( category ) {
				fieldError( category, !categoryOk );
				setStatus( $( '#wo-category-status' ), categoryOk ? '' : 'bad',
					categoryOk ? '' : mw.message( 'wikioasismagic-onboarding-err-category' ).escaped() );
			}

			checkSubdomain( true ).then( ( subdomainOk ) => {
				if ( !nameOk ) {
					return sitename.focus();
				}
				if ( !subdomainOk ) {
					return subdomain.focus();
				}
				if ( !categoryOk ) {
					return category.focus();
				}
				go( 'wiki-purpose' );
			} );
		} );
	}

	function reasonLength() {
		return $( '#wo-reason' ).value.trim().length;
	}

	function updateReason() {
		const min = cfg.reasonMinLength;
		const n = reasonLength();
		const meter = $( '#wo-reason-meter' );
		let text;
		if ( !min ) {
			text = mw.msg( 'wikioasismagic-onboarding-reason-count-nomin', mw.language.convertNumber( n ) );
		} else if ( n >= min ) {
			text = mw.msg( 'wikioasismagic-onboarding-reason-enough', mw.language.convertNumber( n ) );
		} else {
			text = mw.msg( 'wikioasismagic-onboarding-reason-count', mw.language.convertNumber( n ), mw.language.convertNumber( min ) );
		}
		$( '#wo-reason-txt' ).textContent = text;
		meter.hidden = !min;
		meter.classList.toggle( 'is-full', !min || n >= min );
		meter.firstElementChild.style.width = ( min ? Math.min( 100, n / min * 100 ) : 100 ) + '%';
		if ( !min || n >= min ) {
			fieldError( $( '#wo-reason' ), false );
			setStatus( $( '#wo-reason-status' ), '', '' );
		}
		renderPreview();
	}

	function initWikiPurpose() {
		const form = stepElement( 'wiki-purpose' );
		if ( !form ) {
			return;
		}
		const reason = $( '#wo-reason' );
		const purpose = $( '#wo-purpose' );

		reason.addEventListener( 'input', updateReason );
		$$( '[data-prompt]', form ).forEach( ( button ) => button.addEventListener( 'click', () => {
			const value = reason.value;
			let separator = '';
			if ( value && !/\s$/.test( value ) ) {
				separator = /[.!?]$/.test( value.trim() ) ? ' ' : '. ';
			}
			reason.value = value + separator + button.getAttribute( 'data-prompt' );
			reason.focus();
			reason.setSelectionRange( reason.value.length, reason.value.length );
			updateReason();
		} ) );

		if ( purpose ) {
			purpose.addEventListener( 'change', () => {
				fieldError( purpose, false );
				setStatus( $( '#wo-purpose-status' ), '', '' );
			} );
		}

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			if ( purpose && !purpose.value ) {
				fieldError( purpose, true );
				setStatus( $( '#wo-purpose-status' ), 'bad', mw.message( 'wikioasismagic-onboarding-err-purpose' ).escaped() );
				return purpose.focus();
			}
			const n = reasonLength();
			const min = cfg.reasonMinLength;
			if ( !n || ( min && n < min ) ) {
				fieldError( reason, true );
				setStatus( $( '#wo-reason-status' ), 'bad', min ?
					mw.message( 'wikioasismagic-onboarding-reason-short', mw.language.convertNumber( min - n ) ).escaped() :
					mw.message( 'wikioasismagic-onboarding-reason-required' ).escaped() );
				return reason.focus();
			}
			go( 'wiki-settings' );
		} );

		updateReason();
	}

	function updateReveals() {
		$$( '[data-reveal-for]' ).forEach( ( block ) => {
			const toggle = document.getElementById( block.getAttribute( 'data-reveal-for' ) );
			block.hidden = !( toggle && toggle.checked );
		} );
		renderPreview();
	}

	function validSourceUrl( value ) {
		try {
			const url = new URL( value );
			return /^https?:$/.test( url.protocol ) && !!url.host;
		} catch ( e ) {
			return false;
		}
	}

	function initWikiSettings() {
		const form = stepElement( 'wiki-settings' );
		if ( !form ) {
			return;
		}

		$$( 'input', form ).forEach( ( input ) => input.addEventListener( 'change', updateReveals ) );
		$( '#wo-sourceurl' ).addEventListener( 'input', () => {
			fieldError( $( '#wo-sourceurl' ), false );
			setStatus( $( '#wo-sourceurl-status' ), '', '' );
		} );

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			if ( $( '#wo-migrate' ).checked && !validSourceUrl( $( '#wo-sourceurl' ).value.trim() ) ) {
				fieldError( $( '#wo-sourceurl' ), true );
				setStatus( $( '#wo-sourceurl-status' ), 'bad', mw.message( 'wikioasismagic-onboarding-err-sourceurl' ).escaped() );
				return $( '#wo-sourceurl' ).focus();
			}
			go( 'wiki-review' );
		} );

		updateReveals();
	}

	function value( selector ) {
		const el = $( selector );
		return el ? el.value.trim() : '';
	}

	function checked( selector ) {
		const el = $( selector );
		return !!( el && el.checked );
	}

	function selectedText( selector ) {
		const el = $( selector );
		return el && el.selectedIndex >= 0 && el.value ? el.options[ el.selectedIndex ].textContent : '';
	}

	function collectRequest() {
		const privateInput = $( 'input[name="private"]:checked' );
		const sourceType = $( 'input[name="sourcetype"]:checked' );
		return {
			sitename: value( '#wo-sitename' ),
			subdomain: value( '#wo-subdomain' ),
			language: value( '#wo-language' ),
			category: value( '#wo-category' ),
			purpose: value( '#wo-purpose' ),
			reason: value( '#wo-reason' ),
			private: !!( privateInput && privateInput.value === '1' ),
			bio: checked( '#wo-bio' ),
			nsfw: checked( '#wo-nsfw' ),
			nsfwtext: checked( '#wo-nsfw' ) ? value( '#wo-nsfwtext' ) : '',
			'nsfw-primary': checked( '#wo-nsfw' ) && checked( '#wo-nsfw-primary' ),
			source: checked( '#wo-migrate' ),
			sourceurl: checked( '#wo-migrate' ) ? value( '#wo-sourceurl' ) : '',
			sourcetype: sourceType ? sourceType.value : 'move',
			agreement: checked( '#wo-agreement' )
		};
	}

	function renderReview() {
		const req = collectRequest();
		const box = $( '#wo-review-box' );
		const none = mw.msg( 'wikioasismagic-onboarding-review-none' );

		const flags = [];
		if ( req.bio ) {
			flags.push( mw.msg( 'wikioasismagic-onboarding-flag-bio' ) );
		}
		if ( req.nsfw ) {
			flags.push( mw.msg( req[ 'nsfw-primary' ] ? 'wikioasismagic-onboarding-flag-nsfw-primary' : 'wikioasismagic-onboarding-flag-nsfw' ) +
				( req.nsfwtext ? ' (' + req.nsfwtext + ')' : '' ) );
		}

		const sections = [
			[ 'wikioasismagic-onboarding-review-section-name', 'wiki-name', [
				[ 'wikioasismagic-onboarding-review-name', req.sitename || none ],
				[ 'wikioasismagic-onboarding-review-address', ( req.subdomain || none ) + cfg.subdomainSuffix, 'wo-mono' ],
				[ 'wikioasismagic-onboarding-review-language', selectedText( '#wo-language' ) ],
				$( '#wo-category' ) ? [ 'wikioasismagic-onboarding-review-category', selectedText( '#wo-category' ) || none ] : null
			] ],
			[ 'wikioasismagic-onboarding-review-section-purpose', 'wiki-purpose', [
				$( '#wo-purpose' ) ? [ 'wikioasismagic-onboarding-review-purpose', selectedText( '#wo-purpose' ) || none ] : null,
				[ 'wikioasismagic-onboarding-review-description', req.reason || none, 'wo-desc' ]
			] ],
			[ 'wikioasismagic-onboarding-review-section-settings', 'wiki-settings', [
				$( 'input[name="private"]' ) ? [ 'wikioasismagic-onboarding-review-visibility', mw.msg( req.private ?
					'wikioasismagic-onboarding-private' : 'wikioasismagic-onboarding-public' ) ] : null,
				[ 'wikioasismagic-onboarding-review-flags', flags.length ? flags.join( '\n' ) : none, 'wo-desc' ],
				[ 'wikioasismagic-onboarding-review-existing', req.source ?
					mw.msg( req.sourcetype === 'fork' ? 'wikioasismagic-onboarding-review-forking' :
						'wikioasismagic-onboarding-review-moving', req.sourceurl ) :
					mw.msg( 'wikioasismagic-onboarding-review-no' ) ]
			] ]
		];

		box.textContent = '';
		sections.forEach( ( section ) => {
			const el = document.createElement( 'section' );
			const head = document.createElement( 'div' );
			head.className = 'wo-row-between';
			const h3 = document.createElement( 'h3' );
			h3.textContent = mw.msg( section[ 0 ] );
			const edit = document.createElement( 'button' );
			edit.type = 'button';
			edit.className = 'cdx-button cdx-button--weight-quiet cdx-button--size-small';
			edit.innerHTML = iconSvg( 'cdxIconEdit' ) + esc( mw.msg( 'wikioasismagic-onboarding-edit' ) );
			edit.addEventListener( 'click', () => go( section[ 1 ] ) );
			head.appendChild( h3 );
			head.appendChild( edit );
			el.appendChild( head );

			const dl = document.createElement( 'dl' );
			section[ 2 ].filter( Boolean ).forEach( ( row ) => {
				const dt = document.createElement( 'dt' );
				dt.textContent = mw.msg( row[ 0 ] );
				const dd = document.createElement( 'dd' );
				dd.textContent = row[ 1 ];
				if ( row[ 2 ] ) {
					dd.className = row[ 2 ];
				}
				dl.appendChild( dt );
				dl.appendChild( dd );
			} );
			el.appendChild( dl );
			box.appendChild( el );
		} );
	}

	function showRequestErrors( errors ) {
		let firstStep = null;
		Object.keys( errors ).forEach( ( field ) => {
			if ( field === 'general' ) {
				const wrap = $( '#wo-submit-error' );
				$( '.cdx-message__content', wrap ).innerHTML = errors.general;
				wrap.hidden = false;
				firstStep = firstStep || 'wiki-review';
				return;
			}
			const step = FIELD_STEPS[ field ] || 'wiki-review';
			const input = $( '#wo-' + field );
			fieldError( input, true );
			setStatus( $( '#wo-' + field + '-status' ), 'bad', errors[ field ] );
			if ( !firstStep || WIKI_STEPS.indexOf( step ) < WIKI_STEPS.indexOf( firstStep ) ) {
				firstStep = step;
			}
		} );
		if ( firstStep && firstStep !== state.current ) {
			go( firstStep );
		}
	}

	function initWikiReview() {
		const form = stepElement( 'wiki-review' );
		if ( !form ) {
			return;
		}
		const agreement = $( '#wo-agreement' );
		const submit = $( '#wo-submit-request' );

		agreement.addEventListener( 'change', () => setStatus( $( '#wo-agreement-status' ), '', '' ) );

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			$( '#wo-submit-error' ).hidden = true;
			if ( !agreement.checked ) {
				setStatus( $( '#wo-agreement-status' ), 'bad', mw.message( 'wikioasismagic-onboarding-err-policy' ).escaped() );
				return agreement.focus();
			}

			submit.disabled = true;
			submit.textContent = mw.msg( 'wikioasismagic-onboarding-sending' );
			const request = collectRequest();

			post( { do: 'requestwiki', request: JSON.stringify( request ) } ).then( ( data ) => {
				const result = data.wikioasisonboarding;
				if ( result.result === 'success' ) {
					state.request = { id: result.id, url: result.url, data: request };
					go( 'done' );
				} else {
					showRequestErrors( result.errors || {} );
				}
			}, ( code ) => {
				showRequestErrors( { general: code === 'http' ?
					mw.message( 'wikioasismagic-onboarding-err-network' ).escaped() :
					mw.message( 'wikioasismagic-onboarding-err-server', code ).escaped()
				} );
			} ).always( () => {
				submit.disabled = false;
				submit.textContent = mw.msg( 'wikioasismagic-onboarding-send' );
			} );
		} );
	}

	function renderDone() {
		const request = state.request;
		if ( !request ) {
			return;
		}
		const address = request.data.subdomain + cfg.subdomainSuffix;
		$$( '.js-sitename' ).forEach( ( el ) => {
			el.textContent = request.data.sitename;
		} );
		$$( '.js-url' ).forEach( ( el ) => {
			el.textContent = address;
		} );
		$( '#wo-done-lead' ).textContent = mw.msg(
			cfg.hasEmail ? 'wikioasismagic-onboarding-done-lead-email' : 'wikioasismagic-onboarding-done-lead',
			'#' + request.id
		);
		$( '#wo-view-request' ).href = request.url;
		$( '#wo-noemail' ).hidden = cfg.hasEmail;
		$$( '.js-import-step' ).forEach( ( el ) => {
			el.hidden = !request.data.source;
		} );
	}

	function renderPreview() {
		const preview = $( '#wo-preview' );
		if ( !preview ) {
			return;
		}
		const sitename = value( '#wo-sitename' );
		const language = value( '#wo-language' ) || 'en';
		const rtl = cfg.rtlLanguages.indexOf( language.split( '-' )[ 0 ] ) !== -1;
		const reason = value( '#wo-reason' );
		const category = selectedText( '#wo-category' );

		$( '#wo-pv-sub' ).textContent = value( '#wo-subdomain' ) || 'yourwiki';
		$( '#wo-pv-name' ).textContent = sitename || mw.msg( 'wikioasismagic-onboarding-preview-name' );
		$( '#wo-pv-page' ).dir = rtl ? 'rtl' : 'ltr';
		$( '#wo-pv-lang' ).textContent = rtl ? mw.msg( 'wikioasismagic-onboarding-preview-rtl', language ) : language;
		$( '#wo-pv-main' ).textContent = cfg.mainPage;

		const lead = $( '#wo-pv-lead' );
		lead.classList.toggle( 'is-empty', !reason );
		lead.textContent = reason ?
			mw.msg( 'wikioasismagic-onboarding-preview-lead', sitename || mw.msg( 'wikioasismagic-onboarding-preview-name' ), reason ) :
			mw.msg( 'wikioasismagic-onboarding-preview-lead-empty' );

		const cat = $( '#wo-pv-cat' );
		cat.hidden = !category;
		cat.textContent = category;
		const privateInput = $( 'input[name="private"]:checked' );
		$( '#wo-pv-priv' ).hidden = !( privateInput && privateInput.value === '1' );
		$( '#wo-pv-nsfw' ).hidden = !checked( '#wo-nsfw' );
		$( '#wo-pv-bio' ).hidden = !checked( '#wo-bio' );
		$( '#wo-pv-mig' ).hidden = !checked( '#wo-migrate' );

		const ns = ( sitename || mw.msg( 'wikioasismagic-onboarding-preview-name' ) ).replace( /\s+/g, '_' );
		$$( '.js-ns' ).forEach( ( el ) => {
			el.textContent = ns + ':About';
		} );
	}

	function renderCards() {
		const container = $( '#wo-cards' );
		if ( !container ) {
			return;
		}
		const answers = getAnswers().answers;
		const matched = [];
		const always = [];
		$$( '[data-card]', container ).forEach( ( card ) => {
			const when = cfg.cards[ card.getAttribute( 'data-card' ) ];
			if ( !when || Array.isArray( when ) || !Object.keys( when ).length ) {
				always.push( card );
				return;
			}
			const hit = Object.keys( when ).some( ( question ) => ( when[ question ] || [] ).some(
				( option ) => ( answers[ question ] || [] ).indexOf( option ) !== -1
			) );
			if ( hit ) {
				matched.push( card );
			} else {
				card.hidden = true;
			}
		} );

		matched.concat( always ).forEach( ( card, i ) => {
			card.hidden = i >= 6;
			container.appendChild( card );
		} );
	}

	function initFinish() {
		$$( '[data-card]' ).forEach( ( card ) => card.addEventListener( 'click', () => {
			beacon( { event: 'cardclick', card: card.getAttribute( 'data-card' ) } );
		} ) );

		$$( '[data-start-wiki]' ).forEach( ( link ) => {
			if ( !cfg.canRequestWiki && !cfg.wikiBlocked ) {
				return;
			}
			link.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				go( cfg.canRequestWiki ? 'wiki-name' : 'wiki-blocked' );
			} );
		} );

		$$( '[data-goto]' ).forEach( ( button ) => button.addEventListener( 'click', () => {
			go( button.getAttribute( 'data-goto' ) );
		} ) );
	}

	function onWikiPath() {
		return wantsWiki() || !!state.request || WIKI_STEPS.indexOf( state.current ) !== -1 ||
			state.current === 'done' || state.current === 'wiki-blocked';
	}

	function sequence() {
		const seq = [ 'account', 'survey' ];
		if ( onWikiPath() && cfg.wikiBlocked ) {
			return seq.concat( [ 'wiki-blocked', 'finish' ] );
		}
		if ( onWikiPath() && cfg.canRequestWiki ) {
			return seq.concat( WIKI_STEPS, [ 'done' ], state.request && state.current === 'finish' ? [ 'finish' ] : [] );
		}
		return seq.concat( [ 'finish' ] );
	}

	function renderRail() {
		const rail = $( '#wo-rail' );
		const seq = sequence();
		const current = state.current || 'survey';
		const idx = Math.max( 1, seq.indexOf( current ) );

		rail.textContent = '';
		seq.forEach( ( step, i ) => {
			if ( step === 'wiki-name' ) {
				const group = document.createElement( 'li' );
				group.className = 'wo-rail__grouprow';
				group.setAttribute( 'aria-hidden', 'true' );
				group.innerHTML = '<span class="wo-rail__group">' + esc( mw.msg( 'wikioasismagic-onboarding-rail-group-wiki' ) ) + '</span>';
				rail.appendChild( group );
			}

			const done = i < idx;
			const li = document.createElement( 'li' );
			li.className = done ? 'is-done' : ( i === idx ? 'is-current' : '' );
			if ( i === idx ) {
				li.setAttribute( 'aria-current', 'step' );
			}
			li.innerHTML = '<span class="wo-dot">' + ( done ? iconSvg( 'cdxIconCheck' ) : esc( mw.language.convertNumber( i + 1 ) ) ) +
				'</span><span class="wo-rail__text">' + esc( mw.msg( RAIL_LABELS[ step ] ) ) + '</span>';

			const canJump = i < idx && step !== 'account' && current !== 'done' && !state.request;
			if ( canJump ) {
				li.classList.add( 'is-jump' );
				li.tabIndex = 0;
				li.setAttribute( 'role', 'link' );
				li.addEventListener( 'click', () => go( step ) );
				li.addEventListener( 'keydown', ( e ) => {
					if ( e.key === 'Enter' || e.key === ' ' ) {
						e.preventDefault();
						go( step );
					}
				} );
			}
			rail.appendChild( li );
		} );

		$( '#wo-mp-txt' ).textContent = mw.msg(
			'wikioasismagic-onboarding-progress',
			mw.language.convertNumber( idx + 1 ),
			mw.language.convertNumber( seq.length ),
			mw.msg( RAIL_LABELS[ current ] )
		);
		$( '#wo-mp-bar' ).style.width = ( ( idx + 1 ) / seq.length * 100 ) + '%';
	}

	function backOf( step ) {
		const i = WIKI_STEPS.indexOf( step );
		return i > 0 ? WIKI_STEPS[ i - 1 ] : 'survey';
	}

	function leaveStep() {
		if ( state.current && state.enteredAt ) {
			const seconds = ( Date.now() - state.enteredAt ) / 1000;
			if ( seconds > 0.5 ) {
				track( { event: 'stepduration', step: state.current, ms: Math.round( seconds * 1000 ) } );
			}
		}
	}

	function go( step ) {
		if ( !stepElement( step ) ) {
			step = 'finish';
		}
		if ( step !== state.current ) {
			leaveStep();
		}

		state.current = step;
		state.enteredAt = Date.now();

		$$( '.wo-step' ).forEach( ( el ) => {
			el.classList.toggle( 'is-active', el.getAttribute( 'data-step' ) === step );
		} );

		const showPreview = [ 'wiki-name', 'wiki-purpose', 'wiki-settings' ].indexOf( step ) !== -1;
		const preview = $( '#wo-preview' );
		if ( preview ) {
			preview.hidden = !showPreview;
		}
		$( '#wo-shell' ).classList.toggle( 'has-preview', showPreview );

		if ( step === 'wiki-review' ) {
			renderReview();
		}
		if ( step === 'done' ) {
			state.completed = true;
			renderDone();
		}
		if ( step === 'finish' ) {
			renderCards();
			if ( !state.completed ) {
				state.completed = true;
				track( { event: 'complete', path: 'finish' } );
			}
		}

		renderPreview();
		renderRail();
		track( { event: 'stepview', step: step } );

		window.scrollTo( 0, 0 );
		const heading = $( '.wo-step.is-active .wo-h1' );
		if ( heading ) {
			heading.tabIndex = -1;
			heading.focus( { preventScroll: true } );
		}
	}

	function init() {
		root.classList.add( 'is-enhanced' );
		initSurvey();
		initWikiName();
		initWikiPurpose();
		initWikiSettings();
		initWikiReview();
		initFinish();

		$$( '[data-back]' ).forEach( ( button ) => button.addEventListener( 'click', () => go( backOf( state.current ) ) ) );

		document.addEventListener( 'visibilitychange', () => {
			if ( document.visibilityState === 'hidden' && state.current && state.enteredAt ) {
				const seconds = ( Date.now() - state.enteredAt ) / 1000;
				if ( seconds > 0.5 ) {
					beacon( { event: 'stepduration', step: state.current, ms: Math.round( seconds * 1000 ) } );
				}
				state.enteredAt = 0;
			} else if ( document.visibilityState === 'visible' && state.current ) {
				state.enteredAt = Date.now();
			}
		} );

		go( cfg.initialStep || 'survey' );
	}

	init();
}() );
