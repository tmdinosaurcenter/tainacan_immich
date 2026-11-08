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
boot_check( 'script url', 'https://wp.example.test/wp-content/plugins/tainacan-immich/build/immich-link.js', $args[2] );
boot_check( 'class loads after callback', true, class_exists( '\\Tainacan\\Metadata_Types\\Immich_Link', false ) );

$helper = new Boot_Fake_Helper();
$cb     = boot_hooks( 'tainacan-register-vuejs-component' )[0][1];
$cb( $helper );
$args = $helper->calls[0][1];
boot_check( 'form handle', 'tainacan-immich-link-form', $args[0] );
boot_check( 'form script url', 'https://wp.example.test/wp-content/plugins/tainacan-immich/build/immich-link-form.js', $args[1] );

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

echo "\n== Version places (0.2.0) and release docs\n";
$readme_txt = file_get_contents( dirname( __DIR__ ) . '/tainacan-immich/README.txt' );
$pkg        = json_decode( file_get_contents( dirname( __DIR__ ) . '/tainacan-immich/package.json' ), true );
$root_md    = file_get_contents( dirname( __DIR__ ) . '/README.md' );
boot_check( 'main file Version 0.2.0', true, false !== strpos( $src, "\nVersion: 0.2.0\n" ) );
boot_check( 'main file Stable tag 0.2.0', true, false !== strpos( $src, "\nStable tag: 0.2.0\n" ) );
boot_check( 'README.txt Stable tag 0.2.0', true, false !== strpos( $readme_txt, "\nStable tag: 0.2.0\n" ) );
boot_check( 'README.txt Requires at least 6.5', true, false !== strpos( $readme_txt, "\nRequires at least: 6.5\n" ) );
boot_check( 'README.txt changelog entry 0.2.0', true, false !== strpos( $readme_txt, "\n= 0.2.0 =\n" ) );
boot_check( 'README.txt 0.2.0 entry above 0.1.0 entry', true, strpos( $readme_txt, "\n= 0.2.0 =\n" ) < strpos( $readme_txt, "\n= 0.1.0 =\n" ) );
boot_check( 'package.json version 0.2.0', '0.2.0', isset( $pkg['version'] ) ? $pkg['version'] : null );
boot_check( 'root README has Releasing before License', true, false !== strpos( $root_md, "\n## Releasing\n" ) && strpos( $root_md, "\n## Releasing\n" ) < strpos( $root_md, "\n## License\n" ) );
boot_check( 'root README has the update-notice feature line', true, false !== strpos( $root_md, '**Update notices.**' ) );

echo "\n== Boot summary: {$GLOBALS['boot_passes']} passed, {$GLOBALS['boot_fails']} failed\n";
exit( $GLOBALS['boot_fails'] > 0 ? 1 : 0 );
