<?php

namespace Tainacan;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * REST routes: "check" (editors) and "thumbnail" (signed proxy).
 */
class Immich_Rest {

	public static function register_routes() : void {
		register_rest_route(
			'tainacan-immich/v1',
			'/check',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_check' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'kind'  => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && in_array( $value, \Tainacan\Immich_Client::KINDS, true );
						},
					),
					'input' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_string( $value );
						},
					),
				),
			)
		);
		register_rest_route(
			'tainacan-immich/v1',
			'/thumbnail',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_thumbnail' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Build the check answer. Always the same shape.
	 *
	 * @param string $kind  asset, album or person.
	 * @param mixed  $input Text from the editor.
	 * @return array
	 */
	public static function check( $kind, $input ) : array {
		$out = array(
			'ok'      => false,
			'code'    => '',
			'message' => '',
			'id'      => '',
			'label'   => '',
			'thumb'   => '',
			'exif'    => null,
		);
		if ( \Tainacan\Immich_Client::looks_like_share( $input ) ) {
			$ids = \Tainacan\Immich_Client::resolve_share( $input, $kind );
			if ( is_wp_error( $ids ) ) {
				$out['code']    = $ids->get_error_code();
				$out['message'] = $ids->get_error_message();
				return $out;
			}
			$id = $ids[0];
		} else {
			$id = \Tainacan\Immich_Client::parse_id( $input, $kind );
		}
		if ( '' === $id ) {
			/* translators: %s: asset, album or person */
			$out['message'] = sprintf( __( 'This is not an Immich %s ID or URL.', 'tainacan-immich' ), is_string( $kind ) ? $kind : '' );
			$out['code']    = 'tainacan_immich_bad_input';
			return $out;
		}
		$out['id'] = $id;
		$info      = \Tainacan\Immich_Client::get_info( $kind, $id );
		if ( is_wp_error( $info ) ) {
			$out['code']    = $info->get_error_code();
			$out['message'] = $info->get_error_message();
			return $out;
		}
		if ( 'asset' === $kind && ! empty( $info['isTrashed'] ) ) {
			$out['code']    = 'tainacan_immich_trashed';
			$out['message'] = __( 'This asset is in the Immich trash.', 'tainacan-immich' );
			return $out;
		}
		$field        = array(
			'asset'  => 'originalFileName',
			'album'  => 'albumName',
			'person' => 'name',
		);
		$name         = $field[ $kind ];
		$out['ok']    = true;
		$out['code']  = 'ok';
		$out['label'] = ( isset( $info[ $name ] ) && is_string( $info[ $name ] ) ) ? $info[ $name ] : '';
		if ( 'asset' === $kind || 'person' === $kind ) {
			$out['thumb'] = self::thumbnail_url( $kind, $id );
		}
		if ( 'asset' === $kind ) {
			$out['exif'] = \Tainacan\Immich_Client::extract_exif( $info );
		}
		return $out;
	}

	public static function handle_check( $request ) {
		return new \WP_REST_Response( self::check( $request->get_param( 'kind' ), $request->get_param( 'input' ) ), 200 );
	}

	/**
	 * Pure part of the thumbnail route.
	 *
	 * @return array array{status:int,headers:array,body:string}
	 */
	public static function serve_thumbnail( $kind, $id, $sig ) : array {
		$valid = is_string( $kind ) && in_array( $kind, array( 'asset', 'person' ), true )
			&& is_string( $id ) && preg_match( \Tainacan\Immich_Client::UUID_REGEX, $id )
			&& self::verify( $kind, $id, $sig );
		if ( ! $valid ) {
			return array(
				'status'  => 403,
				'headers' => array(),
				'body'    => '',
			);
		}
		$thumb = \Tainacan\Immich_Client::get_thumbnail( $kind, $id );
		if ( is_wp_error( $thumb ) ) {
			return array(
				'status'  => 404,
				'headers' => array(),
				'body'    => '',
			);
		}
		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => $thumb['content_type'],
				'Cache-Control'          => 'public, max-age=86400',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => $thumb['body'],
		);
	}

	public static function handle_thumbnail( $request ) {
		$res = self::serve_thumbnail( $request->get_param( 'kind' ), $request->get_param( 'id' ), $request->get_param( 'sig' ) );
		status_header( $res['status'] );
		foreach ( $res['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $res['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image bytes. The type was checked as image/*.
		exit;
	}

	public static function sign( $kind, $id ) : string {
		return hash_hmac( 'sha256', $kind . '|' . $id, wp_salt( 'auth' ) );
	}

	public static function verify( $kind, $id, $sig ) : bool {
		if ( ! is_string( $kind ) || ! is_string( $id ) || ! is_string( $sig ) ) {
			return false;
		}
		return hash_equals( self::sign( $kind, $id ), $sig );
	}

	public static function thumbnail_url( $kind, $id ) : string {
		return add_query_arg(
			array(
				'kind' => $kind,
				'id'   => $id,
				'sig'  => self::sign( $kind, $id ),
			),
			rest_url( 'tainacan-immich/v1/thumbnail' )
		);
	}

	public static function enqueue_config() : void {
		wp_add_inline_script(
			'tainacan-immich-link',
			'window.tainacanImmich = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'tainacan-immich/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}
}
