<?php
/**
 * Tester checks, second spec round: exact error texts, error leaks, final-newline and
 * control-character rules, request arguments, docs and version text.
 * Plain PHP, stubs only, no network. Fake host, fake key, fake slug and key.
 * Run: php tests/test-immich-share-final.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;
use Tainacan\Entities\Item_Metadata_Entity as E;
use Tainacan\Metadata_Types\Immich_Link as L;

$A    = '11111111-2222-3333-4444-555555555555';
$B    = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$D    = 'cccccccc-dddd-eeee-ffff-000000000000';
$H    = 'https://immich.example.org';
$SLUG = 'SeCrEtSlug';
$KEY  = 'KeYsEcReT-9_z';
$ROOT = dirname( __DIR__ );

function fz_album( $id ) {
	return json_encode( array( 'type' => 'ALBUM', 'password' => null, 'album' => array( 'id' => $id, 'albumName' => 'Trip' ) ) );
}
function fz_ind( $ids ) {
	$a = array();
	foreach ( $ids as $i ) {
		$a[] = array( 'id' => $i );
	}
	return json_encode( array( 'type' => 'INDIVIDUAL', 'password' => null, 'assets' => $a ) );
}
function fz_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
}
function fz_msg( $r ) {
	return is_wp_error( $r ) ? $r->get_error_message() : '';
}
function fz_calls() {
	return count( $GLOBALS['test_http_log'] );
}
function fz_read( $root, $rel ) {
	return (string) file_get_contents( $root . '/' . $rel );
}

// ---- Exact error texts (spec "Error texts") ----

t_run( 'exact error texts from the spec', function () use ( $A, $B, $D, $H, $SLUG, $KEY ) {
	t_reset();
	t_configure();
	foreach ( array( 401, 403, 404, 400 ) as $code ) {
		t_queue( $code, '' );
		$r = C::get_shared_link( 'slug', $SLUG );
		t_check( "unusable $code code", 'tainacan_immich_share_unusable', fz_code( $r ) );
		t_check( "unusable $code text", 'This share link cannot be used. It is removed, expired or protected by a password.', fz_msg( $r ) );
	}
	t_queue( 200, json_encode( array( 'type' => 'ALBUM', 'password' => 'pw', 'album' => array( 'id' => $A ) ) ) );
	$r = C::get_shared_link( 'slug', $SLUG );
	t_check( 'password text', 'This share link is protected by a password. Use a share link without a password.', fz_msg( $r ) );
	t_queue( 200, json_encode( array( 'type' => 'INDIVIDUAL', 'password' => null, 'assets' => array() ) ) );
	$r = C::get_shared_link( 'key', $KEY );
	t_check( 'empty text', 'This share link has no photos.', fz_msg( $r ) );
	t_queue( 200, json_encode( array( 'type' => 'FOO', 'password' => null ) ) );
	$r = C::get_shared_link( 'key', $KEY );
	t_check( 'unknown text', 'The Immich server sent a kind of share link that the plugin does not know.', fz_msg( $r ) );
	t_queue( 200, '{bad json' );
	$r = C::get_shared_link( 'key', $KEY );
	t_check( 'bad_response text', 'The Immich server sent an answer that the plugin cannot read.', fz_msg( $r ) );
	t_check( 'bad_response code', 'tainacan_immich_bad_response', fz_code( $r ) );

	$n = fz_calls();
	t_queue( 200, fz_ind( array( $A ) ) );
	$r = R::check( 'album', "$H/share/$KEY" );
	t_check( 'wrong kind photo text (album field)', 'This share link has single photos. Use a photo field.', $r['message'] );
	t_check( 'wrong kind photo code', 'tainacan_immich_share_wrong_kind', $r['code'] );
	t_check( 'wrong kind photo: one HTTP call, no info call', $n + 1, fz_calls() );

	$n = fz_calls();
	t_queue( 200, fz_album( $A ) );
	$r = R::check( 'asset', "$H/s/$SLUG" );
	t_check( 'wrong kind album text (asset field)', 'This share link is for an album. Use an album field.', $r['message'] );
	t_check( 'wrong kind album: one HTTP call, no info call', $n + 1, fz_calls() );

	$n = fz_calls();
	t_queue( 200, fz_ind( array( $A, $B, $D ) ) );
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( 'too many text with count 3', 'This share link has 3 photos. Each value holds one photo. Paste a link to one photo for each value.', $r['message'] );
	t_check( 'too many code', 'tainacan_immich_share_too_many', $r['code'] );
	t_check( 'too many: no id', '', $r['id'] );
	t_check( 'too many: one HTTP call, no info call', $n + 1, fz_calls() );

	$n = fz_calls();
	$r = R::check( 'asset', "$H/share/abc\x01" );
	t_check( 'bad_input share text', 'This is not a valid Immich share link for this server.', $r['message'] );
	t_check( 'bad_input share code', 'tainacan_immich_bad_input', $r['code'] );
	t_check( 'bad_input share: no HTTP', $n, fz_calls() );

	$t = new L();
	t_check( 'validate not converted refuses', false, $t->validate( new E( "$H/s/$SLUG" ) ) );
	t_check( 'validate not converted text', 'A share link cannot be saved. Wait until the field shows the photo or album, then save again.', $t->errors[0] );
	t_check( 'validate not converted one error', 1, count( $t->errors ) );
} );

t_run( 'too many: count is the number of unique photos (2 unique in 3 entries)', function () use ( $A, $B, $H, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, fz_ind( array( $A, strtoupper( $A ), $B ) ) );
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( 'count 2 text', 'This share link has 2 photos. Each value holds one photo. Paste a link to one photo for each value.', $r['message'] );
} );

t_run( 'duplicate photos that collapse to one are accepted (case differs)', function () use ( $A, $H, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, fz_ind( array( $A, strtoupper( $A ) ) ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'one.jpg', 'isTrashed' => false ) ) );
	$r = R::check( 'asset', "$H/share/$KEY" );
	t_check( 'ok', true, $r['ok'] );
	t_check( 'id lowercase', $A, $r['id'] );
	t_check( 'info call happened (2 calls in total)', 2, fz_calls() );
} );

t_run( 'album kind with an INDIVIDUAL link holding zero photos gives share_empty', function () use ( $H, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, fz_ind( array() ) );
	$r = R::check( 'album', "$H/share/$KEY" );
	t_check( 'empty code', 'tainacan_immich_share_empty', $r['code'] );
	t_check( 'empty text', 'This share link has no photos.', $r['message'] );
	t_check( 'no info call', 1, fz_calls() );
} );

// ---- Error leaks (spec Safety and acceptance) ----

t_run( 'no error text holds the slug, key, pasted URL, API key or host', function () use ( $A, $H, $SLUG, $KEY ) {
	t_reset();
	t_configure();
	$secrets = array( $SLUG, $KEY, 'fake-key-abc123', 'immich.example.org', "$H/s/$SLUG", "$H/share/$KEY" );
	$msgs    = array();
	foreach ( array( 401, 403, 404, 400, 500, 503, 302 ) as $code ) {
		t_queue( $code, '' );
		$msgs[ "http $code" ] = fz_msg( C::get_shared_link( 'slug', $SLUG ) );
	}
	t_queue_error();
	$msgs['transport'] = fz_msg( C::get_shared_link( 'key', $KEY ) );
	foreach ( array( '{bad', '', 'null', '"x"', '[]', '{"type":"ALBUM"}', '{"type":"FOO"}', '{"type":"ALBUM","password":"pw"}', '{"type":"INDIVIDUAL","assets":[]}' ) as $i => $body ) {
		t_queue( 200, $body );
		$msgs[ "body $i" ] = fz_msg( C::get_shared_link( 'slug', $SLUG ) );
	}
	t_queue( 200, fz_ind( array( $A, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' ) ) );
	$r                 = R::check( 'asset', "$H/share/$KEY" );
	$msgs['too many']  = $r['message'];
	$msgs['empty']     = R::check( 'asset', '' )['message'];
	$msgs['blank']     = R::check( 'asset', "  \t " )['message'];
	$t = new L();
	$t->validate( new E( "$H/s/$SLUG" ) );
	$msgs['validate']  = $t->errors[0];
	foreach ( $msgs as $label => $m ) {
		foreach ( $secrets as $s ) {
			t_not_contains( "$label has no secret", $s, $m );
		}
	}
	t_check( 'all messages collected (21)', 21, count( $msgs ) );
} );

// ---- Final newline and control characters (spec-round rules) ----

t_run( 'UUID with final newline is refused by the thumbnail route and the thumbnail client, no HTTP', function () use ( $A ) {
	t_reset();
	t_configure();
	$bad = "$A\n";
	$r   = R::serve_thumbnail( 'asset', $bad, R::sign( 'asset', $bad ) );
	t_check( 'serve_thumbnail status 403 for final newline', 403, $r['status'] );
	t_check( 'get_thumbnail asset not_found for final newline', 'tainacan_immich_not_found', fz_code( C::get_thumbnail( 'asset', $bad ) ) );
	t_check( 'get_thumbnail person not_found for final newline', 'tainacan_immich_not_found', fz_code( C::get_thumbnail( 'person', $bad ) ) );
	t_check( 'get_info asset not_found for final newline', 'tainacan_immich_not_found', fz_code( C::get_info( 'asset', $bad ) ) );
	t_check( 'no HTTP for these refusals', 0, fz_calls() );
} );

t_run( 'plain UUID thumbnail still works (one HTTP call, image bytes)', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, 'BYTES', array( 'content-type' => 'image/jpeg' ) );
	$r = R::serve_thumbnail( 'asset', $A, R::sign( 'asset', $A ) );
	t_check( 'status 200', 200, $r['status'] );
	t_check( 'one call', 1, fz_calls() );
	t_check( 'thumbnail url has no newline', 0, substr_count( $GLOBALS['test_http_log'][0][0], "\n" ) );
} );

t_run( 'UUID with newline or NUL in a URL path is refused (no HTTP)', function () use ( $A, $H ) {
	t_reset();
	t_configure();
	t_check( 'album url with newline in path', '', C::parse_id( "$H/albums/$A\n/x", 'album' ) );
	t_check( 'photo url with form feed at end', '', C::parse_id( "$H/photos/$A\x0c", 'asset' ) );
	t_check( 'no HTTP', 0, fz_calls() );
} );

t_run( 'share URL with an embedded control character is refused by check and validate, no HTTP', function () use ( $H, $KEY ) {
	t_reset();
	t_configure();
	foreach ( array( "$H/s/ab\x01c", "$H/s/ab\x7fc", "$H/s/ab\tc", "$H/share/$KEY\x0cx" ) as $i => $u ) {
		$r = R::check( 'asset', $u );
		t_check( "check refused $i", false, $r['ok'] );
		$t = new L();
		t_check( "validate refused $i", false, $t->validate( new E( $u ) ) );
	}
	t_check( 'no HTTP', 0, fz_calls() );
} );

// ---- Second Spec round rules ----

t_run( 'whitespace around a share link is trimmed and accepted (item 1)', function () use ( $H, $A ) {
	t_reset();
	t_configure();
	t_check( 'trailing LF', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "$H/s/abc\n" ) );
	t_check( 'tabs around', array( 'param' => 'slug', 'value' => 'abc' ), C::parse_share( "\t$H/s/abc\t" ) );
	t_check( 'spaces and CRLF', array( 'param' => 'key', 'value' => 'AbC-12_x' ), C::parse_share( "  $H/share/AbC-12_x \r\n" ) );
	t_queue( 200, fz_album( $A ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'albumName' => 'Trip' ) ) );
	$r = R::check( 'album', "$H/s/abc\n" );
	t_check( 'check resolves trailing LF', true, $r['ok'] );
	t_check( 'two HTTP calls', 2, fz_calls() );
} );

t_run( 'control character inside the trimmed link is refused (item 1)', function () use ( $H ) {
	t_reset();
	t_configure();
	foreach ( array( "$H/s/a\nbc", "$H/s/a\tbc", "$H/s/a\x01bc", "$H/s/a\x7Fbc" ) as $i => $u ) {
		t_check( "parse_share $i", array(), C::parse_share( $u ) );
		$r = R::check( 'album', $u );
		t_check( "check bad_input $i", 'tainacan_immich_bad_input', $r['code'] );
	}
	t_check( 'no HTTP', 0, fz_calls() );
} );

t_run( 'parse_id trims; inner newline refused (item 2)', function () use ( $A ) {
	t_reset();
	t_configure();
	t_check( 'album UUID + LF', $A, C::parse_id( "$A\n", 'album' ) );
	t_check( 'asset UUID + LF', $A, C::parse_id( "$A\n", 'asset' ) );
	t_check( 'inner newline', '', C::parse_id( "1111\n1111-2222-3333-4444-555555555555", 'asset' ) );
} );

t_run( 'percent-encoded share path is detected and not echoed (item 3)', function () use ( $H, $SLUG ) {
	t_reset();
	t_configure();
	foreach ( array( "$H/%73/$SLUG", "$H/%73hare/$SLUG", "$H/%73%68%61%72%65/$SLUG", "$H/s%2F$SLUG", "$H/%2573/$SLUG" ) as $i => $u ) {
		t_check( "looks_like_share $i", true, C::looks_like_share( $u ) );
		$t = new L();
		t_check( "validate refused $i", false, $t->validate( new E( $u ) ) );
		t_check( "one error $i", 1, count( $t->errors ) );
		t_not_contains( "no slug $i", $SLUG, $t->errors[0] );
		t_not_contains( "no %73 $i", '%73', $t->errors[0] );
		t_not_contains( "no host $i", 'immich.example.org', $t->errors[0] );
		$r = R::check( 'asset', $u );
		t_check( "check bad_input $i", 'tainacan_immich_bad_input', $r['code'] );
		t_not_contains( "check no slug $i", $SLUG, $r['message'] );
	}
	t_check( 'no HTTP', 0, fz_calls() );
} );

// ---- Request arguments (spec Safety and acceptance) ----

t_run( 'request arguments for both share forms', function () use ( $A, $SLUG, $KEY ) {
	t_reset();
	t_configure();
	t_queue( 200, fz_album( $A ) );
	C::get_shared_link( 'slug', $SLUG );
	t_check( 'slug url exact', "https://immich.example.org/api/shared-links/me?slug=$SLUG", $GLOBALS['test_http_log'][0][0] );
	$args = $GLOBALS['test_http_log'][0][1];
	t_check( 'slug: no x-api-key', false, array_key_exists( 'x-api-key', $args['headers'] ) );
	t_check( 'slug: accept json', 'application/json', $args['headers']['Accept'] );
	t_check( 'slug: redirection 0', 0, $args['redirection'] );
	t_check( 'slug: timeout 10', 10, $args['timeout'] );
	t_check( 'slug: response limit 5 MB', 5 * MB_IN_BYTES, $args['limit_response_size'] );
	t_queue( 200, fz_album( $A ) );
	C::get_shared_link( 'key', $KEY );
	t_check( 'key url exact', "https://immich.example.org/api/shared-links/me?key=$KEY", $GLOBALS['test_http_log'][1][0] );
	t_check( 'key: no x-api-key', false, array_key_exists( 'x-api-key', $GLOBALS['test_http_log'][1][1]['headers'] ) );
	t_check( 'no password parameter in url', false, false !== strpos( $GLOBALS['test_http_log'][1][0], 'password' ) );
	t_check( 'no login path in url', false, false !== strpos( $GLOBALS['test_http_log'][1][0], 'login' ) );
} );

t_run( 'normal album call still sends the key and the same options', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'albumName' => 'X' ) ) );
	C::get_info( 'album', $A );
	$args = $GLOBALS['test_http_log'][0][1];
	t_check( 'key sent on info call', 'fake-key-abc123', $args['headers']['x-api-key'] );
	t_check( 'info redirection 0', 0, $args['redirection'] );
	t_check( 'info timeout 10', 10, $args['timeout'] );
} );

// ---- Docs and text match the code ----

t_run( 'README.md documents both share forms and the share call, no banned words', function () use ( $ROOT ) {
	$md = fz_read( $ROOT, 'README.md' );
	t_contains( 'documents /s/<slug>', '`/s/<slug>`', $md );
	t_contains( 'documents /share/<key>', '`/share/<key>`', $md );
	t_contains( 'documents GET /shared-links/me', '`GET /shared-links/me`', $md );
	t_check( 'no banned words', 0, preg_match( '/not yet|not supported|removed/i', $md ) );
	t_contains( 'says share key is never saved', 'The share key or slug is never saved.', $md );
	t_contains( 'says a password link cannot be used', 'A share link with a password cannot be used.', $md );
	t_contains( 'says two photos are refused', 'A share link with two or more photos is refused', $md );
} );

t_run( 'docs/live-checks.md C2 wording matches the error texts in the code', function () use ( $ROOT ) {
	$doc = fz_read( $ROOT, 'docs/live-checks.md' );
	t_contains( 'C2 heading', '### C2. Share links', $doc );
	t_contains( 'known gap line', 'Immich 3.3.6 share-link answers', $doc );
	t_contains( 'save refused text', '"A share link cannot be saved"', $doc );
	t_contains( 'two photos text', 'has 2 photos', $doc );
	t_check( 'album field wording (case-insensitive)', 1, preg_match( '/use an album field/i', $doc ) );
	t_check( 'photo field wording (case-insensitive)', 1, preg_match( '/use a photo field/i', $doc ) );
	t_contains( 'cannot be used wording', 'cannot be used', $doc );
	t_contains( 'password wording', 'has a password or cannot be used', $doc );
} );

t_run( 'changelog and version text', function () use ( $ROOT ) {
	$txt = fz_read( $ROOT, 'tainacan-immich/README.txt' );
	t_contains( 'changelog line exact', "* Feature: Immich share links (/s/<slug> and /share/<key>) are accepted and resolved to the album or photo.\n", $txt );
	t_check( '0.3.0 entry above 0.2.2 entry', true, strpos( $txt, "= 0.3.0 =\n" ) < strpos( $txt, "= 0.2.2 =\n" ) );
	t_contains( 'README.txt stable tag', "\nStable tag: 0.3.0\n", $txt );
	$main = fz_read( $ROOT, 'tainacan-immich/tainacan-immich.php' );
	t_contains( 'main Version', "\nVersion: 0.3.0\n", $main );
	t_contains( 'main Stable tag', "\nStable tag: 0.3.0\n", $main );
	$pkg = json_decode( fz_read( $ROOT, 'tainacan-immich/package.json' ), true );
	t_check( 'package.json version', '0.3.0', $pkg['version'] );
} );

t_run( 'placeholder text is the same in PHP, JS source and built JS', function () use ( $ROOT ) {
	$want  = 'Immich ID, URL or share link';
	$php   = fz_read( $ROOT, 'tainacan-immich/metadata-types/class-tainacan-immich-link.php' );
	$js    = fz_read( $ROOT, 'tainacan-immich/src/immich-link.js' );
	t_contains( 'PHP preview placeholder', $want, $php );
	t_contains( 'JS source placeholder', "'$want'", $js );
	t_contains( 'built JS placeholder', $want, fz_read( $ROOT, 'tainacan-immich/build/immich-link.js' ) );
} );

t_run( 'acceptance grep: no tmdc.cc in plugin code', function () use ( $ROOT ) {
	$files = array_merge(
		glob( $ROOT . '/tainacan-immich/*.php' ),
		glob( $ROOT . '/tainacan-immich/metadata-types/*' ),
		glob( $ROOT . '/tainacan-immich/src/*' )
	);
	$hits  = 0;
	foreach ( $files as $f ) {
		$hits += substr_count( (string) file_get_contents( $f ), 'tmdc.cc' );
	}
	t_check( 'tmdc.cc hits', 0, $hits );
	t_check( 'files scanned', true, count( $files ) > 5 );
} );

t_run( 'no new option, transient, cache or log call in the share code', function () use ( $ROOT ) {
	$bad = array( 'add_option(', 'update_option(', 'set_transient(', 'set_site_transient(', 'wp_cache_set(', 'error_log(', 'delete_option(' );
	foreach ( array( 'tainacan-immich/class-tainacan-immich-client.php', 'tainacan-immich/class-tainacan-immich-rest.php', 'tainacan-immich/metadata-types/class-tainacan-immich-link.php' ) as $rel ) {
		$src = fz_read( $ROOT, $rel );
		foreach ( $bad as $needle ) {
			t_check( "$rel has no $needle", 0, substr_count( $src, $needle ) );
		}
	}
} );

t_summary();
