<template>
	<section class="wo-fi-preview">
		<div class="wo-fi-preview__head">
			<div>
				<h2 class="wo-fi-preview__title">{{ info ? info.sitename : source.host }}</h2>
				<a
					class="wo-fi-preview__link external"
					:href="source.baseUrl"
					rel="nofollow noopener"
					target="_blank"
				>{{ source.host }}</a>
			</div>
			<cdx-info-chip v-if="dump" status="success" :icon="cdxIconDownload">
				{{ dumpLabel }}
			</cdx-info-chip>
			<cdx-info-chip v-else-if="info" status="warning" :icon="cdxIconClock">
				{{ $i18n( 'wikioasismagic-fandomimport-dump-missing-chip' ).text() }}
			</cdx-info-chip>
		</div>
		<dl v-if="info" class="wo-fi-stats">
			<div class="wo-fi-stat">
				<dt>{{ $i18n( 'wikioasismagic-fandomimport-stat-articles' ).text() }}</dt>
				<dd>{{ format.number( info.articles ) }}</dd>
			</div>
			<div class="wo-fi-stat">
				<dt>{{ $i18n( 'wikioasismagic-fandomimport-stat-pages' ).text() }}</dt>
				<dd>{{ format.number( info.pages ) }}</dd>
			</div>
			<div class="wo-fi-stat">
				<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-files' ).text() }}</dt>
				<dd>{{ format.number( info.files ) }}</dd>
			</div>
			<div class="wo-fi-stat">
				<dt>{{ $i18n( 'wikioasismagic-fandomimport-info-license' ).text() }}</dt>
				<dd>
					<a
						v-if="info.licenseurl"
						class="external"
						:href="info.licenseurl"
						rel="nofollow noopener"
						target="_blank"
					>{{ info.license || info.licenseurl }}</a>
					<span v-else>{{ info.license }}</span>
				</dd>
			</div>
		</dl>
		<p v-if="info && !dump" class="wo-fi-preview__note" v-html="noDumpHtml"></p>
	</section>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxInfoChip } = require( '../codex.js' );
const { cdxIconDownload, cdxIconClock } = require( './icons.json' );
const format = require( '../format.js' );

module.exports = exports = defineComponent( {
	name: 'WikiPreview',
	components: { CdxInfoChip },
	props: {
		source: { type: Object, required: true },
		info: { type: Object, default: null },
		dump: { type: Object, default: null }
	},
	setup( props ) {
		return {
			format,
			cdxIconDownload,
			cdxIconClock,
			dumpLabel: computed( () => props.dump ? mw.msg(
				'wikioasismagic-fandomimport-dump-' + props.dump.variant,
				props.dump.dateText || mw.msg( 'wikioasismagic-fandomimport-unknown' )
			) : '' ),
			noDumpHtml: computed( () => mw.message(
				'wikioasismagic-fandomimport-dump-none', props.source.statisticsUrl
			).parse() )
		};
	}
} );
</script>
