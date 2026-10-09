<template>
	<div class="wo-fi-meter">
		<div class="wo-fi-meter__label">
			<span>{{ label }}</span>
			<span class="wo-fi-meter__value">{{ text }}</span>
		</div>
		<div
			class="wo-fi-meter__track"
			role="progressbar"
			:aria-label="label"
			:aria-valuenow="value"
			aria-valuemin="0"
			:aria-valuemax="max"
		>
			<div class="wo-fi-meter__bar" :style="{ width: percent + '%' }"></div>
		</div>
	</div>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );

module.exports = exports = defineComponent( {
	name: 'ProgressMeter',
	props: {
		label: { type: String, required: true },
		value: { type: Number, required: true },
		max: { type: Number, required: true },
		text: { type: String, required: true }
	},
	setup( props ) {
		return {
			percent: computed( () => props.max > 0 ?
				Math.min( 100, Math.round( props.value / props.max * 1000 ) / 10 ) :
				0 )
		};
	}
} );
</script>
