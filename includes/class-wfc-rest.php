<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WFC_REST {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'watchfarm-connect/v1',
			'/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive_upload' ),
				'permission_callback' => array( 'WFC_Auth', 'authorize' ),
			)
		);
	}

	public static function receive_upload( WP_REST_Request $request ) {
		global $wpdb;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'wfc_invalid_payload', 'Expected a JSON object.', array( 'status' => 400 ) );
		}

		$instance_id = isset( $body['instance_id'] ) ? sanitize_text_field( $body['instance_id'] ) : '';
		$recorded_at = isset( $body['recorded_at'] ) ? sanitize_text_field( $body['recorded_at'] ) : '';
		$statistics  = isset( $body['statistics'] ) ? $body['statistics'] : null;
		$claims      = isset( $body['claims'] ) ? $body['claims'] : array();

		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $instance_id ) || ! is_array( $statistics ) || ! is_array( $claims ) ) {
			return new WP_Error( 'wfc_invalid_payload', 'The upload payload is missing required fields or has invalid types.', array( 'status' => 400 ) );
		}

		$timestamp = $recorded_at ? strtotime( $recorded_at ) : false;
		if ( false === $timestamp || $timestamp > time() + 300 || $timestamp < time() - YEAR_IN_SECONDS ) {
			return new WP_Error( 'wfc_invalid_timestamp', 'The recorded_at timestamp is invalid or outside the accepted range.', array( 'status' => 400 ) );
		}

		$encoded = wp_json_encode( $statistics );
		if ( false === $encoded || strlen( $encoded ) > 262144 ) {
			return new WP_Error( 'wfc_payload_too_large', 'Statistics payload exceeds the 256 KB limit.', array( 'status' => 413 ) );
		}

		$stats_table = WFC_Database::table( 'snapshots' );
		$inserted    = $wpdb->insert(
			$stats_table,
			array(
				'instance_id' => $instance_id,
				'recorded_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'payload'     => $encoded,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wfc_storage_error', 'Statistics could not be stored.', array( 'status' => 500 ) );
		}

		$claims_table = WFC_Database::table( 'claims' );
		$stored_claims = 0;
		foreach ( array_slice( $claims, 0, 500 ) as $claim ) {
			if ( ! is_array( $claim ) || empty( $claim['app_id'] ) || empty( $claim['claimed_at'] ) ) {
				continue;
			}
			$app_id = absint( $claim['app_id'] );
			$claim_time = strtotime( (string) $claim['claimed_at'] );
			if ( ! $app_id || false === $claim_time ) {
				continue;
			}
			$claim_key = isset( $claim['claim_id'] ) ? sanitize_text_field( $claim['claim_id'] ) : hash( 'sha256', $instance_id . ':' . $app_id . ':' . gmdate( 'c', $claim_time ) );
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$claims_table} (instance_id, claim_key, app_id, title, claimed_at, created_at) VALUES (%s, %s, %d, %s, %s, %s)",
					$instance_id,
					substr( $claim_key, 0, 191 ),
					$app_id,
					isset( $claim['title'] ) ? sanitize_text_field( $claim['title'] ) : '',
					gmdate( 'Y-m-d H:i:s', $claim_time ),
					current_time( 'mysql', true )
				)
			);
			if ( $result ) {
				$stored_claims += (int) $result;
			}
		}

		return rest_ensure_response(
			array(
				'ok'             => true,
				'snapshot_id'    => (int) $wpdb->insert_id,
				'claims_recorded'=> $stored_claims,
				'received_at'    => gmdate( 'c' ),
			)
		);
	}
}
