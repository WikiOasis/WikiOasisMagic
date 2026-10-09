<template>
	<div class="wo-fi">
		<status-view v-if="config.view === 'status'" :initial="config.detail"></status-view>
		<request-view
			v-else-if="config.view === 'request'"
			:preview="config.preview"
			:form="config.form"
			:index-url="config.indexUrl"
		></request-view>
		<index-view v-else :config="config"></index-view>
	</div>
</template>

<script>
const { defineComponent } = require( 'vue' );
const IndexView = require( './IndexView.vue' );
const RequestView = require( './RequestView.vue' );
const StatusView = require( './StatusView.vue' );

module.exports = exports = defineComponent( {
	name: 'App',
	components: { IndexView, RequestView, StatusView },
	props: {
		config: { type: Object, required: true }
	}
} );
</script>
