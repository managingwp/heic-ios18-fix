#!/usr/bin/env bash
# =================================================================================================
# -- tests/run.sh -- prove docs-drift.sh fires, passes, and never writes
# =================================================================================================
# Builds throwaway fixtures under a temp directory, one per case. Each case gets a
# fresh tree, so a mutation in one case cannot leak into another. Every case also
# checksums the tree before and after the run: the checker is read-only, and this
# is where that claim is enforced rather than asserted in prose.
#
# Usage: bash tests/run.sh
# Exit: 0 when every case passes, 1 otherwise.
# =================================================================================================

set -uo pipefail
LC_ALL=C

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRIFT="$HERE/../scripts/docs-drift.sh"
[[ -f $DRIFT ]] || { echo "docs-drift.sh not found beside tests/" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
OUT="$WORK/out.txt"

ok () { PASS=$(( PASS + 1 )); printf 'ok   %s\n' "$*"; }
not_ok () { FAIL=$(( FAIL + 1 )); printf 'FAIL %s\n' "$*"; [[ -s $OUT ]] && sed 's/^/     | /' "$OUT"; }

tree_sum () { # <dir> — sha256 of every file except .git
	(
		cd "$1" || exit 1
		find . \( -path ./.git -o -path './.git/*' \) -prune -o -type f -print0 |
			sort -z | xargs -0 sha256sum
	)
}

run () { # <dir> [args...] — capture combined output, echo the exit status
	local dir="$1"
	shift
	bash "$DRIFT" --repo "$dir" "$@" >"$OUT" 2>&1
	echo $?
}

expect_exit () { # <want> <name> <dir> [args...]
	local want="$1" name="$2" dir="$3" got
	shift 3
	got="$(run "$dir" "$@")"
	if [[ $got == "$want" ]]; then
		ok "$name (exit $got)"
	else
		not_ok "$name: expected exit $want, got $got"
	fi
}

expect_in_output () { # <name> <literal>
	if grep -qF -- "$2" "$OUT"; then
		ok "$1"
	else
		not_ok "$1: output does not contain '$2'"
	fi
}

expect_not_in_output () { # <name> <literal>
	if grep -qF -- "$2" "$OUT"; then
		not_ok "$1: output unexpectedly contains '$2'"
	else
		ok "$1"
	fi
}

expect_match () { # <name> <extended-regex>
	if grep -qE -- "$2" "$OUT"; then
		ok "$1"
	else
		not_ok "$1: output does not match '$2'"
	fi
}

# -- a clean, self-consistent fixture: passes with one advisory
new_fixture () {
	local dir="$1"
	mkdir -p "$dir/bin" "$dir/docs/guides" "$dir/docs/reference"

	cat > "$dir/docs-map.yaml" <<'YAML'
# fixture map
site: .
docs: docs
sidebar: sidebars.ts
env: .env.example
env_prefix: FIX_
commands:
  - ./bin/tool --help
paths:
  - bin/
  - docs/
generated:
  - docs/reference/cli.md:cli
generate: ./bin/gen-help.sh
watch:
  - bin/
YAML

	cat > "$dir/docusaurus.config.js" <<'JS'
module.exports = { title: 'fixture', url: 'https://example.com', baseUrl: '/' };
JS
	printf 'module.exports = { docs: ["index"] };\n' > "$dir/sidebars.ts"
	printf 'FIX_TOKEN=\nFIX_PORT=8080\n' > "$dir/.env.example"

	cat > "$dir/bin/tool" <<'SH'
#!/usr/bin/env bash
echo "Usage: tool [--port N] [--verbose]"
SH
	chmod +x "$dir/bin/tool"
	printf '#!/usr/bin/env bash\n' > "$dir/bin/gen-help.sh"

	cat > "$dir/docs/index.md" <<'MD'
---
title: Home
description: Fixture home.
---

Run `bin/tool --port 8080` with `FIX_TOKEN` set.
MD

	cat > "$dir/docs/guides/start.md" <<'MD'
---
title: Start
description: Fixture start.
---

Use `--port`, and see `docs/guides/start.md`.
MD

	cat > "$dir/docs/reference/cli.md" <<'MD'
---
title: CLI
description: Generated flags.
---

<!-- BEGIN GENERATED: cli -->
| Flag | Meaning |
|------|---------|
| `--port` | Port |
<!-- END GENERATED: cli -->
MD
}

# -- run one mutation against a fresh fixture, then prove nothing was written
mutate_case () { # <name> <want-exit> <expected-output> <mutation-snippet> [extra args...]
	local name="$1" want="$2" needle="$3" mutation="$4"
	shift 4
	local dir="$WORK/$name" before after got
	new_fixture "$dir"
	(
		cd "$dir" || exit 1
		eval "$mutation"
	)
	# checksum after the mutation: the run itself must change nothing
	before="$(tree_sum "$dir")"
	got="$(run "$dir" "$@")"
	after="$(tree_sum "$dir")"

	if [[ $got == "$want" ]]; then
		ok "$name (exit $got)"
	else
		not_ok "$name: expected exit $want, got $got"
	fi
	if grep -qF -- "$needle" "$OUT"; then
		ok "$name reports '$needle'"
	else
		not_ok "$name: output does not contain '$needle'"
	fi
	if [[ $before == "$after" ]]; then
		ok "$name leaves the tree untouched"
	else
		not_ok "$name modified the tree it inspected"
	fi
}

echo "# docs-drift.sh test suite"

# -- 1. clean fixture
new_fixture "$WORK/clean"
expect_exit 0 "clean fixture passes" "$WORK/clean"
expect_in_output "clean fixture reports the one advisory" "FIX_PORT is defined"
grep -q '^CHECK  map   *ok' "$OUT" && ok "map family reports ok" || not_ok "map family did not report ok"
grep -q '^CHECK  flags' "$OUT" && ok "flags family ran" || not_ok "flags family did not run"

# -- 2. quiet mode hides the passing families
expect_exit 0 "quiet clean exits 0" "$WORK/clean" --quiet
expect_not_in_output "quiet hides CHECK lines" "CHECK  map"
expect_in_output "quiet keeps the summary" "SUMMARY clean"

# -- 3. stale flag
mutate_case "stale-flag" 1 "--port is documented but not in any mapped --help" \
	"printf '#!/usr/bin/env bash\\necho \"Usage: tool [--verbose]\"\\n' > bin/tool"
expect_in_output "stale flag names the page" "docs/guides/start.md"

# -- 4. undocumented flag under --strict
mutate_case "strict-advisory" 1 "--strict treats them as failures" "true" --strict

# -- 4b. a placeholder after the flag documents the flag itself
mutate_case "flag-placeholder" 0 "2 documented flags" \
	"printf -- '\\\\nUse \`--verbose N\` to raise output.\\\\n' >> docs/index.md"
expect_not_in_output "flag placeholder clears the advisory" "advisory: --verbose appears in --help"

# -- 4c. a flag documented for a tool the map cannot run
mutate_case "ignore-flag" 0 "2 documented flags" \
	"printf 'ignore_flags:\\n  - --gh-only\\n' >> docs-map.yaml
	 printf -- '\\\\nThe workflow passes \`--gh-only\`.\\\\n' >> docs/index.md"

# -- 4d. an ignore entry that no page mentions is an advisory
mutate_case "ignore-flag-unused" 0 "ignore_flags entry --gh-only is not documented" \
	"printf 'ignore_flags:\\n  - --gh-only\\n' >> docs-map.yaml"

# -- 4e. ignore_flags without commands is a map defect
new_fixture "$WORK/ignore-no-commands"
sed -i '/^commands:$/,+1d' "$WORK/ignore-no-commands/docs-map.yaml"
printf 'ignore_flags:\n  - --gh-only\n' >> "$WORK/ignore-no-commands/docs-map.yaml"
expect_exit 2 "ignore_flags without commands exits 2" "$WORK/ignore-no-commands"
expect_in_output "ignore_flags without commands is named" "ignore_flags is declared without commands"

# -- 5. env variable removed from the source
mutate_case "dead-env" 1 "FIX_TOKEN is documented but missing from .env.example" \
	"printf 'FIX_PORT=8080\\n' > .env.example"

# -- 6. referenced path deleted
mutate_case "dead-path" 1 "bin/gone.sh is referenced but does not exist" \
	"printf -- '---\\ntitle: Start\\ndescription: Fixture start.\\n---\\n\\nSee \`bin/gone.sh\`.\\n' > docs/guides/start.md"

# -- 7. missing END marker
mutate_case "marker-missing" 1 "missing END marker for 'cli'" \
	"sed -i '/END GENERATED: cli/d' docs/reference/cli.md"

# -- 8. doubled BEGIN marker
mutate_case "marker-doubled" 1 "doubled BEGIN marker for 'cli'" \
	"sed -i 's|<!-- BEGIN GENERATED: cli -->|<!-- BEGIN GENERATED: cli -->\\n<!-- BEGIN GENERATED: cli -->|' docs/reference/cli.md"

# -- 9. map defects exit 2
new_fixture "$WORK/map-nested"
printf 'site: .\ndocs:\n  nested: yes\n' > "$WORK/map-nested/docs-map.yaml"
before="$(tree_sum "$WORK/map-nested")"
expect_exit 2 "nested map exits 2" "$WORK/map-nested"
expect_in_output "nested map names the line" "map error at line 3"
[[ $before == "$(tree_sum "$WORK/map-nested")" ]] && ok "nested map case is read-only" || not_ok "nested map case wrote to the tree"

new_fixture "$WORK/map-missing-key"
sed -i '/^docs:/d' "$WORK/map-missing-key/docs-map.yaml"
expect_exit 2 "missing required key exits 2" "$WORK/map-missing-key"
expect_in_output "missing key is named" "required key missing: docs"

new_fixture "$WORK/map-bad-command"
cat > "$WORK/map-bad-command/bin/tool" <<'SH'
#!/usr/bin/env bash
exit 3
SH
chmod +x "$WORK/map-bad-command/bin/tool"
expect_exit 2 "failing --help is a map error" "$WORK/map-bad-command"
expect_in_output "failing command names the invocation" "commands entry exited 3"

# -- 10. age report on a real git history
AGE="$WORK/age"
new_fixture "$AGE"
(
	cd "$AGE" || exit 1
	git init -q .
	git config user.email "test@example.com"
	git config user.name "Test"
	git add -A
	GIT_AUTHOR_DATE="2026-01-01T00:00:00Z" GIT_COMMITTER_DATE="2026-01-01T00:00:00Z" \
		git commit -q -m "docs and first tool"
	cat > bin/tool <<'SH'
#!/usr/bin/env bash
echo "Usage: tool [--port N] [--verbose] [--json]"
SH
	chmod +x bin/tool
	git add bin/tool
	GIT_AUTHOR_DATE="2026-06-01T00:00:00Z" GIT_COMMITTER_DATE="2026-06-01T00:00:00Z" \
		git commit -q -m "tool gains a flag"
)
expect_exit 0 "report exits 0" "$AGE" --report
expect_in_output "report names an aged page" "is 151d older than the newest watched source"
expect_match "report family ran" '^CHECK  report +stale'

expect_exit 0 "report on a non-git tree is skipped" "$WORK/clean" --report
expect_in_output "non-git report says skipped" "not a git repository"

# -- 11. usage errors
expect_exit 2 "missing repository exits 2" "$WORK/does-not-exist"
expect_exit 2 "unknown option exits 2" "$WORK/clean" --nonsense

new_fixture "$WORK/no-map"
rm "$WORK/no-map/docs-map.yaml"
expect_exit 2 "missing map exits 2" "$WORK/no-map"
expect_in_output "missing map names the candidates" "no docs-map.yaml found"

echo
printf '%d case(s): %d passed, %d failed\n' "$(( PASS + FAIL ))" "$PASS" "$FAIL"
(( FAIL == 0 )) || exit 1
exit 0
