<?php
/*
Plugin Name: Tainacan Immich
Plugin URI: https://tainacan.org/
Description: Links Tainacan items to assets in an Immich photo server.
Author: Steve

Version: 0.0.1
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.1
Stable tag: 0.0.1
Text Domain: tainacan-immich
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: tainacan
*/

if (!defined('ABSPATH')) {
    exit; // Avoid direct access
}

add_action('plugins_loaded', function() {

    if ( class_exists('\Tainacan\Pages') ) {
        require_once(__DIR__ . '/class-tainacan-immich.php');
        $Tainacan_Immich_Page = \Tainacan\Immich::get_instance();
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Tainacan Immich plugin requires Tainacan plugin to be installed and activated.', 'tainacan-immich') . '</p></div>';
        });
    }
    
});
