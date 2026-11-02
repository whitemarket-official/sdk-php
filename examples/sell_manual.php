<?php

declare(strict_types=1);

/*
 * MANUAL selling flow: list an item, wait for a buyer, then YOU send the Steam trade offer and report its id.
 *
 * Step 1, list and wait for a buyer:
 *   php examples/sell_manual.php list <inventory id or Steam asset id> <price USD> [--wait=<seconds>] [--yes]
 * When a buyer appears it prints what to send and to whom (item, asset id, the buyer's trade URL); for a deal that
 * already waits for your offer:
 *   php examples/sell_manual.php show <deal id>
 * Step 2, after you sent the item in Steam (the SDK does not send Steam offers), report the offer:
 *   php examples/sell_manual.php report <deal id> <Steam offer id> [--yes]
 *
 * (WHITEMARKET_PARTNER_TOKEN in the environment). THIS PUTS AN ITEM UP FOR SALE and changes a deal. Without `--yes` it
 * only prints what it would do. `--wait` (default 0: do not wait) polls your seller deals until the deal reaches
 * SELLER_SENDING_STEAM_OFFER. If you cannot send the item, use `deals->cancelSendingOffer()` with the TRUE reason: a
 * wrong one can lead to sanctions. Mutations are sent once and never retried; after an ambiguous failure of `list`
 * check `selling->myProducts()`, after one of `report` check the deal with `deals->get()`.
 */

use WhiteMarket\Sdk\Models\Deal;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Selling\ListingOptions;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

// What to send and to whom [docs 6-skins-sell, manual delivery]: the item's `assetId` to `receiverTradeUrl`.
function printSendInstructions(#[\SensitiveParameter] Deal $deal): void
{
    $sold = $deal->item ?? $deal->product->item;
    echo 'Deal ', $deal->id, ' status ', $deal->status->value, "\n";
    echo '  item:      ', $sold->nameHash ?? 'unknown item', "\n";
    echo '  asset id:  ', $sold->assetId ?? 'unknown', "\n";
    // The trade URL carries the buyer's trade token: printed because you need it, never log it.
    echo '  send to:   ', $deal->receiverTradeUrl?->reveal() ?? 'no trade URL in the deal', "\n";
    echo "Send exactly this item to this trade URL in Steam, then report the offer id:\n";
    echo "  php examples/sell_manual.php report {$deal->id} <Steam offer id> --yes\n";
}

$usage = "Usage:\n  php examples/sell_manual.php list <inventory id or asset id> <price USD> [--wait=<seconds>] [--yes]\n"
    . "  php examples/sell_manual.php show <deal id>\n"
    . "  php examples/sell_manual.php report <deal id> <Steam offer id> [--yes]\n";

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
$mode = $positional[0] ?? '';
$valid = match ($mode) {
    'list' => count($positional) === 3 && preg_match('/^\d+(\.\d+)?$/', $positional[2]) === 1,
    'show' => count($positional) === 2,
    'report' => count($positional) === 3,
    default => false,
};
if (!$valid) {
    fwrite(STDERR, $usage);
    exit(2);
}

$first = $positional[1] ?? '';
$second = $positional[2] ?? '';

if ($mode === 'show') {
    printSendInstructions(WhiteMarketClient::create(partnerToken: partnerToken())->deals->get($first));
    exit(0);
}

if ($mode === 'report') {
    [$dealId, $offerId] = [$first, $second];
    echo "Would report Steam offer {$offerId} for deal {$dealId}\n";
    if (!$confirmed) {
        echo "Nothing was done. Add --yes to really report.\n";
        exit(0);
    }
    $client = WhiteMarketClient::create(partnerToken: partnerToken());
    $deal = $client->deals->reportOfferSent($dealId, $offerId);
    echo 'Reported. Deal status ', $deal->status->value, "\n";
    exit(0);
}

[$item, $price] = [$first, $second]; // the asset id is a 64-bit number: keep it a string
echo "Would list item {$item} for {$price} USD with MANUAL delivery (you send the Steam offer)\n";
if (!$confirmed) {
    echo "Nothing was done. Add --yes to really list.\n";
    exit(0);
}

$client = WhiteMarketClient::create(partnerToken: partnerToken());
$product = $client->selling->list($item, Money::usd($price), new ListingOptions(deliveryType: DeliveryType::Manual));
echo 'Listed product ', $product->id, "\n";

$deadline = microtime(true) + $wait;
while (microtime(true) < $deadline) {
    foreach ($client->deals->listAsSeller([DealStatus::SellerSendingSteamOffer]) as $deal) {
        // A seller deal carries the id of the listed product (observed live 2026-11-02; not stated by the API docs).
        if ($deal->product->id === $product->id) {
            printSendInstructions($deal);
            exit(0);
        }
    }
    sleep(15); // a poll of one query until a buyer appears
}
echo $wait > 0.0 ? "No buyer yet. The listing stays on the market until it expires or you remove it.\n" : "Not waiting.\n";
