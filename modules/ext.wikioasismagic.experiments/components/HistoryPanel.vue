<template>
	<div class="wo-exp-panel">
		<p v-if="!env.isCentral && env.centralUrl" class="wo-exp-muted">
			<span v-i18n-html:wikioasismagic-experiments-history-central="[ env.centralUrl + '#history' ]"></span>
		</p>
		<div v-else-if="loading" class="wo-exp-loading">
			<cdx-progress-bar :inline="true" :aria-label="$i18n( 'wikioasismagic-experiments-loading' ).text()"></cdx-progress-bar>
		</div>
		<cdx-message v-else-if="error" type="error">
			{{ error }}
		</cdx-message>
		<template v-else>
			<p v-if="!entries.length" class="wo-exp-muted">
				{{ $i18n( 'wikioasismagic-experiments-history-empty' ).text() }}
			</p>
			<ol v-else class="wo-exp-history">
				<li v-for="entry in entries" :key="entry.logid" class="wo-exp-history__entry">
					<div class="wo-exp-history__head">
						<cdx-icon :icon="entry.action === 'reset' ? cdxIconUndo : cdxIconSettings" size="small"></cdx-icon>
						<a :href="userUrl( entry.user )">{{ entry.user }}</a>
						<span>{{ actionLabel( entry.action ) }}</span>
						<span class="wo-exp-muted">{{ dateTime( entry.timestamp ) }}</span>
					</div>
					<ul v-if="entry.changes.length" class="wo-exp-changelist">
						<li v-for="change in entry.changes" :key="change.field">
							<strong>{{ fieldLabel( change.field ) }}</strong>
							<span class="wo-exp-changelist__old">{{ value( change.field, change.old ) }}</span>
							<span aria-hidden="true">→</span>
							<span class="wo-exp-changelist__new">{{ value( change.field, change.new ) }}</span>
						</li>
					</ul>
					<p v-if="entry.comment" class="wo-exp-history__comment">
						{{ entry.comment }}
					</p>
				</li>
			</ol>
			<p>
				<a :href="experiment.logUrl">{{ $i18n( 'wikioasismagic-experiments-history-log' ).text() }}</a>
			</p>
		</template>
	</div>
</template>

<script>
const { defineComponent, ref, onMounted } = require( 'vue' );
const { CdxIcon, CdxMessage, CdxProgressBar } = require( '../codex.js' );
const format = require( '../format.js' );
const { cdxIconUndo, cdxIconSettings } = require( './icons.json' );

module.exports = exports = defineComponent( {
	name: 'HistoryPanel',
	components: { CdxIcon, CdxMessage, CdxProgressBar },
	props: {
		experiment: { type: Object, required: true },
		env: { type: Object, required: true }
	},
	setup( props ) {
		const loading = ref( true );
		const error = ref( '' );
		const entries = ref( [] );

		onMounted( () => {
			if ( !props.env.isCentral ) {
				loading.value = false;
				return;
			}
			new mw.Api().get( {
				action: 'query',
				list: 'logevents',
				letype: 'experiment',
				letitle: 'Special:Experiments/' + props.experiment.name,
				leprop: 'ids|user|timestamp|comment|details|type',
				lelimit: 50,
				formatversion: 2
			} ).then( ( data ) => {
				entries.value = ( data.query.logevents || [] ).map( ( entry ) => ( {
					logid: entry.logid,
					user: entry.user,
					action: entry.action,
					timestamp: entry.timestamp,
					comment: entry.comment,
					changes: ( entry.params && entry.params.changes ) || []
				} ) );
			}, ( code ) => {
				error.value = mw.msg( 'wikioasismagic-experiments-history-failed', code );
			} ).always( () => {
				loading.value = false;
			} );
		} );

		return {
			loading,
			error,
			entries,
			cdxIconUndo,
			cdxIconSettings,
			dateTime: format.dateTime,
			userUrl: ( user ) => mw.util.getUrl( 'User:' + user ),
			actionLabel: ( action ) => mw.msg( 'wikioasismagic-experiments-history-' + action ),
			fieldLabel: ( field ) => mw.msg( 'wikioasismagic-experiments-field-' + field.toLowerCase() ),
			value: ( field, v ) => {
				if ( field === 'rollout' && typeof v === 'number' ) {
					return format.percent( v / 100 );
				}
				if ( v === null || v === undefined || ( Array.isArray( v ) && !v.length ) ) {
					return '–';
				}
				if ( typeof v === 'boolean' ) {
					return mw.msg( v ? 'wikioasismagic-experiments-value-on' : 'wikioasismagic-experiments-value-off' );
				}
				if ( Array.isArray( v ) ) {
					return v.join( ', ' );
				}
				if ( typeof v === 'object' ) {
					return Object.keys( v ).map( ( key ) => key + ' ' + format.number( v[ key ] ) ).join( ', ' );
				}
				if ( /^\d{14}$/.test( String( v ) ) ) {
					const s = String( v );
					return format.dateTime( s.slice( 0, 4 ) + '-' + s.slice( 4, 6 ) + '-' + s.slice( 6, 8 ) +
						'T' + s.slice( 8, 10 ) + ':' + s.slice( 10, 12 ) + ':' + s.slice( 12, 14 ) + 'Z' );
				}
				return String( v );
			}
		};
	}
} );
</script>
