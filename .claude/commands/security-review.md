---
description: Pre-PR deep security review of the whole branch (git diff main...HEAD, or the whole tree before the first commit) against every secure-coding checklist plus SDK deep checks. Run before `gh pr create`.
---

# /security-review: whole-branch security review

Applies **all** checklists of `.claude/skills/secure-coding/checklists/` to **the whole branch** plus the deep checks below. Run explicitly by the user before a PR (CLAUDE.md rule 0a).

## When

- Before `gh pr create` / the first push of a branch.
- After `quality-checks` and `secure-coding` are clean on the latest edits.
- To catch what slipped between in-flight `secure-coding` runs over several commits.

## Step 1: scope

```bash
git rev-parse --verify -q main && git log --oneline main..HEAD && git diff --name-only main...HEAD
```

No `main` or no commits yet: review the whole tree (`git ls-files`, plus untracked `git status --porcelain`). Base unclear: ask the user.

## Step 2: run every checklist

All of them, not only those active by path: `transport-auth.md`, `untrusted-data.md`, `ci-release.md`, over the full branch diff (`git diff main...HEAD`, or the whole tree).

## Step 3: deep checks

- **DEEP-REDACTION-TESTS (blocker)**: tests exist that prove the token never appears in log output, exception messages, `var_dump`/`print_r`/`json_encode`/`serialize` of the client, config and `Secret`.
- **DEEP-MUTATION-RETRY (blocker)**: list every place that sends a GraphQL document; for each, show that a mutation cannot reach a retry loop. A test asserts one request on 503 for a mutation.
- **DEEP-RECON (blocker)**: the reconciliation code matches SDK_SPEC 3.3 step by step; tests cover found in `deal_item`, found in history, not found.
- **DEEP-REDIRECT (blocker)**: a cross-host redirect test exists and asserts no `Authorization` / `X-partner-token` on the second request.
- **DEEP-PUBLIC-SURFACE (warning)**: new public classes/methods that accept a URL, a path, a callable or a raw document; each is intended and documented.
- **DEEP-DEPS (warning)**: `git diff main...HEAD -- composer.json composer.lock`; every new or major-bumped package has an ADR and a changelog look.
- **DEEP-SECRET-IN-HISTORY (blocker)**: the `SECRET-HISTORY-1` grep over `git log -p main..HEAD`. A hit stays a blocker until rotated, even if removed later.
- **DEEP-WORKFLOWS (blocker)**: `actionlint` clean (Docker `rhysd/actionlint` if not installed); GHA-1..GHA-4 over every workflow, not only changed ones.

## Step 4: adversarial verification of findings

A finding judged by the context that produced it is not verified. Every `blocker` finding goes to `adversarial-verifier` (mode B) before the verdict.

**Sent to the subagent:** the diff fragment or `path:line`, one sentence with the claim to refute, how to reproduce if known.
**Not sent:** your reasoning on why the finding is real, what you already checked, which alternatives you rejected.

Verdicts:
- `REFUTED` -> the finding is dropped and recorded as FP with what refuted it.
- `NOT REFUTED` -> stays; severity from the verifier's preconditions table, not from the bug category.
- `COULD NOT VERIFY` -> stays as `WARN` marked "reachability not proven".

Keep two fields of its report: **same defect elsewhere** (separate line in the report) and **statically codifiable** (candidate for a PHPStan rule or a hook; tell the user).

## Step 5: report

```
/security-review: branch <name> (<N> commits, <M> files changed)

Checklists: transport-auth, untrusted-data, ci-release + 8 deep checks
Items: <N>

PASS <X>
WARN <Y> (manual review):
- <ID>: <description>

FAIL <Z> (blockers):
- <ID>: <description> -> <action>

Ready for PR: YES/NO
```

## After the run

**One pass is not a review.** Fixing one problem reveals the next. Loop "find -> fix -> search again" 2-3 times until a pass yields nothing new. Zero findings on the first pass of a branch with substantial security-zone edits is a reason to re-check the scope.

- `FAIL` -> fix, re-run `/security-review`. No PR.
- Every security fix goes to `adversarial-verifier` mode A (re-attack) before closing.
- `WARN` -> decide with the user; a PR is allowed but lists the unresolved WARNs.
- Clean -> `gh pr create` is allowed (still only on the user's request).

## When not needed

Docs-only (`*.md`, `docs/`), tests-only, or `.claude/` skills/agents/commands-only changes (hooks and `settings.json` changes get human review instead).
