<template>
	<div class="wo-exp-detail">
		<a class="wo-exp-back" :href="env.listUrl">
			<cdx-icon :icon="cdxIconArrowPrevious" size="small"></cdx-icon>
			{{ $i18n( 'wikioasismagic-experiments-back' ).text() }}
		</a>

		<header class="wo-exp-header">
			<div class="wo-exp-header__titlerow">
				<h2 class="wo-exp-header__title">
					{{ experiment.label }}
				</h2>
				<status-chip :status="experiment.status"></status-chip>
			</div>
			<p v-if="experiment.description" class="wo-exp-header__description">
				{{ experiment.description }}
			</p>
			<ul class="wo-exp-facts wo-exp-header__facts">
				<li class="wo-exp-fact">
					<code>{{ experiment.name }}</code>
				</li>
				<li class="wo-exp-fact">
					<cdx-icon :icon="unitIcon" size="small"></cdx-icon>
					{{ audience }}
				</li>
				<li v-if="experiment.owner" class="wo-exp-fact">
					<cdx-icon :icon="cdxIconUserAvatar" size="small"></cdx-icon>
					{{ $i18n( 'wikioasismagic-experiments-owner', experiment.owner ).text() }}
				</li>
				<li v-if="experiment.task" class="wo-exp-fact">
					<cdx-icon :icon="cdxIconLinkExternal" size="small"></cdx-icon>
					<a :href="experiment.task" rel="noopener">{{ $i18n( 'wikioasismagic-experiments-task' ).text() }}</a>
				</li>
				<li v-if="experiment.dashboardUrl" class="wo-exp-fact">
					<cdx-icon :icon="cdxIconChart" size="small"></cdx-icon>
					<a :href="experiment.dashboardUrl" rel="noopener">{{ $i18n( 'wikioasismagic-experiments-dashboard' ).text() }}</a>
				</li>
			</ul>
		</header>

		<cdx-message v-if="experiment.problems.length" type="warning" class="wo-exp-problems">
			<strong>{{ $i18n( 'wikioasismagic-experiments-problems', experiment.problems.length ).text() }}</strong>
			<ul>
				<li v-for="problem in experiment.problems" :key="problem">
					{{ problem }}
				</li>
			</ul>
		</cdx-message>
		<overrides-notice :env="env"></overrides-notice>

		<cdx-tabs v-model:active="tab" class="wo-exp-tabs">
			<cdx-tab name="overview" :label="$i18n( 'wikioasismagic-experiments-tab-overview' ).text()">
				<overview-panel :experiment="experiment" :env="env"></overview-panel>
			</cdx-tab>
			<cdx-tab name="rollout" :label="$i18n( 'wikioasismagic-experiments-tab-rollout' ).text()">
				<rollout-panel
					:experiment="experiment"
					:env="env"
					@saved="onSaved"
				></rollout-panel>
			</cdx-tab>
			<cdx-tab name="results" :label="$i18n( 'wikioasismagic-experiments-tab-results' ).text()">
				<results-panel
					v-if="seen.results"
					:experiment="experiment"
					:env="env"
				></results-panel>
			</cdx-tab>
			<cdx-tab name="history" :label="$i18n( 'wikioasismagic-experiments-tab-history' ).text()">
				<history-panel
					v-if="seen.history"
					:key="historyKey"
					:experiment="experiment"
					:env="env"
				></history-panel>
			</cdx-tab>
		</cdx-tabs>
	</div>
</template>

<script>
const { defineComponent, ref, reactive, computed, watch } = require( 'vue' );
const { CdxIcon, CdxMessage, CdxTabs, CdxTab } = require( '../codex.js' );
const StatusChip = require( './StatusChip.vue' );
const OverridesNotice = require( './OverridesNotice.vue' );
const OverviewPanel = require( './OverviewPanel.vue' );
const RolloutPanel = require( './RolloutPanel.vue' );
const ResultsPanel = require( './ResultsPanel.vue' );
const HistoryPanel = require( './HistoryPanel.vue' );
const describe = require( '../describe.js' );
const {
	cdxIconArrowPrevious, cdxIconUserAvatar, cdxIconLinkExternal, cdxIconChart
} = require( './icons.json' );

const TABS = [ 'overview', 'rollout', 'results', 'history' ];

module.exports = exports = defineComponent( {
	name: 'ExperimentDetail',
	components: {
		CdxIcon, CdxMessage, CdxTabs, CdxTab,
		StatusChip, OverridesNotice, OverviewPanel, RolloutPanel, ResultsPanel, HistoryPanel
	},
	props: {
		initial: { type: Object, required: true },
		env: { type: Object, required: true }
	},
	setup( props ) {
		const experiment = ref( props.initial );
		const fromHash = location.hash.slice( 1 );
		const tab = ref( TABS.indexOf( fromHash ) !== -1 ? fromHash : 'overview' );
		const seen = reactive( { results: tab.value === 'results', history: tab.value === 'history' } );
		const historyKey = ref( 0 );

		watch( tab, ( name ) => {
			seen[ name ] = true;
			history.replaceState( null, '', '#' + name );
		} );

		function onSaved( detail ) {
			experiment.value = detail;
			historyKey.value++;
		}

		return {
			experiment,
			tab,
			seen,
			historyKey,
			onSaved,
			unitIcon: computed( () => describe.unitIcon( experiment.value.unit ) ),
			audience: computed( () => describe.audience( experiment.value.unit, experiment.value.population ) ),
			cdxIconArrowPrevious,
			cdxIconUserAvatar,
			cdxIconLinkExternal,
			cdxIconChart
		};
	}
} );
</script>
