<template>
	<div class="wo-exp-panel wo-exp-rollout">
		<cdx-message v-if="readOnlyReason" type="notice">
			<span v-if="readOnlyReason === 'central'" v-i18n-html:wikioasismagic-experiments-readonly-central="[ env.centralUrl || '' ]"></span>
			<span v-else>{{ $i18n( 'wikioasismagic-experiments-readonly-' + readOnlyReason ).text() }}</span>
		</cdx-message>
		<cdx-message
			v-if="result"
			:type="result.type"
			:allow-user-dismiss="true"
			@user-dismissed="result = null"
		>
			{{ result.text }}
		</cdx-message>

		<fieldset class="wo-exp-section" :disabled="readOnly">
			<legend class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-edit-status' ).text() }}
			</legend>
			<cdx-toggle-switch v-model="draft.active" :disabled="readOnly">
				{{ draft.active ?
					$i18n( 'wikioasismagic-experiments-edit-active-on' ).text() :
					$i18n( 'wikioasismagic-experiments-edit-active-off' ).text() }}
			</cdx-toggle-switch>
			<p class="wo-exp-help">
				{{ $i18n( 'wikioasismagic-experiments-edit-active-help', draft.default ).text() }}
			</p>
		</fieldset>

		<fieldset class="wo-exp-section" :disabled="readOnly">
			<legend class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-edit-rollout' ).text() }}
			</legend>
			<div class="wo-exp-rollout__share">
				<cdx-field :status="errors.rollout ? 'error' : 'default'" :messages="{ error: errors.rollout }">
					<cdx-text-input
						v-model="draft.rollout"
						input-type="number"
						min="0"
						max="100"
						step="0.1"
						class="wo-exp-input-number"
						:disabled="readOnly"
						:aria-label="$i18n( 'wikioasismagic-experiments-edit-rollout' ).text()"
					></cdx-text-input>
					<template #label>
						{{ $i18n( 'wikioasismagic-experiments-edit-rollout-label' ).text() }}
					</template>
					<template #help-text>
						{{ rolloutHelp }}
					</template>
				</cdx-field>
				<cdx-toggle-button-group
					v-model="rolloutPreset"
					class="wo-exp-presets"
					:buttons="presetButtons"
					:disabled="readOnly"
				></cdx-toggle-button-group>
			</div>
		</fieldset>

		<fieldset class="wo-exp-section" :disabled="readOnly">
			<legend class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-edit-variants' ).text() }}
			</legend>
			<table class="wo-exp-weights">
				<thead>
					<tr>
						<th>{{ $i18n( 'wikioasismagic-experiments-column-variant' ).text() }}</th>
						<th>{{ $i18n( 'wikioasismagic-experiments-column-weight' ).text() }}</th>
						<th class="wo-exp-num">
							{{ $i18n( 'wikioasismagic-experiments-column-share' ).text() }}
						</th>
						<th class="wo-exp-num">
							{{ $i18n( 'wikioasismagic-experiments-column-overall' ).text() }}
						</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in weightRows" :key="row.name">
						<td>
							<span class="wo-exp-variant">
								<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + row.swatch"></span>
								<strong>{{ row.name }}</strong>
							</span>
						</td>
						<td>
							<cdx-text-input
								v-model="draft.weights[ row.name ]"
								input-type="number"
								min="0"
								step="1"
								class="wo-exp-input-number"
								:status="errors.weights ? 'error' : 'default'"
								:disabled="readOnly"
								:aria-label="$i18n( 'wikioasismagic-experiments-weight-label', row.name ).text()"
							></cdx-text-input>
						</td>
						<td class="wo-exp-num">
							{{ row.share }}
						</td>
						<td class="wo-exp-num">
							{{ row.overall }}
						</td>
						<td class="wo-exp-weights__action">
							<cdx-button
								v-if="row.name !== draft.default || draft.active"
								weight="quiet"
								size="small"
								:disabled="readOnly"
								@click="ship( row.name )"
							>
								{{ $i18n( 'wikioasismagic-experiments-ship' ).text() }}
							</cdx-button>
						</td>
					</tr>
				</tbody>
			</table>
			<div class="wo-exp-row">
				<cdx-button :disabled="readOnly" @click="evenSplit">
					{{ $i18n( 'wikioasismagic-experiments-even' ).text() }}
				</cdx-button>
				<span v-if="errors.weights" class="wo-exp-error">{{ errors.weights }}</span>
			</div>
			<p class="wo-exp-help">
				{{ $i18n( 'wikioasismagic-experiments-ship-help' ).text() }}
			</p>

			<cdx-field class="wo-exp-field">
				<cdx-select
					v-model:selected="draft.default"
					:menu-items="variantItems"
					:disabled="readOnly"
				></cdx-select>
				<template #label>
					{{ $i18n( 'wikioasismagic-experiments-field-default' ).text() }}
				</template>
				<template #help-text>
					{{ $i18n( 'wikioasismagic-experiments-tile-default-note' ).text() }}
				</template>
			</cdx-field>
		</fieldset>

		<fieldset v-if="experiment.unit !== 'browser'" class="wo-exp-section" :disabled="readOnly">
			<legend class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-edit-population' ).text() }}
			</legend>
			<cdx-radio
				v-for="population in populations"
				:key="population"
				v-model="draft.population"
				name="wo-exp-population"
				:input-value="population"
				:disabled="readOnly"
			>
				{{ $i18n( 'wikioasismagic-experiments-audience-' + experiment.unit + '-' + population ).text() }}
				<template #description>
					{{ $i18n( 'wikioasismagic-experiments-population-' + experiment.unit + '-' + population ).text() }}
				</template>
			</cdx-radio>
		</fieldset>

		<fieldset class="wo-exp-section" :disabled="readOnly">
			<legend class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-edit-schedule' ).text() }}
			</legend>
			<div class="wo-exp-row wo-exp-row--fields">
				<cdx-field>
					<cdx-text-input
						v-model="draft.start"
						input-type="datetime-local"
						:clearable="true"
						:disabled="readOnly"
					></cdx-text-input>
					<template #label>
						{{ $i18n( 'wikioasismagic-experiments-field-start' ).text() }}
					</template>
					<template #help-text>
						{{ $i18n( 'wikioasismagic-experiments-field-start-help' ).text() }}
					</template>
				</cdx-field>
				<cdx-field :status="errors.end ? 'error' : 'default'" :messages="{ error: errors.end }">
					<cdx-text-input
						v-model="draft.end"
						input-type="datetime-local"
						:clearable="true"
						:disabled="readOnly"
					></cdx-text-input>
					<template #label>
						{{ $i18n( 'wikioasismagic-experiments-field-end' ).text() }}
					</template>
					<template #help-text>
						{{ $i18n( 'wikioasismagic-experiments-field-end-help' ).text() }}
					</template>
				</cdx-field>
			</div>
		</fieldset>

		<fieldset class="wo-exp-section" :disabled="readOnly">
			<legend class="wo-exp-section__title">
				{{ $i18n( 'wikioasismagic-experiments-edit-wikis' ).text() }}
			</legend>
			<cdx-field class="wo-exp-field">
				<cdx-chip-input
					v-model:input-chips="draft.wikis"
					:chip-validator="validWiki"
					:disabled="readOnly"
				></cdx-chip-input>
				<template #label>
					{{ $i18n( 'wikioasismagic-experiments-field-wikis' ).text() }}
				</template>
				<template #help-text>
					{{ $i18n( 'wikioasismagic-experiments-field-wikis-help-' + experiment.unit ).text() }}
				</template>
			</cdx-field>
			<cdx-field class="wo-exp-field">
				<cdx-chip-input
					v-model:input-chips="draft.excludeWikis"
					:chip-validator="validWiki"
					:disabled="readOnly"
				></cdx-chip-input>
				<template #label>
					{{ $i18n( 'wikioasismagic-experiments-field-excludewikis' ).text() }}
				</template>
				<template #help-text>
					{{ $i18n( 'wikioasismagic-experiments-field-excludewikis-help' ).text() }}
				</template>
			</cdx-field>
		</fieldset>

		<div v-if="!readOnly" class="wo-exp-savebar" :class="{ 'wo-exp-savebar--dirty': changes.length }">
			<span class="wo-exp-savebar__status">
				{{ changes.length ?
					$i18n( 'wikioasismagic-experiments-unsaved', changes.length ).text() :
					lastChangeText }}
			</span>
			<div class="wo-exp-savebar__actions">
				<cdx-button
					v-if="experiment.overrides.length"
					weight="quiet"
					action="destructive"
					@click="openConfirm( 'reset' )"
				>
					{{ $i18n( 'wikioasismagic-experiments-reset' ).text() }}
				</cdx-button>
				<cdx-button
					weight="quiet"
					:disabled="!changes.length"
					@click="discard"
				>
					{{ $i18n( 'wikioasismagic-experiments-discard' ).text() }}
				</cdx-button>
				<cdx-button
					action="progressive"
					weight="primary"
					:disabled="!changes.length || hasErrors"
					@click="openConfirm( 'save' )"
				>
					{{ $i18n( 'wikioasismagic-experiments-review' ).text() }}
				</cdx-button>
			</div>
		</div>

		<cdx-dialog
			v-model:open="confirmOpen"
			:title="dialogTitle"
			:use-close-button="true"
			:primary-action="primaryAction"
			:default-action="{ label: $i18n( 'wikioasismagic-experiments-cancel' ).text() }"
			@primary="submit"
			@default="confirmOpen = false"
		>
			<ul v-if="mode === 'save'" class="wo-exp-changelist">
				<li v-for="change in changes" :key="change.field">
					<strong>{{ change.label }}</strong>
					<span class="wo-exp-changelist__old">{{ change.old }}</span>
					<span aria-hidden="true">→</span>
					<span class="wo-exp-changelist__new">{{ change.new }}</span>
				</li>
			</ul>
			<p v-else>
				{{ $i18n( 'wikioasismagic-experiments-reset-confirm' ).text() }}
			</p>
			<cdx-message
				v-for="warning in warnings"
				:key="warning"
				type="warning"
				:inline="true"
				class="wo-exp-dialog-warning"
			>
				{{ warning }}
			</cdx-message>
			<cdx-field class="wo-exp-field">
				<cdx-text-input v-model="reason"></cdx-text-input>
				<template #label>
					{{ $i18n( 'wikioasismagic-experiments-reason' ).text() }}
				</template>
			</cdx-field>
			<cdx-message v-if="saveError" type="error" :inline="true">
				{{ saveError }}
			</cdx-message>
		</cdx-dialog>
	</div>
</template>

<script>
const { defineComponent, ref, reactive, computed, watch } = require( 'vue' );
const {
	CdxButton, CdxChipInput, CdxDialog, CdxField, CdxMessage, CdxRadio, CdxSelect,
	CdxTextInput, CdxToggleButtonGroup, CdxToggleSwitch
} = require( '../codex.js' );
const format = require( '../format.js' );
const { swatch } = require( '../swatches.js' );

const WIKI_PATTERN = /^(@central|@local|[a-z0-9_]{1,64})$/;
const PRESETS = [ 1, 5, 10, 25, 50, 100 ];

/**
 * @param {Object} state
 * @return {Object}
 */
function toDraft( state ) {
	const weights = {};
	Object.keys( state.weights ).forEach( ( name ) => {
		weights[ name ] = String( state.weights[ name ] );
	} );
	return {
		active: state.active,
		rollout: String( state.rollout ),
		default: state.default,
		weights,
		population: state.population,
		start: format.toLocalInput( state.start ),
		end: format.toLocalInput( state.end ),
		wikis: state.wikis.map( ( value ) => ( { value } ) ),
		excludeWikis: state.excludeWikis.map( ( value ) => ( { value } ) )
	};
}

/**
 * @param {Object} draft
 * @return {Object}
 */
function fromDraft( draft ) {
	const weights = {};
	Object.keys( draft.weights ).forEach( ( name ) => {
		weights[ name ] = parseFloat( draft.weights[ name ] ) || 0;
	} );
	return {
		active: draft.active,
		rollout: parseFloat( draft.rollout ),
		default: draft.default,
		weights,
		population: draft.population,
		start: format.fromLocalInput( draft.start ),
		end: format.fromLocalInput( draft.end ),
		wikis: draft.wikis.map( ( chip ) => chip.value ),
		excludeWikis: draft.excludeWikis.map( ( chip ) => chip.value )
	};
}

module.exports = exports = defineComponent( {
	name: 'RolloutPanel',
	components: {
		CdxButton, CdxChipInput, CdxDialog, CdxField, CdxMessage, CdxRadio, CdxSelect,
		CdxTextInput, CdxToggleButtonGroup, CdxToggleSwitch
	},
	props: {
		experiment: { type: Object, required: true },
		env: { type: Object, required: true }
	},
	emits: [ 'saved' ],
	setup( props, { emit } ) {
		const draft = reactive( toDraft( props.experiment.state ) );
		const reason = ref( '' );
		const confirmOpen = ref( false );
		const mode = ref( 'save' );
		const saving = ref( false );
		const saveError = ref( '' );
		const result = ref( null );

		watch( () => props.experiment, ( experiment ) => {
			Object.assign( draft, toDraft( experiment.state ) );
		} );

		const names = computed( () => props.experiment.variants.map( ( v ) => v.name ) );

		const readOnlyReason = computed( () => {
			if ( !props.env.isCentral ) {
				return 'central';
			}
			return props.env.stateAvailable ? null : 'unavailable';
		} );

		const parsed = computed( () => fromDraft( draft ) );
		const totalWeight = computed( () => Object.keys( parsed.value.weights )
			.reduce( ( sum, name ) => sum + Math.max( 0, parsed.value.weights[ name ] ), 0 ) );

		const errors = computed( () => {
			const list = {};
			const rollout = parsed.value.rollout;
			if ( isNaN( rollout ) || rollout < 0 || rollout > 100 ) {
				list.rollout = mw.msg( 'wikioasismagic-experiments-error-rollout' );
			}
			if ( totalWeight.value <= 0 || Object.keys( parsed.value.weights )
				.some( ( name ) => parsed.value.weights[ name ] < 0 ) ) {
				list.weights = mw.msg( 'wikioasismagic-experiments-error-weights' );
			}
			if ( parsed.value.start && parsed.value.end && parsed.value.end <= parsed.value.start ) {
				list.end = mw.msg( 'wikioasismagic-experiments-error-dates' );
			}
			return list;
		} );

		const rolloutPreset = computed( {
			get: () => parsed.value.rollout,
			set: ( value ) => {
				draft.rollout = String( value );
			}
		} );

		const weightRows = computed( () => names.value.map( ( name ) => {
			const weight = Math.max( 0, parsed.value.weights[ name ] || 0 );
			const share = totalWeight.value > 0 ? weight / totalWeight.value : 0;
			const rollout = isNaN( parsed.value.rollout ) ? 0 : parsed.value.rollout / 100;
			let overall = draft.active ? share * rollout : 0;
			if ( name === draft.default ) {
				overall += draft.active ? 1 - rollout : 1;
			}
			return {
				name,
				swatch: swatch( name, names.value ),
				share: format.percent( share ),
				overall: format.percent( overall )
			};
		} ) );

		const describeValue = ( field, value ) => {
			if ( field === 'active' ) {
				return mw.msg( value ? 'wikioasismagic-experiments-value-on' : 'wikioasismagic-experiments-value-off' );
			}
			if ( field === 'rollout' ) {
				return format.percent( value / 100 );
			}
			if ( field === 'weights' ) {
				return names.value.map( ( name ) => name + ' ' + format.number( value[ name ] || 0 ) ).join( ', ' );
			}
			if ( field === 'population' ) {
				return mw.msg( 'wikioasismagic-experiments-audience-' + props.experiment.unit + '-' + value );
			}
			if ( field === 'start' || field === 'end' ) {
				return value ? format.dateTime( value ) : '–';
			}
			if ( Array.isArray( value ) ) {
				return value.length ? value.join( ', ' ) : '–';
			}
			return String( value );
		};

		const comparable = ( field, value ) => {
			if ( field === 'start' || field === 'end' ) {
				return value ? new Date( value ).toISOString().slice( 0, 16 ) : '';
			}
			if ( field === 'weights' ) {
				return names.value.map( ( name ) => Number( value[ name ] || 0 ) ).join( '|' );
			}
			if ( field === 'rollout' ) {
				return Number( value );
			}
			return JSON.stringify( value );
		};

		const changes = computed( () => {
			const base = props.experiment.state;
			const next = parsed.value;
			return Object.keys( next )
				.filter( ( field ) => comparable( field, base[ field ] ) !== comparable( field, next[ field ] ) )
				.map( ( field ) => ( {
					field,
					label: mw.msg( 'wikioasismagic-experiments-field-' + field.toLowerCase() ),
					old: describeValue( field, base[ field ] ),
					new: describeValue( field, next[ field ] )
				} ) );
		} );

		const warnings = computed( () => {
			const list = [];
			const base = props.experiment.state;
			const next = parsed.value;
			const changed = ( field ) => changes.value.some( ( c ) => c.field === field );
			const running = props.experiment.status === 'running';

			if ( mode.value === 'reset' ) {
				list.push( mw.msg( 'wikioasismagic-experiments-warning-reset' ) );
			} else {
				if ( running && changed( 'weights' ) ) {
					list.push( mw.msg( 'wikioasismagic-experiments-warning-weights' ) );
				}
				if ( running && changed( 'rollout' ) && next.rollout < base.rollout ) {
					list.push( mw.msg( 'wikioasismagic-experiments-warning-rampdown' ) );
				}
				if ( running && changed( 'population' ) ) {
					list.push( mw.msg( 'wikioasismagic-experiments-warning-population' ) );
				}
				if ( base.active && !next.active ) {
					list.push( mw.msg( 'wikioasismagic-experiments-warning-stop' ) );
				}
				if ( next.population !== 'all' && !next.start ) {
					list.push( mw.msg( 'wikioasismagic-experiments-warning-start' ) );
				}
			}

			if ( props.experiment.changesWikiConfig ) {
				list.push( mw.msg( 'wikioasismagic-experiments-warning-wikis' ) );
			}
			return list;
		} );

		const rolloutHelp = computed( () => {
			const total = props.experiment.unitTotal;
			const rollout = parsed.value.rollout;
			if ( total !== null && total !== undefined && !isNaN( rollout ) ) {
				return mw.message(
					'wikioasismagic-experiments-enrolled-wikis',
					format.number( Math.round( total * rollout / 100 ) ),
					format.number( total )
				).text();
			}
			return mw.msg( 'wikioasismagic-experiments-edit-rollout-help' );
		} );

		function ship( name ) {
			draft.default = name;
			draft.active = false;
		}

		function evenSplit() {
			names.value.forEach( ( name ) => {
				draft.weights[ name ] = '1';
			} );
		}

		function discard() {
			Object.assign( draft, toDraft( props.experiment.state ) );
		}

		function openConfirm( which ) {
			mode.value = which;
			saveError.value = '';
			confirmOpen.value = true;
		}

		function submit() {
			const params = {
				action: 'wikioasisexperimentsave',
				experiment: props.experiment.name,
				reason: reason.value,
				baseversion: props.experiment.lastChange ? props.experiment.lastChange.version : '',
				formatversion: 2,
				errorformat: 'plaintext',
				uselang: mw.config.get( 'wgUserLanguage' )
			};

			if ( mode.value === 'reset' ) {
				params.reset = 1;
			} else {
				const state = {};
				changes.value.forEach( ( change ) => {
					state[ change.field ] = parsed.value[ change.field ];
				} );
				params.state = JSON.stringify( state );
			}

			saving.value = true;
			saveError.value = '';
			new mw.Api().postWithToken( 'csrf', params ).then( ( data ) => {
				const response = data.wikioasisexperimentsave;
				confirmOpen.value = false;
				reason.value = '';
				result.value = {
					type: 'success',
					text: response.result === 'nochange' ?
						mw.msg( 'wikioasismagic-experiments-saved-nochange' ) :
						mw.msg( props.experiment.unit === 'wiki' ?
							'wikioasismagic-experiments-saved-wikis' :
							'wikioasismagic-experiments-saved' )
				};
				emit( 'saved', response.detail );
			}, ( code, data ) => {
				const error = data && data.errors && data.errors[ 0 ];
				saveError.value = error && error.text ? error.text :
					mw.msg( 'wikioasismagic-experiments-save-failed', code );
			} ).always( () => {
				saving.value = false;
			} );
		}

		return {
			draft,
			reason,
			confirmOpen,
			mode,
			saveError,
			result,
			readOnlyReason,
			readOnly: computed( () => readOnlyReason.value !== null ),
			errors,
			hasErrors: computed( () => Object.keys( errors.value ).length > 0 ),
			rolloutPreset,
			presetButtons: PRESETS.map( ( value ) => ( {
				value,
				label: mw.msg( 'wikioasismagic-experiments-percent', mw.language.convertNumber( value ) )
			} ) ),
			rolloutHelp,
			weightRows,
			variantItems: computed( () => names.value.map( ( name ) => ( { value: name, label: name } ) ) ),
			populations: [ 'all', 'new', 'existing' ],
			changes,
			warnings,
			lastChangeText: computed( () => props.experiment.lastChange ?
				mw.msg(
					'wikioasismagic-experiments-lastchange',
					props.experiment.lastChange.user,
					format.dateTime( props.experiment.lastChange.iso )
				) :
				mw.msg( 'wikioasismagic-experiments-asconfigured' ) ),
			dialogTitle: computed( () => mw.msg( mode.value === 'reset' ?
				'wikioasismagic-experiments-reset-title' :
				'wikioasismagic-experiments-save-title', props.experiment.label ) ),
			primaryAction: computed( () => ( {
				label: mw.msg( mode.value === 'reset' ?
					'wikioasismagic-experiments-reset' :
					'wikioasismagic-experiments-save' ),
				actionType: mode.value === 'reset' ? 'destructive' : 'progressive',
				disabled: saving.value
			} ) ),
			validWiki: ( value ) => WIKI_PATTERN.test( value ),
			ship,
			evenSplit,
			discard,
			openConfirm,
			submit
		};
	}
} );
</script>
