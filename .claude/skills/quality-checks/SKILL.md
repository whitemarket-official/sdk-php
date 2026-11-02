---
name: quality-checks
description: Use BEFORE claiming PHP work is complete or proposing a commit/PR. Mandatory pre-finish workflow = the parts of `make check` in order (stan -> rector-check -> fmt-check -> fmt if needed -> stan again -> coverage -> codegen-check -> audit + deps). Cheap read-only static runs FIRST, before the mutating autofix. Each step green before the next; a violation on pre-existing code is suppressed in place with a reason and an issue reference, not refactored. During iteration use the fast loop (make stan + make test FILTER=...), full run once.
---

# Quality checks: mandatory before finishing

Run **in order**, fix **every** error before the next step (CLAUDE.md rule 0). Claiming "done" with red checks is forbidden.

## Full run once; fast loop while working

The full steps 1-8 are needed **once**, before the readiness claim. Running them after every edit is the most expensive way to check yourself: most full runs fail on static analysis and never reach the tests.

While working, use the subset. These two may run **inline in the main session**: make targets filter output down to errors and a summary.

```bash
make stan                            # static analysis
make test FILTER=<ClassName>         # the relevant tests, not the whole suite
```

The full output of every filtered target is in `var/qc/<target>.log`: `grep` it instead of re-running; never read it whole into context. `VERBOSE=1 make <target>` disables the filter (for a human debugging, not for the agent).

Rule: **output filtered down to a verdict may run inline; everything else goes to a subagent.** More than ~20 errors or unclear output: hand it to the subagent.

The fast loop does **not** replace the full run.

## Who runs it: the subagent

The full run is done by `Agent(subagent_type: "qc-runner")` (`.claude/agents/qc-runner.md`, sonnet). Prompt: "Run quality-checks steps 1-8". Reason: tool output is resent with every later request of the session.

Subagent contract (CLAUDE.md "Delegation"):

```
Step N (<command>): PASS | FAIL
On FAIL: up to 20 errors as `path:line - message`, the total count,
the last 15 lines of output. Later steps not run.
```

The main session fixes (it has the task context); the re-run is the subagent again. The subagent never edits code. Unclear output or a crashed Docker container is reported as a fact, never as "PASS".

## Step 1: static analysis (before autofix)
```bash
make stan
```
PHPStan level max + phpstan-strict-rules + phpstan-deprecation-rules. Fix everything. Static goes first because the next steps may **change code**; autofixing unchecked code means paying twice.

## Step 2: refactoring rules (read-only)
```bash
make rector-check
```
Rector dry-run. A reported change: apply it by hand or via the Rector target only if you understand it. **Rector deletes code, not only rewrites it**: dead-code rules evaluate conditions statically and can remove a guard plus the method body. A check that must fire in the future must not be statically computable now.

## Step 3: format check (read-only)
```bash
make fmt-check
```

## Step 4: autofix (mutating, only if step 3 failed)
```bash
make fmt
```
php-cs-fixer (PER-CS 2.0, `declare_strict_types`, `no_unused_imports`). Note **which files** it changed.

## Step 5: static analysis again
```bash
make stan
```
**Only if step 4 changed something**; otherwise step 1 stands.

## Step 6: tests + coverage gate
```bash
make coverage
```
Full PHPUnit run with the 85 % line coverage gate (generated code excluded). A drop below the gate almost always means new code without tests: fix with tests (rule 0b), never by lowering the threshold or adding `@codeCoverageIgnore` without a reason and an issue reference.

## Step 7: generated code is in sync
```bash
make codegen-check
```
Dirty: someone edited generated paths or forgot `make codegen`. Fix by regenerating, never by hand (CLAUDE.md rule 1).

## Step 8: dependencies
```bash
make audit
make deps
```
`audit`: known vulnerabilities; fix by updating the package. `deps` (composer-dependency-analyser): a class used from a package not declared in `composer.json` (shadow dependency, CLAUDE.md rule 10), unused dependencies, dev packages in `src/`. Fix by declaring the package, not by an ignore rule. An ignore entry needs a reason and an issue reference.

Only when **all steps** are green is the work ready.

## Pre-existing code

A violation on a symbol that existed before your diff (a new PHPStan level, a rule upgrade, your addition pushed it over a threshold) is **suppressed in place**, not refactored: a targeted suppression with a reason and an issue reference on the same or previous line (CLAUDE.md rule 15), plus one line to the user. Check what you actually added:

```bash
git diff HEAD -- <file> | grep -c '^+'
```

Never add new errors to a baseline file.

## On demand, outside the mandatory run

- `make infection`: mutation testing (MSI threshold in `infection.json5`). Before a PR touching core logic, or when asked.
- `make matrix`: `make check` in Docker for every supported PHP version. Before a release, or after touching version-sensitive code.
- `make hooks-test`: after touching `.claude/hooks/**`.

## When to skip

Only `.claude/` skills/agents/commands, `*.md`, `docs/` without code blocks that are compiled: steps 1-8 may be skipped; say so in one line.
