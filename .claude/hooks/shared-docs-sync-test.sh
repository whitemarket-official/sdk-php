#!/bin/bash
# Regression tests of shared-docs-sync.sh in throwaway repositories (no network, no real SDKs).
# SDK-agnostic: this file is byte-identical in all seven SDK repositories (.claude/shared-docs.conf). "This" SDK is
# taken from the repository directory name (whitemarket_sdk_<name>, php when it does not match); the sister used by
# the cases is python, or php when this SDK is python.
set -u

hook="$(cd "$(dirname "$0")" && pwd -P)/shared-docs-sync.sh"
conf_src="$(cd "$(dirname "$0")/.." && pwd -P)/shared-docs.conf"
repo_name=$(basename "$(cd "$(dirname "$0")/../.." && pwd -P)")
me=${repo_name#whitemarket_sdk_}
case "$me" in php | golang | js | ts | csharp | rust | python) ;; *) me=php ;; esac
if [ "$me" = python ]; then sis=php; else sis=python; fi
ME=$(printf '%s' "$me" | tr '[:lower:]' '[:upper:]')
SIS=$(printf '%s' "$sis" | tr '[:lower:]' '[:upper:]')

fails=0
check() { # name, condition result (0 = pass)
  if [ "$2" -eq 0 ]; then echo "PASS $1"; else echo "FAIL $1"; fails=$((fails + 1)); fi
}
valid_json() { [ -z "$1" ] || printf '%s' "$1" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null; }

make_repo() { # dir
  mkdir -p "$1/.claude" "$1/docs"
  cp "$conf_src" "$1/.claude/shared-docs.conf"
  printf 'spec v1\n' >"$1/docs/SDK_SPEC.md"
  printf 'api v1\n' >"$1/docs/WHITEMARKET_API.md"
  git -C "$1" init -q && git -C "$1" add -A && git -C "$1" -c user.name=t -c user.email=t@t commit -qm init
}
run() { # repo, mode, json, [env assignments...]
  local repo=$1 mode=$2 json=$3; shift 3
  printf '%s' "$json" | env -u WHITEMARKET_SDK_PHP_DIR -u WHITEMARKET_SDK_GOLANG_DIR -u WHITEMARKET_SDK_JS_DIR \
    -u WHITEMARKET_SDK_TS_DIR -u WHITEMARKET_SDK_CSHARP_DIR -u WHITEMARKET_SDK_RUST_DIR -u WHITEMARKET_SDK_PYTHON_DIR \
    "$@" CLAUDE_PROJECT_DIR="$repo" bash "$hook" "$mode"
}
edit_json() { printf '{"session_id":"s1","tool_input":{"file_path":"%s"}}' "$1"; }
STOP='{"session_id":"s1"}'

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# 1. Only this SDK checked out: nothing at all, even after an edit of a shared file.
make_repo "$tmp/alone/whitemarket_sdk_$me"
self="$tmp/alone/whitemarket_sdk_$me"
printf 'spec v2\n' >"$self/docs/SDK_SPEC.md"
out=$(run "$self" post-edit "$(edit_json "$self/docs/SDK_SPEC.md")")
check "no sister present -> post-edit silent" "$([ -z "$out" ]; echo $?)"
out=$(run "$self" stop "$STOP")
check "no sister present -> stop silent" "$([ -z "$out" ]; echo $?)"

# 2. Default ../ paths, one sister present.
w="$tmp/work"
make_repo "$w/whitemarket_sdk_$me"
make_repo "$w/whitemarket_sdk_$sis"
self="$w/whitemarket_sdk_$me"
out=$(run "$self" stop "$STOP")
check "identical and unchanged -> stop silent" "$([ -z "$out" ]; echo $?)"

out=$(run "$self" post-edit "$(edit_json "$self/README.md")")
check "edit of a non-shared file -> silent" "$([ -z "$out" ]; echo $?)"

printf 'spec v2 "quoted"\n' >"$self/docs/SDK_SPEC.md"
out=$(run "$self" post-edit "$(edit_json "$self/docs/SDK_SPEC.md")")
check "edit of a shared file -> note names the file and the sister" \
  "$(printf '%s' "$out" | grep -q 'docs/SDK_SPEC.md' && printf '%s' "$out" | grep -q "$sis" && printf '%s' "$out" | grep -q additionalContext; echo $?)"
check "post-edit output is valid JSON" "$(valid_json "$out"; echo $?)"
out=$(run "$self" post-edit "$(edit_json "docs/SDK_SPEC.md")")
check "relative file_path is recognised" "$(printf '%s' "$out" | grep -q 'SHARED DOC'; echo $?)"

out=$(run "$self" stop "$STOP")
check "changed and differs -> stop blocks with the sister path" \
  "$(printf '%s' "$out" | grep -q '"decision":"block"' && printf '%s' "$out" | grep -q "whitemarket_sdk_$sis/docs/SDK_SPEC.md"; echo $?)"
check "stop output is valid JSON" "$(valid_json "$out"; echo $?)"
md5_here=$( (md5sum 2>/dev/null || md5 -q) <"$self/docs/SDK_SPEC.md" | cut -d' ' -f1)
check "stop names the md5 of this repository's copy" "$(printf '%s' "$out" | grep -q "md5 here $md5_here"; echo $?)"
check "stop does not list the unchanged shared file" "$(printf '%s' "$out" | grep -q WHITEMARKET_API; [ $? -ne 0 ]; echo $?)"
out=$(run "$self" stop "$STOP")
check "same state -> second stop silent" "$([ -z "$out" ]; echo $?)"
printf 'spec v3\n' >"$self/docs/SDK_SPEC.md"
out=$(run "$self" stop "$STOP")
check "file changed again -> stop blocks again" "$(printf '%s' "$out" | grep -q '"decision":"block"'; echo $?)"

cp "$self/docs/SDK_SPEC.md" "$w/whitemarket_sdk_$sis/docs/SDK_SPEC.md"
out=$(run "$self" stop '{"session_id":"s2"}')
check "copied to the sister -> stop silent" "$([ -z "$out" ]; echo $?)"

# 3. A sister differs but this repo did not touch the file: not this session's job.
printf 'api drift\n' >"$w/whitemarket_sdk_$sis/docs/WHITEMARKET_API.md"
out=$(run "$self" stop '{"session_id":"s3"}')
check "unchanged here, sister differs -> stop silent" "$([ -z "$out" ]; echo $?)"

# 4. Env override: a sister elsewhere is found; a default ../ sister is skipped when the env
#    points to a missing path.
other=golang
[ "$me" = golang ] && other=rust
OTHER=$(printf '%s' "$other" | tr '[:lower:]' '[:upper:]')
make_repo "$tmp/elsewhere/$other-checkout"
out=$(run "$self" stop '{"session_id":"s4"}' "WHITEMARKET_SDK_${OTHER}_DIR=$tmp/elsewhere/$other-checkout")
check "env override points at a sister -> its stale copy is listed" \
  "$(printf '%s' "$out" | grep -q "$other-checkout/docs/SDK_SPEC.md"; echo $?)"
out=$(run "$self" stop '{"session_id":"s5"}' "WHITEMARKET_SDK_${OTHER}_DIR=$tmp/missing" "WHITEMARKET_SDK_${SIS}_DIR=$tmp/missing2")
check "every sister path missing -> stop silent" "$([ -z "$out" ]; echo $?)"
out=$(run "$self" stop '{"session_id":"s6"}' "WHITEMARKET_SDK_${ME}_DIR=$self" "WHITEMARKET_SDK_${SIS}_DIR=$tmp/missing2")
check "this repo given as a sister is not compared with itself" "$([ -z "$out" ]; echo $?)"

# 5. A sister without the file at all counts as stale.
rm "$w/whitemarket_sdk_$sis/docs/SDK_SPEC.md"
out=$(run "$self" stop '{"session_id":"s7"}')
check "sister without the file -> listed" "$(printf '%s' "$out" | grep -q "whitemarket_sdk_$sis/docs/SDK_SPEC.md"; echo $?)"

echo "shared-docs-sync: $fails failed"
[ "$fails" -eq 0 ]