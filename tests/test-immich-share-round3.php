<?php
/**
 * Tester checks, third run: share links whose path holds a percent-encoded control
 * character (%0A) right after the share segment. These must count as share-shaped
 * (fail closed): validate() refuses with the share text and echoes nothing; check()
 * gives the share bad_input text with no HTTP call.
 * Plain PHP, stubs only, no network. Fake host, fake key, fake slug and key.
 * Run: php tests/test-immich-share-round3.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;
use Tainacan\Entities\Item_Metadata_Entity as E;
use Tainacan\Metadata_Types\Immich_Link as L;

$H    = 'https://immich.example.org';
$SLUG = 'SeCrEtSlug';
$KEY  = 'KeYsEcReT-9_z';
$SHARE_TEXT    = 'This is not a valid Immich share link for this server.';
$REFUSE_TEXT   = 'A share link cannot be saved. Wait until the field shows the photo or album, then save again.';

$encoded = array(
	'encoded LF after /s/'            => $H . '/s/%0A' . $SLUG,
	'encoded LF alone after /s/'      => $H . '/s/%0A',
	'encoded LF after /share/'        => $H . '/share/%0A' . $KEY,
	'encoded s and encoded LF'        => $H . '/%73/%0A' . $SLUG,
);

echo "\n== Round 3: percent-encoded LF after the share segment is share-shaped\n";
foreach ( $encoded as $label => $url ) {
	t_reset();
	t_configure();
	t_check( "looks_like_share: $label", true, C::looks_like_share( $url ) );

	$link = new L();
	$link->options = array( 'link_kind' => 'album' );
	$ok    = $link->validate( new E( $url ) );
	$err   = isset( $link->errors[0] ) ? $link->errors[0] : '';
	t_check( "validate refuses: $label", false, $ok );
	t_check( "validate sets one error: $label", 1, count( $link->errors ) );
	t_check( "validate uses share_not_converted text: $label", $REFUSE_TEXT, $err );
	t_not_contains( "validate error has no slug or key: $label", $SLUG, $err );
	t_not_contains( "validate error has no key: $label", $KEY, $err );
	t_not_contains( "validate error has no pasted URL: $label", $url, $err );
	t_not_contains( "validate error has no host: $label", 'immich.example.org', $err );
	t_check( "validate makes no HTTP call: $label", 0, count( $GLOBALS['test_http_log'] ) );

	t_reset();
	t_configure();
	$out = R::check( 'album', $url );
	t_check( "check code is bad_input: $label", 'tainacan_immich_bad_input', $out['code'] );
	t_check( "check message is the share text: $label", $SHARE_TEXT, $out['message'] );
	t_check( "check id is empty: $label", '', $out['id'] );
	t_check( "check makes no HTTP call: $label", 0, count( $GLOBALS['test_http_log'] ) );
	t_not_contains( "check output has no slug or key: $label", $SLUG, json_encode( $out ) );
	t_not_contains( "check output has no key: $label", $KEY, json_encode( $out ) );
	t_not_contains( "check output has no pasted URL: $label", $url, json_encode( $out ) );
	t_not_contains( "check output has no host: $label", 'immich.example.org', json_encode( $out ) );

	t_check( "parse_share gives array(): $label", array(), C::parse_share( $url ) );
}

t_summary();
