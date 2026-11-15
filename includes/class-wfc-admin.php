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

		$token    = WFC_Auth::generate_token();
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

		$token = get_transient( 'wfc_new_token_' . get_current_user_id() );
		if ( $token ) {
			delete_transient( 'wfc_new_token_' . get_current_user_id() );
		}
		$settings = get_option( 'wfc_settings', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WatchFarm Connect', 'watchfarm-connect' ); ?></h1>
			<p><?php esc_html_e( 'One-way statistics reporting from FarmWatch. This plugin does not provide remote bot controls or expose an ASF management interface.', 'watchfarm-connect' ); ?></p>
			<?php if ( $token ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'Copy this token now. It will not be shown again.', 'watchfarm-connect' ); ?></strong></p><p><code style="user-select:all;word-break:break-all"><?php echo esc_html( $token ); ?></code></p></div>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Uploader connection', 'watchfarm-connect' ); ?></h2>
			<p><?php esc_html_e( 'Generate a site token and enter it into the optional Connect uploader settings in FarmWatch Core. The uploader makes outbound HTTPS requests to this WordPress site; no inbound ASF ports or tunnels are required.', 'watchfarm-connect' ); ?></p>
			<p><strong><?php esc_html_e( 'Upload endpoint:', 'watchfarm-connect' ); ?></strong> <code><?php echo esc_html( rest_url( 'watchfarm-connect/v1/upload' ) ); ?></code></p>
			<p><strong><?php esc_html_e( 'Token status:', 'watchfarm-connect' ); ?></strong> <?php echo ! empty( $settings['token_hash'] ) ? esc_html__( 'Configured', 'watchfarm-connect' ) : esc_html__( 'Not configured', 'watchfarm-connect' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wfc_generate_token">
				<?php wp_nonce_field( 'wfc_generate_token' ); ?>
				<?php submit_button( __( 'Generate / rotate upload token', 'watchfarm-connect' ), 'primary' ); ?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'Statistics dashboard', 'watchfarm-connect' ); ?></h2>
			<p><?php esc_html_e( 'The receiver and storage foundation is in place. Dashboard cards, history charts, filters and display blocks are being added in the next development stage.', 'watchfarm-connect' ); ?></p>
		</div>
		<?php
	}
}
