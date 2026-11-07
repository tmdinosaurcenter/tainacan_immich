<?php
/**
 * Shared stubs for the Immich client, REST and metadata type tests.
 * All values are fake. No network. Include this file once per test script.
 */

namespace Tainacan {
	class Pages {
		public function init() {
		}
	}
}

namespace Tainacan\Traits {
	trait Singleton_Instance {
	}
}

namespace Tainacan\Metadata_Types {
	class Metadata_Type {
		public $name;
		public $description;
		public $primitive_type;
		public $component;
		public $form_component;
		public $default_options = array();
		public $preview_template;
		public $options         = array();
		public $errors          = array();

		public function __construct() {
		}
		public function set_name( $v ) {
			$this->name = $v;
		}
		public function set_description( $v ) {
			$this->description = $v;
		}
		public function set_primitive_type( $v ) {
			$this->primitive_type = $v;
		}
		public function set_component( $v ) {
			$this->component = $v;
		}
		public function set_form_component( $v ) {
			$this->form_component = $v;
		}
		public function set_default_options( $v ) {
			$this->default_options = $v;
		}
		public function set_preview_template( $v ) {
			$this->preview_template = $v;
		}
		public function get_option( $name ) {
			if ( array_key_exists( $name, $this->options ) ) {
				return $this->options[ $name ];
			}
			return isset( $this->default_options[ $name ] ) ? $this->default_options[ $name ] : null;
		}
		public function add_error( $msg ) {
			$this->errors[] = $msg;
		}
	}
}

namespace Tainacan\Entities {
	class Item_Metadata_Entity {
		public $value;
		public $multiple;
		public function __construct( $value, $multiple = false ) {
			$this->value    = $value;
			$this->multiple = $multiple;
		}
		public function get_value() {
			return $this->value;
		}
		public function is_multiple() {
			return $this->multiple;
		}
		public function get_multivalue_prefix() {
			return '<span>';
		}
		public function get_multivalue_suffix() {
			return '</span>';
		}
		public function get_multivalue_separator() {
			return ' | ';
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/fake/' );
	}
	if ( ! defined( 'MB_IN_BYTES' ) ) {
		define( 'MB_IN_BYTES', 1048576 );
	}

	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) {
			throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
		}
	);

	$GLOBALS['test_options']  = array();
	$GLOBALS['test_http']     = array(); // queued fake responses
	$GLOBALS['test_http_log'] = array(); // recorded calls: array( url, args )
	$GLOBALS['test_inline']   = array();
	$GLOBALS['test_routes']   = array();
	$GLOBALS['test_passes']   = 0;
	$GLOBALS['test_failures'] = 0;

	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
	function esc_attr( $text ) {
		return esc_html( $text );
	}
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
	function esc_url( $url ) {
		return esc_html( $url );
	}
	function esc_url_raw( $url ) {
		return (string) $url;
	}
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default;
	}
	function wp_salt( $scheme = 'auth' ) {
		return isset( $GLOBALS['test_salt'] ) ? $GLOBALS['test_salt'] : 'fake-salt-' . $scheme;
	}
	function rest_url( $path = '' ) {
		return 'https://wp.example.test/wp-json/' . ltrim( $path, '/' );
	}
	function add_query_arg( $args, $url ) {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
	function wp_create_nonce( $action ) {
		return 'fake-nonce';
	}
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['test_inline'][] = array( $handle, $data, $position );
		return true;
	}
	function register_rest_route( $ns, $route, $args ) {
		$GLOBALS['test_routes'][ $ns . $route ] = $args;
	}
	function current_user_can( $cap ) {
		return false;
	}
	function __return_true() {
		return true;
	}
	function status_header( $code ) {
	}

	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['test_http_log'][] = array( $url, $args );
		if ( empty( $GLOBALS['test_http'] ) ) {
			throw new RuntimeException( 'wp_remote_get called with no queued response' );
		}
		return array_shift( $GLOBALS['test_http'] );
	}
	function wp_remote_retrieve_response_code( $r ) {
		return $r['code'];
	}
	function wp_remote_retrieve_body( $r ) {
		return $r['body'];
	}
	function wp_remote_retrieve_header( $r, $name ) {
		return isset( $r['headers'][ $name ] ) ? $r['headers'][ $name ] : '';
	}

	// ---- Test helpers ----

	function t_reset() {
		$GLOBALS['test_options']  = array();
		$GLOBALS['test_http']     = array();
		$GLOBALS['test_http_log'] = array();
		$GLOBALS['test_inline']   = array();
	}
	function t_configure() {
		$GLOBALS['test_options']['tainacan_immich_url']     = 'https://immich.example.org';
		$GLOBALS['test_options']['tainacan_immich_api_key'] = 'fake-key-abc123';
	}
	function t_queue( $code, $body = '', $headers = array() ) {
		$GLOBALS['test_http'][] = array(
			'code'    => $code,
			'body'    => $body,
			'headers' => $headers,
		);
	}
	function t_queue_error() {
		$GLOBALS['test_http'][] = new WP_Error( 'http_request_failed', 'cURL error: https://immich.example.org fake-key-abc123' );
	}
	function t_check( $label, $expected, $actual ) {
		if ( $expected === $actual ) {
			$GLOBALS['test_passes']++;
			echo "PASS  $label\n";
		} else {
			$GLOBALS['test_failures']++;
			echo "FAIL  $label: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
		}
	}
	function t_contains( $label, $needle, $haystack ) {
		t_check( $label, true, false !== strpos( (string) $haystack, $needle ) );
	}
	function t_not_contains( $label, $needle, $haystack ) {
		t_check( $label, true, false === strpos( (string) $haystack, $needle ) );
	}
	function t_run( $name, $fn ) {
		echo "\n== $name\n";
		try {
			$fn();
		} catch ( \Throwable $e ) {
			$GLOBALS['test_failures']++;
			echo 'FAIL  ' . $name . ' threw ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
		}
	}
	function t_summary() {
		echo "\n== Summary: {$GLOBALS['test_passes']} passed, {$GLOBALS['test_failures']} failed\n";
		exit( $GLOBALS['test_failures'] > 0 ? 1 : 0 );
	}

	require_once __DIR__ . '/../tainacan-immich/class-tainacan-immich.php';
	require_once __DIR__ . '/../tainacan-immich/class-tainacan-immich-client.php';
	require_once __DIR__ . '/../tainacan-immich/class-tainacan-immich-rest.php';
	require_once __DIR__ . '/../tainacan-immich/metadata-types/class-tainacan-immich-link.php';
}
