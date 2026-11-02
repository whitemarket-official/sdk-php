#!/usr/bin/env bash
# PreToolUse hook (Read): re-reading a whole file that was already read in this
# session and has not changed since is blocked (CLAUDE.md "Context hygiene" 3).
#
# Why: a measurement in the source repo (64 sessions, one week) found 32% of all
# Read calls were repeats. What was read is already in the context; a repeat adds
# the same wall of text again and is resent with every following request.
#
# Allowed:
# - reads with offset/limit/pages: a targeted range is the recommended path;
# - a file modified after the previous read (newer mtime): external process,
#   php-cs-fixer, own Edit; the content in the context may be stale;
# - reads before a context compaction: after it the old output is gone;
# - subagents: they have their own context.
# One-off bypass for a session: WM_REREAD_GUARD=off.

set -u

[ "${WM_REREAD_GUARD:-on}" = "off" ] && exit 0

HOOK_INPUT=$(cat)
export HOOK_INPUT

python3 -I - <<'PY'
import json, os, sys
from datetime import datetime

try:
    inp = json.loads(os.environ.get('HOOK_INPUT') or '{}')
except Exception:
    sys.exit(0)

if inp.get('agent_id') or inp.get('tool_name') != 'Read':
    sys.exit(0)

ti = inp.get('tool_input') or {}
target = ti.get('file_path') or ''
if not target or any(ti.get(k) not in (None, '') for k in ('offset', 'limit', 'pages')):
    sys.exit(0)
if not os.path.isfile(target):
    sys.exit(0)

path = inp.get('transcript_path') or ''
if not os.path.isfile(path):
    sys.exit(0)

def ts(s):
    try:
        return datetime.fromisoformat(s.replace('Z', '+00:00')).timestamp()
    except Exception:
        return None

pending = {}    # tool_use_id -> call time, only full Reads of this file
last_read = None
with open(path, 'rb') as fh:
    for raw in fh:
        compact = b'compact_boundary' in raw or b'isCompactSummary' in raw
        if not compact and b'"tool_use"' not in raw and b'"tool_result"' not in raw:
            continue
        try:
            d = json.loads(raw)
        except Exception:
            continue
        # A field, not a substring: the word compact_boundary also appears in session
        # text (an edit of this very hook) and would reset the bookkeeping for nothing.
        if d.get('subtype') == 'compact_boundary' or d.get('isCompactSummary'):
            pending.clear()
            last_read = None
            continue
        if d.get('isSidechain'):
            continue
        for c in (d.get('message') or {}).get('content') or []:
            if not isinstance(c, dict):
                continue
            if c.get('type') == 'tool_use' and c.get('name') == 'Read':
                i = c.get('input') or {}
                if i.get('file_path') == target and not any(i.get(k) not in (None, '') for k in ('offset', 'limit', 'pages')):
                    pending[c.get('id')] = ts(d.get('timestamp') or '')
            elif c.get('type') == 'tool_result' and c.get('tool_use_id') in pending:
                t = pending.pop(c.get('tool_use_id'))
                if not c.get('is_error') and t is not None:
                    last_read = t

if last_read is None:
    sys.exit(0)
if os.path.getmtime(target) > last_read - 2:     # margin for clock skew and mtime rounding
    sys.exit(0)

when = datetime.fromtimestamp(last_read).strftime('%H:%M:%S')
sys.stderr.write(
    f'BLOCKED: {target} was already read in full in this session ({when}) and has not changed since '
    '(CLAUDE.md, Context hygiene 3). Its content is in the context above. '
    'Need a fragment again: Read with offset/limit.\n'
)
sys.exit(2)
PY
