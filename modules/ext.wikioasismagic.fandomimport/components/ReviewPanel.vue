<template>
	<section class="wo-fi-panel wo-fi-review">
		<h3>{{ $i18n( 'wikioasismagic-fandomimport-review-heading' ).text() }}</h3>

		<template v-if="detail.permissions.approve">
			<cdx-message v-if="account" :type="accountType" class="wo-fi-review__account">
				{{ accountText }}
			</cdx-message>
			<p v-else-if="detail.fandomUser" class="wo-fi-muted">
				{{ $i18n( 'wikioasismagic-fandomimport-account-checking' ).text() }}
			</p>
			<cdx-message v-else type="notice" class="wo-fi-review__account">
				{{ $i18n( 'wikioasismagic-fandomimport-account-none' ).text() }}
			</cdx-message>

			<cdx-field>
				<cdx-text-area v-model="comment" rows="3"></cdx-text-area>
				<template #label>
					{{ $i18n( 'wikioasismagic-fandomimport-review-comment' ).text() }}
				</template>
			</cdx-field>
			<cdx-checkbox v-if="detail.large" v-model="acknowledge">
				{{ acknowledgeText }}
			</cdx-checkbox>
			<div class="wo-fi-review__actions">
				<cdx-button
					action="progressive"
					weight="primary"
					:disabled="busy || ( detail.large && !acknowledge )"
					@click="act( 'approve', { comment, acknowledge: acknowledge ? 1 : undefined } )"
				>
					<cdx-icon :icon="cdxIconCheck"></cdx-icon>
					{{ $i18n( 'wikioasismagic-fandomimport-action-approve' ).text() }}
				</cdx-button>
				<cdx-button action="destructive" :disabled="busy" @click="declineOpen = true">
					<cdx-icon :icon="cdxIconClose"></cdx-icon>
					{{ $i18n( 'wikioasismagic-fandomimport-action-decline' ).text() }}
				</cdx-button>
			</div>
		</template>

		<div v-if="detail.permissions.retry || detail.permissions.discard" class="wo-fi-review__actions">
			<cdx-button
				v-if="detail.permissions.retry"
				action="progressive"
				weight="primary"
				:disabled="busy"
				@click="act( 'retry' )"
			>
				<cdx-icon :icon="cdxIconReload"></cdx-icon>
				{{ $i18n( 'wikioasismagic-fandomimport-action-retry' ).text() }}
			</cdx-button>
			<cdx-button
				v-if="detail.permissions.discard"
				action="destructive"
				:disabled="busy"
				@click="discardOpen = true"
			>
				<cdx-icon :icon="cdxIconTrash"></cdx-icon>
				{{ $i18n( 'wikioasismagic-fandomimport-action-discard' ).text() }}
			</cdx-button>
		</div>

		<cdx-message v-if="error" type="error">
			<span v-html="error"></span>
		</cdx-message>

		<cdx-dialog
			v-model:open="declineOpen"
			:title="$i18n( 'wikioasismagic-fandomimport-dialog-decline-title' ).text()"
			:use-close-button="true"
			:primary-action="{
				label: $i18n( 'wikioasismagic-fandomimport-action-decline' ).text(),
				actionType: 'destructive',
				disabled: busy || !declineReason.trim()
			}"
			:default-action="{ label: $i18n( 'wikioasismagic-fandomimport-cancel' ).text() }"
			@primary="decline"
			@default="declineOpen = false"
		>
			<cdx-field>
				<cdx-text-area v-model="declineReason" rows="4"></cdx-text-area>
				<template #label>
					{{ $i18n( 'wikioasismagic-fandomimport-review-decline-reason' ).text() }}
				</template>
				<template #help-text>
					{{ $i18n( 'wikioasismagic-fandomimport-dialog-decline-help' ).text() }}
				</template>
			</cdx-field>
		</cdx-dialog>

		<cdx-dialog
			v-model:open="discardOpen"
			:title="$i18n( 'wikioasismagic-fandomimport-dialog-discard-title' ).text()"
			:use-close-button="true"
			:primary-action="{
				label: $i18n( 'wikioasismagic-fandomimport-action-discard' ).text(),
				actionType: 'destructive',
				disabled: busy
			}"
			:default-action="{ label: $i18n( 'wikioasismagic-fandomimport-cancel' ).text() }"
			@primary="discard"
			@default="discardOpen = false"
		>
			<p>{{ $i18n( 'wikioasismagic-fandomimport-dialog-discard-body' ).text() }}</p>
		</cdx-dialog>
	</section>
</template>

<script>
const { defineComponent, ref, computed, onMounted } = require( 'vue' );
const { CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxIcon, CdxMessage, CdxTextArea } = require( '../codex.js' );
const { cdxIconCheck, cdxIconClose, cdxIconReload, cdxIconTrash } = require( './icons.json' );
const api = require( '../api.js' );

module.exports = exports = defineComponent( {
	name: 'ReviewPanel',
	components: { CdxButton, CdxCheckbox, CdxDialog, CdxField, CdxIcon, CdxMessage, CdxTextArea },
	props: {
		detail: { type: Object, required: true }
	},
	emits: [ 'update' ],
	setup( props, { emit } ) {
		const comment = ref( '' );
		const acknowledge = ref( false );
		const busy = ref( false );
		const error = ref( '' );
		const declineOpen = ref( false );
		const declineReason = ref( '' );
		const discardOpen = ref( false );
		const account = ref( null );

		function act( action, params ) {
			busy.value = true;
			error.value = '';
			return api.act( Object.assign( { do: action, id: props.detail.id }, params || {} ) )
				.then( ( result ) => {
					emit( 'update', result.detail, action );
				} )
				.catch( ( message ) => {
					error.value = message;
				} )
				.then( () => {
					busy.value = false;
				} );
		}

		onMounted( () => {
			if ( props.detail.permissions.approve && props.detail.fandomUser ) {
				api.account( props.detail.id ).then( ( result ) => {
					account.value = result;
				} ).catch( () => {
					account.value = { checked: false };
				} );
			}
		} );

		return {
			comment,
			acknowledge,
			busy,
			error,
			declineOpen,
			declineReason,
			discardOpen,
			account,
			act,
			decline: () => act( 'decline', { reason: declineReason.value.trim() } ).then( () => {
				declineOpen.value = false;
			} ),
			discard: () => act( 'discard' ).then( () => {
				discardOpen.value = false;
			} ),
			accountType: computed( () => {
				if ( !account.value || !account.value.checked ) {
					return 'notice';
				}
				return account.value.admin ? 'success' : 'warning';
			} ),
			accountText: computed( () => {
				const user = props.detail.fandomUser;
				if ( !account.value || !account.value.checked ) {
					return mw.msg( 'wikioasismagic-fandomimport-account-unknown', user );
				}
				if ( !account.value.exists ) {
					return mw.msg( 'wikioasismagic-fandomimport-account-missing', user );
				}
				return account.value.admin ?
					mw.msg( 'wikioasismagic-fandomimport-account-admin', user, account.value.groups.join( ', ' ) ) :
					mw.msg( 'wikioasismagic-fandomimport-account-notadmin', user );
			} ),
			acknowledgeText: computed( () => props.detail.info ?
				mw.msg( 'wikioasismagic-fandomimport-review-acknowledge',
					mw.language.convertNumber( props.detail.info.files ) ) :
				mw.msg( 'wikioasismagic-fandomimport-review-acknowledge-unknown' ) ),
			cdxIconCheck,
			cdxIconClose,
			cdxIconReload,
			cdxIconTrash
		};
	}
} );
</script>
