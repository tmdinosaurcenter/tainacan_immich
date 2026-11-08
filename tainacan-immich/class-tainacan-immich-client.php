<?php

namespace Tainacan;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Read-only HTTP wrapper for the Immich API, and the ID parser.
 * Only GET calls are made. The API key is sent only in the auth header.
 */
class Immich_Client {

	const KINDS = array( 'asset', 'album', 'person' );

	const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

	const SHARE_KEY_REGEX = '/^[A-Za-z0-9_-]{1,128}\z/';

	const SHARE_SLUG_REGEX = '/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,126}[A-Za-z0-9])?\z/';

	/**
	 * Get a lowercase UUID from a bare UUID or an Immich web URL.
	 *
	 * @param mixed  $input Text from the editor.
	 * @param string $kind  asset, album or person.
	 * @return string The UUID, or '' when the input is not valid.
	 */
	public static function parse_id( $input, $kind ) : string {
		if ( ! is_string( $input ) || ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) ) {
			return '';
		}
		if ( strlen( $input ) > 2048 ) {
			return '';
		}
		$input = trim( $input );
		if ( preg_match( self::UUID_REGEX, $input ) ) {
			return strtolower( $input );
		}
		if ( ! preg_match( '#^https?://#i', $input ) ) {
			return '';
		}
		$path = parse_url( $input, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$segment = array(
			'asset'  => 'photos',
			'album'  => 'albums',
			'person' => 'people',
		);
		$parts   = explode( '/', trim( $path, '/' ) );
		$found   = '';
		$count   = count( $parts );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( $parts[ $i ] === $segment[ $kind ] && preg_match( self::UUID_REGEX, $parts[ $i + 1 ] ) ) {
				// The last match wins (/albums/{a}/photos/{b}).
				$found = strtolower( $parts[ $i + 1 ] );
			}
		}
		return $found;
	}

	/**
	 * True when the input looks like an Immich share URL. No option read, no HTTP.
	 * Broad on purpose: it is used to refuse storing such a value.
	 *
	 * @param mixed $input Text from the editor.
	 * @return bool
	 */
	public static function looks_like_share( $input ) : bool {
		if ( ! is_string( $input ) ) {
			return false;
		}
		$input = trim( $input );
		if ( ! preg_match( '#^https?://#i', $input ) ) {
			return false;
		}
		$path = parse_url( $input, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}
		// Decode the path until it does not change (3 rounds at most). Fail closed.
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = rawurldecode( $path );
			if ( $decoded === $path ) {
				break;
			}
			$path = $decoded;
		}
		if ( false !== strpos( $path, '%' ) ) {
			return true;
		}
		return 1 === preg_match( '#(?:^|/)(?:s|share)/.#s', $path );
	}

	/**
	 * Read a share key or slug from a share URL on the configured Immich server. No HTTP.
	 *
	 * @param mixed $input Text from the editor.
	 * @return array array{param:string,value:string}, or an empty array.
	 */
	public static function parse_share( $input ) : array {
		if ( ! is_string( $input ) || strlen( $input ) > 2048 ) {
			return array();
		}
		$input = trim( $input );
		if ( preg_match( '/[\x00-\x1F\x7F]/', $input ) ) {
			return array();
		}
		$base  = get_option( \Tainacan\Immich::OPTION_URL, '' );
		if ( ! is_string( $base ) || '' === trim( $base ) ) {
			return array();
		}
		$url  = parse_url( $input );
		$burl = parse_url( rtrim( trim( $base ), '/' ) );
		if ( ! is_array( $url ) || ! is_array( $burl ) || empty( $url['scheme'] ) || empty( $url['host'] ) || empty( $burl['host'] ) ) {
			return array();
		}
		if ( ! in_array( strtolower( $url['scheme'] ), array( 'http', 'https' ), true ) ) {
			return array();
		}
		if ( isset( $url['user'] ) || isset( $url['pass'] ) ) {
			return array();
		}
		if ( strtolower( $url['host'] ) !== strtolower( $burl['host'] ) ) {
			return array();
		}
		$port  = isset( $url['port'] ) ? (int) $url['port'] : null;
		$bport = isset( $burl['port'] ) ? (int) $burl['port'] : null;
		if ( $port !== $bport ) {
			return array();
		}
		$path = isset( $url['path'] ) ? $url['path'] : '';
		if ( '' === $path || false !== strpos( $path, '%' ) || false !== strpos( $path, '\\' ) ) {
			return array();
		}
		$prefix = isset( $burl['path'] ) ? rtrim( $burl['path'], '/' ) : '';
		if ( '' !== $prefix ) {
			if ( 0 !== strpos( $path, $prefix . '/' ) ) {
				return array();
			}
			$path = substr( $path, strlen( $prefix ) );
		}
		if ( '/' !== substr( $path, 0, 1 ) ) {
			return array();
		}
		$rest = substr( $path, 1 );
		if ( '/' === substr( $rest, -1 ) ) {
			$rest = substr( $rest, 0, -1 );
		}
		$parts = explode( '/', $rest );
		if ( 2 !== count( $parts ) ) {
			return array();
		}
		if ( 'share' === $parts[0] && preg_match( self::SHARE_KEY_REGEX, $parts[1] ) ) {
			return array(
				'param' => 'key',
				'value' => $parts[1],
			);
		}
		if ( 's' === $parts[0] && preg_match( self::SHARE_SLUG_REGEX, $parts[1] ) ) {
			return array(
				'param' => 'slug',
				'value' => $parts[1],
			);
		}
		return array();
	}

	/**
	 * Resolve a share key or slug with GET /shared-links/me. No API key, no password.
	 * Messages never hold the key, the slug or the Immich URL.
	 *
	 * @param string $param key or slug.
	 * @param string $value The key or slug.
	 * @return array|\WP_Error array{type:string,ids:string[]}
	 */
	public static function get_shared_link( $param, $value ) {
		$regex = array(
			'key'  => self::SHARE_KEY_REGEX,
			'slug' => self::SHARE_SLUG_REGEX,
		);
		if ( ! is_string( $param ) || ! isset( $regex[ $param ] ) || ! is_string( $value ) || ! preg_match( $regex[ $param ], $value ) ) {
			return new \WP_Error( 'tainacan_immich_bad_input', __( 'This is not a valid Immich share link for this server.', 'tainacan-immich' ) );
		}
		$res = self::request( '/shared-links/me?' . $param . '=' . rawurlencode( $value ), true, 'share link', '', false );
		if ( is_wp_error( $res ) ) {
			if ( in_array( $res->get_error_code(), array( 'tainacan_immich_auth', 'tainacan_immich_forbidden', 'tainacan_immich_not_found' ), true ) ) {
				return new \WP_Error( 'tainacan_immich_share_unusable', __( 'This share link cannot be used. It is removed, expired or protected by a password.', 'tainacan-immich' ) );
			}
			return $res;
		}
		$bad  = new \WP_Error( 'tainacan_immich_bad_response', __( 'The Immich server sent an answer that the plugin cannot read.', 'tainacan-immich' ) );
		$data = json_decode( $res['body'], true );
		if ( ! is_array( $data ) ) {
			return $bad;
		}
		if ( isset( $data['password'] ) && is_string( $data['password'] ) && '' !== $data['password'] ) {
			return new \WP_Error( 'tainacan_immich_share_password', __( 'This share link is protected by a password. Use a share link without a password.', 'tainacan-immich' ) );
		}
		if ( ! isset( $data['type'] ) || ! is_string( $data['type'] ) ) {
			return $bad;
		}
		if ( 'ALBUM' === $data['type'] ) {
			if ( ! isset( $data['album'] ) || ! is_array( $data['album'] ) || ! isset( $data['album']['id'] ) || ! is_string( $data['album']['id'] ) || ! preg_match( self::UUID_REGEX, $data['album']['id'] ) ) {
				return $bad;
			}
			return array(
				'type' => 'album',
				'ids'  => array( strtolower( $data['album']['id'] ) ),
			);
		}
		if ( 'INDIVIDUAL' === $data['type'] ) {
			if ( ! isset( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
				return $bad;
			}
			$ids = array();
			foreach ( $data['assets'] as $asset ) {
				if ( ! is_array( $asset ) || ! isset( $asset['id'] ) || ! is_string( $asset['id'] ) || ! preg_match( self::UUID_REGEX, $asset['id'] ) ) {
					return $bad;
				}
				$id = strtolower( $asset['id'] );
				if ( ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			if ( ! $ids ) {
				return new \WP_Error( 'tainacan_immich_share_empty', __( 'This share link has no photos.', 'tainacan-immich' ) );
			}
			return array(
				'type' => 'asset',
				'ids'  => $ids,
			);
		}
		return new \WP_Error( 'tainacan_immich_share_unknown', __( 'The Immich server sent a kind of share link that the plugin does not know.', 'tainacan-immich' ) );
	}

	/**
	 * Turn a share URL into the UUIDs it holds, for a kind of field.
	 *
	 * @param mixed  $input Text from the editor.
	 * @param string $kind  asset, album or person.
	 * @return array|\WP_Error UUIDs.
	 */
	public static function resolve_share( $input, $kind ) {
		if ( ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'tainacan_immich_bad_kind', __( 'Unknown kind.', 'tainacan-immich' ) );
		}
		if ( 'person' === $kind ) {
			/* translators: %s: asset, album or person */
			return new \WP_Error( 'tainacan_immich_bad_input', sprintf( __( 'This is not an Immich %s ID or URL.', 'tainacan-immich' ), $kind ) );
		}
		$share = self::parse_share( $input );
		if ( ! $share ) {
			return new \WP_Error( 'tainacan_immich_bad_input', __( 'This is not a valid Immich share link for this server.', 'tainacan-immich' ) );
		}
		$link = self::get_shared_link( $share['param'], $share['value'] );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( 'album' === $kind && 'asset' === $link['type'] ) {
			return new \WP_Error( 'tainacan_immich_share_wrong_kind', __( 'This share link has single photos. Use a photo field.', 'tainacan-immich' ) );
		}
		if ( 'asset' === $kind && 'album' === $link['type'] ) {
			return new \WP_Error( 'tainacan_immich_share_wrong_kind', __( 'This share link is for an album. Use an album field.', 'tainacan-immich' ) );
		}
		if ( 'asset' === $kind && count( $link['ids'] ) > 1 ) {
			/* translators: %d: number of photos in the share link */
			return new \WP_Error( 'tainacan_immich_share_too_many', sprintf( __( 'This share link has %d photos. Each value holds one photo. Paste a link to one photo for each value.', 'tainacan-immich' ), count( $link['ids'] ) ) );
		}
		return $link['ids'];
	}

	/**
	 * Format a coordinate as text. Same output at every locale. No exponent.
	 *
	 * @param float $value Coordinate.
	 * @return string
	 */
	public static function format_coord( float $value ) : string {
		$text = number_format( $value, 10, '.', '' );
		if ( false !== strpos( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		if ( '-0' === $text || '' === $text ) {
			$text = '0';
		}
		return $text;
	}

	/**
	 * Get safe EXIF data from an Immich asset answer. No HTTP.
	 *
	 * @param mixed $info Decoded asset info.
	 * @return array|null
	 */
	public static function extract_exif( $info ) : ?array {
		if ( ! is_array( $info ) || ! isset( $info['exifInfo'] ) || ! is_array( $info['exifInfo'] ) ) {
			return null;
		}
		$exif  = $info['exifInfo'];
		$valid = function ( $key, $limit ) use ( $exif ) {
			$v = isset( $exif[ $key ] ) ? $exif[ $key ] : null;
			return ( is_int( $v ) || is_float( $v ) ) && is_finite( $v ) && $v >= -$limit && $v <= $limit;
		};
		$gps   = $valid( 'latitude', 90 ) && $valid( 'longitude', 180 );
		$lat   = $gps ? self::format_coord( (float) $exif['latitude'] ) : '';
		$lon   = $gps ? self::format_coord( (float) $exif['longitude'] ) : '';
		$text  = function ( $key, $limit ) use ( $exif ) {
			if ( ! isset( $exif[ $key ] ) || ! is_string( $exif[ $key ] ) ) {
				return '';
			}
			return mb_substr( trim( $exif[ $key ] ), 0, $limit );
		};
		return array(
			'has_gps'     => $gps,
			'lat'         => $lat,
			'lon'         => $lon,
			'map_url'     => $gps ? 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=15/' . $lat . '/' . $lon : '',
			'null_island' => $gps && 0.0 === (float) $exif['latitude'] && 0.0 === (float) $exif['longitude'],
			'city'        => $text( 'city', 200 ),
			'state'       => $text( 'state', 200 ),
			'country'     => $text( 'country', 200 ),
			'date_taken'  => $text( 'dateTimeOriginal', 64 ),
			'time_zone'   => ( isset( $exif['timeZone'] ) && is_string( $exif['timeZone'] ) ) ? mb_substr( $exif['timeZone'], 0, 64 ) : '',
		);
	}

	public static function is_configured() : bool {
		return '' !== self::get_base_url() && '' !== self::get_key();
	}

	private static function get_base_url() : string {
		$url = get_option( \Tainacan\Immich::OPTION_URL, '' );
		return is_string( $url ) ? rtrim( $url, '/' ) : '';
	}

	private static function get_key() : string {
		$key = get_option( \Tainacan\Immich::OPTION_API_KEY, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Get the Immich record for an ID.
	 *
	 * @param string $kind asset, album or person.
	 * @param string $id   UUID accepted by parse_id.
	 * @return array|\WP_Error Decoded JSON.
	 */
	public static function get_info( $kind, $id ) {
		$bad = self::check_args( $kind, $id );
		if ( $bad ) {
			return $bad;
		}
		$paths = array(
			'asset'  => '/assets/',
			'album'  => '/albums/',
			'person' => '/people/',
		);
		$perms = array(
			'asset'  => 'asset.read',
			'album'  => 'album.read',
			'person' => 'person.read',
		);
		$res   = self::request( $paths[ $kind ] . $id, true, $kind, $perms[ $kind ] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( $res['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['id'] ) || ! is_string( $data['id'] ) ) {
			return new \WP_Error( 'tainacan_immich_bad_response', __( 'The Immich server sent an answer that the plugin cannot read.', 'tainacan-immich' ) );
		}
		return $data;
	}

	/**
	 * Get thumbnail bytes for an asset or a person.
	 *
	 * @param string $kind asset or person.
	 * @param string $id   UUID.
	 * @return array|\WP_Error array{body:string,content_type:string}
	 */
	public static function get_thumbnail( $kind, $id ) {
		if ( 'asset' !== $kind && 'person' !== $kind ) {
			return new \WP_Error( 'tainacan_immich_bad_kind', __( 'This kind has no thumbnail.', 'tainacan-immich' ) );
		}
		$bad = self::check_args( $kind, $id );
		if ( $bad ) {
			return $bad;
		}
		if ( 'asset' === $kind ) {
			$path = '/assets/' . $id . '/thumbnail?size=thumbnail';
			$perm = 'asset.view';
		} else {
			$path = '/people/' . $id . '/thumbnail';
			$perm = 'person.read';
		}
		$res = self::request( $path, false, $kind, $perm );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 0 !== strpos( strtolower( $res['content_type'] ), 'image/' ) ) {
			return new \WP_Error( 'tainacan_immich_bad_response', __( 'The Immich server sent an answer that the plugin cannot read.', 'tainacan-immich' ) );
		}
		return array(
			'body'         => $res['body'],
			'content_type' => $res['content_type'],
		);
	}

	/**
	 * @return \WP_Error|null
	 */
	private static function check_args( $kind, $id ) {
		if ( ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'tainacan_immich_bad_kind', __( 'Unknown kind.', 'tainacan-immich' ) );
		}
		if ( ! is_string( $id ) || ! preg_match( self::UUID_REGEX, $id ) ) {
			/* translators: %s: asset, album or person */
			return new \WP_Error( 'tainacan_immich_not_found', sprintf( __( 'No Immich %s has this ID, or the API key cannot see it.', 'tainacan-immich' ), $kind ) );
		}
		return null;
	}

	/**
	 * One GET call. Returns array{body,content_type} or WP_Error.
	 * Messages never hold the key or the Immich URL.
	 *
	 * @param string $path        Fixed segment plus a validated UUID.
	 * @param bool   $accept_json True for JSON calls.
	 * @param string $kind        Kind, for messages.
	 * @param string $permission  Immich permission name, for the 403 message.
	 * @param bool   $send_key    False for the share call: the link is the credential.
	 * @return array|\WP_Error
	 */
	private static function request( $path, $accept_json, $kind, $permission, $send_key = true ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'tainacan_immich_not_configured', __( 'Immich is not set up. Ask the admin to add the server URL and API key.', 'tainacan-immich' ) );
		}
		$headers  = array( 'Accept' => $accept_json ? 'application/json' : 'image/*' );
		if ( $send_key ) {
			$headers = array( 'x-api-key' => self::get_key() ) + $headers;
		}
		$response = wp_remote_get(
			self::get_base_url() . '/api' . $path,
			array(
				'headers'             => $headers,
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 5 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'tainacan_immich_unreachable', __( 'The Immich server did not answer. Try again later.', 'tainacan-immich' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code ) {
			return new \WP_Error( 'tainacan_immich_auth', __( 'The Immich server did not accept the API key. Ask the admin to check the key.', 'tainacan-immich' ) );
		}
		if ( 403 === $code ) {
			/* translators: %s: Immich permission name */
			return new \WP_Error( 'tainacan_immich_forbidden', sprintf( __( 'The API key does not have the permission %s. Ask the admin to add it in Immich.', 'tainacan-immich' ), $permission ) );
		}
		if ( 404 === $code || 400 === $code ) {
			/* translators: %s: asset, album or person */
			return new \WP_Error( 'tainacan_immich_not_found', sprintf( __( 'No Immich %s has this ID, or the API key cannot see it.', 'tainacan-immich' ), $kind ) );
		}
		if ( $code < 200 || $code > 299 ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'tainacan_immich_server_error', sprintf( __( 'The Immich server answered with an error (HTTP %d).', 'tainacan-immich' ), $code ) );
		}
		return array(
			'body'         => (string) wp_remote_retrieve_body( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}
}
