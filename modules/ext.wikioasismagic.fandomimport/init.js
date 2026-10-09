'use strict';

const Vue = require( 'vue' );
const App = require( './components/App.vue' );

const config = mw.config.get( 'wgWikiOasisFandomImport' );
const root = document.getElementById( 'wo-fi-app' );

if ( root && config ) {
	Vue.createMwApp( App, { config } ).mount( root );
}
