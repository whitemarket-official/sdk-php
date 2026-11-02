#!/usr/bin/env bash
# Regression tests for the Stop hook reuse-check.sh.
#
# Why: a hook that never fires looks exactly like a working one.
# Every case runs in a TEMPORARY git repository: the real project is not touched,
# and the session marker is written there too.
#
# Run from anywhere (only bash, git, jq, python3):
#   bash .claude/hooks/reuse-check-test.sh
# Exit code: 0 when every case passes, 1 otherwise.

set -u

HOOK="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/reuse-check.sh"
[ -f "$HOOK" ] || { echo "missing $HOOK"; exit 1; }

fail=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

setup_repo() { # recreates a clean repo with one commit
  rm -rf "$tmp/repo"
  mkdir -p "$tmp/repo/src"
  git -C "$tmp/repo" init -q
  git -C "$tmp/repo" config user.email test@example.invalid; git -C "$tmp/repo" config user.name test
  git -C "$tmp/repo" config commit.gpgsign false
  echo "seed" >"$tmp/repo/README.md"
  git -C "$tmp/repo" add -A >/dev/null
  git -C "$tmp/repo" commit -qm seed
}

mk() { mkdir -p "$tmp/repo/$(dirname "$1")"; printf '<?php\n' >"$tmp/repo/$1"; }

run() { # $1=session $2=stop_hook_active(true|false) -> prints the hook's stdout
  printf '{"session_id":"%s","stop_hook_active":%s}' "$1" "$2" \
    | CLAUDE_PROJECT_DIR="$tmp/repo" bash "$HOOK" 2>/dev/null
}

chk() { # $1=want(BLOCK|PASS) $2=label $3=grep pattern inside the reason (optional)
  local out got
  out="$(run "${SESSION:-s1}" false)"
  got=PASS; [ -n "$out" ] && got=BLOCK
  if [ "$got" != "$1" ]; then
    printf '  FAIL  want=%-5s got=%-5s %s\n' "$1" "$got" "$2"; fail=$((fail+1)); return
  fi
  if [ -n "${3:-}" ] && ! printf '%s' "$out" | grep -qF -- "$3"; then
    printf '  FAIL  BLOCK without "%s" %s\n' "$3" "$2"; fail=$((fail+1)); return
  fi
  printf '  ok    %-5s %s\n' "$got" "$2"
}

echo "== N new classes of the same role: every role =="
n=0
for role in Mapper Builder Parser Decoder Client Store Validator Factory; do
  n=$((n + 1))
  setup_repo; mk "src/Market/Listing${role}.php"; mk "src/Deal/Deal${role}.php"
  SESSION=r$n chk BLOCK "2 new *${role}" "${role} (x2)"
done

echo "== Boundaries =="
setup_repo; mk src/Market/ListingMapper.php
SESSION=a1 chk PASS "1 new *Mapper"

setup_repo; mk src/Market/ListingMapper.php; mk src/Deal/DealParser.php
SESSION=a2 chk PASS "2 new classes of different roles"

setup_repo; mk src/A/AParser.php; mk src/B/BParser.php; mk src/C/CParser.php
SESSION=a3 chk BLOCK "3 new *Parser in different modules" "rule 11"

setup_repo; mk src/Error/AuthException.php; mk src/Error/RateLimitException.php
SESSION=a4 chk PASS "2 new *Exception (role excluded on purpose)"

setup_repo; mk src/Generated/Operation/MarketListClient.php; mk src/Generated/Operation/DealListClient.php
SESSION=a5 chk PASS "new classes under src/Generated are ignored"

setup_repo; mk src/Generated/Operation/MarketListMapper.php; mk src/Market/ListingMapper.php
SESSION=a6 chk PASS "one generated + one hand-written Mapper is not a pair"

setup_repo; mk tests/Market/ListingMapper.php; mk tests/Deal/DealMapper.php
SESSION=a7 chk PASS "new classes in tests/ are ignored"

echo "== Modified files are not new =="
setup_repo
mk src/Foo/AFactory.php; mk src/Foo/BFactory.php
git -C "$tmp/repo" add -A >/dev/null; git -C "$tmp/repo" commit -qm add >/dev/null
printf '// touch\n' >>"$tmp/repo/src/Foo/AFactory.php"
printf '// touch\n' >>"$tmp/repo/src/Foo/BFactory.php"
SESSION=m1 chk PASS "editing 2 existing *Factory"

setup_repo; mk src/Foo/AFactory.php; mk src/Foo/BFactory.php
git -C "$tmp/repo" add -A >/dev/null
SESSION=m2 chk BLOCK "staged (added, not committed) files count as new"

echo "== Gates =="
setup_repo; mk src/Foo/AFactory.php; mk src/Foo/BFactory.php
out1="$(run g1 false)"; out2="$(run g1 false)"
if [ -n "$out1" ] && [ -z "$out2" ]; then echo "  ok    PASS  a repeat in the same session does not block"
else echo "  FAIL  session marker does not work (1='${out1:0:20}' 2='${out2:0:20}')"; fail=$((fail+1)); fi

if [ -f "$tmp/repo/var/claude/reuse-check-done" ] && grep -qx '\*' "$tmp/repo/var/claude/.gitignore" 2>/dev/null; then
  echo "  ok    STATE marker in var/claude/ with a self-ignoring .gitignore"
else echo "  FAIL  marker not in var/claude/ or .gitignore missing"; fail=$((fail+1)); fi

out3="$(run g2 true)"
if [ -z "$out3" ]; then echo "  ok    PASS  stop_hook_active=true does not block"
else echo "  FAIL  stop_hook_active is ignored"; fail=$((fail+1)); fi

setup_repo; mk src/Foo/AFactory.php
out4="$(printf '{"session_id":"g3","stop_hook_active":false}' | CLAUDE_PROJECT_DIR="$tmp/nonexistent" bash "$HOOK" 2>/dev/null)"
if [ -z "$out4" ]; then echo "  ok    PASS  outside a git repository exits quietly"
else echo "  FAIL  fired outside a repository"; fail=$((fail+1)); fi

echo "== Mutations: the cases above must turn red on a broken hook =="
# A green test that is also green on a broken implementation checks nothing.
# Each mutation edits a COPY of the hook in the temp dir; the original is untouched.
MUT="$tmp/mutant.sh"
mut_chk() { # $1=want(BLOCK|PASS) $2=label $3=sed expression
  sed "$3" "$HOOK" >"$MUT"
  if cmp -s "$HOOK" "$MUT"; then printf '  FAIL  mutation did not apply: %s\n' "$2"; fail=$((fail+1)); return; fi
  local got=PASS
  [ -n "$(printf '{"session_id":"m%s","stop_hook_active":false}' "$RANDOM" \
    | CLAUDE_PROJECT_DIR="$tmp/repo" bash "$MUT" 2>/dev/null)" ] && got=BLOCK
  if [ "$got" = "$1" ]; then printf '  ok    %-5s %s\n' "$got" "$2"
  else printf '  FAIL  want=%-5s got=%-5s %s\n' "$1" "$got" "$2"; fail=$((fail+1)); fi
}

setup_repo; mk src/Foo/AListingFactory.php; mk src/Foo/BListingFactory.php
mut_chk PASS "duplicate threshold 2->99 silences detection" "s/>= 2 { print/>= 99 { print/"

setup_repo; mk src/Error/AuthException.php; mk src/Error/RateLimitException.php
mut_chk BLOCK "Exception added to ROLES starts being caught" "s/|Validator|Factory'/|Validator|Factory|Exception'/"

setup_repo; mk src/Generated/Operation/MarketListClient.php; mk src/Generated/Operation/DealListClient.php
mut_chk BLOCK "dropping the src/Generated exclusion catches generated code" "s# ':(exclude,glob)src/Generated/\*\*'##"

echo
[ "$fail" -eq 0 ] && { echo "RESULT: all cases passed"; exit 0; }
echo "RESULT: failed cases: $fail"; exit 1
