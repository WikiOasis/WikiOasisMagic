<template>
	<cdx-info-chip
		class="wo-fi-chip"
		:class="'wo-fi-chip--' + status"
		:status="chipStatus"
		:icon="icon"
	>
		{{ label }}
	</cdx-info-chip>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxInfoChip } = require( '../codex.js' );
const { cdxIconClock, cdxIconReload } = require( './icons.json' );

const STATUS = {
	done: 'success',
	failed: 'error',
	declined: 'error',
	'waiting-dump': 'warning'
};

module.exports = exports = defineComponent( {
	name: 'StatusChip',
	components: { CdxInfoChip },
	props: {
		status: { type: String, required: true }
	},
	setup( props ) {
		return {
			chipStatus: computed( () => STATUS[ props.status ] || 'notice' ),
			icon: computed( () => ( {
				pending: cdxIconClock,
				approved: cdxIconReload,
				creating: cdxIconReload,
				queued: cdxIconClock,
				running: cdxIconReload
			} )[ props.status ] || null ),
			label: computed( () => mw.msg( 'wikioasismagic-fandomimport-status-' + props.status ) )
		};
	}
} );
</script>
