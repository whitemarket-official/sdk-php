#!/bin/bash
# Stop hook: counts the SESSION SIZE in requests and reminds about a handoff
# (CLAUDE.md "Context hygiene" 1).
#
# Why separate from the statusline: the statusline shows how full the window is
# (%), but cost is "context x number of requests". A session of 900 steps at 30%
# of the window costs more than one of 150 steps at 45%. In the source repo's
# measurement (96 sessions) the 22 sessions longer than 300 requests produced 85%
# of all token spend. The step count is not shown anywhere in the UI; this hook
# closes that gap.
#
# WARN: one line on stderr (the user sees it, the model is not distracted).
# HARD: decision:block once per session: the agent must propose a handoff.
#
# State: var/claude/session-size-warned (git-ignored through var/claude/.gitignore).

set -u

WARN_REQ=${WARN_REQ:-120}
HARD_REQ=${HARD_REQ:-200}
WARN_CTX=${WARN_CTX:-300000}
HARD_CTX=${HARD_CTX:-450000}

input=$(cat)

transcript=$(printf '%s' "$input" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("transcript_path",""))' 2>/dev/null)
session=$(printf '%s' "$input" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session_id",""))' 2>/dev/null)
[ -z "$transcript" ] || [ ! -f "$transcript" ] && exit 0

# Requests = assistant lines with usage, excluding subagents (they have their own
# context and their own count; their steps do not make the main session dearer).
read -r req ctx < <(python3 - "$transcript" <<'PY' 2>/dev/null
import json, sys
req = 0
ctx = 0
with open(sys.argv[1], encoding='utf-8', errors='replace') as fh:
    for line in fh:
        if '"cache_read_input_tokens"' not in line:
            continue
        if '"isSidechain":true' in line:
            continue
        try:
            u = json.loads(line)['message']['usage']
        except Exception:
            continue
        if not u:
            continue
        req += 1
        ctx = ((u.get('input_tokens') or 0)
               + (u.get('cache_read_input_tokens') or 0)
               + (u.get('cache_creation_input_tokens') or 0))
print(req, ctx)
PY
)
[ -z "${req:-}" ] && exit 0

state_dir="${CLAUDE_PROJECT_DIR:-.}/var/claude"
marker="$state_dir/session-size-warned"

fired() { [ -f "$marker" ] && grep -qF "$session|$1" "$marker" 2>/dev/null; }
mark() {
  mkdir -p "$state_dir" 2>/dev/null
  [ -f "$state_dir/.gitignore" ] || printf '*\n' >"$state_dir/.gitignore" 2>/dev/null
  printf '%s|%s\n' "$session" "$1" >>"$marker"
  [ "$(wc -l <"$marker" 2>/dev/null || echo 0)" -gt 200 ] &&
    { tail -n 100 "$marker" >"${marker}.tmp" && mv "${marker}.tmp" "$marker"; }
}

ctx_k=$((ctx / 1000))

if { [ "$req" -ge "$HARD_REQ" ] || [ "$ctx" -ge "$HARD_CTX" ]; } && ! fired hard; then
  mark hard
  mark warn   # above the hard threshold a warn makes no sense; do not duplicate
  reason="STOP: the session has grown to ${req} requests, context ${ctx_k}k. Session cost grows quadratically (every step resends the whole history); in the source repo's measurement sessions longer than 300 requests produced 85% of the spend. Report the current state to the user in one line and propose Skill(handoff) + /clear. Do not start a new subtask in this session. This reminder will not repeat."
  printf '{"decision":"block","reason":%s}\n' \
    "$(printf '%s' "$reason" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null || printf '"%s"' "$reason")"
  exit 0
fi

if { [ "$req" -ge "$WARN_REQ" ] || [ "$ctx" -ge "$WARN_CTX" ]; } && ! fired warn; then
  mark warn
  echo "WARN: session at ${req} requests, context ${ctx_k}k. The handoff threshold is near (${HARD_REQ} requests / $((HARD_CTX / 1000))k): a good moment to finish the current step and hand off." >&2
fi

exit 0
