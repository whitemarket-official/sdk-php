#!/usr/bin/env bash
# PreToolUse hook (Edit|Write|MultiEdit|NotebookEdit): generated code is never
# edited by hand (CLAUDE.md rule 1).
#
# Protected trees (relative to the repo root): src/Generated/, schema/,
# graphql/operations/, graphql/fragments/. They change only through
# `make schema` / `make codegen`; `make codegen-check` must stay clean.
# Hand-written selection overrides live in graphql/overrides/ (not protected).
#
# Accepts relative paths and absolute paths inside the repo; paths outside the
# repo pass. `..` segments are normalised before matching.
# Not covered: writes through Bash (sed -i, redirects); codegen-check catches them.
#
# On match: exit 2 + message on stderr (same mechanics as block-secrets.sh).

set -u

input=$(cat)
tool=$(printf '%s' "$input" | jq -r '.tool_name // empty')

case "$tool" in
  Edit|Write|MultiEdit|NotebookEdit) ;;
  *) exit 0 ;;
esac

file_path=$(printf '%s' "$input" | jq -r '.tool_input.file_path // .tool_input.notebook_path // empty')
[[ -z "$file_path" ]] && exit 0

repo_root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"

# Normalise: resolve `.`/`..` lexically (the file may not exist yet).
norm() {
  python3 -I -c 'import os, sys; print(os.path.normpath(sys.argv[1]))' "$1" 2>/dev/null || printf '%s\n' "$1"
}

p="$(norm "$file_path")"
root="$(norm "$repo_root")"

if [[ "$p" == /* ]]; then
  # macOS: /tmp and /var are symlinks into /private; compare both spellings.
  if [[ "$p" == "$root"/* ]]; then
    p="${p#"$root"/}"
  elif [[ "$p" == /private"$root"/* ]]; then
    p="${p#/private"$root"/}"
  elif [[ "/private$p" == "$root"/* ]]; then
    p="${p#"${root#/private}"/}"
  else
    exit 0
  fi
fi

case "$p" in
  src/Generated|src/Generated/*|schema|schema/*|graphql/operations|graphql/operations/*|graphql/fragments|graphql/fragments/*)
    printf 'BLOCKED: %s: Generated file: regenerate via make schema / make codegen (CLAUDE.md rule 1). Hand-written selection overrides go to graphql/overrides/.\n' "$file_path" >&2
    exit 2
    ;;
esac

exit 0
