<?php
/**
 * Plugin Name: WatchFarm Connect
 * Description: Secure, outbound-only statistics receiver for FarmWatch on ArchiSteamFarm.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Tjketley
 * License: GPL-2.0-or-later
 * Text Domain: watchfarm-connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WatchFarm_Connect {
	const VERSION = '1.0.0';
	const OPTION_TOKEN_HASH = 'watchfarm_connect_token_hash';
	const REST_NAMESPACE = 'watchfarm-connect/v1';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_watchfarm_connect_generate_token', array( $this, 'generate_token' ) );
	}

	private function uploads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'watchfarm_uploads';
	}

	private function claims_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'watchfarm_claims';
	}

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$uploads = $wpdb->prefix . 'watchfarm_uploads';
		$claims = $wpdb->prefix . 'watchfarm_claims';

		$sql_uploads = "CREATE TABLE {$uploads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			instance_id varchar(64) NOT NULL,
			upload_id varchar(64) NOT NULL,
			recorded_at datetime NOT NULL,
			farmwatch_version varchar(32) NOT NULL DEFAULT '',
			bots_total int(10) unsigned NOT NULL DEFAULT 0,
			bots_connected int(10) unsigned NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY instance_upload (instance_id,upload_id),
			KEY recorded_at (recorded_at)
		) {$charset};";

		$sql_claims = "CREATE TABLE {$claims} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			instance_id varchar(64) NOT NULL,
			claim_id varchar(64) NOT NULL,
			target_type varchar(16) NOT NULL,
			target_id bigint(20) unsigned NOT NULL,
			claimed_at datetime NOT NULL,
			source varchar(32) NOT NULL DEFAULT 'farmwatch',
			PRIMARY KEY  (id),
			UNIQUE KEY instance_claim (instance_id,claim_id),
			KEY claimed_at (claimed_at),
			KEY target_id (target_id)
		) {$charset};";

		dbDelta( $sql_uploads );
		dbDelta( $sql_claims );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive_upload' ),
				'permission_callback' => array( $this, 'authorize_upload' ),
			)
		);
	}

	public function authorize_upload( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+([A-Za-z0-9_-]{32,128})$/', trim( $header ), $matches ) ) {
			return new WP_Error( 'watchfarm_unauthorized', 'A valid Bearer token is required.', array( 'status' => 401 ) );
		}

		$stored_hash = (string) get_option( self::OPTION_TOKEN_HASH, '' );
		$provided_hash = hash( 'sha256', $matches[1] );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $provided_hash ) ) {
			return new WP_Error( 'watchfarm_unauthorized', 'The supplied token is not valid.', array( 'status' => 401 ) );
		}

		return true;
	}

	public function receive_upload( WP_REST_Request $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'watchfarm_invalid_json', 'A JSON object is required.', array( 'status' => 400 ) );
		}

		$schema_version = isset( $body['schema_version'] ) ? absint( $body['schema_version'] ) : 0;
		$upload_id = isset( $body['upload_id'] ) ? sanitize_text_field( (string) $body['upload_id'] ) : '';
		$instance_id = isset( $body['instance_id'] ) ? sanitize_text_field( (string) $body['instance_id'] ) : '';
		$recorded_at = $this->parse_utc_datetime( $body['recorded_at'] ?? null );
		$statistics = $body['statistics'] ?? null;
		$claims = $body['claims'] ?? array();

		if ( 1 !== $schema_version || ! preg_match( '/^[a-f0-9-]{32,36}$/i', $upload_id ) || ! preg_match( '/^[a-f0-9]{32}$/i', $instance_id ) || ! $recorded_at || ! is_array( $statistics ) || ! is_array( $claims ) ) {
			return new WP_Error( 'watchfarm_invalid_envelope', 'The upload envelope is incomplete or unsupported.', array( 'status' => 400 ) );
		}

		$version = sanitize_text_field( (string) ( $statistics['farmwatch_version'] ?? '' ) );
		$bots_total = absint( $statistics['bots_total'] ?? 0 );
		$bots_connected = absint( $statistics['bots_connected'] ?? 0 );
		$payload = wp_json_encode( $statistics );
		if ( false === $payload ) {
			return new WP_Error( 'watchfarm_invalid_statistics', 'The statistics payload could not be encoded.', array( 'status' => 400 ) );
		}

		$uploads_table = $this->uploads_table();
		$claims_table = $this->claims_table();
		$wpdb->query( 'START TRANSACTION' );

		$inserted = $wpdb->insert(
			$uploads_table,
			array(
				'instance_id'       => $instance_id,
				'upload_id'         => $upload_id,
				'recorded_at'       => $recorded_at,
				'farmwatch_version' => $version,
				'bots_total'        => $bots_total,
				'bots_connected'    => $bots_connected,
				'payload'           => $payload,
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$duplicate = false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' );
			$wpdb->query( 'ROLLBACK' );
			if ( $duplicate ) {
				return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
			}
			return new WP_Error( 'watchfarm_storage_error', 'The upload could not be stored.', array( 'status' => 500 ) );
		}

		foreach ( $claims as $claim ) {
			if ( ! is_array( $claim ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'watchfarm_invalid_claim', 'A claim event is malformed.', array( 'status' => 400 ) );
			}
			$claim_id = sanitize_text_field( (string) ( $claim['claim_id'] ?? '' ) );
			$target_type = sanitize_text_field( (string) ( $claim['target_type'] ?? '' ) );
			$target_id = absint( $claim['target_id'] ?? 0 );
			$claimed_at = $this->parse_utc_datetime( $claim['claimed_at'] ?? null );
			$source = sanitize_text_field( (string) ( $claim['source'] ?? 'farmwatch' ) );

			if ( ! preg_match( '/^[a-f0-9]{32}$/i', $claim_id ) || ! in_array( $target_type, array( 'App', 'Sub' ), true ) || 0 === $target_id || ! $claimed_at ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'watchfarm_invalid_claim', 'A claim event contains invalid fields.', array( 'status' => 400 ) );
			}

			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$claims_table} (instance_id, claim_id, target_type, target_id, claimed_at, source) VALUES (%s, %s, %s, %d, %s, %s)",
					$instance_id,
					$claim_id,
					$target_type,
					$target_id,
					$claimed_at,
					$source
				)
			);
		}

		$wpdb->query( 'COMMIT' );
		return rest_ensure_response( array( 'ok' => true, 'duplicate' => false ) );
	}

	private function parse_utc_datetime( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		try {
			$date = new DateTimeImmutable( $value );
			return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	public function register_admin_page(): void {
		add_menu_page(
			__( 'WatchFarm', 'watchfarm-connect' ),
			__( 'WatchFarm', 'watchfarm-connect' ),
			'manage_options',
			'watchfarm-connect',
			array( $this, 'render_admin_page' ),
			'dashicons-chart-area',
			58
		);
	}

	public function generate_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WatchFarm Connect.', 'watchfarm-connect' ) );
		}
		check_admin_referer( 'watchfarm_connect_generate_token' );

		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		update_option( self::OPTION_TOKEN_HASH, hash( 'sha256', $token ), false );
		set_transient( 'watchfarm_connect_token_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => 'watchfarm-connect', 'token_generated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$uploads_table = $this->uploads_table();
		$claims_table = $this->claims_table();
		$uploads = $wpdb->get_results( "SELECT * FROM {$uploads_table} ORDER BY recorded_at DESC LIMIT 20" );
		$claims = $wpdb->get_results( "SELECT * FROM {$claims_table} ORDER BY claimed_at DESC LIMIT 30" );
		$token = get_transient( 'watchfarm_connect_token_' . get_current_user_id() );
		if ( $token ) {
			delete_transient( 'watchfarm_connect_token_' . get_current_user_id() );
		}
		$endpoint = rest_url( self::REST_NAMESPACE . '/upload' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WatchFarm Connect', 'watchfarm-connect' ); ?></h1>
			<p><?php esc_html_e( 'Receive aggregate FarmWatch statistics from your ASF instance. Connections are initiated by FarmWatch; this plugin does not connect back to ASF.', 'watchfarm-connect' ); ?></p>
			<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Upload endpoint:', 'watchfarm-connect' ); ?></strong> <code><?php echo esc_html( $endpoint ); ?></code></p></div>
			<?php if ( $token ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'Copy this token now. It is shown once and cannot be recovered.', 'watchfarm-connect' ); ?></strong></p><p><code style="font-size:16px;user-select:all"><?php echo esc_html( $token ); ?></code></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="watchfarm_connect_generate_token">
				<?php wp_nonce_field( 'watchfarm_connect_generate_token' ); ?>
				<?php submit_button( __( 'Generate / rotate upload token', 'watchfarm-connect' ), 'primary', 'submit', false ); ?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'Connected ASF instances', 'watchfarm-connect' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Instance', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Last report (UTC)', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'FarmWatch version', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Bots online / total', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Claims recorded', 'watchfarm-connect' ); ?></th></tr></thead><tbody>
			<?php if ( $uploads ) : foreach ( $uploads as $upload ) :
				$stats = json_decode( $upload->payload, true );
				$claim_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$claims_table} WHERE instance_id = %s", $upload->instance_id ) );
				?>
				<tr><td><code><?php echo esc_html( substr( $upload->instance_id, 0, 12 ) ); ?>…</code></td><td><?php echo esc_html( $upload->recorded_at ); ?></td><td><?php echo esc_html( $upload->farmwatch_version ); ?></td><td><?php echo esc_html( (int) $upload->bots_connected . ' / ' . (int) $upload->bots_total ); ?></td><td><?php echo esc_html( $claim_count ); ?></td></tr>
			<?php endforeach; else : ?><tr><td colspan="5"><?php esc_html_e( 'No FarmWatch instances have uploaded yet.', 'watchfarm-connect' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<h2 style="margin-top:28px"><?php esc_html_e( 'Recent claim events', 'watchfarm-connect' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Claimed (UTC)', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Type', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Steam target ID', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Source', 'watchfarm-connect' ); ?></th></tr></thead><tbody>
			<?php if ( $claims ) : foreach ( $claims as $claim ) : ?>
				<tr><td><?php echo esc_html( $claim->claimed_at ); ?></td><td><?php echo esc_html( $claim->target_type ); ?></td><td><?php echo esc_html( $claim->target_id ); ?></td><td><?php echo esc_html( $claim->source ); ?></td></tr>
			<?php endforeach; else : ?><tr><td colspan="4"><?php esc_html_e( 'No claim events have been received yet.', 'watchfarm-connect' ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<?php
	}
}

WatchFarm_Connect::instance();
