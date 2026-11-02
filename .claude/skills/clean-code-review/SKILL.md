---
name: clean-code-review
description: Use BEFORE quality-checks, after the implementation is written and before any claim of readiness, when the diff adds a public type, more than 100 lines of hand-written PHP, or touches auth, money, buying/reconciliation, retry policy or the live protocol. Clean Code review by a subagent on a strong model: duplication between new classes, oversized methods, mixed abstraction levels, schema names leaking into the high-level API. Returns executable recommendations; the main session edits.
---

# clean-code-review: quality review before quality-checks

Readiness chain (CLAUDE.md rule 0-):

```
implementation -> clean-code-review -> quality-checks -> secure-coding -> claim
```

In that order. Structural changes are cheaper **before** tests pile up on the code and PHPStan starts reporting consequences of bad structure instead of its cause. Merging three paginators into one after writing tests for each costs three times as much.

## Why

The typical failure of generated code: it solves each task head-on, so the same logic reappears in several classes. Canonical case: three high-level methods return paginated lists and each gets its own iterator with its own page decoding, instead of one lazy paginator parameterized by the operation and the item mapper. PHPStan and the `reuse-check` hook see file names and numbers, not that two implementations differ by one line. That is the reviewer's zone.

## When mandatory

At least **one** of:

- a **new public type** (class, interface, enum) in `src/` outside `src/Generated/`;
- more than **100 added lines** of hand-written PHP (only `+` lines):

  ```bash
  git diff HEAD --numstat -- '*.php' ':!src/Generated/**' | awk '{s+=$1} END {print s}'
  ```

  Reformatting, bulk renames and signature updates produce big diffs with zero new code; those need no review;
- anything touching auth, money/decimals, buying/reconciliation, retry policy, or the live protocol.

## When to skip

- Diff under 20 lines without new types.
- Only `tests/`, `docs/`, `*.md`, `.claude/`, config.
- Mechanical edit by a clear spec (bulk rename, regenerated code, signature follow-up).
- One-two line bug fix with a failing test already in place.

Skipped: say so in one line in the report to the user, so the skip is a visible decision (CLAUDE.md rule 0-).

## How to run

Subagent `code-reviewer` (`.claude/agents/code-reviewer.md`, opus). It takes the diff itself; **do not paste the diff into the prompt**.

The prompt contains exactly:

1. The diff command: `git diff HEAD -- '*.php' ':!src/Generated/**'` (or `git diff main...HEAD -- ...` if the work spans commits).
2. One or two sentences on the task and the SDK_SPEC section it implements. Without that the reviewer cannot tell intended separation from copy-paste.
3. Explicit constraints: what cannot change and why (public API already released, SDK_SPEC naming, parity with sister SDKs).
4. The scope, verbatim: "Give recommendations on **added** lines. Judge code that existed before this diff only as context; findings on it go to a separate `[legacy]` list and are not applied in this task."

**Do not include** your justification of why it is written this way. Same isolation rule as `adversarial-verifier`.

## What to do with the report

Sections: `CONSOLIDATE`, `SPLIT`, `RENAME`, `STRUCTURE`, `Leave as is`, `[legacy]`, `Noticed in passing`.

- **`CONSOLIDATE`: apply by default.** That is the main defect. Not applying: name the concrete difference that outweighs the common part (CLAUDE.md rule 11).
- **`SPLIT`, `RENAME`, `STRUCTURE`**: your decision, but a rejection is recorded in one line with the reason, never silently. A `RENAME` of an already released public symbol is a breaking change (rule 16): deprecate first.
- **`[legacy]`**: **do not apply**. Drive-by refactoring bloats the diff and rewrites `git blame`. List for the user as `path:line - what`, one line each.
- **`Noticed in passing`**: a correctness defect is separate work: fix with a failing test (rule 0b) or report to the user. Never swallow it.
- The report is not a gate in the sense of rule 0; it informs. The gate is `quality-checks`.

## Consolidate before you hand over

Before the claim, look at your own diff for two classes of the same role (Mapper, Builder, Parser, Client, Validator, Decoder, Store, Factory). Two of one role -> one abstraction with parameters, or name the difference that outweighs the common part (CLAUDE.md rule 11). Also check the sister SDK for the same feature (`../whitemarket_sdk_*`): a structure that differs without a language reason is a parity smell (rule 18).

## Cycle limit

**Maximum two passes.** The first after implementation. The second only if `CONSOLIDATE` changes were structural (new interface, moved responsibility). There is no third: if after two passes the reviewer still finds structural problems, the task is designed wrong; stop and discuss with the user.

One run per finished implementation, not per small edit.

## Sources of rules

`CLAUDE.md` (Hard rules, Language rules) and `docs/SDK_SPEC.md` (names, models, errors), then idiomatic PHP 8.2+: `final readonly` value objects, enums with unknown fallback, `Generator` iterators, constructor promotion, named arguments for option objects, no static mutable state. On conflict between Clean Code and `CLAUDE.md`, `CLAUDE.md` wins.
