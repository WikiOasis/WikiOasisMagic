'use strict';

const COLOURS = 5;

/**
 * @param {string} variant
 * @param {string[]} variants
 * @return {string}
 */
function swatch( variant, variants ) {
	if ( variant === 'control' ) {
		return 'control';
	}
	const others = variants.filter( ( name ) => name !== 'control' );
	const index = others.indexOf( variant );
	return String( ( index < 0 ? 0 : index ) % COLOURS );
}

module.exports = { swatch, OUTSIDE: 'outside' };
