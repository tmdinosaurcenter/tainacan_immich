/**
 * Item edit-form component for the "Immich link" metadata type.
 *
 * Plain JS (Vue 3 options API, template string). No imports: Tainacan loads this
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
	emits: [ 'update:value', 'blur' ],
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
		place() {
			const exif = this.result && this.result.exif;
			if ( ! exif ) {
				return '';
			}
			return [ exif.city, exif.state, exif.country ]
				.filter( ( part ) => !! part )
				.join( ', ' );
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
	beforeUnmount() {
		clearTimeout( this.timer );
	},
	methods: {
		onInput( text ) {
			this.$emit( 'update:value', text );
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
						this.$emit( 'update:value', data.id );
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
				:model-value="value"
				:placeholder="itemMetadatum.metadatum.placeholder ? itemMetadatum.metadatum.placeholder : 'Immich ID, URL or share link'"
				@update:model-value="onInput($event)"
				@blur="onBlur" />
			<div v-if="result" class="tainacan-immich-check">
				<template v-if="result.ok">
					<img v-if="result.thumb" :src="result.thumb" alt="" style="max-height: 96px; max-width: 96px; display: block; margin-top: 0.5em;">
					<span>{{ result.label }}</span>
					<div v-if="result.exif" class="tainacan-immich-exif">
						<template v-if="result.exif.has_gps">
							<div>Location: {{ result.exif.lat }}, {{ result.exif.lon }} <a :href="result.exif.map_url" target="_blank" rel="noopener noreferrer">Open in OpenStreetMap</a></div>
							<div v-if="result.exif.null_island" class="has-text-warning">Location is 0, 0. Check the photo.</div>
						</template>
						<div v-else>This photo has no GPS location.</div>
						<div v-if="place">Place: {{ place }}</div>
						<div v-if="result.exif.date_taken">Date taken: {{ result.exif.date_taken }}<span v-if="result.exif.time_zone"> ({{ result.exif.time_zone }})</span></div>
					</div>
				</template>
				<span v-else class="has-text-danger">{{ result.message }}</span>
			</div>
		</div>
	`,
};

window.tainacan_extra_components[ 'tainacan-immich-link' ] = TainacanImmichLink;
