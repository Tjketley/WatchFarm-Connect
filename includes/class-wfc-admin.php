<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WFC_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wfc_generate_token', array( __CLASS__, 'generate_token' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'WatchFarm Connect', 'watchfarm-connect' ),
			__( 'WatchFarm', 'watchfarm-connect' ),
			'manage_options',
			'watchfarm-connect',
			array( __CLASS__, 'render' ),
			'dashicons-chart-area',
			58
		);
	}

	public static function generate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WatchFarm Connect.', 'watchfarm-connect' ) );
		}
		check_admin_referer( 'wfc_generate_token' );

		try {
			$token = WFC_Auth::generate_token();
		} catch ( Exception $exception ) {
			wp_die( esc_html__( 'A secure upload token could not be generated on this server.', 'watchfarm-connect' ) );
		}

		$settings = get_option( 'wfc_settings', array() );
		$settings['token_hash'] = hash( 'sha256', $token );
		update_option( 'wfc_settings', $settings, false );

		set_transient( 'wfc_new_token_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=watchfarm-connect&token=generated' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;

		$token = get_transient( 'wfc_new_token_' . get_current_user_id() );
		if ( $token ) {
			delete_transient( 'wfc_new_token_' . get_current_user_id() );
		}
		$settings = get_option( 'wfc_settings', array() );
		$snapshots_table = WFC_Database::table( 'snapshots' );
		$claims_table = WFC_Database::table( 'claims' );
		$latest = $wpdb->get_results(
			"SELECT s.* FROM {$snapshots_table} s
			 INNER JOIN (SELECT instance_id, MAX(id) AS latest_id FROM {$snapshots_table} GROUP BY instance_id) latest
			 ON latest.latest_id = s.id
			 ORDER BY s.recorded_at DESC LIMIT 20"
		);
		$recent_claims = $wpdb->get_results( "SELECT * FROM {$claims_table} ORDER BY claimed_at DESC LIMIT 30" );
		$total_instances = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT instance_id) FROM {$snapshots_table}" );
		$total_claims = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$claims_table}" );
		$total_uploads = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$snapshots_table}" );
		$latest_stats = array();
		$bots_total = 0;
		$bots_connected = 0;
		$claim_total = 0;
		$farming_seconds = 0;
		foreach ( $latest as $snapshot ) {
			$stats = json_decode( $snapshot->payload, true );
			if ( ! is_array( $stats ) ) {
				continue;
			}
			$latest_stats[] = $stats;
			$bots_total += absint( $stats['bots_total'] ?? 0 );
			$bots_connected += absint( $stats['bots_connected'] ?? 0 );
			$claim_summary = $stats['claim_summary'] ?? array();
			$farming_summary = $stats['farming_summary'] ?? array();
			$claim_total += absint( $claim_summary['total'] ?? 0 );
			$farming_seconds += (float) ( $farming_summary['lifetimeSeconds'] ?? 0 );
		}
		$endpoint = rest_url( 'watchfarm-connect/v1/upload' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WatchFarm Connect', 'watchfarm-connect' ); ?></h1>
			<p><?php esc_html_e( 'Your private FarmWatch fleet dashboard. Data is pushed outbound from ASF over HTTPS; this plugin never connects back to or controls your ASF instance.', 'watchfarm-connect' ); ?></p>
			<?php if ( $token ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'Copy this token now. It will not be shown again. Rotating it immediately invalidates the previous token.', 'watchfarm-connect' ); ?></strong></p><p><code style="user-select:all;word-break:break-all;font-size:15px"><?php echo esc_html( $token ); ?></code></p></div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;max-width:1100px;margin:20px 0">
				<?php
				$cards = array(
					__( 'Connected instances', 'watchfarm-connect' ) => number_format_i18n( $total_instances ),
					__( 'Snapshots received', 'watchfarm-connect' ) => number_format_i18n( $total_uploads ),
					__( 'Lifetime claims', 'watchfarm-connect' ) => number_format_i18n( $claim_total ),
					__( 'Stored claim events', 'watchfarm-connect' ) => number_format_i18n( $total_claims ),
					__( 'Bots online / total', 'watchfarm-connect' ) => number_format_i18n( $bots_connected ) . ' / ' . number_format_i18n( $bots_total ),
					__( 'Farming hours', 'watchfarm-connect' ) => number_format_i18n( $farming_seconds / 3600, 1 ),
				);
				foreach ( $cards as $label => $value ) :
					?>
					<div style="background:#101820;color:#edf3f6;border:1px solid #26343d;border-radius:10px;padding:16px">
						<div style="color:#9aabb6;font-size:12px"><?php echo esc_html( $label ); ?></div>
						<div style="font-size:24px;font-weight:700;color:#63e6a4;margin-top:6px"><?php echo esc_html( $value ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div style="max-width:1100px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:18px 0">
				<h2 style="margin-top:0"><?php esc_html_e( 'Uploader connection', 'watchfarm-connect' ); ?></h2>
				<p><?php esc_html_e( 'Generate a site token and enter it into FarmWatch → Safety & controls → WatchFarm Connect. The uploader makes outbound HTTPS requests; no inbound ASF ports or tunnels are required.', 'watchfarm-connect' ); ?></p>
				<p><strong><?php esc_html_e( 'Upload endpoint:', 'watchfarm-connect' ); ?></strong> <code><?php echo esc_html( $endpoint ); ?></code></p>
				<p><strong><?php esc_html_e( 'Method:', 'watchfarm-connect' ); ?></strong> <code>POST</code> &nbsp; <strong><?php esc_html_e( 'Authentication:', 'watchfarm-connect' ); ?></strong> <code>Authorization: Bearer YOUR_TOKEN</code></p>
				<p><strong><?php esc_html_e( 'Token status:', 'watchfarm-connect' ); ?></strong> <?php echo ! empty( $settings['token_hash'] ) ? esc_html__( 'Configured', 'watchfarm-connect' ) : esc_html__( 'Not configured', 'watchfarm-connect' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wfc_generate_token">
					<?php wp_nonce_field( 'wfc_generate_token' ); ?>
					<?php submit_button( __( 'Generate / rotate upload token', 'watchfarm-connect' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<h2><?php esc_html_e( 'ASF instances', 'watchfarm-connect' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php esc_html_e( 'Instance', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Last report (UTC)', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'FarmWatch version', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Bots online / total', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Claims', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Auto-claim', 'watchfarm-connect' ); ?></th></tr></thead><tbody>
			<?php if ( $latest ) : foreach ( $latest as $snapshot ) :
				$stats = json_decode( $snapshot->payload, true );
				$stats = is_array( $stats ) ? $stats : array();
				$claims = $stats['claim_summary'] ?? array();
				?>
				<tr>
					<td><code><?php echo esc_html( substr( $snapshot->instance_id, 0, 12 ) ); ?>…</code></td>
					<td><?php echo esc_html( $snapshot->recorded_at ); ?></td>
					<td><?php echo esc_html( $stats['farmwatch_version'] ?? 'Unknown' ); ?></td>
					<td><?php echo esc_html( absint( $stats['bots_connected'] ?? 0 ) . ' / ' . absint( $stats['bots_total'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( absint( $claims['total'] ?? 0 ) ) ); ?></td>
					<td><?php echo ! empty( $stats['global_auto_claim_enabled'] ) && empty( $stats['observe_only'] ) ? '<span style="color:#b45309;font-weight:600">' . esc_html__( 'Active', 'watchfarm-connect' ) . '</span>' : esc_html__( 'Off / observe-only', 'watchfarm-connect' ); ?></td>
				</tr>
			<?php endforeach; else : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No FarmWatch instances have uploaded yet.', 'watchfarm-connect' ); ?></td></tr>
			<?php endif; ?>
			</tbody></table>

			<h2 style="margin-top:28px"><?php esc_html_e( 'Recent claim history', 'watchfarm-connect' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php esc_html_e( 'Claimed (UTC)', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Instance', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Target type', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Steam target ID', 'watchfarm-connect' ); ?></th><th><?php esc_html_e( 'Source', 'watchfarm-connect' ); ?></th></tr></thead><tbody>
			<?php if ( $recent_claims ) : foreach ( $recent_claims as $claim ) : ?>
				<tr><td><?php echo esc_html( $claim->claimed_at ); ?></td><td><code><?php echo esc_html( substr( $claim->instance_id, 0, 12 ) ); ?>…</code></td><td><?php echo esc_html( $claim->target_type ); ?></td><td><?php echo esc_html( $claim->target_id ); ?></td><td><?php echo esc_html( $claim->source ); ?></td></tr>
			<?php endforeach; else : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No claim events have been received yet.', 'watchfarm-connect' ); ?></td></tr>
			<?php endif; ?>
			</tbody></table>
			<p style="color:#646970;margin-top:16px"><?php esc_html_e( 'Times are stored and displayed in UTC. Raw diagnostics, Steam credentials, Steam IDs, account names and bot names are not uploaded.', 'watchfarm-connect' ); ?></p>
		</div>
		<?php
	}
}