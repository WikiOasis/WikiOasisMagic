<template>
	<div class="wo-exp-split" :class="{ 'wo-exp-split--compact': compact }">
		<div
			class="wo-exp-split__bar"
			role="img"
			:aria-label="ariaLabel"
		>
			<span
				v-for="segment in segments"
				:key="segment.key"
				class="wo-exp-split__segment"
				:class="'wo-exp-swatch--' + segment.swatch"
				:style="{ width: segment.share + '%' }"
				:title="segment.label + ': ' + formatShare( segment.share )"
			></span>
		</div>
		<ul v-if="!compact" class="wo-exp-legend">
			<li v-for="segment in segments" :key="segment.key">
				<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + segment.swatch"></span>
				<span class="wo-exp-legend__label">{{ segment.label }}</span>
				<span class="wo-exp-legend__value">{{ formatShare( segment.share ) }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const format = require( '../format.js' );
const { swatch, OUTSIDE } = require( '../swatches.js' );

module.exports = exports = defineComponent( {
	name: 'SplitBar',
	props: {
		variants: { type: Array, required: true },
		rollout: { type: Number, required: true },
		defaultVariant: { type: String, required: true },
		active: { type: Boolean, default: true },
		compact: { type: Boolean, default: false }
	},
	setup( props ) {
		const names = computed( () => props.variants.map( ( v ) => v.name ) );

		const segments = computed( () => {
			if ( !props.active ) {
				return [ {
					key: 'all',
					swatch: swatch( props.defaultVariant, names.value ),
					share: 100,
					label: mw.msg( 'wikioasismagic-experiments-split-everyone', props.defaultVariant )
				} ];
			}

			const total = props.variants.reduce( ( sum, v ) => sum + Math.max( 0, v.weight ), 0 );
			const list = [];
			if ( total > 0 ) {
				props.variants.forEach( ( v ) => {
					if ( v.weight > 0 ) {
						list.push( {
							key: v.name,
							swatch: swatch( v.name, names.value ),
							share: props.rollout * v.weight / total,
							label: v.name
						} );
					}
				} );
			}

			const outside = total > 0 ? 100 - props.rollout : 100;
			if ( outside > 0 ) {
				list.push( {
					key: '(outside)',
					swatch: OUTSIDE,
					share: outside,
					label: mw.msg( 'wikioasismagic-experiments-split-outside', props.defaultVariant )
				} );
			}
			return list;
		} );

		const formatShare = ( share ) => format.percent( share / 100, share > 0 && share < 1 ? 2 : 1 );

		return {
			segments,
			formatShare,
			ariaLabel: computed( () => segments.value
				.map( ( s ) => s.label + ' ' + formatShare( s.share ) )
				.join( ', ' ) )
		};
	}
} );
</script>
