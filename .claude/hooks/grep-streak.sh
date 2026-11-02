#!/usr/bin/env bash
# PreToolUse hook (Bash|Grep): the second text search over code in a row is
# blocked with a hint to use the index (CLAUDE.md "Context hygiene" 4).
#
# Why: a measurement in the source repo (64 sessions, one week) found ~1176
# grep/rg calls vs ~125 CodeGraph calls. The cost is not grep's output but the
# step itself: every request resends the whole context. The rule "index before
# grep" exists in CLAUDE.md and was not followed, so a hook holds it, not text
# (Context hygiene 9).
#
# Counts as a code search: grep -r/-R, rg, git grep and the Grep tool.
# Does not count: grep as a filter after a pipe (`make test | grep FAIL`), searches
# in logs (var/, *.log), searches with an explicit include/glob/type of non-PHP
# files (yaml, md, json, graphql...). A deliberate literal search in PHP (a log
# string, an exception message) is marked with a `# literal` comment at the end
# of the command, so the bypass becomes a decision.
#
# Subagents (Explore etc.) are not limited: they have their own context, grep is
# the cheap path there. One-off bypass for a session: WM_GREP_STREAK=off.

set -u

[ "${WM_GREP_STREAK:-on}" = "off" ] && exit 0

HOOK_INPUT=$(cat)
export HOOK_INPUT

python3 -I - <<'PY'
import json, os, re, sys

try:
    inp = json.loads(os.environ.get('HOOK_INPUT') or '{}')
except Exception:
    sys.exit(0)

if inp.get('agent_id'):          # call from a subagent
    sys.exit(0)

NON_PHP = re.compile(r'\.(ya?ml|md|twig|json5?|neon|xml|txt|log|sh|env\w*|graphql|csv|dist)\b|\b(yaml|md|json|xml|sh|txt|graphql)\b')
LOGS = re.compile(r'(^|[\s/])var/|\.log\b')

def is_code_search(name, ti):
    if name == 'Grep':
        where = ' '.join(str(ti.get(k) or '') for k in ('path', 'glob', 'type'))
        return not (LOGS.search(where) or NON_PHP.search(where))
    if name != 'Bash':
        return False
    cmd = ti.get('command') or ''
    if '# literal' in cmd:
        return False
    for seg in re.split(r'&&|\|\||;|\n', cmd):
        head = seg.split('|')[0].strip()          # everything after a pipe is a filter
        m = re.match(r'(?:\w+=\S+\s+)*(rg|git\s+grep|e?grep)\b(.*)', head)
        if not m:
            continue
        tool, args = m.group(1), m.group(2)
        if tool == 'grep' or tool == 'egrep':
            if not re.search(r'(^|\s)-\w*[rR]\w*\b|--recursive', args):
                continue                          # grep over one file is not exploration
        if LOGS.search(args):
            continue
        if re.search(r'--include[= ]\S*', args) and NON_PHP.search(args) and '.php' not in args:
            continue
        if re.search(r'(-g|--glob|-t|--type)[= ]\S*', args) and NON_PHP.search(args) and 'php' not in args:
            continue
        return True
    return False

name = inp.get('tool_name')
if not is_code_search(name, inp.get('tool_input') or {}):
    sys.exit(0)

path = inp.get('transcript_path') or ''
if not os.path.isfile(path):
    sys.exit(0)

current = inp.get('tool_use_id')
prev = None
with open(path, 'rb') as fh:
    fh.seek(0, 2)
    fh.seek(max(0, fh.tell() - 4 * 1024 * 1024))  # the previous call is always in the tail
    for raw in fh:
        if b'"tool_use"' not in raw:
            continue
        try:
            d = json.loads(raw)
        except Exception:
            continue
        if d.get('isSidechain') or d.get('type') != 'assistant':
            continue
        for c in (d.get('message') or {}).get('content') or []:
            if isinstance(c, dict) and c.get('type') == 'tool_use' and c.get('id') != current:
                prev = c

if prev and is_code_search(prev.get('name'), prev.get('input') or {}):
    sys.stderr.write(
        'BLOCKED: second grep over code in a row (CLAUDE.md, Context hygiene 4).\n'
        '- where defined / who calls / what breaks: codegraph_search, codegraph_callers, '
        'codegraph_callees, codegraph_impact;\n'
        '- interface implementations, types: PhpStorm MCP (search_symbol, get_symbol_info) if the IDE is open;\n'
        '- wide literal search: Explore subagent;\n'
        '- deliberate literal search (log string, exception message): append `# literal` to the command.\n'
    )
    sys.exit(2)
PY
