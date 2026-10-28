# 20261028-wordpress-heic-monitor

**Version:** 5
**Status:** 🚧 In Progress
**Type:** feature

## Plan Contract

- **Mission:** Ship a standalone, read-only WordPress plugin that evaluates the server's iOS 18 HEIC/Imagick repair from the PHP-FPM context, records deterministic state transitions, and alerts once on regression or recovery by email, Slack, or a generic JSON webhook.
- **Execution Size:** large — execute all phases in order in one continuous request, with a blocking validation and pushed commit after each phase.
- **Scope:**
  - In scope: a publishable plugin under `wordpress-plugin/heic-fix-monitor/`; PHP 8.0+ and WordPress 6.4+; site-scoped multisite operation; in-process Imagick decode; optional `heic-fix.sh check -q --php X.Y`; read-only file/ELF/ini evidence; deterministic state/fingerprint storage; transition, reminder, recovery, digest, and heartbeat notifications; email, Slack, and generic JSON webhook channels; Settings API configuration; admin status and manual actions; WP-Cron; `wp heic-monitor check`; clean deactivation/uninstall; fixture-driven and disposable-WordPress tests; documentation, CI, and zip validation.
  - Out of scope: building, enabling, disabling, relinking, rolling back, installing, or repairing ImageMagick/Imagick/libheif; privilege escalation; writes to `/usr`, `/opt/heic-ios18-fix`, PHP configuration, or the sample; live production deployment; live webhook sends in automated tests; a network-wide settings screen; AJAX or REST endpoints; Composer runtime dependencies; duplicating the cron/monit runbook already in `docs/monitoring.md`.
- **Success Metrics:**
  - A known-good PHP 8.5 reference (`status=ok`, `linkage=static-hidden`, HEIC coder present, bundled sample decode successful) resolves to `ok`, stores a baseline, and sends no notification.
  - Fixtures for `linkage=shared`, a missing FPM Imagick extension, and a failed sample decode resolve to `affected`, retain the exact normalized evidence, and emit one regression event each.
  - A subsequent `affected` → `ok` fixture emits one recovery event; repeated identical failures remain silent until the configured re-notify window.
  - Prefix/sample absence resolves to `missing`; soft unavailable/skipped signals resolve to `degraded` when other evidence is trustworthy; timeout, permission, malformed output, or no trustworthy probe resolves to `error`.
  - Secrets are supplied by `wp-config.php` constants or stored libsodium-encrypted in `autoload=no` options; no secret is rendered back, logged, placed in state/fingerprints, or included in notification bodies.
  - Activation only initializes options and schedules read-only checks; all probes remain unprivileged and read-only; deactivation unschedules; uninstall removes plugin options, site transients, locks, and scheduled events.
  - The standalone zip contains plugin runtime files and the verified sample, but excludes tests, development configuration, build output, VCS files, and dependencies.
- **Touched paths:** `wordpress-plugin/**`, `docs/**`, `README.md`, `CHANGELOG.md`, `.github/workflows/ci.yml`, `plans/**`
- **Version Target:** release-assigned (git-squash-release)
- **Approach / Architectural Fit:** Add a modular, namespaced `Heic_Fix_Monitor_*` class set with a minimal bootstrap. Keep probe collection separate from aggregate evaluation, state persistence, notification policy, channel delivery, scheduling, CLI, and admin rendering. Store a versioned normalized result document and SHA-256 fingerprint in site options. Use WordPress APIs for options, cron, mail, HTTP, privacy text, escaping, and translation. Reuse the stable quiet-output contract and ELF facts from `heic-fix.sh`; link to the published monitoring and troubleshooting pages instead of copying their operational runbooks.
- **References:** `README.md`; `docs/how-it-works.md`; `docs/monitoring.md`; `docs/troubleshooting.md`; `docs/commands.md`; `docs/samples.md`; `heic-fix.sh` (`check_php_version`, `cmd_check`, `magickcore_linkage_state`, `cmd_verify`, `cmd_status`); `tests/run-container-test.sh`; `.github/workflows/ci.yml`; `plans/implemented/20261028-fix-gd-libheif-symbol-collision.md`; `plan-generation-skill`; `lmt-skills-development-workflow`; `wp-plugin-dev`; `settings-options-security`; `cron-background-job-security`; `http-api-ssrf-prevention`; `secrets-credentials-management`; `output-escaping`; `input-sanitization-validation`; `capability-permission-checks`; `nonces-csrf-protection`; `wp-cli-security`; `user-data-protection-privacy`; `dependency-supply-chain-security`.

### Confirmed decisions

- **Secret storage:** constants are preferred. Otherwise encrypt Slack URLs, generic webhook URLs, and generic webhook tokens with `sodium_crypto_secretbox()` using a dedicated `HEIC_FIX_MONITOR_ENCRYPTION_KEY` constant or a key derived from WordPress salts. If libsodium/key material is unavailable, refuse to persist secrets and require constants; never fall back to plaintext.
- **Multisite:** settings, current state, fingerprints, recipients, schedules, locks, and notifications are site-scoped. Network activation provisions every existing site and `wp_initialize_site` provisions new sites. There is no network-wide settings screen or cross-site recipient sharing.
- **Request surface:** settings use `options.php`; “Run check now”, “Send test”, and “Reset baseline” use separate `admin-post.php` handlers with `manage_options`, action-specific nonces, strict input allowlists, and safe redirects. No AJAX or REST route is added.
- **Probe execution:** direct PHP probes are primary. Shell-assisted probes use one shared bounded command runner based on `proc_open`, fixed executable paths, `escapeshellarg()` for every argument, no environment-derived command text, no user-controlled switches, a hard timeout, capped stdout/stderr, and process termination on timeout. Treat disabled `proc_open`/`exec` functions as an unavailable signal, never as permission to use another execution primitive.
- **Check path allowlist:** the setting must be an absolute, readable, executable regular file named `heic-fix.sh`, resolved with `realpath()`, and exactly match an entry from `HEIC_FIX_MONITOR_ALLOWED_CHECK_PATHS`. Seed `/opt/heic-ios18-fix/heic-fix.sh`; document how production adds its repository path in `wp-config.php`. PHP versions must match `^[0-9]+\.[0-9]+$` and are the only variable command arguments.
- **State precedence:** aggregate hard failures as `affected` first; then required installation/sample absence as `missing`; then untrustworthy execution as `error`; then soft unavailable/skipped evidence as `degraded`; otherwise `ok`. A missing FPM Imagick extension is `affected` because it is a working-install regression; prefix or sample absence is `missing`. An individual errored signal can yield `degraded` only when another authoritative signal proves health and the failure is explicitly non-authoritative; no trustworthy result yields `error`.
- **Notification semantics:** first `ok` establishes a silent baseline. First non-`ok` emits one regression. A normalized fingerprint change emits one material-change event, a non-`ok` → `ok` transition emits one recovery, and an unchanged non-`ok` fingerprint re-alerts only after the configured window. Optional daily digest/heartbeat is separately scheduled and does not mutate probe state.
- **Outbound policy:** HTTPS only, default ports only, no embedded URL credentials, `wp_http_validate_url()`, `wp_safe_remote_post()`, private/loopback/link-local/multicast/unspecified IP rejection after A/AAAA resolution, redirect limit 0 or 1 with revalidation, short timeout, bounded body, and bounded retries with exponential backoff. Slack is restricted to `hooks.slack.com`; generic webhook hosts must be listed in `HEIC_FIX_MONITOR_ALLOWED_WEBHOOK_HOSTS`.
- **Privacy:** collect no visitor data and no IP addresses. Site hostname, PHP version, normalized HEIC evidence, configured recipient addresses, and opt-in outbound destinations are operational configuration; add suggested privacy-policy text, minimize retention, redact secrets, and remove all data on uninstall.

### State and evidence contract

Each probe returns `signal`, `status` (`ok|degraded|affected|missing|error|unavailable|skipped`), `code`, `summary`, and a bounded `evidence` map. Never persist raw command output, exception traces, secrets, arbitrary paths outside configured roots, or timestamps inside the fingerprint.

| Signal | `ok` evidence | Hard outcome | Unavailable/soft outcome |
| --- | --- | --- | --- |
| `imagick_decode` | FPM SAPI, Imagick loaded, `HEIC/HEIF` coder present, verified sample opens and yields JPEG dimensions/bytes above thresholds | missing extension/coder or decode exception → `affected`; missing sample → `missing` | resource guard or rate limit → `skipped`; runtime/permission failure → `error` |
| `check_command` | exit 0 and all stable fields parse; `status=ok`, `heic=yes`, `linkage=static-hidden`, `decode=ok` | exit 1, `status=affected`, non-static linkage, missing coder, or decode failure → `affected`; exit 3/no Imagick → `affected` | disabled runner/path not allowlisted → `unavailable`; exit 2, timeout, or malformed contract → `error` |
| `filesystem_elf` | configured prefix/sample/extension/ini exist; `IMAGEMAGICK_LINKAGE_REV`, `LIBHEIF_VERSION`, and `LIBDE265_MODE` parse; MagickCore has no shared libheif and exports no `heif_*` | missing expected extension/ini or shared/mixed linkage → `affected`; missing prefix/static archives/sample → `missing` | `readelf`/`ldd` unavailable → retain direct file facts and mark ELF sub-check unavailable |

Normalize sorted PHP targets and signals into JSON with schema version, aggregate state, stable codes, and sorted evidence keys. Exclude run IDs, durations, timestamps, hostnames, recipients, destinations, secrets, raw stderr/stdout, and human prose. Hash `wp_json_encode()` output with SHA-256. Store timestamps separately for last run, last good, last notification, and last identical reminder.

## Skills Used

- **Creation:** `plan-generation-skill` (v5 PGF contract, validation, test ledger, and approval workflow); `lmt-skills-development-workflow` (sync, per-phase commits, push, inclusive terminology, and release ownership); `wp-plugin-dev` (modular plugin/zip architecture); `settings-options-security` (typed Settings API schema and non-autoloaded options); `cron-background-job-security` (duplicate-safe site schedules and no-user cron context); `http-api-ssrf-prevention` (safe webhook delivery); `secrets-credentials-management` (constant-first encrypted secrets); `output-escaping` and `input-sanitization-validation` (late escaping and strict input schemas); `capability-permission-checks` and `nonces-csrf-protection` (privileged admin actions); `wp-cli-security` (argument-safe, non-destructive CLI); `user-data-protection-privacy` (data minimization and policy text); `dependency-supply-chain-security` (no runtime dependencies/remote code and minimal zip).
- **Implementation:** none yet.

## Phases

### Phase 1: Freeze the plugin and probe contracts

**Goal:** Record executable interfaces, state precedence, fixtures, and packaging boundaries before runtime code is introduced.

#### Tasks

- [x] Add `wordpress-plugin/heic-fix-monitor/docs/architecture.md` with the class map, hook names, option/transient names, constants, normalized result schema, precedence table, fingerprint exclusions, notification event table, and the exact read-only command allowlists from this contract.
- [x] Define plugin identifiers in that document: slug/text domain `heic-fix-monitor`, PHP prefix `hfm_`, class prefix `Heic_Fix_Monitor_`, cron hooks `hfm_run_check` and `hfm_daily_digest`, settings option `hfm_settings`, encrypted secrets option `hfm_secrets`, state option `hfm_state`, lock transient `hfm_run_lock`, and schema key `schema_version`.
- [x] Define interfaces/signatures before implementation: `Heic_Fix_Monitor_Probe_Interface::run( array $context ): array`, `Heic_Fix_Monitor_Probe_Engine::run( array $settings, string $source ): array`, `Heic_Fix_Monitor_State_Evaluator::evaluate( array $signals ): array`, `Heic_Fix_Monitor_State_Store::record( array $result ): array`, `Heic_Fix_Monitor_Notifier_Interface::send( array $event, array $settings ): array`, and `Heic_Fix_Monitor_Command_Runner::run( string $binary, array $args, int $timeout ): array`.
- [x] Add JSON fixtures under `wordpress-plugin/heic-fix-monitor/tests/fixtures/` for `known-good`, `linkage-shared`, `missing-imagick`, `decode-failed`, `prefix-missing`, `exec-disabled`, `check-timeout`, `malformed-check`, `resource-skipped`, and `recovery`; include expected aggregate state, normalized fingerprint input, and notification event count.
- [x] Add `wordpress-plugin/heic-fix-monitor/.distignore` listing tests, development configs, local artifacts, logs, `vendor/`, and VCS metadata; require the bundled sample and runtime docs in the zip.
- [x] Record that no version file, plugin `Version:` header, or `Stable tag` is changed by feature phases; the release helper assigns the release version.
- [x] **Validation:** `python3 -m json.tool` exits 0 for every fixture; a Python assertion confirms all ten fixture names and expected states exist; `grep -F` confirms every required interface signature and option/hook name in `docs/architecture.md`.

**Commit:** `ec5e71b` — `docs: define HEIC monitor contracts`



### Phase 2: Scaffold the standalone plugin and secure settings

**Goal:** Create an activatable, translatable plugin shell with typed site settings and constant-first encrypted secret handling.

#### Tasks

- [x] Add the minimal bootstrap `wordpress-plugin/heic-fix-monitor/heic-fix-monitor.php` with GPL-2.0-or-later metadata, `Requires at least: 6.4`, `Requires PHP: 8.0`, `ABSPATH` guard, release-owned version placeholder matching `readme.txt`, constants, class includes, activation/deactivation hooks, and one `hfm_run()` entry point only.
- [x] Add `includes/class-heic-fix-monitor.php` as the orchestrator and `includes/class-heic-fix-monitor-activator.php` / `class-heic-fix-monitor-deactivator.php`; activation creates only typed `autoload=no` options and site schedules, while deactivation clears both cron hooks and the run lock without deleting history.
- [x] Add `admin/class-heic-fix-monitor-settings.php` using `register_setting()` with a field-by-field `sanitize_callback`, typed defaults, `manage_options`, `settings_fields()`, and site-scoped options for PHP versions, prefix, sample, check binary, interval, timeout, decode resource thresholds/minimum interval, channels, recipients, re-notify window, retry bounds, digest enabled/time, and heartbeat enabled.
- [x] Validate PHP versions against `X.Y`; canonicalize absolute paths without requiring absent paths to exist at save time; enforce prefix/sample/check containment and check-path allowlisting at probe time; validate emails with `sanitize_email()` plus `is_email()`; validate enums and numeric ranges against documented bounds.
- [x] Add `includes/class-heic-fix-monitor-secret-store.php`: resolve `HEIC_FIX_MONITOR_SLACK_WEBHOOK_URL`, `HEIC_FIX_MONITOR_GENERIC_WEBHOOK_URL`, and `HEIC_FIX_MONITOR_GENERIC_WEBHOOK_TOKEN` first; otherwise encrypt/decrypt `hfm_secrets` with secretbox, preserve a masked field on blank/unchanged submissions, store with `autoload=no`, and return a `WP_Error` rather than persisting plaintext when encryption is unavailable.
- [x] Add `admin/partials/heic-fix-monitor-settings.php`; escape every value at output, mask secrets without exposing length/content, mark constant-backed fields read-only, use translated labels/help text, and pair text labels/icons with every status color.
- [x] Add `readme.txt`, `LICENSE`, `languages/heic-fix-monitor.pot` generation instructions, and compatibility guards that show an admin notice without executing probes on unsupported WordPress/PHP versions.
- [x] **Validation:** PHP lint passes for bootstrap/settings/secret/activation classes; a disposable WordPress activation exits 0; `wp option get hfm_settings --format=json` has the typed default shape; `wp option list --search=hfm_% --fields=option_name,autoload --format=csv` shows `off`/`no` autoloading for settings, secrets, and state; static assertions find a sanitizer, `manage_options`, escaping, and no plaintext secret getter in the template.

**Commit:** `5f41330` — `feat: scaffold secure HEIC monitor settings`

### Phase 3: Implement the read-only probe engine

**Goal:** Collect bounded, normalized evidence from FPM Imagick, the stable `check -q` contract, and filesystem/ELF inspection without changing the host.

#### Tasks

- [x] Add `includes/probes/interface-heic-fix-monitor-probe.php`, `class-heic-fix-monitor-probe-engine.php`, and `class-heic-fix-monitor-probe-result.php`; validate every returned signal against the state/evidence schema and convert uncaught probe failures to bounded `error` evidence.
- [x] Add `includes/probes/class-heic-fix-monitor-imagick-probe.php`; run only from cron, CLI, or the nonce-gated manual action; enforce minimum interval, configured memory/time headroom, file checksum/type/size bounds, `extension_loaded( 'imagick' )`, `Imagick::queryFormats( 'HEI*' )`, one-frame decode, JPEG conversion, minimum bytes/dimensions, `clear()`/`destroy()`, and sanitized exception classification.
- [x] Copy `samples/failure_magick.HEIC` to `wordpress-plugin/heic-fix-monitor/assets/failure_magick.HEIC`, add its SHA-256 to `assets/SHA256SUMS`, and make the bundled verified copy the default; permit an absolute configured sample only after regular-file, readability, size, extension, and checksum-policy checks.
- [x] Add `includes/probes/class-heic-fix-monitor-command-runner.php` with `proc_open`, fixed binary plus escaped arguments, non-blocking reads, monotonic timeout, process termination, capped output, explicit exit/signal metadata, disabled-function detection, and no shell operators/environment inheritance beyond a minimal fixed environment.
- [x] Add `includes/probes/class-heic-fix-monitor-check-probe.php`; execute exactly `<allowlisted-heic-fix.sh> check -q --php <X.Y>` once per sorted target, parse only `phpX.Y` plus `status`, `imagick`, `imagemagick`, `heic`, `libheif`, `linkage`, and `decode`, reject duplicates/unknown/missing fields, map exits 0/1/2/3 exactly, and never retain full stdout/stderr.
- [x] Add `includes/probes/class-heic-fix-monitor-filesystem-probe.php`; use PHP filesystem APIs for prefix/static archives, `installed-versions.txt` allowlisted keys, extension, mods-available ini, and CLI/FPM `conf.d` symlinks. Never follow configured paths outside the canonical prefix and `/etc/php/<X.Y>` roots.
- [x] In the filesystem probe, optionally call fixed `/usr/bin/readelf` and `/usr/bin/ldd` through the runner to inspect the canonical MagickCore/Imagick files; require no shared libheif `NEEDED`, no exported `heif_*`, and no unresolved libraries. Mark command absence unavailable while retaining direct file facts.
- [x] Add filters only for tests (`hfm_probe_signals`, `hfm_command_result`, `hfm_now`); document that production callbacks must not be registered by the plugin and never expose arbitrary command execution.
- [x] **Validation:** fixture tests prove every probe mapping, parser rejection, timeout, output cap, allowlist rejection, no-shell command construction, sample checksum, and direct decode guard; a repository grep finds no invocation of `build`, `php`, `enable`, `rollback`, `sudo`, package managers, service controls, `eval`, remote include, or writes to configured server paths.

**Commit:** `6d4291e` — `feat: add read-only HEIC probes`

### Phase 4: Evaluate state and persist transitions

**Goal:** Convert independent evidence into deterministic aggregate state, fingerprints, and one-time transition events.

#### Tasks

- [x] Add `includes/class-heic-fix-monitor-state-evaluator.php` implementing the confirmed precedence and explicit reason codes; require both primary decode and static-hidden evidence for `ok`, while mapping unavailable secondary/tertiary execution to `degraded` rather than hiding it.
- [x] Add `includes/class-heic-fix-monitor-normalizer.php`; sort PHP targets/signals/evidence keys, coerce scalar types, cap values, remove timestamps/hostnames/paths beyond approved identifiers/raw text/secrets, encode schema-versioned JSON, and compute a SHA-256 fingerprint.
- [x] Add `includes/class-heic-fix-monitor-state-store.php`; store current normalized result, fingerprint, previous state/fingerprint, first seen, last checked, last changed, last good, last notified, and last reminder as one bounded `autoload=no` site option.
- [x] Implement event derivation: silent first `ok`; first non-`ok` regression; state/fingerprint material change; one non-`ok` → `ok` recovery; unchanged failures silent before re-notify and one reminder after; digest/heartbeat generated separately without changing fingerprint history.
- [x] Add corruption handling: invalid option shape/version is preserved only as a redacted diagnostic code, replaced atomically with a fresh `error` state, and never decoded with `unserialize()` or `maybe_unserialize()` on attacker-controlled content.
- [x] Apply the fixture matrix through the evaluator/store in order, including identical repeated failures and recovery, and assert exact event counts and stable fingerprints.
- [x] **Validation:** fixture runner exits 0 with expected states (`ok`, `affected`, `missing`, `degraded`, `error`), identical normalized inputs hash identically regardless of key order/time, repeated failures emit no duplicate before the window, and recovery emits exactly once.

**Commit:** `d5d8cb0` — `feat: track HEIC monitor transitions`

### Phase 5: Add redacted notification channels

**Goal:** Deliver bounded transition messages over opt-in channels without leaking credentials or permitting SSRF.

#### Tasks

- [x] Add `includes/notifications/interface-heic-fix-monitor-notifier.php`, `class-heic-fix-monitor-notification-manager.php`, and `class-heic-fix-monitor-message-builder.php`; build plain text/minimal HTML containing event/state, changed reason codes, per-signal bounded evidence, last-good time, `wp_parse_url( home_url(), PHP_URL_HOST )`, PHP version, and `https://managingwp.github.io/heic-ios18-fix/troubleshooting/`.
- [x] Add `class-heic-fix-monitor-email-notifier.php` using `wp_mail()` with validated site recipients, fixed translated subject prefixes, no arbitrary headers, and redaction before send.
- [x] Add `class-heic-fix-monitor-slack-notifier.php` with minimal Block Kit/plain text JSON, `hooks.slack.com` enforcement, and no webhook URL/token in payloads, results, or errors.
- [x] Add `class-heic-fix-monitor-webhook-notifier.php` using `wp_safe_remote_post()` and the confirmed outbound policy; put the optional token only in an `Authorization: Bearer` header, never in URLs/bodies/logs, and send a versioned JSON payload with no raw process output.
- [x] Add a shared URL/IP validator that lowercases/punycodes hosts, validates HTTPS/default port/no userinfo, checks the configured host allowlist, resolves A/AAAA immediately before each attempt, rejects non-public ranges, sets redirects to zero by default, and revalidates any explicitly allowed redirect.
- [x] Add bounded retry scheduling with attempt count and opaque event ID only; exponential delays must not place secrets/event payloads in cron arguments, and delivery results retain channel/status/HTTP class only.
- [x] Add WordPress privacy-policy suggestion text describing configured recipients and opt-in external channels; store no visitor data or IP addresses and define state/delivery retention and uninstall cleanup.
- [x] **Validation:** `pre_http_request`-mocked tests assert email, Slack, and generic payload shapes; public-host validation passes; HTTP/private/loopback/link-local/userinfo/unallowlisted hosts fail; retries stop at the bound; a secret-canary scan finds no secret in HTML, state, fingerprints, payload bodies, errors, or logs.

**Commit:** `3a8957e` — `feat: notify on HEIC regressions`

### Phase 6: Build the admin status and manual actions

**Goal:** Give site administrators an accessible, capability-gated status view and safe manual controls without running heavy probes on normal requests.

#### Tasks

- [x] Add `admin/class-heic-fix-monitor-admin.php` and `admin/partials/heic-fix-monitor-status.php`; render current state, text label plus icon, last checked/changed/good/notified times, per-signal reason/evidence table, schedule health, configured channel availability, and links to published monitoring/troubleshooting docs.
- [x] Register a Settings → HEIC Fix Monitor page with `manage_options`; re-check capability in every render/handler; escape text, attributes, URLs, translated strings, and intentionally allowed minimal HTML at output.
- [x] Add `admin-post.php` handler `hfm_run_check_now`; verify `hfm_run_check_now` nonce and `manage_options`, acquire the same re-entrancy lock as cron/CLI, invoke the probe engine with source `manual`, store transitions, dispatch according to normal policy, and redirect with a code-only admin notice.
- [x] Add handler `hfm_send_test`; verify a channel-specific nonce/capability and channel allowlist, build a synthetic redacted “test notification” event without changing monitor state, send only to the selected configured channel, and show a code-only result.
- [x] Add handler `hfm_reset_baseline`; mark it **DESTRUCTIVE TO PLUGIN HISTORY ONLY**, require a separate confirmation checkbox and nonce/capability, delete only `hfm_state`/notification timestamps, never server files/settings, and explain that the next `ok` is silent while the next non-`ok` alerts.
- [x] Do not enqueue public assets. If admin CSS is needed, add `admin/css/heic-fix-monitor-admin.css` and enqueue it only on this settings page; preserve readable focus, contrast, headings, table captions, and status text independent of color.
- [x] **Validation:** disposable WordPress tests return 403 for missing capability or invalid nonce, accept valid admin-post actions, never run a probe on frontend/admin page load, keep test-send/reset isolated from state incorrectly, and static escaping/i18n checks pass.

**Commit:** `4a79b98` — `feat: add HEIC monitor admin status`

### Phase 7: Wire cron, WP-CLI, multisite, and lifecycle cleanup

**Goal:** Run checks reliably per site from WP-Cron or system cron, avoid overlap, and cleanly provision/deprovision multisite data.

#### Tasks

- [x] Add `includes/class-heic-fix-monitor-cron.php`; register allowlisted intervals, guard duplicate scheduling with `wp_next_scheduled()`, implement a tokenized transient lock with stale timeout, enforce max runtime/decode interval, run without `current_user_can()`, and schedule digest in the site's timezone.
- [x] Store no secrets or payloads in cron args. Retry events contain only site ID, opaque event ID, channel key, and attempt; callbacks re-read sanitized settings/secrets and validate the stored context before delivery.
- [x] Add `includes/class-heic-fix-monitor-cli-command.php` and register `wp heic-monitor check` only when `WP_CLI`; support `--format=table|json`, `--notify`, and `--force-decode` as strict allowlists, sanitize all args, emit no secrets, return exit 0/1/2/3 for `ok|affected-or-missing|error|degraded`, and document that it is read-only and needs no WordPress user context.
- [x] Make `--notify` explicit and default off for interactive checks; use `WP_CLI::log/success/error`, never `echo`, and ensure JSON output contains normalized evidence only.
- [x] Implement site-scoped network activation by iterating existing sites in bounded batches with `switch_to_blog()`/`restore_current_blog()`, provision new sites on `wp_initialize_site`, and remove per-site hooks/options on network uninstall. Never trust a blog ID from requests.
- [x] Add `uninstall.php` with `WP_UNINSTALL_PLUGIN` guard; clear check/digest/retry events, options, and transients on each provisioned site, including network uninstall, without custom SQL or touching host files.
- [x] Document a real system cron invoking `wp heic-monitor check --notify` and link to `docs/monitoring.md` for server-level alternatives; state that WP-Cron depends on traffic.
- [x] **Validation:** disposable single-site and multisite tests prove one schedule per site, no overlap, new-site provisioning, site isolation, CLI exit/JSON contracts, deactivation unscheduling, and uninstall removal with the configured prefix/sample unchanged byte-for-byte.

**Commit:** `9553a20` — `feat: schedule site-scoped HEIC monitoring`

### Phase 8: Document, package, and integrate CI

**Goal:** Make the plugin installable and supportable as a standalone zip while preserving the repository's existing documentation boundaries.

#### Tasks

- [x] Add `wordpress-plugin/heic-fix-monitor/docs/configuration.md` covering prerequisites, site settings, constants, allowed check paths/hosts, encrypted-secret behavior, cron/system-cron setup, states, exit codes, resource guards, and uninstall behavior.
- [x] Update `docs/monitoring.md` with a short WordPress-native monitor section linking to the plugin docs; do not duplicate existing cron/monit examples. Update `docs/troubleshooting.md` with plugin state/reason-code guidance and the published troubleshooting URL used in notifications.
- [x] Update `README.md` with one concise plugin link and `CHANGELOG.md` `[Unreleased]` entries for the monitor, security boundaries, tests, and standalone package; do not edit release versions.
- [x] Add `wordpress-plugin/heic-fix-monitor/bin/build-zip.sh` that validates the distribution allowlist, copies to a temporary directory, rejects secrets/symlinks/dev files, produces `artifacts/heic-fix-monitor.zip`, and verifies archive paths are rooted under `heic-fix-monitor/`.
- [x] Add development-only `phpcs.xml.dist` for WordPress Coding Standards and a fixture/integration runner under `tests/`; no runtime `vendor/`, Composer autoloader, remote code, CDN asset, or bundled WordPress library may enter the zip.
- [x] Extend `.github/workflows/ci.yml` with a plugin job that installs PHP tooling in CI, runs lint/PHPCS when available, executes fixture tests, starts disposable WordPress single-site/multisite services, runs activation/lifecycle/CLI tests, builds the zip, and uploads it as a CI artifact. Keep existing shell/docs/container jobs unchanged.
- [x] Add a CI/static scan for `eval`, remote includes, dynamic shell command construction, plaintext secret option writes, forbidden mutating `heic-fix.sh` commands, and unescaped dynamic output; fail on findings outside explicit test fixtures.
- [x] **Validation:** docs build/link audit passes; the plugin CI commands pass; zip inspection contains expected runtime files/sample/license/readme and excludes tests/dev/VCS/vendor; repository version sources remain unchanged.

**Commit:** `757305b` — `docs: package the HEIC monitor plugin`

### Phase 9: Testing & Verification

**Goal:** Prove the complete plugin is read-only, secure, deterministic, multisite-aware, and silent on a known-good server before any production use.

#### Tasks

- [x] Run PHP lint over every plugin PHP file and PHPCS/WPCS when its development tools are available; treat lint and security-standard failures as blocking.
- [x] Run fixture tests for every aggregate state, check exit code, parser error, fingerprint transition, reminder window, digest/heartbeat, recovery, command timeout, SSRF rejection, secret redaction, and notification channel.
- [x] Activate/deactivate/uninstall in disposable WordPress single-site and multisite containers; verify schedules/options are site-scoped, frontend requests never decode, and `/opt`, `/usr`, PHP ini, and the configured sample remain unchanged.
- [x] Exercise `wp heic-monitor check` table/JSON/exit codes and a system-cron-style `--notify` run using fixture filters; do not call a live destination.
- [x] Build and inspect the standalone zip, install that zip into a second clean disposable WordPress, and repeat the known-good/affected/recovery smoke sequence.
- [x] Run the existing repository static/docs tests and confirm the original HEIC sample checksum; do not run the host-mutating container installer suite unless the user separately requests it in a disposable Docker environment.
- [ ] **USER-ONLY / PRODUCTION / OUTBOUND — DO NOT EXECUTE AUTOMATICALLY:** After all disposable tests pass, the user may install the zip on a non-client staging site matching PHP 8.5, configure constant-backed secrets/allowlists, run one manual check, and explicitly choose each “Send test” channel. This makes real outbound requests and must remain outstanding until the user reports results.
- [ ] **Outstanding user gate:** Record staging FPM decode, `check -q` evidence, one simulated regression alert, duplicate suppression, one recovery, and channel delivery. Do not claim production/client-site validation, deployment, or live notification success without user-supplied evidence.
- [ ] **Validation:** every runnable command below exits 0; fixture assertions match the acceptance criteria; mocked receivers capture no secret in bodies; destructive/system paths are unchanged; any unavailable PHPCS, Docker, multisite, or human staging check is recorded through `pgf test close` as testing debt rather than silently skipped.


**Automated verification (2026-10-28):** all 17 `How to Test` commands pass in this
checkout: PHP lint, fixture JSON, 25 probe assertions, 10 fixture state/transition
cases plus reminder/corruption/fingerprint checks, static safety scan, disposable
single-site WordPress (settings, secrets, notifications, admin actions, CLI,
lifecycle, uninstall), disposable multisite provisioning/isolation/uninstall,
minimal zip assertion, clean-site zip install, sample checksum, and the Docusaurus
build. PHPCS/WPCS was not installed locally; the CI job installs it and runs the
same ruleset. The staging/live-channel gate remains outstanding for the user.

#### How to Test

Prerequisites: Bash 4+, PHP 8.0+ with sodium and zip, Python 3, Docker with Compose, and optional PHPCS/WPCS. Commands are read-only outside disposable containers except for writing the local zip under `wordpress-plugin/heic-fix-monitor/artifacts/`. No live webhook URL or production server is used.

```bash
find wordpress-plugin/heic-fix-monitor -type f -name '*.php' -print0 | xargs -0 -n1 php -l
if command -v phpcs >/dev/null 2>&1 && phpcs -i 2>/dev/null | grep -q WordPress; then phpcs --standard=wordpress-plugin/heic-fix-monitor/phpcs.xml.dist wordpress-plugin/heic-fix-monitor; fi
python3 -c 'import glob,json; [json.load(open(p)) for p in glob.glob("wordpress-plugin/heic-fix-monitor/tests/fixtures/*.json")]'
php -d memory_limit=512M wordpress-plugin/heic-fix-monitor/tests/run-probe-tests.php
sh wordpress-plugin/heic-fix-monitor/tests/run-fixture-tests.sh
bash wordpress-plugin/heic-fix-monitor/bin/static-scan.sh
docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml up -d --wait db wordpress db-ms wordpress-ms
docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml run --rm --entrypoint sh cli /var/www/html/wp-content/plugins/heic-fix-monitor/tests/run-wp-tests.sh
docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml run --rm --entrypoint sh cli-ms /var/www/html/wp-content/plugins/heic-fix-monitor/tests/run-multisite-tests.sh
bash wordpress-plugin/heic-fix-monitor/bin/build-zip.sh
python3 wordpress-plugin/heic-fix-monitor/tests/assert-zip-contents.py wordpress-plugin/heic-fix-monitor/artifacts/heic-fix-monitor.zip
docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml up -d --wait db-zip wordpress-zip
docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml run --rm --entrypoint sh cli-zip /hfm-tests/run-zip-install-tests.sh
sha256sum -c samples/SHA256SUMS
git diff --check
npm run build
docker compose -f wordpress-plugin/heic-fix-monitor/tests/docker-compose.yml down -v
```

Expected: lint/optional PHPCS and every fixture, probe, integration and static scan exits 0; single-site and multisite disposable tests prove state isolation and clean lifecycle; mocked email/HTTP deliveries show one regression, no duplicate before the window, one recovery and no secret leakage; WP-CLI emits valid bounded JSON; the zip is valid/minimal, installs in a clean disposable WordPress, and uninstalls cleanly; the repository sample checksum and Docusaurus build pass; Compose resources are removed. The staging/live-channel gate remains outstanding for the user.

#### Manual / UI Checks

1. On a disposable or non-client staging site, open **Settings → HEIC Fix Monitor** and confirm every status has text plus an icon, keyboard focus is visible, secrets remain masked, and constant-backed fields cannot be edited.
2. Run **Check now** and confirm the page reports the FPM-context result without a frontend request triggering a decode.
3. Only after supplying non-production destinations, choose one **Send test** channel at a time and confirm the destination receives a redacted test message; this is an explicit outbound action.
4. Simulate a regression with fixture/test filters on staging, confirm one alert and duplicate suppression, restore known-good evidence, and confirm one recovery.

**Commit:** `test: verify the HEIC monitor plugin`
