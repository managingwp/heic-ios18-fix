<?php
/**
 * Status page markup.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$hfm_state    = Heic_Fix_Monitor_State_Store::current();
$hfm_status   = isset( $hfm_state['state'] ) ? (string) $hfm_state['state'] : '';
$hfm_document = isset( $hfm_state['document'] ) && is_array( $hfm_state['document'] ) ? $hfm_state['document'] : array();
$hfm_signals  = isset( $hfm_document['signals'] ) && is_array( $hfm_document['signals'] ) ? $hfm_document['signals'] : array();
$hfm_codes    = isset( $hfm_state['codes'] ) && is_array( $hfm_state['codes'] ) ? $hfm_state['codes'] : array();
$hfm_notice   = isset( $_GET['hfm_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['hfm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code.
$hfm_notices  = array(
	'checked'           => array( 'success', __( 'Check completed and transitions were evaluated.', 'heic-fix-monitor' ) ),
	'locked'            => array( 'warning', __( 'Another check is already running. Try again shortly.', 'heic-fix-monitor' ) ),
	'test_sent'         => array( 'success', __( 'The test notification was accepted by the channel.', 'heic-fix-monitor' ) ),
	'test_skipped'      => array( 'warning', __( 'The test was skipped: the channel is disabled or not configured.', 'heic-fix-monitor' ) ),
	'test_failed'       => array( 'error', __( 'The test notification failed. Check the channel configuration and server logs.', 'heic-fix-monitor' ) ),
	'test_invalid'      => array( 'error', __( 'The selected channel is not valid.', 'heic-fix-monitor' ) ),
	'reset'             => array( 'success', __( 'Baseline reset. The next OK result is silent; the next non-OK result alerts.', 'heic-fix-monitor' ) ),
	'reset_unconfirmed' => array( 'warning', __( 'Baseline reset was not confirmed.', 'heic-fix-monitor' ) ),
);

$hfm_next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'hfm_run_check' ) : false;

?>
<div class="wrap hfm-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( isset( $hfm_notices[ $hfm_notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $hfm_notices[ $hfm_notice ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $hfm_notices[ $hfm_notice ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<p>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Heic_Fix_Monitor_Settings::PAGE ) ); ?>">
			<?php echo esc_html__( 'Configure paths, schedule and notifications', 'heic-fix-monitor' ); ?>
		</a>
	</p>

	<?php if ( '' === $hfm_status ) : ?>
		<div class="notice notice-info inline">
			<p><?php echo esc_html__( 'No check has run yet. Run one now, or wait for the scheduled WP-Cron event.', 'heic-fix-monitor' ); ?></p>
		</div>
	<?php else : ?>
		<h2><?php echo esc_html__( 'Current state', 'heic-fix-monitor' ); ?></h2>

		<p class="hfm-state hfm-state--<?php echo esc_attr( $hfm_status ); ?>">
			<span class="dashicons <?php echo esc_attr( Heic_Fix_Monitor_Admin::state_icon( $hfm_status ) ); ?>" aria-hidden="true"></span>
			<strong><?php echo esc_html( Heic_Fix_Monitor_Admin::state_label( $hfm_status ) ); ?></strong>
			<span class="hfm-state__code"><?php echo esc_html( $hfm_status ); ?></span>
		</p>

		<?php if ( ! empty( $hfm_codes ) ) : ?>
			<p><strong><?php echo esc_html__( 'Reason codes:', 'heic-fix-monitor' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( 'strval', $hfm_codes ) ) ); ?></p>
		<?php endif; ?>

		<table class="widefat striped hfm-meta" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last checked', 'heic-fix-monitor' ); ?></th>
					<td><?php echo esc_html( Heic_Fix_Monitor_Admin::format_time( $hfm_state['last_checked'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last changed', 'heic-fix-monitor' ); ?></th>
					<td><?php echo esc_html( Heic_Fix_Monitor_Admin::format_time( $hfm_state['last_changed'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last good', 'heic-fix-monitor' ); ?></th>
					<td><?php echo esc_html( Heic_Fix_Monitor_Admin::format_time( $hfm_state['last_good'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last notified', 'heic-fix-monitor' ); ?></th>
					<td><?php echo esc_html( Heic_Fix_Monitor_Admin::format_time( $hfm_state['last_notified'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Next scheduled check', 'heic-fix-monitor' ); ?></th>
					<td><?php echo esc_html( $hfm_next ? Heic_Fix_Monitor_Admin::format_time( $hfm_next ) : __( 'Not scheduled', 'heic-fix-monitor' ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<h2><?php echo esc_html__( 'Per-signal evidence', 'heic-fix-monitor' ); ?></h2>
		<table class="widefat striped hfm-signals">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Probe status and bounded evidence for the last check.', 'heic-fix-monitor' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Signal', 'heic-fix-monitor' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Status', 'heic-fix-monitor' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Code', 'heic-fix-monitor' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Evidence', 'heic-fix-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $hfm_signals as $hfm_name => $hfm_signal ) : ?>
				<?php $hfm_lines = Heic_Fix_Monitor_Admin::evidence_lines( is_array( $hfm_signal ) ? $hfm_signal : array() ); ?>
				<tr>
					<td><code><?php echo esc_html( (string) $hfm_name ); ?></code></td>
					<td><?php echo esc_html( (string) ( $hfm_signal['status'] ?? 'unknown' ) ); ?></td>
					<td><code><?php echo esc_html( (string) ( $hfm_signal['code'] ?? 'unknown' ) ); ?></code></td>
					<td>
						<?php if ( empty( $hfm_lines ) ) : ?>
							<span class="description"><?php echo esc_html__( 'No evidence recorded.', 'heic-fix-monitor' ); ?></span>
						<?php else : ?>
							<ul class="hfm-evidence">
								<?php foreach ( $hfm_lines as $hfm_line ) : ?>
									<li><code><?php echo esc_html( $hfm_line ); ?></code></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr />

	<h2><?php echo esc_html__( 'Manual actions', 'heic-fix-monitor' ); ?></h2>
	<p class="description">
		<?php echo esc_html__( 'Manual checks are read-only. They run in this request, decode the verified sample once, and never change server files or PHP configuration.', 'heic-fix-monitor' ); ?>
	</p>

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="hfm_run_check_now" />
		<?php wp_nonce_field( 'hfm_run_check_now', 'hfm_run_check_now_nonce' ); ?>
		<?php submit_button( __( 'Run check now', 'heic-fix-monitor' ), 'primary', 'submit', false ); ?>
	</form>

	<hr />

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="hfm_send_test" />
		<?php wp_nonce_field( 'hfm_send_test', 'hfm_send_test_nonce' ); ?>
		<p>
			<label for="hfm_test_channel"><?php echo esc_html__( 'Test channel', 'heic-fix-monitor' ); ?></label>
			<select id="hfm_test_channel" name="hfm_test_channel">
				<option value="email"><?php echo esc_html__( 'Email', 'heic-fix-monitor' ); ?></option>
				<option value="slack"><?php echo esc_html__( 'Slack', 'heic-fix-monitor' ); ?></option>
				<option value="webhook"><?php echo esc_html__( 'JSON webhook', 'heic-fix-monitor' ); ?></option>
			</select>
			<?php submit_button( __( 'Send test notification', 'heic-fix-monitor' ), 'secondary', 'submit', false ); ?>
		</p>
		<p class="description"><?php echo esc_html__( 'This makes one real outbound request to the selected configured channel.', 'heic-fix-monitor' ); ?></p>
	</form>

	<hr />

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="hfm_reset_baseline" />
		<?php wp_nonce_field( 'hfm_reset_baseline', 'hfm_reset_baseline_nonce' ); ?>
		<p>
			<label>
				<input type="checkbox" name="hfm_confirm_reset" value="1" />
				<?php echo esc_html__( 'Reset the stored baseline and notification timestamps (plugin history only; server files and settings are untouched).', 'heic-fix-monitor' ); ?>
			</label>
		</p>
		<?php submit_button( __( 'Reset baseline', 'heic-fix-monitor' ), 'secondary', 'submit', false ); ?>
	</form>

	<p class="description">
		<?php
		printf(
			/* translators: %s: monitoring documentation URL. */
			esc_html__( 'Need help? Read %s.', 'heic-fix-monitor' ),
			'<a href="' . esc_url( 'https://managingwp.github.io/heic-ios18-fix/troubleshooting/' ) . '">' . esc_html__( 'the troubleshooting runbook', 'heic-fix-monitor' ) . '</a>'
		);
		?>
	</p>
</div>
