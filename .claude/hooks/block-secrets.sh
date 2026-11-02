#!/usr/bin/env bash
# PreToolUse hook: deny access to secrets (files and environment variables).
#
# Why, when permissions.deny exists: deny covers the Read/Edit tools, but Bash
# reaches the same files (`cat .env`) and the environment (`echo $X_TOKEN`).
# This hook closes the Bash path and duplicates the file path check.
#
# Data policy (CLAUDE.md "Data policy"): Partner Tokens, JWTs, API keys,
# passwords and private keys never enter the session. The agent never prints,
# echoes or writes the value of WHITEMARKET_PARTNER_TOKEN.
#
# Covers what used to be a separate block-deploy-env.sh in the source repo:
# there is no deploy env here, every `.env*` file is a secret.
#
# On match: exit 2 + message on stderr (Claude Code forwards it to the agent).

set -u

input=$(cat)
tool=$(printf '%s' "$input" | jq -r '.tool_name // empty')

block() {
  printf 'BLOCKED: %s\n' "$1" >&2
  exit 2
}

# Secret path: match by basename to catch any nesting, plus a few directories.
is_secret_path() {
  local p="$1"
  [[ -z "$p" ]] && return 1

  # ~/.ssh, ~/.aws, ~/.gnupg, ~/.kube, ~/.config/gcloud: whole directories
  case "$p" in
    "$HOME"/.ssh/*|"$HOME"/.aws/*|"$HOME"/.gnupg/*|"$HOME"/.kube/*|"$HOME"/.config/gcloud/*|"$HOME"/.netrc)
      return 0
      ;;
  esac

  # Any `secrets/` directory, and the live smoke-test token cache.
  case "$p" in
    secrets/*|*/secrets/*) return 0 ;;
    var/live-smoke/token*|./var/live-smoke/token*|*/var/live-smoke/token*) return 0 ;;
  esac

  local base="${p##*/}"
  case "$base" in
    # .env, .env.local, .env.test, .env_live ...
    .env|.env.*|.env_*) return 0 ;;
    # private keys, certificates, keystores
    *.pem|*.key|*.p12|*.pfx|*.jks|*.keystore) return 0 ;;
    id_rsa*|id_ed25519*|id_dsa*|id_ecdsa*) return 0 ;;
    # database dumps (none expected in an SDK, kept as a safety net)
    *.sql|*.sql.gz|*.dump) return 0 ;;
  esac

  return 1
}

case "$tool" in
  Read|Edit|Write|MultiEdit|NotebookEdit)
    file_path=$(printf '%s' "$input" | jq -r '.tool_input.file_path // .tool_input.notebook_path // empty')
    if is_secret_path "$file_path"; then
      block "$file_path is a secret (env file, key, secrets/ or live-smoke token); access denied by the data policy (CLAUDE.md)"
    fi
    ;;

  Bash)
    cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // empty')
    [[ -z "$cmd" ]] && exit 0

    # --- Environment dump -------------------------------------------------
    # Checked BEFORE the reader filter below, otherwise its early exit would skip these.
    # env/printenv count as a dump only in COMMAND POSITION: start of line, after
    # ; | & ( or after exec/-c with 0-3 words in between. Otherwise
    # `grep -rn env src/` would be blocked by mistake.
    # The form `env FOO=bar cmd` (run with a variable) is safe and allowed.
    env_dump='(^|[;&|(][[:space:]]*|(^|[[:space:]])(exec|-c)[[:space:]]+([A-Za-z0-9_.@:-]+[[:space:]]+){0,3})(env|printenv)([[:space:]]|$)'
    if [[ "$cmd" =~ $env_dump ]] \
       && [[ ! "$cmd" =~ (^|[[:space:]])env[[:space:]]+[A-Za-z_][A-Za-z0-9_]*= ]]; then
      block "env/printenv print the environment, which holds WHITEMARKET_PARTNER_TOKEN and other secrets (data policy)"
    fi

    if [[ "$cmd" =~ (^|[[:space:];\&\|\(])(export[[:space:]]+-p|declare[[:space:]]+-[px]|typeset[[:space:]]+-[px])([[:space:]]|$) ]]; then
      block "export -p / declare -p / declare -x print the environment with secrets (data policy)"
    fi

    # Bare `set` (or `set | ...`, `set > f`) prints every shell variable.
    # `set -euo pipefail` and other option forms are fine.
    set_dump='(^|[;&|(][[:space:]]*)set[[:space:]]*($|[;&|>)])'
    if [[ "$cmd" =~ $set_dump ]]; then
      block "bare \`set\` prints every shell variable, including secrets (data policy)"
    fi

    # Expanding a secret-named variable: $WHITEMARKET_PARTNER_TOKEN, ${X_TOKEN}, $X_SECRET ...
    # Presence checks stay allowed: [ -n "$X" ], [[ -z ${X:-} ]], test -n "$X",
    # ${X:+set}, ${#X}. They are rewritten away before the check.
    secret_var='[A-Za-z0-9_]*(TOKEN|SECRET|PASSWORD|PASSWD|API_KEY|PRIVATE_KEY)[A-Za-z0-9_]*'
    stripped=$(printf '%s' "$cmd" | sed -E \
      -e 's/(\[\[?|test)[[:space:]]+-[nz][[:space:]]+"?\$\{?[A-Za-z_][A-Za-z0-9_]*(:-)?\}?"?/\1 -n PRESENCE/g' \
      -e 's/\$\{[A-Za-z_][A-Za-z0-9_]*:\+[^}]*\}/PRESENCE/g' \
      -e 's/\$\{#[A-Za-z_][A-Za-z0-9_]*\}/PRESENCE/g')
    secret_expand='\$\{?'"$secret_var"
    if [[ "$stripped" =~ $secret_expand ]]; then
      block "the command expands a secret variable (*TOKEN*, *SECRET*, *PASSWORD*, *API_KEY*); its value must never reach the session. Presence checks ([ -n \"\${X:-}\" ]) are allowed"
    fi

    # Inline interpreter code reading the environment (php -r, python -c, node -e ...).
    # Requires the inline flag, otherwise `grep -rn getenv src/` would be blocked.
    if [[ "$cmd" =~ (^|[[:space:]])(php|python3|python|perl|ruby|node|deno|bun)[[:space:]]+-[recE] ]] \
       && [[ "$cmd" =~ (\$_ENV|\$_SERVER|getenv|os\.environ|os\.getenv|process\.env|ENV\[) ]]; then
      block "inline interpreter code reads environment variables (secrets live there); denied by the data policy"
    fi

    # --- Reading secret files ---------------------------------------------
    # Block only when content is actually read or passed on. `ls`, `find -name`
    # and the like are harmless; blocking them turns the hook into a nuisance
    # that gets disabled. Interpreters and git are in the list: without them
    # `php -r 'echo file_get_contents(".env");'` and `git show HEAD:.env` pass.
    reader='(^|[[:space:]|;&(]|\$\()(cat|bat|head|tail|less|more|nl|strings|xxd|od|base64|awk|sed|grep|egrep|fgrep|rg|ag|cp|mv|scp|rsync|curl|wget|tar|zip|gzip|gunzip|zcat|dd|openssl|source|\.|php|python3|python|perl|ruby|node|deno|bun|git|xargs|tee|jq|cut|tr|diff)([[:space:]]|$)'
    if [[ ! "$cmd" =~ $reader ]]; then
      exit 0
    fi

    # Boundaries include ; & | ( ) , : @ so `f=.env; cat $f` and `git show HEAD:.env` match.
    secret='(^|[[:space:]/"'"'"'`=;&|(:@])(\.env([._][A-Za-z0-9_.-]+)?|id_rsa[A-Za-z0-9_.-]*|id_ed25519[A-Za-z0-9_.-]*|[A-Za-z0-9_./-]+\.(pem|key|p12|pfx|jks|keystore|sql|dump))([[:space:]"'"'"'`;&|),]|$)'
    if [[ "$cmd" =~ $secret ]]; then
      block "the command reads a secret (.env*, private key or dump); denied by the data policy"
    fi

    secret_dir='(^|[[:space:]/"'"'"'`=;&|(:@])(secrets/|var/live-smoke/token)'
    if [[ "$cmd" =~ $secret_dir ]]; then
      block "the command reads secrets/ or the live-smoke token cache (var/live-smoke/token*); denied by the data policy"
    fi

    # Home directories with credentials
    if [[ "$cmd" =~ (~|\$HOME|/Users/[A-Za-z0-9_.-]+|/home/[A-Za-z0-9_.-]+)/\.(ssh|aws|gnupg|kube)/ ]]; then
      block "the command touches a credentials directory (~/.ssh, ~/.aws, ~/.gnupg, ~/.kube)"
    fi
    ;;
esac

exit 0
