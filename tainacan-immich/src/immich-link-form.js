/**
 * Options-form component for the "Immich link" metadata type.
 *
 * Plain JS (Vue 3 options API, template string). No imports.
 * Contract: props `value` (the options object) and `$emit('update:value', options)`.
 */
window.tainacan_extra_components =
	typeof window.tainacan_extra_components !== 'undefined'
		? window.tainacan_extra_components
		: {};

const TainacanImmichLinkForm = {
	name: 'TainacanImmichLinkForm',
	props: {
		value: [ String, Number, Array, Object ],
	},
	emits: [ 'update:value' ],
	data() {
		return {
			linkKind: 'asset',
		};
	},
	created() {
		const kind = this.value && this.value.link_kind;
		this.linkKind =
			[ 'asset', 'album', 'person' ].indexOf( kind ) >= 0
				? kind
				: 'asset';
	},
	methods: {
		onUpdateLinkKind( kind ) {
			this.linkKind = kind;
			this.$emit( 'update:value', { link_kind: kind } );
		},
	},
	template: `
		<div>
			<b-field :addons="false" label="Kind of Immich link">
				<b-select
					size="is-small"
					:model-value="linkKind"
					@update:model-value="onUpdateLinkKind">
					<option value="asset">Asset (many allowed)</option>
					<option value="album">Album (one)</option>
					<option value="person">Person (one)</option>
				</b-select>
			</b-field>
		</div>
	`,
};

window.tainacan_extra_components[ 'tainacan-immich-link-form' ] =
	TainacanImmichLinkForm;
