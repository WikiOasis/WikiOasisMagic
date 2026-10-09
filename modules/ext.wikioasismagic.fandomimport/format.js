'use strict';

module.exports = {
	number: ( value ) => mw.language.convertNumber( Number( value ) || 0 ),
	bytes: ( value ) => {
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let size = Number( value ) || 0;
		let unit = 0;
		while ( size >= 1024 && unit < units.length - 1 ) {
			size /= 1024;
			unit++;
		}
		return mw.language.convertNumber( Math.round( size * 10 ) / 10 ) + ' ' + units[ unit ];
	}
};
