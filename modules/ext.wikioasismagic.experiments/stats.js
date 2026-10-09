'use strict';

/**
 * @param {number} z
 * @return {number}
 */
function normalCdf( z ) {
	const t = 1 / ( 1 + 0.3275911 * Math.abs( z ) / Math.SQRT2 );
	const poly = t * ( 0.254829592 + t * ( -0.284496736 + t * ( 1.421413741 + t * ( -1.453152027 + t * 1.061405429 ) ) ) );
	const erf = 1 - poly * Math.exp( -( z * z ) / 2 );
	return z >= 0 ? ( 1 + erf ) / 2 : ( 1 - erf ) / 2;
}

/**
 * @param {number} baseAdopters
 * @param {number} baseUnits
 * @param {number} adopters
 * @param {number} units
 * @return {Object|null}
 */
function compareRates( baseAdopters, baseUnits, adopters, units ) {
	if ( !baseUnits || !units ) {
		return null;
	}

	const p1 = baseAdopters / baseUnits;
	const p2 = adopters / units;
	const pooled = ( baseAdopters + adopters ) / ( baseUnits + units );
	const se = Math.sqrt( pooled * ( 1 - pooled ) * ( 1 / baseUnits + 1 / units ) );
	const z = se > 0 ? ( p2 - p1 ) / se : 0;
	const seDiff = Math.sqrt( p1 * ( 1 - p1 ) / baseUnits + p2 * ( 1 - p2 ) / units );

	return {
		diff: p2 - p1,
		lift: p1 > 0 ? ( p2 - p1 ) / p1 : null,
		pValue: se > 0 ? 2 * ( 1 - normalCdf( Math.abs( z ) ) ) : 1,
		low: p2 - p1 - 1.96 * seDiff,
		high: p2 - p1 + 1.96 * seDiff,
		enoughData: Math.min( baseAdopters, baseUnits - baseAdopters, adopters, units - adopters ) >= 5
	};
}

/**
 * @param {number} x
 * @return {number}
 */
function logGamma( x ) {
	const c = [ 76.18009172947146, -86.50532032941677, 24.01409824083091,
		-1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5 ];
	let y = x;
	const tmp = x + 5.5 - ( x + 0.5 ) * Math.log( x + 5.5 );
	let ser = 1.000000000190015;
	for ( let j = 0; j < 6; j++ ) {
		ser += c[ j ] / ++y;
	}
	return -tmp + Math.log( 2.5066282746310005 * ser / x );
}

/**
 * @param {number} a
 * @param {number} x
 * @return {number}
 */
function gammaP( a, x ) {
	if ( x <= 0 ) {
		return 0;
	}

	const gln = logGamma( a );
	if ( x < a + 1 ) {
		let sum = 1 / a;
		let del = sum;
		for ( let n = 1; n < 500; n++ ) {
			del *= x / ( a + n );
			sum += del;
			if ( Math.abs( del ) < Math.abs( sum ) * 1e-12 ) {
				break;
			}
		}
		return sum * Math.exp( -x + a * Math.log( x ) - gln );
	}

	let b = x + 1 - a;
	let c = 1 / 1e-300;
	let d = 1 / b;
	let h = d;
	for ( let i = 1; i < 500; i++ ) {
		const an = -i * ( i - a );
		b += 2;
		d = an * d + b;
		d = Math.abs( d ) < 1e-300 ? 1e-300 : d;
		c = b + an / c;
		c = Math.abs( c ) < 1e-300 ? 1e-300 : c;
		d = 1 / d;
		const del = d * c;
		h *= del;
		if ( Math.abs( del - 1 ) < 1e-12 ) {
			break;
		}
	}
	return 1 - Math.exp( -x + a * Math.log( x ) - gln ) * h;
}

/**
 * @param {number[]} observed
 * @param {number[]} weights
 * @return {Object|null}
 */
function sampleRatio( observed, weights ) {
	const total = observed.reduce( ( sum, count, i ) => sum + ( weights[ i ] > 0 ? count : 0 ), 0 );
	const weightTotal = weights.reduce( ( a, b ) => a + Math.max( 0, b ), 0 );
	if ( total < 20 || weightTotal <= 0 ) {
		return null;
	}

	let chi = 0;
	let df = -1;
	observed.forEach( ( count, i ) => {
		if ( weights[ i ] <= 0 ) {
			return;
		}
		const expected = total * weights[ i ] / weightTotal;
		chi += ( count - expected ) * ( count - expected ) / expected;
		df++;
	} );

	if ( df < 1 ) {
		return null;
	}
	return { chi, df, pValue: 1 - gammaP( df / 2, chi / 2 ) };
}

module.exports = { normalCdf, compareRates, sampleRatio, gammaP };
