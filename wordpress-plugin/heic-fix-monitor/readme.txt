=== HEIC Fix Monitor ===
Contributors: managingwp
Tags: heic, imagick, monitoring, webhooks, diagnostics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only monitoring and transition alerts for the iOS 18 HEIC/Imagick repair.

== Description ==

HEIC Fix Monitor evaluates the server-side HEIC repair described at
https://managingwp.github.io/heic-ios18-fix/ and alerts when it regresses.

The primary signal decodes a verified iOS 18 sample through Imagick inside
WP-Cron, which runs in the same PHP-FPM context as uploads and therefore
catches the GD symbol-collision regression. Optional corroborating signals
parse the stable `heic-fix.sh check -q --php X.Y` fields and inspect prefix
files, ini state and ELF linkage.

**Features**

* Read-only probes. The plugin never builds, enables, disables or repairs Imagick.
* States: `ok`, `degraded`, `affected`, `missing`, `error`.
* Deterministic fingerprinting: one alert per transition, one recovery notice.
* Email, Slack incoming webhook and generic JSON webhook channels.
* Optional daily digest and heartbeat.
* Site-scoped settings with WP-Cron, WP-CLI and multisite support.
* Secrets from wp-config constants or an encrypted, non-autoloaded option.
* Manual "check now", test notification and baseline reset, all capability- and nonce-gated.

== Installation ==

1. Upload the plugin zip through Plugins > Add New > Upload Plugin, or copy
   `heic-fix-monitor` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Settings > HEIC Fix Monitor and configure PHP versions, paths,
   recipients and channels.
4. Prefer constants in `wp-config.php` for webhook secrets:

```php
define( 'HEIC_FIX_MONITOR_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/...' );
define( 'HEIC_FIX_MONITOR_GENERIC_WEBHOOK_URL', 'https://example.com/hooks/heic' );
define( 'HEIC_FIX_MONITOR_GENERIC_WEBHOOK_TOKEN', '...' );
define( 'HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS', array( '/opt/heic-ios18-fix/heic-fix.sh' ) );
define( 'HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS', array( 'example.com' ) );
```

== Frequently Asked Questions ==

= Does this plugin modify the server? =

No. It only reads files, runs `heic-fix.sh check` when allowed, and decodes
the bundled sample in memory. It never runs `build`, `php`, `enable` or
`rollback`, never writes to `/usr` or `/opt/heic-ios18-fix`, and never changes
PHP configuration.

= Why did no email arrive for an `ok` result? =

The first `ok` establishes a silent baseline. Notifications are transition
based, so healthy servers stay quiet. Repeated identical failures are silent
until the configured re-notify window.

= What if WP-Cron does not run? =

WP-Cron depends on site traffic. Use a real system cron entry with
`wp heic-monitor check --notify`, or use the server-level cron and monit
examples in the monitoring documentation.

= Why is the state `degraded`? =

The primary FPM decode may still be healthy while a corroborating signal is
unavailable: process execution is disabled, `check` timed out, or the decode
was skipped by the memory guard. The status page names the exact reason code.

== Screenshots ==

1. Status summary with text-labelled state and per-signal evidence.
2. Settings page with paths, schedule and channel configuration.

== Changelog ==

= 0.1.0 =
* Initial release: read-only probes, fingerprint transitions, email/Slack/JSON webhook alerts, digest, WP-CLI and multisite support.
