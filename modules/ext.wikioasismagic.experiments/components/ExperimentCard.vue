<template>
	<a class="wo-exp-card" :href="experiment.url">
		<div class="wo-exp-card__top">
			<div class="wo-exp-card__heading">
				<span class="wo-exp-card__title">{{ experiment.label }}</span>
				<code class="wo-exp-card__name">{{ experiment.name }}</code>
			</div>
			<status-chip :status="experiment.status"></status-chip>
		</div>
		<p v-if="experiment.description" class="wo-exp-card__description">
			{{ experiment.description }}
		</p>
		<div class="wo-exp-facts">
			<span class="wo-exp-fact">
				<cdx-icon :icon="unitIcon" size="small"></cdx-icon>
				{{ audience }}
			</span>
			<span v-if="changes" class="wo-exp-fact">
				<cdx-icon :icon="cdxIconPuzzle" size="small"></cdx-icon>
				{{ changes }}
			</span>
		</div>
		<split-bar
			:variants="experiment.variants"
			:rollout="experiment.rollout"
			:default-variant="experiment.default"
			:active="experiment.status === 'running'"
			compact
		></split-bar>
		<div class="wo-exp-card__footer">
			<span>{{ rolloutText }}</span>
			<span v-if="experiment.exposed !== null">{{ exposedText }}</span>
			<span v-if="experiment.problems" class="wo-exp-card__problems">
				<cdx-icon :icon="cdxIconAlert" size="small"></cdx-icon>
				{{ $i18n( 'wikioasismagic-experiments-problems-count', experiment.problems ).text() }}
			</span>
		</div>
	</a>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxIcon } = require( '../codex.js' );
const StatusChip = require( './StatusChip.vue' );
const SplitBar = require( './SplitBar.vue' );
const describe = require( '../describe.js' );
const format = require( '../format.js' );
const { cdxIconPuzzle, cdxIconAlert } = require( './icons.json' );

module.exports = exports = defineComponent( {
	name: 'ExperimentCard',
	components: { CdxIcon, StatusChip, SplitBar },
	props: {
		experiment: { type: Object, required: true }
	},
	setup( props ) {
		const e = props.experiment;
		return {
			cdxIconPuzzle,
			cdxIconAlert,
			unitIcon: describe.unitIcon( e.unit ),
			audience: describe.audience( e.unit, e.population ),
			changes: computed( () => {
				const text = describe.changes( e.variants );
				return text ? mw.msg( 'wikioasismagic-experiments-changes', text ) : '';
			} ),
			rolloutText: computed( () => e.status === 'running' ?
				mw.msg( 'wikioasismagic-experiments-card-enrolled', format.percent( e.rollout / 100 ) ) :
				mw.msg( 'wikioasismagic-experiments-card-default', e.default ) ),
			exposedText: computed( () => mw.message(
				'wikioasismagic-experiments-card-exposed-' + e.unit,
				format.number( e.exposed ),
				e.exposed
			).text() )
		};
	}
} );
</script>
