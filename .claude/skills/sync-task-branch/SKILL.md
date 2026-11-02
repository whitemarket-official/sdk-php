---
name: sync-task-branch
description: >
  Use when the task branch (feature/<issue>-*, fix/<issue>-*) must be brought up to date with main: "merge main into
  the branch", "update the branch", "the branch is behind", "merge conflicts", a PR shows "This branch has conflicts"
  or "out-of-date with the base branch". Also when a merge into the task branch is already in progress (MERGE_HEAD
  exists, "You have unmerged paths", "All conflicts fixed but you are still merging").
---

# Bring the task branch up to date

`main` is merged into the task branch with a **merge**, conflicts are resolved by meaning, and the merge **is finished with `git merge --continue`**. A merge left at "conflicts resolved, only continue left" is unfinished work.

**Invoking this skill = the user's explicit request to create a merge commit** (CLAUDE.md rule 8 is satisfied by the request itself). The permission covers only this merge commit. Push, separate fix commits and amend still require a separate request.

## Direction and method

| What | How |
|---|---|
| Direction | only `origin/main` -> task branch. Never merge the task branch into `main` |
| Method | `git merge`, not `rebase` (rebasing a published branch needs force push, rule 9) |
| Source | `origin/main` after `git fetch`, not local `main` |
| Commit message | the default `Merge remote-tracking branch 'origin/main' into <branch>`; no `type(scope)` needed |

## Steps

1. **Preconditions.** `git branch --show-current` is a task branch, not `main`. `git status --porcelain` is empty. Dirty tree -> stop and ask (never `stash` silently). If `git rev-parse -q --verify MERGE_HEAD` exists, a merge is in progress: go to step 4.
2. `git fetch origin main`
3. `git merge origin/main --no-edit`. No conflicts: commit created, go to step 7.
4. **Conflict list:** `git diff --name-only --diff-filter=U`. In a merge `ours` = task branch, `theirs` = `main`. Resolve each file by the table below.
5. **Verify the resolution:**
   - `git diff --check` and `git grep -n -E '^(<{7}|={7}|>{7})( |$)' -- $(git diff --name-only --cached)`: no markers;
   - for each touched `*.php`: `php -l <file>`;
   - `git add <files>`; `git diff --name-only --diff-filter=U` is empty.
6. **`GIT_EDITOR=true git merge --continue`** (non-interactive, default message). A hook fails -> fix the cause and repeat `merge --continue`. `--no-verify` is forbidden.
7. **Completion check:** `git rev-parse -q --verify MERGE_HEAD` empty, `git log -1 --format='%P'` has two hashes, `git status` clean.
8. **After the merge.** A conflict was resolved in `*.php`, or the merge brought `composer.lock` / `schema/` / `graphql/` changes -> `Skill(quality-checks)` before claiming "branch is up to date and working". Fixes from QC stay uncommitted in the working tree; report to the user.

## Resolution by file type

| File | How |
|---|---|
| Hand-written code (`*.php`, `Makefile`, yaml) | Understand both sides: `git log --oneline --merge -- <file>`, `git diff :1:<file> :3:<file>` (what main changed), `git diff :1:<file> :2:<file>` (what the task changed). The result contains both. No `-X ours/theirs` or `checkout --ours/--theirs` on code |
| `composer.lock` | Never by hand. `git checkout --theirs composer.lock`, then re-run `composer update <packages the task changed in composer.json>` through the Makefile's Composer (or `composer update --lock` if none) |
| `composer.json` | Like code: both sides, then the lock as above |
| `src/Generated/`, `graphql/operations/`, `graphql/fragments/`, `graphql/operations/CHECKSUMS` | Never resolve by hand: take `--theirs`, then `make codegen` from the merged `schema/` and `graphql/overrides/`; `make codegen-check` clean |
| `schema/*` | Take `--theirs` (main has the newest synced snapshot); if the task branch changed the schema intentionally, stop and ask |
| `CHANGELOG.md` | Keep both sides' entries under the right headings |
| `CLAUDE.md`, `.claude/**`, `.mcp.json` | Merge by meaning and report to the user as a separate item (security zone, human review) |

A conflict where the sides contradict each other in meaning (both change the same behaviour differently, money, retry/reconciliation, auth) -> stop, show both versions, ask. Leave the merge in progress; no `git merge --abort` without the user's consent.

## Red flags

- "Conflicts resolved, only `git merge --continue` left" in the answer instead of doing it.
- "Committing without a request is forbidden, so I stop the merge": the request to update the branch is the request.
- `git commit -m "..."` with a custom message instead of `merge --continue`.
- `git rebase`, `git push --force`, `git merge --abort` on your own initiative.
- Whole-file `--ours`/`--theirs` on hand-written code to go faster.
- Claiming "done" without step 7.

## Report

```
Branch <branch> updated from origin/main: <merge-hash>
Conflicts: <file> - how resolved (one line per file) | none
Checks: php -l / quality-checks - result (or: not run, why)
Not pushed.
```
