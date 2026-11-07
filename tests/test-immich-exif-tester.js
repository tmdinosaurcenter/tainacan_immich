/**
 * Tester checks for the EXIF block template in src/immich-link.js. Node only, no Vue runtime.
 * Checks the order of the blocks, that the EXIF block sits inside the ok branch, the exact
 * line texts from the spec, and that only text bindings are used.
 * Run: node tests/test-immich-exif-tester.js
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

const sandbox = { window: {}, console, setTimeout, clearTimeout };
vm.createContext( sandbox );
vm.runInContext( source, sandbox, { filename: file } );
const comp = sandbox.window.tainacan_extra_components[ 'tainacan-immich-link' ];
const t = comp.template;

const idxOk = t.indexOf( '<template v-if="result.ok">' );
const idxExif = t.indexOf( 'class="tainacan-immich-exif"' );
const idxElse = t.indexOf( '<span v-else class="has-text-danger">' );
const idxLabel = t.indexOf( '<span>{{ result.label }}</span>' );
check( 'ok branch exists', true, idxOk >= 0 );
check( 'label line exists', true, idxLabel >= 0 );
check( 'exif block after label', true, idxLabel < idxExif );
check( 'exif block inside ok branch', true, idxOk < idxExif && idxExif < idxElse );

// Order inside the EXIF block: location, null island, no-gps, place, date.
const order = [
	'Location: {{ result.exif.lat }}, {{ result.exif.lon }}',
	'Open in OpenStreetMap',
	'Location is 0, 0. Check the photo.',
	'This photo has no GPS location.',
	'Place: {{ place }}',
	'Date taken: {{ result.exif.date_taken }}',
	'({{ result.exif.time_zone }})',
];
let last = idxExif;
let ordered = true;
const positions = [];
for ( const s of order ) {
	const p = t.indexOf( s, last );
	positions.push( p );
	if ( p < 0 ) {
		ordered = false;
		break;
	}
	last = p;
}
check( 'EXIF lines present in spec order (' + positions.join( ',' ) + ')', true, ordered );

check( 'map link uses map_url binding', true, t.indexOf( ':href="result.exif.map_url"' ) >= 0 );
check( 'map link has _blank', true, /<a :href="result\.exif\.map_url" target="_blank" rel="noopener noreferrer">Open in OpenStreetMap<\/a>/.test( t ) );
check( 'null island only inside has_gps', true, t.indexOf( 'v-if="result.exif.null_island"' ) > t.indexOf( 'v-if="result.exif.has_gps"' ) );
check( 'null island warning class', true, /class="has-text-warning">Location is 0, 0\. Check the photo\./.test( t ) );
check( 'place hidden when empty (v-if)', true, t.indexOf( '<div v-if="place">Place: {{ place }}</div>' ) >= 0 );
check( 'date hidden when empty (v-if)', true, t.indexOf( 'v-if="result.exif.date_taken"' ) >= 0 );
check( 'time zone only when set', true, t.indexOf( '<span v-if="result.exif.time_zone"> ({{ result.exif.time_zone }})</span>' ) >= 0 );

// Only text bindings. No raw HTML binding anywhere in the template.
check( 'no v-html in template', false, /v-html/.test( t ) );
check( 'no innerHTML in source', false, /innerHTML/.test( source ) );
check( 'no triple-brace binding', false, /\{\{\{/.test( t ) );

// The EXIF block does not add a button or a write call.
const exifPart = t.slice( idxExif, idxElse );
check( 'exif block has no button', false, /<b-button|<button/.test( exifPart ) );
check( 'exif block has no input', false, /<input|<b-input/.test( exifPart ) );
check( 'no fetch with POST in source', false, /method\s*:\s*['"](POST|PUT|PATCH|DELETE)['"]/i.test( source ) );

// Place: join rule with only some parts present.
const place = comp.computed.place;
check( 'place only state', 'IDF', place.call( { result: { exif: { city: '', state: 'IDF', country: '' } } } ) );
check( 'place only country', 'France', place.call( { result: { exif: { city: '', state: '', country: 'France' } } } ) );
check( 'place city and country', 'Paris, France', place.call( { result: { exif: { city: 'Paris', state: '', country: 'France' } } } ) );
check( 'place keeps html text as data', '<img src=x>, b', place.call( { result: { exif: { city: '<img src=x>', state: 'b', country: '' } } } ) );

console.log( '\n== JS tester summary: ' + passes + ' passed, ' + failures + ' failed' );
process.exit( failures > 0 ? 1 : 0 );
