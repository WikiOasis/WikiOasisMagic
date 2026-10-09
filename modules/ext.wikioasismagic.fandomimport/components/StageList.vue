<template>
	<ol class="wo-fi-steps">
		<li
			v-for="stage in stages"
			:key="stage.id"
			class="wo-fi-step"
			:class="'wo-fi-step--' + stage.state"
		>
			<span class="wo-fi-step__marker">
				<cdx-progress-indicator v-if="stage.state === 'current'" class="wo-fi-step__spinner">
					{{ stateLabel( stage.state ) }}
				</cdx-progress-indicator>
				<cdx-icon
					v-else-if="icons[ stage.state ]"
					:icon="icons[ stage.state ]"
					size="small"
					:icon-label="stateLabel( stage.state )"
				></cdx-icon>
			</span>
			<span class="wo-fi-step__label">{{ stageLabel( stage.id ) }}</span>
		</li>
	</ol>
</template>

<script>
const { defineComponent } = require( 'vue' );
const { CdxIcon, CdxProgressIndicator } = require( '../codex.js' );
const { cdxIconCheck, cdxIconAlert, cdxIconClock } = require( './icons.json' );

module.exports = exports = defineComponent( {
	name: 'StageList',
	components: { CdxIcon, CdxProgressIndicator },
	props: {
		stages: { type: Array, required: true }
	},
	setup() {
		return {
			icons: { done: cdxIconCheck, failed: cdxIconAlert, waiting: cdxIconClock },
			stageLabel: ( id ) => mw.msg( 'wikioasismagic-fandomimport-stage-' + id ),
			stateLabel: ( state ) => mw.msg( 'wikioasismagic-fandomimport-step-' + state )
		};
	}
} );
</script>
