'use strict';

function locale() {
	return mw.config.get( 'wgUserLanguage' );
}

/**
 * @param {number|null} value
 * @return {string}
 */
function number( value ) {
	if ( value === null || value === undefined || isNaN( value ) ) {
		return '–';
	}
	return mw.language.convertNumber( value );
}

/**
 * @param {number|null} ratio
 * @param {number} [digits=1]
 * @return {string}
 */
function percent( ratio, digits ) {
	if ( ratio === null || ratio === undefined || !isFinite( ratio ) ) {
		return '–';
	}
	const fixed = Number( ( ratio * 100 ).toFixed( digits === undefined ? 1 : digits ) );
	return mw.msg( 'wikioasismagic-experiments-percent', mw.language.convertNumber( fixed ) );
}

/**
 * @param {number|null} ratio
 * @return {string}
 */
function signedPercent( ratio ) {
	if ( ratio === null || !isFinite( ratio ) ) {
		return '–';
	}
	return ( ratio > 0 ? '+' : ratio < 0 ? '−' : '' ) + percent( Math.abs( ratio ) );
}

function tryFormat( date, options ) {
	try {
		return date.toLocaleString( locale(), options );
	} catch ( e ) {
		return date.toLocaleString( undefined, options );
	}
}

/**
 * @param {string|null} iso
 * @return {string}
 */
function dateTime( iso ) {
	if ( !iso ) {
		return '–';
	}
	return mw.msg( 'wikioasismagic-experiments-utc', tryFormat( new Date( iso ), {
		year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC'
	} ) );
}

/**
 * @param {string|null} iso
 * @return {string}
 */
function date( iso ) {
	if ( !iso ) {
		return '–';
	}
	return tryFormat( new Date( iso ), { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' } );
}

/**
 * @param {string} day
 * @return {Date}
 */
function parseDay( day ) {
	return new Date( Date.UTC( +day.slice( 0, 4 ), +day.slice( 4, 6 ) - 1, +day.slice( 6, 8 ) ) );
}

/**
 * @param {Date} d
 * @return {string}
 */
function toDay( d ) {
	return d.toISOString().slice( 0, 10 ).replace( /-/g, '' );
}

/**
 * @param {string} day
 * @return {string}
 */
function shortDay( day ) {
	return tryFormat( parseDay( day ), { month: 'short', day: 'numeric', timeZone: 'UTC' } );
}

/**
 * @param {string} first
 * @param {string} last
 * @param {number} [max=400]
 * @return {string[]}
 */
function dayRange( first, last, max ) {
	const days = [];
	const end = parseDay( last ).getTime();
	for ( let t = parseDay( first ).getTime(); t <= end && days.length < ( max || 400 ); t += 86400000 ) {
		days.push( toDay( new Date( t ) ) );
	}
	return days;
}

/**
 * @param {string|null} iso
 * @return {string}
 */
function toLocalInput( iso ) {
	return iso ? new Date( iso ).toISOString().slice( 0, 16 ) : '';
}

/**
 * @param {string} value
 * @return {string|null}
 */
function fromLocalInput( value ) {
	return value ? value + ':00Z' : null;
}

module.exports = {
	number,
	percent,
	signedPercent,
	dateTime,
	date,
	shortDay,
	parseDay,
	toDay,
	dayRange,
	toLocalInput,
	fromLocalInput
};
