<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WFC_Auth {
	public static function authorize( WP_REST_Request $request ) {
		$settings = get_option( 'wfc_settings', array() );
		$stored   = isset( $settings['token_hash'] ) ? (string) $settings['token_hash'] : '';
		$header   = (string) $request->get_header( 'authorization' );

		if ( '' === $stored || ! preg_match( '/^Bearer\\s+([A-Za-z0-9_-]{32,128})$/', $header, $matches ) ) {
			return new WP_Error( 'wfc_unauthorized', 'A valid upload token is required.', array( 'status' => 401 ) );
		}

		$provided_hash = hash( 'sha256', $matches[1] );
		if ( ! hash_equals( $stored, $provided_hash ) ) {
			return new WP_Error( 'wfc_unauthorized', 'A valid upload token is required.', array( 'status' => 401 ) );
		}

		return true;
	}

	public static function generate_token() {
		return rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
	}
}
