---
name: handoff
description: >
  Write the state of the current work to a file a fresh session continues from without loss. Triggers: "/handoff",
  "make a handoff", "update the handoff", "add X to the handoff", "is the handoff current?" (and the same in Russian
  or Ukrainian). Create ONLY on explicit request (never on your own); once the file exists, keep it current until the
  session ends. Paired skill: /pickup reads the file in a new session.
---

# Session handoff

Writes the state of a work thread to a file the next session starts from, because answer quality drops long before the context window is full, and a deliberate handoff controlled by the author beats automatic summarization (`/compact`).

A handoff is **task state + instructions to the next executor**, NOT a chronology of this session.

## File

- **Name:** `HANDOFF_<slug>.md` in the repo root. Slug = issue number + short topic (`HANDOFF_12_retry_policy.md`) or topic alone (`HANDOFF_sailor_spike.md`): readable at a glance, since several threads may run in parallel.
- **One live handoff per thread, updated in place.** No dated copies.
- **Git hygiene:** never stage or commit it. `.gitignore` already contains `/HANDOFF_*.md`; confirm with `git check-ignore -q <file>`. If it is not ignored, add the mask `HANDOFF_*.md` to `.git/info/exclude` (asks permission), never individual names. Before any `git add` of neighbouring files, check `git check-ignore -v HANDOFF_*.md`.
- Delete the file when the task is fully closed, after asking the user.

## Invocation

- `/handoff` without arguments: exactly one handoff matches the current thread -> update it; none -> create; several plausible -> ask which.
- `/handoff <slug|issue|topic>`: explicit target.
- Mid-session "add X to the handoff": add X to the right section now. Anything the user named explicitly MUST end up in the file.
- "Is the handoff current?": re-read, compare with the real session state, fix every stale item, then confirm.

## Structure

```markdown
# Handoff: <#issue / topic> - <update date>
Branch: <branch> @ <short-hash> | Source session: <session id, if available>

## NEXT
<concrete first step for the new session; then a numbered plan at the level of WHAT>

## State / result
<result first, with numbers; then git: commits with hashes, pushed / NOT pushed,
what to commit and what NOT, how to restore anything deleted>

## Done
<one compact block per closed stage: hash, essence, who reviewed,
what was deliberately postponed (follow-up, not a merge blocker)>

## Discarded - do not return
<dead ends and dropped hypotheses WITH THE REASON>

## Remember
<environment gotchas, sharp details, commands with checkpoints proving the environment is alive (all via `make ...`)>

## Artifacts
<links to issues/PRs/specs/ADRs, key files with their role; durable knowledge by link
to `docs/` or memory, NOT a copy>

## Open questions
<decision points AS QUESTIONS, or an explicit "None">
```

Drop empty sections. The file is written in English (it lives in the repo directory). Session id: try `$CLAUDE_SESSION_ID`; otherwise the newest `.jsonl` in `~/.claude/projects/<this project>/`; with parallel sessions this is ambiguous, so omit rather than guess. It lets the next session fetch details via `claude --resume <id>`.

## Content rules

- **WHAT, not HOW.** No step-by-step investigation plans, no solutions presented as conclusions, no risk/effort forecasts. Strip markers like "probably worth...", "I recommend...", "most likely the root cause...". Do not transplant this session's dead ends and wrong models. Allowed: reproduction steps, symptoms/facts, what was tried and excluded (with numbers), decision points as questions.
- **Result first.** "What was achieved + numbers", not "what was done".
- **Negative knowledge is first-class.** What was excluded and WHY.
- **Link, do not duplicate.** Durable knowledge belongs in `docs/` (SDK_SPEC, WHITEMARKET_API, ADRs, DEVIATIONS); the handoff links to it.
- **Record discrepancies** between the file's assumptions and reality (e.g. manual edits outside the branch).
- **Never write anything the data policy forbids** (CLAUDE.md "Data policy"): tokens, IPs, personal data, real account data.

## Living document

- Create only on request. Update an EXISTING file on request and proactively right after a stage completes (fold the detailed stage plan into a short "Done" block with hashes; say so in one line).
- After writing, re-read the file once and remove everything that breaks the content rules; only then report it ready.
