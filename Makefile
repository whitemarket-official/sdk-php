# One entry point for humans, agents and CI (CLAUDE.md "Commands").
# Noisy targets keep the full log in var/qc/<target>.log and print only errors and a summary; VERBOSE=1 prints everything.
# The exit code is the truth.

SHELL := /bin/bash
.DEFAULT_GOAL := help

PHP ?= php
# Composer runs in the official image by default (the host Composer may be missing or outdated).
# CI and the matrix containers override it: COMPOSER_BIN=composer.
COMPOSER_VERSION := 2.11.0
DOCKER_RUN := docker run --rm -u "$$(id -u):$$(id -g)" -v "$(CURDIR)":/app -w /app -e COMPOSER_HOME=/tmp/composer
COMPOSER_BIN ?= $(DOCKER_RUN) composer:$(COMPOSER_VERSION)

# PHP with a coverage driver (pcov). The host PHP has none, so coverage and Infection run in a local image.
# CI sets PHP_COV=php (setup-php installs pcov).
PHP_VERSIONS := 8.2 8.3 8.4 8.5
TOOLS_PHP_VERSION := 8.5
php_image = whitemarket-sdk-php:$(1)
PHP_COV ?= $(DOCKER_RUN) $(call php_image,$(TOOLS_PHP_VERSION)) php
COVERAGE_MIN := 85

QC := tools/qc-filter.sh

.PHONY: help
help: ## List targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-16s\033[0m %s\n", $$1, $$2}'

## --- dependencies -------------------------------------------------------------

.PHONY: install
install: ## composer install (root + isolated tools)
	$(COMPOSER_BIN) install --no-interaction --no-progress
	$(COMPOSER_BIN) install --no-interaction --no-progress --working-dir=tools/infection

.PHONY: update
update: ## composer update (re-resolve composer.lock for config.platform.php)
	$(COMPOSER_BIN) update --no-interaction --no-progress

.PHONY: docker-image
docker-image: ## Build the local PHP image(s) with pcov: make docker-image V=8.2
	@for v in $(or $(V),$(TOOLS_PHP_VERSION)); do \
		docker build -q --build-arg PHP_VERSION=$$v -t $(call php_image,$$v) -f tools/docker/php.Dockerfile tools/docker >/dev/null || exit 1; \
	done

## --- checks -------------------------------------------------------------------

.PHONY: check
# Targets that depend on the PHP version; the matrix runs them plus the unit tests. Checked once on TOOLS_PHP_VERSION:
# coverage (a gate on the code, not on the runtime), deps (checks composer.json against the symbols used), test-codegen
# (the generator runs only in our CI, never on a user's machine) and codegen-check (Node).
PHP_CHECKS := fmt-check lint stan audit

check: $(PHP_CHECKS) coverage deps test-codegen codegen-check ## Everything CI runs

.PHONY: fmt
fmt: ## Format code (php-cs-fixer)
	@mkdir -p var/cache
	@$(QC) fmt $(PHP) vendor/bin/php-cs-fixer fix --no-interaction

.PHONY: fmt-check
fmt-check: ## Check formatting (php-cs-fixer dry run)
	@mkdir -p var/cache
	@$(QC) fmt-check $(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction

.PHONY: lint
lint: rector-check ## Syntax check + composer.json validation + Rector dry run
	@$(QC) lint bash -c 'set -e; $(COMPOSER_BIN) validate --strict --no-check-publish; \
		find src tests examples tools -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 -P4 $(PHP) -l >/dev/null'

.PHONY: rector-check
rector-check: ## Rector dry run
	@mkdir -p var/cache
	@$(QC) rector-check $(PHP) vendor/bin/rector process --dry-run --no-progress-bar

.PHONY: rector
rector: ## Apply Rector changes
	@$(PHP) vendor/bin/rector process --no-progress-bar

.PHONY: stan
stan: ## PHPStan level max + strict rules
	@mkdir -p var/cache
	@$(QC) stan $(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

.PHONY: test
test: ## Unit tests; one class: make test FILTER=ClassName
	@$(QC) test $(PHP) vendor/bin/phpunit --testsuite unit $(if $(FILTER),--filter '$(FILTER)')

.PHONY: test-memory
test-memory: ## 1M-row memory test of streamProducts (slow, not part of check); run on another PHP: make test-memory PHP=...
	@$(QC) test-memory $(PHP) -d memory_limit=128M vendor/bin/phpunit --testsuite memory --no-coverage

.PHONY: live-smoke
live-smoke: ## Read-only calls against the real API (SDK_SPEC 6.4), needs WHITEMARKET_PARTNER_TOKEN; writes var/live-smoke/report.md; not part of check
	$(PHP) tools/live-smoke/run.php

.PHONY: bench-export
bench-export: ## Decoder benchmark of streamProducts on the host: make bench-export ROWS=200000
	$(PHP) tools/bench/export-decoders.php $(if $(ROWS),$(ROWS))

.PHONY: test-codegen
test-codegen: ## Dev-tool tests (tools/codegen-php, tools/phpstan), on the tools PHP version only
	@$(QC) test-codegen $(PHP) vendor/bin/phpunit --testsuite codegen

.PHONY: coverage
coverage: $(if $(findstring docker,$(PHP_COV)),docker-image) ## Unit tests with the line coverage gate (85 %, generated code excluded)
	@mkdir -p var/qc
	@$(QC) coverage bash -c '$(PHP_COV) vendor/bin/phpunit --testsuite unit --coverage-clover var/qc/clover.xml \
		&& $(PHP) tools/coverage-check.php var/qc/clover.xml $(COVERAGE_MIN)'

.PHONY: infection
INFECTION := tools/infection/vendor/bin/infection --no-progress --no-interaction --show-mutations=20
# Under Docker Desktop for macOS, Infection's parallel processes exhaust open files on the bind mount ("Too many open
# files", 2 of 2 runs through the mount, 0 of 3 on a container-local copy), so the Docker run works on a copy of the
# tree and copies the reports back. The exit code is Infection's. The image has no php.ini, so the CLI default 128M
# applies; Infection's final tmpDir cleanup ran out of it after writing the reports (2026-11-01).
INFECTION_IN_COPY := set -e; mkdir /tmp/w; tar -C /app -cf - --exclude=./.git --exclude=./var . | tar -C /tmp/w -xf -; \
	cd /tmp/w; mkdir -p var/qc; status=0; php -d memory_limit=1G $(INFECTION) || status=$$?; cp var/qc/infection* /app/var/qc/ || true; \
	exit $$status
infection: $(if $(findstring docker,$(PHP_COV)),docker-image) ## Mutation tests (thresholds in infection.json5)
	@mkdir -p var/qc
	@$(QC) infection $(if $(findstring docker,$(PHP_COV)),$(DOCKER_RUN) $(call php_image,$(TOOLS_PHP_VERSION)) \
		bash -c '$(INFECTION_IN_COPY)',$(PHP_COV) $(INFECTION))

.PHONY: audit
audit: ## Known vulnerabilities in dependencies
	@$(QC) audit $(COMPOSER_BIN) audit --no-interaction

.PHONY: deps
deps: ## No shadow / unused dependencies
	@$(QC) deps $(PHP) vendor/bin/composer-dependency-analyser

## --- codegen ------------------------------------------------------------------

.PHONY: schema
schema: ## Fetch the partner schema by introspection
	@test -f tools/codegen/fetch-schema.mjs || { echo "schema: tools/codegen/fetch-schema.mjs does not exist yet (P1)"; exit 1; }
	cd tools/codegen && npm ci --silent && node fetch-schema.mjs

# PHP layer (ADR 0002): schema + operations + fragments -> src/Generated (or the given directory).
CODEGEN_PHP = $(PHP) tools/codegen-php/bin/generate.php schema/partner.graphql graphql/operations graphql/fragments
CODEGEN_FIXTURE := tests/fixtures/codegen
CODEGEN_NODE := cd tools/codegen && npm ci --silent --no-audit --no-fund && node

.PHONY: codegen
codegen: ## Regenerate operations, fragments and the generated PHP layer from the committed schema
	@$(CODEGEN_NODE) generate-operations.mjs
	@$(CODEGEN_PHP) src/Generated

.PHONY: codegen-golden
codegen-golden: ## Regenerate the generator's golden files (tests/fixtures/codegen) after a deliberate generator change
	@$(PHP) tools/codegen-php/bin/generate.php $(CODEGEN_FIXTURE)/schema.graphql $(CODEGEN_FIXTURE)/operations \
		$(CODEGEN_FIXTURE)/fragments $(CODEGEN_FIXTURE)/golden 'WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden'

.PHONY: codegen-check
codegen-check: ## Regenerate into var/codegen-check and fail on any diff
	@rm -rf var/codegen-check && mkdir -p var/codegen-check
	@$(QC) codegen-check bash -c '$(CODEGEN_NODE) generate-operations.mjs --out-dir ../../var/codegen-check \
		&& cd ../.. && diff -r graphql/operations var/codegen-check/graphql/operations \
		&& diff -r graphql/fragments var/codegen-check/graphql/fragments \
		&& $(CODEGEN_PHP) var/codegen-check/php && diff -r src/Generated var/codegen-check/php'

## --- runtime matrix -----------------------------------------------------------

.PHONY: matrix
matrix: ## PHP_CHECKS + unit tests on every supported PHP version in Docker
	@for v in $(PHP_VERSIONS); do \
		echo "=== PHP $$v"; \
		$(MAKE) --no-print-directory docker-image V=$$v || exit 1; \
		$(DOCKER_RUN) $(call php_image,$$v) make $(PHP_CHECKS) test COMPOSER_BIN=composer PHP_COV=php || exit 1; \
	done

## --- agent tooling ------------------------------------------------------------

.PHONY: hooks-test
hooks-test: ## Regression tests of the .claude hooks
	@bash .claude/hooks/hooks-test.sh && bash .claude/hooks/hooks-test-agent-guard.sh \
		&& bash .claude/hooks/suppress-check-test.sh && bash .claude/hooks/reuse-check-test.sh

## --- repository hygiene -------------------------------------------------------

GITLEAKS_IMAGE := ghcr.io/gitleaks/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f
ACTIONLINT_IMAGE := rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667

.PHONY: secrets-scan
secrets-scan: ## gitleaks over the working tree (findings redacted)
	@$(QC) secrets-scan docker run --rm -v "$(CURDIR)":/repo $(GITLEAKS_IMAGE) dir /repo --redact --no-banner \
		--config /repo/.gitleaks.toml

.PHONY: actionlint
actionlint: ## Lint GitHub Actions workflows
	@$(QC) actionlint docker run --rm -v "$(CURDIR)":/repo -w /repo $(ACTIONLINT_IMAGE) -no-color

.PHONY: github-ruleset
github-ruleset: ## (maintainer, needs repo admin) Apply tools/github/ruleset-*.json: PR + green "CI OK" for everyone, merge only by maintain/admin
	gh api --method POST repos/whitemarket-official/sdk-php/rulesets --input tools/github/ruleset-main.json
	gh api --method POST repos/whitemarket-official/sdk-php/rulesets --input tools/github/ruleset-main-merge-restriction.json
