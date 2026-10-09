'use strict';

const Vue = require( 'vue' );
const App = require( './components/App.vue' );

const config = mw.config.get( 'wgWikiOasisExperiments' );
const root = document.getElementById( 'wo-experiments-app' );

if ( root && config ) {
	Vue.createMwApp( App, { config } ).mount( root );
}
