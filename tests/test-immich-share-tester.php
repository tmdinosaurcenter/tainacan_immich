<?php
/**
 * Tester checks for share links: spec edge cases 1-29 and the acceptance checks.
 * Plain PHP, stubs only, no network. Fake host, fake key, fake slugs and keys.
 * Run: php tests/test-immich-share-tester.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;
use Tainacan\Entities\Item_Metadata_Entity as E;
use Tainacan\Metadata_Types\Immich_Link as L;

$A      = '11111111-2222-3333-4444-555555555555';
$B      = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$H      = 'https://immich.example.org';
$SLUG   = 'SeCrEtSlug';
$KEY    = 'KeYsEcReT-9_z';

function tt_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
}
function tt_album( $id ) {
	return json_encode( array( 'type' => 'ALBUM', 'password' => null, 'album' => array( 'id' => $id, 'albumName' => 'Trip' ) ) );
}
function tt_ind( $ids ) {
	$a = array();
	foreach ( $ids as $i ) {
		$a[] = array( 'id' => $i );
	}
	return json_encode( array( 'type' => 'INDIVIDUAL', 'password' => null, 'assets' => $a ) );
}
function tt_http_count() {
	return count( $GLOBALS['test_http_log'] );
}
function tt_leak( $label, $text, $extra ) {
	$secrets = array_merge( $extra, array( 'fake-key-abc123', 'immich.example.org' ) );
	foreach ( $secrets as $s ) {
		t_not_contains( "$label has no '$s'", $s, $text );
	}
}

// ---- Happy path ----

t_run( 'happy path: album share in album field', function () use ( $A, $H, $SLUG ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_album( strtoupper( $A ) ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'albumName' => 'Trip' ) ) );
	$r = R::check( 'album', "$H/s/$SLUG" );
	t_check( 'ok', true, $r['ok'] );
	t_check( 'code', 'ok', $r['code'] );
	t_check( 'id is lowercase uuid', $A, $r['id'] );
	t_check( 'label', 'Trip', $r['label'] );
	t_check( 'two calls', 2, tt_http_count() );
	t_check( 'share url', "https://immich.example.org/api/shared-links/me?slug=$SLUG", $GLOBALS['test_http_log'][0][0] );
	t_check( 'info url', "https://immich.example.org/api/albums/$A", $GLOBALS['test_http_log'][1][0] );
	t_check( 'share call has no key', false, array_key_exists( 'x-api-key', $GLOBALS['test_http_log'][0][1]['headers'] ) );
	t_check( 'info call has key', 'fake-key-abc123', $GLOBALS['test_http_log'][1][1]['headers']['x-api-key'] );
	tt_leak( 'album output', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $SLUG ) );
} );

t_run( 'happy path: one photo share in asset field, with exif', function () use ( $A, $H, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_ind( array( strtoupper( $A ) ) ) );
	t_queue( 200, json_encode( array(
		'id'               => $A,
		'originalFileName' => 'a.jpg',
		'isTrashed'        => false,
		'exifInfo'         => array( 'latitude' => 48.5, 'longitude' => 2.25, 'city' => 'Paris' ),
	) ) );
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( 'ok', true, $r['ok'] );
	t_check( 'id', $A, $r['id'] );
	t_check( 'label', 'a.jpg', $r['label'] );
	t_check( 'exif gps', true, $r['exif']['has_gps'] );
	t_contains( 'thumb url', '/thumbnail?', $r['thumb'] );
	t_check( 'key url', "https://immich.example.org/api/shared-links/me?key=$KEY", $GLOBALS['test_http_log'][0][0] );
	tt_leak( 'asset output', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $KEY ) );
} );

t_run( 'happy path: validate accepts the UUID after conversion', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	$t = new L();
	t_check( 'uuid valid', true, $t->validate( new E( $A ) ) );
	t_check( 'no errors', array(), $t->errors );
} );

// ---- Edge 1: empty and whitespace input ----

t_run( 'edge 1: empty and whitespace input', function () {
	t_reset();
	t_configure();
	foreach ( array( '', '   ', "\t\n", null ) as $i => $v ) {
		$t = new L();
		t_check( "validate skips $i", true, $t->validate( new E( $v ) ) );
		t_check( "validate no errors $i", array(), $t->errors );
	}
	$r = R::check( 'asset', '   ' );
	t_check( 'check bad_input', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'check message', 'This is not an Immich asset ID or URL.', $r['message'] );
	t_check( 'check not ok', false, $r['ok'] );
	t_check( 'edge 1 no http', 0, tt_http_count() );
} );

// ---- Edge 2: non-string input ----

t_run( 'edge 2: non-string input', function () {
	t_reset();
	t_configure();
	foreach ( array( null, 5, 1.5, array(), array( 'https://immich.example.org/s/abc' ), true ) as $i => $v ) {
		t_check( "looks_like_share $i", false, C::looks_like_share( $v ) );
		t_check( "parse_share $i", array(), C::parse_share( $v ) );
		t_check( "parse_id $i", '', C::parse_id( $v, 'asset' ) );
		$r = R::check( 'asset', $v );
		t_check( "check $i", 'tainacan_immich_bad_input', $r['code'] );
	}
	t_check( 'no http', 0, tt_http_count() );
} );

// ---- Edge 3: input over 2048 characters ----

t_run( 'edge 3: length limit', function () use ( $H ) {
	t_reset();
	t_configure();
	$pre   = "$H/s/abc?x=";
	$exact = $pre . str_repeat( 'a', 2048 - strlen( $pre ) );
	t_check( 'exact 2048 length', 2048, strlen( $exact ) );
	t_check( '2048 accepted', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( $exact ) );
	$over = $pre . str_repeat( 'a', 2049 - strlen( $pre ) );
	t_check( 'over 2048 length', 2049, strlen( $over ) );
	t_check( '2049 refused by parse_share', array(), C::parse_share( $over ) );
	t_check( '2049 looks like share', true, C::looks_like_share( $over ) );

	$r = R::check( 'asset', $over );
	t_check( 'check refused', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'check no id', '', $r['id'] );
	t_check( 'check no http', 0, tt_http_count() );

	$t = new L();
	t_check( 'validate refused', false, $t->validate( new E( $over ) ) );
	t_check( 'validate one error', 1, count( $t->errors ) );
	t_not_contains( 'validate no echo of long input', 'aaaaaaaaaa', $t->errors[0] );
	t_not_contains( 'validate no echo of host', 'immich.example.org', $t->errors[0] );
	t_check( 'no http for validate', 0, tt_http_count() );

	$long = "$H/s/abc?x=" . str_repeat( 'LONGMARK', 400 );
	$r    = R::check( 'asset', $long );
	t_check( 'long message is fixed text', 'This is not a valid Immich share link for this server.', $r['message'] );
	t_not_contains( 'long check no echo', 'LONGMARK', json_encode( $r ) );
} );

// ---- Edge 4 and 5: slug with dots, key with - and _ ----

t_run( 'edge 4 and 5: dots in slug, dash and underscore in key', function () use ( $H ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_album( '11111111-2222-3333-4444-555555555555' ) );
	t_queue( 200, json_encode( array( 'id' => '11111111-2222-3333-4444-555555555555', 'albumName' => 'T' ) ) );
	R::check( 'album', "$H/s/ta2020.1" );
	t_check( 'slug dots url', 'https://immich.example.org/api/shared-links/me?slug=ta2020.1', $GLOBALS['test_http_log'][0][0] );

	t_queue( 200, tt_ind( array( '11111111-2222-3333-4444-555555555555' ) ) );
	t_queue( 200, json_encode( array( 'id' => '11111111-2222-3333-4444-555555555555', 'originalFileName' => 'x' ) ) );
	R::check( 'asset', "$H/share/AbC-12_x" );
	t_check( 'key dash underscore url', 'https://immich.example.org/api/shared-links/me?key=AbC-12_x', $GLOBALS['test_http_log'][2][0] );

	t_check( 'slug single char', array( 'param' => 'slug', 'value' => 'a' ), C::parse_share( "$H/s/a" ) );
	t_check( 'slug two chars', array( 'param' => 'slug', 'value' => 'ab' ), C::parse_share( "$H/s/ab" ) );
	t_check( 'slug dash inside', array( 'param' => 'slug', 'value' => 'a-b_c.d' ), C::parse_share( "$H/s/a-b_c.d" ) );
	t_check( 'key single char', array( 'param' => 'key', 'value' => 'k' ), C::parse_share( "$H/share/k" ) );
} );

// ---- Edge 6: trailing slash ----

t_run( 'edge 6: trailing slashes', function () use ( $H ) {
	t_reset();
	t_configure();
	t_check( 'one trailing slash ok', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc/" ) );
	t_check( 'two trailing refused', array(), C::parse_share( "$H/s/abc//" ) );
	t_check( 'share two trailing refused', array(), C::parse_share( "$H/share/abc//" ) );
	t_check( 'empty segment refused', array(), C::parse_share( "$H/s//" ) );
	$r = R::check( 'asset', "$H/s/abc//" );
	t_check( 'check two trailing bad_input', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'no http for two trailing', 0, tt_http_count() );
} );

// ---- Edge 7: query and fragment ----

t_run( 'edge 7: query and fragment ignored', function () use ( $H ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_album( '11111111-2222-3333-4444-555555555555' ) );
	t_queue( 200, json_encode( array( 'id' => '11111111-2222-3333-4444-555555555555', 'albumName' => 'T' ) ) );
	R::check( 'album', "$H/s/abc?x=1#y" );
	t_check( 'only slug sent', 'https://immich.example.org/api/shared-links/me?slug=abc', $GLOBALS['test_http_log'][0][0] );
	t_check( 'query value ignored', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc?slug=other&key=zz#frag" ) );
	t_check( 'fragment only', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc#/x/y" ) );
} );

// ---- Edge 8: wrong host, port, userinfo ----

t_run( 'edge 8: wrong host, port, userinfo', function () use ( $H ) {
	t_reset();
	t_configure();
	$bad = array(
		'other host'   => 'https://other.example.org/s/abc',
		'host suffix'  => 'https://evil-immich.example.org/s/abc',
		'host prefix'  => 'https://immich.example.org.evil.test/s/abc',
		'other port'   => 'https://immich.example.org:8443/s/abc',
		'userinfo'     => 'https://user:pass@immich.example.org/s/abc',
		'user only'    => 'https://user@immich.example.org/s/abc',
	);
	foreach ( $bad as $label => $url ) {
		t_check( "parse_share $label", array(), C::parse_share( $url ) );
		$r = R::check( 'asset', $url );
		t_check( "check $label", 'tainacan_immich_bad_input', $r['code'] );
		$t = new L();
		t_check( "validate $label refused", false, $t->validate( new E( $url ) ) );
		t_check( "validate $label one error", 1, count( $t->errors ) );
	}
	t_check( 'no http for host refusals', 0, tt_http_count() );
} );


// ---- Edge 9: Immich URL option empty or unset ----

t_run( 'edge 9: Immich URL option empty or unset', function () use ( $H, $SLUG ) {
	t_reset();
	t_configure();
	$GLOBALS['test_options']['tainacan_immich_url'] = '';
	t_check( 'empty option parse', array(), C::parse_share( "$H/s/abc" ) );
	$r = R::check( 'album', "$H/s/$SLUG" );
	t_check( 'empty option check', 'tainacan_immich_bad_input', $r['code'] );
	unset( $GLOBALS['test_options']['tainacan_immich_url'] );
	t_check( 'unset option parse', array(), C::parse_share( "$H/s/abc" ) );
	$r = R::check( 'album', "$H/s/$SLUG" );
	t_check( 'unset option check', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'no http', 0, tt_http_count() );
	tt_leak( 'option empty message', $r['message'], array( $SLUG ) );
} );

// ---- Edge 10: http and https both accepted when host and port match ----

t_run( 'edge 10: scheme', function () use ( $H ) {
	t_reset();
	t_configure();
	t_check( 'http input, https base', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( 'http://immich.example.org/s/abc' ) );
	t_check( 'HTTP upper input', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( 'HTTP://immich.example.org/s/abc' ) );
	$GLOBALS['test_options']['tainacan_immich_url'] = 'http://immich.example.org';
	t_check( 'https input, http base', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc" ) );
	t_check( 'ftp refused', array(), C::parse_share( 'ftp://immich.example.org/s/abc' ) );
	t_check( 'javascript refused', array(), C::parse_share( 'javascript://immich.example.org/s/abc' ) );
} );

// ---- Edge 11: base URL with path prefix ----

t_run( 'edge 11: base path prefix', function () use ( $H ) {
	t_reset();
	t_configure();
	$GLOBALS['test_options']['tainacan_immich_url'] = "$H/immich";
	t_check( 'prefix accepted', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/immich/s/abc" ) );
	t_check( 'prefix trailing slash', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/immich/s/abc/" ) );
	t_check( 'no prefix refused', array(), C::parse_share( "$H/s/abc" ) );
	t_check( 'similar prefix refused', array(), C::parse_share( "$H/immichx/s/abc" ) );
	t_check( 'prefix only refused', array(), C::parse_share( "$H/immich" ) );
	$GLOBALS['test_options']['tainacan_immich_url'] = "$H/immich/";
	t_check( 'base trailing slash prefix', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/immich/s/abc" ) );
	$r = R::check( 'album', "$H/s/abc" );
	t_check( 'check no prefix bad_input', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'no http for no-prefix', 0, tt_http_count() );
} );

// ---- Edge 12: path traversal ----

t_run( 'edge 12: traversal', function () use ( $H ) {
	t_reset();
	t_configure();
	$bad = array( '/s/../x', '/s/..', '/s/.', '/s/.abc', '/share/..', '/share/../x', '/s/a/../b', '/../s/abc', '/s/abc/..', '/s/abc/.', '/share/./abc' );
	foreach ( $bad as $p ) {
		t_check( "parse_share refused $p", array(), C::parse_share( $H . $p ) );
	}
	$r = R::check( 'album', "$H/s/../x" );
	t_check( 'check traversal bad_input', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'no http for traversal', 0, tt_http_count() );
} );

// ---- Edge 13: encoded characters and backslash ----

t_run( 'edge 13: encoded characters and backslash', function () use ( $H ) {
	t_reset();
	t_configure();
	$bad = array( '/s/a%2Fb', '/share/a%2e%2e', '/s/a%2e', '/s/a%20b', '/s/a%5Cb', '/share/%41bc', '/s/%', "/s/a\\b", "/share/a\\.." );
	foreach ( $bad as $p ) {
		t_check( "parse_share refused $p", array(), C::parse_share( $H . $p ) );
	}
	$r = R::check( 'album', "$H/s/a%2Fb" );
	t_check( 'check encoded bad_input', 'tainacan_immich_bad_input', $r['code'] );
	$t = new L();
	t_check( 'validate encoded refused', false, $t->validate( new E( "$H/s/a%2Fb" ) ) );
	t_check( 'no http for encoded', 0, tt_http_count() );
} );

// ---- Edge 14: bad characters and length ----

t_run( 'edge 14: bad characters and length', function () use ( $H ) {
	t_reset();
	t_configure();
	$bad = array( '/s/a b', '/share/a+b', '/share/a=b', '/s/caf%C3%A9', "/s/caf\xc3\xa9", '/s/a!b', '/s/a*b', '/s/a~b', '/s/a@b', '/s/a:b', '/s/a(b)', '/s/a&b', '/s/a,b', '/s/a;b', '/s/a|b', '/s/a[b]', '/share/a.b', '/share/a.' );
	foreach ( $bad as $p ) {
		t_check( "refused $p", array(), C::parse_share( $H . $p ) );
	}
	t_check( 'slug 128 ok', 128, strlen( C::parse_share( "$H/s/" . str_repeat( 'a', 128 ) )['value'] ) );
	t_check( 'slug 129 refused', array(), C::parse_share( "$H/s/" . str_repeat( 'a', 129 ) ) );
	t_check( 'key 128 ok', 128, strlen( C::parse_share( "$H/share/" . str_repeat( 'k', 128 ) )['value'] ) );
	t_check( 'key 129 refused', array(), C::parse_share( "$H/share/" . str_repeat( 'k', 129 ) ) );
	t_check( 'slug ends dot refused', array(), C::parse_share( "$H/s/abc." ) );
	t_check( 'slug starts dot refused', array(), C::parse_share( "$H/s/.abc" ) );
	t_check( 'slug 2020 dot ok', array( 'param' => 'slug', 'value' => 'ta2020.1' ), C::parse_share( "$H/s/ta2020.1" ) );
	$r = R::check( 'album', "$H/s/a%20b" );
	t_check( 'check bad chars bad_input', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'no http for bad chars', 0, tt_http_count() );
} );

// ---- Edge 15: nothing after s or share ----

t_run( 'edge 15: nothing after s or share', function () use ( $H ) {
	t_reset();
	t_configure();
	foreach ( array( "$H/s/", "$H/share/", "$H/s//", "$H/share//", "$H/s", "$H/share" ) as $u ) {
		t_check( "parse_share refused $u", array(), C::parse_share( $u ) );
		$r = R::check( 'asset', $u );
		t_check( "check refused $u", 'tainacan_immich_bad_input', $r['code'] );
		$t = new L();
		t_check( "validate refused $u", false, $t->validate( new E( $u ) ) );
		t_check( "validate one error $u", 1, count( $t->errors ) );
	}
	t_check( 'no http', 0, tt_http_count() );
} );

// ---- Edge 16: person kind ----

t_run( 'edge 16: person kind with a share link', function () use ( $H, $SLUG, $KEY ) {
	t_reset();
	t_configure();
	foreach ( array( "$H/s/$SLUG", "$H/share/$KEY" ) as $u ) {
		$r = R::check( 'person', $u );
		t_check( "check person $u", 'tainacan_immich_bad_input', $r['code'] );
		t_check( "check person text $u", 'This is not an Immich person ID or URL.', $r['message'] );
		$res = C::resolve_share( $u, 'person' );
		t_check( "resolve person $u", 'tainacan_immich_bad_input', tt_code( $res ) );
		$t = new L();
		$t->options['link_kind'] = 'person';
		t_check( "validate person refused $u", false, $t->validate( new E( $u ) ) );
		tt_leak( "person $u", json_encode( array( $r, $t->errors ), JSON_UNESCAPED_SLASHES ), array( $SLUG, $KEY ) );
	}
	t_check( 'no http for person', 0, tt_http_count() );
} );

// ---- Edge 17: album link in album field ----

t_run( 'edge 17: album link in album field, get_info denied', function () use ( $A, $H, $SLUG ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_album( $A ) );
	t_queue( 404 );
	$r = R::check( 'album', "$H/s/$SLUG" );
	t_check( 'not found from get_info', 'tainacan_immich_not_found', $r['code'] );
	t_check( 'not found text', 'No Immich album has this ID, or the API key cannot see it.', $r['message'] );
	t_check( 'id kept on info failure', $A, $r['id'] );
	t_check( 'info call uses key', 'fake-key-abc123', $GLOBALS['test_http_log'][1][1]['headers']['x-api-key'] );
	tt_leak( 'edge 17 output', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $SLUG ) );

	t_reset();
	t_configure();
	t_queue( 200, tt_album( $A ) );
	t_queue( 403 );
	$r = R::check( 'album', "$H/s/$SLUG" );
	t_check( 'forbidden from get_info', 'tainacan_immich_forbidden', $r['code'] );
	t_check( 'forbidden text names permission', 'The API key does not have the permission album.read. Ask the admin to add it in Immich.', $r['message'] );
} );

// ---- Edge 18: one photo in asset field, trash check ----

t_run( 'edge 18: one photo, trash check applies', function () use ( $A, $H, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_ind( array( $A ) ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'isTrashed' => true ) ) );
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( 'trashed code', 'tainacan_immich_trashed', $r['code'] );
	t_check( 'trashed not ok', false, $r['ok'] );

	t_reset();
	t_configure();
	t_queue( 200, tt_ind( array( $A ) ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'isTrashed' => false, 'originalFileName' => 'b.jpg' ) ) );
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( 'not trashed ok', true, $r['ok'] );
} );

// ---- Edge 19: wrong kind, no get_info ----

t_run( 'edge 19: wrong kind, no get_info', function () use ( $A, $H, $SLUG, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_ind( array( $A ) ) );
	$r = R::check( 'album', "$H/s/$SLUG" );
	t_check( 'photo link in album field', 'tainacan_immich_share_wrong_kind', $r['code'] );
	t_check( 'photo text', 'This share link has single photos. Use a photo field.', $r['message'] );
	t_check( 'calls 1', 1, tt_http_count() );

	t_queue( 200, tt_album( $A ) );
	$r = R::check( 'asset', "$H/s/$SLUG" );
	t_check( 'album link in asset field', 'tainacan_immich_share_wrong_kind', $r['code'] );
	t_check( 'album text', 'This share link is for an album. Use an album field.', $r['message'] );
	t_check( 'calls 2', 2, tt_http_count() );
	t_check( 'id empty', '', $r['id'] );
	tt_leak( 'wrong kind', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $SLUG ) );

	t_queue( 200, tt_album( $A ) );
	$r = R::check( 'person', "$H/share/$KEY" );
	t_check( 'person kind share no http', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'calls 2 still', 2, tt_http_count() );
} );

// ---- Edge 20: two or more photos ----

t_run( 'edge 20: two or more photos', function () use ( $A, $B, $H, $SLUG ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_ind( array( $A, $B ) ) );
	$r = R::check( 'asset', "$H/s/$SLUG" );
	t_check( 'two photos code', 'tainacan_immich_share_too_many', $r['code'] );
	t_check( 'two photos text', 'This share link has 2 photos. Each value holds one photo. Paste a link to one photo for each value.', $r['message'] );
	t_check( 'no get_info', 1, tt_http_count() );
	t_check( 'id empty', '', $r['id'] );

	t_reset();
	t_configure();
	$c = '22222222-2222-2222-2222-222222222222';
	t_queue( 200, tt_ind( array( $A, $B, $c ) ) );
	$r = R::check( 'asset', "$H/s/$SLUG" );
	t_check( 'three photos count', 'This share link has 3 photos. Each value holds one photo. Paste a link to one photo for each value.', $r['message'] );

	t_reset();
	t_configure();
	t_queue( 200, tt_ind( array( $A, strtoupper( $A ), $B, $B ) ) );
	$r = C::resolve_share( "$H/s/$SLUG", 'asset' );
	t_check( 'two distinct after dedup is too many', 'tainacan_immich_share_too_many', tt_code( $r ) );
	t_check( 'count is 2 not 4', 'This share link has 2 photos. Each value holds one photo. Paste a link to one photo for each value.', $r->get_error_message() );

	t_reset();
	t_configure();
	t_queue( 200, tt_ind( array( $A, strtoupper( $A ) ) ) );
	t_check( 'duplicates collapse to one', array( $A ), C::resolve_share( "$H/s/$SLUG", 'asset' ) );
} );

// ---- Edge 21: HTTP errors and transport ----

t_run( 'edge 21: unusable codes', function () use ( $SLUG, $H, $KEY ) {
	t_reset();
	t_configure();
	foreach ( array( 401, 403, 404, 400 ) as $code ) {
		t_queue( $code );
		$r = R::check( 'asset', "$H/s/$SLUG" );
		t_check( "$code share unusable", 'tainacan_immich_share_unusable', $r['code'] );
		t_check( "$code text", 'This share link cannot be used. It is removed, expired or protected by a password.', $r['message'] );
		tt_leak( "$code", json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $SLUG ) );
	}
	t_queue( 500 );
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( '500 passes through', 'tainacan_immich_server_error', $r['code'] );
	t_check( '500 text', 'The Immich server answered with an error (HTTP 500).', $r['message'] );
	tt_leak( '500', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $KEY ) );

	t_queue_error();
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( 'transport passes through', 'tainacan_immich_unreachable', $r['code'] );
	t_check( 'transport text', 'The Immich server did not answer. Try again later.', $r['message'] );
	tt_leak( 'transport', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $KEY ) );
} );

t_run( 'edge 21: request sends no key and no redirects', function () use ( $SLUG, $H ) {
	t_reset();
	t_configure();
	t_queue( 401 );
	C::get_shared_link( 'slug', $SLUG );
	$args = $GLOBALS['test_http_log'][0][1];
	t_check( 'no x-api-key', false, array_key_exists( 'x-api-key', $args['headers'] ) );
	t_check( 'redirection 0', 0, $args['redirection'] );
	t_check( 'timeout 10', 10, $args['timeout'] );
	t_check( 'limit 5 MB', 5 * MB_IN_BYTES, $args['limit_response_size'] );
	t_check( 'accept json', 'application/json', $args['headers']['Accept'] );
} );

// ---- Edge 22: password ----

t_run( 'edge 22: password', function () use ( $A, $SLUG ) {
	t_reset();
	t_configure();
	$pw = 'hunter2pw';
	$pwcases = array(
		'password string'  => array( array( 'type' => 'ALBUM', 'password' => $pw, 'album' => array( 'id' => $A ) ), 'tainacan_immich_share_password' ),
		'whitespace pw'    => array( array( 'type' => 'ALBUM', 'password' => ' ', 'album' => array( 'id' => $A ) ), 'tainacan_immich_share_password' ),
		'zero string pw'   => array( array( 'type' => 'ALBUM', 'password' => '0', 'album' => array( 'id' => $A ) ), 'tainacan_immich_share_password' ),
		'password null'    => array( array( 'type' => 'ALBUM', 'password' => null, 'album' => array( 'id' => $A ) ), 'ok' ),
		'password absent'  => array( array( 'type' => 'ALBUM', 'album' => array( 'id' => $A ) ), 'ok' ),
		'password empty'   => array( array( 'type' => 'ALBUM', 'password' => '', 'album' => array( 'id' => $A ) ), 'ok' ),
		'password false'   => array( array( 'type' => 'ALBUM', 'password' => false, 'album' => array( 'id' => $A ) ), 'ok' ),
		'password int 0'   => array( array( 'type' => 'ALBUM', 'password' => 0, 'album' => array( 'id' => $A ) ), 'ok' ),
	);
	foreach ( $pwcases as $label => $c ) {
		t_queue( 200, json_encode( $c[0] ) );
		$r = C::get_shared_link( 'slug', $SLUG );
		t_check( $label, $c[1], tt_code( $r ) );
		if ( is_wp_error( $r ) ) {
			tt_leak( $label, $r->get_error_message(), array( $SLUG, $pw ) );
		}
	}
	t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'password' => $pw, 'album' => array( 'id' => $A ) ) ) );
	$r = C::get_shared_link( 'slug', $SLUG );
	t_check( 'password text', 'This share link is protected by a password. Use a share link without a password.', $r->get_error_message() );
	t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'password' => $pw, 'album' => array( 'id' => $A ) ) ) );
	$r = R::check( 'album', "https://immich.example.org/s/$SLUG" );
	t_check( 'check password code', 'tainacan_immich_share_password', $r['code'] );
	tt_leak( 'check password', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $SLUG, $pw ) );
} );

// ---- Edge 23: bad body ----

t_run( 'edge 23: body shape', function () use ( $A, $SLUG ) {
	t_reset();
	t_configure();
	$cases = array(
		'malformed'         => array( '{"type":', 'tainacan_immich_bad_response' ),
		'json null'         => array( 'null', 'tainacan_immich_bad_response' ),
		'json true'         => array( 'true', 'tainacan_immich_bad_response' ),
		'json string'       => array( '"ALBUM"', 'tainacan_immich_bad_response' ),
		'empty array'       => array( '[]', 'tainacan_immich_bad_response' ),
		'missing type'      => array( json_encode( array( 'album' => array( 'id' => $A ) ) ), 'tainacan_immich_bad_response' ),
		'type null'         => array( json_encode( array( 'type' => null ) ), 'tainacan_immich_bad_response' ),
		'type array'        => array( json_encode( array( 'type' => array( 'ALBUM' ) ) ), 'tainacan_immich_bad_response' ),
		'type bool'         => array( json_encode( array( 'type' => true ) ), 'tainacan_immich_bad_response' ),
		'type empty string' => array( json_encode( array( 'type' => '' ) ), 'tainacan_immich_share_unknown' ),
		'type FOO'          => array( json_encode( array( 'type' => 'FOO' ) ), 'tainacan_immich_share_unknown' ),
		'type Album'        => array( json_encode( array( 'type' => 'Album' ) ), 'tainacan_immich_share_unknown' ),
	);
	foreach ( $cases as $label => $c ) {
		t_queue( 200, $c[0] );
		t_check( $label, $c[1], tt_code( C::get_shared_link( 'slug', $SLUG ) ) );
	}
	t_queue( 200, json_encode( array( 'type' => 'FOO' ) ) );
	$r = C::get_shared_link( 'slug', $SLUG );
	t_check( 'unknown type text', 'The Immich server sent a kind of share link that the plugin does not know.', $r->get_error_message() );
} );

// ---- Edge 24: ALBUM and INDIVIDUAL body fields ----

t_run( 'edge 24: ALBUM and INDIVIDUAL fields', function () use ( $A, $B, $SLUG ) {
	t_reset();
	t_configure();
	$bad_album = array(
		'album missing'    => array( 'type' => 'ALBUM' ),
		'album null'       => array( 'type' => 'ALBUM', 'album' => null ),
		'album id missing' => array( 'type' => 'ALBUM', 'album' => array( 'albumName' => 'x' ) ),
		'album id int'     => array( 'type' => 'ALBUM', 'album' => array( 'id' => 5 ) ),
		'album id short'   => array( 'type' => 'ALBUM', 'album' => array( 'id' => 'abc' ) ),
		'album id no dash' => array( 'type' => 'ALBUM', 'album' => array( 'id' => str_replace( '-', '', $A ) ) ),
		'album id array'   => array( 'type' => 'ALBUM', 'album' => array( 'id' => array( $A ) ) ),
	);
	foreach ( $bad_album as $label => $body ) {
		t_queue( 200, json_encode( $body ) );
		t_check( $label, 'tainacan_immich_bad_response', tt_code( C::get_shared_link( 'slug', $SLUG ) ) );
	}
	$bad_ind = array(
		'assets missing'     => array( 'type' => 'INDIVIDUAL' ),
		'assets null'        => array( 'type' => 'INDIVIDUAL', 'assets' => null ),
		'assets string'      => array( 'type' => 'INDIVIDUAL', 'assets' => 'x' ),
		'asset null item'    => array( 'type' => 'INDIVIDUAL', 'assets' => array( null ) ),
		'asset no id'        => array( 'type' => 'INDIVIDUAL', 'assets' => array( array( 'x' => $A ) ) ),
		'asset id not uuid'  => array( 'type' => 'INDIVIDUAL', 'assets' => array( array( 'id' => 'nope' ) ) ),
		'one good one bad'   => array( 'type' => 'INDIVIDUAL', 'assets' => array( array( 'id' => $A ), array( 'id' => 'nope' ) ) ),
		'asset id int'       => array( 'type' => 'INDIVIDUAL', 'assets' => array( array( 'id' => 1 ) ) ),
	);
	foreach ( $bad_ind as $label => $body ) {
		t_queue( 200, json_encode( $body ) );
		t_check( $label, 'tainacan_immich_bad_response', tt_code( C::get_shared_link( 'slug', $SLUG ) ) );
	}
	t_queue( 200, json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array() ) ) );
	t_check( 'assets empty share_empty', 'tainacan_immich_share_empty', tt_code( C::get_shared_link( 'slug', $SLUG ) ) );
	t_queue( 200, json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array() ) ) );
	$r = C::get_shared_link( 'slug', $SLUG );
	t_check( 'empty text', 'This share link has no photos.', $r->get_error_message() );

	t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'album' => array( 'id' => strtoupper( $A ) ) ) ) );
	$r = C::get_shared_link( 'slug', $SLUG );
	t_check( 'album id uppercase lowercased', array( 'type' => 'album', 'ids' => array( $A ) ), $r );
} );

// ---- Edge 25: content type ----

t_run( 'edge 25: content type', function () use ( $A, $SLUG ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_album( $A ), array( 'content-type' => 'text/html; charset=utf-8' ) );
	t_check( 'html content type, json body ok', 'album', C::get_shared_link( 'slug', $SLUG )['type'] );
	t_queue( 200, 'not json', array( 'content-type' => 'application/json' ) );
	t_check( 'json content type, bad body', 'tainacan_immich_bad_response', tt_code( C::get_shared_link( 'slug', $SLUG ) ) );
	t_queue( 200, tt_album( $A ) );
	t_check( 'no content type header ok', 'album', C::get_shared_link( 'slug', $SLUG )['type'] );
} );

// ---- Edge 26: save with a raw share link ----

t_run( 'edge 26: save with a share link, Immich down', function () use ( $A, $H, $SLUG, $KEY ) {
	t_reset();
	t_check( 'option unset', false, C::is_configured() );
	foreach ( array( 'asset', 'album', 'person' ) as $kind ) {
		foreach ( array( "$H/s/$SLUG", "$H/share/$KEY" ) as $u ) {
			$t                       = new L();
			$t->options['link_kind'] = $kind;
			t_check( "$kind $u refused", false, $t->validate( new E( $u ) ) );
			t_check( "$kind $u one error", 1, count( $t->errors ) );
			t_check( "$kind $u text", 'A share link cannot be saved. Wait until the field shows the photo or album, then save again.', $t->errors[0] );
			tt_leak( "$kind $u", $t->errors[0], array( $SLUG, $KEY ) );
		}
	}
	t_check( 'no http with Immich down', 0, tt_http_count() );

	t_configure();
	t_reset();
	t_configure();
	$t = new L();
	t_check( 'list with share refused', false, $t->validate( new E( array( $A, "$H/s/$SLUG" ), true ) ) );
	t_check( 'list one error', 1, count( $t->errors ) );
	$t = new L();
	t_check( 'list share first refused', false, $t->validate( new E( array( "$H/share/$KEY", $A ), true ) ) );
	t_check( 'list share first no http', 0, tt_http_count() );
} );

// ---- Edge 27: share with photos suffix, and existing URLs unchanged ----

t_run( 'edge 27: share with photos suffix and existing URLs', function () use ( $A, $B, $H, $KEY ) {
	t_reset();
	t_configure();
	$u = "$H/share/$KEY/photos/$A";
	$r = R::check( 'asset', $u );
	t_check( 'check share photos bad_input', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'check share photos id empty', '', $r['id'] );
	$t = new L();
	t_check( 'validate share photos refused', false, $t->validate( new E( $u ) ) );
	t_check( 'no http for share photos', 0, tt_http_count() );

	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'p.jpg' ) ) );
	$r = R::check( 'asset', "$H/photos/$A" );
	t_check( 'plain photos ok', true, $r['ok'] );
	t_check( 'plain photos id', $A, $r['id'] );
	t_check( 'plain photos url', "https://immich.example.org/api/assets/$A", $GLOBALS['test_http_log'][0][0] );

	t_queue( 200, json_encode( array( 'id' => $A, 'albumName' => 'x' ) ) );
	$r = R::check( 'album', "$H/albums/$A" );
	t_check( 'plain albums ok', $A, $r['id'] );

	t_queue( 200, json_encode( array( 'id' => $A, 'name' => 'x' ) ) );
	$r = R::check( 'person', "$H/people/$A" );
	t_check( 'plain people ok', $A, $r['id'] );

	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	$r = R::check( 'asset', $A );
	t_check( 'bare uuid ok', $A, $r['id'] );

	t_queue( 200, json_encode( array( 'id' => $B ) ) );
	t_check( 'albums photos pair id', $B, C::parse_id( "$H/albums/$A/photos/$B", 'asset' ) );

	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	t_check( 'validate photos url', true, ( new L() )->validate( new E( "$H/photos/$A" ) ) );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	$t = new L();
	$t->options['link_kind'] = 'album';
	t_check( 'validate albums url', true, $t->validate( new E( "$H/albums/$A" ) ) );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	$t = new L();
	$t->options['link_kind'] = 'person';
	t_check( 'validate people url', true, $t->validate( new E( "$H/people/$A" ) ) );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	t_check( 'validate bare uuid', true, ( new L() )->validate( new E( $A ) ) );
} );

// ---- Edge 28: looks_like_share must not match plain Immich URLs ----

t_run( 'edge 28: looks_like_share and plain URLs', function () use ( $A, $B, $H ) {
	t_reset();
	t_configure();
	$plain = array(
		"$H/albums/$A",
		"$H/photos/$A",
		"$H/people/$A",
		"$H/albums/$A/photos/$B",
		"$H/photos/$A?s/x",
		"$H/photos/$A#/share/x",
		$A,
	);
	foreach ( $plain as $u ) {
		t_check( "not share $u", false, C::looks_like_share( $u ) );
	}
	t_check( 'shares', true, C::looks_like_share( "$H/s/abc" ) );
	t_check( 'query only match not share', false, C::looks_like_share( "$H/photos/$A?x=/s/abc" ) );
} );

// ---- Edge 29: no new option, cache, transient, log or setting ----

t_run( 'edge 29: no storage or logging added', function () {
	$files = array(
		__DIR__ . '/../tainacan-immich/class-tainacan-immich-client.php',
		__DIR__ . '/../tainacan-immich/class-tainacan-immich-rest.php',
		__DIR__ . '/../tainacan-immich/metadata-types/class-tainacan-immich-link.php',
	);
	$words = array( 'set_transient', 'get_transient', 'update_option', 'add_option', 'delete_option', 'wp_cache_', 'error_log', 'set_option', 'update_post_meta', 'update_user_meta', 'file_put_contents', 'wp_schedule' );
	foreach ( $files as $f ) {
		$src = (string) file_get_contents( $f );
		foreach ( $words as $w ) {
			t_check( basename( $f ) . " no $w", false, false !== strpos( $src, $w ) );
		}
	}
} );

// ---- Acceptance checks ----

t_run( 'acceptance: validate with share sets one error, no http', function () use ( $H, $SLUG ) {
	t_reset();
	t_configure();
	$t = new L();
	t_check( 'false', false, $t->validate( new E( "$H/s/$SLUG" ) ) );
	t_check( 'one error', 1, count( $t->errors ) );
	t_check( 'no http', 0, tt_http_count() );
} );

t_run( 'acceptance: check output has no slug or key on error paths', function () use ( $H, $SLUG, $KEY ) {
	t_reset();
	t_configure();
	$inputs = array(
		array( 'album', "$H/s/$SLUG" ),
		array( 'asset', "$H/share/$KEY" ),
		array( 'asset', "$H/share/$KEY/photos/11111111-2222-3333-4444-555555555555" ),
	);
	$codes = array( 401, 403, 404, 500, 'transport', 'password', 'bad', 'unknown', 'empty', 'many', 'kind' );
	foreach ( $inputs as $in ) {
		foreach ( $codes as $c ) {
			t_reset();
			t_configure();
			if ( is_int( $c ) ) {
				t_queue( $c );
			} elseif ( 'transport' === $c ) {
				t_queue_error();
			} elseif ( 'password' === $c ) {
				t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'password' => 'pw', 'album' => array( 'id' => '11111111-2222-3333-4444-555555555555' ) ) ) );
			} elseif ( 'bad' === $c ) {
				t_queue( 200, '{' );
			} elseif ( 'unknown' === $c ) {
				t_queue( 200, json_encode( array( 'type' => 'FOO' ) ) );
			} elseif ( 'empty' === $c ) {
				t_queue( 200, json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array() ) ) );
			} elseif ( 'many' === $c ) {
				t_queue( 200, tt_ind( array( '11111111-2222-3333-4444-555555555555', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ) ) );
			} else {
				t_queue( 200, tt_album( '11111111-2222-3333-4444-555555555555' ) );
				t_queue( 200, json_encode( array( 'id' => '11111111-2222-3333-4444-555555555555', 'albumName' => 'x' ) ) );
			}
			$r = R::check( $in[0], $in[1] );
			$text = json_encode( $r, JSON_UNESCAPED_SLASHES );
			tt_leak( "check {$in[0]} $c", $text, array( $SLUG, $KEY, 'pw', $in[1], 'aaaaaaaa-bbbb' ) );
		}
	}
} );

t_run( 'acceptance: success output has lowercase id and no slug or key', function () use ( $H, $SLUG, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, tt_album( 'ABCDEF12-2222-3333-4444-555555555555' ) );
	t_queue( 200, json_encode( array( 'id' => 'abcdef12-2222-3333-4444-555555555555', 'albumName' => 'A' ) ) );
	$r = R::check( 'album', "$H/s/$SLUG" );
	t_check( 'lowercase id', 'abcdef12-2222-3333-4444-555555555555', $r['id'] );
	tt_leak( 'success', json_encode( $r, JSON_UNESCAPED_SLASHES ), array( $SLUG, $KEY ) );
} );

// ---- Probes: inputs that the spec's regex rules must refuse ----
// The spec lists bad characters as refused (edge 14), and an album id that is not a UUID as
// bad_response (edge 24). The regex constants use `$`, which also matches before a final newline.

t_run( 'probe: newline at end of slug value is refused', function () use ( $H ) {
	t_reset();
	t_configure();
	t_queue( 404 );
	$r = C::get_shared_link( 'slug', "abc\n" );
	t_check( 'slug with newline refused by get_shared_link', 'tainacan_immich_bad_input', tt_code( $r ) );
	t_check( 'no http for newline slug', 0, tt_http_count() );
} );

t_run( 'probe: control characters inside a share URL segment are refused by parse_share', function () use ( $H ) {
	t_reset();
	t_configure();
	t_check( 'parse_share key with newline', array(), C::parse_share( "$H/share/ab\n/" ) );
	t_check( 'parse_share slug with newline', array(), C::parse_share( "$H/s/ab\n/" ) );
	t_check( 'parse_share slug with tab', array(), C::parse_share( "$H/s/a\tb" ) );
	t_check( 'parse_share key with control 0x01', array(), C::parse_share( "$H/share/a\x01b" ) );
	t_check( 'parse_share slug with DEL 0x7f', array(), C::parse_share( "$H/s/a\x7fb" ) );
	t_check( 'no http for control chars', 0, tt_http_count() );
} );

t_run( 'probe: album id with newline is bad_response', function () use ( $A, $SLUG ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'album' => array( 'id' => "$A\n" ) ) ) );
	t_check( 'album id with newline refused', 'tainacan_immich_bad_response', tt_code( C::get_shared_link( 'slug', $SLUG ) ) );
} );

t_run( 'probe: asset id with newline is bad_response', function () use ( $A, $SLUG ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array( array( 'id' => "$A\n" ) ) ) ) );
	t_check( 'asset id with newline refused', 'tainacan_immich_bad_response', tt_code( C::get_shared_link( 'slug', $SLUG ) ) );
} );

t_summary();
