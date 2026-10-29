# Adopting the skill in a project

The skill is vendored, not installed globally: each repository carries its own
copy under `.agents/skills/` and commits it, so the gate works for every agent
and every CI runner without a machine-level install. The copy is a snapshot of
this repository's version.

Budget about fifteen minutes for the first repository. After that, adoption is
one install command and one map edit.

## 1. Install the copy

From the project root:

```bash
npx skills add jordantrizz/lmt-skills --skill lmt-skills-docusaurus-sync -y
git add .agents/skills/lmt-skills-docusaurus-sync
git commit -m "chore: add the docs sync skill"
```

What lands where:

| Path | Role |
|------|------|
| `.agents/skills/lmt-skills-docusaurus-sync/` | the committed copy every agent and CI reads |
| `docs-map.yaml` (next step) | the project's own truth declaration, never overwritten by a refresh |

The `skills` CLI may also expose the skill to other agents through links it
manages itself. Commit `.agents/skills/`; leave anything else the CLI created to
the CLI.

## 2. Write the map

Copy the starter and fill every line:

```bash
cp .agents/skills/lmt-skills-docusaurus-sync/assets/docs-map.yaml docs-map.yaml
```

Read [project-map.md](project-map.md) while doing it. The short version for each
site shape:

| Shape | Example | `site` | `docs` |
|-------|---------|--------|--------|
| Site at the repo root | a script repo with `docs/` | `.` | `docs` |
| Site in a subdirectory, content inside it | `docs/docusaurus.config.ts` + `docs/content/` | `docs` | `docs/content` |
| Site in a subdirectory, content beside it | `website/` + `../docs` | `website` | `docs` |

Discover the truth rather than guessing:

```bash
find . -maxdepth 4 -name 'docusaurus.config.*' -not -path '*/node_modules/*'
grep -nE "path:|routeBasePath:|sidebarPath:" <site>/docusaurus.config.*
```

Then run the checker and fix what it reports:

```bash
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check
```

Exit `2` means the map is wrong, not the pages. Exit `1` means the map is right
and a page is stale — which is the finding you wanted.

## 3. Signal it in the repository

Add this block to the project's `AGENTS.md` so the next agent (or the next
session) does not have to rediscover the gate:

```markdown
## Docs upkeep

- Skill: `lmt-skills-docusaurus-sync` (installed under `.agents/skills/`)
- Map: `docs-map.yaml` — truth sources for flags, env, paths, generated pages
- Gate: `bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check`
- Regenerate with the project's `generate` command before the gate
- A code change that alters a documented fact ships with its page update
```

Optional but useful: one line in the contributing page pointing at the map, so a
human contributor knows why the gate exists.

## 4. Put the gate in CI

Two jobs, deliberately separate: the checker is cheap and needs no runtime; the
generator check needs the project's toolchain.

```yaml
name: docs

on:
  pull_request:
    paths:
      - 'docs/**'
      - 'docs-map.yaml'
      - 'bin/**'
      - 'scripts/**'
      - '.github/workflows/docs.yml'
  workflow_dispatch:

jobs:
  drift:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Docs drift
        run: bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check

  generated:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
          cache-dependency-path: <site>/package-lock.json
      - run: npm ci
        working-directory: <site>
      - run: npm run generate
        working-directory: <site>
      - run: git diff --exit-code
```

Adjust the `paths` filters to the repository, set `<site>` to the directory from
the map, and replace `npm run generate` with the command in the map's `generate`
key. If the generator is a repo script, drop the Node steps and run the script.

Why two jobs:

- The drift job is read-only and cheap, so it can run on every pull request.
- The generator job proves the block is reproducible. It must run the generator
  and compare the tree, which is exactly what the drift checker refuses to do.

Once the repository is clean, add `--strict` to the drift job to catch a flag
that exists in `--help` but appears on no page.

## 5. Optional pre-commit

A hook keeps a stale page out of the commit that caused it. Keep it to the
checker; a full site build is too slow for a hook.

```bash
#!/usr/bin/env bash
set -euo pipefail
changed="$(git diff --cached --name-only)"
if printf '%s\n' "$changed" | grep -qE '^(docs/|docs-map\.yaml|bin/|scripts/)'; then
  bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check
fi
```

Save it as `.git/hooks/pre-commit` and make it executable. A pre-commit hook is
local and easy to bypass; CI is the gate that actually holds.

## 6. Refresh the copy

When this repository's skill changes:

```bash
npx skills add jordantrizz/lmt-skills --skill lmt-skills-docusaurus-sync -y
git diff --stat .agents/skills/lmt-skills-docusaurus-sync
git add .agents/skills/lmt-skills-docusaurus-sync
git commit -m "chore: refresh docusaurus sync skill"
```

Read the vendored `CHANGELOG.md` first: it says whether the refresh changes
behaviour or only wording. Never hand-edit the vendored files. If a project needs
different behaviour, the fix belongs upstream in this repository, or in that
project's `docs-map.yaml`, which a refresh never touches.

## Verification after adoption

Run these once and record the result in the adoption commit:

```bash
# the gate, on a clean tree
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check

# the advisory view
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --report

# prove it fails, using a family this map actually runs.
# If the map declares commands, document a flag that does not exist:
printf -- '\n`--definitely-not-a-flag`\n' >> docs/index.md
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check; echo "expected 1, got $?"
git checkout -- docs/index.md

# If the map declares only paths, reference a file that does not exist:
printf -- '\nSee `scripts/definitely-not-here.py`.\n' >> docs/index.md
bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check; echo "expected 1, got $?"
git checkout -- docs/index.md
```

Read the `CHECK` lines, not only the exit code. A family the map does not feed
is reported `skipped`, and a skipped family can never fail — a green summary over
four skipped families proves nothing. If your probe exits `0`, either the family
is skipped or the page is not inside the mapped `docs` directory.

Check the working tree afterwards: `printf` appended a line, and `git checkout --`
only reverts it if the file was tracked and clean beforehand.
