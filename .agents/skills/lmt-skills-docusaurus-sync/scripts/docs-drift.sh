#!/usr/bin/env bash
# =================================================================================================
# -- docs-drift.sh -- read-only freshness gate for a Docusaurus docs site
# =================================================================================================
# Turns a project's docs-map.yaml into a pass/fail verdict for the facts a docs
# site can get wrong cheaply: flags, env variables, repo paths, generated blocks.
# It writes nothing, runs no generator, and needs no network.
#
# Exit: 0 clean, 1 drift, 2 usage or map error.
# =================================================================================================

set -uo pipefail
LC_ALL=C

VERSION="0.1.0"

usage () {
	cat <<'EOF'
Usage: docs-drift.sh [options]

Options:
  --repo <path>   Repository to inspect (default: current directory)
  --map <path>    Map file (default: docs-map.yaml, website/docs-map.yaml,
                  or docs/docs-map.yaml at the repo root)
  --check         Run the checks; the default
  --report        Add the advisory age report (pages older than their sources)
  --strict        Treat advisories as failures
  --quiet         Print only failures and the summary
  -h, --help      Show this help
  -V, --version   Show the version

Map keys, as a constrained YAML subset (top-level scalars, plus top-level lists
whose items are indented two spaces and start with "- "):

  site        required  directory holding docusaurus.config.*  ("." for the root)
  docs        required  content directory the site renders
  sidebar     required  sidebar file that registers every page
  env         optional  env source of truth (.env.example, schema, sample config)
  env_prefix  with env  prefix that marks a documented token as an env variable
  commands    optional  full invocations (--help included) whose output lists flags
  ignore_flags  with commands  documented flags this map cannot verify
  paths       optional  repo path prefixes docs may reference
  generated   optional  "path:marker" entries a generator owns
  generate    with generated  command that rewrites the generated blocks
  watch       optional  source paths that make a page stale (--report only)

Checks: map, flags, env, paths, generated. A "skipped" family means the map
declares no source for it. Full-line "#" comments are allowed; nested maps, flow
lists, multi-line strings, anchors, tabs, and trailing comments are rejected with
exit 2 and the line number.

Each "commands" entry is run exactly as written, from the repository root, with a
10 s timeout. Put "--help" in the entry; a non-zero exit is a map error, so a
command that needs a running service does not belong here.
EOF
}

die_usage () { printf 'docs-drift: %s\n' "$1" >&2; exit 2; }

REPO="."
MAP=""
DO_REPORT=0
STRICT=0
QUIET=0

while (( $# )); do
	case "$1" in
		--repo)   shift; REPO="${1:-}" ;;
		--map)    shift; MAP="${1:-}" ;;
		--check)  DO_REPORT=0 ;;
		--report) DO_REPORT=1 ;;
		--strict) STRICT=1 ;;
		--quiet)  QUIET=1 ;;
		-h|--help) usage; exit 0 ;;
		-V|--version) printf 'docs-drift %s\n' "$VERSION"; exit 0 ;;
		*) die_usage "unknown option: $1" ;;
	esac
	shift
done

if (( BASH_VERSINFO[0] < 4 )); then
	die_usage "bash 4 or newer is required (found ${BASH_VERSION})"
fi

[[ -d $REPO ]] || die_usage "repository not found: $REPO"
REPO="$(cd -P -- "$REPO" && pwd)"

if [[ -z $MAP ]]; then
	for candidate in "$REPO/docs-map.yaml" "$REPO/website/docs-map.yaml" "$REPO/docs/docs-map.yaml"; do
		if [[ -f $candidate ]]; then MAP="$candidate"; break; fi
	done
	[[ -n $MAP ]] || die_usage "no docs-map.yaml found in $REPO (looked at the root, website/, and docs/)"
fi
[[ -f $MAP ]] || die_usage "map not found: $MAP"

FAILURES=0
ADVISORIES=0

say () { (( QUIET )) || printf '%s\n' "$*"; }
note () { (( QUIET )) || printf '  %s\n' "$*"; }
fail () { printf '  %s\n' "$*"; FAILURES=$(( FAILURES + 1 )); }
advise () { ADVISORIES=$(( ADVISORIES + 1 )); (( QUIET )) || printf '  advisory: %s\n' "$*"; }

family_ok () { (( QUIET )) || printf 'CHECK  %-10s ok        %s\n' "$1" "${2:-}"; }
family_skip () { (( QUIET )) || printf 'CHECK  %-10s skipped   %s\n' "$1" "${2:-}"; }
family_head () { (( QUIET )) || printf 'CHECK  %-10s %s\n' "$1" "$2"; }
plural () { # <count> <singular> [<plural>]
	if (( $1 == 1 )); then printf '%d %s' "$1" "$2"
	else printf '%d %s' "$1" "${3:-${2}s}"; fi
}

declare -A SCALAR=()
declare -A LIST=()

# -- parse the constrained YAML subset; emit "kind<TAB>key<TAB>value" records
parse_map () {
	awk '
		function trim(s) { sub(/^[[:space:]]+/, "", s); sub(/[[:space:]]+$/, "", s); return s }
		function unquote(s, first, last) {
			first = substr(s, 1, 1)
			last = substr(s, length(s), 1)
			if ((first == "\"" && last == "\"") || (first == "\047" && last == "\047"))
				return substr(s, 2, length(s) - 2)
			return s
		}
		function bad(msg) { printf "E\t%d\t%s\n", NR, msg; failed = 1 }
		{
			line = $0
			sub(/\r$/, "", line)
			if (line ~ /\t/) { bad("tab characters are not allowed"); next }
			if (line ~ /^[[:space:]]*$/) next
			if (line ~ /^[[:space:]]*#/) next
			if (line ~ /^[[:space:]]*-[[:space:]]/) {
				if (listkey == "") { bad("list item outside a list key"); next }
				item = line
				sub(/^[[:space:]]*-[[:space:]]+/, "", item)
				item = trim(item)
				if (item == "") { bad("empty list item"); next }
				if (item ~ /^\[/) { bad("flow lists are not supported; use one item per line"); next }
				printf "L\t%s\t%s\n", listkey, unquote(item)
				next
			}
			if (line ~ /^[[:space:]]/) { bad("indented line that is not a two-space list item"); next }
			if (line !~ /^[A-Za-z_][A-Za-z0-9_]*:[[:space:]]*/) { bad("expected \"key: value\" or \"key:\""); next }
			key = line
			sub(/:.*/, "", key)
			value = line
			sub(/^[A-Za-z_][A-Za-z0-9_]*:/, "", value)
			value = trim(value)
			if (key in seen) { bad("duplicate key: " key); next }
			seen[key] = 1
			if (value == "") { listkey = key; printf "K\t%s\t\n", key; next }
			if (value ~ /^\[/) { bad("flow lists are not supported; use one item per line"); next }
			if (value ~ /[[:space:]]#/) { bad("trailing comments are not allowed; put the comment on its own line"); next }
			listkey = ""
			printf "S\t%s\t%s\n", key, unquote(value)
		}
		END { if (failed) exit 1 }
	' "$1"
}

map_out="$(parse_map "$MAP" 2>&1)" || {
	printf 'CHECK  %-10s FAIL\n' map
	printf '%s\n' "$map_out" | sed -n 's/^E\t\([0-9]*\)\t/  map error at line \1: /p'
	FAILURES=$(( FAILURES + 1 ))
	printf '\nSUMMARY %s — fix the map first\n' "$(plural "$FAILURES" failure)"
	exit 2
}

while IFS=$'\t' read -r kind key value; do
	[[ -n ${kind:-} ]] || continue
	case "$kind" in
		S) SCALAR["$key"]="$value" ;;
		K) LIST["$key"]="" ;;
		L) LIST["$key"]+="$value"$'\n' ;;
	esac
done <<< "$map_out"

list_items () { # <key> — newline-separated items, empty when undeclared
	local raw="${LIST[$1]:-}"
	[[ -n $raw ]] && printf '%s' "$raw"
}

REL_MAP="${MAP#"$REPO"/}"

# -- resolve docs files once
DOCS_DIR="${SCALAR[docs]:-}"
DOC_FILES=()
if [[ -n $DOCS_DIR && -d "$REPO/$DOCS_DIR" ]]; then
	while IFS= read -r -d '' f; do
		DOC_FILES+=("${f#"$REPO"/}")
	done < <(find "$REPO/$DOCS_DIR" -type f \( -name '*.md' -o -name '*.mdx' \) \
	         -not -path '*/node_modules/*' -not -path '*/.docusaurus/*' -print0 | sort -z)
fi

# -- backticked tokens outside fenced blocks, as "path:line<TAB>token"
scan_tokens () { # <regex>
	local re="$1" f
	for f in "${DOC_FILES[@]}"; do
		awk -v file="$f" -v re="$re" '
			/^[[:space:]]*(```|~~~)/ { fence = !fence; next }
			fence { next }
			{
				rest = $0
				while (match(rest, /`[^`]+`/)) {
					tok = substr(rest, RSTART + 1, RLENGTH - 2)
					if (tok ~ re) printf "%s:%d\t%s\n", file, FNR, tok
					rest = substr(rest, RSTART + RLENGTH)
				}
			}
		' "$REPO/$f"
	done
}

# =================================================================================================
# -- 1. map
# =================================================================================================
map_problems=0
map_problem () { fail "$*"; map_problems=$(( map_problems + 1 )); }

for key in site docs sidebar; do
	[[ -n ${SCALAR[$key]:-} ]] || map_problem "required key missing: $key"
done

SITE="${SCALAR[site]:-}"
if [[ -n $SITE ]]; then
	[[ -d "$REPO/$SITE" ]] || map_problem "site directory does not exist: $SITE"
	if ! compgen -G "$REPO/$SITE/docusaurus.config.*" >/dev/null; then
		map_problem "no docusaurus.config.* in $SITE"
	fi
fi

if [[ -n $DOCS_DIR ]]; then
	[[ -d "$REPO/$DOCS_DIR" ]] || map_problem "docs directory does not exist: $DOCS_DIR"
	(( ${#DOC_FILES[@]} )) || map_problem "no .md/.mdx pages under $DOCS_DIR"
fi

if [[ -n ${SCALAR[sidebar]:-} ]]; then
	[[ -f "$REPO/${SCALAR[sidebar]}" ]] || map_problem "sidebar file does not exist: ${SCALAR[sidebar]}"
fi

if [[ -n ${SCALAR[env]:-} ]]; then
	[[ -f "$REPO/${SCALAR[env]}" ]] || map_problem "env source does not exist: ${SCALAR[env]}"
	[[ -n ${SCALAR[env_prefix]:-} ]] || map_problem "env is declared without env_prefix"
fi
[[ -z ${SCALAR[env]:-} && -n ${SCALAR[env_prefix]:-} ]] && map_problem "env_prefix is declared without env"

if [[ -n $(list_items paths) ]]; then
	while IFS= read -r p; do
		[[ -n $p ]] || continue
		[[ -e "$REPO/$p" ]] || map_problem "paths entry does not exist: $p"
	done < <(list_items paths)
fi

if [[ -n $(list_items ignore_flags) ]]; then
	if [[ -z $(list_items commands) ]]; then
		map_problem "ignore_flags is declared without commands"
	fi
	while IFS= read -r f; do
		[[ -n $f ]] || continue
		[[ $f == --* ]] || map_problem "ignore_flags entry is not a long flag: $f"
	done < <(list_items ignore_flags)
fi

has_generated=0
if [[ -n $(list_items generated) ]]; then
	has_generated=1
	while IFS= read -r entry; do
		[[ -n $entry ]] || continue
		gpath="${entry%:*}"
		if [[ -z $gpath || $gpath == "$entry" ]]; then
			map_problem "generated entry is not path:marker: $entry"; continue
		fi
		[[ -f "$REPO/$gpath" ]] || map_problem "generated page does not exist: $gpath"
	done < <(list_items generated)
	[[ -n ${SCALAR[generate]:-} ]] || map_problem "generated is declared without generate"
fi

# -- commands must actually answer; a failing one is a map defect, not drift
declare -a HELP_OUTPUT=()
declare -a HELP_LABEL=()
if [[ -n $(list_items commands) ]]; then
	while IFS= read -r cmd; do
		[[ -n $cmd ]] || continue
		if command -v timeout >/dev/null 2>&1; then
			out="$(cd "$REPO" && timeout 10 bash -c "$cmd" 2>&1)"
		else
			out="$(cd "$REPO" && bash -c "$cmd" 2>&1)"
		fi
		rc=$?
		if (( rc != 0 )); then
			map_problem "commands entry exited $rc on its own invocation: $cmd"
			HELP_OUTPUT+=("")
		else
			HELP_OUTPUT+=("$out")
		fi
		HELP_LABEL+=("$cmd")
	done < <(list_items commands)
fi

if (( map_problems > 0 )); then
	family_head map "FAIL      $map_problems problem(s) in $REL_MAP"
	printf '\nSUMMARY %s — fix the map first\n' "$(plural "$FAILURES" map\ failure)"
	exit 2
fi
family_ok map "$REL_MAP, ${#DOC_FILES[@]} page(s), site $SITE"

# =================================================================================================
# -- 2. flags
# =================================================================================================
if (( ${#HELP_OUTPUT[@]} == 0 )); then
	family_skip flags "no commands declared"
else
	declare -A HELP_FLAG=()
	for out in "${HELP_OUTPUT[@]}"; do
		while IFS= read -r flag; do
			[[ -n $flag ]] && HELP_FLAG["$flag"]=1
		done < <(printf '%s\n' "$out" | grep -oE -- '--[A-Za-z0-9][A-Za-z0-9-]*' | sort -u)
	done

	declare -A DOC_FLAG=()
	while IFS=$'\t' read -r where tok; do
		[[ -n ${tok:-} ]] || continue
		# "--jobs N" and "--jobs=N" document the same flag as "--jobs"
		tok="${tok%%[ =]*}"
		DOC_FLAG["$tok"]+="$where "
	done < <(scan_tokens '^--[A-Za-z0-9][A-Za-z0-9-]*([ =]|$)')

	# -- flags documented for a tool the map cannot run (a root-only installer,
	# -- apt, gh, ...). Excluded from the stale check, but tracked so a stale
	# -- entry in the list is still visible.
	declare -A IGNORE_FLAG=()
	while IFS= read -r tok; do
		[[ -n $tok ]] && IGNORE_FLAG["$tok"]=0
	done < <(list_items ignore_flags)

	flag_fail=0
	for tok in $(printf '%s\n' "${!DOC_FLAG[@]}" | sort); do
		if [[ -n ${IGNORE_FLAG[$tok]+set} ]]; then
			IGNORE_FLAG["$tok"]=1
			continue
		fi
		[[ -n ${HELP_FLAG[$tok]:-} ]] && continue
		if (( flag_fail == 0 )); then family_head flags FAIL; fi
		fail "$tok is documented but not in any mapped --help (${DOC_FLAG[$tok]% }); map its command, or list it under ignore_flags"
		flag_fail=1
	done
	for tok in $(printf '%s\n' "${!IGNORE_FLAG[@]}" | sort); do
		(( IGNORE_FLAG[$tok] )) && continue
		advise "ignore_flags entry $tok is not documented on any page"
	done
	for tok in $(printf '%s\n' "${!HELP_FLAG[@]}" | sort); do
		[[ -n ${DOC_FLAG[$tok]:-} ]] && continue
		advise "$tok appears in --help but no page documents it"
	done
	if (( flag_fail == 0 )); then
		family_ok flags "$(plural "${#DOC_FLAG[@]}" documented\ flag) across $(plural "${#HELP_OUTPUT[@]}" command)"
	fi
fi

# =================================================================================================
# -- 3. env
# =================================================================================================
ENV_FILE="${SCALAR[env]:-}"
if [[ -z $ENV_FILE ]]; then
	family_skip env "no env source declared"
else
	PREFIX="${SCALAR[env_prefix]}"
	declare -A ENV_KEY=()
	while IFS= read -r key; do
		[[ -n $key ]] && ENV_KEY["$key"]=1
	done < <(awk '
		/^[[:space:]]*#?[[:space:]]*[A-Za-z_][A-Za-z0-9_]*[[:space:]]*=/ {
			line = $0
			sub(/^[[:space:]]*#?[[:space:]]*/, "", line)
			sub(/[[:space:]]*=.*/, "", line)
			print line
		}' "$REPO/$ENV_FILE" | sort -u)

	escaped_prefix="$(printf '%s' "$PREFIX" | sed 's/[.[\*^$()+?{}|]/\\&/g')"
	declare -A DOC_ENV=()
	while IFS=$'\t' read -r where tok; do
		[[ -n ${tok:-} ]] || continue
		DOC_ENV["$tok"]+="$where "
	done < <(scan_tokens "^${escaped_prefix}[A-Z0-9_]+$")

	env_fail=0
	for tok in $(printf '%s\n' "${!DOC_ENV[@]}" | sort); do
		[[ -n ${ENV_KEY[$tok]:-} ]] && continue
		if (( env_fail == 0 )); then family_head env FAIL; fi
		fail "$tok is documented but missing from $ENV_FILE (${DOC_ENV[$tok]% })"
		env_fail=1
	done
	for tok in $(printf '%s\n' "${!ENV_KEY[@]}" | sort); do
		[[ -n ${DOC_ENV[$tok]:-} ]] && continue
		advise "$tok is defined in $ENV_FILE but no page documents it"
	done
	# shellcheck disable=SC2004
	if (( env_fail == 0 )); then
		family_ok env "$(plural "${#DOC_ENV[@]}" documented\ variable), $(plural "${#ENV_KEY[@]}" defined\ variable)"
	fi
fi

# =================================================================================================
# -- 4. paths
# =================================================================================================
if [[ -z $(list_items paths) ]]; then
	family_skip paths "no path prefixes declared"
else
	declare -A PATH_TOKEN=()
	path_checked=0
	path_prefixes="$(list_items paths | grep -c .)"
	while IFS=$'\t' read -r where tok; do
		[[ -n ${tok:-} ]] || continue
		[[ $tok == *'*'* || $tok == *'$'* || $tok == *'{'* || $tok == *'<'* || $tok == *'://'* || $tok == *' '* ]] && continue
		matched=0
		while IFS= read -r p; do
			[[ -n $p ]] || continue
			[[ $tok == "$p"* ]] && { matched=1; break; }
		done < <(list_items paths)
		(( matched )) || continue
		[[ $tok == */ ]] && continue
		if [[ -e "$REPO/$tok" ]]; then
			path_checked=$(( path_checked + 1 ))
			continue
		fi
		PATH_TOKEN["$tok"]+="$where "
	done < <(scan_tokens '^[A-Za-z0-9._][^ ]*$')

	if (( ${#PATH_TOKEN[@]} )); then
		family_head paths FAIL
		for tok in $(printf '%s\n' "${!PATH_TOKEN[@]}" | sort); do
			fail "$tok is referenced but does not exist (${PATH_TOKEN[$tok]% })"
		done
	else
		family_ok paths "$(plural "$path_checked" reference) resolve across $(plural "$path_prefixes" prefix prefixes)"
	fi
fi

# =================================================================================================
# -- 5. generated
# =================================================================================================
if (( has_generated == 0 )); then
	family_skip generated "no generated blocks declared"
else
	gen_fail=0
	while IFS= read -r entry; do
		[[ -n $entry ]] || continue
		gpath="${entry%:*}"
		marker="${entry##*:}"
		begin="<!-- BEGIN GENERATED: $marker -->"
		end="<!-- END GENERATED: $marker -->"
		begin_line="$(grep -nF -- "$begin" "$REPO/$gpath" | cut -d: -f1 | head -n1)"
		end_line="$(grep -nF -- "$end" "$REPO/$gpath" | cut -d: -f1 | head -n1)"
		begin_count="$(grep -cF -- "$begin" "$REPO/$gpath")"
		end_count="$(grep -cF -- "$end" "$REPO/$gpath")"
		problem=""
		if (( begin_count == 0 )); then problem="missing BEGIN marker for '$marker'"
		elif (( end_count == 0 )); then problem="missing END marker for '$marker'"
		elif (( begin_count > 1 )); then problem="doubled BEGIN marker for '$marker' ($begin_count)"
		elif (( end_count > 1 )); then problem="doubled END marker for '$marker' ($end_count)"
		elif (( begin_line > end_line )); then problem="END marker for '$marker' comes before BEGIN"
		fi
		if [[ -n $problem ]]; then
			(( gen_fail == 0 )) && family_head generated FAIL
			fail "$gpath: $problem"
			gen_fail=1
		fi
	done < <(list_items generated)

	(( gen_fail == 0 )) && family_ok generated "$(plural "$(list_items generated | grep -c .)" block) balanced"

	# advisory only: the declared generator may be a repo script, not an npm one
	if [[ -f "$REPO/package.json" ]]; then
		gen_word="${SCALAR[generate]%% *}"
		gen_word="${gen_word##*/}"
		if ! grep -qF -- "$gen_word" "$REPO/package.json"; then
			advise "generate command '$gen_word' is not a package.json script (fine if it is a repo script)"
		fi
	fi
fi

# =================================================================================================
# -- 6. age report (advisory)
# =================================================================================================
if (( DO_REPORT )); then
	if ! git -C "$REPO" rev-parse --git-dir >/dev/null 2>&1; then
		family_skip report "not a git repository"
	else
		watch_ts=0
		while IFS= read -r w; do
			[[ -n $w ]] || continue
			t="$(git -C "$REPO" log -1 --format=%ct -- "$w" 2>/dev/null)"
			[[ -n $t ]] && (( t > watch_ts )) && watch_ts=$t
		done < <(list_items watch)

		if (( watch_ts == 0 )); then
			family_skip report "no watch paths declared"
		else
			stale=0
			for f in "${DOC_FILES[@]}"; do
				page_ts="$(git -C "$REPO" log -1 --format=%ct -- "$f" 2>/dev/null)"
				[[ -n $page_ts ]] || continue
				if (( watch_ts > page_ts )); then
					days=$(( (watch_ts - page_ts) / 86400 ))
					(( stale == 0 )) && family_head report "stale"
					say "  $f is ${days}d older than the newest watched source"
					stale=$(( stale + 1 ))
				fi
			done
			(( stale == 0 )) && family_ok report "no page older than its sources"
		fi
	fi
fi

# =================================================================================================
# -- summary
# =================================================================================================
if (( FAILURES > 0 )); then
	printf 'SUMMARY %s, %s\n' "$(plural "$FAILURES" failure)" "$(plural "$ADVISORIES" advisory advisories)"
	exit 1
fi
if (( STRICT && ADVISORIES > 0 )); then
	printf 'SUMMARY 0 failures, %s — --strict treats them as failures\n' "$(plural "$ADVISORIES" advisory advisories)"
	exit 1
fi
printf 'SUMMARY clean (%s)\n' "$(plural "$ADVISORIES" advisory advisories)"
exit 0
