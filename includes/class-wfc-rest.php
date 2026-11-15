<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned, one-way FarmWatch statistics receiver.
 *
 * Contract v1:
 * {
 *   "schema_version": 1,
 *   "upload_id": "UUID",
 *   "instance_id": "stable-installation-id",
 *   "recorded_at": "RFC3339 timestamp with timezone",
 *   "statistics": { ... FarmWatch-owned statistics ... },
 *   "claims": [{ "claim_id": "stable-id", "app_id": 123, "title": "...", "claimed_at": "RFC3339" }]
 * }
 */
final class WFC_REST {
	const MAX_BODY_BYTES = 262144;
	const MAX_CLAIMS     = 500;
	const RATE_LIMIT     = 120;

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

	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function parse_timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			return false;
		}
		try {
			$date = new DateTimeImmutable( $value );
			$errors = DateTimeImmutable::getLastErrors();
			if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
				return false;
			}
			return $date->getTimestamp();
		} catch ( Exception $exception ) {
			return false;
		}
	}

	public static function receive_upload( WP_REST_Request $request ) {
		global $wpdb;

		$raw_body = $request->get_body();
		if ( ! is_string( $raw_body ) || '' === $raw_body || strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			return self::error( 'wfc_payload_size', 'The request body must be between 1 byte and 256 KB.', 413 );
		}

		$shape = json_decode( $raw_body );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $shape ) || ! isset( $shape->statistics ) || ! is_object( $shape->statistics ) ) {
			return self::error( 'wfc_invalid_json', 'Expected a JSON object containing a statistics object.', 400 );
		}
		$body = json_decode( $raw_body, true );
		if ( ! is_array( $body ) ) {
			return self::error( 'wfc_invalid_json', 'Expected a valid JSON object.', 400 );
		}

		if ( ! isset( $body['schema_version'] ) || 1 !== $body['schema_version'] ) {
			return self::error( 'wfc_schema_version', 'Unsupported or missing schema_version. This receiver accepts version 1.', 400 );
		}

		$instance_id = isset( $body['instance_id'] ) && is_string( $body['instance_id'] ) ? $body['instance_id'] : '';
		$upload_id   = isset( $body['upload_id'] ) && is_string( $body['upload_id'] ) ? strtolower( $body['upload_id'] ) : '';
		$recorded_at = isset( $body['recorded_at'] ) ? self::parse_timestamp( $body['recorded_at'] ) : false;
		$statistics  = (array) $shape->statistics;
		$claims      = isset( $body['claims'] ) ? $body['claims'] : array();
		if ( isset( $shape->claims ) && ! is_array( $shape->claims ) ) {
			return self::error( 'wfc_invalid_claims', 'claims must be a JSON array.', 400 );
		}

		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $instance_id ) ) {
			return self::error( 'wfc_invalid_instance', 'instance_id must be 8–64 letters, numbers, underscores or hyphens.', 400 );
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $upload_id ) ) {
			return self::error( 'wfc_invalid_upload_id', 'upload_id must be a UUID and must remain unchanged when retrying an upload.', 400 );
		}
		if ( false === $recorded_at || $recorded_at > time() + 300 || $recorded_at < time() - YEAR_IN_SECONDS ) {
			return self::error( 'wfc_invalid_timestamp', 'recorded_at must be an RFC3339 timestamp within the last year and no more than five minutes in the future.', 400 );
		}
		if ( ! is_array( $statistics ) || ! is_array( $claims ) || count( $claims ) > self::MAX_CLAIMS ) {
			return self::error( 'wfc_invalid_payload', 'statistics must be an object and claims must be an array of no more than 500 items.', 400 );
		}

		$encoded = wp_json_encode( $statistics );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_BODY_BYTES ) {
			return self::error( 'wfc_payload_too_large', 'The statistics object exceeds the 256 KB limit.', 413 );
		}

		$validated_claims = array();
		foreach ( $claims as $claim ) {
			if ( ! is_array( $claim ) ) {
				return self::error( 'wfc_invalid_claim', 'Every claims entry must be an object.', 400 );
			}
			$app_id = isset( $claim['app_id'] ) && is_numeric( $claim['app_id'] ) ? (int) $claim['app_id'] : 0;
			$claim_time = isset( $claim['claimed_at'] ) ? self::parse_timestamp( $claim['claimed_at'] ) : false;
			if ( $app_id < 1 || $app_id > 4294967295 || false === $claim_time || $claim_time > $recorded_at + 300 || $claim_time < time() - YEAR_IN_SECONDS ) {
				return self::error( 'wfc_invalid_claim', 'Each claim requires a valid Steam app_id and RFC3339 claimed_at timestamp.', 400 );
			}
			$claim_key = isset( $claim['claim_id'] ) && is_string( $claim['claim_id'] ) ? sanitize_text_field( $claim['claim_id'] ) : '';
			if ( '' === $claim_key ) {
				$claim_key = hash( 'sha256', $instance_id . ':' . $app_id . ':' . $claim_time );
			}
			$validated_claims[] = array(
				'claim_key'  => substr( $claim_key, 0, 191 ),
				'app_id'     => $app_id,
				'title'      => isset( $claim['title'] ) && is_string( $claim['title'] ) ? wp_html_excerpt( sanitize_text_field( $claim['title'] ), 255, '' ) : '',
				'claimed_at' => gmdate( 'Y-m-d H:i:s', $claim_time ),
			);
		}

		// A valid token is required before this callback; rate-limit by authenticated instance.
		$rate_key = 'wfc_rate_' . md5( $instance_id . ':' . hash( 'sha256', (string) $request->get_header( 'authorization' ) ) );
		$count = (int) get_transient( $rate_key );
		if ( $count >= self::RATE_LIMIT ) {
			return self::error( 'wfc_rate_limited', 'Upload rate limit exceeded. Retry after five minutes.', 429 );
		}
		set_transient( $rate_key, $count + 1, 5 * MINUTE_IN_SECONDS );

		$stats_table  = WFC_Database::table( 'snapshots' );
		$claims_table = WFC_Database::table( 'claims' );
		$wpdb->query( 'START TRANSACTION' );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$stats_table} (instance_id, upload_id, recorded_at, payload, created_at) VALUES (%s, %s, %s, %s, %s)",
				$instance_id,
				$upload_id,
				gmdate( 'Y-m-d H:i:s', $recorded_at ),
				$encoded,
				current_time( 'mysql', true )
			)
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wfc_storage_error', 'Statistics could not be stored.', 500 );
		}
		if ( 0 === (int) $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return rest_ensure_response(
				array(
					'ok'             => true,
					'duplicate'      => true,
					'upload_id'      => $upload_id,
					'claims_recorded'=> 0,
					'received_at'    => gmdate( 'c' ),
				)
			);
		}

		$snapshot_id = (int) $wpdb->insert_id;
		$stored_claims = 0;
		foreach ( $validated_claims as $claim ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$claims_table} (instance_id, claim_key, app_id, title, claimed_at, created_at) VALUES (%s, %s, %d, %s, %s, %s)",
					$instance_id,
					$claim['claim_key'],
					$claim['app_id'],
					$claim['title'],
					$claim['claimed_at'],
					current_time( 'mysql', true )
				)
			);
			if ( false === $result ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'wfc_storage_error', 'Claim history could not be stored; the upload was rolled back.', 500 );
			}
			$stored_claims += (int) $result;
		}

		$wpdb->query( 'COMMIT' );

		return rest_ensure_response(
			array(
				'ok'              => true,
				'duplicate'       => false,
				'upload_id'       => $upload_id,
				'snapshot_id'     => $snapshot_id,
				'claims_recorded' => $stored_claims,
				'received_at'     => gmdate( 'c' ),
			)
		);
	}
}
