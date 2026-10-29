---
title: Monitoring
description: Cron, monit and alerting examples for heic-fix.sh check.
---

[← Docs home](index.md) · [Commands](commands.md)

# Monitoring

`check` is read-only and safe to run as often as you like. It never installs,
builds or enables anything.

## Exit codes

| Code | Meaning | Typical action |
| --- | --- | --- |
| 0 | OK | none |
| 1 | AFFECTED | run `build`, then `php --php X.Y`, or re-enable after an update |
| 2 | ERROR | investigate a broken probe, missing binary or extension |
| 3 | NO IMAGICK | this server has no Imagick for the checked PHP versions |

The `check` command exits non-zero for AFFECTED on purpose, so Nagios, monit and
cron see it as a failure.

Scope `--php` to the versions your sites use. Without it, `check` inspects
**every** installed PHP version, and a version that was never built will keep
the alert firing.

## Quiet output

`check -q` prints one line per PHP version:

```text
php8.3 status=ok imagick=3.8.1 imagemagick=7 heic=yes libheif=1.23.7 linkage=static-hidden decode=ok
php8.3 status=affected imagick=3.8.1 imagemagick=6 heic=yes libheif=1.17.6 linkage=n/a decode=fail
```

Fields are stable: `status`, `imagick`, `imagemagick` (major), `heic`, `libheif`,
`linkage`, `decode`.

## Cron: silent when OK

```cron
# /etc/cron.d/heic-fix
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

*/30 * * * * root out=$(/opt/heic-ios18-fix/heic-fix.sh check -q --php 8.3 2>&1); rc=$?; if [ "$rc" -ne 0 ]; then printf '%s\n' "$out" | mail -s "HEIC check on $(hostname): rc=$rc" ops@example.com; fi
```

This is completely silent when `check` exits 0. If it exits 1 (AFFECTED), 2
(ERROR) or 3 (NO IMAGICK), the captured output is mailed.

A wrapper can separate the alert text:

```bash
#!/usr/bin/env bash
# /usr/local/bin/heic-check-alert
set -Eeuo pipefail

out=$(/opt/heic-ios18-fix/heic-fix.sh check -q --php 8.3 2>&1) || rc=$?
rc=${rc:-0}

if (( rc == 0 )); then
    exit 0
fi

case $rc in
    1) subject="HEIC AFFECTED on $(hostname)" ;;
    2) subject="HEIC check ERROR on $(hostname)" ;;
    3) subject="Imagick missing on $(hostname)" ;;
    *) subject="HEIC check failed ($rc) on $(hostname)" ;;
esac

printf '%s\n' "$out" | mail -s "$subject" ops@example.com
exit "$rc"
```

Test it manually:

```bash
/usr/local/bin/heic-check-alert; echo "exit=$?"
```

## Monit

```monit
check program heic-fix with path "/opt/heic-ios18-fix/heic-fix.sh check -q --php 8.3"
    every 6 cycles
    if status != 0 then alert
```

Monit treats any non-zero exit as a failure. Add separate checks if you want
different alert text for AFFECTED and ERROR:

```monit
check program heic-fix with path "/opt/heic-ios18-fix/heic-fix.sh check -q --php 8.3"
    every 6 cycles
    if status = 1 then alert
    if status = 2 then alert
    if status = 3 then alert
```

## Zabbix / Prometheus textfile

Extract a single value:

```bash
/opt/heic-ios18-fix/heic-fix.sh check -q --php 8.3 \
  | sed -n 's/.*status=\([a-z-]*\).*/\1/p'
```

Map it to a number in your monitoring system (`ok=0`, `affected=1`,
`error=2`, `no-imagick=3`), or use the process exit code directly.

## After an update

Monitoring catches the common failure mode: a GridPane or package update
restores stock imagick or upgrades the system libraries. When `check` flips to
AFFECTED:

```bash
sudo /opt/heic-ios18-fix/heic-fix.sh enable --php 8.3
sudo /opt/heic-ios18-fix/heic-fix.sh verify --php 8.3
```

If `verify` fails, rebuild:

```bash
sudo /opt/heic-ios18-fix/heic-fix.sh build
sudo /opt/heic-ios18-fix/heic-fix.sh php --php 8.3
```

On Ubuntu 26.04, do not rebuild. Install `libheif-plugin-libde265` instead; see
[Ubuntu 26.04](platforms/ubuntu-26-04.md).

## WordPress-native monitor

If the site runs WordPress, the standalone
[HEIC Fix Monitor plugin](https://github.com/managingwp/heic-ios18-fix/tree/main/wordpress-plugin/heic-fix-monitor)
performs the same read-only checks from the PHP-FPM request context, stores a
fingerprint, and sends one alert per regression or recovery by email, Slack or
JSON webhook. It also provides `wp heic-monitor check --notify` for system
cron. The cron and monit examples above remain the right choice for servers
without WordPress.

Download the installable ZIP from the
[plugin download page](https://managingwp.github.io/heic-ios18-fix/plugin-download/).
Versioning and the maintainer workflow are documented in
[Plugin releases](plugin-releases.md).
