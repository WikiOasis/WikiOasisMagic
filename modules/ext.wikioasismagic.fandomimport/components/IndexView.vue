<template>
	<div class="wo-fi-index">
		<section class="wo-fi-hero">
			<div class="wo-fi-hero__text" v-html="config.intro"></div>
			<form class="wo-fi-lookup" @submit.prevent="lookup">
				<cdx-field :status="error ? 'error' : 'default'" :messages="{}">
					<div class="wo-fi-lookup__row">
						<cdx-text-input
							v-model="address"
							input-type="url"
							:start-icon="cdxIconSearch"
							:placeholder="$i18n( 'wikioasismagic-fandomimport-lookup-placeholder' ).text()"
							:status="error ? 'error' : 'default'"
							:disabled="busy"
							@update:model-value="error = ''"
						></cdx-text-input>
						<cdx-button
							type="submit"
							action="progressive"
							weight="primary"
							:disabled="busy || !address.trim()"
						>
							{{ $i18n( 'wikioasismagic-fandomimport-lookup-submit' ).text() }}
						</cdx-button>
					</div>
					<template #label>
						{{ $i18n( 'wikioasismagic-fandomimport-lookup-label' ).text() }}
					</template>
					<template #help-text>
						{{ $i18n( 'wikioasismagic-fandomimport-lookup-help-app' ).text() }}
					</template>
				</cdx-field>
			</form>
			<cdx-message v-if="error" type="error" class="wo-fi-lookup-error">
				<span v-html="error"></span>
			</cdx-message>
			<cdx-message v-if="config.blocker" type="notice">
				<span v-html="config.blocker"></span>
			</cdx-message>
		</section>

		<section v-if="config.mine.length" class="wo-fi-section">
			<h2>{{ $i18n( 'wikioasismagic-fandomimport-list-mine' ).text() }}</h2>
			<import-table
				:caption="$i18n( 'wikioasismagic-fandomimport-list-mine' ).text()"
				:imports="config.mine"
			></import-table>
		</section>

		<section v-if="config.queues" class="wo-fi-section">
			<h2>{{ $i18n( 'wikioasismagic-fandomimport-review-queue' ).text() }}</h2>
			<cdx-tabs v-model:active="activeQueue" :framed="true">
				<cdx-tab
					v-for="queue in queues"
					:key="queue.name"
					:name="queue.name"
					:label="queue.label"
				>
					<import-table
						:caption="queue.label"
						:imports="queue.imports"
						:show-requester="true"
					></import-table>
				</cdx-tab>
			</cdx-tabs>
		</section>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxButton, CdxField, CdxMessage, CdxTab, CdxTabs, CdxTextInput } = require( '../codex.js' );
const { cdxIconSearch } = require( './icons.json' );
const ImportTable = require( './ImportTable.vue' );
const api = require( '../api.js' );

const QUEUES = [ 'pending', 'inprogress', 'failed', 'done', 'declined' ];

module.exports = exports = defineComponent( {
	name: 'IndexView',
	components: { CdxButton, CdxField, CdxMessage, CdxTab, CdxTabs, CdxTextInput, ImportTable },
	props: {
		config: { type: Object, required: true }
	},
	setup( props ) {
		const address = ref( '' );
		const busy = ref( false );
		const error = ref( props.config.error || '' );
		const queues = computed( () => props.config.queues ? QUEUES.map( ( name ) => ( {
			name,
			imports: props.config.queues[ name ],
			label: mw.msg( 'wikioasismagic-fandomimport-queue-tab',
				mw.msg( 'wikioasismagic-fandomimport-list-' + name ),
				mw.language.convertNumber( props.config.queues[ name ].length ) )
		} ) ) : [] );
		const firstBusy = queues.value.find( ( queue ) => queue.imports.length );
		const activeQueue = ref( firstBusy ? firstBusy.name : QUEUES[ 0 ] );

		function lookup() {
			busy.value = true;
			error.value = '';
			api.lookup( address.value.trim() ).then( ( result ) => {
				window.location.href = result.existing ? result.existing.source.pageUrl : result.source.pageUrl;
			} ).catch( ( message ) => {
				error.value = message;
				busy.value = false;
			} );
		}

		return { address, busy, error, queues, activeQueue, lookup, cdxIconSearch };
	}
} );
</script>
