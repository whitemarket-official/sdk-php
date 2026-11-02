#!/usr/bin/env bash
# PreToolUse hook for Bash: blocks dangerous commands.
# On match: exit 2 + message on stderr (Claude Code forwards it to the agent).
#
# Rules (CLAUDE.md "Hard rules" 8, 9 and "Agent configuration is a security zone"):
#   - recursive rm outside var/, build/, vendor/, node_modules/, /tmp/*, the Claude scratchpad
#   - git push --force / -f / --force-with-lease / +refspec
#   - --no-verify (and `git commit -n`, its short form)
#   - git reset --hard (except onto origin/* or upstream/*), git clean -f*
#   - editing .claude/hooks/** or .claude/settings*.json from Bash
#   - publishing: npm/pnpm/yarn publish, cargo publish, dotnet nuget push,
#     twine upload, gh release create, gh repo create, curl to packagist.org/api
#   - composer remove

set -u

input=$(cat)
cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // empty')

[[ -z "$cmd" ]] && exit 0

block() {
  printf 'BLOCKED: %s\n' "$1" >&2
  exit 2
}

repo_root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"

# Regexes live in variables: bare ( ) inside [[ =~ ]] are parsed by bash itself -> syntax error.
git_cmd='git([[:space:]]+-C[[:space:]]+[^[:space:]]+)?[[:space:]]+'

# --- Force push in every form ------------------------------------------------
# Scoped to one pipeline segment ([^;&|]*) so `git push origin x && rm -f y` is not a force push.
force_re="${git_cmd}push[^;&|]*[[:space:]](--force[A-Za-z-]*|-[A-Za-z]*f[A-Za-z]*|\\+[^[:space:]]+)([[:space:]]|\$)"
if [[ "$cmd" =~ $force_re ]]; then
  block "force push is forbidden (CLAUDE.md rule 9), including -f, --force-with-lease and +refspec"
fi

# --- --no-verify: skipping pre-commit/pre-push hooks --------------------------
noverify_re="${git_cmd}[a-z-]+[^;&|]*--no-verify"
commit_n_re="${git_cmd}commit[^;&|]*[[:space:]]-[A-Za-z]*n[A-Za-z]*([[:space:]]|\$)"
if [[ "$cmd" =~ $noverify_re ]] || [[ "$cmd" =~ $commit_n_re ]]; then
  block "--no-verify (or git commit -n) is forbidden (CLAUDE.md rule 9): fix the hook instead of skipping it"
fi

# --- git reset --hard: only onto origin/* or upstream/* ----------------------
case "$cmd" in
  *"git reset --hard"*)
    case "$cmd" in
      *"git reset --hard origin/"*|*"git reset --hard upstream/"*) ;;
      *) block "git reset --hard without origin/upstream is dangerous (uncommitted work is lost); ask the user" ;;
    esac
    ;;
esac

# --- git clean with force -----------------------------------------------------
clean_re="${git_cmd}clean[^;&|]*[[:space:]](-[A-Za-z]*f[A-Za-z]*|--force)([[:space:]]|\$)"
if [[ "$cmd" =~ $clean_re ]]; then
  block "git clean -f/-fd/-fdx deletes untracked work irreversibly; use git clean -n to list and ask the user"
fi

# --- composer remove ----------------------------------------------------------
case "$cmd" in
  *"composer remove"*) block "composer remove is forbidden without an explicit user request (CLAUDE.md rule 10)" ;;
esac

# --- Self-modification of enforcement: editing hooks and settings from Bash ---
# permissions.deny/ask work on the Read/Edit/Write tools; Bash goes around them.
# Without this check the agent could `sed -i` block-secrets.sh, i.e. lift its own
# restrictions. Editing through Edit stays possible: permissions.ask fires there
# and a human decides. Only protected paths are targeted; reading (cat/ls/grep) is not.
protected='(\.claude/hooks/|\.claude/settings(\.local)?\.json|(~|\$HOME|/Users/[A-Za-z0-9_.-]+|/home/[A-Za-z0-9_.-]+)/\.claude/)'

# 1) Redirect INTO a protected path. Checking for '>' anywhere is wrong:
#    `jq . .claude/settings.json >/dev/null` is a harmless read.
redirect_re='>>?[[:space:]]*[^[:space:]]*'"$protected"
if [[ "$cmd" =~ $redirect_re ]]; then
  block "overwriting a Claude Code hook/config from Bash is forbidden (self-modification of restrictions); use Edit, which asks the user"
fi

# 2) A mutating command with a protected path as an argument (within one pipeline segment).
mutate_re='(^|[[:space:];&|(])(sed[[:space:]]+-i|tee|cp|mv|rm|chmod|chown|truncate|dd|patch|install|ln)[[:space:]][^;|&]*'"$protected"
if [[ "$cmd" =~ $mutate_re ]]; then
  block "changing a Claude Code hook/config from Bash is forbidden (self-modification of restrictions); use Edit, which asks the user"
fi

# 3) Interpreter + protected path anywhere in the command.
#
# Checks 1-2 know mutating utilities and redirect forms, so
# `python3 - <<'PY' ... open('.claude/hooks/x.sh','w') ... PY` slipped past them.
# Here we do not analyse what the interpreter does with the path: interpreter AND
# protected path in one command is enough to block. Consequence: reading a
# protected file with an interpreter is blocked too; that is deliberate, use Read.
#
# The interpreter is matched ONLY in command position (start or after ; | & ( ),
# otherwise `grep -r python .claude/hooks` would count as a mutation.
# bash/sh/zsh are deliberately NOT in the list: `bash .claude/hooks/hooks-test.sh`
# is the normal way to run the hook tests, not an edit.
interp_re='(^|[;&|(][[:space:]]*)(python3?|perl|ruby|node|deno|bun|php|awk|ed|xargs)[[:space:]]'
if [[ "$cmd" =~ $interp_re ]] && [[ "$cmd" =~ $protected ]]; then
  block "editing a Claude Code hook/config through an interpreter (python/perl/node/...) is the same bypass as sed -i; use Edit, where a human decides"
fi

# --- Per-segment checks: recursive rm targets and publishing ------------------
# The command is split into simple commands on ; & | ( ) and newlines; the leading
# words (VAR=x, sudo, command, time, then, do, ...) are skipped to find the program.

# Allowed recursive-rm targets, relative to the repo root.
rm_target_ok() {
  local t="$1"
  # strip one layer of quotes
  t="${t#[\"\']}"; t="${t%[\"\']}"
  [[ -z "$t" ]] && return 0
  t="${t//\$\{CLAUDE_PROJECT_DIR\}/$repo_root}"
  t="${t//\$CLAUDE_PROJECT_DIR/$repo_root}"
  case "$t" in
    *'$'*|*'`'*|'~'*) return 1 ;;                  # unknown expansion: cannot prove it is safe
  esac
  case "/$t/" in
    */../*) return 1 ;;                            # traversal out of an allowed dir
  esac
  case "$t" in
    /tmp/?*|/private/tmp/claude-*) return 0 ;;
  esac
  if [[ "$t" == "$repo_root"/* ]]; then
    t="${t#"$repo_root"/}"
  fi
  t="${t#./}"
  case "$t" in
    var|var/*|build|build/*|vendor|vendor/*|node_modules|node_modules/*) return 0 ;;
  esac
  return 1
}

segments=$(printf '%s\n' "$cmd" | tr ';&|()' '\n\n\n\n\n')
while IFS= read -r seg; do
  [[ -z "${seg//[[:space:]]/}" ]] && continue
  read -ra words <<<"$seg"
  i=0
  n=${#words[@]}
  while (( i < n )); do
    w="${words[$i]}"
    case "$w" in
      *=*) [[ "$w" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && { i=$((i + 1)); continue; } ;;
      sudo|command|builtin|exec|time|nice|nohup|then|do|else|'{'|'!') i=$((i + 1)); continue ;;
      env) i=$((i + 1)); continue ;;
    esac
    break
  done
  (( i >= n )) && continue
  prog="${words[$i]##*/}"
  rest=("${words[@]:$((i + 1))}")
  args="${rest[*]:-}"

  case "$prog" in
    rm)
      recursive=0
      targets=()
      opts_done=0
      for a in ${rest[@]+"${rest[@]}"}; do
        if (( ! opts_done )); then
          case "$a" in
            --) opts_done=1; continue ;;
            --recursive) recursive=1; continue ;;
            --*) continue ;;
            -*) [[ "$a" =~ [rR] ]] && recursive=1; continue ;;
          esac
        fi
        targets+=("$a")
      done
      if (( recursive )); then
        for t in ${targets[@]+"${targets[@]}"}; do
          rm_target_ok "$t" || block "recursive rm of '$t' is allowed only under var/, build/, vendor/, node_modules/, /tmp/* or the Claude scratchpad; ask the user"
        done
      fi
      ;;
    npm|pnpm|yarn)
      [[ "$args" =~ (^|[[:space:]])publish([[:space:]]|$) ]] && block "$prog publish is forbidden: releases go through the release workflow, not the agent"
      ;;
    cargo)
      [[ "$args" =~ (^|[[:space:]])publish([[:space:]]|$) ]] && block "cargo publish is forbidden: releases go through the release workflow, not the agent"
      ;;
    dotnet)
      [[ "$args" =~ (^|[[:space:]])nuget[[:space:]]+push([[:space:]]|$) ]] && block "dotnet nuget push is forbidden: releases go through the release workflow, not the agent"
      ;;
    twine)
      [[ "$args" =~ (^|[[:space:]])upload([[:space:]]|$) ]] && block "twine upload is forbidden: releases go through the release workflow, not the agent"
      ;;
    gh)
      [[ "$args" =~ (^|[[:space:]])release[[:space:]]+create([[:space:]]|$) ]] && block "gh release create is forbidden: releases go through the release workflow, not the agent"
      [[ "$args" =~ (^|[[:space:]])repo[[:space:]]+create([[:space:]]|$) ]] && block "gh repo create is forbidden without an explicit user request"
      ;;
    curl|wget|http|https)
      [[ "$args" =~ packagist\.org/api ]] && block "calls to the Packagist API (package update/publish) are forbidden: releases go through the release workflow"
      ;;
  esac
done <<<"$segments"

exit 0
