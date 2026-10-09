<template>
	<section v-if="hasAny" class="wo-fi-panel">
		<h3>{{ $i18n( 'wikioasismagic-fandomimport-progress-heading' ).text() }}</h3>
		<div class="wo-fi-stats">
			<div v-for="stat in stats" :key="stat.key" class="wo-fi-stat">
				<dt>{{ stat.label }}</dt>
				<dd>{{ stat.value }}</dd>
			</div>
		</div>
		<progress-meter
			v-if="batchesTotal"
			:label="$i18n( 'wikioasismagic-fandomimport-progress-batches' ).text()"
			:value="batchesDone"
			:max="batchesTotal"
			:text="$i18n( 'wikioasismagic-fandomimport-progress-batches-value', format.number( batchesDone ), format.number( batchesTotal ) ).text()"
		></progress-meter>
		<progress-meter
			v-if="imagesTotal"
			:label="$i18n( 'wikioasismagic-fandomimport-progress-images-added' ).text()"
			:value="imagesAdded"
			:max="imagesTotal"
			:text="$i18n( 'wikioasismagic-fandomimport-progress-batches-value', format.number( imagesAdded ), format.number( imagesTotal ) ).text()"
		></progress-meter>
		<p v-if="skippedText" class="wo-fi-muted">{{ skippedText }}</p>
		<p v-if="extras" class="wo-fi-muted">{{ extras }}</p>
		<cdx-accordion v-if="failedFiles.length" class="wo-fi-failed">
			<template #title>
				{{ $i18n( 'wikioasismagic-fandomimport-progress-failed-files', failedFiles.length ).text() }}
			</template>
			<ul>
				<li v-for="name in failedFiles" :key="name">{{ name }}</li>
			</ul>
		</cdx-accordion>
	</section>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxAccordion } = require( '../codex.js' );
const ProgressMeter = require( './ProgressMeter.vue' );
const format = require( '../format.js' );

const STATS = [
	[ 'pages_kept', 'wikioasismagic-fandomimport-progress-pages' ],
	[ 'images_total', 'wikioasismagic-fandomimport-progress-images-total' ],
	[ 'images_fetched', 'wikioasismagic-fandomimport-progress-images-fetched' ],
	[ 'images_failed', 'wikioasismagic-fandomimport-progress-images-failed' ]
];

module.exports = exports = defineComponent( {
	name: 'ProgressPanel',
	components: { CdxAccordion, ProgressMeter },
	props: {
		progress: { type: Object, required: true },
		dump: { type: Object, default: null }
	},
	setup( props ) {
		const num = ( key ) => Number( props.progress[ key ] ) || 0;
		const has = ( key ) => props.progress[ key ] !== undefined && props.progress[ key ] !== null;

		const stats = computed( () => {
			const list = STATS.filter( ( [ key ] ) => has( key ) ).map( ( [ key, msg ] ) => ( {
				key, label: mw.msg( msg ), value: format.number( num( key ) )
			} ) );
			if ( has( 'images_bytes_total' ) ) {
				list.push( {
					key: 'bytes',
					label: mw.msg( 'wikioasismagic-fandomimport-progress-images-bytes' ),
					value: format.bytes( num( 'images_bytes_total' ) )
				} );
			}
			if ( props.dump ) {
				list.unshift( {
					key: 'dump',
					label: mw.msg( 'wikioasismagic-fandomimport-progress-dump' ),
					value: mw.msg( 'wikioasismagic-fandomimport-dump-' + props.dump.variant,
						props.dump.dateText || mw.msg( 'wikioasismagic-fandomimport-unknown' ) )
				} );
			}
			return list;
		} );

		const skippedText = computed( () => {
			const skipped = props.progress.skipped_namespaces;
			if ( !skipped || typeof skipped !== 'object' || !Object.keys( skipped ).length ) {
				return '';
			}
			const parts = Object.keys( skipped ).map( ( ns ) => mw.msg(
				'wikioasismagic-fandomimport-progress-skipped-ns', ns, skipped[ ns ]
			) );
			return mw.msg( 'wikioasismagic-fandomimport-progress-skipped-summary',
				format.number( num( 'pages_skipped' ) ), parts.join( ', ' ) );
		} );

		const extras = computed( () => {
			const parts = [];
			const list = ( key ) => Array.isArray( props.progress[ key ] ) ? props.progress[ key ] : [];
			const extensions = list( 'enabled_extensions' );
			const namespaces = list( 'created_namespaces' ).map( ( item ) => item && item.name ? item.name : String( item ) );
			if ( extensions.length ) {
				parts.push( mw.msg( 'wikioasismagic-fandomimport-progress-extensions' ) + ': ' + extensions.join( ', ' ) );
			}
			if ( namespaces.length ) {
				parts.push( mw.msg( 'wikioasismagic-fandomimport-progress-namespaces' ) + ': ' + namespaces.join( ', ' ) );
			}
			return parts.join( ' · ' );
		} );

		return {
			format,
			stats,
			skippedText,
			extras,
			batchesTotal: computed( () => num( 'batches_total' ) ),
			batchesDone: computed( () => num( 'batches_done' ) ),
			imagesTotal: computed( () => num( 'images_total' ) ),
			imagesAdded: computed( () => num( 'images_added' ) + num( 'images_skipped' ) ),
			failedFiles: computed( () => Array.isArray( props.progress.failed_files ) ? props.progress.failed_files : [] ),
			hasAny: computed( () => stats.value.length > 0 || num( 'batches_total' ) > 0 )
		};
	}
} );
</script>
