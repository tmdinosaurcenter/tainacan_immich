<?php
/**
 * Tests for \Tainacan\Immich_Rest. Plain PHP, stubs only, no network.
 * Run: php tests/test-immich-rest.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Rest as R;

$A = '11111111-2222-3333-4444-555555555555';

t_run( 'sign and verify', function () use ( $A ) {
	$sig = R::sign( 'asset', $A );
	t_check( 'good', true, R::verify( 'asset', $A, $sig ) );
	t_check( 'wrong sig', false, R::verify( 'asset', $A, str_repeat( '0', 64 ) ) );
	t_check( 'other kind', false, R::verify( 'person', $A, $sig ) );
	t_check( 'array sig', false, R::verify( 'asset', $A, array( $sig ) ) );
	t_check( 'null sig', false, R::verify( 'asset', $A, null ) );
	t_check( 'int id', false, R::verify( 'asset', 5, $sig ) );
	t_contains( 'thumbnail_url', '/wp-json/tainacan-immich/v1/thumbnail?kind=asset&id=' . $A . '&sig=' . $sig, R::thumbnail_url( 'asset', $A ) );
} );

t_run( 'serve_thumbnail', function () use ( $A ) {
	t_reset();
	t_configure();
	$sig = R::sign( 'asset', $A );
	$r   = R::serve_thumbnail( 'asset', $A, 'bad' );
	t_check( 'bad sig 403', 403, $r['status'] );
	t_check( 'bad sig empty body', '', $r['body'] );
	t_check( 'album 403', 403, R::serve_thumbnail( 'album', $A, R::sign( 'album', $A ) )['status'] );
	t_check( 'bad uuid 403', 403, R::serve_thumbnail( 'asset', 'x', R::sign( 'asset', 'x' ) )['status'] );
	t_check( 'array kind 403', 403, R::serve_thumbnail( array(), $A, $sig )['status'] );
	t_check( 'no call for 403s', 0, count( $GLOBALS['test_http_log'] ) );

	t_queue( 404 );
	$r = R::serve_thumbnail( 'asset', $A, $sig );
	t_check( 'client error 404', 404, $r['status'] );
	t_check( '404 empty body', '', $r['body'] );
	t_queue( 200, '<html>', array( 'content-type' => 'text/html' ) );
	t_check( 'non-image 404', 404, R::serve_thumbnail( 'asset', $A, $sig )['status'] );
	t_queue( 500, 'x' );
	t_check( '5xx 404', 404, R::serve_thumbnail( 'asset', $A, $sig )['status'] );
	t_queue( 200, 'IMG', array( 'content-type' => 'image/webp' ) );
	$r = R::serve_thumbnail( 'asset', $A, $sig );
	t_check( 'ok 200', 200, $r['status'] );
	t_check( 'ok body', 'IMG', $r['body'] );
	t_check( 'content type', 'image/webp', $r['headers']['Content-Type'] );
	t_check( 'cache', 'public, max-age=86400', $r['headers']['Cache-Control'] );
	t_check( 'nosniff', 'nosniff', $r['headers']['X-Content-Type-Options'] );
} );

t_run( 'check JSON shape', function () use ( $A ) {
	$keys = array( 'ok', 'code', 'message', 'id', 'label', 'thumb' );

	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg', 'isTrashed' => false ) ) );
	$r = R::check( 'asset', $A );
	t_check( 'ok keys', $keys, array_keys( $r ) );
	t_check( 'ok', true, $r['ok'] );
	t_check( 'ok code', 'ok', $r['code'] );
	t_check( 'ok label', 'a.jpg', $r['label'] );
	t_check( 'ok id', $A, $r['id'] );
	t_contains( 'ok thumb', '/thumbnail?', $r['thumb'] );

	t_queue( 200, json_encode( array( 'id' => $A, 'albumName' => 'Trip' ) ) );
	$r = R::check( 'album', $A );
	t_check( 'album label', 'Trip', $r['label'] );
	t_check( 'album no thumb', '', $r['thumb'] );

	t_queue( 200, json_encode( array( 'id' => $A, 'name' => 'Ann' ) ) );
	$r = R::check( 'person', $A );
	t_check( 'person label', 'Ann', $r['label'] );
	t_contains( 'person thumb', 'kind=person', $r['thumb'] );

	t_queue( 404 );
	$r = R::check( 'asset', $A );
	t_check( 'not found', false, $r['ok'] );
	t_check( 'not found code', 'tainacan_immich_not_found', $r['code'] );
	t_queue( 401 );
	t_check( 'auth', 'tainacan_immich_auth', R::check( 'asset', $A )['code'] );
	t_queue( 403 );
	t_check( 'forbidden', 'tainacan_immich_forbidden', R::check( 'asset', $A )['code'] );
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg', 'isTrashed' => true ) ) );
	$r = R::check( 'asset', $A );
	t_check( 'trashed', 'tainacan_immich_trashed', $r['code'] );
	t_check( 'trashed not ok', false, $r['ok'] );

	t_check( 'bad input', false, R::check( 'asset', 'nope' )['ok'] );
	t_check( 'bad input keys', $keys, array_keys( R::check( 'asset', array() ) ) );

	t_reset();
	$r = R::check( 'asset', $A );
	t_check( 'not configured', 'tainacan_immich_not_configured', $r['code'] );
	t_check( 'not configured no call', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'routes and config', function () {
	t_reset();
	t_configure();
	R::register_routes();
	t_check( 'check route', true, isset( $GLOBALS['test_routes']['tainacan-immich/v1/check'] ) );
	t_check( 'thumbnail route', true, isset( $GLOBALS['test_routes']['tainacan-immich/v1/thumbnail'] ) );
	$check = $GLOBALS['test_routes']['tainacan-immich/v1/check'];
	t_check( 'check denies without cap', false, call_user_func( $check['permission_callback'] ) );
	t_check( 'kind arg required', true, $check['args']['kind']['required'] );
	t_check( 'kind valid', true, call_user_func( $check['args']['kind']['validate_callback'], 'album' ) );
	t_check( 'kind invalid', false, call_user_func( $check['args']['kind']['validate_callback'], 'video' ) );
	t_check( 'thumbnail permission', '__return_true', $GLOBALS['test_routes']['tainacan-immich/v1/thumbnail']['permission_callback'] );

	R::enqueue_config();
	list( $handle, $js, $pos ) = $GLOBALS['test_inline'][0];
	t_check( 'handle', 'tainacan-immich-link', $handle );
	t_check( 'position', 'before', $pos );
	t_contains( 'has nonce', 'fake-nonce', $js );
	t_contains( 'has rest url', 'wp.example.test', $js );
	t_not_contains( 'no key', 'fake-key-abc123', $js );
	t_not_contains( 'no host', 'immich.example.org', $js );
} );

t_summary();
