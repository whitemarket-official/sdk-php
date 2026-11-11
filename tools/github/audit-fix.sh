#!/usr/bin/env bash
# Audits one Composer project's lock file and, when advisories are found, updates only the affected packages (and what
# they need) in composer.lock. Nothing from the dependencies runs: --no-install, --no-plugins, --no-scripts.
#
# Usage: audit-fix.sh <project dir> <report file>
# Env:   COMPOSER_BIN (default: composer)
# Exit:  0 = no advisories left (lock possibly changed), 1 = advisories remain after the update, 2 = usage/tool error.
# The report (Markdown) lists the packages before and after; it is appended to, so one report can cover several dirs.
set -euo pipefail

dir=${1:?project dir}
report=${2:?report file}
composer=${COMPOSER_BIN:-composer}
flags=(--working-dir="$dir" --no-interaction --no-plugins --no-scripts)

command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }

audit_json() {
  # composer audit exits non-zero when advisories exist; the JSON is the result, the exit code is not.
  $composer audit "${flags[@]}" --locked --format=json --abandoned=ignore 2>/dev/null || true
}

packages() { jq -r '(.advisories // {}) | if type == "object" then keys[] else empty end'; }

locked_version() {
  jq -r --arg p "$1" '[.packages[], (.["packages-dev"] // [])[]] | map(select(.name == $p)) | .[0].version // "absent"' \
    "$dir/composer.lock"
}

before=$(audit_json)
if ! jq -e . >/dev/null 2>&1 <<<"$before"; then
  echo "composer audit in $dir did not return JSON" >&2
  exit 2
fi
mapfile -t affected < <(packages <<<"$before")

if [ "${#affected[@]}" -eq 0 ]; then
  echo "$dir: no advisories."
  exit 0
fi

declare -A old
for p in "${affected[@]}"; do old[$p]=$(locked_version "$p"); done

echo "$dir: advisories for ${affected[*]}; updating them in composer.lock."
$composer update "${flags[@]}" --no-install --no-progress --with-all-dependencies "${affected[@]}"

after=$(audit_json)
mapfile -t remaining < <(packages <<<"$after")

{
  echo "### \`$dir/composer.lock\`"
  echo
  echo "| Package | Advisories | Before | After |"
  echo "|---|---|---|---|"
  for p in "${affected[@]}"; do
    ids=$(jq -r --arg p "$p" '[.advisories[$p][] | "[\(.advisoryId)](\(.link // ""))"] | join(", ")' <<<"$before")
    echo "| \`$p\` | $ids | \`${old[$p]}\` | \`$(locked_version "$p")\` |"
  done
  echo
  if [ "${#remaining[@]}" -gt 0 ]; then
    echo "**Still affected after the update (no fixed version allowed by the constraints):** ${remaining[*]}"
    echo
  fi
} >>"$report"

if [ "${#remaining[@]}" -gt 0 ]; then
  echo "$dir: still affected after the update: ${remaining[*]}" >&2
  exit 1
fi
