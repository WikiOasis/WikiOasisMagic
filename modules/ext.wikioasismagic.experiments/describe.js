'use strict';

const { cdxIconGlobe, cdxIconUserAvatar, cdxIconBrowser } = require( './components/icons.json' );

const UNIT_ICONS = {
	wiki: cdxIconGlobe,
	user: cdxIconUserAvatar,
	browser: cdxIconBrowser
};

/**
 * @param {string} unit
 * @param {string} population
 * @return {string}
 */
function audience( unit, population ) {
	return mw.msg( 'wikioasismagic-experiments-audience-' + unit + '-' + ( unit === 'browser' ? 'all' : population ) );
}

/**
 * @param {string} unit
 * @return {string|Object}
 */
function unitIcon( unit ) {
	return UNIT_ICONS[ unit ] || cdxIconGlobe;
}

/**
 * @param {Object[]} variants
 * @return {string}
 */
function changes( variants ) {
	const items = [];
	variants.forEach( ( v ) => {
		( v.extensions || [] ).forEach( ( ext ) => {
			const name = typeof ext === 'string' ? ext : ext.name;
			if ( items.indexOf( name ) === -1 ) {
				items.push( name );
			}
		} );
		const vars = Array.isArray( v.config ) ? v.config : Object.keys( v.config || {} );
		vars.forEach( ( variable ) => {
			if ( items.indexOf( '$' + variable ) === -1 ) {
				items.push( '$' + variable );
			}
		} );
	} );
	return items.join( ', ' );
}

/**
 * @param {Object} metric
 * @return {string}
 */
function metricCounts( metric ) {
	const filter = metric.filter || {};
	const list = ( value ) => ( Array.isArray( value ) ? value : [] ).join( ', ' );
	switch ( metric.type ) {
		case 'edit':
			return filter.namespaces && filter.namespaces.length ?
				mw.msg( 'wikioasismagic-experiments-metric-edit-ns', list( filter.namespaces ) ) :
				mw.msg( 'wikioasismagic-experiments-metric-edit' );
		case 'tag':
			return mw.msg( 'wikioasismagic-experiments-metric-tag', list( filter.tags ) );
		case 'log':
			return mw.msg( 'wikioasismagic-experiments-metric-log', ( filter.log || [] )
				.map( ( pair ) => pair[ 1 ] === '*' ? pair[ 0 ] : pair[ 0 ] + '/' + pair[ 1 ] ).join( ', ' ) );
		case 'specialpage':
			return mw.msg( 'wikioasismagic-experiments-metric-specialpage', list( filter.pages ) );
		case 'api':
			return mw.msg( 'wikioasismagic-experiments-metric-api', list( filter.modules ) );
		default:
			return filter.client ?
				mw.msg( 'wikioasismagic-experiments-metric-event-client' ) :
				mw.msg( 'wikioasismagic-experiments-metric-event' );
	}
}

/**
 * @param {*} value
 * @return {string}
 */
function configValue( value ) {
	if ( value === true ) {
		return 'true';
	}
	if ( value === false ) {
		return 'false';
	}
	return JSON.stringify( value );
}

module.exports = { audience, unitIcon, changes, metricCounts, configValue };
