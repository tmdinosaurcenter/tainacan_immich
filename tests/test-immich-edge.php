<?php
/**
 * Edge-case tests from the spec (edge cases 1-20). Plain PHP, stubs only, no network.
 * Run: php tests/test-immich-edge.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Entities\Item_Metadata_Entity as E;
use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;
use Tainacan\Metadata_Types\Immich_Link as L;

$A = '11111111-2222-3333-4444-555555555555';
$B = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$KEY  = 'fake-key-abc123';
$HOST = 'immich.example.org';

function e_type( $kind = 'asset' ) {
	$t = new L();
	if ( null !== $kind ) {
		$t->options['link_kind'] = $kind;
	}
	return $t;
}
function e_info( $id, $extra = array() ) {
	return json_encode( array_merge( array( 'id' => $id ), $extra ) );
}

// Edge 1: empty value or empty array is valid, no HTTP call, nothing rendered.
t_run( 'edge 1: empty values', function () {
	t_reset();
	t_configure();
	t_check( 'null valid', true, e_type()->validate( new E( null ) ) );
	t_check( 'false valid', true, e_type()->validate( new E( false ) ) );
	t_check( 'empty array valid', true, e_type()->validate( new E( array(), true ) ) );
	t_check( 'html for null', '', e_type()->get_value_as_html( new E( null ) ) );
	t_check( 'html for empty array', '', e_type()->get_value_as_html( new E( array(), true ) ) );
	t_check( 'html for false', '', e_type()->get_value_as_html( new E( false ) ) );
	t_check( 'no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
} );

// Edge 2: non-string input gives '' from parse_id, and no PHP warning (the stub handler turns warnings into exceptions).
t_run( 'edge 2: non-string input', function () use ( $A ) {
	t_reset();
	t_configure();
	t_check( 'parse nested array', '', C::parse_id( array( array( $A ) ), 'asset' ) );
	t_check( 'parse float', '', C::parse_id( 1.5, 'asset' ) );
	t_check( 'parse bool', '', C::parse_id( true, 'asset' ) );
	t_check( 'parse object', '', C::parse_id( new stdClass(), 'asset' ) );
	t_check( 'validate int rejected', false, e_type()->validate( new E( 42 ) ) );
	t_check( 'validate nested array rejected', false, e_type()->validate( new E( array( array( 'x' ) ), true ) ) );
	t_check( 'validate object rejected', false, e_type()->validate( new E( new stdClass() ) ) );
	t_check( 'html for int', '', e_type()->get_value_as_html( new E( 42 ) ) );
	t_check( 'no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
} );

// Edge 3: text that is not a UUID or Immich URL is rejected with the message.
t_run( 'edge 3: text rejected', function () {
	t_reset();
	t_configure();
	$t = e_type( 'album' );
	t_check( 'album text rejected', false, $t->validate( new E( 'Trip to the coast' ) ) );
	t_contains( 'album message', 'is not an Immich album ID or URL', $t->errors[0] );
	t_check( 'uuid without hyphens rejected', '', C::parse_id( '11111111222233334444555555555555', 'asset' ) );
	t_check( 'uuid in braces rejected', '', C::parse_id( '{11111111-2222-3333-4444-555555555555}', 'asset' ) );
	t_check( 'uuid with inner space rejected', '', C::parse_id( '1111111 1-2222-3333-4444-555555555555', 'asset' ) );
	t_check( 'javascript url rejected', '', C::parse_id( 'javascript:alert(1)', 'asset' ) );
	t_check( 'no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
} );

// Edge 4: wrong kind in a URL is rejected for every kind pairing.
t_run( 'edge 4: wrong kind URL', function () use ( $A ) {
	t_reset();
	t_configure();
	t_check( 'person url in person album field', '', C::parse_id( "https://immich.example.org/photos/$A", 'person' ) );
	t_check( 'photo url in album field', '', C::parse_id( "https://immich.example.org/photos/$A", 'album' ) );
	t_check( 'photo url in person field', '', C::parse_id( "https://immich.example.org/photos/$A", 'person' ) );
	t_check( 'validate photo url for person rejected', false, e_type( 'person' )->validate( new E( "https://immich.example.org/photos/$A" ) ) );
	t_check( 'check() wrong kind gives bad input', 'tainacan_immich_bad_input', Tainacan\Immich_Rest::check( 'album', "https://immich.example.org/photos/$A" )['code'] );
	t_check( 'no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
} );

// Edge 5: uppercase and spaces are accepted and lowercased, through validate too.
t_run( 'edge 5: uppercase and spaces', function () use ( $A, $B ) {
	t_reset();
	t_configure();
	t_check( 'newline after uuid', $A, C::parse_id( $A . "\n", 'asset' ) );
	t_check( 'tab before uuid', $A, C::parse_id( "\t" . strtoupper( $A ), 'asset' ) );
	t_check( 'mixed case url path', $B, C::parse_id( 'https://immich.example.org/photos/' . strtoupper( $B ), 'asset' ) );
	t_queue( 200, e_info( $B ) );
	t_check( 'validate uppercase accepted', true, e_type()->validate( new E( '  ' . strtoupper( $B ) . '  ' ) ) );
	t_check( 'request uses lowercase id', "https://immich.example.org/api/assets/$B", $GLOBALS['test_http_log'][0][0] );
} );

// Edge 6: options empty: not_configured, no HTTP call, save allowed, check message, proxy 404.
t_run( 'edge 6: options empty', function () use ( $A ) {
	t_reset();
	$t = e_type();
	t_check( 'save allowed', true, $t->validate( new E( $A ) ) );
	t_check( 'no HTTP call on save', 0, count( $GLOBALS['test_http_log'] ) );
	$r = R::check( 'asset', $A );
	t_check( 'check code', 'tainacan_immich_not_configured', $r['code'] );
	t_contains( 'check message', 'Immich is not set up', $r['message'] );
	$s = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_check( 'proxy 404 when not configured', 404, $s['status'] );
	t_check( 'proxy empty body', '', $s['body'] );
	t_check( 'no HTTP call on proxy', 0, count( $GLOBALS['test_http_log'] ) );
} );

// Edge 7: 401 is kept and the check says the key was not accepted.
t_run( 'edge 7: 401', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 401 );
	t_check( 'save allowed on 401', true, e_type()->validate( new E( $A ) ) );
	t_queue( 401 );
	$r = R::check( 'asset', $A );
	t_check( 'check code auth', 'tainacan_immich_auth', $r['code'] );
	t_contains( 'check message', 'did not accept the API key', $r['message'] );
	t_queue( 401 );
	$s = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_check( 'proxy 404 on 401', 404, $s['status'] );
} );

// Edge 8: 403 is kept with the permission name. The proxy never passes a 403 through.
t_run( 'edge 8: 403', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 403 );
	t_check( 'save allowed on 403', true, e_type()->validate( new E( $A ) ) );
	t_queue( 403 );
	$r = R::check( 'asset', $A );
	t_check( 'check code forbidden', 'tainacan_immich_forbidden', $r['code'] );
	t_contains( 'asset.read named', 'asset.read', $r['message'] );
	t_queue( 403 );
	t_contains( 'album.read named', 'album.read', C::get_info( 'album', $A )->get_error_message() );
	t_queue( 403 );
	$s = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_check( 'proxy gives 404 not 403 on Immich 403', 404, $s['status'] );
	t_check( 'proxy empty body on Immich 403', '', $s['body'] );
} );

// Edge 9: 404 and 400 reject the save; proxy gives 404.
t_run( 'edge 9: 404 and 400', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 404 );
	t_check( '404 rejects save', false, e_type()->validate( new E( $A ) ) );
	t_queue( 400 );
	t_check( '400 rejects save', false, e_type()->validate( new E( $A ) ) );
	t_queue( 400 );
	t_check( '400 code not found', 'tainacan_immich_not_found', C::get_info( 'asset', $A )->get_error_code() );
	t_queue( 404 );
	$s = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_check( 'proxy 404 on Immich 404', 404, $s['status'] );
	t_check( 'proxy empty body', '', $s['body'] );
	t_queue( 400 );
	t_check( 'proxy 404 on Immich 400', 404, R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) )['status'] );
} );

// Edge 10: trashed asset rejects the save with the trash message.
t_run( 'edge 10: trashed', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, e_info( $A, array( 'originalFileName' => 'x.jpg', 'isTrashed' => true ) ) );
	$t = e_type();
	t_check( 'trashed rejects save', false, $t->validate( new E( $A ) ) );
	t_contains( 'trash message', 'trash', $t->errors[0] );
	t_queue( 200, e_info( $A, array( 'isTrashed' => true ) ) );
	t_check( 'trashed check code', 'tainacan_immich_trashed', R::check( 'asset', $A )['code'] );
	t_queue( 200, e_info( $A, array( 'name' => 'Ann', 'isTrashed' => true ) ) );
	t_check( 'person with isTrashed key is not trashed', true, e_type( 'person' )->validate( new E( $A ) ) );
} );

// Edge 11: timeout or connection error keeps the save; proxy gives 404.
t_run( 'edge 11: unreachable', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue_error();
	t_check( 'save allowed when unreachable', true, e_type()->validate( new E( $A ) ) );
	t_queue_error();
	$r = R::check( 'asset', $A );
	t_check( 'check code unreachable', 'tainacan_immich_unreachable', $r['code'] );
	t_not_contains( 'check message has no host', 'immich.example.org', $r['message'] );
	t_queue_error();
	$s = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_check( 'proxy 404 when unreachable', 404, $s['status'] );
	t_check( 'proxy empty body when unreachable', '', $s['body'] );
} );

// Edge 12: 3xx is not followed, and gives server_error. Only one HTTP call is made.
t_run( 'edge 12: redirect', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 301, '', array( 'location' => 'https://evil.example.net/api/assets/' . $A ) );
	t_check( 'save allowed on 301', true, e_type()->validate( new E( $A ) ) );
	t_check( 'one HTTP call only', 1, count( $GLOBALS['test_http_log'] ) );
	t_check( 'redirection disabled', 0, $GLOBALS['test_http_log'][0][1]['redirection'] );
	t_queue( 302 );
	$r = C::get_info( 'asset', $A );
	t_check( 'code server_error', 'tainacan_immich_server_error', $r->get_error_code() );
	t_contains( 'message has code', '302', $r->get_error_message() );
	t_queue( 301 );
	$s = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_check( 'proxy 404 on redirect', 404, $s['status'] );
	t_not_contains( 'no redirect host in messages', 'evil.example.net', $r->get_error_message() );
} );

// Edge 13: not an image, or 5xx, gives proxy 404 with empty body. Image types are accepted case-insensitively.
t_run( 'edge 13: non-image and 5xx', function () use ( $A ) {
	t_reset();
	t_configure();
	$sig = R::sign( 'asset', $A );
	t_queue( 200, '{}', array( 'content-type' => 'application/octet-stream' ) );
	t_check( 'octet-stream 404', 404, R::serve_thumbnail( 'asset', $A, $sig )['status'] );
	t_queue( 200, 'x', array( 'content-type' => 'imagex/png' ) );
	t_check( 'imagex prefix 404', 404, R::serve_thumbnail( 'asset', $A, $sig )['status'] );
	t_queue( 200, 'x', array() );
	t_check( 'missing content type 404', 404, R::serve_thumbnail( 'asset', $A, $sig )['status'] );
	t_queue( 503, 'down' );
	$s = R::serve_thumbnail( 'asset', $A, $sig );
	t_check( '503 404', 404, $s['status'] );
	t_check( '503 empty body', '', $s['body'] );
	t_queue( 200, 'PNG', array( 'content-type' => 'Image/PNG' ) );
	$s = R::serve_thumbnail( 'asset', $A, $sig );
	t_check( 'Image/PNG accepted', 200, $s['status'] );
	t_check( 'Image/PNG header passed', 'Image/PNG', $s['headers']['Content-Type'] );
} );

// Edge 14: bad or missing sig, kind album, bad UUID: 403 with no HTTP call.
t_run( 'edge 14: proxy refusals', function () use ( $A, $B ) {
	t_reset();
	t_configure();
	$sig = R::sign( 'asset', $A );
	t_check( 'null sig 403', 403, R::serve_thumbnail( 'asset', $A, null )['status'] );
	t_check( 'array sig 403', 403, R::serve_thumbnail( 'asset', $A, array( $sig ) )['status'] );
	t_check( 'short sig 403', 403, R::serve_thumbnail( 'asset', $A, substr( $sig, 0, 10 ) )['status'] );
	t_check( 'changed one char 403', 403, R::serve_thumbnail( 'asset', $A, substr_replace( $sig, ( '0' === $sig[0] ? '1' : '0' ), 0, 1 ) )['status'] );
	t_check( 'null kind 403', 403, R::serve_thumbnail( null, $A, $sig )['status'] );
	t_check( 'video kind 403', 403, R::serve_thumbnail( 'video', $A, R::sign( 'video', $A ) )['status'] );
	t_check( 'album with valid album sig 403', 403, R::serve_thumbnail( 'album', $A, R::sign( 'album', $A ) )['status'] );
	t_check( 'uppercase id with lowercase sig 403', 403, R::serve_thumbnail( 'asset', strtoupper( $B ), R::sign( 'asset', $B ) )['status'] );
	t_check( 'uppercase id no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
	t_check( 'uuid with newline 403', 403, R::serve_thumbnail( 'asset', $A . "\n", $sig )['status'] );
	t_check( 'null id 403', 403, R::serve_thumbnail( 'asset', null, $sig )['status'] );
	t_check( 'no HTTP call for refusals', 0, count( $GLOBALS['test_http_log'] ) );
	t_queue( 200, 'IMG', array( 'content-type' => 'image/jpeg' ) );
	$pid = $B;
	$psig = R::sign( 'person', $pid );
	t_check( 'person valid sig gives 200', 200, R::serve_thumbnail( 'person', $pid, $psig )['status'] );
	t_check( 'person sig not valid for asset', 403, R::serve_thumbnail( 'asset', $pid, $psig )['status'] );
	t_check( 'person path used', "https://immich.example.org/api/people/$pid/thumbnail", $GLOBALS['test_http_log'][0][0] );
} );

// Edge 15: album or person with two values is rejected (also for the person kind).
t_run( 'edge 15: album/person multiple', function () use ( $A, $B ) {
	t_reset();
	t_configure();
	$t = e_type( 'person' );
	t_check( 'person two values rejected', false, $t->validate( new E( array( $A, $B ), true ) ) );
	t_contains( 'person message', 'Only one Immich person is allowed', $t->errors[0] );
	t_check( 'two values rejected before any HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
	$t = e_type( 'album' );
	t_check( 'album two values with one bad rejected', false, $t->validate( new E( array( $A, 'junk' ), true ) ) );
} );

// Edge 16: unknown or empty link_kind is treated as asset.
t_run( 'edge 16: unknown link_kind', function () {
	t_check( 'empty string kind', 'asset', e_type( '' )->get_kind() );
	t_check( 'null kind', 'asset', e_type( null )->get_kind() );
	t_check( 'uppercase kind', 'asset', e_type( 'ALBUM' )->get_kind() );
	t_check( 'int kind', 'asset', e_type( 5 )->get_kind() );
	t_check( 'person kind kept', 'person', e_type( 'person' )->get_kind() );
} );

// Edge 17: salt change makes old signed URLs fail, and new URLs differ.
t_run( 'edge 17: salt change', function () use ( $A ) {
	t_reset();
	t_configure();
	$GLOBALS['test_salt'] = 'salt-one';
	$old_sig = R::sign( 'asset', $A );
	$old_url = R::thumbnail_url( 'asset', $A );
	$GLOBALS['test_salt'] = 'salt-two';
	t_check( 'old sig not valid after salt change', false, R::verify( 'asset', $A, $old_sig ) );
	t_queue( 200, 'IMG', array( 'content-type' => 'image/webp' ) );
	t_check( 'old sig gives 403', 403, R::serve_thumbnail( 'asset', $A, $old_sig )['status'] );
	t_check( 'new render makes a different sig', false, R::sign( 'asset', $A ) === $old_sig );
	t_check( 'new render url differs', false, R::thumbnail_url( 'asset', $A ) === $old_url );
	t_check( 'no HTTP call for old sig', 0, count( $GLOBALS['test_http_log'] ) );
	unset( $GLOBALS['test_salt'] );
} );

// Edge 20: key and host never appear in output (asset output, check JSON, errors, proxy).
t_run( 'edge 20: no secret in output', function () use ( $A, $KEY, $HOST ) {
	t_reset();
	t_configure();
	$codes = array( 200, 400, 401, 403, 404, 500, 302 );
	foreach ( $codes as $code ) {
		t_queue( $code, 'body with ' . $KEY, array( 'content-type' => 'image/png' ) );
		$r = R::check( 'asset', $A );
		t_not_contains( "check $code json has no key", $KEY, json_encode( $r ) );
		t_not_contains( "check $code json has no host", $HOST, json_encode( $r ) );
	}
	t_queue_error();
	$r = R::check( 'asset', $A );
	t_not_contains( 'unreachable json has no key', $KEY, json_encode( $r ) );
	t_not_contains( 'unreachable json has no host', $HOST, json_encode( $r ) );

	$t = e_type();
	t_queue( 404 );
	$t->validate( new E( $A ) );
	t_not_contains( 'asset validate error has no key', $KEY, implode( ' ', $t->errors ) );
	t_not_contains( 'asset validate error has no host', $HOST, implode( ' ', $t->errors ) );
	$html = e_type( 'asset' )->get_value_as_html( new E( $A ) );
	t_not_contains( 'asset html has no host', $HOST, $html );
	t_not_contains( 'asset html has no key', $KEY, $html );
	$html = e_type( 'person' )->get_value_as_html( new E( $A ) );
	t_contains( 'person html has the Immich base link only', 'https://' . $HOST . '/people/' . $A, $html );
	t_not_contains( 'person html has no key', $KEY, $html );
	$html = e_type( 'album' )->get_value_as_html( new E( $A ) );
	t_not_contains( 'album html has no key', $KEY, $html );
	t_not_contains( 'thumb url has no key', $KEY, R::thumbnail_url( 'asset', $A ) );
	t_not_contains( 'thumb url has no host', $HOST, R::thumbnail_url( 'asset', $A ) );
	t_contains( 'thumb url uses rest host', 'wp.example.test/wp-json/tainacan-immich/v1/thumbnail', R::thumbnail_url( 'asset', $A ) );
	t_queue( 500, $KEY );
	$s = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_not_contains( 'proxy body has no key', $KEY, $s['body'] );
	t_not_contains( 'proxy headers have no key', $KEY, json_encode( $s['headers'] ) );
} );

// Extra: parse_id boundary cases and label types.
t_run( 'extra: boundaries', function () use ( $A ) {
	t_reset();
	t_configure();
	$pad = str_repeat( ' ', 2048 - 36 ) . $A;
	t_check( 'exactly 2048 bytes accepted', $A, C::parse_id( $pad, 'asset' ) );
	t_check( '2049 bytes rejected', '', C::parse_id( ' ' . $pad, 'asset' ) );
	t_check( 'url with trailing slash', $A, C::parse_id( "https://immich.example.org/photos/$A/", 'asset' ) );
	t_check( 'url with no scheme rejected', '', C::parse_id( "immich.example.org/photos/$A", 'asset' ) );
	t_check( 'empty string', '', C::parse_id( '', 'asset' ) );
	t_check( 'whitespace only', '', C::parse_id( "   \t ", 'asset' ) );
	t_check( 'kind null', '', C::parse_id( $A, null ) );
	t_queue( 200, e_info( $A, array( 'originalFileName' => 5 ) ) );
	t_check( 'non-string label gives empty label', '', R::check( 'asset', $A )['label'] );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	t_check( 'missing name gives empty label', '', R::check( 'person', $A )['label'] );
	t_queue( 200, json_encode( array( 'id' => $A ) ) );
	t_check( 'missing trashed field is not trashed', true, R::check( 'asset', $A )['ok'] );
	t_queue( 200, 'null' );
	t_check( 'json null answer is bad response', 'tainacan_immich_bad_response', C::get_info( 'asset', $A )->get_error_code() );
	t_queue( 200, json_encode( array( 'id' => $A ) ), array() );
	t_check( 'thumb without content type is bad response', 'tainacan_immich_bad_response', C::get_thumbnail( 'asset', $A )->get_error_code() );
} );

// Extra: album and person links use the base URL. Asset output has no link.
t_run( 'extra: album link and base url', function () use ( $A ) {
	t_reset();
	$GLOBALS['test_options']['tainacan_immich_url'] = 'https://immich.example.org/';
	$html = e_type( 'album' )->get_value_as_html( new E( $A ) );
	t_contains( 'trailing slash on base removed', 'href="https://immich.example.org/albums/' . $A . '"', $html );
	t_check( 'album html has no img', false, false !== strpos( $html, '<img' ) );
	$html = e_type( 'asset' )->get_value_as_html( new E( $A ) );
	t_check( 'asset html has no anchor', false, false !== strpos( $html, '<a ' ) );
} );

t_summary();
