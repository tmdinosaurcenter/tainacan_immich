<?php

namespace Tainacan;

class Immich extends \Tainacan\Pages {
	use \Tainacan\Traits\Singleton_Instance;

	const OPTION_GROUP   = 'tainacan_immich_settings';
	const OPTION_URL     = 'tainacan_immich_url';
	const OPTION_API_KEY = 'tainacan_immich_api_key';

    public function init() {
		parent::init();
		add_action( 'admin_init', array( &$this, 'register_settings' ) );
	}

	public function register_settings() : void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_url' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
	}

	public static function sanitize_url( $value ) : string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$url  = untrailingslashit( esc_url_raw( trim( $value ), array( 'http', 'https' ) ) );
		$host = '' === $url ? null : wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || empty( $host ) ) {
			add_settings_error(
				self::OPTION_URL,
				'tainacan_immich_bad_url',
				__( 'The Immich URL is not valid. Use a full http or https URL.', 'tainacan-immich' )
			);
			return (string) get_option( self::OPTION_URL, '' );
		}
		return $url;
	}

	public static function sanitize_api_key( $value ) : string {
		if ( ! is_string( $value ) ) {
			$value = '';
		}
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return (string) get_option( self::OPTION_API_KEY, '' );
		}
		return $value;
	}

	protected function get_page_slug() : string {
		return 'tainacan_immich';
	}

	public function add_admin_menu() {
		$immich_page_suffix = add_submenu_page(
			$this->tainacan_root_menu_slug,
			__('Immich', 'tainacan'),
			'<span class="icon">' . $this->get_svg_icon( 'star' ) . '</span><span class="menu-text">' .__( 'Immich', 'tainacan-immich' ) . '</span>',
			'read',
			'tainacan_immich',
			array( &$this, 'render_page' ),
            3
		);
		add_action( 'load-' . $immich_page_suffix, array( &$this, 'load_page' ) );
	}

    function admin_enqueue_css() {
        $asset_file = include( plugin_dir_path( __FILE__ ) . 'build/index.asset.php');

		wp_enqueue_style(
			'tainacan-immich-page',
			plugins_url( 'build/index.css', __FILE__ ),
			$asset_file['dependencies'],
			$asset_file['version']
		);
	}

	function admin_enqueue_js() {
        $asset_file = include( plugin_dir_path( __FILE__ ) . 'build/index.asset.php');

		wp_enqueue_script(
			'tainacan-immich-page',
			plugins_url( 'build/index.js', __FILE__ ),
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);
	}

    public function render_page_content() {
		require_once('page.php');
	}
}