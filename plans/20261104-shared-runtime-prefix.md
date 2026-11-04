# 20261104-shared-runtime-prefix

**Version:** 6
**Status:** Not Started
**Type:** fix

## Original Prompt

> Create PGF.

## Plan Contract

- **Mission:** Move the default private HEIC/Imagick installation from GridPane-restricted `/opt/heic-ios18-fix` to the multi-user-readable `/usr/local/lib/heic-ios18-fix`, while preserving explicit custom prefixes and giving existing installations a safe rebuild-and-switch migration.
- **Execution Size:** large — four phases execute back to back, each with its own validation and commit; compilation and live activation remain separately gated.
- **Session Title:** Shared runtime prefix
- **Scope:** Change the default prefix everywhere to `/usr/local/lib/heic-ios18-fix`; retain `--prefix DIR`; detect an existing legacy `/opt/heic-ios18-fix` installation and print non-destructive guidance rather than copying binaries; guarantee root-owned, non-user-writable, world-readable/traversable installed runtime paths; exercise the extension as multiple unprivileged users; update the monitor defaults and all current user-facing path documentation. Do not rewrite historical completed PGFs or historical changelog entries, auto-copy/relink an existing prefix, delete `/opt/heic-ios18-fix`, weaken GridPane ACLs, perform a live build, change production PHP configuration, reload FPM, deploy to a client site, or assign a release version.
- **Success Metrics:** `heic-fix.sh --help` reports the new default; an explicit `--prefix` still wins; a legacy-only installation produces one actionable migration hint without mutation; disposable Ubuntu 22.04/24.04 builds pass the existing static-hidden linkage and GD-first decode assertions from the new default; two unprivileged users can traverse the prefix, read `imagick.so` and its private libraries, and decode the checked-in iOS 18 sample; installed files remain root-owned and not group/other writable; plugin defaults and docs contain no current default reference to `/opt/heic-ios18-fix`; shell, docs-drift, Docusaurus, local-search, link, and PGF checks pass.
- **Touched paths:** heic-fix.sh, tests/run-container-test.sh, tests/check-default-prefix.sh, tests/run-prefix-matrix.sh, README.md, docs/**/*.md, monitoring/README.md, wordpress-plugin/heic-fix-monitor/admin/class-heic-fix-monitor-settings.php, wordpress-plugin/heic-fix-monitor/includes/probes/class-heic-fix-monitor-check-probe.php, wordpress-plugin/heic-fix-monitor/includes/probes/class-heic-fix-monitor-filesystem-probe.php, wordpress-plugin/heic-fix-monitor/docs/*.md, wordpress-plugin/heic-fix-monitor/readme.txt, wordpress-plugin/heic-fix-monitor/tests/run-wp-tests.sh, wordpress-plugin/heic-fix-monitor/tests/run-probe-tests.php, plans/tests.md, .opencode/docker-instances.log
- **Version Target:** release-assigned (git-squash-release)
- **Approach / Architectural Fit:** Keep the existing single-prefix architecture and embedded absolute rpaths: rebuild the complete private stack at the new prefix rather than moving only `imagick.so`. Introduce explicit default/legacy prefix constants and an explicit-prefix flag in `heic-fix.sh`; initialize all derived paths from the selected prefix exactly as today. A legacy detector may warn only when the caller did not pass `--prefix`, the new prefix has no installation metadata/runtime, and `/opt/heic-ios18-fix` does; it must direct inspection to `--prefix /opt/heic-ios18-fix` and migration to the documented rebuild flow. Rely on root ownership plus `umask 022`, explicit install modes, and post-install assertions—not broad ACL grants—to make runtime artifacts readable without making them writable. Extend the existing container test rather than create a second build harness. Update the WordPress monitor's seeded defaults after its open PGF is no longer being actively changed; do not edit or close that PGF from this plan.
- **Dependencies / Ordering:** `plans/20261028-wordpress-heic-monitor.md` owns overlapping `wordpress-plugin/**`, `docs/**`, and `README.md` paths and remains open on user-only staging evidence. Before execution, confirm no session is actively changing those paths; serialize this plan behind that work or obtain an explicit ownership handoff. Never run two plans against the overlapping paths concurrently.
- **Safety:** Every local compile/container run requires advance approval with the exact command, two-core/six-GB ceiling, and expected wall time. Every production build, PHP module switch, FPM reload, and deletion is a separate USER-ONLY task. The migration keeps `/opt/heic-ios18-fix` intact until the user verifies every PHP version and independently decides whether to remove it. During PGF creation, Docusaurus and Docker validation were deliberately not run because no build/container approval was requested; these remain tracked execution-time gates, not silently passed checks.
- **References:** `AGENTS.md` (docs truth-source and drift gate); `README.md`; `docs/how-it-works.md`; `docs/commands.md`; `docs/platforms/gridpane.md`; `docs/troubleshooting.md`; `docs-map.yaml`; `heic-fix.sh` (`PREFIX`, `usage`, `init_paths`, `parse_args`, `cmd_build`, `cmd_php`, `enable_ext`, `cmd_verify`); `tests/run-container-test.sh`; `.github/workflows/ci.yml`; `plans/20261028-wordpress-heic-monitor.md`; `plans/implemented/20261028-fix-gd-libheif-symbol-collision.md`; `plan-generation-skill`; `diagnosing-bugs`; `gridpane-core`; `lmt-skills-development-workflow`; `lmt-skills-docusaurus-sync`.

## Skills Used

- **Creation:** `plan-generation-skill` (v6 contract, phase, validation, and close-out format), `diagnosing-bugs` (permission-denial feedback loop and regression signal), `gridpane-core` (GridPane ACL and production-safety boundaries).
- **Implementation:** none yet.

## Phases

### Phase 1: Default Prefix and Legacy Detection

**Goal:** Make the accessible shared prefix the canonical default while preserving explicit-prefix behavior and exposing a non-destructive legacy-installation path.

#### Tasks
- [x] In `heic-fix.sh`, replace the literal default assignment with `DEFAULT_PREFIX="/usr/local/lib/heic-ios18-fix"`, add `LEGACY_PREFIX="/opt/heic-ios18-fix"`, initialize `PREFIX=$DEFAULT_PREFIX`, and add `PREFIX_EXPLICIT=0`; update the file header and `usage()` default text from `/opt/heic-ios18-fix` to `/usr/local/lib/heic-ios18-fix`.
- [x] In both `parse_args()` branches for `--prefix`, set `PREFIX_EXPLICIT=1` after assigning the caller's value; leave all existing `init_paths()` derivations (`HEIF`, `HEIF_STATIC`, `IMAGEMAGICK`, `SRC`, `LOGS`, `PHPROOT`, `VERSIONS_FILE`, `TMPDIR_PREFIX`) relative to `PREFIX` so custom paths and embedded rpaths continue to work.
- [x] Add a read-only `legacy_prefix_hint()` helper and invoke it after `init_paths()` but before command dispatch. Emit one stderr warning only when `PREFIX_EXPLICIT == 0`, the new prefix lacks both `installed-versions.txt` and `php/*/imagick.so`, and the legacy prefix contains either signal. The message must show the exact inspection form `heic-fix.sh <command> --prefix /opt/heic-ios18-fix`, state that binaries with absolute rpaths must be rebuilt rather than copied, and point to the migration section in `docs/troubleshooting.md`; it must never mutate either prefix or change the command's exit code.
- [x] Add an installation-access assertion used by `build_imagick()` after `install -m 0755` and by `cmd_build()` after ImageMagick installation: use `stat`/`namei`-equivalent path-component checks to require directories from the selected prefix downward to be traversable by non-owners, required shared objects and `imagick.so` to be readable by non-owners, and every installed path checked to be root-owned and not group/other writable. Fail with the exact inaccessible component and a rollback-safe explanation; do not run recursive `chmod`, alter parent ACLs, or change permissions outside the selected prefix.
- [x] Preserve custom-prefix compatibility: the access assertion may report unsafe caller-selected modes but must not silently normalize an existing custom tree; newly created default-prefix directories continue to derive safe modes from `umask 022`, and installed libraries/extensions retain their existing explicit install modes.
- [x] Add `tests/check-default-prefix.sh` as a fast non-building regression harness: assert `--help` names the new default, an explicit disposable `--prefix` appears in `status`, a seeded legacy-only metadata/extension fixture produces exactly one hint, the same call with explicit `--prefix /opt/heic-ios18-fix` suppresses that hint, neither fixture is mutated, and its `--audit` mode allowlists every current-tree `/opt/heic-ios18-fix` reference as legacy migration/backwards-compatibility evidence.
- [x] **Validation:** `bash -n heic-fix.sh tests/run-container-test.sh`; `shellcheck -x heic-fix.sh tests/*.sh`; and non-building help/status fixture probes prove default, override, legacy-warning, no-mutation, and exit-code behavior.

**Commit:** `pending` — `fix: default the heic-fix prefix to /usr/local/lib for site users`

### Phase 2: Multi-User Runtime Regression Coverage

**Goal:** Prove the complete rebuilt runtime—not merely the extension file—can be loaded safely by multiple unprivileged accounts without weakening ownership or write protections.

#### Tasks
- [ ] Change `tests/run-container-test.sh`'s fallback `PREFIX` to `/usr/local/lib/heic-ios18-fix`; retain the `PREFIX` environment override so the harness still covers caller-selected locations.
- [ ] Add `assert_runtime_permissions()` to inspect the prefix, `imagemagick/lib`, `heif/lib`, each built `php/<X.Y>` directory, `imagick.so`, and every `ldd "$PREFIX/php/$PHP_VER/imagick.so"` dependency resolved inside the prefix. Require UID 0 ownership, no group/other write bits, directory traversal, file readability, and no unresolved dependency; print the exact failing path and mode.
- [ ] In the disposable container only, create two unprivileged users with distinct primary groups and no supplemental root/web groups. For each user, run `namei -l`/`test -r` against `imagick.so`, execute the existing `imagick-only` decode, then execute the existing `gd-first` decode against `samples/failure_magick.HEIC`; ensure the sample and repository mount remain read-only.
- [ ] Refactor `run_php_decode()` only as needed to execute through `runuser -u <user> -- env ... php$PHP_VER` while preserving the exact extension load order and existing root-run assertions. Do not grant either test user ownership, ACL entries, or write access to the prefix.
- [ ] Retain the current static-hidden ELF contract: `imagick.so` resolves private MagickCore/MagickWand, exposes no shared libheif path, and both unprivileged users decode successfully with GD loaded first.
- [ ] Add `tests/run-prefix-matrix.sh` as the local bounded wrapper for the three Ubuntu/PHP combinations. It must run cases sequentially, default to `--cpus=2 --memory=6g --memory-swap=6g` and `JOBS=2`, set the required `opencode.project`/`opencode.started` labels and `oc-heic-ios18-fix-` names, append start/stop state to `.opencode/docker-instances.log`, trap interruption to stop project-labeled containers it started, and fail if any project-labeled container remains running.
- [ ] Ensure the existing Ubuntu 22.04/PHP 8.3, Ubuntu 22.04/PHP 8.5, and Ubuntu 24.04/PHP 8.3 CI matrix exercises the new default without changing `.github/workflows/ci.yml`; only edit the workflow if test evidence proves an environment variable or package is actually missing.
- [ ] **Validation:** after explicit heavy-build approval, all three bounded disposable container runs finish with the new prefix, permission assertions, both unprivileged-user modes, static-hidden linkage, rollback, and re-enable checks passing.

### Phase 3: Migration Runbook and Consumer Defaults

**Goal:** Align every active consumer and user-facing instruction with the new shared prefix and provide a safe, reversible migration for existing `/opt` installations.

#### Tasks
- [ ] Update `README.md`, `docs/index.md`, `docs/getting-started.md`, `docs/how-it-works.md`, `docs/commands.md`, `docs/alternatives.md`, `docs/monitoring.md`, `docs/platforms/gridpane.md`, `docs/platforms/runcloud.md`, `docs/platforms/ubuntu-26-04.md`, `docs/troubleshooting.md`, and `monitoring/README.md` so current defaults, layouts, examples, check paths, and executable/library paths use `/usr/local/lib/heic-ios18-fix`; preserve `/opt/heic-ios18-fix` only where it explicitly denotes the legacy source installation in migration instructions.
- [ ] Add a dedicated migration section to `docs/troubleshooting.md`: capture `heic-fix.sh status --prefix /opt/heic-ios18-fix`, snapshot `/etc/php/<X.Y>/mods-available/imagick-heic.ini` and `/opt/heic-ios18-fix/installed-versions.txt`, inventory enabled PHP versions, check `nproc`/memory/swap/current containers, rebuild with the repository script and the new default using `--jobs 2`, answer no to `php`'s enable prompt during preflight, test `php -n` as an unprivileged site user, then switch and verify one PHP version at a time.
- [ ] In the same runbook, document rollback as restoring the saved `imagick-heic.ini`/running the old-prefix `enable --prefix /opt/heic-ios18-fix --php X.Y` only if the old path is readable by the target users, otherwise using `rollback --php X.Y` to restore stock Imagick. State that the old prefix must remain untouched until every CLI/FPM/site check passes and that removing it is a separate destructive decision.
- [ ] Update `wordpress-plugin/heic-fix-monitor/admin/class-heic-fix-monitor-settings.php` defaults, `class-heic-fix-monitor-filesystem-probe.php` fallback, and `class-heic-fix-monitor-check-probe.php` seeded allowlist to `/usr/local/lib/heic-ios18-fix`; retain an explicit documented constant-based allowlist entry for legacy `/opt` installations rather than silently accepting both locations.
- [ ] Update `wordpress-plugin/heic-fix-monitor/docs/configuration.md`, `docs/architecture.md`, `tests/run-wp-tests.sh`, and `tests/run-probe-tests.php` expected values/fixtures for the new default; keep traversal-rejection and different-script-name security assertions intact.
- [ ] Do not edit historical path evidence in `CHANGELOG.md` or `plans/implemented/**`. Do not edit or close `plans/20261028-wordpress-heic-monitor.md`; before touching overlapping monitor/docs paths, verify no other session owns them and record the ownership handoff in this plan's execution notes.
- [ ] Run a scoped stale-default audit: current code/docs/tests outside `plans/implemented/**` and historical `CHANGELOG.md` may contain `/opt/heic-ios18-fix` only in the legacy migration section, explicit backwards-compatibility tests, or legacy allowlist guidance; classify every remaining match in the phase notes.
- [ ] **Validation:** plugin fixture/probe tests pass with the new defaults; the docs-drift gate passes; the stale-default audit has no unexplained match; migration commands name the correct source/destination and never delete, copy, symlink, enable, reload, or deploy automatically.

### Phase 4: Testing & Verification

**Goal:** Prove the default-prefix change, compatibility behavior, documentation, and production handoff are complete without executing the live migration.

#### Tasks
- [ ] Run `bash -n heic-fix.sh tests/*.sh` and `shellcheck -x heic-fix.sh tests/*.sh`; confirm no parse or ShellCheck findings.
- [ ] Run the WordPress monitor's existing non-container fixture/probe tests and confirm seeded defaults, path containment, and allowlist behavior pass.
- [ ] Run `bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check`, then—after explicit build approval—`npm run build` and `bash scripts/check-docs-search.sh`; confirm Docusaurus and generated search checks pass.
- [ ] After explicit Docker/build approval, run the three existing Ubuntu/PHP container combinations with `--cpus=2 --memory=6g --memory-swap=6g`, `JOBS=2`, the required `opencode.project`/`opencode.started` labels and project-prefixed container names; log and stop every container per the global agent instructions. Do not launch all three concurrently.
- [ ] Run `pgf test run 20261104-shared-runtime-prefix --dry-run` and `pgf check`; record every gated or unavailable test in `plans/tests.md` instead of silently skipping it.
- [ ] **USER-ONLY / PRODUCTION / COMPILE — DO NOT EXECUTE AUTOMATICALLY:** On the target GridPane server, approve and run the bounded new-prefix build only after reviewing `nproc`, `MemTotal`, `SwapTotal`, and active containers. Budget at most 2 build jobs and a 6 GB memory ceiling, expect roughly 10–30 minutes, keep `/opt/heic-ios18-fix` intact, and do not combine this approval with PHP activation.
- [ ] **USER-ONLY / PRODUCTION / PHP-FPM — DO NOT EXECUTE AUTOMATICALLY:** For each required PHP version, approve the module switch and FPM reload separately; verify as at least two real site users that `namei`, `test -r`, WP-CLI, Imagick-only decode, GD-first decode, and the application upload path succeed before proceeding to the next version. Record the results; do not claim client-site validation without user-supplied evidence.
- [ ] **USER-ONLY / DESTRUCTIVE — DO NOT EXECUTE AUTOMATICALLY:** Removal of `/opt/heic-ios18-fix` is out of scope. If desired after a separate retention period, create a separately approved cleanup task with a fresh backup and proof that no ini, rpath, monitor setting, cron, Monit command, or service references the old prefix.
- [ ] **Validation:** every automated command in `How to Test` exits 0; all current defaults point to `/usr/local/lib/heic-ios18-fix`; custom and legacy prefixes remain inspectable; multi-user runtime tests pass; production build, activation, real-site verification, and any legacy deletion remain explicitly outstanding until the user supplies evidence.

#### How to Test

Prerequisites: Bash 4+, `shellcheck`, the checked-in iOS 18 sample, PHP for the monitor unit/fixture tests, existing `node_modules` (or separately approved `npm ci`), and explicit approval before Docusaurus or Docker builds. Docker E2E runs require a host with at least 2 available CPU cores and 6 GB available memory; run one matrix case at a time. Live GridPane migration is intentionally excluded.

```bash
bash -n heic-fix.sh tests/*.sh
shellcheck -x heic-fix.sh tests/*.sh
bash tests/check-default-prefix.sh
bash wordpress-plugin/heic-fix-monitor/tests/run-wp-tests.sh
php wordpress-plugin/heic-fix-monitor/tests/run-probe-tests.php
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check
npm run build
bash scripts/check-docs-search.sh
bash tests/run-prefix-matrix.sh
/home/openchamber/git/zsh-custom/.agents/skills/plan-generation-skill/scripts/pgf test run 20261104-shared-runtime-prefix --dry-run
/home/openchamber/git/zsh-custom/.agents/skills/plan-generation-skill/scripts/pgf check
```

Expected: shell and monitor tests exit 0; help names `/usr/local/lib/heic-ios18-fix`; docs drift, Docusaurus, search, and PGF checks pass; each bounded container builds at the new default and proves root-owned/non-writable artifacts plus Imagick-only and GD-first iOS 18 decoding as two unrelated unprivileged users; no project-labeled container remains running. Any `/opt/heic-ios18-fix` grep match is explicitly classified as legacy migration/backwards-compatibility evidence. Production compilation, PHP/FPM activation, real-site checks, and deletion remain outstanding USER-ONLY work.
