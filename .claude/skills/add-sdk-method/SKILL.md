---
name: add-sdk-method
description: Use when adding or changing a high-level SDK method (market, buying, deals, selling, inventory, orders, wallet, profile, store, exports, live). Checklist from the SDK_SPEC entry through the generated operation, mapper, errors, tests, README, example and CHANGELOG to a parity note for the sister SDKs.
---

# add-sdk-method: one high-level method, end to end

Every step is mandatory unless marked otherwise. Stop at the first step that cannot be satisfied and report it.

## 1. Spec entry

- The method exists in `docs/SDK_SPEC.md` section 3 (name, parameters, options, return type, GraphQL field). Not there -> **stop**: propose the SDK_SPEC change (via `clarify-spec` if non-trivial) and wait for approval (CLAUDE.md rule 18). Never add a public method the spec does not list.
- API facts (limits, statuses, error categories) come only from `docs/WHITEMARKET_API.md` with their source tags (rule 14). An open question there is not guessed.
- Check the sister SDK for the same method (`../whitemarket_sdk_*`, read only) for naming and behaviour already settled.

## 2. Generated operation

- The operation exists in `graphql/operations/<query|mutation>/<field>.graphql` and the generated class in `src/Generated/`. Missing or the selection is wrong/too deep -> add `graphql/overrides/<query|mutation>/<field>.graphql`, run `make codegen`, `make codegen-check` clean. Never edit generated files (rule 1).
- Query or mutation decides the retry behaviour: mutations are never retried (rule 2). Buying follows SDK_SPEC 3.3 reconciliation exactly.

## 3. Method and models

- Find before you write (rule 11): reuse the existing paginator, mapper base, options object, validators. A second class of an existing role needs a named reason.
- Signature in PHP idiom with SDK_SPEC names (rule 12); options as a `final readonly` options object with named arguments; tokens with `#[\SensitiveParameter]`.
- Client-side validation only where SDK_SPEC states a limit (comment length, lifetime, `customId` length); limits are constants with the spec reference.
- Batch endpoints are used where they exist and lists are chunked at the documented size (rule 7). Pagination is a lazy `Generator`.
- Models: `final readonly`, decimals for money/wear, string for 64-bit ids, enums with an unknown fallback (rules 3, 5).

## 4. Mapper

- Generated result -> high-level model in one mapper per model, reused everywhere that model appears.
- `null` handling follows the schema nullability; a guard for a value the API model says is valid is pinned by a test (rule 0b).

## 5. Errors

- Map expected failures to the SDK_SPEC 1.5 hierarchy; branch on `extensions.category`, never `message` (rule 4). A new known category goes to `ErrorCategory` with its source.
- Messages carry no tokens and no request bodies.

## 6. Tests (`Skill(testing)` first)

- Success through the fake PSR-18 transport: asserts request shape (`operationName`, variables) and the mapped model.
- Input matrix with boundaries for every validated parameter; GraphQL error with category; relevant HTTP statuses; for queries the retry path, for mutations exactly one request on 503.
- Pagination: stops on `hasNextPage = false`; batching: N-1/N/N+1 around the chunk size.
- Mapping test from a fixture, including unknown enum values and boundary numbers.
- Break the implementation once and see each new test fail.

## 7. Docs and example

- README section for the method (SDK_SPEC 9 order), with a short runnable snippet.
- Example under `examples/` if SDK_SPEC 9 lists one for this area; reads `WHITEMARKET_PARTNER_TOKEN` from the environment; read-only unless its name says `buy`/`sell`.
- PHPDoc on the public method: what it does, which exceptions it throws, whether it is retried.

## 8. CHANGELOG and contract

- `CHANGELOG.md` line under Added (or Changed/Breaking with `before -> after` per rule 16).
- Deviation from SDK_SPEC -> `docs/DEVIATIONS.md` line (rule 17); one affecting public API/retry/auth/money -> stop and ask first.

## 9. Readiness chain

`clean-code-review` (new public type or >100 lines) -> `quality-checks` -> `secure-coding` (if transport, auth, buying, live or export code was touched) -> claim.

## 10. Parity note

End the report with one line for the sister SDKs: what they must mirror (method, behaviour, test cases) and whether SDK_SPEC changed. Never edit the sister repos from here.

## Report

```
Method: <service>-><method>() -> <GraphQL field> (SDK_SPEC §x.y)
Files: path:line - what
Tests: <classes>, red-on-broken checked
Checks: quality-checks <result>, secure-coding <result | N/A>
CHANGELOG / README / example: done | not needed (why)
Parity: <what sister SDKs must mirror>
Risks / open questions: <list>
```
