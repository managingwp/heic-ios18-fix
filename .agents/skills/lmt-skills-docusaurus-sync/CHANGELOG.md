# Changelog

## 0.2.0 — 2026-10-29

- `ignore_flags` — flags a page documents for a tool the map cannot run (apt,
  `gh release create`, a root-only installer) are excluded from the stale check
  instead of failing it. An entry no page mentions is reported as an advisory, so
  the list cannot quietly grow.
- A documented token with an argument (`--jobs N`, `--jobs=N`) now counts as
  documenting `--jobs`; before, only a bare `--jobs` was recognised.
- A stale-flag failure names both remedies: map the command, or list the flag
  under `ignore_flags`.

## 0.1.0 — 2026-10-29

First version, split out as a standalone per-project upkeep skill.

- `SKILL.md` — the update loop, the map contract, install and signal, the
  pitfalls that keep a docs gate honest.
- `references/drift.md` — change class to pages, generated-block rules, and the
  history policy.
- `references/project-map.md` — schema, constrained YAML subset, annotated
  example, the three site shapes, and the bootstrap procedure.
- `references/adoption.md` — install, the `AGENTS.md` signal, CI jobs for both
  site shapes, an optional pre-commit hook, the refresh procedure, and how to
  prove the gate fails.
- `assets/docs-map.yaml` — commented starter map.
- `scripts/docs-drift.sh` — read-only checker for map, flags, env, paths, and
  generated blocks, with an advisory age report. Exit 0 clean, 1 drift, 2 map or
  usage error. The `paths` family reports how many references it actually
  resolved, so a passing family is distinguishable from an empty one.
- `tests/run.sh` — 42 assertions across 13 fixture cases, including a read-only
  proof for every mutated tree.
