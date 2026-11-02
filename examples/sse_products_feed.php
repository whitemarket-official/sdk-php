<?php

declare(strict_types=1);

/*
 * Watches the live product feed (added, edited, removed lots) over SSE (server-sent events) for 60 seconds and prints one line per event.
 *
 * Run: php examples/sse_products_feed.php (WHITEMARKET_PARTNER_TOKEN in the environment). Read-only: nothing is bought,
 * listed or changed. Live events are hints: after a reconnect the events of the gap are lost, so a real program
 * re-reads what it cares about through GraphQL when `LiveConnected::$reconnected` is true.
 */

use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveDisconnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\Event\ProductEdited;
use WhiteMarket\Sdk\Live\Event\ProductRemoved;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveTransport;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

$client = WhiteMarketClient::create(partnerToken: partnerToken());
$live = $client->live(new LiveOptions(transport: LiveTransport::Sse)); // the same events as ws_products_feed.php

$live->onConnected(static function (LiveConnected $connected): void {
    echo $connected->reconnected
        ? "reconnected: events of the gap are lost, re-read the lots you track\n"
        : "connected\n";
});
$live->onDisconnected(static function (LiveDisconnected $disconnected): void {
    echo 'disconnected, code ', $disconnected->code ?? 'none', $disconnected->willReconnect ? ', reconnecting' : '', "\n";
});
$live->onError(static function (Throwable $problem): void {
    echo 'problem: ', $problem::class, "\n"; // a malformed message, a failed attempt before the next retry
});

$live->subscribeProducts(static function (LiveEvent $event): void {
    echo match (true) {
        $event instanceof ProductAdded => 'added   ' . $event->marketHashName . ' ' . $event->price,
        $event instanceof ProductEdited => 'edited  ' . $event->id,
        $event instanceof ProductRemoved => 'removed ' . $event->id,
        default => 'other   ' . $event::class,
    }, "\n";
});

try {
    $live->run(60.0); // dispatches to the handlers; run() without an argument goes on until close()
} catch (LiveConnectionException $e) {
    // Terminal: a close code that forbids reconnecting, or maxReconnectAttempts used up.
    fwrite(STDERR, 'Live connection ended: close code ' . ($e->closeCode ?? 'none') . "\n");
    exit(1);
} finally {
    $live->close();
}
