<?php
/**
 * Tests for the Immich settings form.
 *
 * Covers class-tainacan-immich.php (sanitize callbacks, init, register_settings)
 * and page.php (rendered HTML). Plain PHP script with stubs. No WordPress needed.
 * All values are fake. No network calls.
 *
 * Run: php tests/test-settings-sanitize.php
 * Exit code 0 = all pass, 1 = at least one failure.
 */

namespace Tainacan {
	class Pages {
		public function init() {
			$GLOBALS['test_parent_init_calls'] = ( isset( $GLOBALS['test_parent_init_calls'] ) ? $GLOBALS['test_parent_init_calls'] : 0 ) + 1;
		}
	}
}

namespace Tainacan\Traits {
	trait Singleton_Instance {
	}
}

namespace {

	// Turn every PHP warning or notice into an exception, so the tests fail on warnings.
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) {
			throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
		}
	);

	$GLOBALS['test_options']         = array();
	$GLOBALS['test_settings_errors'] = array();
	$GLOBALS['test_registered']      = array();
	$GLOBALS['test_actions']         = array();
	$GLOBALS['test_caps']            = array();
	$GLOBALS['test_passes']          = 0;
	$GLOBALS['test_failures']        = 0;

	// ---- WordPress stubs ------------------------------------------------------

	function __( $text, $domain = 'default' ) {
		return $text;
	}

	function _e( $text, $domain = 'default' ) {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}

	// Simple version: only http and https pass. Scheme-less text gives ''.
	function esc_url_raw( $url, $protocols = null ) {
		if ( null === $protocols ) {
			$protocols = array( 'http', 'https' );
		}
		$url   = (string) $url;
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), $protocols, true ) ) {
			return '';
		}
		return $url;
	}

	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function sanitize_text_field( $value ) {
		// Simple version: strip tags and line breaks, then trim.
		$value = str_replace( array( "\r", "\n", "\t" ), '', (string) $value );
		return trim( strip_tags( $value ) );
	}

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default;
	}

	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['test_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}

	function register_setting( $group, $name, $args = array() ) {
		$GLOBALS['test_registered'][ $name ] = array(
			'group' => $group,
			'args'  => $args,
		);
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['test_actions'][] = array(
			'hook'     => $hook,
			'callback' => $callback,
		);
		return true;
	}

	function current_user_can( $capability ) {
		return in_array( $capability, $GLOBALS['test_caps'], true );
	}

	function admin_url( $path = '' ) {
		return 'http://fake.example.test/wp-admin/' . $path;
	}

	function settings_errors( $setting = '', $sanitize = false, $hide_on_update = false ) {
		echo "<!-- settings_errors called -->\n";
	}

	function settings_fields( $option_group ) {
		echo '<input type="hidden" name="option_page" value="' . htmlspecialchars( $option_group, ENT_QUOTES, 'UTF-8' ) . '" />';
	}

	function submit_button( $text = 'Save Changes' ) {
		echo '<p class="submit"><input type="submit" value="' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '" /></p>';
	}

	// ---- Test helpers ---------------------------------------------------------

	function t_reset() {
		$GLOBALS['test_options']         = array();
		$GLOBALS['test_settings_errors'] = array();
		$GLOBALS['test_caps']            = array();
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
		t_check( $label, true, false !== strpos( $haystack, $needle ) );
	}

	function t_not_contains( $label, $needle, $haystack ) {
		t_check( $label, true, false === strpos( $haystack, $needle ) );
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

	function t_error_count() {
		return count( $GLOBALS['test_settings_errors'] );
	}

	function t_render_page() {
		ob_start();
		include __DIR__ . '/../tainacan-immich/page.php';
		return ob_get_clean();
	}

	require __DIR__ . '/../tainacan-immich/class-tainacan-immich.php';

	$OPT_URL = \Tainacan\Immich::OPTION_URL;
	$OPT_KEY = \Tainacan\Immich::OPTION_API_KEY;
	$OLD_URL = 'https://old.example.org';
	$OLD_KEY = 'fake-key-123';

	// ---- Edge 1: empty URL --------------------------------------------------

	t_run(
		'Edge 1: empty URL field saves empty, no error',
		function () use ( $OPT_URL, $OLD_URL ) {
			t_reset();
			t_check( 'E1 empty string gives empty', '', \Tainacan\Immich::sanitize_url( '' ) );
			t_check( 'E1 spaces only gives empty', '', \Tainacan\Immich::sanitize_url( '   ' ) );
			$GLOBALS['test_options'][ $OPT_URL ] = $OLD_URL;
			t_check( 'E1 cleared URL does not keep old value', '', \Tainacan\Immich::sanitize_url( '' ) );
			t_check( 'E1 no error notice for empty URL', 0, t_error_count() );
		}
	);

	// ---- Edge 2: spaces around URL -----------------------------------------

	t_run(
		'Edge 2: spaces around URL are trimmed',
		function () {
			t_reset();
			t_check( 'E2 leading and trailing spaces removed', 'https://immich.example.org', \Tainacan\Immich::sanitize_url( '  https://immich.example.org  ' ) );
			t_check( 'E2 tab and spaces removed', 'https://immich.example.org', \Tainacan\Immich::sanitize_url( "\t https://immich.example.org \t" ) );
			t_check( 'E2 no error notice', 0, t_error_count() );
		}
	);

	// ---- Edge 3: trailing slash --------------------------------------------

	t_run(
		'Edge 3: trailing slash is removed',
		function () {
			t_reset();
			t_check( 'E3 single trailing slash removed', 'https://immich.example.org', \Tainacan\Immich::sanitize_url( 'https://immich.example.org/' ) );
			t_check( 'E3 several trailing slashes removed', 'https://immich.example.org', \Tainacan\Immich::sanitize_url( 'https://immich.example.org//' ) );
			t_check( 'E3 path base kept, slash removed', 'https://example.org/immich', \Tainacan\Immich::sanitize_url( 'https://example.org/immich/' ) );
		}
	);

	// ---- Edge 4: bad scheme ------------------------------------------------

	t_run(
		'Edge 4: bad scheme gives error and keeps old value',
		function () use ( $OPT_URL, $OLD_URL ) {
			foreach ( array( 'ftp://x', 'javascript:alert(1)' ) as $bad ) {
				t_reset();
				$GLOBALS['test_options'][ $OPT_URL ] = $OLD_URL;
				t_check( "E4 '$bad' keeps old value", $OLD_URL, \Tainacan\Immich::sanitize_url( $bad ) );
				t_check( "E4 '$bad' adds one error", 1, t_error_count() );
				if ( 1 === t_error_count() ) {
					$err = $GLOBALS['test_settings_errors'][0];
					t_check( "E4 '$bad' error setting is URL option", $OPT_URL, $err['setting'] );
					t_check( "E4 '$bad' error code", 'tainacan_immich_bad_url', $err['code'] );
					t_check( "E4 '$bad' error type", 'error', $err['type'] );
				}
			}
		}
	);

	// ---- Edge 5: not a URL -------------------------------------------------

	t_run(
		'Edge 5: text that is not a URL gives error and keeps old value',
		function () use ( $OPT_URL, $OLD_URL ) {
			foreach ( array( 'not a url', 'https://', 'http://', 'immich.example.org' ) as $bad ) {
				t_reset();
				$GLOBALS['test_options'][ $OPT_URL ] = $OLD_URL;
				t_check( "E5 '$bad' keeps old value", $OLD_URL, \Tainacan\Immich::sanitize_url( $bad ) );
				t_check( "E5 '$bad' adds one error", 1, t_error_count() );
			}
		}
	);

	t_run(
		'Edge 5: not a URL with no old value gives empty',
		function () use ( $OPT_URL ) {
			t_reset();
			t_check( 'E5 not a url, nothing stored gives empty', '', \Tainacan\Immich::sanitize_url( 'not a url' ) );
			t_check( 'E5 error still added', 1, t_error_count() );
			unset( $GLOBALS['test_options'][ $OPT_URL ] );
		}
	);

	// ---- Edge 6: non-string input ------------------------------------------

	t_run(
		'Edge 6: non-string URL returns empty, no PHP warning',
		function () {
			t_reset();
			t_check( 'E6 array URL gives empty', '', \Tainacan\Immich::sanitize_url( array( 'https://immich.example.org' ) ) );
			t_check( 'E6 int URL gives empty', '', \Tainacan\Immich::sanitize_url( 42 ) );
			t_check( 'E6 null URL gives empty', '', \Tainacan\Immich::sanitize_url( null ) );
		}
	);

	t_run(
		'Edge 6: non-string API key keeps old value, no PHP warning',
		function () use ( $OPT_KEY, $OLD_KEY ) {
			t_reset();
			$GLOBALS['test_options'][ $OPT_KEY ] = $OLD_KEY;
			t_check( 'E6 array key keeps old value', $OLD_KEY, \Tainacan\Immich::sanitize_api_key( array( 'fake' ) ) );
			t_check( 'E6 int key keeps old value', $OLD_KEY, \Tainacan\Immich::sanitize_api_key( 42 ) );
			t_check( 'E6 null key keeps old value', $OLD_KEY, \Tainacan\Immich::sanitize_api_key( null ) );
		}
	);

	// ---- Edge 7: empty API key keeps stored key ----------------------------

	t_run(
		'Edge 7: empty API key field keeps stored key',
		function () use ( $OPT_KEY, $OLD_KEY ) {
			t_reset();
			$GLOBALS['test_options'][ $OPT_KEY ] = $OLD_KEY;
			t_check( 'E7 empty string keeps stored key', $OLD_KEY, \Tainacan\Immich::sanitize_api_key( '' ) );
			t_check( 'E7 spaces only keeps stored key', $OLD_KEY, \Tainacan\Immich::sanitize_api_key( '   ' ) );
			t_check( 'E7 tags only keeps stored key', $OLD_KEY, \Tainacan\Immich::sanitize_api_key( '<b></b>' ) );
		}
	);

	t_run(
		'Edge 7: empty API key with nothing stored gives empty',
		function () use ( $OPT_KEY ) {
			t_reset();
			unset( $GLOBALS['test_options'][ $OPT_KEY ] );
			t_check( 'E7 empty key, no stored key gives empty', '', \Tainacan\Immich::sanitize_api_key( '' ) );
		}
	);

	// ---- Edge 8: spaces and HTML tags in API key ---------------------------

	t_run(
		'Edge 8: API key is sanitized and trimmed before save',
		function () use ( $OPT_KEY, $OLD_KEY ) {
			t_reset();
			$GLOBALS['test_options'][ $OPT_KEY ] = $OLD_KEY;
			t_check( 'E8 spaces and tags removed', 'fake key 123', \Tainacan\Immich::sanitize_api_key( '  fake key <b>123</b>  ' ) );
			t_check( 'E8 tag inside key removed', 'fake-key-123', \Tainacan\Immich::sanitize_api_key( '<b>fake</b>-key-123' ) );
			t_check( 'E8 plain key unchanged', 'fake-key-123', \Tainacan\Immich::sanitize_api_key( 'fake-key-123' ) );
		}
	);

	// ---- Edge 12: callbacks are idempotent ---------------------------------

	t_run(
		'Edge 12: sanitize_url gives same result when run twice',
		function () use ( $OPT_URL, $OLD_URL ) {
			$inputs = array(
				'https://immich.example.org/',
				'  https://immich.example.org/  ',
				'https://example.org/immich/',
				'',
				'   ',
				'ftp://x',
				'not a url',
			);
			foreach ( $inputs as $input ) {
				t_reset();
				$GLOBALS['test_options'][ $OPT_URL ] = $OLD_URL;
				$first  = \Tainacan\Immich::sanitize_url( $input );
				$second = \Tainacan\Immich::sanitize_url( $first );
				t_check( "E12 url '$input' stable on second run", $first, $second );
			}
		}
	);

	t_run(
		'Edge 12: sanitize_url twice on first save (nothing stored)',
		function () use ( $OPT_URL ) {
			t_reset();
			unset( $GLOBALS['test_options'][ $OPT_URL ] );
			$first  = \Tainacan\Immich::sanitize_url( 'https://immich.example.org/' );
			$second = \Tainacan\Immich::sanitize_url( 'https://immich.example.org/' );
			t_check( 'E12 first save gives same value on both calls', $first, $second );
			t_check( 'E12 first save value is clean', 'https://immich.example.org', $first );
		}
	);

	t_run(
		'Edge 12: sanitize_api_key gives same result when run twice',
		function () use ( $OPT_KEY, $OLD_KEY ) {
			t_reset();
			$GLOBALS['test_options'][ $OPT_KEY ] = $OLD_KEY;
			$inputs = array( 'fake-key-123', '  fake key <b>123</b>  ', '', '   ' );
			foreach ( $inputs as $input ) {
				$first  = \Tainacan\Immich::sanitize_api_key( $input );
				$second = \Tainacan\Immich::sanitize_api_key( $first );
				t_check( "E12 key '$input' stable on second run", $first, $second );
			}
		}
	);

	t_run(
		'Edge 12: sanitize_api_key twice on first save (nothing stored)',
		function () use ( $OPT_KEY ) {
			t_reset();
			unset( $GLOBALS['test_options'][ $OPT_KEY ] );
			$first  = \Tainacan\Immich::sanitize_api_key( 'fake-key-123' );
			$second = \Tainacan\Immich::sanitize_api_key( 'fake-key-123' );
			t_check( 'E12 first key save same on both calls', 'fake-key-123', $first );
			t_check( 'E12 second call same as first', $first, $second );
			t_check( 'E12 empty key first save stable', '', \Tainacan\Immich::sanitize_api_key( '' ) );
		}
	);

	// ---- Interfaces: init and register_settings ----------------------------

	t_run(
		'Interface: init() calls parent init and hooks admin_init',
		function () {
			t_reset();
			$GLOBALS['test_actions'] = array();
			$GLOBALS['test_parent_init_calls'] = 0;
			$obj = new \Tainacan\Immich();
			$obj->init();
			t_check( 'init calls parent::init once', 1, $GLOBALS['test_parent_init_calls'] );
			t_check( 'init adds one action', 1, count( $GLOBALS['test_actions'] ) );
			if ( 1 === count( $GLOBALS['test_actions'] ) ) {
				$action = $GLOBALS['test_actions'][0];
				t_check( 'init hook is admin_init', 'admin_init', $action['hook'] );
				t_check( 'init callback is this object', true, is_array( $action['callback'] ) && $action['callback'][0] === $obj );
				t_check( 'init callback method is register_settings', 'register_settings', $action['callback'][1] );
			}
		}
	);

	t_run(
		'Interface: register_settings() registers both options',
		function () use ( $OPT_URL, $OPT_KEY ) {
			t_reset();
			$GLOBALS['test_registered'] = array();
			$obj = new \Tainacan\Immich();
			$obj->register_settings();
			t_check( 'two options registered', 2, count( $GLOBALS['test_registered'] ) );

			$url = $GLOBALS['test_registered'][ $OPT_URL ];
			t_check( 'URL group', 'tainacan_immich_settings', $url['group'] );
			t_check( 'URL type string', 'string', $url['args']['type'] );
			t_check( 'URL sanitize callback', array( 'Tainacan\\Immich', 'sanitize_url' ), $url['args']['sanitize_callback'] );
			t_check( 'URL default empty', '', $url['args']['default'] );
			t_check( 'URL show_in_rest false', false, $url['args']['show_in_rest'] );

			$key = $GLOBALS['test_registered'][ $OPT_KEY ];
			t_check( 'key group', 'tainacan_immich_settings', $key['group'] );
			t_check( 'key type string', 'string', $key['args']['type'] );
			t_check( 'key sanitize callback', array( 'Tainacan\\Immich', 'sanitize_api_key' ), $key['args']['sanitize_callback'] );
			t_check( 'key default empty', '', $key['args']['default'] );
			t_check( 'key show_in_rest false', false, $key['args']['show_in_rest'] );
		}
	);

	// ---- Page rendering: edge 9, edge 10 and form markup -------------------

	t_run(
		'Edge 10: user without manage_options sees no form',
		function () {
			t_reset();
			$GLOBALS['test_caps'] = array( 'read' );
			$GLOBALS['test_options'] = array(
				'tainacan_immich_url'     => 'https://immich.example.org',
				'tainacan_immich_api_key' => 'fake-key-123',
			);
			$html = t_render_page();
			t_contains( 'E10 permission text shown', 'You do not have permission to change the Immich settings.', $html );
			t_not_contains( 'E10 no form tag', '<form', $html );
			t_not_contains( 'E10 no URL input', 'name="tainacan_immich_url"', $html );
			t_not_contains( 'E10 no key input', 'name="tainacan_immich_api_key"', $html );
			t_not_contains( 'E10 no stored key printed', 'fake-key-123', $html );
		}
	);

	t_run(
		'Edge 9: stored API key never printed in page HTML',
		function () {
			t_reset();
			$GLOBALS['test_caps'] = array( 'manage_options' );
			$GLOBALS['test_options'] = array(
				'tainacan_immich_url'     => 'https://immich.example.org',
				'tainacan_immich_api_key' => 'fake-key-123',
			);
			$html = t_render_page();
			t_not_contains( 'E9 stored key not in HTML', 'fake-key-123', $html );
			t_check( 'E9 key input has empty value', true, (bool) preg_match( '/name="tainacan_immich_api_key"[^>]*\svalue=""/', $html ) );
			t_check( 'E9 key input is password type', true, (bool) preg_match( '/type="password"[^>]*name="tainacan_immich_api_key"|name="tainacan_immich_api_key"[^>]*type="password"/', $html ) );
			t_contains( 'E9 saved-key status text shown', 'An API key is saved. Leave the field empty to keep it.', $html );
		}
	);

	t_run(
		'Edge 9: no stored key shows no-key text',
		function () {
			t_reset();
			$GLOBALS['test_caps'] = array( 'manage_options' );
			$html = t_render_page();
			t_contains( 'E9 no-key status text shown', 'No API key is saved.', $html );
			t_not_contains( 'E9 saved-key text not shown', 'An API key is saved.', $html );
		}
	);

	t_run(
		'Page: admin sees URL value, form to options.php, settings fields',
		function () {
			t_reset();
			$GLOBALS['test_caps'] = array( 'manage_options' );
			$GLOBALS['test_options'] = array(
				'tainacan_immich_url' => 'https://immich.example.org',
			);
			$html = t_render_page();
			t_contains( 'form has action to options.php', 'action="http://fake.example.test/wp-admin/options.php"', $html );
			t_contains( 'form has class tainacan-immich-settings', 'class="tainacan-immich-settings"', $html );
			t_contains( 'form method post', 'method="post"', $html );
			t_contains( 'settings_fields uses group', 'value="tainacan_immich_settings"', $html );
			t_contains( 'URL value shown', 'value="https://immich.example.org"', $html );
			t_contains( 'URL placeholder shown', 'placeholder="https://immich.example.org"', $html );
			t_contains( 'settings_errors called', 'settings_errors called', $html );
			t_contains( 'submit button shown', 'Save settings', $html );
		}
	);

	t_run(
		'Page: stored URL with HTML special characters is escaped',
		function () {
			t_reset();
			$GLOBALS['test_caps'] = array( 'manage_options' );
			$GLOBALS['test_options'] = array(
				'tainacan_immich_url' => 'https://x.example/"><script>alert(1)</script>',
			);
			$html = t_render_page();
			t_not_contains( 'URL attribute not broken out of', '"><script>', $html );
		}
	);

	// ---- Spec checks: exact error text, labels, signatures, header, source --

	t_run(
		'Edge 4/5: error message text matches spec',
		function () use ( $OPT_URL, $OLD_URL ) {
			t_reset();
			$GLOBALS['test_options'][ $OPT_URL ] = $OLD_URL;
			\Tainacan\Immich::sanitize_url( 'not a url' );
			t_check( 'bad URL error message text', 'The Immich URL is not valid. Use a full http or https URL.', isset( $GLOBALS['test_settings_errors'][0]['message'] ) ? $GLOBALS['test_settings_errors'][0]['message'] : '' );
		}
	);

	t_run(
		'Interface: option constants and method signatures',
		function () {
			t_check( 'OPTION_GROUP value', 'tainacan_immich_settings', \Tainacan\Immich::OPTION_GROUP );
			t_check( 'OPTION_URL value', 'tainacan_immich_url', \Tainacan\Immich::OPTION_URL );
			t_check( 'OPTION_API_KEY value', 'tainacan_immich_api_key', \Tainacan\Immich::OPTION_API_KEY );

			$m = new \ReflectionMethod( 'Tainacan\\Immich', 'sanitize_url' );
			t_check( 'sanitize_url is public static', true, $m->isPublic() && $m->isStatic() );
			t_check( 'sanitize_url returns string', 'string', (string) $m->getReturnType() );

			$m = new \ReflectionMethod( 'Tainacan\\Immich', 'sanitize_api_key' );
			t_check( 'sanitize_api_key is public static', true, $m->isPublic() && $m->isStatic() );
			t_check( 'sanitize_api_key returns string', 'string', (string) $m->getReturnType() );

			$m = new \ReflectionMethod( 'Tainacan\\Immich', 'register_settings' );
			t_check( 'register_settings is public', true, $m->isPublic() );
			t_check( 'register_settings returns void', 'void', (string) $m->getReturnType() );
		}
	);

	t_run(
		'Spec round 1: plugin header says Requires PHP: 7.1 on line 11 only',
		function () {
			$lines = file( __DIR__ . '/../tainacan-immich/tainacan-immich.php' );
			t_check( 'line 11 is Requires PHP: 7.1', 'Requires PHP: 7.1', isset( $lines[10] ) ? trim( $lines[10] ) : '' );
			t_check( 'exactly one Requires PHP line', 1, count( preg_grep( '/^Requires PHP:/', $lines ) ) );
		}
	);

	t_run(
		'Edge 9: page.php source never puts the stored key in a value attribute',
		function () {
			$src = file_get_contents( __DIR__ . '/../tainacan-immich/page.php' );
			t_check( 'no get_option of API key inside value=""', 0, preg_match( '/value="<\?php[^>]*(OPTION_API_KEY|tainacan_immich_api_key)/', $src ) );
			t_check( 'key input source has value=""', 1, preg_match( '/name="tainacan_immich_api_key"[^>]*value=""/', $src ) );
		}
	);

	t_run(
		'Page: labels, input types, autocomplete and help text match spec',
		function () {
			t_reset();
			$GLOBALS['test_caps'] = array( 'manage_options' );
			$html = t_render_page();
			t_contains( 'URL label text', 'Immich server URL', $html );
			t_contains( 'key label text', 'Immich API key', $html );
			t_contains( 'URL input is type url', 'type="url"', $html );
			t_contains( 'key input is type password', 'type="password"', $html );
			t_contains( 'key input autocomplete new-password', 'autocomplete="new-password"', $html );
			t_contains( 'URL help text', 'The base URL of the Immich server, without /api.', $html );
			t_contains( 'submit button label', 'value="Save settings"', $html );
			t_contains( 'no-key status when none saved', 'No API key is saved.', $html );
		}
	);

	// ---- Summary ----------------------------------------------------------

	echo "\n== Summary: {$GLOBALS['test_passes']} passed, {$GLOBALS['test_failures']} failed\n";
	exit( $GLOBALS['test_failures'] > 0 ? 1 : 0 );
}
