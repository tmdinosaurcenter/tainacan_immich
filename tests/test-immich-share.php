<?php
/**
 * Tests for share links. Plain PHP, stubs only, no network.
 * Run: php tests/test-immich-share.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;
use Tainacan\Entities\Item_Metadata_Entity as E;
use Tainacan\Metadata_Types\Immich_Link as L;

$A = '11111111-2222-3333-4444-555555555555';
$B = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$H = 'https://immich.example.org';

function sh_album( $id ) {
	return json_encode( array( 'type' => 'ALBUM', 'password' => null, 'album' => array( 'id' => $id, 'albumName' => 'X' ) ) );
}
function sh_ind( $ids ) {
	$a = array();
	foreach ( $ids as $i ) {
		$a[] = array( 'id' => $i );
	}
	return json_encode( array( 'type' => 'INDIVIDUAL', 'password' => null, 'assets' => $a ) );
}
function sh_leak( $label, $text, $secrets ) {
	foreach ( array_merge( $secrets, array( 'fake-key-abc123', 'immich.example.org' ) ) as $s ) {
		t_not_contains( "$label no leak of $s", $s, $text );
	}
}

t_run( 'looks_like_share', function () use ( $A, $H ) {
	t_check( 's', true, C::looks_like_share( "$H/s/abc" ) );
	t_check( 'share', true, C::looks_like_share( "$H/share/abc" ) );
	t_check( 'share then photos', true, C::looks_like_share( "$H/share/abc/photos/$A" ) );
	t_check( 'trim and case scheme', true, C::looks_like_share( '  HTTPS://immich.example.org/s/abc ' ) );
	t_check( 'albums', false, C::looks_like_share( "$H/albums/$A" ) );
	t_check( 'photos', false, C::looks_like_share( "$H/photos/$A" ) );
	t_check( 'people', false, C::looks_like_share( "$H/people/$A" ) );
	t_check( 'albums photos', false, C::looks_like_share( "$H/albums/$A/photos/$A" ) );
	t_check( 'bare uuid', false, C::looks_like_share( $A ) );
	t_check( 'nothing after s', false, C::looks_like_share( "$H/s/" ) );
	t_check( 'array', false, C::looks_like_share( array() ) );
	t_check( 'null', false, C::looks_like_share( null ) );
	t_check( 'int', false, C::looks_like_share( 5 ) );
	t_check( 'ftp', false, C::looks_like_share( 'ftp://immich.example.org/s/abc' ) );
} );

t_run( 'parse_share ok', function () use ( $H ) {
	t_reset();
	t_configure();
	t_check( 'slug', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc" ) );
	t_check( 'slug dots', array( 'param' => 'slug', 'value' => 'ta2020.1' ), C::parse_share( "$H/s/ta2020.1" ) );
	t_check( 'key', array( 'param' => 'key', 'value' => 'AbC-12_x' ), C::parse_share( "$H/share/AbC-12_x" ) );
	t_check( 'trailing slash', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc/" ) );
	t_check( 'query fragment', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc?x=1#y" ) );
	t_check( 'http', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( 'http://immich.example.org/s/abc' ) );
	t_check( 'host case', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( 'https://IMMICH.example.org/s/abc' ) );
	t_check( 'padded', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( " $H/s/abc " ) );
	t_check( 'max length', 128, strlen( C::parse_share( "$H/s/" . str_repeat( 'a', 128 ) )['value'] ) );
	$GLOBALS['test_options']['tainacan_immich_url'] = 'https://immich.example.org/immich/';
	t_check( 'prefix ok', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/immich/s/abc" ) );
	t_check( 'prefix missing', array(), C::parse_share( "$H/s/abc" ) );
	$GLOBALS['test_options']['tainacan_immich_url'] = 'https://immich.example.org:8443';
	t_check( 'port ok', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H:8443/s/abc" ) );
	t_check( 'port missing', array(), C::parse_share( "$H/s/abc" ) );
} );

t_run( 'parse_share refused', function () use ( $H ) {
	t_reset();
	t_configure();
	$bad = array(
		'wrong host'     => 'https://other.example.org/s/abc',
		'other port'     => "$H:8443/s/abc",
		'userinfo'       => 'https://u:p@immich.example.org/s/abc',
		'user only'      => 'https://u@immich.example.org/s/abc',
		'two trailing'   => "$H/s/abc//",
		'dotdot'         => "$H/s/../x",
		'dotdot only'    => "$H/s/..",
		'dot'            => "$H/s/.",
		'leading dot'    => "$H/s/.abc",
		'trailing dot'   => "$H/s/abc.",
		'enc slash'      => "$H/s/a%2Fb",
		'enc dots key'   => "$H/share/a%2e%2e",
		'backslash'      => "$H/s/a\\b",
		'space'          => "$H/s/a b",
		'plus'           => "$H/share/a+b",
		'equals'         => "$H/share/a=b",
		'unicode'        => "$H/s/caf\xc3\xa9",
		'slug too long'  => "$H/s/" . str_repeat( 'a', 129 ),
		'key too long'   => "$H/share/" . str_repeat( 'a', 129 ),
		'dot in key'     => "$H/share/a.b",
		'empty s'        => "$H/s/",
		'empty share'    => "$H/share/",
		'three segments' => "$H/share/abc/photos/abc",
		'extra segment'  => "$H/s/abc/def",
		'other path'     => "$H/photos/abc",
		'ftp'            => 'ftp://immich.example.org/s/abc',
		'no scheme'      => 'immich.example.org/s/abc',
		'too long'       => "$H/s/abc?" . str_repeat( 'a', 2100 ),
		'empty'          => '',
	);
	foreach ( $bad as $label => $in ) {
		t_check( "refused: $label", array(), C::parse_share( $in ) );
	}
	foreach ( array( null, 5, array(), true ) as $i => $in ) {
		t_check( "non-string $i", array(), C::parse_share( $in ) );
	}
	$GLOBALS['test_options']['tainacan_immich_url'] = '';
	t_check( 'empty option', array(), C::parse_share( "$H/s/abc" ) );
	t_check( 'no http', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'get_shared_link request', function () use ( $A, $B ) {
	t_reset();
	t_configure();
	t_queue( 200, sh_album( $A ) );
	$r = C::get_shared_link( 'slug', 'ta2020.1' );
	t_check( 'album', array( 'type' => 'album', 'ids' => array( $A ) ), $r );
	$log = $GLOBALS['test_http_log'][0];
	t_check( 'url slug', 'https://immich.example.org/api/shared-links/me?slug=ta2020.1', $log[0] );
	t_check( 'no api key header', false, isset( $log[1]['headers']['x-api-key'] ) );
	t_check( 'accept json', 'application/json', $log[1]['headers']['Accept'] );
	t_check( 'redirection', 0, $log[1]['redirection'] );
	t_check( 'timeout', 10, $log[1]['timeout'] );
	t_check( 'size limit', 5 * MB_IN_BYTES, $log[1]['limit_response_size'] );
	t_check( 'one call', 1, count( $GLOBALS['test_http_log'] ) );
	t_not_contains( 'no api key in args', 'fake-key-abc123', json_encode( $log ) );

	t_queue( 200, sh_ind( array( strtoupper( $A ) ) ) );
	$r = C::get_shared_link( 'key', 'AbC-12_x' );
	t_check( 'url key', 'https://immich.example.org/api/shared-links/me?key=AbC-12_x', $GLOBALS['test_http_log'][1][0] );
	t_check( 'asset lowercase', array( 'type' => 'asset', 'ids' => array( $A ) ), $r );

	t_queue( 200, sh_ind( array( $A, $B, strtoupper( $A ) ) ) );
	t_check( 'dedup keep order', array( 'type' => 'asset', 'ids' => array( $A, $B ) ), C::get_shared_link( 'key', 'k' ) );

	$n = count( $GLOBALS['test_http_log'] );
	$cases = array(
		array( 'x', 'abc' ),
		array( 'key', 'a b' ),
		array( 'key', 'a.b' ),
		array( 'slug', '..' ),
		array( 'slug', 'a/b' ),
		array( 'key', '' ),
		array( 'key', array() ),
		array( null, 'a' ),
	);
	foreach ( $cases as $i => $args ) {
		$r = C::get_shared_link( $args[0], $args[1] );
		t_check( "bad args $i", 'tainacan_immich_bad_input', $r->get_error_code() );
	}
	t_check( 'bad args no http', $n, count( $GLOBALS['test_http_log'] ) );
	foreach ( $GLOBALS['test_http_log'] as $i => $entry ) {
		t_not_contains( "call $i is not login", 'login', $entry[0] );
		t_check( "call $i no method override", false, isset( $entry[1]['method'] ) );
		t_check( "call $i no body", false, isset( $entry[1]['body'] ) );
	}
} );

t_run( 'get_shared_link errors', function () use ( $A ) {
	t_reset();
	t_configure();
	$secret = array( 'SeCrEtSlug' );
	foreach ( array( 400, 401, 403, 404 ) as $code ) {
		t_queue( $code );
		$r = C::get_shared_link( 'slug', 'SeCrEtSlug' );
		t_check( "$code unusable", 'tainacan_immich_share_unusable', $r->get_error_code() );
		t_check( "$code text", 'This share link cannot be used. It is removed, expired or protected by a password.', $r->get_error_message() );
		sh_leak( "$code", $r->get_error_message(), $secret );
	}
	t_queue( 500 );
	$r = C::get_shared_link( 'slug', 'SeCrEtSlug' );
	t_check( '500', 'tainacan_immich_server_error', $r->get_error_code() );
	t_queue_error();
	$r = C::get_shared_link( 'slug', 'SeCrEtSlug' );
	t_check( 'transport', 'tainacan_immich_unreachable', $r->get_error_code() );
	sh_leak( 'transport', $r->get_error_message(), $secret );
	t_reset();
	$r = C::get_shared_link( 'slug', 'SeCrEtSlug' );
	t_check( 'not configured', 'tainacan_immich_not_configured', $r->get_error_code() );
	t_check( 'not configured no http', 0, count( $GLOBALS['test_http_log'] ) );

	t_configure();
	$pw    = 'hunter2pw';
	$cases = array(
		'password'            => array( json_encode( array( 'type' => 'ALBUM', 'password' => $pw, 'album' => array( 'id' => $A ) ) ), 'tainacan_immich_share_password' ),
		'password individual' => array( json_encode( array( 'type' => 'INDIVIDUAL', 'password' => $pw, 'assets' => array() ) ), 'tainacan_immich_share_password' ),
		'malformed json'      => array( '{not json', 'tainacan_immich_bad_response' ),
		'empty body'          => array( '', 'tainacan_immich_bad_response' ),
		'scalar'              => array( '5', 'tainacan_immich_bad_response' ),
		'missing type'        => array( json_encode( array( 'album' => array( 'id' => $A ) ) ), 'tainacan_immich_bad_response' ),
		'type not string'     => array( json_encode( array( 'type' => 5 ) ), 'tainacan_immich_bad_response' ),
		'unknown type'        => array( json_encode( array( 'type' => 'FOO' ) ), 'tainacan_immich_share_unknown' ),
		'lowercase type'      => array( json_encode( array( 'type' => 'album', 'album' => array( 'id' => $A ) ) ), 'tainacan_immich_share_unknown' ),
		'album missing'       => array( json_encode( array( 'type' => 'ALBUM' ) ), 'tainacan_immich_bad_response' ),
		'album not array'     => array( json_encode( array( 'type' => 'ALBUM', 'album' => 'x' ) ), 'tainacan_immich_bad_response' ),
		'album id bad'        => array( json_encode( array( 'type' => 'ALBUM', 'album' => array( 'id' => 'nope' ) ) ), 'tainacan_immich_bad_response' ),
		'album id int'        => array( json_encode( array( 'type' => 'ALBUM', 'album' => array( 'id' => 5 ) ) ), 'tainacan_immich_bad_response' ),
		'assets missing'      => array( json_encode( array( 'type' => 'INDIVIDUAL' ) ), 'tainacan_immich_bad_response' ),
		'assets not array'    => array( json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => 'x' ) ), 'tainacan_immich_bad_response' ),
		'asset no id'         => array( json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array( array( 'x' => 1 ) ) ) ), 'tainacan_immich_bad_response' ),
		'asset id bad'        => array( json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array( array( 'id' => 'zz' ) ) ) ), 'tainacan_immich_bad_response' ),
		'asset not array'     => array( json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array( 'x' ) ) ), 'tainacan_immich_bad_response' ),
		'assets empty'        => array( json_encode( array( 'type' => 'INDIVIDUAL', 'assets' => array() ) ), 'tainacan_immich_share_empty' ),
	);
	foreach ( $cases as $label => $c ) {
		t_queue( 200, $c[0] );
		$r = C::get_shared_link( 'slug', 'SeCrEtSlug' );
		t_check( $label, $c[1], is_wp_error( $r ) ? $r->get_error_code() : 'ok' );
		if ( is_wp_error( $r ) ) {
			sh_leak( $label, $r->get_error_message(), array( 'SeCrEtSlug', $pw ) );
		}
	}
	t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'album' => array( 'id' => $A ) ) ) );
	t_check( 'password absent ok', 'album', C::get_shared_link( 'slug', 'abc' )['type'] );
	t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'password' => '', 'album' => array( 'id' => $A ) ) ) );
	t_check( 'password empty string ok', 'album', C::get_shared_link( 'slug', 'abc' )['type'] );
	t_queue( 200, sh_album( $A ), array( 'content-type' => 'text/html' ) );
	t_check( 'content type ignored', 'album', C::get_shared_link( 'slug', 'abc' )['type'] );
} );

t_run( 'resolve_share kinds', function () use ( $A, $B, $H ) {
	t_reset();
	t_configure();
	t_queue( 200, sh_album( $A ) );
	t_check( 'album in album field', array( $A ), C::resolve_share( "$H/s/ta2020.1", 'album' ) );
	t_queue( 200, sh_ind( array( $A ) ) );
	t_check( 'one asset in asset field', array( $A ), C::resolve_share( "$H/share/AbC-12_x", 'asset' ) );

	t_queue( 200, sh_ind( array( $A ) ) );
	$r = C::resolve_share( "$H/s/abc", 'album' );
	t_check( 'individual in album field', 'tainacan_immich_share_wrong_kind', $r->get_error_code() );
	t_check( 'photo text', 'This share link has single photos. Use a photo field.', $r->get_error_message() );
	t_queue( 200, sh_album( $A ) );
	$r = C::resolve_share( "$H/s/abc", 'asset' );
	t_check( 'album in asset field', 'tainacan_immich_share_wrong_kind', $r->get_error_code() );
	t_check( 'album text', 'This share link is for an album. Use an album field.', $r->get_error_message() );

	t_queue( 200, sh_ind( array( $A, $B ) ) );
	$r = C::resolve_share( "$H/s/abc", 'asset' );
	t_check( 'too many', 'tainacan_immich_share_too_many', $r->get_error_code() );
	t_check( 'too many text', 'This share link has 2 photos. Each value holds one photo. Paste a link to one photo for each value.', $r->get_error_message() );
	t_queue( 200, sh_ind( array( $A, strtoupper( $A ) ) ) );
	t_check( 'dups collapse ok', array( $A ), C::resolve_share( "$H/s/abc", 'asset' ) );

	$n = count( $GLOBALS['test_http_log'] );
	$r = C::resolve_share( "$H/s/abc", 'person' );
	t_check( 'person', 'tainacan_immich_bad_input', $r->get_error_code() );
	t_check( 'person text', 'This is not an Immich person ID or URL.', $r->get_error_message() );
	$r = C::resolve_share( "$H/s/abc", 'bogus' );
	t_check( 'bad kind', 'tainacan_immich_bad_kind', $r->get_error_code() );
	$r = C::resolve_share( 'https://other.example.org/s/abc', 'album' );
	t_check( 'wrong host', 'tainacan_immich_bad_input', $r->get_error_code() );
	t_check( 'wrong host text', 'This is not a valid Immich share link for this server.', $r->get_error_message() );
	$r = C::resolve_share( "$H/s/../x", 'album' );
	t_check( 'traversal', 'tainacan_immich_bad_input', $r->get_error_code() );
	t_check( 'no http for refusals', $n, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'check route', function () use ( $A, $B, $H ) {
	$keys = array( 'ok', 'code', 'message', 'id', 'label', 'thumb', 'exif' );
	t_reset();
	t_configure();
	t_queue( 200, sh_album( $A ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'albumName' => 'Trip' ) ) );
	$r = R::check( 'album', "$H/s/ta2020.1?x=1#y" );
	t_check( 'keys', $keys, array_keys( $r ) );
	t_check( 'ok', true, $r['ok'] );
	t_check( 'id', $A, $r['id'] );
	t_check( 'label', 'Trip', $r['label'] );
	t_check( 'two calls', 2, count( $GLOBALS['test_http_log'] ) );
	t_contains( 'info url', "/api/albums/$A", $GLOBALS['test_http_log'][1][0] );
	t_check( 'info has api key', 'fake-key-abc123', $GLOBALS['test_http_log'][1][1]['headers']['x-api-key'] );
	t_not_contains( 'no slug in output', 'ta2020', json_encode( $r ) );

	t_queue( 200, sh_ind( array( strtoupper( $A ) ) ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg', 'isTrashed' => false ) ) );
	$r = R::check( 'asset', "$H/share/AbC-12_x" );
	t_check( 'asset ok', true, $r['ok'] );
	t_check( 'asset id lowercase', $A, $r['id'] );
	t_contains( 'asset thumb', '/thumbnail?', $r['thumb'] );
	t_not_contains( 'no share key in output', 'AbC-12_x', json_encode( $r ) );

	t_queue( 200, sh_ind( array( $A ) ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'isTrashed' => true ) ) );
	$r = R::check( 'asset', "$H/share/AbC-12_x" );
	t_check( 'trashed', 'tainacan_immich_trashed', $r['code'] );

	$n = count( $GLOBALS['test_http_log'] );
	t_queue( 200, sh_ind( array( $A ) ) );
	$r = R::check( 'album', "$H/s/SeCrEtSlug" );
	t_check( 'wrong kind', 'tainacan_immich_share_wrong_kind', $r['code'] );
	t_check( 'wrong kind not ok', false, $r['ok'] );
	t_check( 'wrong kind id empty', '', $r['id'] );
	t_check( 'no get_info', $n + 1, count( $GLOBALS['test_http_log'] ) );
	sh_leak( 'wrong kind', json_encode( $r ), array( 'SeCrEtSlug' ) );

	t_queue( 200, sh_ind( array( $A, $B ) ) );
	$r = R::check( 'asset', "$H/s/SeCrEtSlug" );
	t_check( 'too many', 'tainacan_immich_share_too_many', $r['code'] );
	t_check( 'too many no get_info', $n + 2, count( $GLOBALS['test_http_log'] ) );

	t_queue( 404 );
	$r = R::check( 'asset', "$H/s/SeCrEtSlug" );
	t_check( 'unusable', 'tainacan_immich_share_unusable', $r['code'] );
	sh_leak( 'unusable', json_encode( $r ), array( 'SeCrEtSlug' ) );

	$n = count( $GLOBALS['test_http_log'] ) - 1;
	$r = R::check( 'person', "$H/s/abc" );
	t_check( 'person', 'tainacan_immich_bad_input', $r['code'] );
	$r = R::check( 'asset', "$H/share/abc/photos/$A" );
	t_check( 'share plus photos', 'tainacan_immich_bad_input', $r['code'] );
	$r = R::check( 'asset', 'https://other.example.org/s/abc' );
	t_check( 'wrong host', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'refusals no http', $n + 1, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'existing parsing unchanged', function () use ( $A, $B, $H ) {
	t_reset();
	t_configure();
	t_check( 'photos', $A, C::parse_id( "$H/photos/$A", 'asset' ) );
	t_check( 'albums', $A, C::parse_id( "$H/albums/$A", 'album' ) );
	t_check( 'people', $A, C::parse_id( "$H/people/$A", 'person' ) );
	t_check( 'bare', $A, C::parse_id( strtoupper( $A ), 'asset' ) );
	t_check( 'albums photos', $B, C::parse_id( "$H/albums/$A/photos/$B", 'asset' ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg' ) ) );
	$r = R::check( 'asset', "$H/photos/$A" );
	t_check( 'check photos url', true, $r['ok'] );
	t_check( 'one call', 1, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'validate refuses share links', function () use ( $A, $H ) {
	t_reset();
	t_configure();
	$urls = array( "$H/s/SeCrEtSlug", "$H/share/AbC-12_x", "$H/share/abc/photos/$A", 'https://other.example.org/s/abc' );
	foreach ( array( 'asset', 'album', 'person' ) as $kind ) {
		foreach ( $urls as $url ) {
			$t                       = new L();
			$t->options['link_kind'] = $kind;
			t_check( "$kind refused", false, $t->validate( new E( $url ) ) );
			t_check( "$kind one error", 1, count( $t->errors ) );
			t_check( "$kind text", 'A share link cannot be saved. Wait until the field shows the photo or album, then save again.', $t->errors[0] );
			sh_leak( "$kind validate", $t->errors[0], array( 'SeCrEtSlug', 'AbC-12_x' ) );
		}
	}
	$t = new L();
	t_check( 'in a list', false, $t->validate( new E( array( $A, "$H/s/abc" ), true ) ) );
	t_check( 'no http', 0, count( $GLOBALS['test_http_log'] ) );

	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	$t = new L();
	t_check( 'uuid still ok', true, $t->validate( new E( $A ) ) );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	t_check( 'photos url still ok', true, $t->validate( new E( "$H/photos/$A" ) ) );
	t_contains( 'placeholder', 'share link', ( new L() )->preview_template );
} );

t_run( 'control characters and final newline (UUID_REGEX, SHARE_*_REGEX)', function () use ( $A, $B, $H ) {
	t_reset();
	t_configure();
	foreach ( array( "abc\n", "abc\t", "ab\x01c", "ab\x7fc" ) as $v ) {
		$r = C::get_shared_link( 'slug', $v );
		t_check( 'slug ' . bin2hex( $v ), 'tainacan_immich_bad_input', $r instanceof \WP_Error ? $r->get_error_code() : 'ok' );
		$r = C::get_shared_link( 'key', $v );
		t_check( 'key ' . bin2hex( $v ), 'tainacan_immich_bad_input', $r instanceof \WP_Error ? $r->get_error_code() : 'ok' );
	}
	t_check( 'no http for bad values', 0, count( $GLOBALS['test_http_log'] ) );
	foreach ( array( "$H/s/ab\nc", "$H/s/a\tb", "$H/share/a\x01b", "$H/s/a\x7fb", "$H/share/ab\n/" ) as $u ) {
		t_check( 'parse_share ' . bin2hex( $u ), array(), C::parse_share( $u ) );
		$r = R::check( 'album', $u );
		t_check( 'check ' . bin2hex( $u ), false, $r['ok'] );
	}
	t_check( 'check: no http for control chars', 0, count( $GLOBALS['test_http_log'] ) );
	t_queue( 200, sh_album( "$A\n" ) );
	$r = C::get_shared_link( 'slug', 'abc' );
	t_check( 'album id with newline', 'tainacan_immich_bad_response', $r instanceof \WP_Error ? $r->get_error_code() : 'ok' );
	t_queue( 200, sh_ind( array( "$A\n" ) ) );
	$r = C::get_shared_link( 'slug', 'abc' );
	t_check( 'asset id with newline', 'tainacan_immich_bad_response', $r instanceof \WP_Error ? $r->get_error_code() : 'ok' );
	// Existing callers.
	t_check( 'parse_id bare uuid', $A, C::parse_id( $A, 'album' ) );
	t_check( 'parse_id uuid with inner newline', '', C::parse_id( "11111111-2222-3333-4444-5555\n55555555", 'album' ) );
	t_check( 'parse_id albums url', $B, C::parse_id( "$H/albums/$B", 'album' ) );
	t_check( 'parse_id url uuid newline in path', '', C::parse_id( "$H/albums/$B\n/x", 'album' ) );
	$n = count( $GLOBALS['test_http_log'] );
	$r = C::get_info( 'album', "$A\n" );
	t_check( 'get_info uuid with final newline', true, $r instanceof \WP_Error );
	t_check( 'get_info newline: no http', $n, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'second round: whitespace, encoded share path, empty API key', function () use ( $A, $H ) {
	t_reset();
	t_configure();
	t_check( 'tab trim', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "\t$H/s/abc\t" ) );
	t_check( 'inner LF', array(), C::parse_share( "$H/s/a\nbc" ) );
	t_check( 'parse_id UUID + LF', $A, C::parse_id( "$A\n", 'asset' ) );
	t_check( 'parse_id inner LF', '', C::parse_id( "1111\n1111-2222-3333-4444-555555555555", 'asset' ) );
	foreach ( array( "$H/%73/abc", "$H/%73hare/abc", "$H/%73%68%61%72%65/abc", "$H/s%2Fabc", "$H/%2573/abc" ) as $i => $u ) {
		t_check( "encoded looks_like_share $i", true, C::looks_like_share( $u ) );
		$t = new L();
		t_check( "encoded validate $i", false, $t->validate( new E( $u ) ) );
		t_not_contains( "encoded no echo $i", '%73', $t->errors[0] );
		$r = R::check( 'asset', $u );
		t_check( "encoded check $i", 'tainacan_immich_bad_input', $r['code'] );
	}
	t_check( 'albums with encoded query', false, C::looks_like_share( "$H/albums/$A?x=%73" ) );
	t_check( 'no HTTP', 0, count( $GLOBALS['test_http_log'] ) );
	$GLOBALS['test_options']['tainacan_immich_api_key'] = '';
	$r = R::check( 'album', "$H/s/abc" );
	t_check( 'empty key: not_configured', 'tainacan_immich_not_configured', $r['code'] );
	t_check( 'empty key: no HTTP', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_summary();
