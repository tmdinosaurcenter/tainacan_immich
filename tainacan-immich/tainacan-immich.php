<?php
/*
Plugin Name: Tainacan Immich
Plugin URI: https://tainacan.org/
Description: Links Tainacan items to assets in an Immich photo server.
Author: Steve

Version: 0.3.1
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.1
Stable tag: 0.3.1
Text Domain: tainacan-immich
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: tainacan
Update URI: https://github.com/tmdinosaurcenter/tainacan_immich
*/

if (!defined('ABSPATH')) {
    exit; // Avoid direct access
}

if ( ! defined( 'TAINACAN_IMMICH_UPDATE_REPO' ) ) {
    define( 'TAINACAN_IMMICH_UPDATE_REPO', 'tmdinosaurcenter/tainacan_immich' );
}

if ( ! function_exists( 'tainacan_immich_get_version' ) ) {
    /**
     * Returns the plugin version from the "Version:" header of this file.
     * Read once per request. Falls back to '0' when the value is missing or not X.Y.Z.
     */
    function tainacan_immich_get_version() {
        static $version = null;
        if ( null !== $version ) {
            return $version;
        }
        $raw = '';
        if ( function_exists( 'get_file_data' ) ) {
            $data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
            $raw  = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
        }
        if ( 1 === preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', $raw ) ) {
            $version = $raw;
        } else {
            $version = '0';
        }
        return $version;
    }
}

if ( ! function_exists( 'tainacan_immich_script_url' ) ) {
    /**
     * Returns the URL of a bundle in build/ with the plugin version in the query.
     * The argument is not named "ver": WordPress replaces that one with the Tainacan version.
     */
    function tainacan_immich_script_url( $file_name ) {
        $url = plugin_dir_url( __FILE__ ) . 'build/' . $file_name;
        $sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
        return $url . $sep . 'tainacan_immich_v=' . rawurlencode( tainacan_immich_get_version() );
    }
}

if ( ! function_exists( 'tainacan_immich_register_metadata_type' ) ) {
    /**
     * Registers the "Immich link" metadata type. The bundle is registered by URL only.
     */
    function tainacan_immich_register_metadata_type( $helper ) {
        require_once( __DIR__ . '/metadata-types/class-tainacan-immich-link.php' );
        $helper->register_metadata_type(
            'tainacan-immich-link',
            '\\Tainacan\\Metadata_Types\\Immich_Link',
            tainacan_immich_script_url( 'immich-link.js' )
        );
    }
}
add_action( 'tainacan-register-metadata-type', 'tainacan_immich_register_metadata_type' );

if ( ! function_exists( 'tainacan_immich_register_vuejs_component' ) ) {
    /**
     * Registers the options-form component of the metadata type.
     */
    function tainacan_immich_register_vuejs_component( $helper ) {
        $helper->register_vuejs_component(
            'tainacan-immich-link-form',
            tainacan_immich_script_url( 'immich-link-form.js' )
        );
    }
}
add_action( 'tainacan-register-vuejs-component', 'tainacan_immich_register_vuejs_component' );

add_action('plugins_loaded', function() {

    if ( class_exists('\Tainacan\Pages') ) {
        require_once(__DIR__ . '/class-tainacan-immich.php');
        $Tainacan_Immich_Page = \Tainacan\Immich::get_instance();
        require_once(__DIR__ . '/class-tainacan-immich-client.php');
        require_once(__DIR__ . '/class-tainacan-immich-rest.php');
        add_action( 'rest_api_init', array( '\\Tainacan\\Immich_Rest', 'register_routes' ) );
        add_action( 'admin_enqueue_scripts', array( '\\Tainacan\\Immich_Rest', 'enqueue_config' ), 81 );
        require_once(__DIR__ . '/class-tainacan-immich-updater.php');
        \Tainacan\Immich_Updater::register( __FILE__ );
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Tainacan Immich plugin requires Tainacan plugin to be installed and activated.', 'tainacan-immich') . '</p></div>';
        });
    }
    
});
