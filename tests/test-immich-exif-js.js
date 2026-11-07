/**
 * EXIF block checks for src/immich-link.js. Node only, no Vue runtime, no browser.
 * Loads the real file in a vm sandbox with a fake window.fetch.
 * Covers the template text, the place computed property and the stale-answer guard.
 * Run: node tests/test-immich-exif-js.js
 */
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const file = path.join( __dirname, '..', 'tainacan-immich', 'src', 'immich-link.js' );
const source = fs.readFileSync( file, 'utf8' );

let passes = 0;
let failures = 0;
function check( label, expected, actual ) {
	if ( expected === actual ) {
		passes++;
		console.log( 'PASS  ' + label );
	} else {
		failures++;
		console.log( 'FAIL  ' + label + ': expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
	}
}

const pending = [];
const sandbox = {
	window: {
		tainacanImmich: { restUrl: 'https://wp.example.test/wp-json/tainacan-immich/v1/', nonce: 'fake-nonce' },
		fetch: function ( url ) {
			return new Promise( ( resolve ) => {
				pending.push( { url, resolve } );
			} );
		},
	},
	console,
	setTimeout,
	clearTimeout,
};
vm.createContext( sandbox );
vm.runInContext( source, sandbox, { filename: file } );

const comp = sandbox.window.tainacan_extra_components[ 'tainacan-immich-link' ];
check( 'component registered', true, !! comp );

const template = comp.template;
check( 'no v-html', false, /v-html/.test( template ) );
check( 'osm link text', true, template.indexOf( 'Open in OpenStreetMap' ) >= 0 );
check( 'osm link rel', true, template.indexOf( 'rel="noopener noreferrer"' ) >= 0 );
check( 'osm link target', true, template.indexOf( 'target="_blank"' ) >= 0 );
check( 'null island text', true, template.indexOf( 'Location is 0, 0. Check the photo.' ) >= 0 );
check( 'null island class', true, template.indexOf( 'has-text-warning' ) >= 0 );
check( 'no gps text', true, template.indexOf( 'This photo has no GPS location.' ) >= 0 );
check( 'date line text', true, template.indexOf( 'Date taken: {{ result.exif.date_taken }}' ) >= 0 );
check( 'place line text', true, template.indexOf( 'Place: {{ place }}' ) >= 0 );
check( 'exif block class', true, template.indexOf( 'class="tainacan-immich-exif"' ) >= 0 );

// place computed
const place = comp.computed.place;
check( 'place city state country', 'Paris, IDF, France', place.call( { result: { exif: { city: 'Paris', state: 'IDF', country: 'France' } } } ) );
check( 'place skips empty parts', 'Paris, France', place.call( { result: { exif: { city: 'Paris', state: '', country: 'France' } } } ) );
check( 'place all empty', '', place.call( { result: { exif: { city: '', state: '', country: '' } } } ) );
check( 'place no exif', '', place.call( { result: { exif: null } } ) );
check( 'place no result', '', place.call( { result: null } ) );

const kind = comp.computed.kind;
check( 'kind default asset', 'asset', kind.call( { itemMetadatum: null } ) );
check( 'kind album', 'album', kind.call( { itemMetadatum: { metadatum: { metadata_type_options: { link_kind: 'album' } } } } ) );

// stale answer guard
function fakeThis() {
	return {
		hasConfig: true,
		kind: 'asset',
		requestCounter: 0,
		result: null,
		timer: null,
		$emit() {},
		check: comp.methods.check,
	};
}

( async function run() {
	const self = fakeThis();
	self.check( 'A', false );
	self.check( 'B', false );
	check( 'two fetches sent', 2, pending.length );
	// Answer for B first, then the old answer for A.
	pending[ 1 ].resolve( { json: () => Promise.resolve( { ok: true, label: 'B.jpg', exif: { has_gps: false, city: 'Newer', state: '', country: '' } } ) } );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	pending[ 0 ].resolve( { json: () => Promise.resolve( { ok: true, label: 'A.jpg', exif: { has_gps: true, lat: '1', lon: '2', map_url: 'https://www.openstreetmap.org/?mlat=1&mlon=2#map=15/1/2', null_island: false, city: 'Old', state: '', country: '' } } ) } );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	check( 'stale answer ignored label', 'B.jpg', self.result && self.result.label );
	check( 'stale answer ignored exif', 'Newer', self.result && self.result.exif && self.result.exif.city );

	// Empty input clears the EXIF block and invalidates pending answers.
	const self2 = fakeThis();
	self2.check = comp.methods.check;
	self2.onInput = comp.methods.onInput;
	self2.check( 'C', false );
	self2.onInput( '' );
	check( 'empty input clears result', null, self2.result );
	pending[ 2 ].resolve( { json: () => Promise.resolve( { ok: true, label: 'C.jpg', exif: { has_gps: true, lat: '3', lon: '4', map_url: 'x', null_island: false, city: '', state: '', country: '' } } ) } );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	check( 'cleared answer not restored', null, self2.result );

	// Text with HTML in place fields is data only (the template uses {{ }}).
	check( 'city html kept as data', '<b>x</b>', place.call( { result: { exif: { city: '<b>x</b>', state: '', country: '' } } } ) );

	console.log( '\n== JS summary: ' + passes + ' passed, ' + failures + ' failed' );
	process.exit( failures > 0 ? 1 : 0 );
} )();
