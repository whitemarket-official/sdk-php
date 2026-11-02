#!/usr/bin/env bash
# Regression tests for the Stop hook suppress-check.sh.
#
# Every case runs in a TEMPORARY git repository: the real project is not touched,
# and the session marker is written there too.
#
# Run from anywhere (only bash, git, jq, python3):
#   bash .claude/hooks/suppress-check-test.sh
# Exit code: 0 when every case passes, 1 otherwise.

set -u

HOOK="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/suppress-check.sh"
[ -f "$HOOK" ] || { echo "missing $HOOK"; exit 1; }

fail=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

setup_repo() { # clean repo: one committed php file, a baseline and an infection config
  rm -rf "$tmp/repo"
  mkdir -p "$tmp/repo/src/Foo"
  git -C "$tmp/repo" init -q
  git -C "$tmp/repo" config user.email test@example.invalid; git -C "$tmp/repo" config user.name test
  git -C "$tmp/repo" config commit.gpgsign false
  printf '<?php\n\nfinal class A\n{\n    public function run(): void\n    {\n    }\n}\n' >"$tmp/repo/src/Foo/A.php"
  printf 'parameters:\n    ignoreErrors:\n' >"$tmp/repo/phpstan-baseline.neon"
  printf '{\n    "minMsi": 80,\n    "mutators": {\n        "@default": true\n    }\n}\n' >"$tmp/repo/infection.json5"
  git -C "$tmp/repo" add -A >/dev/null
  git -C "$tmp/repo" commit -qm seed
}

# inserts lines $1.. before line 5 (`public function run`) of src/Foo/A.php
ins() {
  local f="$tmp/repo/src/Foo/A.php" body=""
  for l in "$@"; do body="${body}${l}\n"; done
  { head -n 4 "$f"; printf "$body"; tail -n +5 "$f"; } >"$f.new" && mv "$f.new" "$f"
}

# inserts lines $1.. after line 3 (`"mutators": {`) of infection.json5
ins_inf() {
  local f="$tmp/repo/infection.json5" body=""
  for l in "$@"; do body="${body}${l}\n"; done
  { head -n 3 "$f"; printf "$body"; tail -n +4 "$f"; } >"$f.new" && mv "$f.new" "$f"
}

run() { # $1=session $2=stop_hook_active(true|false)
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

echo "== Signal A: every PHP suppression form without reference + reason =="
n=0
for tag in \
  '// @phpstan-ignore argument.type' \
  '// @phpstan-ignore-line' \
  '// @phpstan-ignore-next-line' \
  '/** @psalm-suppress MixedAssignment */' \
  '/** @SuppressWarnings("PHPMD.CyclomaticComplexity") */' \
  '// @codeCoverageIgnoreStart' \
  '// @codeCoverageIgnore' \
  '// @infection-ignore-all' \
  '/** @noinspection PhpUnusedParameterInspection */'; do
  n=$((n + 1))
  setup_repo; ins "    $tag"
  SESSION=a$n chk BLOCK "$tag" "src/Foo/A.php:5"
done

echo "== Signal A: reference and reason rules =="
setup_repo; ins '    // @phpstan-ignore argument.type (Sailor result type is too wide, #12)'
SESSION=r1 chk PASS "#issue + reason on the same line"

setup_repo; ins '    // @phpstan-ignore argument.type (Sailor result type is too wide, https://github.com/example/sdk/issues/12)'
SESSION=r2 chk PASS "issue URL + reason on the same line"

setup_repo; ins '    // Generated union lacks a discriminator, see #12' '    // @phpstan-ignore-next-line'
SESSION=r3 chk PASS "#issue + reason on the previous comment line"

setup_repo; ins '    // @phpstan-ignore argument.type #12'
SESSION=r4 chk BLOCK "#issue without a reason" "rule 15"

setup_repo; ins '    // @phpstan-ignore argument.type (Sailor result type is too wide)'
SESSION=r5 chk BLOCK "reason without an issue reference"

setup_repo; ins '    // @phpstan-ignore argument.type see issue #12'
SESSION=r6 chk BLOCK "filler words (see issue) are not a reason"

setup_repo; ins '    // @phpstan-ignore argument.type (false positive, WM-1234)'
SESSION=r7 chk BLOCK "Jira-style key is not a GitHub issue reference"

setup_repo; ins '    $x = $this->decodeLegacyPayload($y);' '    // @phpstan-ignore-next-line #12'
SESSION=r8 chk BLOCK "code on the previous line does not count as a reason"

setup_repo; ins '    // an ordinary comment without a suppression'
SESSION=r9 chk PASS "added line without a suppression"

echo "== Signal A: existing suppression and reference in context =="
setup_repo; ins '    // @phpstan-ignore argument.type'
git -C "$tmp/repo" commit -qam pre >/dev/null
printf '// touch\n' >>"$tmp/repo/src/Foo/A.php"
SESSION=c1 chk PASS "suppression existed before the diff"

setup_repo; ins '    // Generated union lacks a discriminator, see #12'
git -C "$tmp/repo" commit -qam pre >/dev/null
f="$tmp/repo/src/Foo/A.php"; { head -n 5 "$f"; printf '    // @phpstan-ignore-next-line\n'; tail -n +6 "$f"; } >"$f.new" && mv "$f.new" "$f"
SESSION=c2 chk PASS "reason + reference on an unchanged line above the new suppression"

echo "== Signal A: untracked files =="
setup_repo; printf '<?php\n// @phpstan-ignore-next-line\n$a = 1;\n' >"$tmp/repo/src/Foo/B.php"
SESSION=u1 chk BLOCK "new file with a suppression" "src/Foo/B.php:2"

setup_repo; mkdir -p "$tmp/repo/tests"; printf '<?php\n// @phpstan-ignore-next-line\n' >"$tmp/repo/tests/BTest.php"
SESSION=u2 chk BLOCK "new test with a suppression"

setup_repo; printf '<?php\n// Fixture shape is intentionally invalid, #77\n// @phpstan-ignore-next-line\n' >"$tmp/repo/src/Foo/B.php"
SESSION=u3 chk PASS "new file, reference + reason on the line above"

setup_repo; mkdir -p "$tmp/repo/vendor/x"; printf '<?php\n// @phpstan-ignore-next-line\n' >"$tmp/repo/vendor/x/C.php"
SESSION=u4 chk PASS "outside src/tests/fixtures/tools is ignored"

echo "== Signal A: infection.json5 ignore entries =="
setup_repo; ins_inf '        "MethodCallRemoval": { "ignore": ["WhiteMarket\\\\Sdk\\\\Foo::bar"] },'
SESSION=i1 chk BLOCK "infection ignore entry without reference + reason" "infection.json5:4"

setup_repo; ins_inf '        // Logging call has no observable effect in unit tests, #31' '        "MethodCallRemoval": { "ignore": ["WhiteMarket\\\\Sdk\\\\Foo::bar"] },'
SESSION=i2 chk PASS "infection ignore entry with reference + reason above"

setup_repo; sed -i.bak 's/"minMsi": 80/"minMsi": 85/' "$tmp/repo/infection.json5" && rm -f "$tmp/repo/infection.json5.bak"
SESSION=i3 chk PASS "infection threshold change is not an ignore entry"

echo "== Signal B: baseline =="
setup_repo; printf '        -\n            message: x\n' >>"$tmp/repo/phpstan-baseline.neon"
SESSION=b1 chk BLOCK "lines added to phpstan-baseline" "phpstan-baseline.neon (+2)"

setup_repo; printf 'parameters:\n' >"$tmp/repo/phpstan-baseline.neon"
SESSION=b2 chk PASS "baseline lines only removed"

echo "== Gates =="
setup_repo; ins '    // @phpstan-ignore argument.type'
out1="$(run g1 false)"; out2="$(run g1 false)"
if [ -n "$out1" ] && [ -z "$out2" ]; then echo "  ok    PASS  same set in the same session does not block twice"
else echo "  FAIL  session marker does not work"; fail=$((fail+1)); fi

ins '    // @phpstan-ignore return.type'
out3="$(run g1 false)"
if [ -n "$out3" ]; then echo "  ok    BLOCK a new suppression in the same session blocks again"
else echo "  FAIL  a new suppression slipped through on the old marker"; fail=$((fail+1)); fi

if [ -f "$tmp/repo/var/claude/suppress-check-done" ] && grep -qx '\*' "$tmp/repo/var/claude/.gitignore" 2>/dev/null; then
  echo "  ok    STATE marker in var/claude/ with a self-ignoring .gitignore"
else echo "  FAIL  marker not in var/claude/ or .gitignore missing"; fail=$((fail+1)); fi

out4="$(run g2 true)"
if [ -z "$out4" ]; then echo "  ok    PASS  stop_hook_active=true does not block"
else echo "  FAIL  stop_hook_active is ignored"; fail=$((fail+1)); fi

out5="$(printf '{"session_id":"g3","stop_hook_active":false}' | CLAUDE_PROJECT_DIR="$tmp/nonexistent" bash "$HOOK" 2>/dev/null)"
if [ -z "$out5" ]; then echo "  ok    PASS  outside a git repository exits quietly"
else echo "  FAIL  fired outside a repository"; fail=$((fail+1)); fi

echo "== Mutations: the cases above must turn red on a broken hook =="
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

setup_repo; ins '    // @phpstan-ignore argument.type'
mut_chk PASS "without @phpstan-ignore in the list detection disappears" "s/^SUPPRESS='@phpstan-ignore|/SUPPRESS='/"

setup_repo; ins '    // Generated union lacks a discriminator, see #12' '    // @phpstan-ignore-next-line'
mut_chk BLOCK "ignoring the previous line makes a reason above useless" 's/if (is_comment(prev)) words += reason_words(prev)/words += 0/; s/if (cur !~ tk \&\& prev !~ tk) return 0/if (cur !~ tk) return 0/'

setup_repo; ins '    // @phpstan-ignore argument.type #12'
mut_chk PASS "without the reason requirement a bare #issue passes" 's/return words >= 2/return 1/'

setup_repo; ins_inf '        "MethodCallRemoval": { "ignore": ["WhiteMarket\\\\Sdk\\\\Foo::bar"] },'
mut_chk PASS "infection.json5 outside the scan is not caught" 's/^tracked_inf=.*/tracked_inf=""/'

setup_repo; printf '        -\n' >>"$tmp/repo/phpstan-baseline.neon"
mut_chk PASS "baseline outside the list is not caught" "s/^BASELINES=(phpstan-baseline.neon)/BASELINES=(none.neon)/"

echo
[ "$fail" -eq 0 ] && { echo "RESULT: all cases passed"; exit 0; }
echo "RESULT: failed cases: $fail"; exit 1
