---
name: clarify-spec
description: Use AFTER a design spec is written and BEFORE writing a plan (superpowers:writing-plans): reads the spec for ambiguities and returns numbered questions with defaults. Also when a change to docs/SDK_SPEC.md is proposed, when new partner API docs arrive, and before implementing a GitHub issue without a spec. Gate: BLOCKER questions must be closed before the plan.
---

# clarify-spec: read the spec for ambiguities

Gate between **spec** and **plan**. `superpowers:brainstorming` works BEFORE the spec (collect requirements); this works AFTER: the spec exists, find what the implementer would **silently invent**. Under-specification, not model stupidity, is the main reason implementations go astray.

Budget: 10-20 minutes per spec. No code is written; no project files are edited except the "Decisions" section of the spec itself.

## When

- A design doc or an SDK_SPEC change is written -> **before** `superpowers:writing-plans`.
- New or changed partner docs / `docs/WHITEMARKET_API.md` facts -> before they become spec.
- A GitHub issue bigger than a one-line fix with no spec -> run the checklist over the issue text.

Skip: bug fix with a known root cause, mechanical edit, docs-only change.

## Protocol

1. **Read the whole spec.** Questions are born at the seams between sections.
2. **Run the category checklist** (below). A category the spec does not mention at all is itself a finding: "nothing about retries" is a question, not "N/A".
3. **Filter.** Keep only questions where **different answers produce different code**. A question with one reasonable answer is a default: record it as a decision, do not ask.
4. **Close yourself what needs no human.** For each remaining gap pick a method (`Close:` field below). Only `ask` goes to the human; `check` and `test` are closed before the list is delivered, the answer goes to "Decisions" with its source (`file:line`, docs URL, test output).

   | Gap type | `Close:` | With |
   |---|---|---|
   | Preference, product decision, priority | `ask` | the user |
   | Fact about this SDK | `check` | CodeGraph, `CLAUDE.md`, `docs/decisions/`, `docs/DEVIATIONS.md` |
   | Fact about the API | `check` | `docs/WHITEMARKET_API.md`, `schema/partner.graphql`, partner docs URL |
   | Fact about a sister SDK | `check` | `../whitemarket_sdk_*` (read only) |
   | Behaviour (what the server really returns) | `test` | fixture from the public docs, opt-in live read-only call |
   | Reversible, cheap to undo | `accept` | recorded as an assumption (category 12), not a question |

   An open API question listed in `WHITEMARKET_API.md` section 11 is never guessed: it is `ask` (the backend team) or the spec states the SDK behaviour for both outcomes.
5. **Deliver the list** in the format below. Max ~10 questions per pass; more means the spec is raw: say so and send it back.
6. **Record answers** in the spec as `## Decisions (clarify, YYYY-MM-DD)`: question, answer, who answered or how it was checked. Answers in chat do not count.
7. **Verdict:** open **BLOCKER** -> no plan; say it explicitly.

## Question format

```
### Q3 [BLOCKER] Buying: what if reconciliation finds the deal in a cancelled state

**Where:** §3.3 "Reconciliation": says "if found -> return reconciled", not which statuses count.
**Why ambiguous:** a cancelled deal with our customId means the purchase happened and was reverted; returning it as success hides that.
**Options:** (a) return any found deal, caller inspects status; (b) throw a dedicated error for terminal-failed deals; (c) return with `reconciled: true` and document.
**Proposed:** (a), it matches SDK_SPEC "return reconciled" literally and keeps one code path; document in README.
**Close:** ask, it changes the public contract of buying in all 7 SDKs.
```

Rules:
- **Plain language.** One or two sentences of context, then the question.
- **One question, one thought.**
- **Always propose a default**, taken from SDK_SPEC, the reference PHP implementation, a sister SDK, or an ADR.
- **Severity:** `BLOCKER`: a wrong answer gives wrong code that is expensive to undo (public API, money, idempotency/retry, auth, security, cross-SDK parity). `DEFAULT`: take the proposed default and move on, recorded as a decision.

## Category checklist (for this SDK)

1. **Public API.** Method name and namespace per SDK_SPEC in PHP idiom; parameters vs options object; return type; nullability; is it a breaking change (CLAUDE.md rule 16).
2. **Money and ids.** Decimal representation, scale, currency; 64-bit ids as strings; wear values.
3. **Retry and idempotency.** Query or mutation; retried or not (SDK_SPEC 1.4); for mutations, what "outcome unknown" means and how it is reconciled.
4. **Errors.** Which `GraphQLError` categories are expected; mapping to the hierarchy of SDK_SPEC 1.5; what is a typed error vs a generic one.
5. **Unknown values.** New enum values, event types, fields: how they surface (unknown variant, raw value).
6. **Batching and pagination.** Batch endpoint exists (CLAUDE.md rule 7)? Chunk size; lazy pagination; stop conditions.
7. **Auth.** Which token; refresh and replay behaviour; token store interaction.
8. **Live and exports.** Transport (SSE/WS), reconnect and resubscribe, freshness, streaming vs buffering, size limits.
9. **Security and data.** What is logged, what is redacted, which data is untrusted.
10. **Parity.** Does the spec bind all 7 SDKs? Which language idioms are allowed to differ; does it need an SDK_SPEC change first (rule 18).
11. **Boundaries.** What is explicitly **not** in scope. A missing section is always a question.
12. **Assumptions.** What must be true for the spec to be right but nobody checked: the server always returns this field; this status is terminal; this call is idempotent; this limit is 300. For each: how it is checked (`Close:`), or `accepted unchecked` and what breaks if false. An unchecked assumption about money, buying or auth is a `BLOCKER`. Unchecked assumptions move to the plan's "Assumptions" section.

## Questions for the API owner

Questions only the WhiteMarket backend team can answer go in a separate block at the end, ready to paste into a GitHub issue or `WHITEMARKET_API.md` section 11. Do not block on them; mark dependent plan steps `TBD(Q3)`.

## Anti-patterns

| Sign | What is wrong |
|---|---|
| 25 questions, half rhetorical | Not filtered by "does the answer change the code" |
| Question without a default | Work shifted to the human |
| Asking the human what the code or docs already answer | Wrong `Close:` method; should be `check` |
| Zero assumptions | There are never zero; category 12 not done |
| Answers left in chat | Gone in a week. The spec file is the source of truth |
| "How do we implement X?" | That is the plan. Clarify asks **what**, not **how** |
