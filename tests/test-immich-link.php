<?php
/**
 * Tests for \Tainacan\Metadata_Types\Immich_Link. Plain PHP, stubs only, no network.
 * Run: php tests/test-immich-link.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Entities\Item_Metadata_Entity as E;
use Tainacan\Metadata_Types\Immich_Link as L;

$A = '11111111-2222-3333-4444-555555555555';
$B = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

function t_type( $kind = 'asset' ) {
	$t = new L();
	if ( null !== $kind ) {
		$t->options['link_kind'] = $kind;
	}
	return $t;
}
function t_info( $id, $extra = array() ) {
	return json_encode( array_merge( array( 'id' => $id ), $extra ) );
}

t_run( 'constructor and get_kind', function () {
	$t = new L();
	t_check( 'component', 'tainacan-immich-link', $t->component );
	t_check( 'form component', 'tainacan-immich-link-form', $t->form_component );
	t_check( 'primitive', array( 'text' ), $t->primitive_type );
	t_check( 'default kind', 'asset', $t->get_kind() );
	t_check( 'album kind', 'album', t_type( 'album' )->get_kind() );
	t_check( 'unknown kind', 'asset', t_type( 'video' )->get_kind() );
	t_check( 'array kind', 'asset', t_type( array( 'x' ) )->get_kind() );
	$labels = $t->get_form_labels();
	t_check( 'labels', true, isset( $labels['link_kind']['title'] ) );
} );

t_run( 'validate', function () use ( $A, $B ) {
	t_reset();
	t_configure();
	$t = t_type();
	t_check( 'empty string', true, $t->validate( new E( '' ) ) );
	t_check( 'empty array', true, $t->validate( new E( array(), true ) ) );
	t_check( 'array of empties', true, $t->validate( new E( array( '', '  ' ), true ) ) );
	t_check( 'no call for empties', 0, count( $GLOBALS['test_http_log'] ) );

	$t = t_type();
	t_check( 'bad format', false, $t->validate( new E( '<b>hello</b>' ) ) );
	t_contains( 'bad format message', 'is not an Immich asset ID or URL', $t->errors[0] );
	t_contains( 'value escaped', '&lt;b&gt;', $t->errors[0] );
	t_check( 'no call for bad format', 0, count( $GLOBALS['test_http_log'] ) );

	$t = t_type();
	t_check( 'wrong kind url', false, $t->validate( new E( "https://immich.example.org/albums/$A" ) ) );

	$t = t_type();
	t_check( 'long value', false, $t->validate( new E( str_repeat( 'x', 500 ) ) ) );
	t_check( 'truncated to 100', true, strlen( $t->errors[0] ) < 200 );

	$t = t_type();
	t_check( 'array in array', false, $t->validate( new E( array( array( 'x' ) ), true ) ) );

	t_queue( 200, t_info( $A, array( 'isTrashed' => false ) ) );
	t_check( 'valid asset', true, t_type()->validate( new E( strtoupper( $A ) ) ) );

	$t = t_type();
	t_queue( 404 );
	t_check( 'not found', false, $t->validate( new E( $A ) ) );
	t_contains( 'not found message', 'No Immich asset', $t->errors[0] );

	$t = t_type();
	t_queue( 200, t_info( $A, array( 'isTrashed' => true ) ) );
	t_check( 'trashed', false, $t->validate( new E( $A ) ) );

	t_queue_error();
	t_check( 'unreachable keeps', true, t_type()->validate( new E( $A ) ) );
	t_queue( 401 );
	t_check( 'auth keeps', true, t_type()->validate( new E( $A ) ) );
	t_queue( 403 );
	t_check( 'forbidden keeps', true, t_type()->validate( new E( $A ) ) );
	t_queue( 500 );
	t_check( 'server error keeps', true, t_type()->validate( new E( $A ) ) );

	t_reset();
	t_check( 'not configured keeps', true, t_type()->validate( new E( $A ) ) );
	t_check( 'not configured no call', 0, count( $GLOBALS['test_http_log'] ) );

	t_configure();
	foreach ( array( 'album', 'person' ) as $kind ) {
		$t = t_type( $kind );
		t_check( "$kind two values", false, $t->validate( new E( array( $A, $B ), true ) ) );
		t_contains( "$kind message", "Only one Immich $kind is allowed", $t->errors[0] );
	}
	t_queue( 200, t_info( $A ) );
	t_check( 'album one value', true, t_type( 'album' )->validate( new E( array( $A, '' ), true ) ) );

	t_queue( 200, t_info( $A ) );
	t_queue( 200, t_info( $B ) );
	t_check( 'two assets', true, t_type()->validate( new E( array( $A, $B ), true ) ) );
} );

t_run( 'get_value_as_html', function () use ( $A, $B ) {
	t_reset();
	t_configure();
	$img = '<img class="tainacan-immich-thumb" loading="lazy" src="';

	$html = t_type()->get_value_as_html( new E( $A ) );
	t_contains( 'asset img', $img, $html );
	t_contains( 'signed url', 'sig=', $html );
	t_contains( 'url escaped', '&amp;sig=', $html );
	t_contains( 'alt', 'alt="Immich image"', $html );
	t_not_contains( 'asset no link', '<a ', $html );
	t_not_contains( 'no key', 'fake-key-abc123', $html );

	$html = t_type( 'album' )->get_value_as_html( new E( $A ) );
	t_not_contains( 'album no img', '<img', $html );
	t_contains( 'album link', 'href="https://immich.example.org/albums/' . $A . '"', $html );
	t_contains( 'album rel', 'rel="noopener noreferrer"', $html );
	t_contains( 'album target', 'target="_blank"', $html );

	$html = t_type( 'person' )->get_value_as_html( new E( $A ) );
	t_contains( 'person img', $img, $html );
	t_contains( 'person link', 'href="https://immich.example.org/people/' . $A . '"', $html );

	$html = t_type()->get_value_as_html( new E( array( $A, 'junk', $B ), true ) );
	t_check( 'multi count', 2, substr_count( $html, '<img' ) );
	t_contains( 'multi separator', '</span> | <span>', $html );
	t_check( 'multi starts with prefix', 0, strpos( $html, '<span>' ) );

	t_check( 'empty', '', t_type()->get_value_as_html( new E( '' ) ) );
	t_check( 'junk skipped', '', t_type()->get_value_as_html( new E( 'junk' ) ) );
	t_check( 'empty array', '', t_type()->get_value_as_html( new E( array(), true ) ) );
	t_check( 'array in array', '', t_type()->get_value_as_html( new E( array( array( 'x' ) ), true ) ) );

	$GLOBALS['test_options']['tainacan_immich_url'] = '';
	t_check( 'album no url gives empty', '', t_type( 'album' )->get_value_as_html( new E( $A ) ) );
	$html = t_type( 'person' )->get_value_as_html( new E( $A ) );
	t_contains( 'person no url still img', '<img', $html );
	t_not_contains( 'person no url no link', '<a ', $html );

	t_check( 'no HTTP call at all', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_summary();
