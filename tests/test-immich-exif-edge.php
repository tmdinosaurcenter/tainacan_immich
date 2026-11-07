<?php
/**
 * Edge-case tests for EXIF helpers and the exif key of check(). Plain PHP, stubs only, no network.
 * Covers spec edge cases 1 to 12 that test-immich-exif.php does not cover.
 * Run: php tests/test-immich-exif-edge.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;

$A    = '11111111-2222-3333-4444-555555555555';
$KEYS = array( 'has_gps', 'lat', 'lon', 'map_url', 'null_island', 'city', 'state', 'country', 'date_taken', 'time_zone' );

function ex_x( $exif ) {
	return C::extract_exif( array( 'exifInfo' => $exif ) );
}

t_run( 'edge 3: no GPS keeps place and date', function () {
	$r = ex_x( array( 'latitude' => null, 'longitude' => null, 'city' => 'Paris', 'state' => 'IDF', 'country' => 'France', 'dateTimeOriginal' => '2020-01-02T03:04:05.000Z', 'timeZone' => 'UTC+1' ) );
	t_check( 'no gps', false, $r['has_gps'] );
	t_check( 'place kept city', 'Paris', $r['city'] );
	t_check( 'place kept state', 'IDF', $r['state'] );
	t_check( 'place kept country', 'France', $r['country'] );
	t_check( 'date kept', '2020-01-02T03:04:05.000Z', $r['date_taken'] );
	t_check( 'tz kept', 'UTC+1', $r['time_zone'] );
	t_check( 'lat still empty', '', $r['lat'] );
} );

t_run( 'edge 4: only longitude valid', function () {
	$r = ex_x( array( 'latitude' => null, 'longitude' => -46.6333 ) );
	t_check( 'lon only has_gps false', false, $r['has_gps'] );
	t_check( 'lon only lon empty', '', $r['lon'] );
} );

t_run( 'edge 5: inclusive bounds at both ends', function () {
	$r = ex_x( array( 'latitude' => -90, 'longitude' => 180 ) );
	t_check( 'lat -90 lon 180 valid', true, $r['has_gps'] );
	t_check( 'lat -90 text', '-90', $r['lat'] );
	t_check( 'lon 180 text', '180', $r['lon'] );
	t_check( 'lat -90 map', 'https://www.openstreetmap.org/?mlat=-90&mlon=180#map=15/-90/180', $r['map_url'] );
	$r = ex_x( array( 'latitude' => -90.0, 'longitude' => -180.0 ) );
	t_check( 'lat -90.0 lon -180.0 valid', true, $r['has_gps'] );
	t_check( 'lon -180.0 text', '-180', $r['lon'] );
	$r = ex_x( array( 'latitude' => -90.000001, 'longitude' => 0 ) );
	t_check( 'lat -90.000001 invalid', false, $r['has_gps'] );
	$r = ex_x( array( 'latitude' => 0, 'longitude' => -180.000001 ) );
	t_check( 'lon -180.000001 invalid', false, $r['has_gps'] );
} );

t_run( 'edge 6: int accepted, numeric strings and other types rejected', function () {
	$r = ex_x( array( 'latitude' => 12, 'longitude' => -46 ) );
	t_check( 'int gps valid', true, $r['has_gps'] );
	t_check( 'int lat text', '12', $r['lat'] );
	t_check( 'int lon text', '-46', $r['lon'] );
	t_check( 'int map', 'https://www.openstreetmap.org/?mlat=12&mlon=-46#map=15/12/-46', $r['map_url'] );
	foreach ( array(
		'empty string'   => array( 'latitude' => '', 'longitude' => 10 ),
		'zero string'    => array( 'latitude' => '0', 'longitude' => 0 ),
		'lon string'     => array( 'latitude' => 10, 'longitude' => '-46.6' ),
		'lon bool false' => array( 'latitude' => 10, 'longitude' => false ),
		'lon array'      => array( 'latitude' => 10, 'longitude' => array( 1 ) ),
		'lat object'     => array( 'latitude' => new stdClass(), 'longitude' => 10 ),
	) as $label => $exif ) {
		$r = ex_x( $exif );
		t_check( 'wrong type ' . $label, false, $r['has_gps'] );
		t_check( 'wrong type lat empty ' . $label, '', $r['lat'] );
		t_check( 'wrong type map empty ' . $label, '', $r['map_url'] );
	}
} );

t_run( 'edge 7: null island details', function () {
	$r = ex_x( array( 'latitude' => 0, 'longitude' => 0 ) );
	t_check( 'null island map', 'https://www.openstreetmap.org/?mlat=0&mlon=0#map=15/0/0', $r['map_url'] );
	$r = ex_x( array( 'latitude' => -0.0, 'longitude' => 0 ) );
	t_check( '-0.0 lat is null island', true, $r['null_island'] );
	t_check( '-0.0 lat text', '0', $r['lat'] );
	$r = ex_x( array( 'latitude' => 0.0, 'longitude' => 0.0000000000000001 ) );
	t_check( 'tiny lon not null island', false, $r['null_island'] );
	t_check( 'tiny lon still gps', true, $r['has_gps'] );
} );

t_run( 'edge 8: negative and many decimals', function () {
	$r = ex_x( array( 'latitude' => -33.86881234567, 'longitude' => 151.20929551234 ) );
	t_check( 'many decimals lat', '-33.8688123457', $r['lat'] );
	t_check( 'many decimals lon', '151.2092955123', $r['lon'] );
	t_check( 'many decimals map', 'https://www.openstreetmap.org/?mlat=-33.8688123457&mlon=151.2092955123#map=15/-33.8688123457/151.2092955123', $r['map_url'] );
	t_check( 'many decimals no exponent', false, strpos( $r['lat'] . $r['lon'], 'E' ) !== false || strpos( $r['lat'] . $r['lon'], 'e' ) !== false );
	t_check( 'format neg 90', '-90', C::format_coord( -90.0 ) );
	t_check( 'format 100 int-like', '100', C::format_coord( 100.0 ) );
	t_check( 'format -0.5', '-0.5', C::format_coord( -0.5 ) );
	t_check( 'format tiny rounds to 0', '0', C::format_coord( -1.0E-15 ) );
	t_check( 'format 1e-14', '0', C::format_coord( 1.0E-14 ) );
	t_check( 'format 179.99999999999', '180', C::format_coord( 179.99999999999 ) );
} );

t_run( 'edge 9: non-string and long text fields', function () {
	$r = ex_x( array( 'city' => true, 'state' => 1.5, 'country' => '   ', 'dateTimeOriginal' => false, 'timeZone' => 7 ) );
	t_check( 'city bool', '', $r['city'] );
	t_check( 'state float', '', $r['state'] );
	t_check( 'country whitespace', '', $r['country'] );
	t_check( 'date bool', '', $r['date_taken'] );
	t_check( 'tz int', '', $r['time_zone'] );
	$r = ex_x( array( 'city' => str_repeat( 'é', 300 ), 'dateTimeOriginal' => str_repeat( 'ü', 100 ) ) );
	t_check( 'multibyte city 200 chars', 200, mb_strlen( $r['city'], 'UTF-8' ) );
	t_check( 'multibyte date 64 chars', 64, mb_strlen( $r['date_taken'], 'UTF-8' ) );
	$r = ex_x( array( 'city' => '<img src=x onerror=alert(1)>' ) );
	t_check( 'html stays raw text in data', '<img src=x onerror=alert(1)>', $r['city'] );
	$r = ex_x( array( 'city' => str_repeat( ' ', 5 ) . 'Rio' . str_repeat( ' ', 5 ) ) );
	t_check( 'city padded trimmed', 'Rio', $r['city'] );
} );

t_run( 'extract_exif: exifInfo empty array and wrong top types', function () use ( $KEYS ) {
	$r = ex_x( array() );
	t_check( 'empty exifInfo is array', true, is_array( $r ) );
	t_check( 'empty exifInfo keys', $KEYS, array_keys( $r ) );
	t_check( 'empty exifInfo no gps', false, $r['has_gps'] );
	t_check( 'exifInfo true -> null', null, C::extract_exif( array( 'exifInfo' => true ) ) );
	t_check( 'exifInfo int -> null', null, C::extract_exif( array( 'exifInfo' => 5 ) ) );
	t_check( 'info int -> null', null, C::extract_exif( 5 ) );
	t_check( 'info empty string -> null', null, C::extract_exif( '' ) );
} );

t_run( 'edge 1: bad input for each kind, no HTTP call', function () use ( $A ) {
	foreach ( array( 'asset', 'album', 'person' ) as $kind ) {
		t_reset();
		t_configure();
		$r = R::check( $kind, 'not an id' );
		t_check( $kind . ' bad input code', 'tainacan_immich_bad_input', $r['code'] );
		t_check( $kind . ' bad input exif null', null, $r['exif'] );
		t_check( $kind . ' bad input no call', 0, count( $GLOBALS['test_http_log'] ) );
	}
	t_reset();
	t_configure();
	t_check( 'empty input exif null', null, R::check( 'asset', '' )['exif'] );
	t_check( 'empty input no call', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'edge 11: Immich errors give exif null and no secret in message', function () use ( $A ) {
	$cases = array(
		'403' => array( 'queue' => array( 403, '{}' ), 'code' => 'tainacan_immich_forbidden' ),
		'400' => array( 'queue' => array( 400, '{}' ), 'code' => 'tainacan_immich_not_found' ),
		'500' => array( 'queue' => array( 500, '{}' ), 'code' => 'tainacan_immich_server_error' ),
		'bad json' => array( 'queue' => array( 200, 'not json' ), 'code' => 'tainacan_immich_bad_response' ),
		'json without id' => array( 'queue' => array( 200, '{"exifInfo":{"latitude":1,"longitude":1}}' ), 'code' => 'tainacan_immich_bad_response' ),
	);
	foreach ( $cases as $label => $c ) {
		t_reset();
		t_configure();
		t_queue( $c['queue'][0], $c['queue'][1] );
		$r = R::check( 'asset', $A );
		t_check( $label . ' ok false', false, $r['ok'] );
		t_check( $label . ' code', $c['code'], $r['code'] );
		t_check( $label . ' exif null', null, $r['exif'] );
		t_not_contains( $label . ' no url in message', 'immich.example.org', $r['message'] );
		t_not_contains( $label . ' no key in message', 'fake-key-abc123', $r['message'] );
	}
	t_reset();
	t_configure();
	t_queue_error();
	$r = R::check( 'asset', $A );
	t_check( 'unreachable code', 'tainacan_immich_unreachable', $r['code'] );
	t_check( 'unreachable exif null', null, $r['exif'] );
	t_not_contains( 'unreachable no url', 'immich.example.org', $r['message'] );
	t_not_contains( 'unreachable no key', 'fake-key-abc123', $r['message'] );
	t_reset();
	t_check( 'not configured exif null', null, R::check( 'asset', $A )['exif'] );
	t_check( 'not configured code', 'tainacan_immich_not_configured', R::check( 'asset', $A )['code'] );
	t_check( 'not configured no call', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( 'edge 10: album and person answers keep exif null with one call each', function () use ( $A ) {
	foreach ( array( 'album' => 'albumName', 'person' => 'name' ) as $kind => $field ) {
		t_reset();
		t_configure();
		t_queue( 200, json_encode( array( 'id' => $A, $field => 'x', 'exifInfo' => array( 'latitude' => 5, 'longitude' => 5 ) ) ) );
		$r = R::check( $kind, $A );
		t_check( $kind . ' exif null with gps in data', null, $r['exif'] );
		t_check( $kind . ' one call', 1, count( $GLOBALS['test_http_log'] ) );
	}
} );

t_run( 'check asset: exif shape and JSON output', function () use ( $A, $KEYS ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array(
		'id'               => $A,
		'originalFileName' => 'beach.jpg',
		'exifInfo'         => array(
			'latitude'         => -33.8688,
			'longitude'        => 151.2093,
			'city'             => 'Sydney',
			'dateTimeOriginal' => '2023-12-25T08:00:00.000Z',
		),
	) ) );
	$r = R::check( 'asset', $A );
	t_check( 'asset ok', true, $r['ok'] );
	t_check( 'asset exif keys', $KEYS, array_keys( $r['exif'] ) );
	t_check( 'asset exif lat', '-33.8688', $r['exif']['lat'] );
	t_check( 'asset exif lon', '151.2093', $r['exif']['lon'] );
	t_check( 'asset exif city', 'Sydney', $r['exif']['city'] );
	t_check( 'asset exif date', '2023-12-25T08:00:00.000Z', $r['exif']['date_taken'] );
	$json = json_decode( json_encode( $r ), true );
	t_check( 'json exif has_gps', true, $json['exif']['has_gps'] );
	t_check( 'json exif map', 'https://www.openstreetmap.org/?mlat=-33.8688&mlon=151.2093#map=15/-33.8688/151.2093', $json['exif']['map_url'] );
	t_check( 'json no api key', false, strpos( json_encode( $r ), 'fake-key-abc123' ) !== false );
	t_check( 'json no immich url', false, strpos( json_encode( $r ), 'immich.example.org' ) !== false );
} );

t_run( 'check asset: exifInfo null shows null exif in JSON', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg', 'exifInfo' => null ) ) );
	$r = R::check( 'asset', $A );
	t_check( 'exifInfo null ok', true, $r['ok'] );
	t_check( 'exifInfo null exif null', null, $r['exif'] );
	t_check( 'json exif null', true, strpos( json_encode( $r ), '"exif":null' ) !== false );
} );

t_summary();
