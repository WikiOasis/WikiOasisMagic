<template>
	<div class="wo-fi-request">
		<wiki-preview
			:source="preview.source"
			:info="preview.info"
			:dump="preview.dump"
		></wiki-preview>

		<cdx-message v-if="preview.lookupError === 'missing'" type="error">
			<span v-html="missingHtml"></span>
		</cdx-message>

		<template v-else>
			<cdx-message v-if="preview.lookupError === 'unreachable'" type="notice">
				{{ $i18n( 'wikioasismagic-fandomimport-lookup-unreachable', preview.source.host ).text() }}
			</cdx-message>

			<cdx-message v-if="form.blocker" type="error">
				<span v-html="form.blocker"></span>
			</cdx-message>

			<form v-else class="wo-fi-form" @submit.prevent="submit">
				<p class="wo-fi-form__intro">
					{{ $i18n( 'wikioasismagic-fandomimport-form-intro', preview.source.host ).text() }}
				</p>
				<cdx-message v-if="preview.info && preview.large" type="warning">
					{{ largeText }}
				</cdx-message>

				<fieldset class="wo-fi-form__group">
					<legend>{{ $i18n( 'wikioasismagic-fandomimport-group-wiki' ).text() }}</legend>
					<cdx-field
						:status="subdomainError ? 'error' : 'default'"
						:messages="subdomainError ? { error: subdomainError } : {}"
					>
						<cdx-text-input
							v-model="subdomain"
							:end-icon="subdomainValid ? cdxIconCheck : null"
							:status="subdomainError ? 'error' : 'default'"
							@update:model-value="checkSubdomain"
						></cdx-text-input>
						<template #label>
							{{ $i18n( 'wikioasismagic-fandomimport-field-subdomain' ).text() }}
						</template>
						<template #help-text>
							{{ $i18n( 'wikioasismagic-fandomimport-subdomain-preview', wikiAddress ).text() }}
						</template>
					</cdx-field>

					<cdx-field>
						<cdx-text-input v-model="sitename"></cdx-text-input>
						<template #label>
							{{ $i18n( 'wikioasismagic-fandomimport-field-sitename' ).text() }}
						</template>
					</cdx-field>

					<div class="wo-fi-form__row">
						<cdx-field>
							<cdx-lookup
								v-model:selected="language"
								v-model:input-value="languageInput"
								:menu-items="languageItems"
								:menu-config="{ visibleItemLimit: 8 }"
							></cdx-lookup>
							<template #label>
								{{ $i18n( 'wikioasismagic-fandomimport-field-language' ).text() }}
							</template>
						</cdx-field>

						<cdx-field v-if="form.categories.length">
							<cdx-select
								v-model:selected="category"
								:menu-items="form.categories"
								:default-label="$i18n( 'wikioasismagic-fandomimport-field-category' ).text()"
							></cdx-select>
							<template #label>
								{{ $i18n( 'wikioasismagic-fandomimport-field-category' ).text() }}
							</template>
						</cdx-field>
					</div>
				</fieldset>

				<fieldset class="wo-fi-form__group">
					<legend>{{ $i18n( 'wikioasismagic-fandomimport-group-community' ).text() }}</legend>
					<cdx-field :is-fieldset="true">
						<cdx-radio
							v-for="option in modes"
							:key="option.value"
							v-model="mode"
							name="wo-fi-mode"
							:input-value="option.value"
						>
							{{ option.label }}
							<template #description>
								{{ option.description }}
							</template>
						</cdx-radio>
						<template #label>
							{{ $i18n( 'wikioasismagic-fandomimport-field-mode' ).text() }}
						</template>
					</cdx-field>

					<cdx-field :optional="true">
						<cdx-text-input v-model="fandomUser" :start-icon="cdxIconUserAvatar"></cdx-text-input>
						<template #label>
							{{ $i18n( 'wikioasismagic-fandomimport-field-fandomuser-short' ).text() }}
						</template>
						<template #help-text>
							{{ $i18n( 'wikioasismagic-fandomimport-field-fandomuser-help' ).text() }}
						</template>
					</cdx-field>

					<cdx-field>
						<cdx-text-area v-model="reason" :autosize="true" rows="4"></cdx-text-area>
						<template #label>
							{{ $i18n( 'wikioasismagic-fandomimport-field-reason' ).text() }}
						</template>
						<template #help-text>
							{{ $i18n( 'wikioasismagic-fandomimport-field-reason-help' ).text() }}
						</template>
					</cdx-field>
				</fieldset>

				<cdx-checkbox v-model="agreement">
					{{ $i18n( 'wikioasismagic-fandomimport-field-agreement' ).text() }}
				</cdx-checkbox>

				<cdx-message v-if="error" type="error">
					<span v-html="error"></span>
				</cdx-message>

				<div class="wo-fi-form__actions">
					<cdx-button
						type="submit"
						action="progressive"
						weight="primary"
						:disabled="!canSubmit"
					>
						{{ $i18n( 'wikioasismagic-fandomimport-submit' ).text() }}
					</cdx-button>
					<a :href="indexUrl">{{ $i18n( 'wikioasismagic-fandomimport-cancel' ).text() }}</a>
				</div>
			</form>
		</template>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const {
	CdxButton, CdxCheckbox, CdxField, CdxLookup, CdxMessage, CdxRadio, CdxSelect, CdxTextArea, CdxTextInput
} = require( '../codex.js' );
const { cdxIconCheck, cdxIconUserAvatar } = require( './icons.json' );
const WikiPreview = require( './WikiPreview.vue' );
const api = require( '../api.js' );

module.exports = exports = defineComponent( {
	name: 'RequestView',
	components: {
		CdxButton, CdxCheckbox, CdxField, CdxLookup, CdxMessage, CdxRadio, CdxSelect, CdxTextArea, CdxTextInput,
		WikiPreview
	},
	props: {
		preview: { type: Object, required: true },
		form: { type: Object, required: true },
		indexUrl: { type: String, required: true }
	},
	setup( props ) {
		const info = props.preview.info;
		const findLanguage = ( code ) => props.form.languages.find( ( item ) => item.value === code );
		const initialLanguage = ( info && findLanguage( info.language ) ) || findLanguage( 'en' );

		const subdomain = ref( props.preview.suggestedSubdomain );
		const subdomainError = ref( '' );
		const subdomainValid = ref( false );
		const sitename = ref( info ? info.sitename : '' );
		const language = ref( initialLanguage ? initialLanguage.value : null );
		const languageInput = ref( initialLanguage ? initialLanguage.label : '' );
		const category = ref( props.form.categories.some( ( item ) => item.value === 'uncategorised' ) ?
			'uncategorised' :
			null );
		const mode = ref( 'move' );
		const fandomUser = ref( '' );
		const reason = ref( '' );
		const agreement = ref( false );
		const busy = ref( false );
		const error = ref( '' );

		let timer = null;
		let checked = 0;
		function checkSubdomain() {
			subdomainValid.value = false;
			subdomainError.value = '';
			clearTimeout( timer );
			const value = subdomain.value.trim();
			if ( !value ) {
				return;
			}
			timer = setTimeout( () => {
				const ticket = ++checked;
				api.subdomain( value ).then( ( result ) => {
					if ( ticket === checked ) {
						subdomainValid.value = result.valid;
						subdomainError.value = result.error || '';
					}
				} ).catch( () => {} );
			}, 400 );
		}
		checkSubdomain();

		const languageItems = computed( () => {
			const query = languageInput.value.trim().toLowerCase();
			const selected = findLanguage( language.value );
			if ( !query || ( selected && selected.label === languageInput.value ) ) {
				return props.form.languages;
			}
			return props.form.languages.filter( ( item ) => item.label.toLowerCase().includes( query ) );
		} );

		const canSubmit = computed( () => !busy.value && subdomain.value.trim() && !subdomainError.value &&
			sitename.value.trim() && language.value && reason.value.trim() && agreement.value &&
			( !props.form.categories.length || category.value ) );

		function submit() {
			if ( !canSubmit.value ) {
				return;
			}
			busy.value = true;
			error.value = '';
			api.act( {
				do: 'request',
				source: props.preview.source.subpage,
				subdomain: subdomain.value.trim(),
				sitename: sitename.value.trim(),
				language: language.value,
				category: category.value || '',
				mode: mode.value,
				fandomuser: fandomUser.value.trim(),
				reason: reason.value.trim(),
				agreement: 1
			} ).then( ( result ) => {
				window.location.href = result.url;
			} ).catch( ( message ) => {
				error.value = message;
				busy.value = false;
			} );
		}

		return {
			subdomain,
			subdomainError,
			subdomainValid,
			sitename,
			language,
			languageInput,
			languageItems,
			category,
			mode,
			fandomUser,
			reason,
			agreement,
			error,
			canSubmit,
			checkSubdomain,
			submit,
			cdxIconCheck,
			cdxIconUserAvatar,
			modes: [ 'move', 'fork' ].map( ( value ) => ( {
				value,
				label: mw.msg( 'wikioasismagic-fandomimport-mode-' + value ),
				description: mw.msg( 'wikioasismagic-fandomimport-mode-' + value + '-desc' )
			} ) ),
			wikiAddress: computed( () => ( subdomain.value.trim().toLowerCase() || '…' ) + '.' + props.form.domain ),
			largeText: computed( () => info ?
				mw.msg( 'wikioasismagic-fandomimport-form-large', mw.language.convertNumber( info.files ) ) :
				'' ),
			missingHtml: computed( () => mw.message( 'wikioasismagic-fandomimport-lookup-missing',
				props.preview.source.baseUrl, props.preview.source.host ).parse() )
		};
	}
} );
</script>
