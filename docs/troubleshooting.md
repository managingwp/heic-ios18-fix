---
title: Troubleshooting
description: PCRE2 package conflicts and common verification failures.
---

[← Docs home](index.md) · [Getting started](getting-started.md) · [Commands](commands.md)

# Troubleshooting

## `phpX.Y-dev` fails on Sury/GridPane: libpcre2-dev 10.39 vs 10.42

### Symptom

```text
The following packages have unmet dependencies:
 libpcre2-dev : Depends: libpcre2-8-0 (= 10.39-3ubuntu0.1)
                but 10.42-3+ubuntu22.04.1+deb.sury.org+1 is to be installed
E: Unable to correct problems, you have held broken packages.
```

This blocks `php8.3-dev` and therefore the Imagick build. The libraries from
`build` are fine; only the PHP development package is missing.

### Cause

Sury packages PHP 8.3/8.4/8.5 against **PCRE2 10.42**. The server has the Sury
`libpcre2-8-0` 10.42 runtime installed, but Ubuntu 22.04's `libpcre2-dev` is
built for **10.39** and pins the exact runtime version. Installing the Ubuntu
dev package would downgrade the runtime that PHP expects.

The current `packages.sury.org/php` mirror on the server does not carry
`libpcre2-dev`, but Ondrej's Launchpad PPA does, at the matching 10.42 version.

### Fix: install the matching Sury PCRE2 development set

Do **not** downgrade `libpcre2-8-0` to 10.39 and do not use
`dpkg --force-depends` on `phpX.Y-dev`.

```bash
mkdir -p /tmp/pcre2-sury
cd /tmp/pcre2-sury

base='https://ppa.launchpadcontent.net/ondrej/php/ubuntu/pool/main/p/pcre2'
ver='10.42-3+ubuntu22.04.1+deb.sury.org+1'

for p in libpcre2-dev libpcre2-8-0 libpcre2-16-0 libpcre2-32-0 libpcre2-posix3; do
    curl -fLO "$base/${p}_${ver}_amd64.deb"
done

dpkg -i libpcre2-dev_${ver}_amd64.deb \
        libpcre2-8-0_${ver}_amd64.deb \
        libpcre2-16-0_${ver}_amd64.deb \
        libpcre2-32-0_${ver}_amd64.deb \
        libpcre2-posix3_${ver}_amd64.deb
```

If `dpkg` reports a dependency problem, run:

```bash
apt-get install -f
```

Confirm the set is consistent:

```bash
dpkg-query -W -f='${Package} ${Version}\n' \
    libpcre2-dev libpcre2-8-0 libpcre2-16-0 libpcre2-32-0 libpcre2-posix3
```

All five should report `10.42-3+ubuntu22.04.1+deb.sury.org+1`.

### Then install the PHP development package

```bash
apt-get install -y php8.3-dev
phpize8.3 --version
```

After that, rerun only the PHP step — **no need to rebuild the libraries**:

```bash
cd ~/heic-ios18-fix
./heic-fix.sh php --php 8.3
./heic-fix.sh php --php 8.1 --php 8.2   # other versions used by sites
```

The same PCRE2 fix covers every PHP version; it is a one-time system change.

### Why not downgrade PCRE2?

PHP 8.3/8.4/8.5 from Sury are built for PCRE2 10.42. Downgrading to Ubuntu's
10.39 can produce PHP startup warnings such as:

```text
PHP Warning: PHP Startup: ^(text/|application/xhtml\+xml) (offset=0):
unrecognised compile-time option bit(s)
```

### Package checksums

From the Ondrej PPA `Packages` index (SHA256):

```text
4ead2ea114ee540883b93848dd2dda08992e3b820f3eb0d7604b47430911e51d  libpcre2-dev
5e51ced86ae7bd77cc6df2311d60b9d091f28564c0d89da988794b0c82119d3a  libpcre2-8-0
396787ac8f837a192d40be64c969183d8192fe77368cb08ec6426217ef7d7241  libpcre2-16-0
3eda8ac2aa3763c8cec1a042664da6682f3047dc888c02647c8e93b755575d71  libpcre2-32-0
4bb8ed549cc46d433e19cd8d445fe294177ec3b1785c2acfb7f498d61ec09b7c  libpcre2-posix3
```

Verify with:

```bash
sha256sum /tmp/pcre2-sury/*.deb
```

## `Permission denied` loading `imagick.so`

### Symptom

PHP fails at startup, before any site code runs:

```text
PHP Warning:  PHP Startup: Unable to load dynamic library
'/opt/heic-ios18-fix/php/8.2/imagick.so' (tried:
/opt/heic-ios18-fix/php/8.2/imagick.so (... Permission denied), ...)
in Unknown on line 0
```

`php -m` has no `imagick`, `wp` prints the same warning, and the second path in
the message (`.../imagick.so.so`) is only PHP falling back after the first
failure.

### Cause

The account running PHP cannot traverse or read the prefix. The usual reason on
GridPane is an ACL entry on `/opt`:

```text
# file: /opt
user::rwx
group::r-x
group:GridPane-System-Users:---
mask::r-x
other::r-x
```

Site users are members of `GridPane-System-Users`, and a named ACL entry takes
precedence over `other::r-x`, so `/opt/heic-ios18-fix` is unreachable even though
its mode is `0755`. `ls -l` and `namei -l` show the mode, not the ACL.

### Confirm it

Run this as the site user, not as root:

```bash
id
namei -l /opt/heic-ios18-fix/php/8.2/imagick.so
test -r /opt/heic-ios18-fix/php/8.2/imagick.so \
    && echo "extension readable" || echo "extension NOT readable"
php8.2 -r 'echo extension_loaded("imagick") ? "loaded\n" : "not loaded\n";'
```

`namei` stops at the first component the user cannot enter. To see why, as root:

```bash
getfacl -p /opt
namei -l /usr/local/lib/heic-ios18-fix/php/8.2/imagick.so
```

### Fix

The supported fix is to install under the default prefix, which every site user
can read:

```bash
cd ~/heic-ios18-fix
sudo ./heic-fix.sh build          # compiles into /usr/local/lib/heic-ios18-fix
sudo ./heic-fix.sh php --php 8.2  # decline the enable prompt for now
```

Then follow [Migrate an existing
installation](#migrate-an-existing-optheic-ios18-fix-installation). The old build
stays where it is; nothing is copied or deleted.

A single-account exception is possible but broader than it looks:

```bash
sudo setfacl -m u:<site-user>:x /opt
```

That grants traverse rights on the whole of `/opt`, not just this installation,
so the hosting administrator has to own that decision. Restrict it with a named
user, never by removing the group entry or granting `GridPane-System-Users`
access to `/opt`.

Additional directories and files must be reachable too. Add execute (x) for each
directory the user cannot enter and read (r) for the files it must read, then
prove it as the site user rather than as root.

## Migrate an existing `/opt/heic-ios18-fix` installation

The extension and `libMagickCore` embed absolute paths, so an installation
cannot be moved by copying files or repointing a symlink. Rebuild at the new
prefix instead.

### 1. Record the current state

```bash
sudo /path/to/heic-fix.sh status --prefix /opt/heic-ios18-fix
sudo cp /opt/heic-ios18-fix/installed-versions.txt \
    /root/heic-ios18-fix-versions-$(date +%F).txt
sudo cp /etc/php/8.3/mods-available/imagick-heic.ini \
    /root/imagick-heic-8.3-$(date +%F).ini
```

Repeat the ini copy for every PHP version you have enabled. `status` lists the
built extensions; `grep -l heic-ios18-fix /etc/php/*/mods-available/*.ini` finds
the ini files. The legacy build keeps working during the migration.

### 2. Size the rebuild

```bash
nproc
free -g
docker ps -q | wc -l
```

A clean build needs roughly 1–2 GB of disk. Cap the job count so the compile
cannot take the whole host:

```bash
sudo ./heic-fix.sh build --jobs 2
```

### 3. Build the extension without enabling it

```bash
sudo ./heic-fix.sh php --php 8.3
```

Answer **no** when it offers to enable the extension. Do not pass `-y`: that
answers yes to the module switch and the FPM reload.

### 4. Test as a real site user

Choose an account that owns a site on this PHP version, not root:

```bash
id
namei -l /usr/local/lib/heic-ios18-fix/php/8.3/imagick.so
php8.3 -n -d extension=/usr/local/lib/heic-ios18-fix/php/8.3/imagick.so \
    -r 'echo Imagick::getVersion()["versionString"], "\n";'
```

Repeat with GD loaded first, which is the production order:

```bash
gd=$(php8.3 -r 'echo ini_get("extension_dir");')/gd.so
php8.3 -n -d extension="$gd" \
    -d extension=/usr/local/lib/heic-ios18-fix/php/8.3/imagick.so \
    -r '$i = new Imagick($argv[1]); $i->setImageFormat("jpeg");
        printf("bytes=%d\n", strlen($i->getImageBlob()));' \
    /path/to/photo.HEIC
```

If either command fails with `Permission denied`, stop: fix access on the new
prefix before switching anything.

### 5. Switch one PHP version at a time

```bash
sudo ./heic-fix.sh enable --php 8.3
sudo ./heic-fix.sh verify --php 8.3
sudo -u <site-user> php8.3 -r 'echo Imagick::getVersion()["versionString"], "\n";'
```

`enable` writes `/etc/php/8.3/mods-available/imagick-heic.ini`, disables stock
`imagick` and reloads `php8.3-fpm`. Every site on that PHP version is affected,
so confirm one version before moving to the next. Check a real upload after the
reload.

### 6. Keep the old prefix until you are sure

`/opt/heic-ios18-fix` is not touched by any of the steps above. Keep it for at
least one release cycle in case you need to compare behaviour, and remember that
its modules are not readable by site users, so re-enabling it means going back
to stock Imagick:

```bash
sudo ./heic-fix.sh rollback --php 8.3
```

`rollback` re-enables stock `imagick` and reloads FPM; it does not restore the
legacy prefix and it does not delete anything.

Removing `/opt/heic-ios18-fix` is a separate, deliberate action. Before you do,
prove nothing still points at it:

```bash
grep -R /opt/heic-ios18-fix /etc/php/*/mods-available /etc/php/*/conf.d \
    /etc/cron.d /etc/monit /etc/monit.d 2>/dev/null
```

## `verify` fails with stock ImageMagick 6

Run the PHP step first:

```bash
./heic-fix.sh php --php 8.3
```

`build` only compiles libde265, libheif and ImageMagick 7. It does not create
`/usr/local/lib/heic-ios18-fix/php/8.3/imagick.so`; `php` does that and enables it.

## Decoding fails only when GD is loaded

### Symptom

The private tools decode the photo, but PHP fails once `gd.so` is loaded:

```text
ImagickException: Invalid input: Unspecified:
Too many auxiliary image references (2.0)
  @ error/heic.c/IsHEIFSuccess/207
```

`php -n -d extension=imagick.so` decodes; `php -n -d extension=gd.so -d
extension=imagick.so` fails. `check` reports `linkage=shared` or `Linkage:
mixed` for the prefix extension.

### Cause

The prefix was built with private SONAMEs only. GD loads the system
`libheif.so.1` and exports its `heif_*` functions globally; a SONAME isolates
the loaded object but not the function names, so ImageMagick's calls bind to
the system libheif. Known to happen on Ubuntu 22.04 with PHP 8.5.

### Confirm it

```bash
cd ~/heic-ios18-fix

# 1. Private CLI — proves the build itself is fine
/usr/local/lib/heic-ios18-fix/imagemagick/bin/magick samples/failure_magick.HEIC[0] null:

# 2. Imagick alone and GD first — the split identifies symbol capture
for mode in imagick-only gd-first; do
    printf '\n=== %s ===\n' "$mode"
    args=(-n)
    [[ $mode == gd-first ]] && args+=(-d extension=gd.so)
    php8.5 "${args[@]}" -d extension=/usr/local/lib/heic-ios18-fix/php/8.5/imagick.so \
        -r '$im = new Imagick($argv[1]); $im->setImageFormat("jpeg");
            printf("bytes=%d\n", strlen($im->getImageBlob()));' \
        samples/failure_magick.HEIC
done

# 3. Show which library each call binds to
LD_DEBUG=bindings php8.5 -n -d extension=gd.so \
    -d extension=/usr/local/lib/heic-ios18-fix/php/8.5/imagick.so \
    -r '$im = new Imagick($argv[1]); $im->setImageFormat("jpeg"); $im->getImageBlob();' \
    samples/failure_magick.HEIC 2>&1 |
    grep -E 'binding file .*libMagickCore.* to .*libheif'
```

Any `libMagickCore → /lib/.../libheif.so.1` line confirms the problem.

### Fix

Rebuild the prefix; this is a link-layout change, not a library upgrade:

```bash
cd ~/heic-ios18-fix
sudo ./heic-fix.sh build
sudo ./heic-fix.sh php --php 8.5
sudo ./heic-fix.sh verify --php 8.5
```

`build` links the private static archives into `libMagickCore` with hidden
symbols. `check`, `verify` and the pre-flight now require
`libheif link : static-hidden` and refuse to enable anything else.

### Do not

- disable `gd.so` or change the global extension order — other code depends on
  GD, and the order is not yours to guarantee;
- add the prefix to `LD_PRELOAD` or `/etc/ld.so.conf.d` in production — it
  changes every process on the server;
- run `enable` after a failed pre-flight. The refusal is the safety gate.

## WordPress monitor states

The [HEIC Fix Monitor plugin](https://github.com/managingwp/heic-ios18-fix/tree/main/wordpress-plugin/heic-fix-monitor)
reports one aggregate state per check:

| State | Meaning |
| --- | --- |
| `ok` | FPM Imagick decoded the verified sample and `check`/ELF facts agree. |
| `degraded` | The primary decode is healthy, but a corroborating check was unavailable, skipped or errored. |
| `affected` | Decode failed, Imagick/HEIC is missing, `check` reports affected, or linkage is not `static-hidden`. |
| `missing` | The prefix, archives, sample, extension or ini is absent. |
| `error` | The primary probe could not run or no trustworthy evidence was collected. |

The status page names the exact reason code. A `degraded` result usually means
`proc_open` is disabled, `check` timed out, or the decode was skipped by the
memory guard; it does not mean the repair was silently changed.
