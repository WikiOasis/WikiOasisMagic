<template>
	<div ref="root" class="wo-exp-chart">
		<svg
			v-if="width > 0"
			:width="width"
			:height="height"
			:viewBox="'0 0 ' + width + ' ' + height"
			role="img"
			:aria-label="ariaLabel"
			@mousemove="onMove"
			@mouseleave="hover = null"
		>
			<g class="wo-exp-chart__grid">
				<line
					v-for="tick in yTicks"
					:key="'grid' + tick.value"
					:x1="pad.left"
					:x2="width - pad.right"
					:y1="tick.y"
					:y2="tick.y"
				></line>
			</g>
			<g class="wo-exp-chart__axis">
				<text
					v-for="tick in yTicks"
					:key="'y' + tick.value"
					:x="pad.left - 8"
					:y="tick.y"
					dy="0.32em"
					text-anchor="end"
				>{{ tick.label }}</text>
				<text
					v-for="tick in xTicks"
					:key="'x' + tick.index"
					:x="tick.x"
					:y="height - 6"
					text-anchor="middle"
				>{{ tick.label }}</text>
			</g>
			<path
				v-for="line in lines"
				:key="line.key"
				class="wo-exp-chart__line"
				:class="'wo-exp-stroke--' + line.swatch"
				:d="line.d"
			></path>
			<template v-for="line in lines" :key="'dots' + line.key">
				<circle
					v-for="dot in line.dots"
					:key="dot.index"
					class="wo-exp-chart__dot"
					:class="'wo-exp-fill--' + line.swatch"
					:cx="dot.x"
					:cy="dot.y"
					r="2.5"
				></circle>
			</template>
			<g v-if="hover !== null">
				<line
					class="wo-exp-chart__cursor"
					:x1="xAt( hover )"
					:x2="xAt( hover )"
					:y1="pad.top"
					:y2="height - pad.bottom"
				></line>
				<template v-for="item in series" :key="'hover' + item.key">
					<circle
						v-if="item.values[ hover ] !== null && item.values[ hover ] !== undefined"
						class="wo-exp-chart__marker"
						:class="'wo-exp-fill--' + item.swatch"
						:cx="xAt( hover )"
						:cy="yAt( item.values[ hover ] )"
						r="4"
					></circle>
				</template>
			</g>
		</svg>
		<div
			v-if="hover !== null"
			class="wo-exp-chart__tooltip"
			:style="tooltipStyle"
		>
			<div class="wo-exp-chart__tooltip-day">
				{{ dayLabel( days[ hover ] ) }}
			</div>
			<div v-for="item in series" :key="'tip' + item.key">
				<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + item.swatch"></span>
				{{ item.label }}: <strong>{{ formatValue( item.values[ hover ] ) }}</strong>
			</div>
		</div>
		<ul class="wo-exp-legend">
			<li v-for="item in series" :key="'legend' + item.key">
				<span class="wo-exp-legend__dot" :class="'wo-exp-swatch--' + item.swatch"></span>
				<span class="wo-exp-legend__label">{{ item.label }}</span>
			</li>
		</ul>
		<table class="wo-exp-sr-only">
			<caption>{{ ariaLabel }}</caption>
			<tr>
				<th></th>
				<th v-for="item in series" :key="'th' + item.key">
					{{ item.label }}
				</th>
			</tr>
			<tr v-for="( day, index ) in days" :key="'tr' + day">
				<th>{{ dayLabel( day ) }}</th>
				<td v-for="item in series" :key="'td' + item.key + day">
					{{ formatValue( item.values[ index ] ) }}
				</td>
			</tr>
		</table>
	</div>
</template>

<script>
const { defineComponent, ref, computed, onMounted, onBeforeUnmount } = require( 'vue' );
const format = require( '../format.js' );

/**
 * @param {number} max
 * @return {number}
 */
function niceStep( max ) {
	const rough = max / 4;
	const magnitude = Math.pow( 10, Math.floor( Math.log10( rough ) ) );
	const candidates = [ 1, 2, 2.5, 5, 10 ];
	for ( let i = 0; i < candidates.length; i++ ) {
		if ( candidates[ i ] * magnitude >= rough ) {
			return candidates[ i ] * magnitude;
		}
	}
	return 10 * magnitude;
}

module.exports = exports = defineComponent( {
	name: 'LineChart',
	props: {
		days: { type: Array, required: true },
		series: { type: Array, required: true },
		valueFormat: { type: String, default: 'number' },
		ariaLabel: { type: String, required: true },
		height: { type: Number, default: 260 }
	},
	setup( props ) {
		const root = ref( null );
		const width = ref( 0 );
		const hover = ref( null );
		const pad = { top: 12, right: 16, bottom: 28, left: 56 };
		let observer = null;

		onMounted( () => {
			const measure = () => {
				width.value = root.value ? root.value.clientWidth : 0;
			};
			measure();
			if ( window.ResizeObserver ) {
				observer = new ResizeObserver( measure );
				observer.observe( root.value );
			}
		} );

		onBeforeUnmount( () => {
			if ( observer ) {
				observer.disconnect();
			}
		} );

		const formatValue = ( value ) => {
			if ( value === null || value === undefined ) {
				return '–';
			}
			return props.valueFormat === 'percent' ? format.percent( value ) : format.number( value );
		};

		const yMax = computed( () => {
			let max = 0;
			props.series.forEach( ( item ) => item.values.forEach( ( value ) => {
				if ( value !== null && value > max ) {
					max = value;
				}
			} ) );
			if ( max <= 0 ) {
				return props.valueFormat === 'percent' ? 0.1 : 4;
			}
			const step = niceStep( max );
			return Math.ceil( max / step ) * step;
		} );

		const plotWidth = computed( () => Math.max( 1, width.value - pad.left - pad.right ) );
		const plotHeight = computed( () => props.height - pad.top - pad.bottom );

		const xAt = ( index ) => pad.left + ( props.days.length > 1 ?
			index / ( props.days.length - 1 ) * plotWidth.value :
			plotWidth.value / 2 );
		const yAt = ( value ) => pad.top + plotHeight.value * ( 1 - value / yMax.value );

		const yTicks = computed( () => {
			const step = niceStep( yMax.value );
			const ticks = [];
			for ( let value = 0; value <= yMax.value + step / 1000; value += step ) {
				ticks.push( { value, y: yAt( value ), label: formatValue( value ) } );
			}
			return ticks;
		} );

		const xTicks = computed( () => {
			const count = props.days.length;
			if ( !count ) {
				return [];
			}
			const wanted = Math.max( 2, Math.min( 6, Math.floor( plotWidth.value / 90 ) ) );
			const every = Math.max( 1, Math.ceil( count / wanted ) );
			const ticks = [];
			for ( let index = 0; index < count; index += every ) {
				ticks.push( { index, x: xAt( index ), label: format.shortDay( props.days[ index ] ) } );
			}
			return ticks;
		} );

		const lines = computed( () => props.series.map( ( item ) => {
			let d = '';
			let drawing = false;
			const dots = [];
			item.values.forEach( ( value, index ) => {
				if ( value === null || value === undefined ) {
					drawing = false;
					return;
				}
				d += ( drawing ? 'L' : 'M' ) + xAt( index ).toFixed( 1 ) + ',' + yAt( value ).toFixed( 1 );
				drawing = true;
				if ( props.days.length <= 31 ) {
					dots.push( { index, x: xAt( index ), y: yAt( value ) } );
				}
			} );
			return { key: item.key, swatch: item.swatch, d, dots };
		} ) );

		function onMove( event ) {
			const rect = event.currentTarget.getBoundingClientRect();
			const x = event.clientX - rect.left - pad.left;
			const count = props.days.length;
			if ( !count ) {
				return;
			}
			const index = count > 1 ? Math.round( x / plotWidth.value * ( count - 1 ) ) : 0;
			hover.value = Math.max( 0, Math.min( count - 1, index ) );
		}

		return {
			root,
			width,
			hover,
			pad,
			xAt,
			yAt,
			yTicks,
			xTicks,
			lines,
			onMove,
			formatValue,
			dayLabel: ( day ) => day ? format.date( format.parseDay( day ).toISOString() ) : '',
			tooltipStyle: computed( () => {
				const x = hover.value === null ? 0 : xAt( hover.value );
				const right = x > width.value / 2;
				return right ?
					{ right: ( width.value - x + 12 ) + 'px', top: pad.top + 'px' } :
					{ left: ( x + 12 ) + 'px', top: pad.top + 'px' };
			} )
		};
	}
} );
</script>
