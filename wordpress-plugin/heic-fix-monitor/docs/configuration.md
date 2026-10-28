# HEIC Fix Monitor — configuration

The plugin is a read-only monitor for the iOS 18 HEIC/Imagick repair described
at <https://managingwp.github.io/heic-ios18-fix/>. It never builds, enables,
disables, rolls back or repairs anything on the server.

## Requirements

- WordPress 6.4 or newer, PHP 8.0 or newer.
- The Imagick extension for the primary in-FPM decode signal.
- Optional: `heic-fix.sh` and `/usr/bin/readelf` for corroborating signals.
- No Composer dependencies at runtime.

## Settings

Open **Settings → HEIC Fix Monitor** for status and manual actions, and
**Settings → HEIC Fix Monitor Settings** for configuration.

| Setting | Default | Notes |
| --- | --- | --- |
| PHP versions | empty | Comma-separated `X.Y`. Empty checks the PHP version serving the request. |
| Install prefix | `/opt/heic-ios18-fix` | Absolute path, read-only. |
| Sample path | empty | Empty uses the bundled, checksum-verified iOS 18 sample. A custom path must be an absolute `.heic`/`.heif` file. |
| `heic-fix.sh` path | `/opt/heic-ios18-fix/heic-fix.sh` | Must resolve to an allowlisted `heic-fix.sh`. |
| Interval | hourly | 15 minutes, 30 minutes, hourly, 6 hours or daily. |
| Command timeout | 30 s | Applies to `check` and ELF tools. |
| Minimum decode interval | 300 s | Cron runs skip a second decode inside this window. |
| Decode memory floor | 256 MB | Below this, the decode is skipped and the state degrades. |
| Minimum JPEG bytes / dimensions | 1024 / 100 / 100 | Guards against a hollow decode. |
| Re-notify window | 24 h | Identical failures stay silent until this window passes. |
| Retries | 2 | Bounded exponential retry per failed channel. |
| Digest | disabled | Daily email summary at the configured site-local time. |
| Heartbeat | disabled | Adds the next-check line to the digest, including when healthy. |

## wp-config constants

Constants are preferred in production. They override the encrypted option and
are never shown in the UI.

```php
define( 'HEIC_FIX_MONITOR_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/...' );
define( 'HEIC_FIX_MONITOR_GENERIC_WEBHOOK_URL', 'https://example.com/hooks/heic' );
define( 'HEIC_FIX_MONITOR_GENERIC_WEBHOOK_TOKEN', '...' );

// Optional: allow a repository copy of the check script in addition to the
// default /opt/heic-ios18-fix/heic-fix.sh.
define( 'HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS', array( '/root/heic-ios18-fix/heic-fix.sh' ) );

// Required for the generic JSON webhook: exact destination hostnames.
define( 'HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS', array( 'example.com' ) );

// Optional 32-byte base64 key. Without it, the key is derived from wp_salt().
// Rotating salts invalidates stored secrets; re-enter them after a salt change.
define( 'HEIC_FIX_MONITOR_ENCRYPTION_KEY', 'base64-encoded-32-bytes' );
```

Without sodium or key material the plugin refuses to store secrets in the
database and requires the constants. Secrets are never written to logs,
notification bodies, state, fingerprints or the UI.

## States

| State | Meaning | Typical action |
| --- | --- | --- |
| `ok` | FPM Imagick decoded the verified sample; `check` and ELF facts agree. | None. The first `ok` is silent. |
| `degraded` | The primary decode is healthy but a corroborating signal is unavailable, skipped or errored. | Inspect the reason codes; enable process execution or raise the memory guard if intended. |
| `affected` | The decode failed, Imagick/HEIC is missing, `check` reports affected, or linkage is not `static-hidden`. | Follow the troubleshooting runbook and repair the prefix or extension. |
| `missing` | The prefix, static archives, sample, extension or ini is absent. | Install or restore the fix, or correct the configured paths. |
| `error` | The primary probe could not run, or no trustworthy evidence was collected. | Check permissions, PHP limits, timeouts and the server log. |

Precedence is `affected` → `missing` → `error` → `degraded` → `ok`. A failed
corroborating signal degrades a proven-healthy primary decode instead of
escalating it, because the FPM decode is the exact production path.

## Notifications

One message is sent on the first non-`ok` result, on a material evidence
change, once after the re-notify window for an unchanged failure, and once on
recovery. `ok` to `ok` evidence changes rebaseline silently.

Webhook destinations must use HTTPS on the default port, must not embed
credentials, must resolve to public addresses, and must pass
`wp_safe_remote_post()`. Slack is restricted to `hooks.slack.com`. Redirects
are not followed. The generic token travels only in the `Authorization`
header.

## Scheduling

WP-Cron depends on site traffic. For a dependable schedule use system cron:

```cron
*/30 * * * * www-data cd /var/www/html && /usr/local/bin/wp heic-monitor check --notify >/dev/null 2>&1
```

The command is read-only, prints no secrets, supports `--format=json`, and
exits `0` ok, `1` affected/missing, `2` error, `3` degraded. `--notify` sends
transition notifications; without it nothing is sent.

The server-level cron, monit and Zabbix examples remain in the repository's
[monitoring guide](https://managingwp.github.io/heic-ios18-fix/monitoring/).

## Multisite

Settings, secrets, state, schedules and retries are site-scoped. Network
activation provisions every existing site, and sites created later are
provisioned automatically. There is no network-wide settings screen. Network
uninstall removes the plugin's options, transients and events from every site.

## Manual actions

- **Run check now** — one read-only check in the current FPM request; forces the
  decode even inside the minimum interval.
- **Send test notification** — one real outbound message to the selected
  configured channel.
- **Reset baseline** — deletes only the stored state and notification
  timestamps. Settings, server files and PHP configuration are untouched.

All three require `manage_options` and an action-specific nonce.

## Uninstall

Deactivation unschedules checks, digests and retries. Uninstall removes the
plugin's options, transients and scheduled events for each site. The private
prefix, PHP configuration and sample file are never modified.
