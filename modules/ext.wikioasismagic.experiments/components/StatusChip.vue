<template>
	<cdx-info-chip
		class="wo-exp-status"
		:class="'wo-exp-status--' + status"
		:status="chipStatus"
		:icon="icon"
	>
		{{ label }}
	</cdx-info-chip>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxInfoChip } = require( '../codex.js' );
const { cdxIconClock, cdxIconPause, cdxIconStop } = require( './icons.json' );

module.exports = exports = defineComponent( {
	name: 'StatusChip',
	components: { CdxInfoChip },
	props: {
		status: { type: String, required: true }
	},
	setup( props ) {
		return {
			chipStatus: computed( () => props.status === 'running' ? 'success' : 'notice' ),
			icon: computed( () => ( {
				scheduled: cdxIconClock,
				off: cdxIconPause,
				ended: cdxIconStop
			} )[ props.status ] || null ),
			label: computed( () => mw.msg( 'wikioasismagic-experiments-status-' + props.status ) )
		};
	}
} );
</script>
