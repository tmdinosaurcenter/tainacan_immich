<?php

namespace Tainacan;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Read-only HTTP wrapper for the Immich API, and the ID parser.
 * Only GET calls are made. The API key is sent only in the auth header.
 */
class Immich_Client {

	const KINDS = array( 'asset', 'album', 'person' );

	const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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
			'date_taken'  => ( isset( $exif['dateTimeOriginal'] ) && is_string( $exif['dateTimeOriginal'] ) ) ? mb_substr( $exif['dateTimeOriginal'], 0, 64 ) : '',
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
	 * @return array|\WP_Error
	 */
	private static function request( $path, $accept_json, $kind, $permission ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'tainacan_immich_not_configured', __( 'Immich is not set up. Ask the admin to add the server URL and API key.', 'tainacan-immich' ) );
		}
		$response = wp_remote_get(
			self::get_base_url() . '/api' . $path,
			array(
				'headers'             => array(
					'x-api-key' => self::get_key(),
					'Accept'    => $accept_json ? 'application/json' : 'image/*',
				),
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
