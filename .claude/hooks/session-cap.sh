#!/usr/bin/env bash
# UserPromptSubmit hook: HARD session ceiling.
#
# session-size.sh reminds (WARN 120 / HARD 200 requests), but a reminder is an
# instruction-level control and can be ignored. OWASP LLM Top 10 2026, LLM06
# Unbounded Consumption, asks for enforcement: "hard spending caps ... must be
# enforcement mechanisms that halt, rather than alerting thresholds that
# fast-accumulating workloads can outpace".
#
# This is a second threshold, twice as high: above it no new prompt is accepted.
# Only prompts about a handoff or leaving the session pass, otherwise the ceiling
# would cut off the normal way to save the work.
#
# Bypass for one run: WM_SESSION_CAP=off.

set -u

CAP_REQ=${CAP_REQ:-400}
CAP_CTX=${CAP_CTX:-800000}

# Read stdin BEFORE any exit, otherwise Claude Code gets a broken pipe.
input="$(cat 2>/dev/null || true)"
[ -z "$input" ] && exit 0
[ "${WM_SESSION_CAP:-on}" = "off" ] && exit 0

{ read -r transcript; read -r prompt; } < <(python3 -c '
import json, sys
d = json.load(sys.stdin)
print(d.get("transcript_path", "") or "-")
print((d.get("prompt") or "").replace("\n", " ")[:400])
' <<<"$input" 2>/dev/null)

[ "${transcript:-}" = "-" ] && exit 0

[ -z "${transcript:-}" ] || [ ! -f "$transcript" ] && exit 0

# Escape hatch: handoff, pickup and leaving the session are never cut.
# The Russian/Ukrainian spellings are kept because the user writes in those languages.
case "$(printf '%s' "${prompt:-}" | tr '[:upper:]' '[:lower:]')" in
  *handoff*|*хендофф*|*гендоф*|*pickup*|*/clear*|*/compact*|*/resume*) exit 0 ;;
esac

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

if [ "$req" -lt "$CAP_REQ" ] && [ "$ctx" -lt "$CAP_CTX" ]; then
  exit 0
fi

reason="STOP: session ceiling reached: ${req} requests, context $((ctx / 1000))k (limit ${CAP_REQ} / $((CAP_CTX / 1000))k).

Every further step resends the whole history, so cost grows quadratically, and answer quality already degrades at this length. Work cannot continue in this session.

What to do: ask for a handoff (the prompt must contain the word \"handoff\"; such prompts pass the ceiling), then /clear and continue in a fresh session with /pickup.
One-off bypass, if really needed: WM_SESSION_CAP=off in the Claude Code environment."

printf '{"decision":"block","reason":%s}\n' \
  "$(printf '%s' "$reason" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null || printf '"%s"' "$reason")"
exit 0
