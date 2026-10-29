# AGENTS.md

## Docs upkeep

- Skill: `lmt-skills-docusaurus-sync` (installed under `.agents/skills/`)
- Map: `docs-map.yaml` — truth sources for flags, paths, generated pages
- Gate: `bash .agents/skills/lmt-skills-docusaurus-sync/scripts/docs-drift.sh --check`
- Regenerate with the project's `generate` command before the gate
- A code change that alters a documented fact ships with its page update

The Docusaurus site is the repository root (content in `docs/`) and its `docs`
job in `.github/workflows/ci.yml` runs the gate. `docs-map.yaml` names
`heic-fix.sh --help` as the flag source and lists `--latest` under
`ignore_flags` because that one belongs to `gh release create` in the release
workflow, not to the script.
