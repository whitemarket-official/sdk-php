<?php

declare(strict_types=1);

/*
 * Waits for a deal to reach the exchanged or completed state by polling `deal_buyer_statuses`.
 *
 * Run: php examples/track_deal_polling.php <deal id or customId> [timeout seconds, default: no limit]
 * (WHITEMARKET_PARTNER_TOKEN in the environment). Read-only: it only watches the deal. The poll is one batch-capable
 * query every 30 seconds (a trade can take hours: Steam trade holds, a seller who is away); to wake up on live events instead of waiting, see examples/track_deal_live.php.
 */

use WhiteMarket\Sdk\Deals\Until;
use WhiteMarket\Sdk\Errors\DealWaitTimeoutException;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;
use function WhiteMarket\Sdk\Examples\printStatusChanges;

require __DIR__ . '/bootstrap.php';

$dealId = $argv[1] ?? null;
if ($dealId === null) {
    fwrite(STDERR, "Usage: php examples/track_deal_polling.php <deal id or customId> [timeout seconds]\n");
    exit(2);
}
$timeout = isset($argv[2]) ? (float) $argv[2] : null; // null: wait until the deal reaches the state

$client = WhiteMarketClient::create(partnerToken: partnerToken());

try {
    $state = $client->deals->waitFor($dealId, printStatusChanges(Until::exchangedOrCompleted()), timeout: $timeout, pollInterval: 30.0);
    echo 'Deal reached ', $state->status->value, "\n";
} catch (DealWaitTimeoutException) {
    fwrite(STDERR, "Timed out; the deal did not reach the state in time.\n");
    exit(1);
}
