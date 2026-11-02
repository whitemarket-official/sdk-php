<?php

declare(strict_types=1);

/*
 * Prints the personal deal events of the account (your deals only) over WebSocket for a while.
 *
 * Run: php examples/ws_personal_deals.php [seconds, default 120] (WHITEMARKET_PARTNER_TOKEN in the environment).
 * Read-only: it only listens. A deal event is a hint: read the authoritative state with `deals->get()`. The
 * counterparty's Steam id on the event is personal data and is not printed.
 */

use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Live\Event\DealEvent;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveTransport;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

$seconds = isset($argv[1]) ? (float) $argv[1] : 120.0;

$client = WhiteMarketClient::create(partnerToken: partnerToken());
$live = $client->live(new LiveOptions(transport: LiveTransport::WebSocket));

$live->onConnected(static function (LiveConnected $connected): void {
    echo $connected->reconnected ? "reconnected: events of the gap are lost, re-read your deals\n" : "connected\n";
});
$live->subscribePersonal(static function (LiveEvent $event): void {
    if ($event instanceof DealEvent) {
        $action = $event->action->value;
        echo $event::class, ' deal ', $event->dealId, ' ', $action, "\n";
    }
});

try {
    $live->run($seconds);
} catch (LiveConnectionException $e) {
    fwrite(STDERR, 'Live connection ended: close code ' . ($e->closeCode ?? 'none') . "\n");
    exit(1);
} finally {
    $live->close();
}
