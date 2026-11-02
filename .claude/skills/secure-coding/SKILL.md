---
name: secure-coding
description: Use AFTER quality-checks and BEFORE claiming work complete, when the diff touches auth, transport (headers, logging, TLS, redirects), token storage, live connections (SSE/WS), export downloading, purchase reconciliation, retry policy, composer.json, CI or release workflows. SDK security checklist with PASS/FAIL/WARN/N/A per item; FAIL blocks the claim.
---

# secure-coding: security check before the claim

Run **after** `quality-checks` and **before** any claim of readiness when the session edited a security zone (CLAUDE.md rule 0a). The requirements come from `docs/SDK_SPEC.md` sections 1.3, 1.4, 1.5, 3.3 and 8.

## Algorithm

### Step 1: scope

```bash
git diff --name-only HEAD          # uncommitted work
git diff --name-only main...HEAD   # work spread over commits
```

### Step 2: activate checklists by path

`transport-auth.md` is active whenever any `src/**/*.php` in the zone changed. Add more by path:

| Path / content | Activates |
|---|---|
| transport, auth, token store, secret type, logging, error messages, retry, buying/reconciliation | `transport-auth.md` |
| live clients (SSE/WS), export client and parsers, mappers of server data | `untrusted-data.md` |
| `.github/workflows/**`, `composer.json`, `composer.lock`, release scripts, `Makefile` targets used by CI | `ci-release.md` |

Several active checklists merge (dedupe by ID). This table is the only copy of the map; a new checklist is added here.

### Step 3: run the items

- **`[code]`**: run the command from "How to check" (grep / Read / `make`), then read the hits.
- **`[review]`**: read the diff and judge.

Result per item:
- `PASS`: the check passed.
- `FAIL`: **blocker**. Fix the code, re-run the skill until green.
- `WARN`: goes to the final report for a human look, does not block.
- `N/A`: the item does not apply to this diff (say why in three words).

### Step 4: report

```
secure-coding: N items over M checklists (transport-auth, <list>).
PASS X   WARN Y   FAIL Z   N/A W

FAIL <ID>: <short description>
   file: <path:line>
   action required: <what to do>

WARN (manual review):
- <ID>: <description>

ready to claim: YES/NO
```

- `FAIL > 0`: claiming "done" is FORBIDDEN. Fix and re-run.
- `WARN > 0`: claim allowed, every WARN reported to the user.
- Fixes for FAIL items in auth, reconciliation, retry or redaction go to `adversarial-verifier` (mode A) before the claim (CLAUDE.md "Delegation").

## Checklists

- [`checklists/transport-auth.md`](checklists/transport-auth.md): secrets, redaction, TLS, redirects, token store, retry/mutations, reconciliation, error messages.
- [`checklists/untrusted-data.md`](checklists/untrusted-data.md): live messages, export rows, size and decompression limits, unknown values, URL building.
- [`checklists/ci-release.md`](checklists/ci-release.md): dependencies, GitHub Actions hardening, release provenance.

## When to skip

- Only `tests/`, `docs/`, `*.md`, `.claude/` changed (but `.claude/hooks` and `settings.json` changes go to human review, CLAUDE.md "Agent configuration").
- Pure refactor with no behaviour change outside the zone. In doubt: **do not skip**.

Before a PR: `/security-review` over the whole branch.
