<?php
/*
Plugin Name: Tainacan Immich
Plugin URI: https://tainacan.org/
Description: Links Tainacan items to assets in an Immich photo server.
Author: Steve

Version: 0.2.1
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.1
Stable tag: 0.2.1
Text Domain: tainacan-immich
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: tainacan
Update URI: https://github.com/tmdinosaurcenter/tainacan_immich
*/

if (!defined('ABSPATH')) {
    exit; // Avoid direct access
}

if ( ! defined( 'TAINACAN_IMMICH_UPDATE_REPO' ) ) {
    define( 'TAINACAN_IMMICH_UPDATE_REPO', 'tmdinosaurcenter/tainacan_immich' );
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
            plugin_dir_url( __FILE__ ) . 'build/immich-link.js'
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
            plugin_dir_url( __FILE__ ) . 'build/immich-link-form.js'
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
