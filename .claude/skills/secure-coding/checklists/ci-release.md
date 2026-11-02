# CI and release checklist

Applies to `.github/workflows/**`, `composer.json`, `composer.lock`, release scripts.

### [code] DEPS-1: dependency changes are justified - blocker

```bash
git diff HEAD -- composer.json
```
A new runtime `require` needs an ADR in `docs/decisions/` (CLAUDE.md rule 10). `make audit` and `make deps` are green. Package name checked for typosquatting on Packagist (exact vendor, download count, repository link).

### [code] DEPS-2: lockfile resolves for the lowest PHP - warning

`config.platform.php` pinned to the lowest supported version; `minimum-stability: stable`.

### [code] GHA-1: actions pinned to full commit SHAs - blocker

```bash
grep -rnE 'uses:\s*[^ ]+@' .github/workflows | grep -vE '@[0-9a-f]{40}'
```
Every hit is a FAIL (local `./` actions excepted). The SHA has a `# vX.Y.Z` comment, verified via `gh api repos/<owner>/<action>/git/ref/tags/<tag>`.

### [code] GHA-2: least-privilege permissions - blocker

Top-level `permissions:` present and minimal (`contents: read` by default); write scopes only on the job that needs them.
```bash
grep -LnE '^permissions:' .github/workflows/*.yml
```

### [review] GHA-3: no untrusted code with secrets - blocker

No `pull_request_target` (or `workflow_run`) that checks out PR head code. No `${{ github.event.* }}` interpolated directly into `run:` (pass through `env:`). Secrets only in jobs that need them, never on fork PRs.

### [review] GHA-4: no secrets in logs - blocker

No `echo` of secrets, no `set -x` around secret use, `WHITEMARKET_PARTNER_TOKEN` only via `env:` into the live smoke step, artifacts do not contain tokens or raw live responses.

### [review] GHA-5: concurrency and triggers - warning

Workflows that push or open PRs (`schema-sync.yml`) have a `concurrency` group; cron and `repository_dispatch` triggers cannot be fired with attacker-controlled payload into a shell.

### [review] REL-1: release provenance - warning

Releases are built from a signed / protected tag by the release workflow, not from a local machine; artifacts carry provenance (artifact attestation) where the workflow publishes any; CHANGELOG lists breaking changes.

### [code] SECRET-HISTORY-1: no secret anywhere in branch history - blocker

```bash
git log -p main..HEAD | grep -inE '(token|secret|password|bearer|api[_-]?key)\s*[:=]\s*["'\''][^"'\'']{12,}' | head -50
```
A secret removed in a later commit is still in history: rotate it and tell the user.
