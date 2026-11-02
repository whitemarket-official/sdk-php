#!/usr/bin/env bash
# Stop hook: catches silenced checks in the diff. Suppressing an analyser error
# hides the problem just like a guard that only removes a crash (CLAUDE.md rule 15,
# rule 0b "a guard that only hides a failure is not a fix").
#
# Signals:
#   A. An added line with a suppression without BOTH an issue reference and a
#      reason on the same or the previous line. Suppressions:
#        PHP (src/, tests/, fixtures/, tools/ *.php): @phpstan-ignore (also -line,
#        -next-line), @psalm-suppress, @SuppressWarnings, @codeCoverageIgnore*,
#        @infection-ignore-all, @noinspection;
#        infection.json5: added lines with an `ignore*` key or a `Class::method` entry.
#      Issue reference: `#<number>` or https://github.com/<owner>/<repo>/issues/<n>.
#      Reason: at least two words besides the tag, identifiers and the reference,
#      on the same line or on a comment line directly above. Format:
#        // @phpstan-ignore argument.type (Sailor result type is too wide, #12)
#        // Generated union lacks a discriminator, see #12
#        // @phpstan-ignore-next-line
#   B. Added lines in phpstan-baseline.neon. New violations do not go into the
#      baseline; regeneration is planned, in a separate commit.
#
# php-cs-fixer has no inline ignore comment, so there is nothing to catch for it.
#
# The trigger is DETERMINISTIC (git diff HEAD + untracked). The hook does not decide
# whether a suppression is legitimate; it forces it to be justified or removed.
# Existing suppressions are not touched, only added lines.
#
# Loop-safe: stop_hook_active + marker (session|report hash). A new suppression in
# the same session changes the hash and blocks again; the same set blocks once.
# State: var/claude/suppress-check-done (git-ignored through var/claude/.gitignore).

set -u

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null)}" 2>/dev/null || exit 0

input="$(cat 2>/dev/null || true)"
case "$input" in *'"stop_hook_active": true'*|*'"stop_hook_active":true'*) exit 0 ;; esac

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || exit 0

SUPPRESS='@phpstan-ignore|@psalm-suppress|@SuppressWarnings|@codeCoverageIgnore|@infection-ignore-all|@noinspection'
INFECTION_IGNORE='[Ii]gnore|::'
TICKET='#[0-9]+|https://github[.]com/[^ ]+/issues/[0-9]+'
PHP_PATHS=(':(glob)src/**/*.php' ':(glob)tests/**/*.php' ':(glob)fixtures/**/*.php' ':(glob)tools/**/*.php')
INFECTION_CFG=infection.json5
BASELINES=(phpstan-baseline.neon)

# Shared awk helpers: does a line pair carry an issue reference and a reason?
AWK_LIB='
function is_comment(s) { return s ~ /^[ \t]*(\/\/|#[^\[]|\/\*|\*)/ }
function reason_words(s,   t, n, i, w, c, lw) {
  t = s
  gsub(/https?:\/\/[^ \t]+/, " ", t)
  gsub(/#[0-9]+/, " ", t)
  gsub(/@[A-Za-z][A-Za-z0-9_-]*/, " ", t)
  gsub(/[A-Za-z0-9_]+[.:]+[A-Za-z0-9_.:]+/, " ", t)
  n = split(t, w, /[^A-Za-z]+/)
  c = 0
  for (i = 1; i <= n; i++) {
    lw = tolower(w[i])
    if (length(lw) < 3) continue
    if (lw ~ /^(see|issue|issues|todo|fixme|ref|refs|github|http|https|the|and|for|ignore|line|next)$/) continue
    c++
  }
  return c
}
function justified(cur, prev,   words) {
  if (cur !~ tk && prev !~ tk) return 0
  words = reason_words(cur)
  if (is_comment(prev)) words += reason_words(prev)
  return words >= 2
}
'

# --- Signal A -----------------------------------------------------------------
# Tracked: unified diff with one line of context, to see the previous line
# (a reason comment above a docblock tag). Prints `path:line: text`.
scan_diff() { # $1=regex, rest=pathspecs
  local re="$1"; shift
  git diff HEAD -U1 --no-color -- "$@" 2>/dev/null | awk -v re="$re" -v tk="$TICKET" "$AWK_LIB"'
    /^\+\+\+ / { file = substr($0, 7); next }
    /^@@/      { split($3, a, ","); line = substr(a[1], 2) + 0; prev = ""; next }
    /^-/       { next }
    /^[+ ]/    {
      text = substr($0, 2)
      if (substr($0, 1, 1) == "+" && text ~ re && !justified(text, prev)) {
        t = text; sub(/^[ \t]+/, "", t); printf "%s:%d: %s\n", file, line, t
      }
      prev = text; line++
    }'
}

scan_file() { # $1=regex $2=file: every line counts as added (untracked file)
  awk -v re="$1" -v tk="$TICKET" -v file="$2" "$AWK_LIB"'
    $0 ~ re && !justified($0, prev) { t = $0; sub(/^[ \t]+/, "", t); printf "%s:%d: %s\n", file, NR, t }
    { prev = $0 }' "$2" 2>/dev/null
}

tracked="$(scan_diff "$SUPPRESS" "${PHP_PATHS[@]}")"
tracked_inf="$(scan_diff "$INFECTION_IGNORE" "$INFECTION_CFG")"

untracked=""
while IFS= read -r f; do
  [ -z "$f" ] && continue
  untracked="${untracked}$(scan_file "$SUPPRESS" "$f")
"
done < <(git ls-files --others --exclude-standard -- "${PHP_PATHS[@]}" 2>/dev/null)

if [ -n "$(git ls-files --others --exclude-standard -- "$INFECTION_CFG" 2>/dev/null)" ]; then
  untracked="${untracked}$(scan_file "$INFECTION_IGNORE" "$INFECTION_CFG")
"
fi

suppress_report="$(printf '%s\n%s\n%s\n' "$tracked" "$tracked_inf" "$untracked" | grep -v '^$' || true)"

# --- Signal B -----------------------------------------------------------------
baseline_report="$(git diff HEAD --numstat -- "${BASELINES[@]}" 2>/dev/null | awk '$1 > 0 { printf "%s (+%s)\n", $3, $1 }')"
for b in "${BASELINES[@]}"; do
  if [ -n "$(git ls-files --others --exclude-standard -- "$b" 2>/dev/null)" ] && [ -s "$b" ]; then
    baseline_report="${baseline_report:+$baseline_report
}$b (+$(wc -l <"$b" | tr -d ' '), new file)"
  fi
done

[ -z "$suppress_report" ] && [ -z "$baseline_report" ] && exit 0

# --- Gate: once per session per concrete set of findings ----------------------
state_dir="var/claude"
done_file="$state_dir/suppress-check-done"
session="$(printf '%s' "$input" | jq -r '.session_id // empty' 2>/dev/null)"
[ -z "$session" ] && session="nosession"
digest="$(printf '%s\n%s' "$suppress_report" "$baseline_report" | shasum 2>/dev/null | cut -c1-12)"
key="${session}|${digest}"

if [ -f "$done_file" ] && grep -qxF "$key" "$done_file" 2>/dev/null; then
  exit 0
fi
mkdir -p "$state_dir" 2>/dev/null
[ -f "$state_dir/.gitignore" ] || printf '*\n' >"$state_dir/.gitignore" 2>/dev/null
printf '%s\n' "$key" >>"$done_file"
if [ "$(wc -l <"$done_file" 2>/dev/null || echo 0)" -gt 100 ]; then
  tail -n 50 "$done_file" >"${done_file}.tmp" 2>/dev/null && mv "${done_file}.tmp" "$done_file"
fi

reason=""

if [ -n "$suppress_report" ]; then
  reason="WARN: CLAUDE.md rule 15: new suppressions in the diff without an issue reference and a reason:
$(printf '%s\n' "$suppress_report" | head -20 | sed 's/^/    /')

A silenced error hides the problem like a guard that only removes a crash. For each line, one of two:
  (a) remove the suppression and fix the cause (type, decomposition, test);
  (b) the suppression is needed (analyser false positive, generated-code shape, pre-existing code). Then a reason and a GitHub issue reference on the same or the previous line: \`// @phpstan-ignore argument.type (Sailor result type is too wide, #12)\`, and one line to the user.
"
fi

if [ -n "$baseline_report" ]; then
  reason="${reason}
WARN: lines added to the baseline:
$(printf '%s\n' "$baseline_report" | sed 's/^/    /')

New violations do not go into the baseline (CLAUDE.md rule 15). Unless this is a planned regeneration in a separate commit, revert the baseline and fix the violation. If it is planned, tell the user explicitly.
"
fi

printf '{"decision":"block","reason":%s}\n' \
  "$(printf '%s' "$reason" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null || printf '"%s"' "$reason")"
exit 0
