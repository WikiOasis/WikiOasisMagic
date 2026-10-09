<template>
	<cdx-message v-if="pairs.length" type="notice" class="wo-exp-overrides">
		<span>{{ $i18n( 'wikioasismagic-experiments-overrides', pairs.join( ', ' ) ).text() }}</span>
		<a :href="env.resetOverridesUrl">{{ $i18n( 'wikioasismagic-experiments-overrides-reset' ).text() }}</a>
	</cdx-message>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxMessage } = require( '../codex.js' );

module.exports = exports = defineComponent( {
	name: 'OverridesNotice',
	components: { CdxMessage },
	props: {
		env: { type: Object, required: true }
	},
	setup( props ) {
		return {
			pairs: computed( () => Object.keys( props.env.overrides || {} )
				.map( ( name ) => name + ' → ' + props.env.overrides[ name ] ) )
		};
	}
} );
</script>
