#!/bin/bash
# Shared docs across the seven WhiteMarket SDKs (SDK_SPEC preamble, CLAUDE.md rule 18).
# The same script and the same .claude/shared-docs.conf live in every SDK repository.
#
#   post-edit  (PostToolUse Edit|Write|MultiEdit): an edit of a shared file gets a note for the
#              agent: finish the current change, then copy the file to the sister SDKs.
#   stop       (Stop): at the end of a stage, a shared file changed here (edited in this session or
#              uncommitted) whose md5 differs from a sister copy blocks once per state with the list
#              of sister paths to update.
#
# Sister paths: WHITEMARKET_SDK_<NAME>_DIR (NAME = PHP GOLANG JS TS CSHARP RUST PYTHON), default
# <repo>/../whitemarket_sdk_<name>. A sister directory that does not exist is skipped; with no
# sister present the hook prints nothing (a developer with one SDK checked out is not bothered).
# This repository itself is recognised by its real path and never counted as a sister.
#
# State: var/claude/shared-docs-* (git-ignored through var/claude/.gitignore).

set -u

mode=${1:-}
root=${CLAUDE_PROJECT_DIR:-$(pwd)}
root=$(cd "$root" 2>/dev/null && pwd -P) || exit 0
conf="$root/.claude/shared-docs.conf"
[ -f "$conf" ] || exit 0

input=$(cat)
field() { printf '%s' "$input" | sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" | head -n 1; }
session=$(field session_id)

json_string() {
  # Minimal JSON string encoder (no python/jq needed): backslash, quote, newline, tab.
  printf '"'
  printf '%s' "$1" | awk 'BEGIN{ORS=""} {gsub(/\\/,"\\\\"); gsub(/"/,"\\\""); gsub(/\t/,"\\t"); if (NR>1) print "\\n"; print}'
  printf '"'
}

digest() { # md5 of a file (empty when it is missing); cksum only where no md5 tool exists
  [ -f "$1" ] || return 0
  if command -v md5sum >/dev/null 2>&1; then md5sum <"$1" | cut -d' ' -f1
  elif command -v md5 >/dev/null 2>&1; then md5 -q <"$1"
  else cksum <"$1" | cut -d' ' -f1; fi
}

shared_files() { grep -v '^[[:space:]]*\(#\|$\)' "$conf" | sed 's/[[:space:]]*$//'; }

sisters() {
  # Prints "name|path" for every sister SDK directory that exists, except this repository.
  local name upper var dir real
  for name in php golang js ts csharp rust python; do
    upper=$(printf '%s' "$name" | tr '[:lower:]' '[:upper:]')
    var="WHITEMARKET_SDK_${upper}_DIR"
    dir=${!var:-"$root/../whitemarket_sdk_$name"}
    real=$(cd "$dir" 2>/dev/null && pwd -P) || continue
    [ "$real" = "$root" ] && continue
    printf '%s|%s\n' "$name" "$real"
  done
}

state_dir="$root/var/claude"
state_file() {
  mkdir -p "$state_dir" 2>/dev/null
  [ -f "$state_dir/.gitignore" ] || printf '*\n' >"$state_dir/.gitignore" 2>/dev/null
  printf '%s/shared-docs-%s' "$state_dir" "$1"
}
remember() { # file line
  local f; f=$(state_file "$1")
  grep -qxF "$2" "$f" 2>/dev/null || printf '%s\n' "$2" >>"$f"
  [ "$(wc -l <"$f" 2>/dev/null || echo 0)" -gt 400 ] && { tail -n 200 "$f" >"$f.tmp" && mv "$f.tmp" "$f"; }
}
remembered() { [ -f "$state_dir/shared-docs-$1" ] && grep -qxF "$2" "$state_dir/shared-docs-$1"; }

present=$(sisters)
[ -n "$present" ] || exit 0
names=$(printf '%s\n' "$present" | cut -d'|' -f1 | tr '\n' ' ' | sed 's/ $//')

case "$mode" in
post-edit)
  path=$(field file_path)
  [ -n "$path" ] || exit 0
  case "$path" in /*) ;; *) path="$root/$path" ;; esac
  dir=$(cd "$(dirname "$path")" 2>/dev/null && pwd -P) || exit 0
  rel=${dir#"$root"/}
  [ "$dir" = "$root" ] && rel="" || { [ "$rel" = "$dir" ] && exit 0; }
  rel=${rel:+$rel/}$(basename "$path")
  shared_files | grep -qxF "$rel" || exit 0
  remember edited "$session|$rel"
  note="SHARED DOC: $rel must stay identical in all SDK repositories (.claude/shared-docs.conf). Sister SDKs present here: $names. Finish the current change first; before you close this stage, copy the final $rel to the same path in each of them (and tell the user which repositories you changed). The Stop check reminds you if a copy still differs."
  printf '{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":%s}}\n' "$(json_string "$note")"
  ;;
stop)
  todo=""
  signature=""
  while IFS= read -r rel; do
    [ -n "$rel" ] || continue
    changed=""
    remembered edited "$session|$rel" && changed=y
    [ -n "$(git -C "$root" status --porcelain -- "$rel" 2>/dev/null)" ] && changed=y
    [ -n "$changed" ] || continue
    stale=""
    here=$(digest "$root/$rel")
    while IFS='|' read -r name dir; do
      [ "$(digest "$dir/$rel")" = "$here" ] || stale="$stale $dir/$rel"
    done <<<"$present"
    [ -n "$stale" ] || continue
    todo="$todo
- $rel (md5 here ${here:-missing}) ->$stale"
    signature="$signature|$rel:$here"
  done < <(shared_files)
  [ -n "$todo" ] || exit 0
  remembered fired "$session$signature" && exit 0
  remember fired "$session$signature"
  reason="SHARED DOCS OUT OF SYNC (CLAUDE.md rule 18): a shared file changed in this repository differs from its copy in a sister SDK.$todo
The stage is ending: copy each file over the listed sister paths (same relative path, identical bytes), or tell the user why it must wait. The owner asked for these copies; sister SDK code stays untouched. This reminder repeats only when the files change again."
  printf '{"decision":"block","reason":%s}\n' "$(json_string "$reason")"
  ;;
esac
exit 0
