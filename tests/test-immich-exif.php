<?php
/**
 * Tests for EXIF helpers and the exif key of check(). Plain PHP, stubs only, no network.
 * Run: php tests/test-immich-exif.php
 */
require __DIR__ . '/stubs-immich.php';

use Tainacan\Immich_Client as C;
use Tainacan\Immich_Rest as R;
use Tainacan\Entities\Item_Metadata_Entity as E;
use Tainacan\Metadata_Types\Immich_Link as L;

$A = '11111111-2222-3333-4444-555555555555';

function t_x( $exif ) {
	return C::extract_exif( array( 'exifInfo' => $exif ) );
}

t_run( 'extract_exif full', function () {
	$r = t_x( array(
		'latitude'         => -23.5505,
		'longitude'        => -46.6333,
		'city'             => ' Sao Paulo ',
		'state'            => 'SP',
		'country'          => 'Brazil',
		'dateTimeOriginal' => '2024-05-01T10:00:00.000Z',
		'timeZone'         => 'UTC-3',
	) );
	t_check( 'key order', array( 'has_gps', 'lat', 'lon', 'map_url', 'null_island', 'city', 'state', 'country', 'date_taken', 'time_zone' ), array_keys( $r ) );
	t_check( 'has_gps', true, $r['has_gps'] );
	t_check( 'lat', '-23.5505', $r['lat'] );
	t_check( 'lon', '-46.6333', $r['lon'] );
	t_check( 'map', 'https://www.openstreetmap.org/?mlat=-23.5505&mlon=-46.6333#map=15/-23.5505/-46.6333', $r['map_url'] );
	t_check( 'null island', false, $r['null_island'] );
	t_check( 'city trimmed', 'Sao Paulo', $r['city'] );
	t_check( 'state', 'SP', $r['state'] );
	t_check( 'country', 'Brazil', $r['country'] );
	t_check( 'date', '2024-05-01T10:00:00.000Z', $r['date_taken'] );
	t_check( 'tz', 'UTC-3', $r['time_zone'] );
} );

t_run( 'extract_exif null', function () {
	t_check( 'not array', null, C::extract_exif( 'x' ) );
	t_check( 'null', null, C::extract_exif( null ) );
	t_check( 'no exifInfo', null, C::extract_exif( array( 'id' => 'x' ) ) );
	t_check( 'exifInfo null', null, C::extract_exif( array( 'exifInfo' => null ) ) );
	t_check( 'exifInfo string', null, C::extract_exif( array( 'exifInfo' => 'x' ) ) );
} );

t_run( 'extract_exif gps validity', function () {
	$no = function ( $label, $exif ) {
		$r = t_x( $exif );
		t_check( $label, false, $r['has_gps'] );
		t_check( $label . ' lat empty', '', $r['lat'] );
		t_check( $label . ' lon empty', '', $r['lon'] );
		t_check( $label . ' map empty', '', $r['map_url'] );
		t_check( $label . ' null island', false, $r['null_island'] );
	};
	$no( 'lat null', array( 'latitude' => null, 'longitude' => 10.5 ) );
	$no( 'lon null', array( 'latitude' => 10.5, 'longitude' => null ) );
	$no( 'both null', array( 'latitude' => null, 'longitude' => null ) );
	$no( 'only lat', array( 'latitude' => 10.5 ) );
	$no( 'lat 90.000001', array( 'latitude' => 90.000001, 'longitude' => 0 ) );
	$no( 'lat -91', array( 'latitude' => -91, 'longitude' => 0 ) );
	$no( 'lon 180.5', array( 'latitude' => 0, 'longitude' => 180.5 ) );
	$no( 'lon -181', array( 'latitude' => 0, 'longitude' => -181 ) );
	$no( 'string', array( 'latitude' => '12.5', 'longitude' => 10 ) );
	$no( 'bool', array( 'latitude' => true, 'longitude' => 10 ) );
	$no( 'array', array( 'latitude' => array(), 'longitude' => 10 ) );
	$no( 'nan', array( 'latitude' => NAN, 'longitude' => 10 ) );
	$no( 'inf', array( 'latitude' => INF, 'longitude' => 10 ) );
	$no( 'lon inf', array( 'latitude' => 10, 'longitude' => -INF ) );
	$r = t_x( array( 'latitude' => 90, 'longitude' => -180 ) );
	t_check( 'bounds valid', true, $r['has_gps'] );
	t_check( 'bounds lat', '90', $r['lat'] );
	t_check( 'bounds lon', '-180', $r['lon'] );
	$r = t_x( array( 'latitude' => 0, 'longitude' => 0 ) );
	t_check( '0,0 gps', true, $r['has_gps'] );
	t_check( '0,0 null island', true, $r['null_island'] );
	$r = t_x( array( 'latitude' => 0.0, 'longitude' => 1 ) );
	t_check( 'lat 0 only not null island', false, $r['null_island'] );
} );

t_run( 'extract_exif strings', function () {
	$r = t_x( array( 'city' => 5, 'state' => array( 'x' ), 'country' => null, 'dateTimeOriginal' => 12, 'timeZone' => array() ) );
	t_check( 'city int', '', $r['city'] );
	t_check( 'state array', '', $r['state'] );
	t_check( 'country null', '', $r['country'] );
	t_check( 'date int', '', $r['date_taken'] );
	t_check( 'tz array', '', $r['time_zone'] );
	t_check( 'no gps with place only', false, $r['has_gps'] );
	$r = t_x( array( 'city' => str_repeat( 'a', 300 ), 'dateTimeOriginal' => str_repeat( 'b', 100 ), 'timeZone' => str_repeat( 'c', 100 ) ) );
	t_check( 'city cut', 200, strlen( $r['city'] ) );
	t_check( 'date cut', 64, strlen( $r['date_taken'] ) );
	t_check( 'tz cut', 64, strlen( $r['time_zone'] ) );
	$r = t_x( array( 'city' => '<b>x</b>' ) );
	t_check( 'html kept as text', '<b>x</b>', $r['city'] );
	t_check( 'date spaces blank', '', t_x( array( 'dateTimeOriginal' => '   ' ) )['date_taken'] );
	t_check( 'date empty blank', '', t_x( array( 'dateTimeOriginal' => '' ) )['date_taken'] );
	t_check( 'date whitespace chars blank', '', t_x( array( 'dateTimeOriginal' => " \t\n\r " ) )['date_taken'] );
	t_check( 'date padded trimmed', '2024-05-01T10:00:00.000Z', t_x( array( 'dateTimeOriginal' => ' 2024-05-01T10:00:00.000Z ' ) )['date_taken'] );
	t_check( 'date trim before cut', 'x', t_x( array( 'dateTimeOriginal' => str_repeat( ' ', 70 ) . 'x' ) )['date_taken'] );
	t_check( 'date missing key blank', '', t_x( array( 'city' => 'x' ) )['date_taken'] );
	t_check( 'date null blank', '', t_x( array( 'dateTimeOriginal' => null ) )['date_taken'] );
	t_check( 'date nbsp only unchanged (out of scope)', "\xC2\xA0", t_x( array( 'dateTimeOriginal' => "\xC2\xA0" ) )['date_taken'] );
	t_check( 'tz spaces unchanged (out of scope)', '   ', t_x( array( 'timeZone' => '   ' ) )['time_zone'] );
} );

t_run( 'format_coord', function () {
	t_check( 'neg', '-23.5505', C::format_coord( -23.5505 ) );
	t_check( 'int', '12', C::format_coord( 12.0 ) );
	t_check( 'small', '0.00001', C::format_coord( 0.00001 ) );
	t_check( 'neg zero', '0', C::format_coord( -0.0 ) );
	t_check( 'tiny neg', '0', C::format_coord( -0.00000000001 ) );
	t_check( 'lon 151.2093', '151.2093', C::format_coord( 151.2093 ) );
	t_check( 'lon 151.20929551234', '151.2092955123', C::format_coord( 151.20929551234 ) );
	t_check( 'near 180', '-180', C::format_coord( -179.99999999999 ) );
	t_check( 'decimals', '12.123456789', C::format_coord( 12.123456789012 ) );
} );

t_run( 'locale independence', function () {
	// Part a: static proof. Needs no installed locale.
	$src = array();
	foreach ( array( 'format_coord', 'extract_exif' ) as $m ) {
		$rm    = new ReflectionMethod( C::class, $m );
		$lines = file( $rm->getFileName() );
		$code  = implode( '', array_slice( $lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1 ) );
		$clean = '';
		foreach ( token_get_all( '<?php ' . $code ) as $tok ) {
			if ( is_array( $tok ) ) {
				if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) {
					continue;
				}
				$clean .= $tok[1];
			} else {
				$clean .= $tok;
			}
		}
		$src[ $m ] = $clean;
	}
	$banned = array( 'setlocale', 'localeconv', 'nl_langinfo', 'sprintf', 'printf', 'vsprintf', 'number_format_i18n', 'strftime', 'strval', 'settype', 'NumberFormatter' );
	foreach ( $src as $m => $code ) {
		foreach ( $banned as $fn ) {
			t_check( "static $m no $fn", 0, preg_match( '/\b' . $fn . '\s*\(/i', $code ) );
		}
		t_check( "static $m no (string) cast", 0, preg_match( '/\(\s*string\s*\)/i', $code ) );
	}
	t_contains( 'static format_coord explicit separators', "number_format( \$value, 10, '.', '' )", $src['format_coord'] );
	$map_line = '';
	foreach ( explode( "\n", $src['extract_exif'] ) as $line ) {
		if ( false !== strpos( $line, "'map_url'" ) ) {
			$map_line = $line;
			break;
		}
	}
	$after = substr( $map_line, (int) strpos( $map_line, '=>' ) + 2 );
	preg_match_all( '/\$\w+/', $after, $vars );
	$extra = array_values( array_diff( array_unique( $vars[0] ), array( '$gps', '$lat', '$lon' ) ) );
	t_check( 'static map_url line found', true, '' !== $map_line );
	t_check( 'static map_url uses only gps/lat/lon', array(), $extra );

	// Part b: runtime loop with any installed locale.
	$old   = setlocale( LC_ALL, 0 );
	$ran   = array();
	$comma = array();
	foreach ( array( 'de_DE.UTF-8', 'de_DE', 'pt_BR.UTF-8', 'pt_BR', 'fr_FR.UTF-8', 'ru_RU.UTF-8', 'C.UTF-8', 'C' ) as $loc ) {
		if ( false === setlocale( LC_ALL, $loc ) ) {
			continue;
		}
		$ran[] = $loc;
		if ( ',' === localeconv()['decimal_point'] ) {
			$comma[] = $loc;
		}
		t_check( 'locale ' . $loc, '-23.5505', C::format_coord( -23.5505 ) );
		t_check( 'locale map_url ' . $loc, 'https://www.openstreetmap.org/?mlat=1.5&mlon=2.5#map=15/1.5/2.5', t_x( array( 'latitude' => 1.5, 'longitude' => 2.5 ) )['map_url'] );
	}
	setlocale( LC_ALL, $old );
	t_check( 'locale restored after loop', $old, setlocale( LC_ALL, 0 ) );
	if ( $comma ) {
		echo 'NOTE  locale loop ran with comma-decimal locale(s): ' . implode( ', ', $comma ) . "\n";
	} elseif ( $ran ) {
		echo 'NOTE  no comma-decimal locale is installed; runtime loop ran only with: ' . implode( ', ', $ran ) . "; the static check is the proof\n";
	} else {
		echo "NOTE  no candidate locale is installed; runtime loop did not run; the static check is the proof\n";
	}
} );

t_run( 'check exif key', function () use ( $A ) {
	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg', 'exifInfo' => array( 'latitude' => -23.5505, 'longitude' => -46.6333 ) ) ) );
	$r = R::check( 'asset', $A );
	t_check( 'asset gps', true, $r['exif']['has_gps'] );
	t_check( 'one call', 1, count( $GLOBALS['test_http_log'] ) );

	t_reset();
	t_configure();
	t_queue( 200, json_encode( array( 'id' => $A, 'originalFileName' => 'a.jpg' ) ) );
	t_check( 'asset no exifInfo', null, R::check( 'asset', $A )['exif'] );

	foreach ( array( 'album', 'person' ) as $kind ) {
		t_reset();
		t_configure();
		t_queue( 200, json_encode( array( 'id' => $A, 'albumName' => 'x', 'name' => 'x', 'exifInfo' => array( 'latitude' => 1, 'longitude' => 1 ) ) ) );
		$r = R::check( $kind, $A );
		t_check( $kind . ' ok', true, $r['ok'] );
		t_check( $kind . ' exif null', null, $r['exif'] );
	}

	t_reset();
	t_configure();
	t_queue( 401, '{}' );
	t_check( '401 null', null, R::check( 'asset', $A )['exif'] );
	t_queue( 404, '{}' );
	t_check( '404 null', null, R::check( 'asset', $A )['exif'] );
	t_reset();
	t_configure();
	t_check( 'bad input null', null, R::check( 'asset', 'junk' )['exif'] );
	t_check( 'bad input no call', 0, count( $GLOBALS['test_http_log'] ) );
	t_queue( 200, json_encode( array( 'id' => $A, 'isTrashed' => true, 'exifInfo' => array( 'latitude' => 1, 'longitude' => 1 ) ) ) );
	$r = R::check( 'asset', $A );
	t_check( 'trashed code', 'tainacan_immich_trashed', $r['code'] );
	t_check( 'trashed null', null, $r['exif'] );
} );

t_run( 'public html has no exif', function () use ( $A ) {
	t_reset();
	t_configure();
	$t = new L();
	$t->options['link_kind'] = 'asset';
	$html = $t->get_value_as_html( new E( $A ) );
	t_not_contains( 'no osm', 'openstreetmap', strtolower( $html ) );
	t_check( 'no http', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_summary();
