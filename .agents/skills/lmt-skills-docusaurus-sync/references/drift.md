# Drift: which pages a change invalidates

A change is not "documented" because a page was touched. It is documented when the
pages that state the changed fact now state the new one. This reference maps each
change class to its truth source, the pages that normally carry it, and the check
that catches the omission.

## Change classes

| Change | Truth source | Pages that normally carry it | Caught by |
|--------|--------------|------------------------------|-----------|
| Flag added, removed, or renamed | The command's `--help` | the command's page, plus every reference table that lists its flags | `flags` |
| Environment variable added or removed | `.env.example` or the config schema | configuration reference, getting started, deployment notes | `env` |
| A default value changed | The code that reads the setting | reference table row, upgrade note when behaviour flips | review |
| A route, endpoint, or webhook changed | The router or route table | API reference, client examples | review |
| A config key added, renamed, or deprecated | The config schema | options reference, example configs on the page | review |
| A command added or removed | The CLI entry point | commands index, one page per command, sidebar | `flags` (if mapped) |
| A script, path, or file referenced by docs moved | The repository tree | every page that backticks the path | `paths` |
| A dependency, base image, or runtime version bumped | Lockfile, Dockerfile, toolchain file | installation, requirements, contributing, troubleshooting | review |
| A release or changelog entry | Tags, `CHANGELOG.md` | history page, changelog page | `generated` |
| A feature deprecated | The code and release notes | reference note, history page with the date | review |
| A page renamed or moved | The sidebar | every inbound link, README pointers, redirects | site build |

`review` means a human or agent decision. The other four are machine checks and
they fail the gate. Neither list is exhaustive: when a change does not fit a row,
ask which single sentence in the docs is now false, and start there.

## Ordering

1. Change the truth source first. Documentation follows code, never the reverse.
2. Update the pages the table names, including the index or table of contents row.
3. Update examples that would now fail if a reader pasted them.
4. Regenerate any generated block that includes the changed fact.
5. Run the gate, then the site build.

A page that mentions a removed flag is more damaging than a missing page: the
reader trusts it and the command rejects it.

## Generated blocks

A generated block is a region a command owns. It looks like this:

```markdown
<!-- BEGIN GENERATED: cli -->
| Flag | Meaning |
|------|---------|
| `--port` | Listen port |
<!-- END GENERATED: cli -->
```

Rules that make regeneration safe:

- **One marker pair per block, and the names match.** A doubled or unbalanced pair
  is a `generated` failure, because a partial rewrite silently drops content.
- **The generator owns everything between the markers**, including the table
  header. Text outside the markers is hand-written and survives regeneration.
- **The block is idempotent.** Two consecutive runs produce identical bytes. Prove
  it with a checksum, not with `git diff` after a commit, which always shows the
  regeneration.
- **A changelog generator excludes its own output.** Otherwise its own commit
  changes the next run's input and the block never stabilises.
- **The page says it is generated** in one visible sentence above the block, so a
  contributor edits the generator instead of the table.

The checker verifies that the file exists and the markers balance. It does not run
the generator: a generator rewrites the working tree, and a check that mutates the
tree cannot be trusted on a shared checkout. The project's CI owns the stronger
assertion:

```bash
npm run generate
git diff --exit-code
```

## History, not deletion

Superseded behaviour moves to a history page with the date in the title. It is
cheap to keep and it answers "why is it like this" without re-opening the
decision. Delete only what never shipped.

## Worked example

A flag is renamed from `--url` to `--endpoint`:

| Step | Action |
|------|--------|
| Truth source | `cmd --help` now prints `--endpoint` |
| Pages | the command page, the flags reference table, the quick start example, the upgrade note |
| Generated | any flags table built from `--help` (regenerate, do not edit) |
| Gate | `flags` passes; if `--url` is still documented anywhere, it names the page |
| Site build | link and anchor checks stay green |
| Commit | ships with the code change that renamed the flag |

The upgrade note is the row people skip. A rename is a breaking change, and one
line under "upgrading" is cheaper than the first bug report.
