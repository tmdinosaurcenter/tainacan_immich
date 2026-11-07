/**
 * Item edit-form component for the "Immich link" metadata type.
 *
 * Plain JS (Vue 2 options API, template string). No imports: Tainacan loads this
 * bundle with no script dependencies, and Tainacan provides Vue and Buefy.
 */
window.tainacan_extra_components =
	typeof window.tainacan_extra_components !== 'undefined'
		? window.tainacan_extra_components
		: {};

const DEBOUNCE_MS = 500;

const TainacanImmichLink = {
	name: 'TainacanImmichLink',
	props: {
		itemMetadatum: Object,
		value: [ String, Number, Array ],
		disabled: false,
	},
	data() {
		return {
			timer: null,
			requestCounter: 0,
			result: null,
		};
	},
	computed: {
		kind() {
			const options =
				this.itemMetadatum &&
				this.itemMetadatum.metadatum &&
				this.itemMetadatum.metadatum.metadata_type_options;
			const kind = options ? options.link_kind : '';
			return [ 'asset', 'album', 'person' ].indexOf( kind ) >= 0
				? kind
				: 'asset';
		},
		inputId() {
			if ( ! this.itemMetadatum || ! this.itemMetadatum.metadatum ) {
				return 'tainacan-immich-link';
			}
			return (
				'tainacan-item-metadatum_id-' +
				this.itemMetadatum.metadatum.id +
				( this.itemMetadatum.parent_meta_id
					? '_parent_meta_id-' + this.itemMetadatum.parent_meta_id
					: '' )
			);
		},
		hasConfig() {
			return !! (
				window.tainacanImmich &&
				window.tainacanImmich.restUrl &&
				window.tainacanImmich.nonce
			);
		},
	},
	created() {
		if ( this.value && typeof this.value === 'string' ) {
			this.check( this.value, false );
		}
	},
	beforeDestroy() {
		clearTimeout( this.timer );
	},
	methods: {
		onInput( text ) {
			this.$emit( 'input', text );
			clearTimeout( this.timer );
			if ( ! text || ! String( text ).trim() ) {
				this.requestCounter++;
				this.result = null;
				return;
			}
			this.timer = setTimeout(
				() => this.check( text, true ),
				DEBOUNCE_MS
			);
		},
		onBlur() {
			this.$emit( 'blur' );
		},
		check( text, emitId ) {
			if ( ! this.hasConfig || ! window.fetch ) {
				return;
			}
			const counter = ++this.requestCounter;
			const url =
				window.tainacanImmich.restUrl +
				'check?kind=' +
				encodeURIComponent( this.kind ) +
				'&input=' +
				encodeURIComponent( text );
			window
				.fetch( url, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': window.tainacanImmich.nonce },
				} )
				.then( ( response ) => response.json() )
				.then( ( data ) => {
					if ( counter !== this.requestCounter ) {
						return;
					}
					this.result = data;
					if ( emitId && data && data.id && data.id !== text ) {
						this.$emit( 'input', data.id );
						this.$emit( 'blur' );
					}
				} )
				.catch( () => {
					if ( counter === this.requestCounter ) {
						this.result = null;
					}
				} );
		},
	},
	template: `
		<div v-if="itemMetadatum">
			<b-input
				:disabled="disabled"
				:id="inputId"
				:value="value"
				:placeholder="itemMetadatum.metadatum.placeholder ? itemMetadatum.metadatum.placeholder : 'Immich ID or URL'"
				@input="onInput($event)"
				@blur="onBlur" />
			<div v-if="result" class="tainacan-immich-check">
				<template v-if="result.ok">
					<img v-if="result.thumb" :src="result.thumb" alt="" style="max-height: 96px; max-width: 96px; display: block; margin-top: 0.5em;">
					<span>{{ result.label }}</span>
				</template>
				<span v-else class="has-text-danger">{{ result.message }}</span>
			</div>
		</div>
	`,
};

window.tainacan_extra_components[ 'tainacan-immich-link' ] = TainacanImmichLink;
