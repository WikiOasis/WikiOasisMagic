'use strict';

const READ = 'wikioasisfandomimport';
const WRITE = 'wikioasisfandomimportaction';

/**
 * Calls to the Fandom import API modules. Every call resolves with the
 * module's result and rejects with an HTML error message ready to show.
 */
function request( promise, module ) {
	return new Promise( ( resolve, reject ) => {
		promise.then(
			( data ) => resolve( data[ module ] ),
			( code, data ) => {
				const html = data && data.errors && data.errors[ 0 ] && data.errors[ 0 ].html;
				reject( html || mw.message( 'wikioasismagic-fandomimport-error-generic' ).escaped() );
			}
		);
	} );
}

function get( params ) {
	return request( new mw.Api().get( Object.assign( {
		action: READ,
		formatversion: 2,
		errorformat: 'html',
		uselang: mw.config.get( 'wgUserLanguage' )
	}, params ) ), READ );
}

module.exports = {
	detail: ( id ) => get( { prop: 'detail', id } ).then( ( result ) => result.detail ),
	lookup: ( source ) => get( { prop: 'lookup', source } ),
	subdomain: ( subdomain ) => get( { prop: 'subdomain', subdomain } ),
	account: ( id ) => get( { prop: 'account', id } ).then( ( result ) => result.account ),
	act: ( params ) => request( new mw.Api().postWithToken( 'csrf', Object.assign( {
		action: WRITE,
		formatversion: 2,
		errorformat: 'html',
		uselang: mw.config.get( 'wgUserLanguage' )
	}, params ) ), WRITE )
};
