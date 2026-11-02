#!/usr/bin/env bash
# Runs a noisy command, keeps the full log in var/qc/<target>.log and prints only error lines and a summary.
# Usage: tools/qc-filter.sh <target> <command> [args...]
# VERBOSE=1 prints the full output. The exit code of the command is preserved: it is the truth.
set -uo pipefail

target="$1"
shift
log_dir="var/qc"
log="$log_dir/$target.log"
mkdir -p "$log_dir"

if [[ "${VERBOSE:-0}" == "1" ]]; then
    "$@" 2>&1 | tee "$log"
    exit "${PIPESTATUS[0]}"
fi

"$@" >"$log" 2>&1
status=$?

if [[ $status -eq 0 ]]; then
    printf '[%s] PASS\n' "$target"
    # Last non-empty lines usually hold the tool's own summary ("OK (12 tests ...)", "[OK] No errors").
    grep -v '^[[:space:]]*$' "$log" | tail -n 2 | sed 's/^/  /'
    exit 0
fi

printf '[%s] FAIL (exit %d), full log: %s\n' "$target" "$status" "$log"
error_pattern='(error|Error|ERROR|fail|FAIL|Fatal|Warning|Deprecat|issues|[A-Za-z0-9_/.-]+\.php:[0-9]+|^ *[0-9]+ +[^ ].*|^-|^\+|Line +[0-9]+|✘|\[x\])'
errors=$(grep -E "$error_pattern" "$log" | grep -vE '^(\+\+\+|---) ' | head -n 40)
if [[ -n "$errors" ]]; then
    printf -- '--- error lines (first 40) ---\n%s\n' "$errors"
fi
printf -- '--- last 15 lines ---\n'
tail -n 15 "$log"
exit "$status"
