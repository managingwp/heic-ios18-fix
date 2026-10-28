# HEIC Fix Monitor — architecture contract

This document is the implementation contract for the standalone plugin. It is
shipped only in the repository, not in the release zip.

## Mission

Evaluate the server's iOS 18 HEIC/Imagick repair from the PHP-FPM context,
store a deterministic state and fingerprint, and notify once per transition.
All probes are read-only.

## Identity

| Item | Value |
| --- | --- |
| Slug / text domain | `heic-fix-monitor` |
| PHP function prefix | `hfm_` |
| Class prefix | `Heic_Fix_Monitor_` |
| Settings option | `hfm_settings` |
| Encrypted secrets option | `hfm_secrets` |
| State option | `hfm_state` |
| Run lock transient | `hfm_run_lock` |
| Last decode transient | `hfm_last_decode` |
| Retry event transient prefix | `hfm_event_` |
| Check cron hook | `hfm_run_check` |
| Digest cron hook | `hfm_daily_digest` |
| Retry cron hook | `hfm_retry_delivery` |
| Admin-post actions | `hfm_run_check_now`, `hfm_send_test`, `hfm_reset_baseline` |
| State schema key | `schema_version` (currently `1`) |

## Constants

All optional; production is expected to use them.

```php
HEIC_FIX_MONITOR_SLACK_WEBHOOK_URL
HEIC_FIX_MONITOR_GENERIC_WEBHOOK_URL
HEIC_FIX_MONITOR_GENERIC_WEBHOOK_TOKEN
HEIC_FIX_MONITOR_ENCRYPTION_KEY        // base64, 32 bytes
HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS   // array of absolute paths
HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS // array of hostnames
```

## Class map

```text
heic-fix-monitor.php                         bootstrap only
includes/class-heic-fix-monitor.php          orchestrator
includes/class-heic-fix-monitor-activator.php
includes/class-heic-fix-monitor-deactivator.php
includes/class-heic-fix-monitor-secret-store.php
includes/class-heic-fix-monitor-state-evaluator.php
includes/class-heic-fix-monitor-normalizer.php
includes/class-heic-fix-monitor-state-store.php
includes/class-heic-fix-monitor-cron.php
includes/class-heic-fix-monitor-cli-command.php
includes/probes/interface-heic-fix-monitor-probe.php
includes/probes/class-heic-fix-monitor-probe-result.php
includes/probes/class-heic-fix-monitor-probe-engine.php
includes/probes/class-heic-fix-monitor-command-runner.php
includes/probes/class-heic-fix-monitor-imagick-probe.php
includes/probes/class-heic-fix-monitor-check-probe.php
includes/probes/class-heic-fix-monitor-filesystem-probe.php
includes/notifications/interface-heic-fix-monitor-notifier.php
includes/notifications/class-heic-fix-monitor-notification-manager.php
includes/notifications/class-heic-fix-monitor-message-builder.php
includes/notifications/class-heic-fix-monitor-email-notifier.php
includes/notifications/class-heic-fix-monitor-slack-notifier.php
includes/notifications/class-heic-fix-monitor-webhook-notifier.php
includes/notifications/class-heic-fix-monitor-url-validator.php
admin/class-heic-fix-monitor-settings.php
admin/class-heic-fix-monitor-admin.php
admin/partials/heic-fix-monitor-settings.php
admin/partials/heic-fix-monitor-status.php
```

## Interfaces

```php
interface Heic_Fix_Monitor_Probe_Interface { // interface Heic_Fix_Monitor_Probe_Interface
    public function run( array $context ): array;
}

final class Heic_Fix_Monitor_Probe_Engine {
    public function run( array $settings, string $source ): array; // Heic_Fix_Monitor_Probe_Engine::run
}

final class Heic_Fix_Monitor_State_Evaluator {
    public function evaluate( array $signals ): array; // Heic_Fix_Monitor_State_Evaluator::evaluate
}

final class Heic_Fix_Monitor_State_Store {
    public function record( array $result ): array; // Heic_Fix_Monitor_State_Store::record
}

interface Heic_Fix_Monitor_Notifier_Interface { // interface Heic_Fix_Monitor_Notifier_Interface
    public function send( array $event, array $settings ): array;
}

final class Heic_Fix_Monitor_Command_Runner {
    public function run( string $binary, array $args, int $timeout ): array; // Heic_Fix_Monitor_Command_Runner::run
}
```

`Heic_Fix_Monitor_Probe_Engine::run()` returns:

```php
array(
    'state'      => 'ok|degraded|affected|missing|error',
    'codes'      => array( 'decode_ok', 'linkage_shared' ),
    'signals'    => array( 'imagick_decode' => array( ... ) ),
    'checked_at' => 1760000000,
    'source'     => 'cron|cli|manual',
    'php_versions' => array( '8.5' ),
    'schema_version' => 1,
)
```

## Signal contract

Every signal is an array:

```php
array(
    'signal'   => 'imagick_decode',
    'status'   => 'ok|degraded|affected|missing|error|unavailable|skipped',
    'code'     => 'machine_readable_code',
    'summary'  => 'Short bounded English summary.',
    'evidence' => array( 'bounded' => 'map' ),
)
```

Rules:

- `evidence` values are scalars or flat maps of scalars. No resources, objects,
  exceptions, timestamps, or unbounded strings.
- Raw stdout, raw stderr, exception traces, secrets, and recipient addresses are
  never stored.
- Unknown signal names or statuses are rejected by the engine and replaced with
  an `error` signal for that slot.

## State precedence

Aggregate in this order. The first match wins.

1. `affected` — any signal reports affected.
2. `missing` — any signal reports missing.
3. `error` — the primary `imagick_decode` signal errors, or no signal produced
   trustworthy evidence.
4. `degraded` — any signal is `degraded`, `unavailable`, `skipped`, or errored
   while the primary signal is `ok`.
5. `ok` — otherwise.

The primary signal is `imagick_decode`. `check_command` and `filesystem_elf` are
corroborating signals: their errors degrade a proven-healthy state instead of
escalating it, because the FPM decode is the exact production path.

## Fingerprint

Input document:

```json
{
  "schema_version": 1,
  "state": "affected",
  "codes": ["decode_failed", "linkage_shared"],
  "signals": {
    "check_command": {"status": "affected", "code": "linkage_shared", "evidence": {}},
    "filesystem_elf": {"status": "affected", "code": "linkage_shared", "evidence": {}},
    "imagick_decode": {"status": "affected", "code": "decode_failed", "evidence": {}}
  }
}
```

Rules:

- PHP versions, signals, codes, and evidence keys are sorted.
- Evidence values are normalized to `string|int|float|bool|null`.
- Excluded: timestamps, run IDs, hostnames, recipients, destinations, secrets,
  human prose (`summary`), and raw output.
- Fingerprint = `hash( 'sha256', wp_json_encode( $document ) )`.

## Notification events

| Event | Condition | Notification |
| --- | --- | --- |
| `baseline` | No prior state and new state is `ok` | none |
| `regression` | No prior state and new state is not `ok` | one |
| `regression` | Prior `ok`, new not `ok` | one |
| `change` | Same non-`ok` state, fingerprint changed | one |
| `reminder` | Same non-`ok` state and fingerprint, re-notify window elapsed | one |
| `recovery` | Prior not `ok`, new `ok` | one |
| `none` | Anything else, including `ok` to `ok` | none |

Timestamps stored separately: `first_seen`, `last_checked`, `last_changed`,
`last_good`, `last_notified`, `last_reminder`.

## Read-only command policy

Allowed execution is limited to:

```text
<allowlisted heic-fix.sh> check -q --php <X.Y>
/usr/bin/readelf -d <canonical file>
/usr/bin/readelf --wide --dyn-syms <canonical file>
/usr/bin/ldd <canonical file>
```

Rules:

- The binary must be an absolute, executable, readable regular file.
- `heic-fix.sh` must resolve with `realpath()` and match an entry in
  `HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS`; the default allowlist contains
  `/opt/heic-ios18-fix/heic-fix.sh`.
- PHP versions must match `^[0-9]+\.[0-9]+$`.
- Arguments are passed to `proc_open()` as an array (no shell). The audit string
  uses `escapeshellarg()` for every argument.
- Timeout is enforced with a monotonic deadline; output is capped.
- No `build`, `php`, `enable`, `rollback`, `sudo`, package manager, service
  control, `eval`, or remote include is ever invoked.

## Multisite

Settings, secrets, state, schedules, locks, and delivery are site-scoped.
Network activation provisions every existing site and `wp_initialize_site`
provisions new sites. Uninstall removes data from each provisioned site.

## Privacy

The plugin stores no visitor data and no IP addresses. It stores site
configuration, normalized HEIC evidence, notification timestamps, and processed
delivery metadata. Recipient addresses are configuration, are never rendered to
unprivileged users, and are removed on uninstall.
