<?php
/**
 * Settings page markup.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$hfm_notice = isset( $_GET['hfm_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['hfm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code.
$hfm_notices = array(
	'saved'       => array( 'success', __( 'Secret settings saved.', 'heic-fix-monitor' ) ),
	'error'       => array( 'error', __( 'The secret could not be stored safely. Define the wp-config constants instead.', 'heic-fix-monitor' ) ),
	'bad_slack'   => array( 'error', __( 'The Slack webhook must be an https://hooks.slack.com/ URL.', 'heic-fix-monitor' ) ),
	'bad_webhook' => array( 'error', __( 'The generic webhook must use HTTPS.', 'heic-fix-monitor' ) ),
);

?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( isset( $hfm_notices[ $hfm_notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $hfm_notices[ $hfm_notice ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $hfm_notices[ $hfm_notice ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<?php settings_errors(); ?>

	<p>
		<?php
		printf(
			/* translators: %s: published troubleshooting URL. */
			esc_html__( 'This plugin only reads server state. Repair steps live in the %s.', 'heic-fix-monitor' ),
			'<a href="' . esc_url( 'https://managingwp.github.io/heic-ios18-fix/troubleshooting/' ) . '">' . esc_html__( 'troubleshooting guide', 'heic-fix-monitor' ) . '</a>'
		);
		?>
	</p>

	<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
		<?php
		settings_fields( Heic_Fix_Monitor_Settings::GROUP );
		do_settings_sections( Heic_Fix_Monitor_Settings::PAGE );
		submit_button( __( 'Save settings', 'heic-fix-monitor' ) );
		?>
	</form>

	<hr />

	<h2><?php echo esc_html__( 'Webhook secrets', 'heic-fix-monitor' ); ?></h2>
	<p class="description">
		<?php echo esc_html__( 'Production should define these in wp-config.php. Values entered here are encrypted with libsodium and stored with autoload disabled. Blank fields keep the current value; secrets are never shown again.', 'heic-fix-monitor' ); ?>
	</p>

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="hfm_save_secrets" />
		<?php wp_nonce_field( 'hfm_save_secrets', 'hfm_secrets_nonce' ); ?>

		<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Slack incoming webhook', 'heic-fix-monitor' ); ?></th>
				<td>
					<input type="password" class="regular-text code" id="hfm_slack_webhook_url" name="hfm_slack_webhook_url" value="" autocomplete="off" <?php disabled( Heic_Fix_Monitor_Secret_Store::is_constant( 'slack_webhook_url' ) ); ?> />
					<p class="description"><?php echo esc_html( Heic_Fix_Monitor_Secret_Store::describe( 'slack_webhook_url' ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Generic webhook URL', 'heic-fix-monitor' ); ?></th>
				<td>
					<input type="password" class="regular-text code" id="hfm_generic_webhook_url" name="hfm_generic_webhook_url" value="" autocomplete="off" <?php disabled( Heic_Fix_Monitor_Secret_Store::is_constant( 'generic_webhook_url' ) ); ?> />
					<p class="description"><?php echo esc_html( Heic_Fix_Monitor_Secret_Store::describe( 'generic_webhook_url' ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Generic webhook token', 'heic-fix-monitor' ); ?></th>
				<td>
					<input type="password" class="regular-text code" id="hfm_generic_webhook_token" name="hfm_generic_webhook_token" value="" autocomplete="off" <?php disabled( Heic_Fix_Monitor_Secret_Store::is_constant( 'generic_webhook_token' ) ); ?> />
					<p class="description"><?php echo esc_html( Heic_Fix_Monitor_Secret_Store::describe( 'generic_webhook_token' ) ); ?></p>
				</td>
			</tr>
			<?php if ( ! Heic_Fix_Monitor_Secret_Store::encryption_available() ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Encryption unavailable', 'heic-fix-monitor' ); ?></th>
					<td><p class="description"><?php echo esc_html__( 'The sodium extension is missing. Define the wp-config constants; the plugin will refuse to store plaintext secrets.', 'heic-fix-monitor' ); ?></p></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Remove stored secrets', 'heic-fix-monitor' ); ?></th>
				<td><label><input type="checkbox" name="hfm_clear_secrets" value="1" /> <?php echo esc_html__( 'Delete all encrypted secrets from the database', 'heic-fix-monitor' ); ?></label></td>
			</tr>
		</tbody>
	</table>

		<p class="description"><?php echo esc_html__( 'Re-enter only the values you want to change, then submit.', 'heic-fix-monitor' ); ?></p>
		<?php submit_button( __( 'Save secrets', 'heic-fix-monitor' ), 'secondary' ); ?>
	</form>
</div>
