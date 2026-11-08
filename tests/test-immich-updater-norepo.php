<?php
/**
 * Repo off switches for \Tainacan\Immich_Updater (spec edge 18): repo constant undefined, '',
 * or invalid. A constant cannot be undefined or changed inside one PHP process, so each case runs
 * in its own child process with the constant set (or not set) before the updater loads.
 * Plain PHP, stubs only, no network.
 * Run: php tests/test-immich-updater-norepo.php
 */

// Mode name => value of TAINACAN_IMMICH_UPDATE_REPO (null = not defined).
$modes = array(
	'undefined' => null,
	'empty'     => '',
	'three'     => 'a/b/c',
	'dots'      => '../x',
	'url'       => 'https://github.com/a/b',
	'space'     => 'my owner/repo',
	'owner-dot'    => 'owner/.',
	'owner-dotdot' => 'owner/..',
);

// ---- Parent: run every mode in a child process ----------------------------------------------

if ( ! isset( $argv[1] ) ) {
	$failed_modes = 0;
	foreach ( array_keys( $modes ) as $mode ) {
		$out  = array();
		$code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $mode ) . ' 2>&1', $out, $code );
		echo "\n== child process, repo mode: $mode (exit $code)\n";
		foreach ( $out as $line ) {
			echo '    ' . $line . "\n";
		}
		if ( 0 !== $code ) {
			$failed_modes++;
		}
	}
	echo "\n== Summary: " . ( count( $modes ) - $failed_modes ) . ' of ' . count( $modes ) . " repo modes passed\n";
	exit( $failed_modes > 0 ? 1 : 0 );
}

// ---- Child: one repo mode ----------------------------------------------------------------------

$mode = $argv[1];
if ( ! array_key_exists( $mode, $modes ) ) {
	echo "FAIL  unknown mode $mode\n";
	exit( 1 );
}

require __DIR__ . '/stubs-immich.php';

if ( null !== $modes[ $mode ] ) {
	define( 'TAINACAN_IMMICH_UPDATE_REPO', $modes[ $mode ] );
}

require __DIR__ . '/../tainacan-immich/class-tainacan-immich-updater.php';

use Tainacan\Immich_Updater as U;

// Only what the disabled paths can reach. Any call to these would show as a failure.
$GLOBALS['test_site_transients'] = array();
function apply_filters( $hook, $value ) {
	$GLOBALS['test_filter_calls'][] = $hook;
	return $value;
}
function get_site_transient( $key ) {
	$GLOBALS['test_transient_reads'][] = $key;
	return false;
}
function set_site_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['test_transient_writes'][] = $key;
	return true;
}
$GLOBALS['test_filter_calls']     = array();
$GLOBALS['test_transient_reads']  = array();
$GLOBALS['test_transient_writes'] = array();

function nr_transient() {
	$t            = new \stdClass();
	$t->checked   = array( U::BASENAME => '0.1.0' );
	$t->response  = array();
	$t->no_update = array();
	return $t;
}

t_run( "Repo mode $mode: repo is empty and the check is off", function () {
	t_check( 'get_repo is empty string', '', U::get_repo() );
	t_check( 'is_enabled false', false, U::is_enabled() );
	t_check( 'get_release null', null, U::get_release() );
	t_check( 'no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( "Repo mode $mode: update filter returns the transient unchanged, no request", function () {
	$t    = nr_transient();
	$same = U::filter_update_transient( $t );
	t_check( 'same object', true, $same === $t );
	t_check( 'no response entry', array(), $t->response );
	t_check( 'no no_update entry', array(), $t->no_update );
	t_check( 'no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( "Repo mode $mode: plugins_api returns the result unchanged, no request", function () {
	$res = U::filter_plugins_api( 'untouched', 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) );
	t_check( 'result unchanged', 'untouched', $res );
	t_check( 'no HTTP call', 0, count( $GLOBALS['test_http_log'] ) );
} );

t_run( "Repo mode $mode: no site transient is read or written", function () {
	U::filter_update_transient( nr_transient() );
	t_check( 'no transient read', 0, count( $GLOBALS['test_transient_reads'] ) );
	t_check( 'no transient write', 0, count( $GLOBALS['test_transient_writes'] ) );
} );

t_run( "Repo mode $mode: the filter is not even asked when repo is disabled", function () {
	U::is_enabled();
	t_check( 'apply_filters not called', 0, count( $GLOBALS['test_filter_calls'] ) );
} );

t_summary();
