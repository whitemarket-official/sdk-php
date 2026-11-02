#!/usr/bin/env bash
# Regression tests for the hooks that answer with JSON/text on stdout (block /
# additionalContext / injected reminder) rather than an exit code:
#   untrusted-output.sh (PostToolUse)       hidden characters in external output
#   quality-gate.sh     (Stop)              rule 0 without relying on the model's memory
#   session-size.sh     (Stop)              handoff reminder by session size
#   session-cap.sh      (UserPromptSubmit)  hard session ceiling
#   style-reinject.sh   (UserPromptSubmit)  output-style reminder
#   statusline.sh       (statusLine)        smoke test
# They do not fit hooks-test.sh, whose chk() checks rc=2.
#
# Run from anywhere (only bash, jq, python3):
#   bash .claude/hooks/hooks-test-agent-guard.sh
# Exit code: 0 when every case passes, 1 otherwise.

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

# Paths are overridable to prove the test itself turns red:
#   printf 'cat >/dev/null\n' >/tmp/stub.sh
#   UO=/tmp/stub.sh QG=/tmp/stub.sh SC=/tmp/stub.sh SS=/tmp/stub.sh SR=/tmp/stub.sh \
#     bash .claude/hooks/hooks-test-agent-guard.sh
# must fail every case expecting OUT (hooks are run with `bash`, so the stub is a script). A test that is green with a stub instead of
# the hook checks nothing.
UO=${UO:-.claude/hooks/untrusted-output.sh}
QG=${QG:-.claude/hooks/quality-gate.sh}
SC=${SC:-.claude/hooks/session-cap.sh}
SS=${SS:-.claude/hooks/session-size.sh}
SR=${SR:-.claude/hooks/style-reinject.sh}
SL=${SL:-.claude/statusline.sh}

tmp="$(mktemp -d)" || exit 1
trap 'rm -rf "$tmp"' EXIT
fail=0

chk() { # $1=want(EMPTY|OUT) $2=label $3=output already captured
  local want="$1" label="$2" out="$3"
  local got=EMPTY; [ -n "$out" ] && got=OUT
  if [ "$got" = "$want" ]; then printf '  ok    %-5s %s\n' "$got" "$label"
  else printf '  FAIL  want=%-5s got=%-5s %s\n' "$want" "$got" "$label"; fail=$((fail + 1)); fi
}

has() { # $1=label $2=needle $3=haystack
  if printf '%s' "$3" | grep -qF -- "$2"; then printf '  ok    HAS   %s\n' "$1"
  else printf '  FAIL  output lacks "%s": %s\n' "$2" "$1"; fail=$((fail + 1)); fi
}

# --- fixtures -----------------------------------------------------------------
python3 - "$tmp" <<'PY'
import json, sys
t = sys.argv[1]

def resp(text, tool='WebFetch'):
    return json.dumps({'tool_name': tool, 'tool_response': text})

open(f'{t}/uo_zw.json', 'w').write(resp('hello' + chr(0x200B) + 'world'))
open(f'{t}/uo_tag.json', 'w').write(resp('ok' + ''.join(chr(c) for c in range(0xE0041, 0xE0045))))
open(f'{t}/uo_osc.json', 'w').write(resp('x' + chr(0x1B) + ']52;c;cGF5bG9hZA==' + chr(0x07)))
open(f'{t}/uo_clean.json', 'w').write(resp('plain text without surprises', 'Read'))
open(f'{t}/uo_ansi.json', 'w').write(resp(chr(0x1B) + '[32m OK ' + chr(0x1B) + '[0m', 'Bash'))

def tu(name, **inp):
    return json.dumps({'type': 'assistant',
                       'message': {'content': [{'type': 'tool_use', 'name': name, 'input': inp}]}})

edit = tu('Edit', file_path='/repo/src/Client/MarketClient.php')
stat = tu('Bash', command='make stan')
test = tu('Bash', command='make test FILTER=MarketClientTest')
check = tu('Bash', command='make check')
docs = tu('Edit', file_path='/repo/docs/X.md')
open(f'{t}/tr_ok.jsonl', 'w').write('\n'.join([edit, stat, test]) + '\n')
open(f'{t}/tr_check.jsonl', 'w').write('\n'.join([edit, check]) + '\n')
open(f'{t}/tr_late_edit.jsonl', 'w').write('\n'.join([stat, test, edit]) + '\n')
open(f'{t}/tr_no_tests.jsonl', 'w').write('\n'.join([edit, stat]) + '\n')
open(f'{t}/tr_nophp.jsonl', 'w').write(docs + '\n')

def usage(cache_read):
    return json.dumps({'message': {'usage': {'input_tokens': 10,
                                             'cache_read_input_tokens': cache_read,
                                             'cache_creation_input_tokens': 0}}})
# Compact separators: real transcripts are written as `"isSidechain":true`, and the
# hook matches that exact spelling.
sub = json.dumps({'isSidechain': True, 'message': {'usage': {'input_tokens': 10,
                  'cache_read_input_tokens': 900000, 'cache_creation_input_tokens': 0}}},
                 separators=(',', ':'))
open(f'{t}/tr_usage.jsonl', 'w').write('\n'.join([usage(500000)] * 5) + '\n')
open(f'{t}/tr_small.jsonl', 'w').write('\n'.join([usage(1000)] * 5) + '\n')
open(f'{t}/tr_sub.jsonl', 'w').write('\n'.join([usage(1000)] * 2 + [sub] * 300) + '\n')
PY

hook_input() { # $1=transcript $2=session $3=prompt
  python3 -c 'import json,sys; print(json.dumps({"transcript_path":sys.argv[1],"session_id":sys.argv[2],"prompt":sys.argv[3]}))' "$1" "$2" "${3:-}"
}

# Stop hooks write state into $CLAUDE_PROJECT_DIR/var/claude: point them at a temp dir.
proj="$tmp/proj"; mkdir -p "$proj"

echo "== untrusted-output: hidden characters =="
chk OUT   "zero-width"                  "$(bash $UO <"$tmp/uo_zw.json")"
chk OUT   "tag-block U+E0000"           "$(bash $UO <"$tmp/uo_tag.json")"
chk OUT   "OSC 52 (clipboard hijack)"   "$(bash $UO <"$tmp/uo_osc.json")"
echo "== untrusted-output: no false positives =="
chk EMPTY "plain text"                  "$(bash $UO <"$tmp/uo_clean.json")"
chk EMPTY "ANSI colours (phpstan output)" "$(bash $UO <"$tmp/uo_ansi.json")"

echo "== quality-gate =="
qg() { hook_input "$tmp/$1" "t$RANDOM$RANDOM" | CLAUDE_PROJECT_DIR="$proj" bash $QG; }
out="$(qg tr_late_edit.jsonl)"
chk OUT   "php edited AFTER the run"     "$out"
has "message names Skill(quality-checks)" "Skill(quality-checks)" "$out"
chk OUT   "static ran, tests did not"    "$(qg tr_no_tests.jsonl)"
chk EMPTY "make stan + make test after the edit" "$(qg tr_ok.jsonl)"
chk EMPTY "make check after the edit"    "$(qg tr_check.jsonl)"
chk EMPTY "no php touched"               "$(qg tr_nophp.jsonl)"
s1="once$RANDOM"
o1="$(hook_input "$tmp/tr_late_edit.jsonl" "$s1" | CLAUDE_PROJECT_DIR="$proj" bash $QG)"
o2="$(hook_input "$tmp/tr_late_edit.jsonl" "$s1" | CLAUDE_PROJECT_DIR="$proj" bash $QG)"
chk OUT   "first nudge in a session"     "$o1"
chk EMPTY "second nudge in the same session is suppressed" "$o2"
chk EMPTY "stop_hook_active=true"        "$(printf '{"transcript_path":"%s","session_id":"x","stop_hook_active":true}' "$tmp/tr_late_edit.jsonl" | CLAUDE_PROJECT_DIR="$proj" bash $QG)"
if [ -f "$proj/var/claude/quality-gate-done" ] && grep -qx '\*' "$proj/var/claude/.gitignore" 2>/dev/null; then
  echo "  ok    STATE state in var/claude/ with a self-ignoring .gitignore"
else echo "  FAIL  quality-gate state not in var/claude/ or .gitignore missing"; fail=$((fail + 1)); fi

echo "== session-size =="
ss() { hook_input "$tmp/$1" "$2" | CLAUDE_PROJECT_DIR="$proj" env ${3:-} bash $SS 2>"$tmp/ss.err"; }
chk EMPTY "small session"                "$(ss tr_small.jsonl z1)"
chk EMPTY "small session: no warning"    "$(cat "$tmp/ss.err")"
out="$(ss tr_small.jsonl z2 HARD_REQ=3)"
chk OUT   "over HARD_REQ: block"         "$out"
has "block proposes Skill(handoff)"      "Skill(handoff)" "$out"
chk EMPTY "hard block fires once per session" "$(ss tr_small.jsonl z2 HARD_REQ=3)"
chk OUT   "over HARD_CTX: block"         "$(ss tr_usage.jsonl z3)"
chk EMPTY "over WARN_REQ: no block on stdout" "$(ss tr_small.jsonl z4 WARN_REQ=3)"
chk OUT   "over WARN_REQ: warning on stderr" "$(cat "$tmp/ss.err")"
chk EMPTY "subagent (sidechain) requests are not counted" "$(ss tr_sub.jsonl z5)"
[ -f "$proj/var/claude/session-size-warned" ] && echo "  ok    STATE var/claude/session-size-warned" \
  || { echo "  FAIL  session-size marker not in var/claude/"; fail=$((fail + 1)); }

echo "== session-cap =="
chk EMPTY "under the limit"              "$(hook_input "$tmp/tr_usage.jsonl" s1 'continue' | bash $SC)"
chk OUT   "over the limit"               "$(hook_input "$tmp/tr_usage.jsonl" s1 'continue' | CAP_REQ=3 bash $SC)"
chk OUT   "over the context limit"       "$(hook_input "$tmp/tr_usage.jsonl" s1 'continue' | CAP_CTX=100000 bash $SC)"
chk EMPTY "over the limit, prompt about handoff" "$(hook_input "$tmp/tr_usage.jsonl" s1 'do a handoff' | CAP_REQ=3 bash $SC)"
chk EMPTY "over the limit, /clear"       "$(hook_input "$tmp/tr_usage.jsonl" s1 '/clear' | CAP_REQ=3 bash $SC)"
chk EMPTY "WM_SESSION_CAP=off"           "$(hook_input "$tmp/tr_usage.jsonl" s1 'continue' | CAP_REQ=3 WM_SESSION_CAP=off bash $SC)"

echo "== style-reinject =="
sp="$tmp/style"; mkdir -p "$sp/.claude/style-reminders" "$tmp/userdir"
printf '{\n  "outputStyle": "Terse"\n}\n' >"$sp/.claude/settings.json"
printf 'REMINDER-MARKER\n' >"$sp/.claude/style-reminders/Terse.txt"
sr() { printf '{"prompt":"x"}' | CLAUDE_PROJECT_DIR="$1" CLAUDE_CONFIG_DIR="$tmp/userdir" bash $SR; }
out="$(sr "$sp")"
chk OUT   "style with a reminder file injects it" "$out"
has "injected text is the reminder file" "REMINDER-MARKER" "$out"
printf '{"outputStyle": "Other"}\n' >"$sp/.claude/settings.local.json"
chk EMPTY "settings.local.json wins; style without a reminder injects nothing" "$(sr "$sp")"
printf '{"outputStyle": "../../x"}\n' >"$sp/.claude/settings.local.json"
chk EMPTY "path separators in the style name are refused" "$(sr "$sp")"
out="$(sr "$PWD")"
has "this repo's Terse reminder is wired" "[Hook reminder, NOT user input.]" "$out"

echo "== statusline (smoke) =="
out="$(printf '{"workspace":{"current_dir":"%s","project_dir":"%s"},"model":{"display_name":"TestModel"},"context_window":{"used_percentage":42}}' "$PWD" "$PWD" | bash $SL 2>/dev/null)"
has "model name rendered"                "TestModel" "$out"
has "context percent rendered"           "42%" "$out"

echo
if [ $fail -eq 0 ]; then echo "RESULT: all cases passed"; else echo "RESULT: failed cases: $fail"; fi
exit $((fail > 0))
