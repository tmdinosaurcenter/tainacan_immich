<?php
/**
 * Tests for \Tainacan\Immich_Updater. Plain PHP, stubs only, no network.
 * All release data is fake. wp_remote_get is a fake: a call with no queued response throws,
 * so an unexpected HTTP call shows as a failure.
 * Repo off switches (empty, undefined, invalid repo) run in tests/test-immich-updater-norepo.php,
 * because a constant cannot be changed inside one PHP process.
 * Run: php tests/test-immich-updater.php
 */
require __DIR__ . '/stubs-immich.php';

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// ---- Extra WordPress stubs (only the updater uses these) ----------------------

$GLOBALS['test_filters']         = array();
$GLOBALS['test_apply_override']  = array();
$GLOBALS['test_site_transients'] = array();

function plugin_basename( $file ) {
	$file = str_replace( '\\', '/', (string) $file );
	if ( preg_match( '#/plugins/(.+)$#', $file, $m ) ) {
		return $m[1];
	}
	return basename( $file );
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['test_filters'][] = array(
		'hook'     => $hook,
		'callback' => $callback,
		'priority' => $priority,
		'args'     => $accepted_args,
	);
	return true;
}
function apply_filters( $hook, $value ) {
	if ( array_key_exists( $hook, $GLOBALS['test_apply_override'] ) ) {
		return $GLOBALS['test_apply_override'][ $hook ];
	}
	return $value;
}
function get_site_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['test_site_transients'] ) ? $GLOBALS['test_site_transients'][ $key ]['value'] : false;
}
function set_site_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['test_site_transients'][ $key ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);
	return true;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

// ---- Load the updater with the default repo (as the main plugin file does) ----

define( 'TAINACAN_IMMICH_UPDATE_REPO', 'tmdinosaurcenter/tainacan_immich' );
require __DIR__ . '/../tainacan-immich/class-tainacan-immich-updater.php';

use Tainacan\Immich_Updater as U;

// ---- Test helpers -------------------------------------------------------------

function u_repo() {
	return 'tmdinosaurcenter/tainacan_immich';
}

function u_reset() {
	t_reset();
	$GLOBALS['test_filters']         = array();
	$GLOBALS['test_apply_override']  = array();
	$GLOBALS['test_site_transients'] = array();
}

function u_zip( $tag = 'v0.2.0' ) {
	return 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/' . $tag . '/tainacan-immich.zip';
}

// Fake release JSON in the shape GitHub returns for /releases/latest.
function u_release( $tag = 'v0.2.0' ) {
	return array(
		'tag_name'     => $tag,
		'draft'        => false,
		'prerelease'   => false,
		'body'         => "Fake release notes line.\n* Fake bullet one\n* Fake bullet two\n\nFake closing line.",
		'html_url'     => 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/tag/' . $tag,
		'published_at' => '2026-10-01T10:00:00Z',
		'assets'       => array(
			array(
				'name'                 => 'tainacan-immich.zip',
				'state'                => 'uploaded',
				'browser_download_url' => u_zip( $tag ),
			),
		),
	);
}

function u_queue_ok( $data, $code = 200 ) {
	t_queue( $code, json_encode( $data ) );
}

function u_parse( $data ) {
	return U::parse_release( $data, u_repo() );
}

function u_transient( $installed = '0.1.0' ) {
	$t            = new \stdClass();
	$t->checked   = array( U::BASENAME => $installed );
	$t->response  = array();
	$t->no_update = array();
	return $t;
}

function u_clear_cache() {
	unset( $GLOBALS['test_site_transients'][ U::CACHE_KEY ] );
}

function u_http_count() {
	return count( $GLOBALS['test_http_log'] );
}

function u_cache_entry() {
	return $GLOBALS['test_site_transients'][ U::CACHE_KEY ];
}

$OPT_CHECK = \Tainacan\Immich::OPTION_UPDATE_CHECK;

// ---- Constants and repo ---------------------------------------------------------

t_run( 'Constants have the values from the spec', function () {
	t_check( 'BASENAME', 'tainacan-immich/tainacan-immich.php', U::BASENAME );
	t_check( 'SLUG', 'tainacan-immich', U::SLUG );
	t_check( 'ASSET_NAME', 'tainacan-immich.zip', U::ASSET_NAME );
	t_check( 'CACHE_KEY', 'tainacan_immich_update_release', U::CACHE_KEY );
	t_check( 'REPO_REGEX', '#^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$#', U::REPO_REGEX );
	t_check( 'VERSION_REGEX', '/^\d+\.\d+\.\d+$/', U::VERSION_REGEX );
	t_check( 'default repo constant', 'tmdinosaurcenter/tainacan_immich', TAINACAN_IMMICH_UPDATE_REPO );
	t_check( 'get_repo returns the constant', 'tmdinosaurcenter/tainacan_immich', U::get_repo() );
} );

t_run( 'Repo validation (edge 18, pure part): valid and invalid values', function () {
	t_check( 'valid repo', 'tmdinosaurcenter/tainacan_immich', U::validate_repo( 'tmdinosaurcenter/tainacan_immich' ) );
	t_check( 'surrounding spaces trimmed', 'owner/repo', U::validate_repo( "  owner/repo \n" ) );
	$bad = array(
		'three parts'        => 'a/b/c',
		'dot dot path'       => '../x',
		'full url'           => 'https://github.com/a/b',
		'space in owner'     => 'a b/c',
		'no owner'           => '/repo',
		'no repo'            => 'owner/',
		'no slash'           => 'ownerrepo',
		'owner 40 chars'     => str_repeat( 'a', 40 ) . '/x',
		'repo 101 chars'     => 'owner/' . str_repeat( 'a', 101 ),
		'null'               => null,
		'array'              => array( 'owner/repo' ),
		'int'                => 42,
		'empty'              => '',
	);
	foreach ( $bad as $label => $value ) {
		t_check( "invalid repo: $label", '', U::validate_repo( $value ) );
	}
	t_check( 'owner/. disabled', '', U::validate_repo( 'owner/.' ) );
	t_check( 'owner/.. disabled', '', U::validate_repo( 'owner/..' ) );
	t_check( 'a/b.c stays valid', 'a/b.c', U::validate_repo( 'a/b.c' ) );
	t_check( 'owner 39 chars accepted', str_repeat( 'a', 39 ) . '/x', U::validate_repo( str_repeat( 'a', 39 ) . '/x' ) );
	t_check( 'repo name 100 chars accepted', 'o/' . str_repeat( 'a', 100 ), U::validate_repo( 'o/' . str_repeat( 'a', 100 ) ) );
} );

// ---- Happy path, request shape ------------------------------------------------

t_run( 'Happy path: newer release gives an update item', function () {
	u_reset();
	u_queue_ok( u_release() );
	$t = U::filter_update_transient( u_transient( '0.1.0' ) );
	t_check( 'update in response', true, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'no_update entry not set', false, isset( $t->no_update[ U::BASENAME ] ) );
	t_check( 'exactly one HTTP call', 1, u_http_count() );
	$item = $t->response[ U::BASENAME ];
	t_check( 'update item is object', true, $item instanceof \stdClass );
	t_check( 'item id', 'https://github.com/tmdinosaurcenter/tainacan_immich', $item->id );
	t_check( 'item slug', 'tainacan-immich', $item->slug );
	t_check( 'item plugin', 'tainacan-immich/tainacan-immich.php', $item->plugin );
	t_check( 'item new_version', '0.2.0', $item->new_version );
	t_check( 'item url is html_url', 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/tag/v0.2.0', $item->url );
	t_check( 'item package', u_zip( 'v0.2.0' ), $item->package );
	t_check( 'item tested empty', '', $item->tested );
	t_check( 'item requires empty', '', $item->requires );
	t_check( 'item requires_php empty', '', $item->requires_php );
	t_check( 'item icons empty array', array(), $item->icons );
} );

t_run( 'Request shape: one GET, no auth, no body, fixed args', function () {
	u_reset();
	u_queue_ok( u_release() );
	U::get_release();
	t_check( 'exactly one call', 1, u_http_count() );
	$url  = $GLOBALS['test_http_log'][0][0];
	$args = $GLOBALS['test_http_log'][0][1];
	t_check( 'URL', 'https://api.github.com/repos/tmdinosaurcenter/tainacan_immich/releases/latest', $url );
	t_check( 'timeout 10', 10, $args['timeout'] );
	t_check( 'redirection 0', 0, $args['redirection'] );
	t_check( 'user-agent arg', 'tainacan-immich-updater', $args['user-agent'] );
	t_check( 'headers are Accept only', array( 'Accept' => 'application/vnd.github+json' ), $args['headers'] );
	t_check( 'no authorization header (any case)', false, in_array( 'authorization', array_map( 'strtolower', array_keys( $args['headers'] ) ), true ) );
	t_check( 'no body arg', false, array_key_exists( 'body', $args ) );
	t_check( 'no cookies arg', false, array_key_exists( 'cookies', $args ) );
	t_check( 'method is GET or unset', true, ! isset( $args['method'] ) || 'GET' === $args['method'] );
	t_check( 'limit_response_size is 1 MB', MB_IN_BYTES, $args['limit_response_size'] );
} );

// ---- Edge 1 and 2: tags -----------------------------------------------------------

t_run( 'Edge 1: tags normalize to the bare version', function () {
	t_check( 'v prefix', '0.2.0', U::normalize_version( 'v0.2.0' ) );
	t_check( 'V prefix', '0.2.0', U::normalize_version( 'V0.2.0' ) );
	t_check( 'no prefix', '0.2.0', U::normalize_version( '0.2.0' ) );
	t_check( 'surrounding spaces', '0.2.0', U::normalize_version( '  v0.2.0 ' ) );
} );

t_run( 'Edge 2: bad tags give empty string', function () {
	$bad = array(
		'empty string'  => '',
		'double v'      => 'vv0.2.0',
		'two parts'     => '0.2',
		'beta suffix'   => '0.2.0-beta',
		'word latest'   => 'latest',
		'null'          => null,
		'array'         => array( '0.2.0' ),
		'int'           => 2,
		'four parts'    => '1.2.3.4',
		'too long'      => '0.2.0' . str_repeat( ' ', 30 ),
	);
	foreach ( $bad as $label => $tag ) {
		t_check( "bad tag: $label", '', U::normalize_version( $tag ) );
	}
} );

t_run( 'Edge 2: bad tag gives no update and no change to the transient', function () {
	u_reset();
	u_queue_ok( u_release( 'vv0.2.0' ) );
	$t = U::filter_update_transient( u_transient( '0.1.0' ) );
	t_check( 'no response entry', false, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'no no_update entry', false, isset( $t->no_update[ U::BASENAME ] ) );
	t_check( 'stays unchanged: parse_release gives null', null, u_parse( u_release( 'vv0.2.0' ) ) );
} );

// ---- Edge 3, 4, 5: comparison -------------------------------------------------------

t_run( 'is_newer: equal, older, newer, multi-digit, empty guards', function () {
	t_check( 'equal', false, U::is_newer( '0.2.0', '0.2.0' ) );
	t_check( 'remote older', false, U::is_newer( '0.1.0', '0.2.0' ) );
	t_check( 'multi-digit newer', true, U::is_newer( '0.10.0', '0.9.0' ) );
	t_check( 'multi-digit older', false, U::is_newer( '0.9.0', '0.10.0' ) );
	t_check( 'empty remote', false, U::is_newer( '', '0.1.0' ) );
	t_check( 'empty installed', false, U::is_newer( '0.2.0', '' ) );
	t_check( 'null installed', false, U::is_newer( '0.2.0', null ) );
	t_check( 'int installed', false, U::is_newer( '0.2.0', 1 ) );
	t_check( 'returns bool', 'boolean', gettype( U::is_newer( '0.2.0', '0.1.0' ) ) );
} );

t_run( 'Edge 3: equal version goes to no_update, not response', function () {
	u_reset();
	u_queue_ok( u_release( 'v0.2.0' ) );
	$t = U::filter_update_transient( u_transient( '0.2.0' ) );
	t_check( 'in no_update', true, isset( $t->no_update[ U::BASENAME ] ) );
	t_check( 'not in response', false, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'no_update item version', '0.2.0', $t->no_update[ U::BASENAME ]->new_version );
} );

t_run( 'Edge 4: remote older than installed goes to no_update', function () {
	u_reset();
	u_queue_ok( u_release( 'v0.2.0' ) );
	$t = U::filter_update_transient( u_transient( '0.3.0' ) );
	t_check( 'in no_update', true, isset( $t->no_update[ U::BASENAME ] ) );
	t_check( 'not in response', false, isset( $t->response[ U::BASENAME ] ) );
} );

t_run( 'Edge 5: 0.10.0 is newer than 0.9.0 (version_compare)', function () {
	u_reset();
	u_queue_ok( u_release( 'v0.10.0' ) );
	$t = U::filter_update_transient( u_transient( '0.9.0' ) );
	t_check( 'in response', true, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'new_version 0.10.0', '0.10.0', $t->response[ U::BASENAME ]->new_version );
} );

// ---- Edge 6: draft and prerelease ----------------------------------------------------

t_run( 'Edge 6: draft or prerelease, or key missing / not bool gives null', function () {
	t_check( 'valid release parses', true, is_array( u_parse( u_release() ) ) );
	$d = u_release();
	$d['draft'] = true;
	t_check( 'draft true', null, u_parse( $d ) );
	$d = u_release();
	$d['prerelease'] = true;
	t_check( 'prerelease true', null, u_parse( $d ) );
	$d = u_release();
	unset( $d['draft'] );
	t_check( 'draft missing', null, u_parse( $d ) );
	$d = u_release();
	unset( $d['prerelease'] );
	t_check( 'prerelease missing', null, u_parse( $d ) );
	$d = u_release();
	$d['draft'] = 'false';
	t_check( 'draft string false', null, u_parse( $d ) );
	$d = u_release();
	$d['prerelease'] = null;
	t_check( 'prerelease null', null, u_parse( $d ) );
	t_check( 'not an array', null, u_parse( 'release' ) );
} );

t_run( 'Edge 6: draft release from the API gives no update and one cached failure', function () {
	u_reset();
	$d = u_release();
	$d['draft'] = true;
	u_queue_ok( $d );
	$t = U::filter_update_transient( u_transient( '0.1.0' ) );
	t_check( 'no response entry', false, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'cached as failure', array( 'ok' => false, 'repo' => u_repo() ), u_cache_entry()['value'] );
} );

// ---- Edge 7: assets -------------------------------------------------------------------

t_run( 'Edge 7: assets missing, empty or not an array gives null', function () {
	$d = u_release();
	unset( $d['assets'] );
	t_check( 'assets missing', null, u_parse( $d ) );
	$d = u_release();
	$d['assets'] = array();
	t_check( 'assets empty', null, u_parse( $d ) );
	$d = u_release();
	$d['assets'] = 'tainacan-immich.zip';
	t_check( 'assets string', null, u_parse( $d ) );
	$d = u_release();
	$d['assets'] = null;
	t_check( 'assets null', null, u_parse( $d ) );
} );

// ---- Edge 8: asset name ----------------------------------------------------------------

t_run( 'Edge 8: wrong asset names give null', function () {
	$names = array(
		'versioned name'   => 'tainacan-immich-0.2.0.zip',
		'case differs'     => 'Tainacan-Immich.zip',
		'trailing space'   => 'tainacan-immich.zip ',
		'no name key'      => null,
	);
	foreach ( $names as $label => $name ) {
		$d = u_release();
		$d['assets'][0]['name'] = $name;
		if ( null === $name ) {
			unset( $d['assets'][0]['name'] );
		}
		t_check( "asset name: $label", null, u_parse( $d ) );
	}
	$d = u_release();
	$d['assets'] = array(
		array( 'browser_download_url' => 'https://github.com/tmdinosaurcenter/tainacan_immich/archive/refs/tags/v0.2.0.zip' ),
	);
	t_check( 'source zipball only', null, u_parse( $d ) );
} );

t_run( 'Edge 8: second asset is used when the first has another name', function () {
	$d = u_release();
	$d['assets'] = array(
		array( 'name' => 'tainacan-immich-0.2.0.zip', 'state' => 'uploaded', 'browser_download_url' => u_zip( 'v0.2.0' ) ),
		array( 'name' => 'tainacan-immich.zip', 'state' => 'uploaded', 'browser_download_url' => 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip?second=1' ),
	);
	$r = u_parse( $d );
	t_check( 'release found', true, is_array( $r ) );
	t_check( 'package from the second asset', 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip?second=1', $r['package'] );
} );

t_run( 'Edge 8: first matching asset decides (state checked on that one only)', function () {
	$d = u_release();
	$d['assets'] = array(
		array( 'name' => 'tainacan-immich.zip', 'state' => 'starter', 'browser_download_url' => u_zip( 'v0.2.0' ) ),
		array( 'name' => 'tainacan-immich.zip', 'state' => 'uploaded', 'browser_download_url' => u_zip( 'v0.2.0' ) ),
	);
	t_check( 'first match not uploaded gives null', null, u_parse( $d ) );
	$d = u_release();
	unset( $d['assets'][0]['state'] );
	t_check( 'state key missing is accepted', true, is_array( u_parse( $d ) ) );
} );

// ---- Edge 9 and 10: URL ------------------------------------------------------------------

t_run( 'Edge 9: http:// package URL gives null', function () {
	$d = u_release();
	$d['assets'][0]['browser_download_url'] = 'http://github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip';
	t_check( 'http scheme', null, u_parse( $d ) );
	$d = u_release();
	$d['assets'][0]['browser_download_url'] = 42;
	t_check( 'non-string URL', null, u_parse( $d ) );
	$d = u_release();
	unset( $d['assets'][0]['browser_download_url'] );
	t_check( 'URL missing', null, u_parse( $d ) );
} );

t_run( 'Edge 10: wrong host, credentials, port or path gives null', function () {
	$bad = array(
		'other host'         => 'https://evil.example/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'api host'           => 'https://api.github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'objects host'       => 'https://objects.githubusercontent.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'suffix host'        => 'https://github.com.evil.example/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'www host'           => 'https://www.github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'user and pass'      => 'https://user:pass@github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'user only'          => 'https://user@github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'explicit port 443'  => 'https://github.com:443/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'other port'         => 'https://github.com:8443/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip',
		'other repo'         => 'https://github.com/other/repo/releases/download/v0.2.0/tainacan-immich.zip',
		'fork repo prefix'   => 'https://github.com/tmdinosaurcenter/tainacan_immich_fork/releases/download/v0.2.0/tainacan-immich.zip',
		'not a download'     => 'https://github.com/tmdinosaurcenter/tainacan_immich/archive/tainacan-immich.zip',
		'suffix extra'       => 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip.exe',
		'dot-dot to other repo' => 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/../../../other/repo/releases/download/v9.9.9/tainacan-immich.zip',
		'no path'            => 'https://github.com',
	);
	foreach ( $bad as $label => $url ) {
		$d = u_release();
		$d['assets'][0]['browser_download_url'] = $url;
		t_check( "bad URL: $label", null, u_parse( $d ) );
	}
} );

t_run( 'Edge 10: case differences in scheme, host and path are accepted', function () {
	$url = 'HTTPS://GitHub.com/TMDinosaurCenter/Tainacan_Immich/releases/download/v0.2.0/tainacan-immich.zip';
	$d   = u_release();
	$d['assets'][0]['browser_download_url'] = $url;
	$r = u_parse( $d );
	t_check( 'accepted', true, is_array( $r ) );
	t_check( 'package kept as given', $url, is_array( $r ) ? $r['package'] : null );
} );

// ---- Edge 10, round 2: bad path shapes after the one-segment fix ---------------------------

t_run( 'Edge 10 (round 2): encoded, backslash, double-slash and dot segments give null', function () {
	$p   = 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/';
	$bad = array(
		'pct-encoded dot-dot to other repo' => $p . '%2e%2e/%2e%2e/other/repo/releases/download/v9.9.9/tainacan-immich.zip',
		'pct-encoded dot-dot upper case'    => $p . '%2E%2E/x/tainacan-immich.zip',
		'pct-encoded single dot'            => $p . '%2e/tainacan-immich.zip',
		'bare dot-dot segment'              => $p . '../tainacan-immich.zip',
		'two dot-dot segments'              => $p . '../../tainacan-immich.zip',
		'backslash dot-dot'                 => $p . '..\\..\\other/tainacan-immich.zip',
		'backslash inside tag'              => $p . 'v0.2.0\\x/tainacan-immich.zip',
		'double slash before file'          => $p . 'v0.2.0//tainacan-immich.zip',
		'double slash after download'       => 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download//tainacan-immich.zip',
		'trailing dot segment'              => $p . 'v0.2.0/./tainacan-immich.zip',
		'lone dot as tag'                   => $p . './tainacan-immich.zip',
		'encoded slash in tag'              => $p . 'v0.2.0%2Fx/tainacan-immich.zip',
		'encoded space in tag'              => $p . 'v0.2.0%20x/tainacan-immich.zip',
		'slash after file name'             => $p . 'v0.2.0/tainacan-immich.zip/',
	);
	foreach ( $bad as $label => $url ) {
		$d = u_release();
		$d['assets'][0]['browser_download_url'] = $url;
		t_check( "bad URL: $label", null, u_parse( $d ) );
	}
} );

t_run( 'Edge 10 (round 2): download folder then file name, no tag segment, gives null', function () {
	// The prefix and the suffix share the slash before the file name. The tag part must still be empty.
	$d = u_release();
	$d['assets'][0]['browser_download_url'] = 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/tainacan-immich.zip';
	t_check( 'bad URL: no tag segment (prefix and suffix overlap)', null, u_parse( $d ) );
} );

t_run( 'Edge 10 (round 2): raw space, tab and other characters outside the strict tag set give null', function () {
	// Round 2 decision: only letters, digits and . _ + - are allowed in the tag segment.
	$p   = 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/';
	$bad = array(
		'raw space in tag' => $p . 'v0.2.0 x/tainacan-immich.zip',
		'raw tab in tag'   => $p . "v0.2.0\tx/tainacan-immich.zip",
		'newline in tag'   => $p . "v0.2.0\n/tainacan-immich.zip",
		'colon in tag'     => $p . 'v0.2.0:x/tainacan-immich.zip',
		'at sign in tag'   => $p . 'v@x/tainacan-immich.zip',
		'tilde in tag'     => $p . 'v~1/tainacan-immich.zip',
	);
	foreach ( $bad as $label => $url ) {
		$d = u_release();
		$d['assets'][0]['browser_download_url'] = $url;
		t_check( "bad URL: $label", null, u_parse( $d ) );
	}
} );

t_run( 'Edge 10 (round 2): dot-dot release never reaches the update transient', function () {
	u_reset();
	$d = u_release();
	$d['assets'][0]['browser_download_url'] = 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/../../../other/repo/releases/download/v9.9.9/tainacan-immich.zip';
	u_queue_ok( $d );
	$t = U::filter_update_transient( u_transient( '0.1.0' ) );
	t_check( 'one HTTP call', 1, u_http_count() );
	t_check( 'no response entry for our plugin', false, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'no no_update entry for our plugin', false, isset( $t->no_update[ U::BASENAME ] ) );
	t_check( 'failure cached', false, u_cache_entry()['value']['ok'] );
} );

t_run( 'Edge 10 (round 2): valid tag shapes in the download path are still accepted', function () {
	$p = 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/';
	foreach ( array( 'v0.2.0', '0.2.0', 'v1.10.3', 'v0.2.0-rc1', 'v1.0.0+build.1', 'v1_2' ) as $tag ) {
		$url = $p . $tag . '/tainacan-immich.zip';
		$d   = u_release();
		$d['assets'][0]['browser_download_url'] = $url;
		$r   = u_parse( $d );
		t_check( "good URL with tag $tag: accepted", true, is_array( $r ) );
		t_check( "good URL with tag $tag: package kept", $url, is_array( $r ) ? $r['package'] : null );
	}
} );

t_run( 'Coder extra rule (tester): raw space or control character outside the tag gives null', function () {
	// The Coder rejects space and control characters anywhere in the package URL, before parse_url().
	$p   = 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/v0.2.0/tainacan-immich.zip';
	$bad = array(
		'raw space in query'    => $p . '?x=a b',
		'raw tab in query'      => $p . "?x=\t",
		'raw newline in query'  => $p . "?x=a\n",
		'raw NUL in query'      => $p . "?x=a\x00",
		'raw DEL in query'      => $p . "?x=a\x7f",
		'raw space in fragment' => $p . '#a b',
	);
	foreach ( $bad as $label => $url ) {
		$d = u_release();
		$d['assets'][0]['browser_download_url'] = $url;
		t_check( "bad URL: $label", null, u_parse( $d ) );
	}
	$d = u_release();
	$d['assets'][0]['browser_download_url'] = $p . '?x=a%20b';
	t_check( 'percent-encoded space in query accepted', true, is_array( u_parse( $d ) ) );
} );

t_run( 'Round 2 rule 1 (tester): one-character and symbol-only tags are accepted (not . or ..)', function () {
	$p = 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/download/';
	foreach ( array( 'x', '+', '-', '___', '...', '.x', 'v.0' ) as $tag ) {
		$d = u_release();
		$d['assets'][0]['browser_download_url'] = $p . $tag . '/tainacan-immich.zip';
		t_check( "tag [$tag] accepted", true, is_array( u_parse( $d ) ) );
	}
} );

t_run( 'html_url: used only when https and github.com, else the repo page', function () {
	$r = u_parse( u_release() );
	t_check( 'valid html_url kept', 'https://github.com/tmdinosaurcenter/tainacan_immich/releases/tag/v0.2.0', $r['url'] );
	$d = u_release();
	unset( $d['html_url'] );
	$r = u_parse( $d );
	t_check( 'missing html_url falls back', 'https://github.com/tmdinosaurcenter/tainacan_immich', $r['url'] );
	$d = u_release();
	$d['html_url'] = 'https://evil.example/x';
	$r = u_parse( $d );
	t_check( 'foreign html_url falls back', 'https://github.com/tmdinosaurcenter/tainacan_immich', $r['url'] );
	$d = u_release();
	$d['html_url'] = 'http://github.com/tmdinosaurcenter/tainacan_immich/releases/tag/v0.2.0';
	$r = u_parse( $d );
	t_check( 'http html_url falls back', 'https://github.com/tmdinosaurcenter/tainacan_immich', $r['url'] );
} );

t_run( 'published_at: kept up to 64 chars, empty beyond', function () {
	$d = u_release();
	$d['published_at'] = str_repeat( '2', 64 );
	t_check( '64 chars kept', str_repeat( '2', 64 ), u_parse( $d )['published'] );
	$d['published_at'] = str_repeat( '2', 65 );
	t_check( '65 chars gives empty', '', u_parse( $d )['published'] );
	$d = u_release();
	unset( $d['published_at'] );
	t_check( 'missing gives empty', '', u_parse( $d )['published'] );
} );

// ---- Edge 11, 12, 13: HTTP failures ---------------------------------------------------

t_run( 'Edge 11: bad body gives null and a 15 minute failure cache', function () {
	$bodies = array(
		'empty body'   => '',
		'broken json'  => '{"tag_name": ',
		'json string'  => '"text"',
		'json number'  => '42',
		'json null'    => 'null',
	);
	foreach ( $bodies as $label => $body ) {
		u_reset();
		t_queue( 200, $body );
		t_check( "bad body: $label gives null", null, U::get_release() );
		t_check( "bad body: $label cached as failure", array( 'ok' => false, 'repo' => u_repo() ), u_cache_entry()['value'] );
		t_check( "bad body: $label cache 900 s", 900, u_cache_entry()['expiration'] );
	}
} );

t_run( 'Edge 12: HTTP 403, 404, 301 and 500 give null and a 15 minute failure cache', function () {
	foreach ( array( 403, 404, 301, 500 ) as $code ) {
		u_reset();
		u_queue_ok( u_release(), $code );
		t_check( "HTTP $code gives null", null, U::get_release() );
		t_check( "HTTP $code cached as failure", false, u_cache_entry()['value']['ok'] );
		t_check( "HTTP $code cache 900 s", 900, u_cache_entry()['expiration'] );
		t_check( "HTTP $code makes one call", 1, u_http_count() );
	}
	u_reset();
	u_queue_ok( u_release(), 200 );
	t_check( 'control: HTTP 200 gives a release', true, is_array( U::get_release() ) );
} );

t_run( 'Edge 13: WP_Error gives null, prints nothing, cached as failure', function () {
	u_reset();
	t_queue_error();
	ob_start();
	$r   = U::get_release();
	$out = ob_get_clean();
	t_check( 'null result', null, $r );
	t_check( 'nothing printed', '', $out );
	t_check( 'failure cached 900 s', 900, u_cache_entry()['expiration'] );
	$t = U::filter_update_transient( u_transient( '0.1.0' ) );
	t_check( 'filter returns object with no update added', false, isset( $t->response ) && ! empty( $t->response ) );
} );

// ---- Edge 14 and 15: cache -------------------------------------------------------------

t_run( 'Edge 14: success cache hit gives no HTTP call and 12 h expiry', function () {
	u_reset();
	u_queue_ok( u_release() );
	$first = U::get_release();
	t_check( 'first call fetched', 1, u_http_count() );
	t_check( 'success cache 43200 s', 43200, u_cache_entry()['expiration'] );
	t_check( 'success cache shape', true, u_cache_entry()['value']['ok'] );
	$second = U::get_release();
	t_check( 'second call no new HTTP call', 1, u_http_count() );
	t_check( 'second call same release', $first, $second );
	$t = U::filter_update_transient( u_transient( '0.1.0' ) );
	t_check( 'filter from cache: update shown', true, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'still no new HTTP call', 1, u_http_count() );
} );

t_run( 'Edge 14: failure cache hit gives no HTTP call and no update', function () {
	u_reset();
	t_queue_error();
	t_check( 'first call null', null, U::get_release() );
	$second = U::get_release();
	t_check( 'second call null', null, $second );
	t_check( 'no second HTTP call', 1, u_http_count() );
	$t = u_transient( '0.1.0' );
	$same = U::filter_update_transient( $t );
	t_check( 'filter returns same object', true, $same === $t );
	t_check( 'no response entry', array(), $t->response );
	t_check( 'no no_update entry', array(), $t->no_update );
	t_check( 'still no HTTP call', 1, u_http_count() );
} );

t_run( 'Edge 15: wrong-shape or other-repo cache is a miss (fetch happens)', function () {
	$repo  = u_repo();
	$other = array( 'ok' => true, 'repo' => 'other/repo', 'release' => array( 'version' => '9.9.9' ) );
	$shapes = array(
		'string value'          => 'junk',
		'array without ok'      => array( 'repo' => $repo, 'release' => array( 'version' => '9.9.9' ) ),
		'ok is a string'        => array( 'ok' => 'yes', 'repo' => $repo, 'release' => array( 'version' => '9.9.9' ) ),
		'ok is int 1'           => array( 'ok' => 1, 'repo' => $repo, 'release' => array( 'version' => '9.9.9' ) ),
		'other repo, success'   => $other,
		'other repo, failure'   => array( 'ok' => false, 'repo' => 'other/repo' ),
		'repo key missing'      => array( 'ok' => true, 'release' => array( 'version' => '9.9.9' ) ),
		'null value'            => null,
	);
	foreach ( $shapes as $label => $value ) {
		u_reset();
		$GLOBALS['test_site_transients'][ U::CACHE_KEY ] = array( 'value' => $value, 'expiration' => 0 );
		u_queue_ok( u_release() );
		$r = U::get_release();
		t_check( "miss, fetch made: $label", 1, u_http_count() );
		t_check( "miss, fresh version used: $label", '0.2.0', is_array( $r ) ? $r['version'] : null );
	}
} );

t_run( 'Edge 15 (tester): ok true with no usable release is a miss (fetch), not a cached empty result', function () {
	$repo   = u_repo();
	$shapes = array(
		'ok true, release missing'       => array( 'ok' => true, 'repo' => $repo ),
		'ok true, release string'        => array( 'ok' => true, 'repo' => $repo, 'release' => 'junk' ),
		'ok true, release null'          => array( 'ok' => true, 'repo' => $repo, 'release' => null ),
		'ok true, release empty array'   => array( 'ok' => true, 'repo' => $repo, 'release' => array() ),
		'ok true, release lacks version' => array( 'ok' => true, 'repo' => $repo, 'release' => array( 'package' => 'junk' ) ),
	);
	foreach ( $shapes as $label => $value ) {
		u_reset();
		$GLOBALS['test_site_transients'][ U::CACHE_KEY ] = array( 'value' => $value, 'expiration' => 0 );
		u_queue_ok( u_release() );
		$r = U::get_release();
		t_check( "miss, fetch made: $label", 1, u_http_count() );
		t_check( "miss, fresh version used: $label", '0.2.0', ( is_array( $r ) && isset( $r['version'] ) ) ? $r['version'] : null );
	}
} );

t_run( 'Edge 15 (tester): malformed cached release gives no PHP notice in the update filter', function () {
	u_reset();
	$GLOBALS['test_site_transients'][ U::CACHE_KEY ] = array(
		'value'      => array( 'ok' => true, 'repo' => u_repo(), 'release' => array() ),
		'expiration' => 0,
	);
	u_queue_ok( u_release() );
	$t = new \stdClass();
	$t->checked = array( U::BASENAME => '0.1.0' );
	$out = U::filter_update_transient( $t );
	t_check( 'returns the object', true, $out === $t );
	t_check( 'broken cache is a miss: one fetch made', 1, u_http_count() );
	t_check( 'good update entry in response', true, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'good update new_version is 0.2.0', '0.2.0', isset( $t->response[ U::BASENAME ] ) ? $t->response[ U::BASENAME ]->new_version : null );
	t_check( 'cache rewritten with ok true', true, isset( $GLOBALS['test_site_transients'][ U::CACHE_KEY ]['value']['ok'] ) && true === $GLOBALS['test_site_transients'][ U::CACHE_KEY ]['value']['ok'] );
} );

t_run( 'Edge 20 (tester): response or no_update set to null is replaced by an array', function () {
	u_reset();
	u_queue_ok( u_release() );
	$t = new \stdClass();
	$t->checked   = array( U::BASENAME => '0.1.0' );
	$t->response  = null;
	$t->no_update = null;
	$t = U::filter_update_transient( $t );
	t_check( 'response is array with our entry', true, isset( $t->response[ U::BASENAME ] ) );
	t_check( 'no_update is array', true, is_array( $t->no_update ) );
} );

// ---- Edge 16, 17: off switches ---------------------------------------------------------

t_run( 'Edge 16: option 0 gives no HTTP call and unchanged results', function () use ( $OPT_CHECK ) {
	u_reset();
	$GLOBALS['test_options'][ $OPT_CHECK ] = '0';
	t_check( 'is_enabled false', false, U::is_enabled() );
	$t    = u_transient( '0.1.0' );
	$same = U::filter_update_transient( $t );
	t_check( 'transient returned as same object', true, $same === $t );
	t_check( 'no response added', array(), $t->response );
	t_check( 'no no_update added', array(), $t->no_update );
	$res = U::filter_plugins_api( 'untouched', 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) );
	t_check( 'plugins_api returns result unchanged', 'untouched', $res );
	t_check( 'get_release null', null, U::get_release() );
	t_check( 'no HTTP call at all', 0, u_http_count() );
} );

t_run( 'Edge 17: filter false gives the same off result as the option', function () use ( $OPT_CHECK ) {
	u_reset();
	$GLOBALS['test_apply_override']['tainacan_immich_enable_update_check'] = false;
	t_check( 'is_enabled false', false, U::is_enabled() );
	$t = u_transient( '0.1.0' );
	t_check( 'transient same object', true, U::filter_update_transient( $t ) === $t );
	t_check( 'plugins_api unchanged', 'untouched', U::filter_plugins_api( 'untouched', 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) ) );
	t_check( 'no HTTP call', 0, u_http_count() );
} );

t_run( 'Edge 17: filter true wins over option 0 (check runs)', function () use ( $OPT_CHECK ) {
	u_reset();
	$GLOBALS['test_options'][ $OPT_CHECK ]                                 = '0';
	$GLOBALS['test_apply_override']['tainacan_immich_enable_update_check'] = true;
	t_check( 'is_enabled true', true, U::is_enabled() );
	u_queue_ok( u_release() );
	t_check( 'release returned', '0.2.0', U::get_release()['version'] );
	t_check( 'one HTTP call', 1, u_http_count() );
} );

t_run( 'Edge 27: option never saved means the check is on (default 1)', function () {
	u_reset();
	t_check( 'no option stored: enabled', true, U::is_enabled() );
	t_check( 'returns bool', 'boolean', gettype( U::is_enabled() ) );
	$GLOBALS['test_options'][ \Tainacan\Immich::OPTION_UPDATE_CHECK ] = '1';
	t_check( 'option 1: enabled', true, U::is_enabled() );
} );

t_run( 'Off switches: both filter callbacks and plugins_api make no HTTP call', function () use ( $OPT_CHECK ) {
	$cases = array(
		'option 0'     => function () use ( $OPT_CHECK ) {
			$GLOBALS['test_options'][ $OPT_CHECK ] = '0';
		},
		'filter false' => function () {
			$GLOBALS['test_apply_override']['tainacan_immich_enable_update_check'] = false;
		},
	);
	foreach ( $cases as $label => $setup ) {
		u_reset();
		$setup();
		U::filter_update_transient( u_transient( '0.1.0' ) );
		U::filter_plugins_api( null, 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) );
		t_check( "log empty: $label", array(), $GLOBALS['test_http_log'] );
	}
} );

// ---- Edge 19, 20, 21: transient shapes ------------------------------------------------

t_run( 'Edge 19: non-object transient is returned unchanged, no request', function () {
	$inputs = array(
		'false'  => false,
		'null'   => null,
		'string' => 'text',
		'array'  => array(),
		'int'    => 3,
	);
	foreach ( $inputs as $label => $in ) {
		u_reset();
		t_check( "returned unchanged: $label", $in, U::filter_update_transient( $in ) );
		t_check( "no request: $label", 0, u_http_count() );
	}
} );

t_run( 'Edge 20: no usable installed version gives unchanged object, no request', function () {
	$cases = array();
	$t = new \stdClass();
	$t->response = array();
	$cases['no checked property'] = $t;
	$t = u_transient( '' );
	$cases['empty installed string'] = $t;
	$t = u_transient( 5 );
	$cases['int installed'] = $t;
	$t = u_transient( '0.1.0' );
	$t->checked = array();
	$cases['checked without basename'] = $t;
	$t = u_transient( null );
	$cases['null installed'] = $t;
	foreach ( $cases as $label => $obj ) {
		u_reset();
		t_check( "same object: $label", true, U::filter_update_transient( $obj ) === $obj );
		t_check( "no request: $label", 0, u_http_count() );
	}
} );

t_run( 'Edge 20: missing response and no_update properties are created as arrays', function () {
	u_reset();
	u_queue_ok( u_release() );
	$t = new \stdClass();
	$t->checked = array( U::BASENAME => '0.1.0' );
	$t = U::filter_update_transient( $t );
	t_check( 'response created as array', true, isset( $t->response ) && is_array( $t->response ) );
	t_check( 'no_update created as array', true, isset( $t->no_update ) && is_array( $t->no_update ) );
	t_check( 'update placed in response', true, isset( $t->response[ U::BASENAME ] ) );
} );

t_run( 'Edge 20: missing properties created when the release is not newer', function () {
	u_reset();
	u_queue_ok( u_release() );
	$t = new \stdClass();
	$t->checked = array( U::BASENAME => '0.2.0' );
	$t = U::filter_update_transient( $t );
	t_check( 'response created as array', true, isset( $t->response ) && is_array( $t->response ) );
	t_check( 'entry in no_update', true, isset( $t->no_update[ U::BASENAME ] ) );
} );

t_run( 'Edge 21: failure after a success keeps the old state', function () {
	u_reset();
	u_queue_ok( u_release() );
	$t = U::filter_update_transient( u_transient( '0.1.0' ) );
	t_check( 'first run: update shown', true, isset( $t->response[ U::BASENAME ] ) );
	$before = $t->response[ U::BASENAME ];
	u_clear_cache();
	t_queue_error();
	$same = U::filter_update_transient( $t );
	t_check( 'same object returned', true, $same === $t );
	t_check( 'old update kept', true, $before === $same->response[ U::BASENAME ] );
	t_check( 'no no_update entry added', false, isset( $same->no_update[ U::BASENAME ] ) );
} );

// ---- Edge 22: registration -----------------------------------------------------------

t_run( 'Edge 22: register() adds no hooks for another folder, two hooks for ours', function () {
	u_reset();
	U::register( '/fake/wp-content/plugins/tainacan-immich-copy/tainacan-immich.php' );
	t_check( 'other folder: no hooks', 0, count( $GLOBALS['test_filters'] ) );
	U::register( '/fake/wp-content/plugins/tainacan-immich/tainacan-immich.php' );
	t_check( 'our folder: two hooks', 2, count( $GLOBALS['test_filters'] ) );
	$f = $GLOBALS['test_filters'];
	t_check( 'transient hook name', 'pre_set_site_transient_update_plugins', $f[0]['hook'] );
	t_check( 'transient callback', array( 'Tainacan\\Immich_Updater', 'filter_update_transient' ), $f[0]['callback'] );
	t_check( 'plugins_api hook name', 'plugins_api', $f[1]['hook'] );
	t_check( 'plugins_api callback', array( 'Tainacan\\Immich_Updater', 'filter_plugins_api' ), $f[1]['callback'] );
	t_check( 'plugins_api priority 20', 20, $f[1]['priority'] );
	t_check( 'plugins_api 3 args', 3, $f[1]['args'] );
	U::register( '/fake/wp-content/plugins/tainacan-immich/tainacan-immich.php' );
	t_check( 'second register adds no more hooks', 2, count( $GLOBALS['test_filters'] ) );
} );

// ---- Edge 23, 24: plugins_api ---------------------------------------------------------

t_run( 'Edge 23: plugins_api for other slug, action or args gives result unchanged, no request', function () {
	u_reset();
	t_check( 'other slug', 'untouched', U::filter_plugins_api( 'untouched', 'plugin_information', (object) array( 'slug' => 'some-other-plugin' ) ) );
	t_check( 'query_plugins action', 'untouched', U::filter_plugins_api( 'untouched', 'query_plugins', (object) array( 'slug' => 'tainacan-immich' ) ) );
	t_check( 'array args', 'untouched', U::filter_plugins_api( 'untouched', 'plugin_information', array( 'slug' => 'tainacan-immich' ) ) );
	t_check( 'object without slug', 'untouched', U::filter_plugins_api( 'untouched', 'plugin_information', new \stdClass() ) );
	t_check( 'null args', 'untouched', U::filter_plugins_api( 'untouched', 'plugin_information', null ) );
	t_check( 'no request', 0, u_http_count() );
} );

t_run( 'Edge 24: plugins_api for our slug with no valid release gives WP_Error', function () {
	u_reset();
	t_queue_error();
	$r = U::filter_plugins_api( null, 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) );
	t_check( 'WP_Error returned', true, $r instanceof \WP_Error );
	t_check( 'error code', 'tainacan_immich_update_unavailable', $r->get_error_code() );
	t_check( 'error message is translatable text', 'The update details are not available now. Try again later.', $r->get_error_message() );
} );

t_run( 'plugin_information object: fields and values', function () {
	u_reset();
	u_queue_ok( u_release() );
	$info = U::filter_plugins_api( null, 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) );
	t_check( 'is object', true, is_object( $info ) );
	t_check( 'name', 'Tainacan Immich', $info->name );
	t_check( 'slug', 'tainacan-immich', $info->slug );
	t_check( 'version', '0.2.0', $info->version );
	t_check( 'author link', '<a href="https://github.com/tmdinosaurcenter/tainacan_immich">tmdinosaurcenter/tainacan_immich</a>', $info->author );
	t_check( 'homepage', 'https://github.com/tmdinosaurcenter/tainacan_immich', $info->homepage );
	t_check( 'requires empty', '', $info->requires );
	t_check( 'requires_php empty', '', $info->requires_php );
	t_check( 'tested empty', '', $info->tested );
	t_check( 'last_updated', '2026-10-01T10:00:00Z', $info->last_updated );
	t_check( 'download_link is the package', u_zip( 'v0.2.0' ), $info->download_link );
	t_check( 'description section', '<p>Links Tainacan items to assets in an Immich photo server.</p>', $info->sections['description'] );
	t_check(
		'changelog section',
		'<h4>0.2.0</h4><p>Fake release notes line.</p><ul><li>Fake bullet one</li><li>Fake bullet two</li></ul><p>Fake closing line.</p>',
		$info->sections['changelog']
	);
} );

// ---- Edge 25: release notes ----------------------------------------------------------------

t_run( 'Edge 25: empty notes show "No release notes."', function () {
	$empty = '<p>No release notes.</p>';
	t_check( 'empty string', $empty, U::format_notes( '' ) );
	t_check( 'spaces and newline', $empty, U::format_notes( "   \n  " ) );
	t_check( 'null', $empty, U::format_notes( null ) );
	t_check( 'int', $empty, U::format_notes( 42 ) );
	$d = u_release();
	$d['body'] = '';
	t_check( 'release with empty body: notes empty', '', u_parse( $d )['notes'] );
	u_reset();
	u_queue_ok( $d );
	$info = U::filter_plugins_api( null, 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) );
	t_contains( 'changelog shows no-notes text', 'No release notes.', $info->sections['changelog'] );
} );

t_run( 'Edge 25: script and img tags are escaped, no raw tag remains', function () {
	$out = U::format_notes( '<script>alert(1)</script>' );
	t_check( 'script escaped', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $out );
	t_not_contains( 'no raw script tag', '<script', $out );
	$out = U::format_notes( '<img src=x onerror=alert(1)>' );
	t_check( 'img escaped', '<p>&lt;img src=x onerror=alert(1)&gt;</p>', $out );
	t_not_contains( 'no raw img tag', '<img', $out );
	$out = U::format_notes( "* <b>bold</b>\n- <a href=\"x\">link</a>" );
	t_not_contains( 'no raw b tag in list', '<b>', $out );
	t_not_contains( 'no raw a tag in list', '<a ', $out );
	$d = u_release();
	$d['body'] = '<script>alert(1)</script>';
	u_reset();
	u_queue_ok( $d );
	$info = U::filter_plugins_api( null, 'plugin_information', (object) array( 'slug' => 'tainacan-immich' ) );
	t_not_contains( 'changelog from plugins_api has no raw script', '<script', $info->sections['changelog'] );
	t_contains( 'changelog from plugins_api has escaped script', '&lt;script&gt;', $info->sections['changelog'] );
} );

t_run( 'Edge 25: markdown-lite lists, paragraphs and line endings', function () {
	t_check( 'bullets then paragraph', '<ul><li>a</li><li>b</li></ul><p>c</p>', U::format_notes( "* a\n- b\n\nc" ) );
	t_check( 'list closed at end', '<ul><li>a</li></ul>', U::format_notes( '* a' ) );
	t_check( 'CRLF lines', '<p>a</p><p>b</p>', U::format_notes( "a\r\nb" ) );
	t_check( 'CR lines', '<p>a</p><p>b</p>', U::format_notes( "a\rb" ) );
	t_check( 'lone star is a paragraph', '<p>*</p>', U::format_notes( '*' ) );
} );

t_run( 'Edge 25: body longer than 20000 characters is cut', function () {
	$d = u_release();
	$d['body'] = str_repeat( "\xc3\xa9", 25000 );
	$r = u_parse( $d );
	t_check( 'cut to 20000 characters', 20000, mb_strlen( $r['notes'], 'UTF-8' ) );
	$d['body'] = str_repeat( 'a', 20000 );
	t_check( 'exactly 20000 kept whole', 20000, strlen( u_parse( $d )['notes'] ) );
	$d['body'] = str_repeat( 'a', 19999 );
	t_check( 'shorter kept whole', 19999, strlen( u_parse( $d )['notes'] ) );
} );

// ---- build_update_item direct ---------------------------------------------------------------

t_run( 'build_update_item returns the spec fields', function () {
	u_reset();
	$r = U::build_update_item( U::parse_release( u_release(), u_repo() ) );
	t_check( 'is stdClass', true, $r instanceof \stdClass );
	t_check( 'field list', array( 'id', 'slug', 'plugin', 'new_version', 'url', 'package', 'tested', 'requires', 'requires_php', 'icons' ), array_keys( (array) $r ) );
} );

// ---- Cache expiration and request in the success path -------------------------------------

t_run( 'Cache: entry shape and expiry on success and failure', function () {
	u_reset();
	u_queue_ok( u_release() );
	U::get_release();
	$e = u_cache_entry();
	t_check( 'success expiration 43200', 43200, $e['expiration'] );
	t_check( 'success entry keys', array( 'ok', 'repo', 'release' ), array_keys( $e['value'] ) );
	t_check( 'success entry repo', u_repo(), $e['value']['repo'] );
	u_reset();
	t_queue( 403, '' );
	U::get_release();
	t_check( 'failure expiration 900', 900, u_cache_entry()['expiration'] );
	t_check( 'failure entry keys', array( 'ok', 'repo' ), array_keys( u_cache_entry()['value'] ) );
} );

// ---- Source checks (PHP 7.1 syntax, no unzip or eval, no auth) -------------------------------

t_run( 'Source: PHP 7.1 syntax only and no code-running calls', function () {
	$src = file_get_contents( __DIR__ . '/../tainacan-immich/class-tainacan-immich-updater.php' );
	t_check( 'no arrow functions', 0, preg_match( '/\bfn\s*\(/', $src ) );
	t_check( 'no nullsafe operator', 0, substr_count( $src, '?->' ) );
	t_check( 'no str_starts_with', 0, substr_count( $src, 'str_starts_with' ) );
	t_check( 'no str_ends_with', 0, substr_count( $src, 'str_ends_with' ) );
	t_check( 'no match expression', 0, preg_match( '/\bmatch\s*\(/', $src ) );
	preg_match_all( '/(private|public|protected)\s+(static\s+)?([?A-Za-z_\\\\]+)\s+\$/', $src, $typed );
	$typed_props = array_filter( $typed[3], function ( $word ) {
		return 'static' !== $word;
	} );
	t_check( 'no typed property', 0, count( $typed_props ) );
	t_check( 'no eval, exec, include, require calls', 0, preg_match( '/\b(eval|exec|shell_exec|system|passthru|include|include_once|require|require_once)\s*\(/', $src ) );
	t_check( 'no ZipArchive', 0, substr_count( $src, 'ZipArchive' ) );
	t_check( 'no Authorization header', 0, preg_match( '/authorization/i', $src ) );
	t_check( 'no tmdc name in updater', 0, substr_count( $src, 'tmdc' ) );
} );

t_run( 'Source: Tainacan namespace, ABSPATH guard and register() signature', function () {
	$src = file_get_contents( __DIR__ . '/../tainacan-immich/class-tainacan-immich-updater.php' );
	t_check( 'namespace Tainacan', 1, substr_count( $src, 'namespace Tainacan;' ) );
	t_check( 'ABSPATH guard', 1, substr_count( $src, "defined( 'ABSPATH' ) or die( 'No script kiddies please!' );" ) );
	t_check( 'register is public static void', 'void', (string) ( new \ReflectionMethod( 'Tainacan\\Immich_Updater', 'register' ) )->getReturnType() );
} );

t_summary();
