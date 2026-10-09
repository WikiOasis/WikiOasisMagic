<template>
	<div class="wo-exp">
		<experiment-detail
			v-if="config.view === 'detail'"
			:initial="config.experiment"
			:env="env"
		></experiment-detail>
		<experiment-list
			v-else
			:experiments="config.experiments"
			:env="env"
		></experiment-list>
	</div>
</template>

<script>
const { defineComponent } = require( 'vue' );
const ExperimentDetail = require( './ExperimentDetail.vue' );
const ExperimentList = require( './ExperimentList.vue' );

module.exports = exports = defineComponent( {
	name: 'App',
	components: { ExperimentDetail, ExperimentList },
	props: {
		config: { type: Object, required: true }
	},
	setup( props ) {
		const env = Object.assign( {}, props.config );
		delete env.experiment;
		delete env.experiments;
		return { env };
	}
} );
</script>
