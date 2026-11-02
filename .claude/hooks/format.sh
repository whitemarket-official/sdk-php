#!/usr/bin/env bash
# PostToolUse hook (Edit|Write|MultiEdit): formats the edited PHP file with
# php-cs-fixer (PER-CS 2.0 + declare_strict_types + no_unused_imports, see
# .php-cs-fixer.dist.php). Replaces php-cs-fixer.sh of the source repo.
#
# Wired with "async": true in settings.json, so it runs in the background and
# never delays the edit. Every error is swallowed: the hook must never fail the
# edit, it always exits 0.
#
# Skipped: non-PHP files, files outside the repo, src/Generated/ (generated code
# is formatted by the generator, rule 1), and a missing vendor/bin/php-cs-fixer
# (before `make install`).

set -u

input=$(cat)
file=$(printf '%s' "$input" | jq -r '.tool_response.filePath // .tool_input.file_path // empty' 2>/dev/null)

[[ -z "$file" ]] && exit 0
[[ "$file" == *.php ]] || exit 0

repo_root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"

rel="$file"
if [[ "$rel" == /* ]]; then
  [[ "$rel" == "$repo_root"/* ]] || exit 0
  rel="${rel#"$repo_root"/}"
fi
rel="${rel#./}"

case "$rel" in
  src/Generated/*|vendor/*) exit 0 ;;
esac

fixer="$repo_root/vendor/bin/php-cs-fixer"
[[ -x "$fixer" ]] || exit 0

cd "$repo_root" || exit 0
"$fixer" fix --config=.php-cs-fixer.dist.php --quiet "$rel" >/dev/null 2>&1 || true
exit 0
