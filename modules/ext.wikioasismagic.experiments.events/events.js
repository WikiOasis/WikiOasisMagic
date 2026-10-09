( function () {
	'use strict';

	function record( experiment, metric ) {
		const data = {
			action: 'wikioasisexperimentevent',
			format: 'json',
			experiment: experiment,
			metric: metric,
			token: mw.user.tokens.get( 'csrfToken' )
		};

		if ( navigator.sendBeacon && window.FormData ) {
			const body = new FormData();
			Object.keys( data ).forEach( ( key ) => {
				body.append( key, data[ key ] );
			} );
			if ( navigator.sendBeacon( mw.util.wikiScript( 'api' ), body ) ) {
				return;
			}
		}

		new mw.Api().post( data ).catch( () => {} );
	}

	module.exports = { record: record };
}() );
