# The docs map

`docs-map.yaml` is the one file a project writes for this skill. It states where
the site is, where its content is, and which sources are allowed to disagree with
it. Without it the checker can only guess, and a guess is worse than a red gate.

All paths are repo-relative and use `/`. The site directory is where
`docusaurus.config.*` lives; the content directory may sit inside it or beside it.

## Schema

| Key | Required | Type | Meaning |
|-----|----------|------|---------|
| `site` | yes | scalar | Directory holding `docusaurus.config.js|ts|mjs`. `.` when the site is the repo root. |
| `docs` | yes | scalar | Content directory the site renders. |
| `sidebar` | yes | scalar | Sidebar file that registers every page. |
| `env` | no | scalar | Env source of truth: `.env.example`, a schema file, or a sample config. |
| `env_prefix` | with `env` | scalar | Prefix that marks a documented token as an env variable (for example `APP_`). |
| `commands` | no | list | Full invocations (`--help` included) for every command whose flags the docs mention. |
| `ignore_flags` | no | list | Long flags documented for a tool the map cannot run (a root-only installer, `apt`, `gh`). Excluded from the stale check; requires `commands`. |
| `paths` | no | list | Repo path prefixes that docs may reference, each ending in `/` or naming a file. |
| `generated` | no | list | `path:marker` entries for pages a generator owns. |
| `generate` | with `generated` | scalar | Project command that rewrites the generated blocks. |
| `watch` | no | list | Source paths whose changes make a page stale (`--report` only). |

A family whose keys are absent is reported as `skipped`, not `ok`. Skipping is
visible on purpose: a repository that declares no `commands` is not claiming its
flags are correct.

## The constrained subset

The checker parses this file without a YAML library, so the accepted syntax is a
deliberate subset. Anything outside it is a map error (exit `2`) naming the line.

Accepted:

```yaml
site: docs
docs: docs/content
commands:
  - ./stackapt --help
  - ./build.sh --help
site: .
```

- Top-level `key: value` pairs, one per line. Quotes around a value are stripped.
- Top-level `key:` followed by list items indented exactly two spaces, each
  starting with `- `.
- Full-line comments starting with `#`.

Rejected: nested maps, flow lists (`[a, b]`), multi-line strings, anchors,
trailing comments after a value, tabs, and duplicate keys.

## Annotated example

This is `stackapt` — the site lives in `docs/`, its content in `docs/content/`:

```yaml
# Where docusaurus.config.ts lives.
site: docs
# What the site renders.
docs: docs/content
sidebar: docs/sidebars.ts

# Env source of truth, plus the prefix that marks a token as an env variable.
env: .env.example
env_prefix: STACKAPT_

# Runnable commands whose --help is authoritative. Keep this list short.
commands:
  - ./stackapt --help

# Flags documented for a tool this map cannot run — apt, gh, a root-only
# installer. They are excluded from the stale check, and an entry that no page
# mentions is reported as an advisory so the list does not rot.
ignore_flags:
  - --allow-unauthenticated
  - --force-confold

# Repo paths pages may reference. Narrow enough to be meaningful.
paths:
  - bin/
  - scripts/
  - docs/content/

# Pages a generator owns, as path:marker. The marker text must match the pair in
# the page, and `generate` must rewrite the block in place.
generated:
  - docs/content/reference/cli.md:cli
generate: ./scripts/gen-cli-reference.sh

# Sources whose changes make a page stale (advisory report only).
watch:
  - bin/
  - scripts/
```

## Filling it for each site shape

| Shape | Example repo | `site` | `docs` | Watch out |
|-------|--------------|--------|--------|-----------|
| Site at the repo root | `heic-ios18-fix` | `.` | `docs` | `site: .` is valid; do not write an empty value. |
| Site in a subdirectory, content inside it | `stackapt` | `docs` | `docs/content` | The config uses `path: 'content'`, which is relative to the site — translate it to repo-relative. |
| Site in a subdirectory, content beside it | `cloudflare-wordpress-rules` | `website` | `docs` | The config uses `path: '../docs'`; repo-relative is simply `docs`. |

Discover the shape before writing:

```bash
find . -maxdepth 4 -name 'docusaurus.config.*' -not -path '*/node_modules/*'
grep -nE "path:|routeBasePath:|sidebarPath:" <site>/docusaurus.config.*
```

## Choosing truth sources

A truth source is a file the project already maintains for its own sake. Good
choices: `.env.example`, a config schema, a router's route table, a CLI's
`--help`, a lockfile. Bad choices: a second hand-written table, rendered HTML, a
page under `build/`, or anything inside `node_modules`.

- List every command whose flags the docs mention. A documented flag that belongs
  to an unmapped command reads as stale, so an omission here looks like drift.
- Each `commands` entry is the whole invocation, `--help` included, and is run
  exactly as written from the repository root with a 10 s timeout. A command that
  needs a database or a container is a map error waiting to happen; document its
  flags by hand and leave it out.
- Keep `paths` to the directories docs actually reference. Listing the whole repo
  turns every example path into a false alarm.
- Set `env_prefix` to a prefix that is genuinely reserved. Without one, an
  uppercase word in a code sample is indistinguishable from an env variable.
- Put a generated page's **generator** in `generate`, not in `commands`.
- Use `ignore_flags` only for flags a page really documents but the map cannot
  run: apt's own flags, `gh release create`, a root-only installer. Every entry
  is a promise that a human checked it; an unused entry is reported so the list
  cannot quietly grow.

## Bootstrap procedure

1. Find the config and read `path`, `routeBasePath`, and `sidebarPath`.
2. Copy [../assets/docs-map.yaml](../assets/docs-map.yaml) to the repo root.
3. Fill `site`, `docs`, and `sidebar`. Run the checker: `map` must pass before
   anything else is meaningful.
4. Add `env` and `env_prefix` if the project has a sample env file. Run again and
   fix the pages it names, or widen the prefix if `env` is wrong.
5. Add `commands` one at a time. Run after each: a command whose `--help` exits
   non-zero belongs in the docs by hand, not in the map.
6. Add `paths` for the directories docs reference, then `generated` for pages a
   command owns, then `watch`.
7. Once clean, run with `--strict` and decide which advisories to accept.

## Common mistakes

| Symptom | Cause | Fix |
|---------|-------|-----|
| Exit `2`, `docs` resolves to no pages | `docs` points at the site dir, not the content dir | Point it at the directory holding `.md`/`.mdx` |
| Exit `2`, a command fails on `--help` | The command needs a service or the wrong runtime | Remove it from `commands`; document those flags by hand |
| `env` failures on ordinary words | `env_prefix` too broad, or unset | Set a reserved prefix; keep it narrow |
| `paths` failures on examples | The example path was never real | Either add it under `docs/` as a sample or stop backticking it |
| `generated` failures after a manual edit | The page was edited between the markers | Fix the generator and regenerate |
| `flags` failures on apt or `gh` flags | The page documents a tool the map cannot run | Map that tool, or list the flag under `ignore_flags` |
| An `ignore_flags` entry never matters | No page documents it any more | Remove the entry |
| A page is never flagged stale | It is not under a `watch` path | Add its source directory to `watch` |
