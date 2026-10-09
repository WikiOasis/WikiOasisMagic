<template>
	<div class="wo-exp-list">
		<cdx-message v-if="!env.stateAvailable" type="warning">
			{{ $i18n( 'wikioasismagic-experiments-state-unavailable' ).text() }}
		</cdx-message>
		<cdx-message v-else-if="!env.isCentral && env.centralUrl" type="notice">
			<span v-i18n-html:wikioasismagic-experiments-manage-on-central="[ env.centralUrl ]"></span>
		</cdx-message>
		<overrides-notice :env="env"></overrides-notice>

		<div v-if="experiments.length" class="wo-exp-toolbar">
			<cdx-search-input
				v-model="query"
				class="wo-exp-toolbar__search"
				:clearable="true"
				:aria-label="$i18n( 'wikioasismagic-experiments-search' ).text()"
				:placeholder="$i18n( 'wikioasismagic-experiments-search' ).text()"
			></cdx-search-input>
			<cdx-toggle-button-group
				v-model="filter"
				:buttons="filterButtons"
			></cdx-toggle-button-group>
		</div>

		<div v-if="filtered.length" class="wo-exp-grid">
			<experiment-card
				v-for="experiment in filtered"
				:key="experiment.name"
				:experiment="experiment"
			></experiment-card>
		</div>
		<div v-else class="wo-exp-empty">
			<cdx-icon :icon="cdxIconLabFlask" size="medium"></cdx-icon>
			<p v-if="experiments.length">
				{{ $i18n( 'wikioasismagic-experiments-nomatch' ).text() }}
			</p>
			<p v-else v-i18n-html:wikioasismagic-experiments-empty></p>
		</div>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxIcon, CdxMessage, CdxSearchInput, CdxToggleButtonGroup } = require( '../codex.js' );
const ExperimentCard = require( './ExperimentCard.vue' );
const OverridesNotice = require( './OverridesNotice.vue' );
const { cdxIconLabFlask } = require( './icons.json' );

module.exports = exports = defineComponent( {
	name: 'ExperimentList',
	components: { CdxIcon, CdxMessage, CdxSearchInput, CdxToggleButtonGroup, ExperimentCard, OverridesNotice },
	props: {
		experiments: { type: Array, required: true },
		env: { type: Object, required: true }
	},
	setup( props ) {
		const query = ref( '' );
		const filter = ref( 'all' );

		const isLive = ( e ) => e.status === 'running' || e.status === 'scheduled';
		const count = ( test ) => mw.language.convertNumber( props.experiments.filter( test ).length );

		const filterButtons = computed( () => [
			{ value: 'all', label: mw.msg( 'wikioasismagic-experiments-filter-all', count( () => true ) ) },
			{ value: 'live', label: mw.msg( 'wikioasismagic-experiments-filter-live', count( isLive ) ) },
			{ value: 'stopped', label: mw.msg( 'wikioasismagic-experiments-filter-stopped', count( ( e ) => !isLive( e ) ) ) }
		] );

		const filtered = computed( () => {
			const q = query.value.trim().toLowerCase();
			return props.experiments.filter( ( e ) => {
				if ( filter.value === 'live' && !isLive( e ) ) {
					return false;
				}
				if ( filter.value === 'stopped' && isLive( e ) ) {
					return false;
				}
				return !q || [ e.name, e.label, e.description, e.owner || '' ]
					.some( ( text ) => text.toLowerCase().indexOf( q ) !== -1 );
			} );
		} );

		return { query, filter, filterButtons, filtered, cdxIconLabFlask };
	}
} );
</script>
