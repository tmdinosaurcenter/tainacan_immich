<?php

namespace Tainacan;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Update notices from GitHub releases. Only GET calls are made. No secrets are sent.
 * Nothing from the release is run. WordPress core downloads the zip after the admin clicks Update.
 */
class Immich_Updater {

	const BASENAME      = 'tainacan-immich/tainacan-immich.php';
	const SLUG          = 'tainacan-immich';
	const ASSET_NAME    = 'tainacan-immich.zip';
	const CACHE_KEY     = 'tainacan_immich_update_release';
	const REPO_REGEX    = '#^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$#';
	const VERSION_REGEX = '/^\d+\.\d+\.\d+$/';

	private static $registered = false;

	public static function register( string $plugin_file ) : void {
		if ( plugin_basename( $plugin_file ) !== self::BASENAME ) {
			return;
		}
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 20, 3 );
	}

	public static function validate_repo( $repo ) : string {
		if ( ! is_string( $repo ) ) {
			return '';
		}
		$repo = trim( $repo );
		if ( 1 !== preg_match( self::REPO_REGEX, $repo ) ) {
			return '';
		}
		foreach ( explode( '/', $repo ) as $part ) {
			if ( '.' === $part || '..' === $part ) {
				return '';
			}
		}
		return $repo;
	}

	public static function get_repo() : string {
		return self::validate_repo( defined( 'TAINACAN_IMMICH_UPDATE_REPO' ) ? TAINACAN_IMMICH_UPDATE_REPO : '' );
	}

	public static function is_enabled() : bool {
		if ( '' === self::get_repo() ) {
			return false;
		}
		$on = '1' === (string) get_option( \Tainacan\Immich::OPTION_UPDATE_CHECK, '1' );
		return (bool) apply_filters( 'tainacan_immich_enable_update_check', $on );
	}

	public static function normalize_version( $tag ) : string {
		if ( ! is_string( $tag ) || strlen( $tag ) > 32 ) {
			return '';
		}
		$tag = trim( $tag );
		if ( '' !== $tag && ( 'v' === $tag[0] || 'V' === $tag[0] ) ) {
			$tag = substr( $tag, 1 );
		}
		return 1 === preg_match( self::VERSION_REGEX, $tag ) ? $tag : '';
	}

	public static function is_newer( string $remote, $installed ) : bool {
		if ( '' === $remote || ! is_string( $installed ) || '' === $installed ) {
			return false;
		}
		return version_compare( $remote, $installed, '>' );
	}

	private static function is_github_url( $url ) : bool {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& 'github.com' === strtolower( $parts['host'] );
	}

	public static function parse_release( $data, string $repo ) : ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( ! isset( $data['draft'], $data['prerelease'] ) || false !== $data['draft'] || false !== $data['prerelease'] ) {
			return null;
		}
		$version = self::normalize_version( isset( $data['tag_name'] ) ? $data['tag_name'] : null );
		if ( '' === $version ) {
			return null;
		}
		if ( ! isset( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return null;
		}
		$asset = null;
		foreach ( $data['assets'] as $candidate ) {
			if ( is_array( $candidate ) && isset( $candidate['name'] ) && self::ASSET_NAME === $candidate['name'] ) {
				$asset = $candidate;
				break;
			}
		}
		if ( null === $asset ) {
			return null;
		}
		if ( array_key_exists( 'state', $asset ) && 'uploaded' !== $asset['state'] ) {
			return null;
		}
		$package = isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : null;
		if ( ! is_string( $package ) ) {
			return null;
		}
		// parse_url() turns control characters into "_", so refuse them (and spaces) in the raw URL first.
		if ( 1 === preg_match( '/[\x00-\x20\x7f]/', $package ) ) {
			return null;
		}
		$parts = wp_parse_url( $package );
		if ( ! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'], $parts['path'] )
			|| 'https' !== strtolower( $parts['scheme'] )
			|| 'github.com' !== strtolower( $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return null;
		}
		$path   = strtolower( $parts['path'] );
		$prefix = '/' . strtolower( $repo ) . '/releases/download/';
		$suffix = '/' . self::ASSET_NAME;
		if ( 0 !== strpos( $path, $prefix ) || substr( $path, -strlen( $suffix ) ) !== $suffix ) {
			return null;
		}
		// No room for a tag segment (prefix and suffix touch or overlap).
		if ( strlen( $path ) < strlen( $prefix ) + strlen( $suffix ) + 1 ) {
			return null;
		}
		// The tag segment may hold only letters, digits and . _ + - (and is not . or ..).
		$tag_part = substr( $path, strlen( $prefix ), strlen( $path ) - strlen( $prefix ) - strlen( $suffix ) );
		if ( ! is_string( $tag_part ) || '.' === $tag_part || '..' === $tag_part
			|| 1 !== preg_match( '/^[A-Za-z0-9._+-]+$/D', $tag_part ) ) {
			return null;
		}
		$url = ( isset( $data['html_url'] ) && self::is_github_url( $data['html_url'] ) )
			? $data['html_url']
			: 'https://github.com/' . $repo;
		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => $url,
			'notes'     => ( isset( $data['body'] ) && is_string( $data['body'] ) ) ? mb_substr( $data['body'], 0, 20000 ) : '',
			'published' => ( isset( $data['published_at'] ) && is_string( $data['published_at'] ) && strlen( $data['published_at'] ) <= 64 ) ? $data['published_at'] : '',
		);
	}

	public static function format_notes( $body ) : string {
		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			return '<p>' . esc_html__( 'No release notes.', 'tainacan-immich' ) . '</p>';
		}
		$html    = '';
		$in_list = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				if ( $in_list ) {
					$html   .= '</ul>';
					$in_list = false;
				}
				continue;
			}
			$marker = substr( $line, 0, 2 );
			if ( '* ' === $marker || '- ' === $marker ) {
				if ( ! $in_list ) {
					$html   .= '<ul>';
					$in_list = true;
				}
				$html .= '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
				continue;
			}
			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			$html .= '<p>' . esc_html( $line ) . '</p>';
		}
		if ( $in_list ) {
			$html .= '</ul>';
		}
		return $html;
	}

	public static function fetch_release( string $repo ) : ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'user-agent'          => 'tainacan-immich-updater',
				'headers'             => array( 'Accept' => 'application/vnd.github+json' ),
				'limit_response_size' => MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return self::parse_release( $decoded, $repo );
	}

	public static function get_release() : ?array {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$repo   = self::get_repo();
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['repo'] ) && $cached['repo'] === $repo
			&& isset( $cached['ok'] ) && is_bool( $cached['ok'] ) ) {
			if ( ! $cached['ok'] ) {
				return null;
			}
			if ( isset( $cached['release'] ) && is_array( $cached['release'] ) ) {
				$release_ok = true;
				foreach ( array( 'version', 'package', 'url' ) as $key ) {
					if ( ! isset( $cached['release'][ $key ] ) || ! is_string( $cached['release'][ $key ] ) || '' === $cached['release'][ $key ] ) {
						$release_ok = false;
						break;
					}
				}
				if ( $release_ok ) {
					return $cached['release'];
				}
			}
			// ok is true but the release is not usable: miss, fetch again.
		}
		$release = self::fetch_release( $repo );
		if ( null !== $release ) {
			set_site_transient( self::CACHE_KEY, array( 'ok' => true, 'repo' => $repo, 'release' => $release ), 12 * HOUR_IN_SECONDS );
		} else {
			set_site_transient( self::CACHE_KEY, array( 'ok' => false, 'repo' => $repo ), 15 * MINUTE_IN_SECONDS );
		}
		return $release;
	}

	public static function build_update_item( array $release ) : \stdClass {
		return (object) array(
			'id'           => 'https://github.com/' . self::get_repo(),
			'slug'         => self::SLUG,
			'plugin'       => self::BASENAME,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'tested'       => '',
			'requires'     => '',
			'requires_php' => '',
			'icons'        => array(),
		);
	}

	public static function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) || ! self::is_enabled() ) {
			return $transient;
		}
		$installed = ( isset( $transient->checked ) && is_array( $transient->checked ) && isset( $transient->checked[ self::BASENAME ] ) )
			? $transient->checked[ self::BASENAME ]
			: null;
		if ( ! is_string( $installed ) || '' === $installed ) {
			return $transient;
		}
		$release = self::get_release();
		if ( null === $release ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		$item = self::build_update_item( $release );
		if ( self::is_newer( $release['version'], $installed ) ) {
			$transient->response[ self::BASENAME ] = $item;
			unset( $transient->no_update[ self::BASENAME ] );
		} else {
			$transient->no_update[ self::BASENAME ] = $item;
			unset( $transient->response[ self::BASENAME ] );
		}
		return $transient;
	}

	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		if ( ! self::is_enabled() ) {
			return $result;
		}
		$release = self::get_release();
		if ( null === $release ) {
			return new \WP_Error( 'tainacan_immich_update_unavailable', __( 'The update details are not available now. Try again later.', 'tainacan-immich' ) );
		}
		$repo = self::get_repo();
		return (object) array(
			'name'          => 'Tainacan Immich',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="' . esc_url( 'https://github.com/' . $repo ) . '">' . esc_html( $repo ) . '</a>',
			'homepage'      => 'https://github.com/' . $repo,
			'requires'      => '',
			'requires_php'  => '',
			'tested'        => '',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Links Tainacan items to assets in an Immich photo server.', 'tainacan-immich' ) . '</p>',
				'changelog'   => '<h4>' . esc_html( $release['version'] ) . '</h4>' . self::format_notes( $release['notes'] ),
			),
		);
	}
}
