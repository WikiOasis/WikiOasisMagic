<template>
	<div class="wo-exp-panel wo-exp-results">
		<div class="wo-exp-toolbar">
			<cdx-field class="wo-exp-toolbar__field">
				<cdx-select v-model:selected="period" :menu-items="periodItems"></cdx-select>
				<template #label>
					{{ $i18n( 'wikioasismagic-experiments-results-period' ).text() }}
				</template>
			</cdx-field>
			<cdx-field v-if="experiment.metrics.length > 1" class="wo-exp-toolbar__field">
				<cdx-select v-model:selected="metric" :menu-items="metricItems"></cdx-select>
				<template #label>
					{{ $i18n( 'wikioasismagic-experiments-results-metric' ).text() }}
				</template>
			</cdx-field>
		</div>

		<div v-if="loading" class="wo-exp-loading">
			<cdx-progress-bar :inline="true" :aria-label="$i18n( 'wikioasismagic-experiments-loading' ).text()"></cdx-progress-bar>
		</div>
		<cdx-message v-else-if="error" type="error">
			{{ error }}
		</cdx-message>
		<cdx-message v-else-if="!results.available" type="warning">
			{{ $i18n( 'wikioasismagic-experiments-data-unavailable' ).text() }}
		</cdx-message>
		<div v-else-if="!groups.total" class="wo-exp-empty">
			<cdx-icon :icon="cdxIconChart" size="medium"></cdx-icon>
			<p>{{ $i18n( 'wikioasismagic-experiments-results-empty-' + experiment.unit ).text() }}</p>
		</div>

		<template v-else>
			<div class="wo-exp-tiles">
				<div class="wo-exp-tile">
					<div class="wo-exp-tile__label">
						{{ $i18n( 'wikioasismagic-experiments-results-exposed-' + experiment.unit ).text() }}
					</div>
					<div class="wo-exp-tile__value">
						{{ number( groups.total ) }}
					</div>
					<div class="wo-exp-tile__note">
						{{ $i18n( 'wikioasismagic-experiments-results-enrolled', number( groups.enrolled ), number( groups.outside ) ).text() }}
					</div>
				</div>
				<div
					v-for="group in groups.list"
					:key="group.key"
					class="wo-exp-tile"
				>
					<div class="wo-exp-tile__label">
						<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + group.swatch"></span>
						{{ group.label }}
					</div>
					<div class="wo-exp-tile__value">
						{{ number( group.units ) }}
					</div>
					<div class="wo-exp-tile__note">
						{{ group.enrolled ?
							$i18n( 'wikioasismagic-experiments-results-share', percent( group.units / ( groups.enrolled || 1 ) ) ).text() :
							$i18n( 'wikioasismagic-experiments-results-reference' ).text() }}
					</div>
				</div>
			</div>

			<cdx-message v-if="groups.ineligible" type="notice" :inline="true">
				{{ $i18n( 'wikioasismagic-experiments-results-ineligible', number( groups.ineligible ), groups.ineligible ).text() }}
			</cdx-message>
			<cdx-message v-if="srm && srm.pValue < 0.001" type="warning">
				{{ $i18n( 'wikioasismagic-experiments-results-srm', pValue( srm.pValue ) ).text() }}
			</cdx-message>

			<section v-if="experiment.metrics.length" class="wo-exp-section">
				<h3 class="wo-exp-section__title">
					{{ $i18n( 'wikioasismagic-experiments-results-summary' ).text() }}
				</h3>
				<div class="wo-exp-table-wrap">
					<table class="wo-exp-table">
						<thead>
							<tr>
								<th>{{ $i18n( 'wikioasismagic-experiments-column-metric' ).text() }}</th>
								<th
									v-for="group in groups.list"
									:key="group.key"
									class="wo-exp-num"
								>
									<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + group.swatch"></span>
									{{ group.label }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="row in summary"
								:key="row.metric.name"
								:class="{ 'wo-exp-table__row--selected': row.metric.name === metric }"
							>
								<th scope="row">
									<a href="#" @click.prevent="metric = row.metric.name">{{ row.metric.label }}</a>
								</th>
								<td
									v-for="cell in row.cells"
									:key="cell.key"
									class="wo-exp-num"
								>
									<span>{{ percent( cell.rate ) }}</span>
									<span
										v-if="cell.verdict"
										class="wo-exp-verdict"
										:class="'wo-exp-verdict--' + cell.verdict"
										:title="cell.title"
									>{{ cell.lift }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p class="wo-exp-help">
					{{ $i18n( 'wikioasismagic-experiments-results-summary-help', baseline ? baseline.label : '' ).text() }}
				</p>
			</section>

			<section v-if="selected" class="wo-exp-section">
				<h3 class="wo-exp-section__title">
					{{ selected.label }}
				</h3>
				<p class="wo-exp-muted">
					{{ counts( selected ) }}<template v-if="selected.description">
						— {{ selected.description }}
					</template>
				</p>

				<div class="wo-exp-table-wrap">
					<table class="wo-exp-table">
						<thead>
							<tr>
								<th>{{ $i18n( 'wikioasismagic-experiments-column-group' ).text() }}</th>
								<th class="wo-exp-num">
									{{ $i18n( 'wikioasismagic-experiments-column-units' ).text() }}
								</th>
								<th class="wo-exp-num">
									{{ $i18n( 'wikioasismagic-experiments-column-adopters' ).text() }}
								</th>
								<th class="wo-exp-num">
									{{ $i18n( 'wikioasismagic-experiments-column-rate' ).text() }}
								</th>
								<th class="wo-exp-num">
									{{ $i18n( 'wikioasismagic-experiments-column-events' ).text() }}
								</th>
								<th class="wo-exp-num">
									{{ $i18n( 'wikioasismagic-experiments-column-perunit' ).text() }}
								</th>
								<th class="wo-exp-num">
									{{ $i18n( 'wikioasismagic-experiments-column-change' ).text() }}
								</th>
								<th>{{ $i18n( 'wikioasismagic-experiments-column-confidence' ).text() }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in detailRows" :key="row.key">
								<th scope="row">
									<span class="wo-exp-variant">
										<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + row.swatch"></span>
										{{ row.label }}
									</span>
								</th>
								<td class="wo-exp-num">
									{{ number( row.units ) }}
								</td>
								<td class="wo-exp-num">
									{{ number( row.adopters ) }}
								</td>
								<td class="wo-exp-num">
									<strong>{{ percent( row.rate ) }}</strong>
								</td>
								<td class="wo-exp-num">
									{{ number( row.events ) }}
								</td>
								<td class="wo-exp-num">
									{{ row.perUnit }}
								</td>
								<td class="wo-exp-num">
									<span v-if="row.comparison" :title="row.interval">{{ row.lift }}</span>
									<span v-else class="wo-exp-muted">–</span>
								</td>
								<td>
									<cdx-info-chip v-if="row.verdict === 'win' || row.verdict === 'loss'" :status="row.verdict === 'win' ? 'success' : 'warning'">
										{{ row.confidence }}
									</cdx-info-chip>
									<span v-else class="wo-exp-muted">{{ row.confidence }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="wo-exp-chartbar">
					<cdx-toggle-button-group v-model="chartMode" :buttons="chartButtons"></cdx-toggle-button-group>
				</div>
				<line-chart
					:days="chart.days"
					:series="chart.series"
					:value-format="chartMode === 'adoption' ? 'percent' : 'number'"
					:aria-label="chartLabel"
				></line-chart>

				<template v-if="topUnits.length">
					<h4 class="wo-exp-subtitle">
						{{ $i18n( 'wikioasismagic-experiments-results-top' ).text() }}
					</h4>
					<div class="wo-exp-table-wrap">
						<table class="wo-exp-table">
							<thead>
								<tr>
									<th>{{ $i18n( 'wikioasismagic-experiments-column-wiki' ).text() }}</th>
									<th>{{ $i18n( 'wikioasismagic-experiments-column-variant' ).text() }}</th>
									<th class="wo-exp-num">
										{{ $i18n( 'wikioasismagic-experiments-column-events' ).text() }}
									</th>
									<th>{{ $i18n( 'wikioasismagic-experiments-column-first' ).text() }}</th>
									<th>{{ $i18n( 'wikioasismagic-experiments-column-last' ).text() }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in topUnits" :key="row.unit">
									<td>
										<a v-if="row.url" :href="row.url">{{ row.unit }}</a>
										<span v-else>{{ row.unit }}</span>
									</td>
									<td>
										<span class="wo-exp-variant">
											<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + row.swatch"></span>
											{{ row.group }}
										</span>
									</td>
									<td class="wo-exp-num">
										{{ number( row.count ) }}
									</td>
									<td>{{ date( row.first ) }}</td>
									<td>{{ date( row.last ) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</section>

			<p class="wo-exp-help">
				{{ $i18n( 'wikioasismagic-experiments-results-help' ).text() }}
			</p>
		</template>
	</div>
</template>

<script>
const { defineComponent, ref, computed, watch } = require( 'vue' );
const {
	CdxField, CdxIcon, CdxInfoChip, CdxMessage, CdxProgressBar, CdxSelect, CdxToggleButtonGroup
} = require( '../codex.js' );
const LineChart = require( './LineChart.vue' );
const describe = require( '../describe.js' );
const format = require( '../format.js' );
const stats = require( '../stats.js' );
const { swatch, OUTSIDE } = require( '../swatches.js' );
const { cdxIconChart } = require( './icons.json' );

const OUTSIDE_KEY = '(outside)';
const SIGNIFICANCE = 0.05;

module.exports = exports = defineComponent( {
	name: 'ResultsPanel',
	components: {
		CdxField, CdxIcon, CdxInfoChip, CdxMessage, CdxProgressBar, CdxSelect, CdxToggleButtonGroup, LineChart
	},
	props: {
		experiment: { type: Object, required: true },
		env: { type: Object, required: true }
	},
	setup( props ) {
		const period = ref( '0' );
		const loading = ref( true );
		const error = ref( '' );
		const results = ref( null );
		const metrics = computed( () => props.experiment.metrics );
		const metric = ref( props.experiment.primary || ( metrics.value[ 0 ] && metrics.value[ 0 ].name ) || null );
		const chartMode = ref( 'adoption' );
		const names = computed( () => props.experiment.variants.map( ( v ) => v.name ) );

		function load() {
			loading.value = true;
			error.value = '';
			new mw.Api().get( {
				action: 'wikioasisexperiments',
				experiment: props.experiment.name,
				prop: 'results',
				days: period.value,
				formatversion: 2
			} ).then( ( data ) => {
				results.value = data.wikioasisexperiments.results;
			}, ( code ) => {
				error.value = mw.msg( 'wikioasismagic-experiments-results-failed', code );
			} ).always( () => {
				loading.value = false;
			} );
		}
		watch( period, load );
		load();

		const groupKey = ( row ) => row.enrolled ? row.variant : OUTSIDE_KEY;

		const groups = computed( () => {
			const units = {};
			let ineligible = 0;
			( ( results.value && results.value.groups ) || [] ).forEach( ( row ) => {
				if ( !row.eligible ) {
					ineligible += row.units;
					return;
				}
				units[ groupKey( row ) ] = ( units[ groupKey( row ) ] || 0 ) + row.units;
			} );

			const order = names.value.concat( Object.keys( units )
				.filter( ( key ) => key !== OUTSIDE_KEY && names.value.indexOf( key ) === -1 ) );
			const list = order.filter( ( key ) => units[ key ] ).map( ( key ) => ( {
				key,
				label: key,
				units: units[ key ],
				swatch: swatch( key, names.value ),
				enrolled: true
			} ) );
			const enrolled = list.reduce( ( sum, g ) => sum + g.units, 0 );
			if ( units[ OUTSIDE_KEY ] ) {
				list.push( {
					key: OUTSIDE_KEY,
					label: mw.msg( 'wikioasismagic-experiments-results-outside' ),
					units: units[ OUTSIDE_KEY ],
					swatch: OUTSIDE,
					enrolled: false
				} );
			}
			return {
				list,
				enrolled,
				outside: units[ OUTSIDE_KEY ] || 0,
				total: enrolled + ( units[ OUTSIDE_KEY ] || 0 ),
				ineligible
			};
		} );

		const baseline = computed( () => {
			const enrolled = groups.value.list.filter( ( g ) => g.enrolled );
			return enrolled.find( ( g ) => g.key === 'control' ) ||
				enrolled.find( ( g ) => g.key === props.experiment.default ) ||
				enrolled[ 0 ] || null;
		} );

		const adoption = computed( () => {
			const table = {};
			( ( results.value && results.value.adoption ) || [] ).forEach( ( row ) => {
				const key = groupKey( row );
				table[ row.metric ] = table[ row.metric ] || {};
				const cell = table[ row.metric ][ key ] || { adopters: 0, events: 0 };
				cell.adopters += row.adopters;
				cell.events += row.events;
				table[ row.metric ][ key ] = cell;
			} );
			return table;
		} );

		const cellFor = ( metricName, group ) => {
			const cell = ( adoption.value[ metricName ] || {} )[ group.key ] || { adopters: 0, events: 0 };
			return {
				adopters: cell.adopters,
				events: cell.events,
				rate: group.units ? cell.adopters / group.units : null
			};
		};

		const pValue = ( p ) => p < 0.001 ? '< 0.001' : format.number( Number( p.toFixed( 3 ) ) );

		const compare = ( metricName, group ) => {
			const base = baseline.value;
			if ( !base || !group.enrolled || group.key === base.key ) {
				return null;
			}
			const a = cellFor( metricName, base );
			const b = cellFor( metricName, group );
			const result = stats.compareRates( a.adopters, base.units, b.adopters, group.units );
			if ( !result ) {
				return null;
			}
			let verdict = 'unclear';
			if ( !result.enoughData ) {
				verdict = 'little';
			} else if ( result.pValue < SIGNIFICANCE ) {
				verdict = result.diff > 0 ? 'win' : 'loss';
			}
			return Object.assign( result, { verdict } );
		};

		const liftText = ( comparison ) => comparison.lift !== null ?
			format.signedPercent( comparison.lift ) :
			mw.msg(
				'wikioasismagic-experiments-points',
				( comparison.diff > 0 ? '+' : '' ) + format.number( Number( ( comparison.diff * 100 ).toFixed( 1 ) ) )
			);

		const summary = computed( () => metrics.value.map( ( m ) => ( {
			metric: m,
			cells: groups.value.list.map( ( group ) => {
				const comparison = compare( m.name, group );
				return {
					key: group.key,
					rate: cellFor( m.name, group ).rate,
					verdict: comparison ? comparison.verdict : null,
					lift: comparison ? liftText( comparison ) : '',
					title: comparison ? mw.msg( 'wikioasismagic-experiments-pvalue', pValue( comparison.pValue ) ) : ''
				};
			} )
		} ) ) );

		const selected = computed( () => metrics.value.find( ( m ) => m.name === metric.value ) || null );

		const detailRows = computed( () => {
			if ( !selected.value ) {
				return [];
			}
			return groups.value.list.map( ( group ) => {
				const cell = cellFor( selected.value.name, group );
				const comparison = compare( selected.value.name, group );
				let confidence;
				if ( !group.enrolled ) {
					confidence = mw.msg( 'wikioasismagic-experiments-confidence-reference' );
				} else if ( baseline.value && group.key === baseline.value.key ) {
					confidence = mw.msg( 'wikioasismagic-experiments-confidence-baseline' );
				} else if ( !comparison ) {
					confidence = '–';
				} else {
					confidence = mw.msg( 'wikioasismagic-experiments-confidence-' + comparison.verdict, pValue( comparison.pValue ) );
				}
				return {
					key: group.key,
					label: group.label,
					swatch: group.swatch,
					units: group.units,
					adopters: cell.adopters,
					rate: cell.rate,
					events: cell.events,
					perUnit: group.units ? format.number( Number( ( cell.events / group.units ).toFixed( 2 ) ) ) : '–',
					comparison,
					lift: comparison ? liftText( comparison ) : '',
					interval: comparison ? mw.msg(
						'wikioasismagic-experiments-interval',
						format.signedPercent( comparison.low ),
						format.signedPercent( comparison.high )
					) : '',
					verdict: comparison ? comparison.verdict : null,
					confidence
				};
			} );
		} );

		const chart = computed( () => {
			const empty = { days: [], series: [] };
			if ( !results.value || !selected.value ) {
				return empty;
			}
			const series = results.value.series[ selected.value.name ] || { adopters: [], events: [] };
			const rows = chartMode.value === 'adoption' ?
				results.value.exposures.concat( series.adopters ) :
				series.events;
			const allDays = rows.map( ( row ) => row.day ).sort();
			if ( !allDays.length ) {
				return empty;
			}
			const today = format.toDay( new Date() );
			const last = today > allDays[ allDays.length - 1 ] ? today : allDays[ allDays.length - 1 ];
			const earliest = format.toDay( new Date( format.parseDay( last ).getTime() - 399 * 86400000 ) );
			const days = format.dayRange( allDays[ 0 ] > earliest ? allDays[ 0 ] : earliest, last );
			const index = {};
			days.forEach( ( day, i ) => {
				index[ day ] = i;
			} );

			const perDay = ( list, key ) => {
				const values = days.map( () => 0 );
				list.forEach( ( row ) => {
					if ( groupKey( row ) === key && index[ row.day ] !== undefined ) {
						values[ index[ row.day ] ] += row.count;
					}
				} );
				return values;
			};

			return {
				days,
				series: groups.value.list.map( ( group ) => {
					let values;
					if ( chartMode.value === 'adoption' ) {
						const exposed = perDay( results.value.exposures, group.key );
						const adopted = perDay( series.adopters, group.key );
						let cumExposed = 0;
						let cumAdopted = 0;
						values = days.map( ( day, i ) => {
							cumExposed += exposed[ i ];
							cumAdopted += adopted[ i ];
							return cumExposed ? cumAdopted / cumExposed : null;
						} );
					} else {
						values = perDay( series.events, group.key );
					}
					return { key: group.key, label: group.label, swatch: group.swatch, values };
				} )
			};
		} );

		const topUnits = computed( () => {
			if ( !results.value || !selected.value || !results.value.top ) {
				return [];
			}
			return ( results.value.top[ selected.value.name ] || [] ).map( ( row ) => ( Object.assign( {}, row, {
				group: row.enrolled ? row.variant : mw.msg( 'wikioasismagic-experiments-results-outside' ),
				swatch: row.enrolled ? swatch( row.variant, names.value ) : OUTSIDE
			} ) ) );
		} );

		const srm = computed( () => {
			const enrolled = groups.value.list.filter( ( g ) => g.enrolled &&
				( props.experiment.state.weights[ g.key ] || 0 ) > 0 );
			return stats.sampleRatio(
				enrolled.map( ( g ) => g.units ),
				enrolled.map( ( g ) => props.experiment.state.weights[ g.key ] || 0 )
			);
		} );

		return {
			period,
			periodItems: [ '0', '7', '30', '90' ].map( ( value ) => ( {
				value,
				label: mw.msg( 'wikioasismagic-experiments-period-' + value )
			} ) ),
			metric,
			metricItems: computed( () => metrics.value.map( ( m ) => ( { value: m.name, label: m.label } ) ) ),
			loading,
			error,
			results,
			groups,
			baseline,
			summary,
			selected,
			detailRows,
			chartMode,
			chartButtons: [
				{ value: 'adoption', label: mw.msg( 'wikioasismagic-experiments-chart-adoption' ) },
				{ value: 'events', label: mw.msg( 'wikioasismagic-experiments-chart-events' ) }
			],
			chart,
			chartLabel: computed( () => mw.msg(
				chartMode.value === 'adoption' ?
					'wikioasismagic-experiments-chart-adoption-label' :
					'wikioasismagic-experiments-chart-events-label',
				selected.value ? selected.value.label : ''
			) ),
			topUnits,
			srm,
			pValue,
			counts: describe.metricCounts,
			number: format.number,
			percent: format.percent,
			date: format.date,
			cdxIconChart
		};
	}
} );
</script>
