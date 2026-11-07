<?php
/**
 * Tester checks for the EXIF helpers. Plain PHP, stubs only, no network.
 * Adds a seeded property sweep for format_coord(), JSON-decoded edge values,
 * a list-shaped exifInfo, long multibyte text and the REST handler output.
 * Run: php tests/test-immich-exif-tester.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;

$A    = '11111111-2222-3333-4444-555555555555';
$KEYS = array( 'has_gps', 'lat', 'lon', 'map_url', 'null_island', 'city', 'state', 'country', 'date_taken', 'time_zone' );

/**
 * Expected text for k / 10^7, built from integers only (no float).
 */
function tt_expected_7dp( $k ) {
	$sign = $k < 0 ? '-' : '';
	$a    = abs( $k );
	$int  = intdiv( $a, 10000000 );
	$frac = $a % 10000000;
	if ( 0 === $frac ) {
		return $sign . $int;
	}
	return $sign . $int . '.' . rtrim( str_pad( (string) $frac, 7, '0', STR_PAD_LEFT ), '0' );
}

t_run( 'format_coord: seeded sweep of 7-decimal values, no float noise', function () {
	mt_srand( 42 );
	$bad  = 0;
	$first_bad = '';
	for ( $i = 0; $i < 3000; $i++ ) {
		$k    = mt_rand( -1800000000, 1800000000 );
		$want = tt_expected_7dp( $k );
		$got  = C::format_coord( $k / 10000000 );
		if ( $want !== $got ) {
			$bad++;
			if ( '' === $first_bad ) {
				$first_bad = "k=$k want=$want got=$got";
			}
		}
	}
	t_check( 'sweep mismatches (first: ' . $first_bad . ')', 0, $bad );
} );

t_run( 'format_coord: output shape for random doubles', function () {
	mt_srand( 7 );
	$shape_bad = 0;
	$first     = '';
	for ( $i = 0; $i < 3000; $i++ ) {
		$v = ( mt_rand() / mt_getrandmax() ) * 360 - 180;
		if ( 0 === $i % 3 ) {
			$v = $v * 1e-6;
		}
		$t = C::format_coord( $v );
		$ok = 1 === preg_match( '/^-?(0|[1-9][0-9]*)(\.[0-9]*[1-9])?$/', $t ) && '-0' !== $t;
		if ( $ok ) {
			$ok = abs( (float) $t - $v ) <= 5.0e-11 + 1.0e-12;
		}
		if ( ! $ok ) {
			$shape_bad++;
			if ( '' === $first ) {
				$first = var_export( $v, true ) . ' -> ' . $t;
			}
		}
	}
	t_check( 'shape/round-trip mismatches (first: ' . $first . ')', 0, $shape_bad );
} );

t_run( 'format_coord: values of 128 or more', function () {
	t_check( '128.5', '128.5', C::format_coord( 128.5 ) );
	t_check( '128.0000001', '128.0000001', C::format_coord( 128.0000001 ) );
	t_check( '-128.1234567891234', '-128.1234567891', C::format_coord( -128.1234567891234 ) );
	t_check( '139.6917', '139.6917', C::format_coord( 139.6917 ) );
	t_check( '179.5', '179.5', C::format_coord( 179.5 ) );
	t_check( '150.1', '150.1', C::format_coord( 150.1 ) );
	t_check( '1e-300 is 0', '0', C::format_coord( 1.0E-300 ) );
	t_check( '89.99999999999 is 90', '90', C::format_coord( 89.99999999999 ) );
} );

t_run( 'extract_exif: trailing zero trim in map URL', function () {
	$r = C::extract_exif( array( 'exifInfo' => array( 'latitude' => 10.50, 'longitude' => 0.1 ) ) );
	t_check( 'lat 10.50 -> 10.5', '10.5', $r['lat'] );
	t_check( 'lon 0.1 -> 0.1', '0.1', $r['lon'] );
	t_check( 'map with trimmed', 'https://www.openstreetmap.org/?mlat=10.5&mlon=0.1#map=15/10.5/0.1', $r['map_url'] );
	t_check( 'not null island', false, $r['null_island'] );
} );

t_run( 'extract_exif: JSON-decoded values (1e400 is INF, 12.0 is float)', function () use ( $KEYS ) {
	$info = json_decode( '{"exifInfo":{"latitude":1e400,"longitude":1}}', true );
	$r    = C::extract_exif( $info );
	t_check( '1e400 lat rejected', false, $r['has_gps'] );
	t_check( '1e400 keys', $KEYS, array_keys( $r ) );

	$info = json_decode( '{"exifInfo":{"latitude":12.0,"longitude":-0.0}}', true );
	$r    = C::extract_exif( $info );
	t_check( 'json 12.0 has gps', true, $r['has_gps'] );
	t_check( 'json 12.0 lat text', '12', $r['lat'] );
	t_check( 'json -0.0 lon text', '0', $r['lon'] );
	t_check( 'json 12.0,-0.0 not null island', false, $r['null_island'] );

	$info = json_decode( '{"exifInfo":{"latitude":0,"longitude":0.0}}', true );
	t_check( 'json int 0 and float 0.0 null island', true, C::extract_exif( $info )['null_island'] );

	$info = json_decode( '{"exifInfo":{"latitude":"12.5","longitude":"-46.6"}}', true );
	t_check( 'json numeric strings rejected', false, C::extract_exif( $info )['has_gps'] );
} );

t_run( 'extract_exif: list-shaped exifInfo and other odd shapes', function () use ( $KEYS ) {
	$r = C::extract_exif( array( 'exifInfo' => array( -23.5, -46.6 ) ) );
	t_check( 'list exifInfo has_gps false', false, $r['has_gps'] );
	t_check( 'list exifInfo keys', $KEYS, array_keys( $r ) );
	t_check( 'list exifInfo lat empty', '', $r['lat'] );
	t_check( 'info is object-like string', null, C::extract_exif( 'exifInfo' ) );
} );

t_run( 'extract_exif: long multibyte city after leading spaces', function () {
	$r = C::extract_exif( array( 'exifInfo' => array( 'city' => str_repeat( ' ', 5 ) . str_repeat( 'é', 250 ) ) ) );
	t_check( 'trim then cut: 200 chars', 200, mb_strlen( $r['city'], 'UTF-8' ) );
	t_check( 'trim then cut: first char is not space', 'é', mb_substr( $r['city'], 0, 1, 'UTF-8' ) );
	$r = C::extract_exif( array( 'exifInfo' => array( 'state' => str_repeat( 'x', 200 ) . 'yyy' ) ) );
	t_check( 'state cut at 200', str_repeat( 'x', 200 ), $r['state'] );
	$r = C::extract_exif( array( 'exifInfo' => array( 'timeZone' => '+' . str_repeat( '0', 70 ) ) ) );
	t_check( 'tz cut at 64', 64, strlen( $r['time_zone'] ) );
} );

t_run( 'extract_exif: place and date with GPS and time zone', function () {
	$r = C::extract_exif( array( 'exifInfo' => array(
		'latitude'         => 48.8566,
		'longitude'        => 2.3522,
		'city'             => 'Paris',
		'state'            => '',
		'country'          => 'France',
		'dateTimeOriginal' => '2021-06-14T12:00:00.000+02:00',
		'timeZone'         => 'UTC+2',
	) ) );
	t_check( 'paris lat', '48.8566', $r['lat'] );
	t_check( 'paris lon', '2.3522', $r['lon'] );
	t_check( 'paris state empty', '', $r['state'] );
	t_check( 'paris country', 'France', $r['country'] );
	t_check( 'paris date as sent', '2021-06-14T12:00:00.000+02:00', $r['date_taken'] );
	t_check( 'paris tz', 'UTC+2', $r['time_zone'] );
} );

t_run( 'handle_check: REST answer has exif key with 10 EXIF fields', function () use ( $A, $KEYS ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'p.jpg', 'exifInfo' => array( 'latitude' => 1.25, 'longitude' => 2.5 ) ) ) );
	$req = new class() {
		public function get_param( $name ) {
			return array( 'kind' => 'asset', 'input' => '11111111-2222-3333-4444-555555555555' )[ $name ];
		}
	};
	$res = R::handle_check( $req );
	t_check( 'handle_check status', 200, $res->status );
	t_check( 'handle_check exif keys', $KEYS, array_keys( $res->data['exif'] ) );
	t_check( 'handle_check lat', '1.25', $res->data['exif']['lat'] );
	t_check( 'handle_check outer keys', array( 'ok', 'code', 'message', 'id', 'label', 'thumb', 'exif' ), array_keys( $res->data ) );
} );

t_run( 'check: exifInfo with GPS but isTrashed false still ok', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'isTrashed' => false, 'exifInfo' => array( 'latitude' => 3, 'longitude' => 4 ) ) ) );
	$r = R::check( 'asset', $A );
	t_check( 'not trashed ok', true, $r['ok'] );
	t_check( 'not trashed exif has gps', true, $r['exif']['has_gps'] );
	t_check( 'one call', 1, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'check: request URL is GET assets path only, no secret in URL', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg', 'exifInfo' => array( 'latitude' => 3, 'longitude' => 4 ) ) ) );
	R::check( 'asset', $A );
	$call = $GLOBALS['test_http_log'][0];
	t_check( 'url is asset info', 'https://immich.example.org/api/assets/' . $A, $call[0] );
	t_check( 'method is GET (no method arg)', false, isset( $call[1]['method'] ) && 'GET' !== $call[1]['method'] );
	t_not_contains( 'key not in url', 'fake-key-abc123', $call[0] );
	t_check( 'key sent only in header', 'fake-key-abc123', $call[1]['headers']['x-api-key'] );
} );

t_run( 'public item HTML: no EXIF and no HTTP for a linked asset', function () use ( $A ) {
	t_reset();
	t_configure();
	$t = new \Tainacan\Metadata_Types\Immich_Link();
	$t->options['link_kind'] = 'asset';
	$html = $t->get_value_as_html( new \Tainacan\Entities\Item_Metadata_Entity( $A ) );
	t_not_contains( 'no lat in html', 'Location', $html );
	t_not_contains( 'no place in html', 'Place', $html );
	t_not_contains( 'no date in html', 'Date taken', $html );
	t_check( 'no http for public html', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_summary();
