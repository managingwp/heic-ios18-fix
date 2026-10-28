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

## `verify` fails with stock ImageMagick 6

Run the PHP step first:

```bash
./heic-fix.sh php --php 8.3
```

`build` only compiles libde265, libheif and ImageMagick 7. It does not create
`/opt/heic-ios18-fix/php/8.3/imagick.so`; `php` does that and enables it.
