---
name: lmt-skills-docusaurus-sync
description: >-
  Keep a live Docusaurus site's docs matching the code: a committed
  docs-map.yaml names the truth sources, and a drift check fails on stale flags,
  env vars, dead paths, and generated blocks. Not for building, scaffolding, or
  migrating a site.
---

# lmt-skills-docusaurus-sync

The upkeep half of documentation: a site already builds and deploys, and the only
open question is whether its pages still tell the truth. This skill answers that
with a machine verdict for cheap facts — flags, environment variables, repo
paths, generated blocks — and a short, explicit checklist for the prose a
machine cannot judge.

It is standalone. It owns no scaffolding, no migration, and no theme work, and it
depends on nothing but the target repository, `bash`, and `git`.

## What this skill owns

| Owns | Does not own |
|------|--------------|
| The `docs-map.yaml` contract in each project | Creating a site or its first pages |
| Deciding which pages a code change invalidates | Moving or merging an existing docs tree |
| Running the read-only drift check | Choosing information architecture |
| Keeping generated blocks generated | Navbar, CSS, theme, custom components |
| The CI gate and the `AGENTS.md` signal | i18n, versioned docs, a blog, search setup |

A site that does not build is not in scope: fix the build first, then return here.
A repository with no site is not in scope at all.

## When to use — and when not to

Use this skill when:

- A change added, removed, or renamed a flag, environment variable, route, config
  key, command, or default value.
- Someone asks whether the docs are still accurate, or a reviewer suspects they
  are not.
- A release is being cut and the documentation has to be declared current.
- The skill is being adopted in a repository that already publishes a site.
- A docs pull request needs a verdict rather than an opinion.

Do not use it to stand up a site, to restructure an existing one, to translate
pages, or to design a theme. Those are different jobs with different gates.

## The loop

Run this in order. Each step produces something the next one can check.

1. **Locate the map.** `docs-map.yaml` at the repository root, else
   `<site>/docs-map.yaml`. No map means the repository has not adopted this
   skill; bootstrap one from [references/project-map.md](references/project-map.md).
2. **Classify the change** with the table in
   [references/drift.md](references/drift.md). It maps a change class to the pages
   that must move, so nothing depends on remembering the site layout.
3. **Update the pages.** Front matter keeps `title` and `description`; a link to a
   page inside the docs tree is relative and keeps its extension.
4. **Regenerate the declared blocks** with the project's own command. Never
   hand-edit a generated block: the next run reverts it.
5. **Gate.** Run the drift check and the project's site build. Both must exit 0.
6. **Record.** Commit the docs with the code change that caused them, in the same
   commit or at least the same pull request. A separate "docs pass" rots between
   passes.

## The docs map

`docs-map.yaml` is the only project-specific input. It is committed, short, and
reviewed like code. Every key answers "where does this fact really come from":

| Key | Holds |
|-----|-------|
| `site` | Directory containing `docusaurus.config.*` (`.` when the site is the repo root) |
| `docs` | Content directory the site renders |
| `sidebar` | Sidebar file that registers every page |
| `env` | Env source of truth: `.env.example`, a schema, or a sample config |
| `env_prefix` | Prefix that marks a documented token as an env variable |
| `commands` | Runnable commands whose `--help` is the flag source of truth |
| `ignore_flags` | Flags documented for a tool the map cannot run; excluded from the stale check |
| `paths` | Repo path prefixes that docs are allowed to reference |
| `generated` | `path:marker` entries for pages a generator owns |
| `generate` | The project command that rewrites the generated blocks |
| `watch` | Source paths whose changes make a page stale |

The file is deliberately a constrained YAML subset — top-level scalars plus
top-level lists of scalars. That keeps the checker dependency-free: no `yq`, no
Python, no network. The full schema, an annotated example, and how to fill it for
each site shape are in [references/project-map.md](references/project-map.md); a
commented starter is at [assets/docs-map.yaml](assets/docs-map.yaml).

## Checks and exit codes

The bundled checker is [scripts/docs-drift.sh](scripts/docs-drift.sh). Run it from
the repository root, read-only:

```bash
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --report
```

It reports one line per family:

| Family | Fails when |
|--------|-----------|
| `map` | A declared path does not exist, or the site config is missing |
| `flags` | A page documents a `--flag` the command's `--help` no longer lists |
| `env` | A page documents an env variable the env source no longer defines |
| `paths` | A backticked repo path no longer exists |
| `generated` | A generated file is missing, or its marker pair is absent, doubled, or out of order |

Advisory findings — a flag that exists but is undocumented, an age hint from
`--report` — do not fail the gate unless `--strict` is passed. Exit codes are
`0` clean, `1` drift, and `2` a usage or map error: a `2` means the map does not
describe the repository, so fix the map, not the pages.

## Install in a project

From the target repository root:

```bash
npx skills add jordantrizz/lmt-skills --skill lmt-skills-docusaurus-sync -y
```

That writes a copy under `.agents/skills/lmt-skills-docusaurus-sync/` in the
project. Commit the copy with the rest of the change, then:

1. Copy [assets/docs-map.yaml](assets/docs-map.yaml) to `docs-map.yaml` and fill
   in every key, following [references/project-map.md](references/project-map.md).
2. Run the checker once and fix what it reports.
3. Put the signal in the repository's `AGENTS.md`:

   ```markdown
   ## Docs upkeep

   - Skill: `lmt-skills-docusaurus-sync` (installed under `.agents/skills/`)
   - Map: `docs-map.yaml` — truth sources for flags, env, paths, generated pages
   - Gate: `bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check`
   - Regenerate with the project's `generate` command before the gate
   - A code change that alters a documented fact ships with its page update
   ```

4. Add the gate to CI and, optionally, a pre-commit hook. The project owns
   regeneration (`generate` followed by `git diff --exit-code`); the checker never
   runs a generator, because that would rewrite the tree it is inspecting.

The full checklist — CI jobs for both site shapes, the pre-commit snippet, the
refresh procedure, and how to prove the gate fails — is in
[references/adoption.md](references/adoption.md).

## Refresh

The copy under `.agents/skills/` is a snapshot, not a symlink. After this skill
changes upstream, re-run the install command and commit the refreshed copy.
Never hand-edit the vendored files — the next refresh overwrites them, and the
project-specific truth belongs in `docs-map.yaml`, which the refresh never
touches. `CHANGELOG.md` records what changed, so a project can decide whether a
refresh is worth a commit.

## Pitfalls

- **A map that flatters the repository.** Listing only the paths that already
  pass makes the gate green and useless. Every `paths` prefix should cover the
  places docs actually reference.
- **Checking inside a container.** `--help` from a shell that lacks the project's
  runtime prints the host tool's flags. Run the checker where the commands the
  map names actually resolve.
- **Recording a flag from memory.** The source of truth is `--help`, not the last
  pull request description.
- **Regenerating by hand.** Editing between markers works until the next run and
  hides a broken generator.
- **Documenting the generator's output path instead of the fact.** A page under a
  generated block that is edited by hand will be reverted without warning.
- **Deleting superseded behaviour.** Move it to a History page with the date;
  deleting it re-opens a settled argument.
- **A docs pass later.** If the page update is a separate commit from the code
  change, it is the first thing dropped under pressure.
- **Ignoring `--strict`.** Advisory findings are how a reference table hollows
  out; run strict in CI once the repository is clean.
- **Reading a green summary as coverage.** Several families can be `skipped` and
  the checker still exits 0. Read the `CHECK` lines, not only the exit code.
- **A `2` treated as a failure to fix in the docs.** It is a map defect.

## Completion checklist

- [ ] The drift check exits 0, or `--report` shows only accepted advisories.
- [ ] Every flag and env variable added or removed by the change is reflected in
      the pages the drift table named.
- [ ] Generated blocks were regenerated by the project command, not by hand.
- [ ] `docs-map.yaml` still matches the repository: every declared path resolves.
- [ ] The site build exits 0 (broken links and anchors are build failures).
- [ ] The `AGENTS.md` signal exists and names the current gate command.
- [ ] The docs change ships with the code change.
