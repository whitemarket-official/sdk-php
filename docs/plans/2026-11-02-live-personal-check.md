# Owner check: personal live events (A1) and `waitFor(..., live:)`

Closes the last open item of P6 (plan `2026-11-01-live-plan.md` L6: "owner runs both examples live"). The products
channel already ran clean live over WebSocket and SSE (WHITEMARKET_API.md 9, "Live observations").

What it proves:

1. Personal events arrive without a subscribe command (the server subscribes `#<personId>` on connect; SDK_SPEC 3.11).
2. Deal events map to typed `Deal*` events (no `UnknownEvent`, no `onError`).
3. `deals->waitFor(..., live: $live)` wakes up on the deal event and reads the state early, instead of waiting for
   the 30 s poll interval.

## Steps

1. Have the Partner Token in the git-ignored `.env` in the repository root (`WHITEMARKET_PARTNER_TOKEN=...`), or
   export it in the shell. The script never prints it.
2. Start a deal on the account that will change state within the next 10 minutes (a purchase, or a sale whose buyer
   is about to act). Note its deal id (or the `customId` used to buy).
3. From the repository root:

   ```
   php examples/track_deal_live.php <deal id or customId>
   ```

4. Let it run until it prints `Deal reached ...` or times out (600 s).

## Expected output

- `connected`
- one or more `deal event WhiteMarket\Sdk\Live\Event\Deal... for deal <id>` lines as the deal moves
- `Deal reached <STATUS>` shortly (seconds) after the matching deal event, not only on a 30 s boundary

## What to send back (no secrets)

- The printed lines with the deal id replaced by `<id>` (deal ids of a real account are not pasted into chats or
  the repository).
- Whether `Deal reached` came right after a deal event.
- Any `problem:`, `error` or exception line, class name only.

## If something is off

- No deal event at all while the deal changed: A1 is refuted (the personal channel needs an explicit subscribe);
  report it, the session code in `src/Live/LiveSession.php` and SDK_SPEC 3.11 change then.
- `UnknownEvent` or an `onError` line for a deal event: the event shape differs from the docs; the raw shape is
  needed (keys and types only) to fix `src/Live/EventMapper.php`.

## Result (2026-11-02, owner)

A1 confirmed. Four purchases through `examples/buy_product_by_id.php`; `examples/track_deal_live.php` printed typed
`DealOffered`, `DealExchanged` and `DealCancelled` events (no `UnknownEvent`, no `onError`) and `Deal reached ...` right
after the matching event. No change to `src/Live/LiveSession.php` or SDK_SPEC 3.11 needed.
