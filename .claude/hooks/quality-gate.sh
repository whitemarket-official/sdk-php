#!/usr/bin/env bash
# Stop hook: deterministic check of CLAUDE.md rule 0 (quality checks before a claim).
#
# Rule 0 otherwise relies on model compliance: "if *.php was edited, run the
# checks". OWASP LLM Top 10 2026 names this failure class (LLM07 Misinformation,
# Scenario #7 "Fabricated Task Completion"): the agent reports the work as done
# and verified while no run happened. Context hygiene 9 says such things are
# checked by a hook, not the model.
#
# Trigger: an Edit/Write of *.php in THIS session (a fact from the transcript, not
# from git diff: the branch may hold changes that do not belong to the session).
#
# Checks ORDER, not result: the last static-analysis run and the last test run
# must come AFTER the last PHP edit. The hook deliberately does not parse the
# result: phpstan/phpunit output formats change, and a fragile parser is worse
# than none. A run inside a subagent (qc-runner) is visible: sidechain lines are
# in the same transcript file.
#
# Counts as static analysis: phpstan, make stan, make check.
# Counts as tests: phpunit, make test, make coverage, make check.
#
# Loop-safe: stop_hook_active + marker (session|qc), once per session.
# State: var/claude/quality-gate-done (git-ignored through var/claude/.gitignore).

set -u

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null)}" 2>/dev/null || exit 0

input="$(cat 2>/dev/null || true)"
case "$input" in *'"stop_hook_active": true'*|*'"stop_hook_active":true'*) exit 0 ;; esac

transcript="$(printf '%s' "$input" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("transcript_path",""))' 2>/dev/null)"
session="$(printf '%s' "$input" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session_id","") or "nosession")' 2>/dev/null)"
[ -z "$transcript" ] || [ ! -f "$transcript" ] && exit 0

read -r edit_php run_static run_tests < <(python3 - "$transcript" <<'PY' 2>/dev/null
import json, re, sys

STATIC = re.compile(r'phpstan|make\s+(stan|check)\b|composer\s+(stan|check)\b')
TESTS = re.compile(r'phpunit|paratest|make\s+(test|coverage|check)\b|composer\s+(test|check)\b')

edit_php = run_static = run_tests = 0
with open(sys.argv[1], encoding='utf-8', errors='replace') as fh:
    for n, line in enumerate(fh, 1):
        if '"tool_use"' not in line:
            continue
        try:
            content = json.loads(line)['message']['content']
        except Exception:
            continue
        if not isinstance(content, list):
            continue
        for c in content:
            if not isinstance(c, dict) or c.get('type') != 'tool_use':
                continue
            name = c.get('name') or ''
            inp = c.get('input') or {}
            if not isinstance(inp, dict):
                continue
            if name == 'Bash':
                cmd = inp.get('command') or ''
                if STATIC.search(cmd):
                    run_static = n
                if TESTS.search(cmd):
                    run_tests = n
            elif name in ('Edit', 'Write', 'MultiEdit', 'NotebookEdit'):
                if str(inp.get('file_path') or '').endswith('.php'):
                    edit_php = n
print(edit_php, run_static, run_tests)
PY
)
[ -z "${edit_php:-}" ] && exit 0
[ "$edit_php" -eq 0 ] && exit 0

missing=""
[ "$run_static" -lt "$edit_php" ] && missing="static analysis (make stan)"
[ "$run_tests" -lt "$edit_php" ] && missing="${missing:+$missing and }tests (make test)"
[ -z "$missing" ] && exit 0

state_dir="var/claude"
done_file="$state_dir/quality-gate-done"
grep -qxF "${session}|qc" "$done_file" 2>/dev/null && exit 0
mkdir -p "$state_dir" 2>/dev/null
[ -f "$state_dir/.gitignore" ] || printf '*\n' >"$state_dir/.gitignore" 2>/dev/null
printf '%s|qc\n' "$session" >>"$done_file"
if [ "$(wc -l <"$done_file" 2>/dev/null || echo 0)" -gt 100 ]; then
  tail -n 50 "$done_file" >"${done_file}.tmp" 2>/dev/null && mv "${done_file}.tmp" "$done_file"
fi

reason="STOP: CLAUDE.md rule 0: *.php files were edited in this session, but ${missing} did not run after the last edit.

Claiming readiness is forbidden. Call Skill(quality-checks) and take the steps to green (delegate the run to the qc-runner subagent: the output is noisy). If the edit was only in tests or config and you consider the run redundant, say so in one line as a decision, not by omission.
This reminder will not repeat in this session."

printf '{"decision":"block","reason":%s}\n' \
  "$(printf '%s' "$reason" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null || printf '"%s"' "$reason")"
exit 0
