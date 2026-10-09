<template>
	<div class="wo-fi-status-view">
		<header class="wo-fi-header">
			<status-chip :status="detail.status"></status-chip>
			<p class="wo-fi-header__text">{{ description }}</p>
			<span v-if="detail.live" class="wo-fi-live">
				<cdx-progress-indicator class="wo-fi-live__spinner">
					{{ $i18n( 'wikioasismagic-fandomimport-live' ).text() }}
				</cdx-progress-indicator>
				{{ $i18n( 'wikioasismagic-fandomimport-live' ).text() }}
			</span>
		</header>

		<cdx-message v-if="notice" :type="notice.type" :fade-in="true">
			{{ notice.text }}
		</cdx-message>

		<cdx-message v-if="detail.status === 'failed'" type="error">
			<strong>{{ failedAt }}</strong>
			<p class="wo-fi-pre">{{ detail.error }}</p>
			<p v-if="detail.filesExpireText">
				{{ $i18n( 'wikioasismagic-fandomimport-files-kept-short', detail.filesExpireText ).text() }}
			</p>
		</cdx-message>

		<cdx-message v-else-if="detail.status === 'declined'" type="warning">
			<strong>{{ $i18n( 'wikioasismagic-fandomimport-declined-reason' ).text() }}</strong>
			<p class="wo-fi-pre">{{ detail.error }}</p>
			<a v-if="detail.permissions.requestAgain" :href="requestAgainUrl">
				{{ $i18n( 'wikioasismagic-fandomimport-request-again' ).text() }}
			</a>
		</cdx-message>

		<cdx-message v-else-if="detail.status === 'waiting-dump'" type="warning">
			<span v-html="waitingHtml"></span>
		</cdx-message>

		<cdx-message v-else-if="detail.status === 'done'" type="success">
			<span v-html="doneHtml"></span>
		</cdx-message>

		<cdx-message v-if="note" type="notice">
			{{ note }}
		</cdx-message>

		<div class="wo-fi-layout">
			<div class="wo-fi-layout__main">
				<section class="wo-fi-panel">
					<h3>{{ $i18n( 'wikioasismagic-fandomimport-stages-heading' ).text() }}</h3>
					<stage-list :stages="detail.stages"></stage-list>
				</section>
				<progress-panel :progress="detail.progress" :dump="detail.dump"></progress-panel>
				<review-panel
					v-if="showReview"
					:detail="detail"
					@update="onUpdate"
				></review-panel>
			</div>
			<aside class="wo-fi-layout__side">
				<section class="wo-fi-panel">
					<h3>{{ $i18n( 'wikioasismagic-fandomimport-details-heading' ).text() }}</h3>
					<dl class="wo-fi-details">
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-address' ).text() }}</dt>
						<dd>
							<a class="external" :href="detail.source.baseUrl" rel="nofollow noopener" target="_blank">
								{{ detail.source.host }}
							</a>
						</dd>
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-newwiki' ).text() }}</dt>
						<dd>
							<a v-if="detail.wikiExists" :href="detail.wikiUrl">{{ detail.wikiUrl }}</a>
							<span v-else>
								{{ $i18n( 'wikioasismagic-fandomimport-info-newwiki-pending', detail.wikiUrl ).text() }}
							</span>
						</dd>
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-sitename' ).text() }}</dt>
						<dd>{{ detail.sitename }}</dd>
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-language' ).text() }}</dt>
						<dd>{{ detail.languageName }}</dd>
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-mode' ).text() }}</dt>
						<dd>{{ $i18n( 'wikioasismagic-fandomimport-mode-' + detail.mode ).text() }}</dd>
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-requester' ).text() }}</dt>
						<dd>
							<a v-if="detail.requester" :href="detail.requester.url">{{ detail.requester.name }}</a>
							<span v-else>{{ $i18n( 'wikioasismagic-fandomimport-unknown' ).text() }}</span>
						</dd>
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-requested' ).text() }}</dt>
						<dd>{{ detail.createdText }}</dd>
						<template v-if="detail.fandomUser">
							<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-fandomuser' ).text() }}</dt>
							<dd>
								<a class="external" :href="detail.fandomUserUrl" rel="nofollow noopener" target="_blank">
									{{ detail.fandomUser }}
								</a>
							</dd>
						</template>
						<template v-if="detail.reviewer">
							<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-reviewer' ).text() }}</dt>
							<dd><a :href="detail.reviewer.url">{{ detail.reviewer.name }}</a></dd>
						</template>
						<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-updated' ).text() }}</dt>
						<dd>{{ detail.updatedText }}</dd>
					</dl>
				</section>
				<section class="wo-fi-panel">
					<h3>{{ $i18n( 'wikioasismagic-fandomimport-reason-heading' ).text() }}</h3>
					<p class="wo-fi-pre">{{ detail.reason }}</p>
				</section>
			</aside>
		</div>
	</div>
</template>

<script>
const { defineComponent, ref, computed, onMounted, onBeforeUnmount } = require( 'vue' );
const { CdxMessage, CdxProgressIndicator } = require( '../codex.js' );
const StatusChip = require( './StatusChip.vue' );
const StageList = require( './StageList.vue' );
const ProgressPanel = require( './ProgressPanel.vue' );
const ReviewPanel = require( './ReviewPanel.vue' );
const api = require( '../api.js' );

const REFRESH = 15000;

module.exports = exports = defineComponent( {
	name: 'StatusView',
	components: { CdxMessage, CdxProgressIndicator, StatusChip, StageList, ProgressPanel, ReviewPanel },
	props: {
		initial: { type: Object, required: true }
	},
	setup( props ) {
		const detail = ref( props.initial );
		const notice = ref( null );
		let timer = null;

		function refresh() {
			if ( document.hidden || !detail.value.live ) {
				return;
			}
			api.detail( detail.value.id ).then( ( fresh ) => {
				detail.value = fresh;
			} ).catch( () => {} );
		}

		onMounted( () => {
			timer = setInterval( refresh, REFRESH );
			document.addEventListener( 'visibilitychange', refresh );
		} );
		onBeforeUnmount( () => {
			clearInterval( timer );
			document.removeEventListener( 'visibilitychange', refresh );
		} );

		function onUpdate( fresh, action ) {
			detail.value = fresh;
			notice.value = { type: 'success', text: mw.msg( 'wikioasismagic-fandomimport-done-' + action ) };
		}

		const permissions = computed( () => detail.value.permissions );

		return {
			detail,
			notice,
			onUpdate,
			description: computed( () => mw.msg(
				'wikioasismagic-fandomimport-desc-' + detail.value.status, detail.value.source.host
			) ),
			failedAt: computed( () => mw.msg( 'wikioasismagic-fandomimport-failed-at',
				mw.msg( 'wikioasismagic-fandomimport-stage-' + detail.value.stage ) ) ),
			note: computed( () => typeof detail.value.progress.note === 'string' ? detail.value.progress.note : '' ),
			waitingHtml: computed( () => mw.message( 'wikioasismagic-fandomimport-waiting-dump-help',
				detail.value.source.statisticsUrl, detail.value.source.host ).parse() ),
			doneHtml: computed( () => mw.message( 'wikioasismagic-fandomimport-done-banner',
				detail.value.wikiUrl, detail.value.sitename ).parse() ),
			requestAgainUrl: computed( () => mw.util.getUrl( null, { new: 1 } ) ),
			showReview: computed( () => permissions.value.approve || permissions.value.retry ||
				permissions.value.discard )
		};
	}
} );
</script>
