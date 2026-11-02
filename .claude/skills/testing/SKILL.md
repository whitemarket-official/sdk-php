---
name: testing
description: >-
  Testing rules for the WhiteMarket PHP SDK (PHPUnit). Read BEFORE writing or changing tests, and whenever a task
  involves new functionality or a bug fix (CLAUDE.md rule 0b): test structure, input matrix with boundaries, data
  providers, fake PSR-18 transport, fixtures, deterministic time/random, mutation-minded checks, debugging test failures.
---

# Testing

**The goal is not a green run but confidence the code is correct.** A test written "to pass" is worse than none: it creates false coverage. Write tests as if you were trying to **break your own code**.

All patterns target PHPUnit 11+ with PHP 8 attributes only (annotations are removed in 12). Requirements come from `docs/SDK_SPEC.md` section 7.

## References

- [`references/assertions.md`](references/assertions.md): assertion catalog, constraints, exception expectations.
- [`references/data-providers.md`](references/data-providers.md): `#[DataProvider]`, `#[TestWith]`, named datasets, generators.
- [`references/mocking.md`](references/mocking.md): stubs vs mocks, when a double is allowed at all.
- [`references/configuration.md`](references/configuration.md): `phpunit.xml.dist`, suites, strictness, coverage.

## Rules (each one is mandatory)

**1. A test must fail on broken code.** After it goes green, break the implementation once (`>` -> `>=`, return early, drop a guard, return `null`) and watch it go red; then restore. Does not go red -> the test checks nothing. `make infection` does this at scale; the manual check is still required for every new test.

**2. Input matrix, not one happy path.** For every unit of logic:
- **Happy path**: a typical valid input.
- **Boundaries**: exactly at the limit and +-1. SDK limits: `deals.statuses` chunking 300/301/600/601 ids; comment 255/256 chars and forbidden characters; lifetime 59/60/31536000/31536001; `setLifetimeForAll` 599/600; `customId` 100/101 chars; retry attempts max/max+1.
- **Equivalence classes**: one representative per class, no ten cases meaning the same.
- **Negative / invalid**: `null`, empty string/array, wrong type, over the limit, unknown id, malformed JSON, missing field.
- **Server data specials**: unknown enum value / event type (must not throw), money and wear as decimal strings (`"0.07"`, `"0"`, many decimals), 64-bit ids beyond 2^53, `paint_index` string vs number, null floats in exports, Doppler phases sharing a name hash.
- **Transport specials**: every HTTP status of the SDK_SPEC 1.4 / 1.5 tables, `Retry-After` in seconds and as HTTP-date, network error before a response, 401 -> refresh -> replay, a second 401, gzip body, body over the size limit.
- **Time**: token expiring exactly at the safety margin, JWT without `exp`, clock skew.

**3. Data providers for the matrix.** One parameterized test with named datasets beats ten copy-paste methods. Dataset names read as a spec: `'301 ids -> two requests'`, `'503 on mutation -> no retry'`.

**4. Behaviour, not implementation.** Assert observable results: returned models, thrown exception class and category, the requests that hit the transport (method, URL, headers, `operationName`, variables). Do not assert private methods or call counts unless the count *is* the behaviour (exactly one `auth_token` call under concurrency, exactly one request for a mutation on 503).

**5. No tautologies.** A double that repeats the implementation, or an assertion on the value the test itself stubbed, is FORBIDDEN (CLAUDE.md rule 0b). If an honest test cannot be written, stop and tell the user what blocks it.

**6. Red first for every bug.** Write the test that reproduces the bug, see it fail on the current code, then fix. A test that passes on the old code does not catch the regression.

**7. Fixtures over mocks of our own code.** Never mock SDK classes (mappers, transport, token store, paginator). Use the real object with a fake at the **outer boundary**:
- HTTP: a fake PSR-18 client (`Psr\Http\Client\ClientInterface`) that returns queued PSR-7 responses and records requests. Reuse the project's existing fake; do not write a new one per test (CLAUDE.md rule 11).
- Responses: JSON fixtures in `tests/fixtures/`, synthetic or from the public partner docs, scrubbed (CLAUDE.md "Data policy"). No real tokens, ids of real accounts, trade URLs, IPs.
- Cache: an in-memory PSR-16 implementation. Logger: a PSR-3 test logger that keeps records (for redaction tests).
- Live: a local fake SSE / fake Centrifugo WS server, never the real endpoint.

**8. Deterministic time and randomness.** Clock, sleeper, jitter source and UUID generator are injected; tests pass fixed implementations. No `sleep()`, no `time()`/`new DateTimeImmutable()` inside the SUT without the injected clock, no random order dependence. Retry tests assert the computed delays through the fake sleeper instead of waiting.

**9. Isolation.** No shared mutable state between tests, no static state, `executionOrder="random"` stays green. Each test builds its own world in `setUp()` or a small builder.

**10. Live tests are opt-in.** Integration tests against the real API run only when `WHITEMARKET_PARTNER_TOKEN` is set (skip otherwise), are read-only, and never print the token or raw responses.

In doubt: **fewer doubles, more cases.**

## Structure and naming

- Files `*Test.php` only (a non-abstract `*TestCase.php` is silently not run). Classes `final`, extend `TestCase`.
- Mirror `src/`: `src/Buying/BuyingService.php` -> `tests/Unit/Buying/BuyingServiceTest.php`. Live/integration tests in their own suite.
- Methods: `#[Test]` attribute with a behaviour name: `reconcilesFromDealHistoryWhenItemNotFound()`, not `testBuy()`.
- Arrange-Act-Assert; one act per test; several assertions are fine when they verify one behaviour.
- `assertSame` over `assertEquals`. Exceptions: `expectException()` before the act, plus the category / `retryAfter` / `customId` property on the caught exception when it matters.
- `#[CoversClass]` on unit tests; `#[CoversNothing]` on integration tests.
- Generated code (`src/Generated/`) is not unit-tested directly; a codegen smoke test executes a few operations through the fake transport (SDK_SPEC P1).

```php
#[CoversClass(StatusesChunker::class)]
final class StatusesChunkerTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function idCounts(): iterable
    {
        yield '300 ids -> one request'  => [300, 1];
        yield '301 ids -> two requests' => [301, 2];
        yield '600 ids -> two requests' => [600, 2];
        yield '601 ids -> three requests' => [601, 3];
    }

    #[Test]
    #[DataProvider('idCounts')]
    public function splitsIdsIntoBatchesOfThreeHundred(int $ids, int $expectedRequests): void
    {
        $http = FakeHttpClient::withResponses(...array_fill(0, $expectedRequests, Fixtures::dealStatuses()));

        $this->client($http)->deals->statuses(Ids::sequence($ids));

        self::assertCount($expectedRequests, $http->requests());
    }
}
```

## Running

```bash
make test FILTER=<ClassName>     # fast loop
make coverage                    # full suite + 85 % gate (part of quality-checks)
make infection                   # mutation testing, on demand
```

Large output goes through `qc-runner`; logs in `var/qc/<target>.log`.

## Reviewing tests

Cite the issue and the fix inline, do not lecture: `createMock -> createStub (no expects())`, `stub of our own Mapper -> use the real Mapper with a fixture`.
