<?php

namespace Tainacan\Metadata_Types;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Metadata type "Immich link". Tainacan stores this class name: do not rename it.
 */
class Immich_Link extends \Tainacan\Metadata_Types\Metadata_Type {

	function __construct() {
		parent::__construct();
		$this->set_name( __( 'Immich link', 'tainacan-immich' ) );
		$this->set_description( __( 'A link to an Immich asset, album or person.', 'tainacan-immich' ) );
		$this->set_primitive_type( array( 'text' ) );
		$this->set_component( 'tainacan-immich-link' );
		$this->set_form_component( 'tainacan-immich-link-form' );
		$this->set_default_options( array( 'link_kind' => 'asset' ) );
		$this->set_preview_template(
			'<div><div class="control is-clearfix"><input type="text" placeholder="' . esc_attr__( 'Immich ID, URL or share link', 'tainacan-immich' ) . '" class="input"></div></div>'
		);
	}

	public function get_form_labels() {
		return array(
			'link_kind' => array(
				'title'       => __( 'Kind of Immich link', 'tainacan-immich' ),
				'description' => __( 'Choose if this metadatum holds Immich assets, one album or one person.', 'tainacan-immich' ),
			),
		);
	}

	/**
	 * @return string asset, album or person.
	 */
	public function get_kind() {
		$kind = $this->get_option( 'link_kind' );
		return ( is_string( $kind ) && in_array( $kind, \Tainacan\Immich_Client::KINDS, true ) ) ? $kind : 'asset';
	}

	public function validate( \Tainacan\Entities\Item_Metadata_Entity $item_metadata ) {
		$kind   = $this->get_kind();
		$value  = $item_metadata->get_value();
		$values = is_array( $value ) ? $value : array( $value );
		$ids    = array();

		foreach ( $values as $single ) {
			if ( null === $single || false === $single || '' === $single || ( is_string( $single ) && '' === trim( $single ) ) ) {
				continue;
			}
			if ( \Tainacan\Immich_Client::looks_like_share( $single ) ) {
				$this->add_error( __( 'A share link cannot be saved. Wait until the field shows the photo or album, then save again.', 'tainacan-immich' ) );
				return false;
			}
			$id = \Tainacan\Immich_Client::parse_id( $single, $kind );
			if ( '' === $id ) {
				$shown = is_string( $single ) ? esc_html( mb_substr( $single, 0, 100 ) ) : '';
				/* translators: 1: text typed by the editor, 2: asset, album or person */
				$this->add_error( sprintf( __( '"%1$s" is not an Immich %2$s ID or URL.', 'tainacan-immich' ), $shown, $kind ) );
				return false;
			}
			$ids[] = $id;
		}

		if ( 'asset' !== $kind && count( $ids ) > 1 ) {
			/* translators: %s: album or person */
			$this->add_error( sprintf( __( 'Only one Immich %s is allowed.', 'tainacan-immich' ), $kind ) );
			return false;
		}

		foreach ( $ids as $id ) {
			$info = \Tainacan\Immich_Client::get_info( $kind, $id );
			if ( is_wp_error( $info ) ) {
				if ( 'tainacan_immich_not_found' === $info->get_error_code() ) {
					$this->add_error( $info->get_error_message() );
					return false;
				}
				continue;
			}
			if ( 'asset' === $kind && ! empty( $info['isTrashed'] ) ) {
				$this->add_error( __( 'This asset is in the Immich trash.', 'tainacan-immich' ) );
				return false;
			}
		}
		return true;
	}

	/**
	 * Makes no Immich call.
	 */
	public function get_value_as_html( \Tainacan\Entities\Item_Metadata_Entity $item_metadata ) {
		$value = $item_metadata->get_value();

		if ( is_array( $value ) && $item_metadata->is_multiple() ) {
			$prefix    = $item_metadata->get_multivalue_prefix();
			$suffix    = $item_metadata->get_multivalue_suffix();
			$separator = $item_metadata->get_multivalue_separator();
			$items     = array();
			foreach ( $value as $el ) {
				$html = $this->get_single_value_as_html( $el );
				if ( '' !== $html ) {
					$items[] = $prefix . $html . $suffix;
				}
			}
			return implode( $separator, $items );
		}
		return $this->get_single_value_as_html( is_array( $value ) ? reset( $value ) : $value );
	}

	public function get_single_value_as_html( $value ) {
		$kind = $this->get_kind();
		$id   = \Tainacan\Immich_Client::parse_id( $value, $kind );
		if ( '' === $id ) {
			return '';
		}
		$html = '';
		if ( 'asset' === $kind || 'person' === $kind ) {
			$html .= '<img class="tainacan-immich-thumb" loading="lazy" src="' . esc_url( \Tainacan\Immich_Rest::thumbnail_url( $kind, $id ) ) . '" alt="' . esc_attr__( 'Immich image', 'tainacan-immich' ) . '">';
		}
		if ( 'album' === $kind || 'person' === $kind ) {
			$base = get_option( \Tainacan\Immich::OPTION_URL, '' );
			if ( is_string( $base ) && '' !== $base ) {
				$path  = 'album' === $kind ? '/albums/' : '/people/';
				$html .= '<a href="' . esc_url( rtrim( $base, '/' ) . $path . $id ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Immich', 'tainacan-immich' ) . '</a>';
			}
		}
		return $html;
	}
}
