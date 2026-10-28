<?php
/**
 * Settings registration and sanitization.
 *
 * @package Heic_Fix_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers site-scoped settings with the Settings API.
 */
final class Heic_Fix_Monitor_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'hfm_settings';

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const GROUP = 'hfm_settings_group';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE = 'hfm-settings';

	/**
	 * Allowed schedule intervals.
	 *
	 * @var array<string,string>
	 */
	const INTERVALS = array(
		'hfm_15min'   => 'Every 15 minutes',
		'hfm_30min'   => 'Every 30 minutes',
		'hourly'      => 'Hourly',
		'hfm_6hours'  => 'Every 6 hours',
		'daily'       => 'Daily',
	);

	/**
	 * Singleton instance.
	 *
	 * @var Heic_Fix_Monitor_Settings|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Heic_Fix_Monitor_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'php_versions'         => array(),
			'prefix'               => '/opt/heic-ios18-fix',
			'sample_path'          => '',
			'check_binary'         => '/opt/heic-ios18-fix/heic-fix.sh',
			'check_enabled'        => 1,
			'filesystem_enabled'   => 1,
			'decode_enabled'       => 1,
			'interval'             => 'hourly',
			'timeout'              => 30,
			'decode_min_interval'  => 300,
			'memory_floor_mb'      => 256,
			'min_jpeg_bytes'       => 1024,
			'min_width'            => 100,
			'min_height'           => 100,
			'email_enabled'        => 1,
			'emails'               => array(),
			'slack_enabled'        => 0,
			'webhook_enabled'      => 0,
			'renotify_hours'       => 24,
			'retry_max'            => 2,
			'webhook_timeout'      => 5,
			'digest_enabled'       => 0,
			'digest_time'          => '08:00',
			'heartbeat_enabled'    => 0,
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_hfm_save_secrets', array( $this, 'handle_save_secrets' ) );
	}

	/**
	 * Register the option, sections, and fields.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'hfm_paths',
			__( 'Paths and PHP versions', 'heic-fix-monitor' ),
			array( $this, 'render_paths_intro' ),
			self::PAGE
		);

		add_settings_field(
			'hfm_php_versions',
			__( 'PHP versions', 'heic-fix-monitor' ),
			array( $this, 'render_versions_field' ),
			self::PAGE,
			'hfm_paths'
		);

		add_settings_field(
			'hfm_paths',
			__( 'Prefix, sample and check binary', 'heic-fix-monitor' ),
			array( $this, 'render_paths_field' ),
			self::PAGE,
			'hfm_paths'
		);

		add_settings_section(
			'hfm_schedule',
			__( 'Schedule and resource guards', 'heic-fix-monitor' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'hfm_schedule',
			__( 'Checks', 'heic-fix-monitor' ),
			array( $this, 'render_schedule_field' ),
			self::PAGE,
			'hfm_schedule'
		);

		add_settings_section(
			'hfm_channels',
			__( 'Notifications', 'heic-fix-monitor' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'hfm_channels',
			__( 'Channels and thresholds', 'heic-fix-monitor' ),
			array( $this, 'render_channels_field' ),
			self::PAGE,
			'hfm_channels'
		);
	}

	/**
	 * Sanitize the settings array field by field.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = self::get();
		$clean    = $defaults;

		if ( ! is_array( $input ) ) {
			add_settings_error( self::OPTION, 'hfm_invalid', __( 'The submitted settings were not valid.', 'heic-fix-monitor' ) );
			return $current;
		}

		$input = wp_unslash( $input );

		$clean['php_versions'] = $this->sanitize_versions( $input['php_versions'] ?? '' );
		$clean['prefix']       = $this->sanitize_absolute_path( $input['prefix'] ?? '', $current['prefix'], 'prefix' );
		$clean['sample_path']  = $this->sanitize_absolute_path( $input['sample_path'] ?? '', $current['sample_path'], 'sample_path', true );
		$clean['check_binary'] = $this->sanitize_absolute_path( $input['check_binary'] ?? '', $current['check_binary'], 'check_binary' );

		$clean['interval'] = isset( $input['interval'] ) && isset( self::INTERVALS[ $input['interval'] ] )
			? $input['interval']
			: $current['interval'];

		$clean['timeout']             = $this->clamp_int( $input['timeout'] ?? 0, 5, 300, $current['timeout'] );
		$clean['decode_min_interval'] = $this->clamp_int( $input['decode_min_interval'] ?? 0, 60, 86400, $current['decode_min_interval'] );
		$clean['memory_floor_mb']     = $this->clamp_int( $input['memory_floor_mb'] ?? 0, 64, 2048, $current['memory_floor_mb'] );
		$clean['min_jpeg_bytes']      = $this->clamp_int( $input['min_jpeg_bytes'] ?? 0, 128, 10485760, $current['min_jpeg_bytes'] );
		$clean['min_width']           = $this->clamp_int( $input['min_width'] ?? 0, 1, 20000, $current['min_width'] );
		$clean['min_height']          = $this->clamp_int( $input['min_height'] ?? 0, 1, 20000, $current['min_height'] );
		$clean['renotify_hours']      = $this->clamp_int( $input['renotify_hours'] ?? 0, 1, 720, $current['renotify_hours'] );
		$clean['retry_max']           = $this->clamp_int( $input['retry_max'] ?? 0, 0, 5, $current['retry_max'] );
		$clean['webhook_timeout']     = $this->clamp_int( $input['webhook_timeout'] ?? 0, 2, 15, $current['webhook_timeout'] );

		$clean['decode_enabled']     = empty( $input['decode_enabled'] ) ? 0 : 1;
		$clean['check_enabled']      = empty( $input['check_enabled'] ) ? 0 : 1;
		$clean['filesystem_enabled'] = empty( $input['filesystem_enabled'] ) ? 0 : 1;
		$clean['email_enabled']      = empty( $input['email_enabled'] ) ? 0 : 1;
		$clean['slack_enabled']      = empty( $input['slack_enabled'] ) ? 0 : 1;
		$clean['webhook_enabled']    = empty( $input['webhook_enabled'] ) ? 0 : 1;
		$clean['digest_enabled']     = empty( $input['digest_enabled'] ) ? 0 : 1;
		$clean['heartbeat_enabled']  = empty( $input['heartbeat_enabled'] ) ? 0 : 1;

		$clean['emails'] = $this->sanitize_emails( $input['emails'] ?? array() );

		$digest_time = isset( $input['digest_time'] ) ? sanitize_text_field( (string) $input['digest_time'] ) : '';
		$clean['digest_time'] = preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $digest_time )
			? $digest_time
			: $current['digest_time'];

		if ( $clean['email_enabled'] && empty( $clean['emails'] ) ) {
			add_settings_error( self::OPTION, 'hfm_no_recipients', __( 'Email alerts are enabled but no valid recipient is configured.', 'heic-fix-monitor' ) );
		}

		if ( $clean['slack_enabled'] && ! Heic_Fix_Monitor_Secret_Store::has( 'slack_webhook_url' ) ) {
			add_settings_error( self::OPTION, 'hfm_no_slack', __( 'Slack alerts are enabled but no Slack webhook URL is configured.', 'heic-fix-monitor' ) );
		}

		if ( $clean['webhook_enabled'] && ! Heic_Fix_Monitor_Secret_Store::has( 'generic_webhook_url' ) ) {
			add_settings_error( self::OPTION, 'hfm_no_webhook', __( 'The JSON webhook is enabled but no webhook URL is configured.', 'heic-fix-monitor' ) );
		}

		return $clean;
	}

	/**
	 * Sanitize a PHP version list.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	private function sanitize_versions( $value ) {
		$parts = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$clean = array();

		foreach ( (array) $parts as $part ) {
			$part = trim( sanitize_text_field( (string) $part ) );
			if ( preg_match( '/^[0-9]+\.[0-9]+$/', $part ) ) {
				$clean[] = $part;
			}
		}

		$clean = array_slice( array_values( array_unique( $clean ) ), 0, 4 );
		sort( $clean, SORT_NATURAL );

		return $clean;
	}

	/**
	 * Sanitize an absolute path.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Previous value.
	 * @param string $field    Field key.
	 * @param bool   $optional Whether an empty value is allowed.
	 * @return string
	 */
	private function sanitize_absolute_path( $value, $fallback, $field, $optional = false ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value && $optional ) {
			return '';
		}

		$segments = explode( '/', str_replace( '\\', '/', $value ) );
		if ( '' === $value || '/' !== $value[0] || in_array( '..', $segments, true ) || false !== strpos( $value, "\0" ) ) {
			add_settings_error(
				self::OPTION,
				'hfm_bad_path_' . $field,
				sprintf(
					/* translators: %s: settings field name. */
					__( 'The %s must be an absolute path without parent-directory segments.', 'heic-fix-monitor' ),
					$field
				)
			);
			return (string) $fallback;
		}

		return rtrim( $value, '/' ) === '' ? '/' : rtrim( $value, '/' );
	}

	/**
	 * Clamp an integer to a range.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param int   $fallback Fallback.
	 * @return int
	 */
	private function clamp_int( $value, $min, $max, $fallback ) {
		$value = absint( $value );

		if ( $value < $min || $value > $max ) {
			return (int) $fallback;
		}

		return $value;
	}

	/**
	 * Sanitize recipient addresses.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	private function sanitize_emails( $value ) {
		$parts = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$clean = array();

		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( (string) $part ) );
			if ( $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}

		return array_slice( array_values( array_unique( $clean ) ), 0, 10 );
	}

	/**
	 * Intro text for the paths section.
	 *
	 * @return void
	 */
	public function render_paths_intro() {
		echo '<p>' . esc_html__( 'Probes read these paths only. The plugin never builds, enables, disables or repairs Imagick.', 'heic-fix-monitor' ) . '</p>';
	}

	/**
	 * Render the PHP versions field.
	 *
	 * @return void
	 */
	public function render_versions_field() {
		$settings = self::get();
		$value    = implode( ', ', $settings['php_versions'] );
		printf(
			'<input type="text" class="regular-text" id="hfm_php_versions" name="%1$s[php_versions]" value="%2$s" aria-describedby="hfm_php_versions_description" />',
			esc_attr( self::OPTION ),
			esc_attr( $value )
		);
		echo '<p class="description" id="hfm_php_versions_description">' . esc_html__( 'Comma-separated X.Y versions, for example 8.5. Leave empty to check the PHP version serving this request.', 'heic-fix-monitor' ) . '</p>';
	}

	/**
	 * Render path inputs.
	 *
	 * @return void
	 */
	public function render_paths_field() {
		$settings = self::get();
		$fields   = array(
			'prefix'       => __( 'Install prefix', 'heic-fix-monitor' ),
			'sample_path'  => __( 'Sample path (optional)', 'heic-fix-monitor' ),
			'check_binary' => __( 'heic-fix.sh path', 'heic-fix-monitor' ),
		);

		foreach ( $fields as $key => $label ) {
			printf(
				'<p><label for="hfm_%1$s">%2$s</label><br /><input type="text" class="regular-text code" id="hfm_%1$s" name="%3$s[%1$s]" value="%4$s" /></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( self::OPTION ),
				esc_attr( (string) $settings[ $key ] )
			);
		}

		echo '<p class="description">' . esc_html__( 'The check binary must also appear in HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS. The bundled sample is used when no sample path is set.', 'heic-fix-monitor' ) . '</p>';
	}

	/**
	 * Render schedule and guard inputs.
	 *
	 * @return void
	 */
	public function render_schedule_field() {
		$settings = self::get();

		echo '<p><label for="hfm_interval">' . esc_html__( 'Check interval', 'heic-fix-monitor' ) . '</label><br />';
		echo '<select id="hfm_interval" name="' . esc_attr( self::OPTION ) . '[interval]">';
		foreach ( self::INTERVALS as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $settings['interval'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></p>';

		$numbers = array(
			'timeout'             => array( __( 'Command timeout (seconds)', 'heic-fix-monitor' ), 5, 300 ),
			'decode_min_interval' => array( __( 'Minimum decode interval (seconds)', 'heic-fix-monitor' ), 60, 86400 ),
			'memory_floor_mb'     => array( __( 'Decode memory floor (MB)', 'heic-fix-monitor' ), 64, 2048 ),
			'min_jpeg_bytes'      => array( __( 'Minimum JPEG bytes', 'heic-fix-monitor' ), 128, 10485760 ),
			'min_width'           => array( __( 'Minimum width', 'heic-fix-monitor' ), 1, 20000 ),
			'min_height'          => array( __( 'Minimum height', 'heic-fix-monitor' ), 1, 20000 ),
		);

		foreach ( $numbers as $key => $meta ) {
			printf(
				'<p><label for="hfm_%1$s">%2$s</label> <input type="number" id="hfm_%1$s" name="%3$s[%1$s]" value="%4$d" min="%5$d" max="%6$d" class="small-text" /></p>',
				esc_attr( $key ),
				esc_html( $meta[0] ),
				esc_attr( self::OPTION ),
				(int) $settings[ $key ],
				(int) $meta[1],
				(int) $meta[2]
			);
		}

		$this->render_checkbox( 'decode_enabled', __( 'Run the in-FPM Imagick decode (primary signal)', 'heic-fix-monitor' ), $settings );
		$this->render_checkbox( 'check_enabled', __( 'Run heic-fix.sh check when process execution is available', 'heic-fix-monitor' ), $settings );
		$this->render_checkbox( 'filesystem_enabled', __( 'Inspect prefix files, ini state and ELF linkage', 'heic-fix-monitor' ), $settings );
	}

	/**
	 * Render notification inputs.
	 *
	 * @return void
	 */
	public function render_channels_field() {
		$settings = self::get();

		$this->render_checkbox( 'email_enabled', __( 'Email', 'heic-fix-monitor' ), $settings );
		printf(
			'<p><label for="hfm_emails">%1$s</label><br /><textarea id="hfm_emails" name="%2$s[emails]" rows="3" class="large-text code">%3$s</textarea></p>',
			esc_html__( 'Recipients (comma or newline separated)', 'heic-fix-monitor' ),
			esc_attr( self::OPTION ),
			esc_textarea( implode( "\n", (array) $settings['emails'] ) )
		);

		$this->render_checkbox( 'slack_enabled', __( 'Slack incoming webhook', 'heic-fix-monitor' ), $settings );
		$this->render_checkbox( 'webhook_enabled', __( 'Generic JSON webhook', 'heic-fix-monitor' ), $settings );

		$numbers = array(
			'renotify_hours'  => array( __( 'Re-notify window (hours)', 'heic-fix-monitor' ), 1, 720 ),
			'retry_max'       => array( __( 'Webhook retries', 'heic-fix-monitor' ), 0, 5 ),
			'webhook_timeout' => array( __( 'Webhook timeout (seconds)', 'heic-fix-monitor' ), 2, 15 ),
		);

		foreach ( $numbers as $key => $meta ) {
			printf(
				'<p><label for="hfm_%1$s">%2$s</label> <input type="number" id="hfm_%1$s" name="%3$s[%1$s]" value="%4$d" min="%5$d" max="%6$d" class="small-text" /></p>',
				esc_attr( $key ),
				esc_html( $meta[0] ),
				esc_attr( self::OPTION ),
				(int) $settings[ $key ],
				(int) $meta[1],
				(int) $meta[2]
			);
		}

		$this->render_checkbox( 'digest_enabled', __( 'Daily digest', 'heic-fix-monitor' ), $settings );
		printf(
			'<p><label for="hfm_digest_time">%1$s</label> <input type="time" id="hfm_digest_time" name="%2$s[digest_time]" value="%3$s" /></p>',
			esc_html__( 'Digest time (site timezone)', 'heic-fix-monitor' ),
			esc_attr( self::OPTION ),
			esc_attr( (string) $settings['digest_time'] )
		);

		$this->render_checkbox( 'heartbeat_enabled', __( 'Include a heartbeat line in the digest', 'heic-fix-monitor' ), $settings );
	}

	/**
	 * Render a checkbox with a hidden zero value.
	 *
	 * @param string              $key      Setting key.
	 * @param string              $label    Label.
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	private function render_checkbox( $key, $label, array $settings ) {
		printf(
			'<input type="hidden" name="%1$s[%2$s]" value="0" /><p><label for="hfm_%2$s"><input type="checkbox" id="hfm_%2$s" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label></p>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( ! empty( $settings[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Save encrypted secrets from the separate secret form.
	 *
	 * @return void
	 */
	public function handle_save_secrets() {
		check_admin_referer( 'hfm_save_secrets', 'hfm_secrets_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'heic-fix-monitor' ), 403 );
		}

		$values = array();
		$clear  = ! empty( $_POST['hfm_clear_secrets'] );

		if ( isset( $_POST['hfm_slack_webhook_url'] ) ) {
			$url = esc_url_raw( wp_unslash( (string) $_POST['hfm_slack_webhook_url'] ) );
			if ( '' !== $url && ! preg_match( '#^https://hooks\.slack\.com/#i', $url ) ) {
				$this->redirect_secret_result( 'bad_slack' );
			}
			$values['slack_webhook_url'] = $url;
		}

		if ( isset( $_POST['hfm_generic_webhook_url'] ) ) {
			$url = esc_url_raw( wp_unslash( (string) $_POST['hfm_generic_webhook_url'] ) );
			if ( '' !== $url && ! preg_match( '#^https://#i', $url ) ) {
				$this->redirect_secret_result( 'bad_webhook' );
			}
			$values['generic_webhook_url'] = $url;
		}

		if ( isset( $_POST['hfm_generic_webhook_token'] ) ) {
			$values['generic_webhook_token'] = sanitize_text_field( wp_unslash( (string) $_POST['hfm_generic_webhook_token'] ) );
		}

		$result = Heic_Fix_Monitor_Secret_Store::save( $values, $clear );

		$this->redirect_secret_result( is_wp_error( $result ) ? 'error' : 'saved' );
	}

	/**
	 * Redirect back to the settings page with a code-only notice.
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private function redirect_secret_result( $code ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE,
					'hfm_notice' => sanitize_key( $code ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
