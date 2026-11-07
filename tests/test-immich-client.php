<?php
/**
 * Tests for \Tainacan\Immich_Client. Plain PHP, stubs only, no network.
 * Run: php tests/test-immich-client.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;

$A = '11111111-2222-3333-4444-555555555555';
$B = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

t_run( 'parse_id', function () use ( $A, $B ) {
	t_check( 'bare uuid', $A, C::parse_id( $A, 'asset' ) );
	t_check( 'uppercase and spaces', $B, C::parse_id( '  ' . strtoupper( $B ) . ' ', 'album' ) );
	t_check( 'asset url', $A, C::parse_id( "https://immich.example.org/photos/$A", 'asset' ) );
	t_check( 'asset url with query', $A, C::parse_id( "https://immich.example.org/photos/$A?x=1#f", 'asset' ) );
	t_check( 'album url', $A, C::parse_id( "https://immich.example.org/albums/$A", 'album' ) );
	t_check( 'person url', $A, C::parse_id( "http://immich.example.org/people/$A", 'person' ) );
	t_check( 'album/photos gives asset', $B, C::parse_id( "https://immich.example.org/albums/$A/photos/$B", 'asset' ) );
	t_check( 'album/photos gives album', $A, C::parse_id( "https://immich.example.org/albums/$A/photos/$B", 'album' ) );
	t_check( 'wrong kind url', '', C::parse_id( "https://immich.example.org/albums/$A", 'asset' ) );
	t_check( 'person url as asset', '', C::parse_id( "https://immich.example.org/people/$A", 'asset' ) );
	t_check( 'text', '', C::parse_id( 'hello', 'asset' ) );
	t_check( 'non-string array', '', C::parse_id( array( $A ), 'asset' ) );
	t_check( 'non-string int', '', C::parse_id( 42, 'asset' ) );
	t_check( 'non-string null', '', C::parse_id( null, 'asset' ) );
	t_check( 'too long', '', C::parse_id( str_repeat( 'a', 2049 ), 'asset' ) );
	t_check( 'unknown kind', '', C::parse_id( $A, 'video' ) );
	t_check( 'non-v4 uuid accepted', '00000000-0000-1000-8000-000000000000', C::parse_id( '00000000-0000-1000-8000-000000000000', 'asset' ) );
	t_check( 'ftp scheme', '', C::parse_id( "ftp://x.example.org/photos/$A", 'asset' ) );
} );

t_run( 'is_configured', function () {
	t_reset();
	t_check( 'empty', false, C::is_configured() );
	$GLOBALS['test_options']['tainacan_immich_url'] = 'https://immich.example.org';
	t_check( 'url only', false, C::is_configured() );
	t_configure();
	t_check( 'both', true, C::is_configured() );
} );

t_run( 'not configured: no HTTP call', function () use ( $A ) {
	t_reset();
	$r = C::get_info( 'asset', $A );
	t_check( 'code', 'tainacan_immich_not_configured', $r->get_error_code() );
	t_check( 'no call', 0, count( $GLOBALS['test_http_log'] ) );
	$r = C::get_thumbnail( 'asset', $A );
	t_check( 'thumb code', 'tainacan_immich_not_configured', $r->get_error_code() );
	t_check( 'thumb no call', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'request shape', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	$r = C::get_info( 'asset', $A );
	t_check( 'decoded id', $A, $r['id'] );
	list( $url, $args ) = $GLOBALS['test_http_log'][0];
	t_check( 'url', "https://immich.example.org/api/assets/$A", $url );
	t_check( 'key header', 'fake-key-abc123', $args['headers']['x-api-key'] );
	t_check( 'accept header', 'application/json', $args['headers']['Accept'] );
	t_check( 'redirection', 0, $args['redirection'] );
	t_check( 'timeout', 10, $args['timeout'] );
	t_check( 'size limit', 5 * MB_IN_BYTES, $args['limit_response_size'] );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	C::get_info( 'album', $A );
	t_check( 'album path', "https://immich.example.org/api/albums/$A", $GLOBALS['test_http_log'][1][0] );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	C::get_info( 'person', $A );
	t_check( 'person path', "https://immich.example.org/api/people/$A", $GLOBALS['test_http_log'][2][0] );
	t_queue( 200, 'bytes', array( 'content-type' => 'image/webp' ) );
	C::get_thumbnail( 'asset', $A );
	t_check( 'asset thumb path', "https://immich.example.org/api/assets/$A/thumbnail?size=thumbnail", $GLOBALS['test_http_log'][3][0] );
	t_queue( 200, 'bytes', array( 'content-type' => 'image/webp' ) );
	C::get_thumbnail( 'person', $A );
	t_check( 'person thumb path', "https://immich.example.org/api/people/$A/thumbnail", $GLOBALS['test_http_log'][4][0] );
} );

t_run( 'status mapping', function () use ( $A ) {
	$cases = array(
		400 => 'tainacan_immich_not_found',
		401 => 'tainacan_immich_auth',
		403 => 'tainacan_immich_forbidden',
		404 => 'tainacan_immich_not_found',
		500 => 'tainacan_immich_server_error',
		302 => 'tainacan_immich_server_error',
	);
	foreach ( $cases as $status => $code ) {
		t_reset();
		t_configure();
		t_queue( $status, 'secret body fake-key-abc123' );
		$r = C::get_info( 'asset', $A );
		t_check( "status $status", $code, $r->get_error_code() );
		t_not_contains( "status $status message has no key", 'fake-key-abc123', $r->get_error_message() );
		t_not_contains( "status $status message has no host", 'immich.example.org', $r->get_error_message() );
	}
	t_reset();
	t_configure();
	t_queue_error();
	$r = C::get_info( 'asset', $A );
	t_check( 'transport error', 'tainacan_immich_unreachable', $r->get_error_code() );
	t_not_contains( 'transport no key', 'fake-key-abc123', $r->get_error_message() );
	t_not_contains( 'transport no host', 'immich.example.org', $r->get_error_message() );
	t_queue( 500 );
	t_contains( '500 message has code', '500', C::get_info( 'asset', $A )->get_error_message() );
} );

t_run( '403 permission names', function () use ( $A ) {
	$map = array(
		'asset'  => 'asset.read',
		'album'  => 'album.read',
		'person' => 'person.read',
	);
	foreach ( $map as $kind => $perm ) {
		t_reset();
		t_configure();
		t_queue( 403 );
		t_contains( "info $kind", $perm, C::get_info( $kind, $A )->get_error_message() );
	}
	t_reset();
	t_configure();
	t_queue( 403 );
	t_contains( 'asset thumbnail', 'asset.view', C::get_thumbnail( 'asset', $A )->get_error_message() );
	t_queue( 403 );
	t_contains( 'person thumbnail', 'person.read', C::get_thumbnail( 'person', $A )->get_error_message() );
} );

t_run( 'bad responses', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, 'not json' );
	t_check( 'not json', 'tainacan_immich_bad_response', C::get_info( 'asset', $A )->get_error_code() );
	t_queue( 200, json_encode( array( 'name' => 'x' ) ) );
	t_check( 'no id', 'tainacan_immich_bad_response', C::get_info( 'asset', $A )->get_error_code() );
	t_queue( 200, json_encode( array( 'id' => 5 ) ) );
	t_check( 'id not string', 'tainacan_immich_bad_response', C::get_info( 'asset', $A )->get_error_code() );
	t_queue( 200, '<html>', array( 'content-type' => 'text/html' ) );
	t_check( 'thumb not image', 'tainacan_immich_bad_response', C::get_thumbnail( 'asset', $A )->get_error_code() );
	t_check( 'album thumb', 'tainacan_immich_bad_kind', C::get_thumbnail( 'album', $A )->get_error_code() );
	$n = count( $GLOBALS['test_http_log'] );
	t_check( 'bad id', 'tainacan_immich_not_found', C::get_info( 'asset', '../etc' )->get_error_code() );
	t_check( 'bad id made no call', $n, count( $GLOBALS['test_http_log'] ) );
} );

t_summary();
