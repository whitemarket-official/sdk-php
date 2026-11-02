<?php

declare(strict_types=1);

/*
 * Waits for a deal to reach the exchanged or completed state, woken early by the personal live channel.
 *
 * Run: php examples/track_deal_live.php <deal id or customId> [timeout seconds, default: no limit] (WHITEMARKET_PARTNER_TOKEN
 * in the environment). Read-only: it only watches the deal; a trade can take hours, so it waits until the deal is
 * exchanged or final unless a timeout is given. A deal event is a hint; `waitFor` then reads the authoritative state (`statuses()`, or `get()` for a seller deal).
 * Without `live:` the same call polls every `pollInterval` seconds. The counterparty's Steam id on deal events is
 * personal data and is not printed here.
 */

use WhiteMarket\Sdk\Deals\Until;
use WhiteMarket\Sdk\Errors\DealWaitTimeoutException;
use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Live\Event\DealEvent;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;
use function WhiteMarket\Sdk\Examples\printStatusChanges;

require __DIR__ . '/bootstrap.php';

$dealId = $argv[1] ?? null;
if ($dealId === null) {
    fwrite(STDERR, "Usage: php examples/track_deal_live.php <deal id or customId> [timeout seconds]\n");
    exit(2);
}
$timeout = isset($argv[2]) ? (float) $argv[2] : null; // null: wait until the deal reaches the state

$client = WhiteMarketClient::create(partnerToken: partnerToken());
$live = $client->live();

$live->onConnected(static function (LiveConnected $connected): void {
    // After a reconnect the events of the gap are lost; waitFor still polls on its interval, so nothing is missed.
    echo $connected->reconnected ? "reconnected\n" : "connected\n";
});
$live->subscribePersonal(static function (LiveEvent $event): void {
    if ($event instanceof DealEvent) {
        echo 'deal event ', $event::class, ' for deal ', $event->dealId, "\n";
    }
});

try {
    $state = $client->deals->waitFor($dealId, printStatusChanges(Until::exchangedOrCompleted()), timeout: $timeout, pollInterval: 30.0, live: $live);
    echo 'Deal reached ', $state->status->value, "\n";
} catch (DealWaitTimeoutException) {
    fwrite(STDERR, "Timed out; the deal did not reach the state in time.\n");
    exit(1);
} catch (LiveConnectionException $e) {
    fwrite(STDERR, 'Live connection ended: close code ' . ($e->closeCode ?? 'none') . "\n");
    exit(1);
} finally {
    $live->close();
}
