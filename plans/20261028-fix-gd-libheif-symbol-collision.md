# 20261028-fix-gd-libheif-symbol-collision

**Version:** 5
**Status:** Not Started
**Type:** fix

## Plan Contract

- **Mission:** Prevent PHP GD's system libheif from capturing ImageMagick's `heif_*` calls by statically linking private libheif/libde265 into MagickCore with hidden, locally bound symbols, while preserving pre-flight refusal and rollback behavior.
- **Execution Size:** large — execute phases in order and commit after each phase; stop before the production-server gate for the user.
- **Scope:**
  - In scope: exact GD-first regression with `samples/failure_magick.HEIC`; Ubuntu 22.04/PHP 8.5 CI; PIC static private libraries for MagickCore; hidden/local ELF binding; stale-build invalidation; linkage/runtime verification; documentation.
  - Out of scope: disabling GD; globally changing extension order; replacing distribution libraries; production `LD_PRELOAD`; bypassing pre-flight; changing the sample; deploying or restarting production services automatically.
- **Success Metrics:**
  - Private ImageMagick CLI, Imagick-only PHP, and GD-first PHP decode `samples/failure_magick.HEIC` into a non-empty JPEG.
  - Filtered `LD_DEBUG=bindings` shows no MagickCore `heif_*` binding to `/lib` or `/usr/lib` system libheif when GD loads first.
  - MagickCore has no `DT_NEEDED` entry for shared libheif/libde265, and its embedded `heif_*` definitions are local/hidden, not exported in `.dynsym`.
  - GD still resolves its own `heif_*` calls to distribution `libheif.so.1`; both extensions coexist in one PHP process.
  - Existing `check`, `build`, `php`, `verify`, `rollback`, `enable`, synthetic-sample, CLI, PHP 8.3 and Ubuntu 24.04 paths stay green.
  - CI covers Ubuntu 22.04/PHP 8.3, Ubuntu 22.04/PHP 8.5, and Ubuntu 24.04/PHP 8.3.
- **Touched paths:** `heic-fix.sh`, `tests/run-container-test.sh`, `.github/workflows/ci.yml`, `README.md`, `docs/how-it-works.md`, `docs/troubleshooting.md`, `docs/testing.md`, `CHANGELOG.md`, `plans/**`
- **Version Target:** release-assigned (git-squash-release)
- **Approach / Architectural Fit:** Keep the shared private SONAME build for `heif-dec`, diagnostics, and prefix compatibility. Add a separate PIC static build root for ImageMagick. Link those archives into MagickCore with static pkg-config metadata, `-Wl,-Bsymbolic-functions`, and `-Wl,--exclude-libs,ALL`; GD remains on its system shared library. Do not rely on PHP `RTLD_DEEPBIND` because the affected PHP 8.5 trace proves it insufficient.
- **Primary evidence:** On Ubuntu 22.04, private ImageMagick and Imagick-only decode successfully, while GD-first fails with `Too many auxiliary image references`. `LD_DEBUG=bindings` shows MagickCore binding `heif_context_alloc`, `heif_context_read_from_file`, and `heif_context_free` to system libheif 1.12.0 while `heif_context_get_security_limits` binds to private libheif 1.23.7.
- **References:** `heic-fix.sh` functions `build_libde265`, `build_libheif`, `build_imagemagick`, `verify_imagemagick_linkage`, `verify_imagick_linkage`, `preflight_imagick`; `tests/run-container-test.sh`; `.github/workflows/ci.yml`; `docs/how-it-works.md`; `docs/troubleshooting.md`.

### Confirmed decisions

- Require static hidden linkage; symbol-prefixing is not the primary repair.
- Add PHP 8.5 to Ubuntu 22.04 CI and retain the existing jobs.
- Make the affected production server a manual final gate. The build agent supplies commands and stops; the user performs the rebuild and enablement.

## Skills Used

- **Creation:** `plan-generation-skill`.
- **Implementation:** `plan-generation-skill` and `lmt-skills-development-workflow`.
- **Diagnosis reference:** `diagnosing-bugs` to preserve the exact GD-first red/green loop and prove corrected binding.

## Phases

### Phase 1: Lock in the GD-first regression

**Goal:** Convert the affected server's failure into an unattended test that turns red when GD captures MagickCore's libheif calls.

#### Tasks

- [x] Refactor `tests/run-container-test.sh` so `assert_decode_with_gd` accepts a sample path, prints the sample/load mode, and requires a JPEG blob larger than 100 bytes.
- [x] Add `assert_decode_modes` to run `samples/failure_magick.HEIC` through private ImageMagick CLI, Imagick with `phpX.Y -n`, and `gd.so` loaded before private `imagick.so`. Use the repository sample even when `SAMPLE` names the generated fixture.
- [x] Before the collision probe, run `sha256sum -c samples/SHA256SUMS`; fail instead of falling back if the repository sample is missing or altered.
- [x] Add `assert_hidden_heif_binding`. Use `readelf -d`, `readelf -Ws`, and a filtered `LD_DEBUG=bindings` GD-first decode to require: no MagickCore shared-libheif dependency, local/hidden embedded `heif_*`, no MagickCore-to-system binding, and GD-to-system binding where GD supports HEIF.
- [x] Run the new decode-mode probe against the current code in disposable Ubuntu 22.04/PHP 8.5 and capture the expected red result: CLI and Imagick-only pass, GD-first fails with `Too many auxiliary image references`. Do not weaken the assertion.
- [x] Add `{ubuntu: "22.04", php: "8.5"}` to `.github/workflows/ci.yml`; keep the current two jobs, `fail-fast: false`, and the 90-minute timeout.
- [x] Update the test header and execution order to state that generated HEIC behavior and the checked-in iOS 18 collision sample are both mandatory.
- [x] **Validation:** The disposable 22.04/8.5 run exits non-zero specifically at the GD-first repository-sample assertion after both isolated probes pass.

**Commit:** `test: reproduce GD libheif symbol capture`

**Red evidence (pre-fix, Ubuntu 22.04 / PHP 8.5, disposable container):**

```text
gd=no  decode=ok 3024x4032 4289303 bytes
gd=yes Fatal error: Uncaught ImagickException: Invalid input: Unspecified:
       Too many auxiliary image references (2.0) ... error/heic.c/IsHEIFSuccess/207
TEST FAILURE: GD-first decode failed: /repo/samples/failure_magick.HEIC
       (a system libheif captured ImageMagick's heif_* calls)
```

### Phase 2: Statically isolate libheif inside MagickCore

**Goal:** Build MagickCore with private PIC libheif/libde265 code bound locally so GD cannot interpose system `heif_*` functions.

#### Tasks

- [x] In `heic-fix.sh`, add one static install root beneath `$PREFIX` (for example `$PREFIX/heif-static`) and build-revision constants for static libde265, static libheif, and ImageMagick linkage. Keep `$HEIF` shared artifacts for tools and diagnostics.
- [x] Preserve `build_libde265`'s shared build, then add a default-mode static build from the same source with `BUILD_SHARED_LIBS=OFF`, `CMAKE_POSITION_INDEPENDENT_CODE=ON`, `ENABLE_SDL=OFF`, and `ENABLE_ENCODER=OFF`, installed only under the static root.
- [x] When `--system-libde265` is selected, do not create a private libde265 archive; record that static libheif uses the supported system decoder dependency and validate that path separately.
- [x] Add a static libheif build from the resolved source/version with `BUILD_SHARED_LIBS=OFF`, `CMAKE_POSITION_INDEPENDENT_CODE=ON`, the existing codec choices, and `ENABLE_PLUGIN_LOADING=OFF`. Put static libde265's pkg-config directory first.
- [x] Make static-build skip logic require matching build revisions, resolved versions, libde265 mode, and expected `.a` files. On mismatch remove only the corresponding static build/install subtree and rebuild it.
- [x] Change `build_imagemagick` to consume static libheif via static pkg-config metadata and add `-Wl,-Bsymbolic-functions -Wl,--exclude-libs,ALL` to MagickCore linking while retaining ImageMagick's own prefix rpath.
- [x] Add an ImageMagick linkage revision to `build_imagemagick` skip/record logic. Existing hosts with the same ImageMagick version but no matching revision must rebuild once rather than print “already built; skipping.”
- [x] Rewrite `verify_imagemagick_linkage` for the new contract: reject MagickCore `DT_NEEDED` entries for shared libheif/libde265 in default mode; reject exported dynamic `heif_*`; require local `heif_context_alloc`, `heif_context_read_from_file`, and `heif_context_free`; retain unresolved-dependency, ImageMagick 7, and HEIC-format checks.
- [x] Update `verify_imagick_linkage`: require private MagickCore/MagickWand paths, stop requiring private libheif/libde265 in transitive `ldd`, and fail if default-mode `imagick.so` reaches either shared libheif through MagickCore.
- [x] Make `cmd_build` invoke static builds before ImageMagick, print the mode, and record enough revision/linkage metadata in `installed-versions.txt` to identify old versus static-hidden installs.
- [x] Keep `preflight_imagick`'s GD-loaded sample decode and refusal behavior unchanged; any failed real-sample decode must still prevent enablement.
- [x] **Validation:** MagickCore has no shared libheif/libde265 `NEEDED` entry in default mode, required `heif_*` definitions are local/hidden, and CLI, Imagick-only, and GD-first all decode the repository sample.

**Commit:** `fix: isolate libheif symbols inside MagickCore`

**Green evidence (Ubuntu 22.04 / PHP 8.5, disposable container):**

```text
Pre-flight for PHP 8.5
  GD loaded    : yes
  ImageMagick  : ImageMagick 7.1.2-34 Q16-HDRI
  HEIC formats : HEIC,HEIF
  libheif link : static-hidden
  sample       : decode=ok

gd=no  decode=ok 3024x4032 4289303 bytes
gd=yes decode=ok 3024x4032 4289303 bytes
All decode modes succeeded for /repo/samples/failure_magick.HEIC
Static-hidden libheif: no MagickCore-to-system heif_* binding with GD loaded first

  Library isolation
    libheif                  PASS (linked into MagickCore)
    libde265                 PASS (linked into MagickCore)
    imagick.so               PASS no shared libheif
```

`check -q` reports `status=ok ... libheif=1.23.7 linkage=static-hidden`.
`verify` also passes with the system `libheif.so.1` in `LD_PRELOAD`.

### Phase 3: Harden diagnostics and documentation

**Goal:** Expose the corrected linkage and replace the inaccurate claim that private SONAMEs plus deep binding are sufficient.

#### Tasks

- [x] Add concise linkage output to `status` or `verify`: report `libheif linkage=static-hidden` only after ELF checks pass; report an actionable non-zero result for shared or mixed binding. Do not emit full `LD_DEBUG` output normally.
- [x] Distinguish missing HEIC support, legacy shared-private linkage, and confirmed GD/system symbol capture in errors; preserve “not enabling” semantics.
- [x] Update `docs/how-it-works.md` to explain that SONAMEs isolate objects but not globally visible `heif_*` lookup, include the confirmed mixed 1.12.0/1.23.7 example, and describe PIC static linkage plus hidden/local symbols.
- [x] Update `docs/troubleshooting.md` with exact CLI, Imagick-only, GD-first, and filtered binding probes. Explicitly reject disabling GD, global extension reordering, production `LD_PRELOAD`, and bypassing pre-flight.
- [x] Update `docs/testing.md` for the repository collision sample, PHP 8.5 CI job, and ELF/runtime assertions; state why a generated HEIC alone cannot prove the iOS 18 auxiliary-image fix.
- [x] Update `README.md` only if its concise safety/architecture text still says SONAME isolation alone is sufficient.
- [x] Add an unreleased `CHANGELOG.md` entry for the confirmed interposition defect, static-hidden repair, cache invalidation, PHP 8.5 coverage, and preserved safety gate.
- [x] Search `README.md` and `docs/` for `DEEPBIND`, `SONAME`, `libheif-heicfix`, and old `ldd` expectations; qualify or remove every stale unconditional claim.
- [x] **Validation:** Docs match the new ELF layout; no page expects shared private libheif in `imagick.so`'s `ldd`; Docusaurus and offline links pass.

**Commit:** `docs: explain static libheif isolation`

**Validation evidence:** `npm run build` (Docusaurus 3.10) succeeded and
`lychee --offline` reported 82 links, 0 errors. `grep -R` across `README.md`
and `docs/` shows only the new, qualified statements; no page expects shared
private libheif in `imagick.so`'s `ldd` output.

### Phase 4: Testing & Verification

**Goal:** Prove the repair in supported containers, then hold at a marked manual production gate before enabling it on the affected server.

#### Tasks

- [ ] Run shellcheck, whitespace validation, and sample checksum validation.
- [ ] Run the full container suite for Ubuntu 22.04/PHP 8.3, Ubuntu 22.04/PHP 8.5, and Ubuntu 24.04/PHP 8.3. Never substitute the generated fixture for the repository sample in the collision assertion.
- [ ] In every container, retain concise proof: local hidden `heif_*`, no MagickCore shared-libheif dependency, no MagickCore-to-system `heif_*` binding, and GD-to-system binding where GD has HEIF support.
- [ ] Run `build` twice to prove idempotency. In a disposable prefix, seed old revision metadata and prove one run migrates it to static-hidden linkage instead of skipping ImageMagick.
- [ ] Exercise `php`, `verify`, `rollback`, and `enable`; require GD-first decode after initial enablement and re-enable, and confirm rollback does not delete the private prefix.
- [ ] Build Docusaurus, audit offline links, and confirm all GitHub Actions jobs are green.
- [ ] **USER-ONLY / PRODUCTION — DO NOT EXECUTE AUTOMATICALLY:** After CI passes, give the user a copy/paste runbook that snapshots `/opt/heic-ios18-fix/installed-versions.txt`, rebuilds the existing private prefix, runs `php --php 8.5` and `verify --php 8.5` with the repository sample, checks CLI/FPM and GD-first decode, and includes `rollback --php 8.5`. Flag that `php`/`enable` change PHP configuration and may reload PHP-FPM.
- [ ] **Outstanding user gate:** Wait for the affected server's successful pre-flight, verification, and FPM output. Do not close this PGF or claim production validation before the user supplies it.
- [ ] **Validation:** Every automated command below passes, CI is green, and the user confirms the manual server gate; otherwise record failed/skipped tests in the PGF test ledger and keep production validation outstanding.

#### How to Test

Prerequisites: Docker, Bash 4+, `shellcheck`, Node.js/npm, and the checked-in HEIC sample. Container runs install dependencies and may take up to 90 minutes. The production block is deliberately excluded: the build agent must generate it from implemented behavior and wait for the user.

```bash
# Static checks
shellcheck -x heic-fix.sh tests/*.sh
git diff --check
sha256sum -c samples/SHA256SUMS

# Ubuntu 22.04 / PHP 8.3
docker run --rm -v "$PWD:/repo:ro" \
    -e PHP_VER=8.3 -e JOBS=4 \
    ubuntu:22.04 bash /repo/tests/run-container-test.sh

# Ubuntu 22.04 / PHP 8.5 — exact reported PHP version
docker run --rm -v "$PWD:/repo:ro" \
    -e PHP_VER=8.5 -e JOBS=4 \
    ubuntu:22.04 bash /repo/tests/run-container-test.sh

# Ubuntu 24.04 / PHP 8.3
docker run --rm -v "$PWD:/repo:ro" \
    -e PHP_VER=8.3 -e JOBS=4 \
    ubuntu:24.04 bash /repo/tests/run-container-test.sh

# Documentation
npm ci
npm run build
docker run --rm -v "$PWD:/input" lycheeverse/lychee:latest \
    --offline --no-progress /input/README.md /input/docs
```

Expected:

- Static checks and sample checksum exit 0.
- All three containers print `All container tests passed`; GD-first repository-sample probes create non-empty JPEG output.
- Output reports static-hidden MagickCore linkage, no shared libheif dependency, no MagickCore binding to system libheif, and intact GD system binding.
- Repeated build is idempotent; old revision metadata causes exactly one migration rebuild.
- Docusaurus builds and Lychee reports no broken local links.
- The PGF stays open until the user supplies successful output from the separately generated production runbook.

**Commit:** `test: verify GD-safe libheif isolation`
