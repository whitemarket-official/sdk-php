---
name: analyze-plan
description: Use AFTER a plan is written and BEFORE implementing it (superpowers:executing-plans / subagent-driven-development): consistency gate spec <-> plan <-> docs/SDK_SPEC.md <-> CLAUDE.md. Checks requirement coverage, Hard rule violations, existence of referenced files/classes, tests and acceptance criteria, unchecked assumptions. Verdict GO/NO-GO.
---

# analyze-plan: consistency gate before implementation

`quality-checks` and `secure-coding` sit **at the exit**, after code exists. This gate sits **at the entry**: it catches mismatches between spec, plan, SDK_SPEC and project rules before they become commits.

Reads and analyzes. **Changes nothing silently**: findings go to a list, the human decides on each.

## When

- A plan is written in `docs/plans/` -> **before** implementation.
- A plan comes from another session or branch and is to be continued.
- A plan is older than a couple of weeks: the code under it may have moved (check 4).

## Six checks

### 1. Coverage: spec <-> plan

- Every requirement of the spec (and every SDK_SPEC item the plan claims to implement) -> a plan task. Missing = **gap**.
- Every plan task -> traces to a spec item. Not traceable = **orphan**: scope creep or an unrecorded decision.
- Spec "out of scope" -> no plan tasks about it.

Output: table `spec requirement | plan task | status`.

### 2. Rules: plan vs CLAUDE.md and SDK_SPEC

Look at the **content of the steps**, not a disclaimer in the header.

| Rule | Violation sign in the plan |
|---|---|
| 0b tests | a task adds logic but no test file in its `Files:`; a bug fix without a failing test as step 1 |
| 0-, 0, 0a readiness chain | no `clean-code-review` / `quality-checks` step; a security-zone task (auth, transport, token store, live, export download, CI, release) without `secure-coding` |
| 1 generated code | a step edits `src/Generated/`, `schema/`, `graphql/operations/`, `graphql/fragments/` by hand |
| 2 mutations | any retry of a mutation; buying without `customId` + reconciliation per SDK_SPEC 3.3 |
| 3 exact values | `float` for money/wear; 64-bit ids as `int` |
| 4 errors | branching on error `message` |
| 5 unknown | enum/event mapping without an unknown variant |
| 6, 7 batching | several mutations in one document; a request per loop iteration where a batch endpoint exists |
| 10 dependencies | new runtime package without an ADR |
| 12 naming | raw schema names in high-level signatures |
| 16 SemVer | a public symbol renamed/removed or a default changed without a CHANGELOG "Breaking" entry |
| 17 deviations | a deviation from SDK_SPEC not recorded in `docs/DEVIATIONS.md`; one changing public API/retry/auth/money without a stop-and-ask |
| 18 parity | behaviour the spec does not cover, with no SDK_SPEC change proposed first |
| 8, 9 git | a `git commit`/`git push` step, force push, `--no-verify` |
| Commands | a step running tools outside `make` |

### 3. Internal consistency

- Task N uses something created in task N+3 -> wrong order.
- The same file edited in two tasks inconsistently.
- A task without a "done when" criterion.
- A task too big for one run (dozens of files, "refactor all of X") -> propose a cut.
- External dependency (`Depends on:` another plan / phase, a sister SDK's CHECKSUMS) exists and is done.

### 4. Reality: plan vs code

- Classes, interfaces, methods named in the plan exist **now**: CodeGraph (`codegraph_search`, `codegraph_node`), not grep loops.
- Signatures and member count of an interface to implement.
- "Mirror X" files are in place and X still does what the plan assumes.
- An enum getting a new case: find **all** `match` over it.
- Operations the plan relies on exist in `graphql/operations/` and the schema field in `schema/partner.graphql`.

### 5. Acceptance

- The spec has checkable acceptance criteria (observable results, not "works correctly").
- Each criterion maps to a test or an explicit manual check in the plan; SDK_SPEC 7 cases for the touched area are present.
- Bug fix: failing test first.

### 6. Assumptions

The plan's "Assumptions" section lists what is believed but unchecked.

| Sign | Severity |
|---|---|
| Section missing/empty while the plan touches public API, money, buying, auth, retry or live protocol | BLOCKER |
| Section missing/empty otherwise | WARN |
| A clarify-spec category 12 assumption not carried into the plan | BLOCKER for money/buying/auth, else WARN |
| `unchecked` about money, buying, auth or the server contract with no step that checks it | BLOCKER |
| The check step comes **after** a task that relies on the assumption | BLOCKER |
| "How it is checked" vague ("clarify later") | WARN |
| A parameter, value or semantics of an **external system** (library version, CLI flag, GitHub Action input, Centrifugo protocol) without `[code] file:line` / `[docs] URL@version` / `[server]`, with config or a step built on it (CLAUDE.md rule 14) | BLOCKER |
| `[docs]` without a URL | WARN, counts as `[memory]` |
| A spike without a control check ("the setting was applied and the effect is visible") or without an "invalid setup" outcome | BLOCKER |
| A negative conclusion ("does not work", "we drop it") without an `adversarial-verifier` step | BLOCKER |

Along the way: an assumption about **this** code that one CodeGraph call settles, check it here and report `confirmed (file:line)` or `refuted`. One or two key `[memory]` lines about an external system that the plan rests on: `WebFetch` the docs of that version and report the status with the URL. Do not edit the plan.

An "assumption" that is really a decision ("we assume the caller handles cancelled deals") is an open question: move it to the spec's Decisions via `clarify-spec`.

## Report format

```
## Analyze: <plan file>  ->  verdict: NO-GO

| # | Severity | Check | Finding | Where |
|---|---|---|---|---|
| 1 | BLOCKER | #2 rules | Task 6 retries `market_buy` on 503 (rule 2) | plan.md Task 6, Step 3 |
| 2 | BLOCKER | #1 coverage | SDK_SPEC 3.3 reconciliation not covered by any task | SDK_SPEC §3.3 |
| 3 | BLOCKER | #6 assumptions | A2 "statuses limit is 300" unchecked, Task 4 already chunks by it | plan.md Assumptions A2, Task 4 |
| 4 | WARN | #4 reality | Plan refers to `DealMapper`, code has `DealModelMapper` | plan.md Task 10 |

**BLOCKER:** 3 -> do not start; fix the plan.
**WARN:** 1 -> at discretion, record the decision.
```

`GO` only with zero BLOCKERs. Every WARN is either fixed or explicitly accepted, never silently ignored.

## What this gate does NOT do

- Does not run code, tests, PHPStan (`quality-checks`, at the exit).
- Does not review written code (`clean-code-review`, `/code-review`).
- Does not rewrite the plan itself; edits only after the human agrees with the findings.
