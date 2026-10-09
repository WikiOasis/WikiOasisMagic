<template>
	<cdx-table
		class="wo-fi-table"
		:caption="caption"
		:hide-caption="true"
		:columns="columns"
		:data="rows"
	>
		<template #item-source="{ row }">
			<a :href="row.source.pageUrl">{{ row.source.host }}</a>
		</template>
		<template #item-requester="{ row }">
			<a v-if="row.requester" :href="row.requester.url">{{ row.requester.name }}</a>
		</template>
		<template #item-status="{ row }">
			<status-chip :status="row.status"></status-chip>
		</template>
		<template #empty-state>
			{{ $i18n( 'wikioasismagic-fandomimport-list-empty' ).text() }}
		</template>
	</cdx-table>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxTable } = require( '../codex.js' );
const StatusChip = require( './StatusChip.vue' );

module.exports = exports = defineComponent( {
	name: 'ImportTable',
	components: { CdxTable, StatusChip },
	props: {
		caption: { type: String, required: true },
		imports: { type: Array, required: true },
		showRequester: { type: Boolean, default: false }
	},
	setup( props ) {
		const column = ( id ) => ( { id, label: mw.msg( 'wikioasismagic-fandomimport-column-' + id ) } );
		return {
			columns: computed( () => [
				column( 'source' ),
				column( 'wiki' ),
				...( props.showRequester ? [ column( 'requester' ) ] : [] ),
				column( 'status' ),
				column( 'updated' )
			] ),
			rows: computed( () => props.imports.map( ( item ) => Object.assign( {}, item, {
				wiki: item.dbname,
				updated: item.updatedText
			} ) ) )
		};
	}
} );
</script>
