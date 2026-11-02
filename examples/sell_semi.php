<?php

declare(strict_types=1);

/*
 * SEMI selling flow: list an item, then follow the deal until the buyer's offer is exchanged. In SEMI delivery
 * WhiteMarket sends the Steam trade offer and YOU confirm it in Steam Mobile.
 *
 * Run: php examples/sell_semi.php <inventory id or Steam asset id> <price USD> [--wait=<seconds>] [--yes]
 * (WHITEMARKET_PARTNER_TOKEN in the environment). THIS PUTS AN ITEM UP FOR SALE. Without `--yes` it only prints what it
 * would do. `--wait` (default 0: do not wait) polls your seller deals for that long: when the deal reaches
 * NEED_CONFIRMATION you confirm it in Steam Mobile, then the script waits for EXCHANGED or a final status.
 * Listing is a mutation: sent once, never retried. If it fails ambiguously, check `selling->myProducts()` before retrying.
 */

use WhiteMarket\Sdk\Deals\Until;
use WhiteMarket\Sdk\Errors\DealWaitTimeoutException;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Selling\ListingOptions;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;
use function WhiteMarket\Sdk\Examples\printStatusChanges;

require __DIR__ . '/bootstrap.php';

$confirmed = false;
$wait = 0.0;
$positional = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--yes') {
        $confirmed = true;
    } elseif (str_starts_with($argument, '--wait=')) {
        $wait = (float) substr($argument, strlen('--wait='));
    } else {
        $positional[] = $argument;
    }
}
if (count($positional) !== 2 || preg_match('/^\d+(\.\d+)?$/', $positional[1]) !== 1) {
    fwrite(STDERR, "Usage: php examples/sell_semi.php <inventory id or asset id> <price USD> [--wait=<seconds>] [--yes]\n");
    exit(2);
}
[$item, $price] = $positional; // the asset id is a 64-bit number: keep it a string

echo "Would list item {$item} for {$price} USD with SEMI delivery (WhiteMarket sends the offer, you confirm in Steam Mobile)\n";
if (!$confirmed) {
    echo "Nothing was done. Add --yes to really list.\n";
    exit(0);
}

$client = WhiteMarketClient::create(partnerToken: partnerToken());

$product = $client->selling->list($item, Money::usd($price), new ListingOptions(deliveryType: DeliveryType::Semi));
echo 'Listed product ', $product->id, "\n";

if ($wait <= 0.0) {
    echo "Not waiting. Watch your deals with `selling` / `deals->listAsSeller()`.\n";
    exit(0);
}

$deadline = microtime(true) + $wait;
$deal = null;
while ($deal === null && microtime(true) < $deadline) {
    foreach ($client->deals->listAsSeller() as $candidate) {
        // Assumption: a seller deal carries the id of the listed product (not confirmed by the API docs).
        if ($candidate->product->id === $product->id) {
            $deal = $candidate;

            break; // the pages are fetched lazily: stop at the match
        }
    }
    if ($deal === null) {
        sleep(15); // a sale needs a buyer: this is a poll of one query, not a loop of mutations
    }
}
if ($deal === null) {
    fwrite(STDERR, "No buyer yet. The listing stays on the market until it expires or you remove it.\n");
    exit(0);
}

echo 'Deal ', $deal->id, ' status ', $deal->status->value, "\n";
if ($deal->status === DealStatus::NeedConfirmation) {
    echo "Confirm the trade offer in Steam Mobile now.\n";
}

try {
    $state = $client->deals->waitFor($deal->id, printStatusChanges(Until::exchangedOrCompleted()), timeout: max(0.0, $deadline - microtime(true)), pollInterval: 15.0);
    echo 'Deal reached ', $state->status->value, "\n";
} catch (DealWaitTimeoutException) {
    fwrite(STDERR, "Timed out; the deal is still in progress.\n");
    exit(1);
}
