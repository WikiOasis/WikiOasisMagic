<template>
	<div class="wo-exp-panel">
		<div class="wo-exp-tiles">
			<div class="wo-exp-tile">
				<div class="wo-exp-tile__label">
					{{ $i18n( 'wikioasismagic-experiments-tile-enrolled' ).text() }}
				</div>
				<div class="wo-exp-tile__value">
					{{ running ? percent( experiment.rollout / 100 ) : '–' }}
				</div>
				<div class="wo-exp-tile__note">
					{{ enrolledNote }}
				</div>
			</div>
			<div class="wo-exp-tile">
				<div class="wo-exp-tile__label">
					{{ $i18n( 'wikioasismagic-experiments-tile-audience' ).text() }}
				</div>
				<div class="wo-exp-tile__value wo-exp-tile__value--text">
					{{ audience }}
				</div>
				<div class="wo-exp-tile__note">
					{{ audienceNote }}
				</div>
			</div>
			<div class="wo-exp-tile">
				<div class="wo-exp-tile__label">
					{{ $i18n( 'wikioasismagic-experiments-tile-default' ).text() }}
				</div>
				<div class="wo-exp-tile__value wo-exp-tile__value--text">
					{{ experiment.default }}
				</div>
				<div class="wo-exp-tile__note">
					{{ $i18n( 'wikioasismagic-experiments-tile-default-note' ).text() }}
				</div>
			</div>
			<div class="wo-exp-tile">
				<div class="wo-exp-tile__label">
					{{ $i18n( 'wikioasismagic-experiments-tile-schedule' ).text() }}
				</div>
				<div class="wo-exp-tile__value wo-exp-tile__value--text">
					{{ scheduleValue }}
				</div>
				<div class="wo-exp-tile__note">
					{{ scheduleNote }}
				</div>
			</div>
		</div>

		<section class="wo-exp-section">
			<h3 class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-section-split' ).text() }}
			</h3>
			<split-bar
				:variants="experiment.variants"
				:rollout="experiment.rollout"
				:default-variant="experiment.default"
				:active="running"
			></split-bar>
		</section>

		<section class="wo-exp-section">
			<h3 class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-section-variants' ).text() }}
			</h3>
			<cdx-table
				class="wo-exp-table"
				:caption="$i18n( 'wikioasismagic-experiments-section-variants' ).text()"
				:hide-caption="true"
				:columns="variantColumns"
				:data="variantRows"
			>
				<template #item-name="{ row }">
					<span class="wo-exp-variant">
						<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + row.swatch"></span>
						<strong>{{ row.name }}</strong>
					</span>
					<cdx-info-chip v-if="row.isDefault" class="wo-exp-chip-inline">
						{{ $i18n( 'wikioasismagic-experiments-default' ).text() }}
					</cdx-info-chip>
				</template>
				<template #item-share="{ row }">
					{{ row.share }}
				</template>
				<template #item-overall="{ row }">
					{{ row.overall }}
				</template>
				<template #item-changes="{ row }">
					<div class="wo-exp-changes">
						<span
							v-for="ext in row.extensions"
							:key="ext.key"
							class="wo-exp-pill"
							:title="$i18n( 'wikioasismagic-experiments-extension-managewiki' ).text()"
						>
							<cdx-icon :icon="cdxIconPuzzle" size="x-small"></cdx-icon>
							{{ ext.name }}
						</span>
						<code
							v-for="item in row.config"
							:key="item.variable"
							class="wo-exp-config"
						>${{ item.variable }} = {{ item.value }}</code>
						<code v-if="row.params" class="wo-exp-config">{{ row.params }}</code>
						<span v-if="row.nothing" class="wo-exp-muted">
							{{ row.legacy ?
								$i18n( 'wikioasismagic-experiments-changes-legacy' ).text() :
								$i18n( 'wikioasismagic-experiments-changes-none' ).text() }}
						</span>
					</div>
				</template>
				<template #item-preview="{ row }">
					<a v-if="row.preview" :href="row.preview">
						{{ $i18n( 'wikioasismagic-experiments-preview' ).text() }}
					</a>
					<span v-else class="wo-exp-muted">–</span>
				</template>
			</cdx-table>
			<p v-if="experiment.extensionWikis !== null && experiment.extensionWikis !== undefined" class="wo-exp-help">
				{{ $i18n( 'wikioasismagic-experiments-extension-wikis', number( experiment.extensionWikis ), experiment.extensionWikis ).text() }}
			</p>
			<p v-if="hasPreview" class="wo-exp-help">
				{{ $i18n( 'wikioasismagic-experiments-preview-help' ).text() }}
			</p>
		</section>

		<section v-if="experiment.mine" class="wo-exp-section">
			<h3 class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-section-mine-' + experiment.unit ).text() }}
			</h3>
			<p class="wo-exp-mine">
				<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + mineSwatch"></span>
				<span>{{ mineText }}</span>
			</p>
		</section>

		<section class="wo-exp-section">
			<h3 class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-section-metrics' ).text() }}
			</h3>
			<ul v-if="experiment.metrics.length" class="wo-exp-metrics">
				<li v-for="metric in experiment.metrics" :key="metric.name">
					<div class="wo-exp-metrics__head">
						<strong>{{ metric.label }}</strong>
						<cdx-info-chip v-if="metric.name === experiment.primary" status="notice">
							{{ $i18n( 'wikioasismagic-experiments-metric-primary' ).text() }}
						</cdx-info-chip>
					</div>
					<div class="wo-exp-muted">
						{{ counts( metric ) }}
					</div>
					<div v-if="metric.description">
						{{ metric.description }}
					</div>
				</li>
			</ul>
			<p v-else class="wo-exp-muted">
				{{ $i18n( 'wikioasismagic-experiments-metrics-none' ).text() }}
			</p>
			<p class="wo-exp-help">
				{{ windowText }}
			</p>
		</section>

		<section class="wo-exp-section">
			<h3 class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-section-targeting' ).text() }}
			</h3>
			<dl class="wo-exp-dl">
				<dt>{{ $i18n( 'wikioasismagic-experiments-field-wikis' ).text() }}</dt>
				<dd>{{ experiment.state.wikis.length ? experiment.state.wikis.join( ', ' ) : $i18n( 'wikioasismagic-experiments-wikis-all' ).text() }}</dd>
				<dt>{{ $i18n( 'wikioasismagic-experiments-field-excludewikis' ).text() }}</dt>
				<dd>{{ experiment.state.excludeWikis.length ? experiment.state.excludeWikis.join( ', ' ) : '–' }}</dd>
				<dt>{{ $i18n( 'wikioasismagic-experiments-field-salt' ).text() }}</dt>
				<dd><code>{{ experiment.salt }}</code></dd>
			</dl>
			<p class="wo-exp-help">
				{{ $i18n( 'wikioasismagic-experiments-salt-help' ).text() }}
			</p>
		</section>
	</div>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxIcon, CdxInfoChip, CdxTable } = require( '../codex.js' );
const SplitBar = require( './SplitBar.vue' );
const describe = require( '../describe.js' );
const format = require( '../format.js' );
const { swatch } = require( '../swatches.js' );
const { cdxIconPuzzle } = require( './icons.json' );

module.exports = exports = defineComponent( {
	name: 'OverviewPanel',
	components: { CdxIcon, CdxInfoChip, CdxTable, SplitBar },
	props: {
		experiment: { type: Object, required: true },
		env: { type: Object, required: true }
	},
	setup( props ) {
		const e = computed( () => props.experiment );
		const running = computed( () => e.value.status === 'running' );
		const names = computed( () => e.value.variants.map( ( v ) => v.name ) );
		const totalWeight = computed( () => e.value.variants.reduce( ( sum, v ) => sum + v.weight, 0 ) );

		const variantColumns = computed( () => [
			{ id: 'name', label: mw.msg( 'wikioasismagic-experiments-column-variant' ) },
			{ id: 'share', label: mw.msg( 'wikioasismagic-experiments-column-share' ), textAlign: 'number' },
			{ id: 'overall', label: mw.msg( 'wikioasismagic-experiments-column-overall' ), textAlign: 'number' },
			{ id: 'changes', label: mw.msg( 'wikioasismagic-experiments-column-changes' ) }
		].concat( e.value.unit === 'wiki' ? [] : [
			{ id: 'preview', label: mw.msg( 'wikioasismagic-experiments-column-preview' ) }
		] ) );

		const variantRows = computed( () => e.value.variants.map( ( v ) => {
			const share = totalWeight.value > 0 ? v.weight / totalWeight.value : 0;
			let overall = running.value ? share * e.value.rollout / 100 : 0;
			if ( v.name === e.value.default ) {
				overall += running.value ? 1 - e.value.rollout / 100 : 1;
			}
			const config = Object.keys( v.config || {} ).map( ( variable ) => ( {
				variable,
				value: describe.configValue( v.config[ variable ] )
			} ) );
			const params = Object.keys( v.params || {} ).length ? JSON.stringify( v.params ) : '';
			return {
				name: v.name,
				swatch: swatch( v.name, names.value ),
				isDefault: v.name === e.value.default,
				share: format.percent( share ),
				overall: format.percent( overall ),
				extensions: v.extensions,
				config,
				params,
				legacy: v.legacy,
				nothing: !v.extensions.length && !config.length && !params,
				preview: v.preview
			};
		} ) );

		const audienceNote = computed( () => {
			const state = e.value.state;
			if ( state.population === 'all' || !state.start ) {
				return mw.msg( 'wikioasismagic-experiments-audience-note-all' );
			}
			return mw.msg(
				'wikioasismagic-experiments-audience-note-' + state.population,
				format.dateTime( state.start )
			);
		} );

		const enrolledNote = computed( () => {
			if ( !running.value ) {
				return mw.msg( 'wikioasismagic-experiments-enrolled-none', e.value.default );
			}
			if ( e.value.unitTotal !== null && e.value.unitTotal !== undefined ) {
				return mw.message(
					'wikioasismagic-experiments-enrolled-wikis',
					format.number( Math.round( e.value.unitTotal * e.value.rollout / 100 ) ),
					format.number( e.value.unitTotal )
				).text();
			}
			return mw.msg( 'wikioasismagic-experiments-enrolled-note' );
		} );

		const scheduleValue = computed( () => {
			const state = e.value.state;
			if ( !state.active ) {
				return mw.msg( 'wikioasismagic-experiments-schedule-off' );
			}
			return state.start ? format.date( state.start ) : mw.msg( 'wikioasismagic-experiments-schedule-now' );
		} );

		const scheduleNote = computed( () => {
			const state = e.value.state;
			return state.end ?
				mw.msg( 'wikioasismagic-experiments-schedule-until', format.dateTime( state.end ) ) :
				mw.msg( 'wikioasismagic-experiments-schedule-noend' );
		} );

		const mineText = computed( () => {
			const mine = e.value.mine;
			const key = mine.forced ? 'forced' : mine.enrolled ? 'enrolled' : 'outside';
			return mw.msg( 'wikioasismagic-experiments-mine-' + key, mine.variant );
		} );

		return {
			running,
			variantColumns,
			variantRows,
			hasPreview: computed( () => variantRows.value.some( ( row ) => row.preview ) ),
			audience: computed( () => describe.audience( e.value.unit, e.value.population ) ),
			audienceNote,
			enrolledNote,
			scheduleValue,
			scheduleNote,
			mineText,
			mineSwatch: computed( () => swatch( e.value.mine.variant, names.value ) ),
			windowText: computed( () => e.value.window ?
				mw.message( 'wikioasismagic-experiments-window', format.number( e.value.window ), e.value.window ).text() :
				mw.msg( 'wikioasismagic-experiments-window-none' ) ),
			counts: describe.metricCounts,
			percent: format.percent,
			number: format.number,
			cdxIconPuzzle
		};
	}
} );
</script>
