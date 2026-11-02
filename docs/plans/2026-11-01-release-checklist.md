# Release 0.1.0: SDK_SPEC 11 walk-through (plan T7.4)

State on 2026-11-01 (session 22). Nothing is committed yet; the items that need GitHub stay open until the first push.

| SDK_SPEC 11 item | State | Evidence |
|---|---|---|
| Every root field of the schema (minus deprecated) is reachable through the generated layer | Done | 69 of 69 non-deprecated root fields have a `GeneratedClient` method; `profile_steam_api_key` is deprecated and excluded. Reachable from the client as `WhiteMarketClient::$generated` |
| Every high-level method of section 3 exists, is documented, tested | Done except `orders` | `orders` (3.7) is blocked by proposal P14 (DEVIATIONS row); every other module has its methods and a unit test class |
| `make check` green locally and in CI across the runtime matrix | Local done, CI open | `make check` green (coverage 96.68 %), `make matrix` 8.2-8.5 green (3440 tests). CI has not run: no commit, no remote push yet |
| schema-sync workflow ran once via `workflow_dispatch` | Open | Needs the GitHub repository and the repo variable `SCHEMA_SYNC_DISPATCH_SENDERS` (owner) |
| Live smoke test passed once with a test partner token (read-only) | Done locally | `make live-smoke` 37/37 PASS (2026-11-01). Ran with the owner's token from the local env file, not a dedicated test account; the workflow needs the secret `WM_PARTNER_TOKEN` |
| README covers section 9; examples run | Done, partly run | README in the section 9 order. Run live: auth_and_ping, search_market, export_prices_lookup, export_products_stream, sse_products_feed, ws_products_feed, raw_generated_operation. Run live by the owner 2026-11-02: buy_product_by_id (4 purchases), track_deal_live. Not run live: buy_cheapest_by_name, sell_semi, sell_manual, track_deal_polling, ws_personal_deals, iterate_all_listings |
| No secrets in the repo (gitleaks clean), no TLS-off switches, redaction tests green | Done | `make secrets-scan` clean; no TLS option in the high-level API; redaction and trace-argument tests in `make check` |
| `docs/DEVIATIONS.md` lists every deviation from this spec | Done | New rows this session: CodeQL, `orders`, the `deal_history` / `deal_history_item` overrides |
| Open questions from WHITEMARKET_API.md 11 that affected the code are in the release notes | Done | CHANGELOG `[Unreleased]` "Known limitations" |

Still open for the owner before tagging (A1 confirmed 2026-11-02): the first CI run on
GitHub, a schema-sync dispatch, `release.yml` dry run, proposal P14 (P22 B implemented 2026-11-02), the whole-tree `/security-review` before the PR.
