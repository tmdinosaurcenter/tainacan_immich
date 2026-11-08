<?php
/**
 * Boot checks for the plugin main file: top-level hooks, registration by URL when the
 * bundles are not built (spec edge 19), the Tainacan-missing notice, and the
 * Tainacan-present hooks (checked in the source). Plain PHP, no network.
 * Run: php tests/test-immich-boot.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/fake/' );
}

$GLOBALS['boot_actions'] = array();
$GLOBALS['boot_passes']  = 0;
$GLOBALS['boot_fails']   = 0;

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['boot_actions'][] = array( $hook, $callback, $priority );
	return true;
}
function plugin_dir_url( $file ) {
	return 'https://wp.example.test/wp-content/plugins/tainacan-immich/';
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function boot_check( $label, $expected, $actual ) {
	if ( $expected === $actual ) {
		$GLOBALS['boot_passes']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['boot_fails']++;
		echo "FAIL  $label: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}
function boot_hooks( $hook ) {
	$out = array();
	foreach ( $GLOBALS['boot_actions'] as $a ) {
		if ( $a[0] === $hook ) {
			$out[] = $a;
		}
	}
	return $out;
}

class Boot_Fake_Helper {
	public $calls = array();
	public function register_metadata_type() {
		$this->calls[] = array( 'metadata', func_get_args() );
	}
	public function register_vuejs_component() {
		$this->calls[] = array( 'vuejs', func_get_args() );
	}
}

// Fake of core get_file_data (same regex as wp-includes/functions.php). Counts calls.
$GLOBALS['boot_fgd_calls'] = 0;
function get_file_data( $file, $default_headers, $context = '' ) {
	$GLOBALS['boot_fgd_calls']++;
	$fp        = fopen( $file, 'r' );
	$file_data = fread( $fp, 8192 );
	fclose( $fp );
	$file_data = str_replace( "\r", "\n", $file_data );
	$all       = array();
	foreach ( $default_headers as $field => $regex ) {
		if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
			$all[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
		} else {
			$all[ $field ] = '';
		}
	}
	return $all;
}

// Minimal base class, so the metadata type class file can load without Tainacan.
eval( 'namespace Tainacan\Metadata_Types { class Metadata_Type { } }' );

require __DIR__ . '/../tainacan-immich/tainacan-immich.php';

echo "\n== top-level hooks\n";
boot_check( 'metadata type hook', 1, count( boot_hooks( 'tainacan-register-metadata-type' ) ) );
boot_check( 'vuejs component hook', 1, count( boot_hooks( 'tainacan-register-vuejs-component' ) ) );
boot_check( 'plugins_loaded hook', 1, count( boot_hooks( 'plugins_loaded' ) ) );
boot_check( 'no rest hook at top level', 0, count( boot_hooks( 'rest_api_init' ) ) );
boot_check( 'no admin_enqueue hook at top level', 0, count( boot_hooks( 'admin_enqueue_scripts' ) ) );
boot_check( 'function names are prefixed', true, function_exists( 'tainacan_immich_register_metadata_type' ) && function_exists( 'tainacan_immich_register_vuejs_component' ) );

echo "\n== registration by URL, does not depend on the build files (edge 19)\n";
$helper = new Boot_Fake_Helper();
$cb     = boot_hooks( 'tainacan-register-metadata-type' )[0][1];
$cb( $helper );
boot_check( 'metadata type registered once', 1, count( $helper->calls ) );
$args = $helper->calls[0][1];
boot_check( 'handle', 'tainacan-immich-link', $args[0] );
boot_check( 'class name', '\\Tainacan\\Metadata_Types\\Immich_Link', $args[1] );
boot_check( 'script url', 'https://wp.example.test/wp-content/plugins/tainacan-immich/build/immich-link.js?tainacan_immich_v=0.3.1', $args[2] );
$meta_url = $args[2];
boot_check( 'class loads after callback', true, class_exists( '\\Tainacan\\Metadata_Types\\Immich_Link', false ) );

$helper = new Boot_Fake_Helper();
$cb     = boot_hooks( 'tainacan-register-vuejs-component' )[0][1];
$cb( $helper );
$args = $helper->calls[0][1];
boot_check( 'form handle', 'tainacan-immich-link-form', $args[0] );
boot_check( 'form script url', 'https://wp.example.test/wp-content/plugins/tainacan-immich/build/immich-link-form.js?tainacan_immich_v=0.3.1', $args[1] );
$form_url = $args[1];

echo "\n== Script URL version (in-process)\n";
$src = file_get_contents( dirname( __DIR__ ) . '/tainacan-immich/tainacan-immich.php' );
preg_match( '/^Version:[ \t]*(\S+)[ \t]*$/m', $src, $hm );
$header_version = isset( $hm[1] ) ? $hm[1] : '';
foreach ( array( 'immich-link.js' => $meta_url, 'immich-link-form.js' => $form_url ) as $fname => $u ) {
	boot_check( "$fname: ends with ?tainacan_immich_v=<header version>", true, '?tainacan_immich_v=' . $header_version === substr( $u, -strlen( '?tainacan_immich_v=' . $header_version ) ) );
	parse_str( (string) parse_url( $u, PHP_URL_QUERY ), $q );
	boot_check( "$fname: exactly one query argument", array( 'tainacan_immich_v' => '0.3.1' ), $q );
	boot_check( "$fname: no ver argument", false, isset( $q['ver'] ) );
	$path = (string) parse_url( $u, PHP_URL_PATH );
	boot_check( "$fname: path ends with file name", $fname, basename( $path ) );
}
boot_check( 'both URLs use the same version', substr( $meta_url, strpos( $meta_url, '?' ) ), substr( $form_url, strpos( $form_url, '?' ) ) );
$v1 = tainacan_immich_get_version();
$v2 = tainacan_immich_get_version();
boot_check( 'get_version twice: same value', $v1, $v2 );
boot_check( 'get_version equals header', $header_version, $v1 );
boot_check( 'get_file_data called once per request', 1, $GLOBALS['boot_fgd_calls'] );
boot_check( 'source has no ver argument', 0, substr_count( $src, "'ver'" ) + substr_count( $src, '?ver=' ) );
boot_check( 'source has no version constant', 0, substr_count( $src, "define( 'TAINACAN_IMMICH_VERSION'" ) );

echo "\n== Script URL version (subprocess, temp copy of the plugin)\n";
$tmp = sys_get_temp_dir() . '/boottest-' . bin2hex( random_bytes( 6 ) );
mkdir( $tmp, 0700 );
$plugin_dir = $tmp . '/tainacan-immich';
mkdir( $plugin_dir . '/metadata-types', 0755, true );
copy( dirname( __DIR__ ) . '/tainacan-immich/metadata-types/class-tainacan-immich-link.php', $plugin_dir . '/metadata-types/class-tainacan-immich-link.php' );
$runner = <<<'RUNNER'
<?php
define( 'ABSPATH', '/fake/' );
function add_action( $h, $c, $p = 10 ) { $GLOBALS['acts'][ $h ] = $c; return true; }
function __( $t, $d = 'default' ) { return $t; }
function plugin_dir_url( $f ) { $b = getenv( 'BOOT_BASE' ); return false === $b || '' === $b ? 'https://wp.example.test/wp-content/plugins/tainacan-immich/' : $b; }
if ( '1' !== getenv( 'BOOT_NO_FGD' ) ) {
	function get_file_data( $file, $default_headers, $context = '' ) {
		$fp = fopen( $file, 'r' ); $d = fread( $fp, 8192 ); fclose( $fp );
		$d = str_replace( "\r", "\n", $d ); $all = array();
		foreach ( $default_headers as $field => $regex ) {
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $d, $m ) && $m[1] ) {
				$all[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
			} else { $all[ $field ] = ''; }
		}
		return $all;
	}
}
class Fake_Helper {
	public $urls = array();
	public function register_metadata_type( $handle, $class, $url ) { $this->urls[] = $url; }
	public function register_vuejs_component( $handle, $url ) { $this->urls[] = $url; }
}
eval( 'namespace Tainacan\Metadata_Types { class Metadata_Type { } }' );
require $argv[1];
$h = new Fake_Helper();
$GLOBALS['acts']['tainacan-register-metadata-type']( $h );
$GLOBALS['acts']['tainacan-register-vuejs-component']( $h );
echo json_encode( array( 'version' => tainacan_immich_get_version(), 'urls' => $h->urls ) );
RUNNER;
file_put_contents( $tmp . '/runner.php', $runner );

function boot_run_copy( $tmp, $src, $version_line, $crlf, array $env_extra ) {
	$body = $src;
	if ( null === $version_line ) {
		$body = preg_replace( '/^Version:.*\n/m', '', $body );
	} else {
		$body = preg_replace( '/^Version:.*$/m', $version_line, $body );
	}
	if ( $crlf ) {
		$body = str_replace( "\n", "\r\n", $body );
	}
	$file = $tmp . '/tainacan-immich/tainacan-immich.php';
	file_put_contents( $file, $body );
	$env  = array_merge( array( 'PATH' => (string) getenv( 'PATH' ) ), $env_extra );
	$spec = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( array( PHP_BINARY, $tmp . '/runner.php', $file ), $spec, $pipes, $tmp, $env );
	if ( ! is_resource( $proc ) ) {
		return array( -1, null );
	}
	$out = stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	return array( $code, json_decode( $out, true ) );
}
function boot_ends_with( $u, $tail ) {
	return $tail === substr( $u, -strlen( $tail ) );
}

$r = boot_run_copy( $tmp, $src, 'Version: 9.8.7', false, array() );
boot_check( 'header 9.8.7: exit 0', 0, $r[0] );
boot_check( 'header 9.8.7: version', '9.8.7', $r[1]['version'] );
boot_check( 'header 9.8.7: both URLs', array( true, true ), array( boot_ends_with( $r[1]['urls'][0], 'immich-link.js?tainacan_immich_v=9.8.7' ), boot_ends_with( $r[1]['urls'][1], 'immich-link-form.js?tainacan_immich_v=9.8.7' ) ) );

$odd = array( 'empty' => 'Version:', 'removed' => null, '1.2' => 'Version: 1.2', '1.2.3-beta' => 'Version: 1.2.3-beta', 'abc' => 'Version: abc', 'v1.2.3' => 'Version: v1.2.3', '1.2.3.4' => 'Version: 1.2.3.4' );
foreach ( $odd as $label => $line ) {
	$r = boot_run_copy( $tmp, $src, $line, false, array() );
	boot_check( "odd header ($label): fallback 0", '0', isset( $r[1]['version'] ) ? $r[1]['version'] : null );
	boot_check( "odd header ($label): both URLs", array( true, true ), isset( $r[1]['urls'] ) ? array( boot_ends_with( $r[1]['urls'][0], 'immich-link.js?tainacan_immich_v=0' ), boot_ends_with( $r[1]['urls'][1], 'immich-link-form.js?tainacan_immich_v=0' ) ) : null );
}

$r = boot_run_copy( $tmp, $src, 'Version: 1.2.3 ', true, array() );
boot_check( 'CRLF file with trailing space: version', '1.2.3', isset( $r[1]['version'] ) ? $r[1]['version'] : null );

$r = boot_run_copy( $tmp, $src, 'Version: 5.5.5', false, array( 'BOOT_NO_FGD' => '1' ) );
boot_check( 'no get_file_data: exit 0', 0, $r[0] );
boot_check( 'no get_file_data: fallback 0', '0', isset( $r[1]['version'] ) ? $r[1]['version'] : null );

$r = boot_run_copy( $tmp, $src, 'Version: 4.4.4', false, array( 'BOOT_BASE' => 'https://wp.example.test/wp-content/plugins/tainacan-immich/?x=1' ) );
$u = isset( $r[1]['urls'][0] ) ? $r[1]['urls'][0] : '';
boot_check( 'base URL with query: uses &', true, false !== strpos( $u, '&tainacan_immich_v=4.4.4' ) );
boot_check( 'base URL with query: one ?', 1, substr_count( $u, '?' ) );

// Remove the temp dir the test made (only files created above).
@unlink( $plugin_dir . '/tainacan-immich.php' );
@unlink( $plugin_dir . '/metadata-types/class-tainacan-immich-link.php' );
@rmdir( $plugin_dir . '/metadata-types' );
@rmdir( $plugin_dir );
@unlink( $tmp . '/runner.php' );
@rmdir( $tmp );

echo "\n== Tainacan missing branch\n";
$pl = boot_hooks( 'plugins_loaded' )[0][1];
$pl();
boot_check( 'Immich_Rest not loaded without Tainacan', false, class_exists( '\\Tainacan\\Immich_Rest', false ) );
boot_check( 'Immich_Client not loaded without Tainacan', false, class_exists( '\\Tainacan\\Immich_Client', false ) );
$notice = boot_hooks( 'admin_notices' );
boot_check( 'admin notice hooked', 1, count( $notice ) );
ob_start();
$notice[0][1]();
$html = ob_get_clean();
boot_check( 'notice says Tainacan is required', true, false !== strpos( $html, 'requires Tainacan plugin' ) );
boot_check( 'notice is an error notice', true, false !== strpos( $html, 'notice-error' ) );
boot_check( 'Immich_Updater not loaded without Tainacan', false, class_exists( '\\Tainacan\\Immich_Updater', false ) );

echo "\n== Tainacan present branch (source check)\n";
$src = file_get_contents( dirname( __DIR__ ) . '/tainacan-immich/tainacan-immich.php' );
boot_check( 'requires client', true, false !== strpos( $src, "require_once(__DIR__ . '/class-tainacan-immich-client.php');" ) );
boot_check( 'requires rest', true, false !== strpos( $src, "require_once(__DIR__ . '/class-tainacan-immich-rest.php');" ) );
boot_check( 'rest_api_init hook', true, false !== strpos( $src, "add_action( 'rest_api_init', array( '\\\\Tainacan\\\\Immich_Rest', 'register_routes' ) );" ) );
boot_check( 'admin_enqueue_scripts at priority 81', true, false !== strpos( $src, "add_action( 'admin_enqueue_scripts', array( '\\\\Tainacan\\\\Immich_Rest', 'enqueue_config' ), 81 );" ) );

$immich_src = file_get_contents( dirname( __DIR__ ) . '/tainacan-immich/class-tainacan-immich.php' );
// HEAD has two add_action calls in this class (init and add_admin_menu). No new one may be added.
boot_check( 'no new hooks in Immich class (same count as HEAD)', 2, substr_count( $immich_src, 'add_action(' ) );
boot_check( 'no add_filter in Immich class', 0, substr_count( $immich_src, 'add_filter(' ) );

echo "\n== Update checker (source and top level)\n";
$require_updater = "require_once(__DIR__ . '/class-tainacan-immich-updater.php');";
$register_updater = "\\Tainacan\\Immich_Updater::register( __FILE__ );";
boot_check( 'updater require line in source', 1, substr_count( $src, $require_updater ) );
boot_check( 'updater register line in source', 1, substr_count( $src, $register_updater ) );
boot_check( 'updater require is before register', true, strpos( $src, $require_updater ) < strpos( $src, $register_updater ) );
boot_check( 'updater lines are after admin_enqueue_scripts line', true, strpos( $src, "'admin_enqueue_scripts', array( '\\\\Tainacan\\\\Immich_Rest', 'enqueue_config' ), 81 );" ) < strpos( $src, $require_updater ) );
boot_check( 'Update URI header line', true, false !== strpos( $src, 'Update URI: https://github.com/tmdinosaurcenter/tainacan_immich' ) );
boot_check( 'repo constant default set by main file', 'tmdinosaurcenter/tainacan_immich', TAINACAN_IMMICH_UPDATE_REPO );
boot_check( 'no updater hook at top level (transient filter)', 0, count( boot_hooks( 'pre_set_site_transient_update_plugins' ) ) );
boot_check( 'no updater hook at top level (plugins_api)', 0, count( boot_hooks( 'plugins_api' ) ) );
boot_check( 'no tmdc name in plugin code', 0, substr_count( $src . $immich_src, 'tmdc' ) );

echo "\n== Version places (0.3.1) and release docs\n";
$readme_txt = file_get_contents( dirname( __DIR__ ) . '/tainacan-immich/README.txt' );
$pkg        = json_decode( file_get_contents( dirname( __DIR__ ) . '/tainacan-immich/package.json' ), true );
$root_md    = file_get_contents( dirname( __DIR__ ) . '/README.md' );
boot_check( 'main file Version 0.3.1', true, false !== strpos( $src, "\nVersion: 0.3.1\n" ) );
boot_check( 'main file Stable tag 0.3.1', true, false !== strpos( $src, "\nStable tag: 0.3.1\n" ) );
boot_check( 'README.txt Stable tag 0.3.1', true, false !== strpos( $readme_txt, "\nStable tag: 0.3.1\n" ) );
boot_check( 'README.txt Requires at least 6.5', true, false !== strpos( $readme_txt, "\nRequires at least: 6.5\n" ) );
boot_check( 'README.txt changelog entry 0.3.1', true, false !== strpos( $readme_txt, "\n= 0.3.1 =\n" ) );
boot_check( 'README.txt 0.3.1 entry above 0.3.0 entry', true, strpos( $readme_txt, "\n= 0.3.1 =\n" ) < strpos( $readme_txt, "\n= 0.3.0 =\n" ) );
boot_check( 'README.txt changelog entry 0.3.0', true, false !== strpos( $readme_txt, "\n= 0.3.0 =\n" ) );
boot_check( 'README.txt 0.3.0 entry above 0.2.2 entry', true, strpos( $readme_txt, "\n= 0.3.0 =\n" ) < strpos( $readme_txt, "\n= 0.2.2 =\n" ) );
boot_check( 'README.txt changelog entry 0.2.2', true, false !== strpos( $readme_txt, "\n= 0.2.2 =\n" ) );
boot_check( 'README.txt 0.2.2 entry above 0.2.1 entry', true, strpos( $readme_txt, "\n= 0.2.2 =\n" ) < strpos( $readme_txt, "\n= 0.2.1 =\n" ) );
boot_check( 'root README mentions tainacan_immich_v', true, false !== strpos( $root_md, 'tainacan_immich_v' ) );
boot_check( 'README.txt changelog entry 0.2.1', true, false !== strpos( $readme_txt, "\n= 0.2.1 =\n" ) );
boot_check( 'README.txt 0.2.1 entry above 0.2.0 entry', true, strpos( $readme_txt, "\n= 0.2.1 =\n" ) < strpos( $readme_txt, "\n= 0.2.0 =\n" ) );
boot_check( 'README.txt changelog entry 0.2.0', true, false !== strpos( $readme_txt, "\n= 0.2.0 =\n" ) );
boot_check( 'README.txt 0.2.0 entry above 0.1.0 entry', true, strpos( $readme_txt, "\n= 0.2.0 =\n" ) < strpos( $readme_txt, "\n= 0.1.0 =\n" ) );
boot_check( 'package.json version 0.3.1', '0.3.1', isset( $pkg['version'] ) ? $pkg['version'] : null );
boot_check( 'root README links docs/releasing.md', true, false !== strpos( $root_md, '`docs/releasing.md`' ) );
boot_check( 'docs/releasing.md has the Releasing heading', true, 0 === strpos( file_get_contents( dirname( __DIR__ ) . '/docs/releasing.md' ), "# Releasing\n" ) );
boot_check( 'root README has the update-notice feature line', true, false !== strpos( $root_md, '**Update notices.**' ) );
boot_check( 'root README links the latest release', true, false !== strpos( $root_md, '(https://github.com/tmdinosaurcenter/tainacan_immich/releases/latest)' ) );
boot_check( 'root README has Installation heading', true, false !== strpos( $root_md, "\n## Installation\n" ) );
boot_check( 'root README has Configuration heading', true, false !== strpos( $root_md, "\n## Configuration\n" ) );
boot_check( 'root README Installation before Configuration', true, strpos( $root_md, "\n## Installation\n" ) < strpos( $root_md, "\n## Configuration\n" ) );
boot_check( 'root README names the update filter', true, false !== strpos( $root_md, 'tainacan_immich_enable_update_check' ) );
boot_check( 'root README names the repo constant', true, false !== strpos( $root_md, 'TAINACAN_IMMICH_UPDATE_REPO' ) );
$root_prose = preg_replace( '/^```.*?^```[^\n]*$/ms', '', $root_md );
boot_check( 'root README prose has no "not supported"/"not yet"/"removed"', 0, preg_match( '/not supported|not yet|removed/i', $root_prose ) );
boot_check( 'root README ends with License section', true, false !== strpos( $root_md, "\n## License\n\nGNU General Public License version 3. See `LICENSE`." ) );

echo "\n== Boot summary: {$GLOBALS['boot_passes']} passed, {$GLOBALS['boot_fails']} failed\n";
exit( $GLOBALS['boot_fails'] > 0 ? 1 : 0 );
