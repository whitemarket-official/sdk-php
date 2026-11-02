<?php

declare(strict_types=1);

/*
 * Buys one listing by its product id, with an idempotent `customId`.
 *
 * Run: php examples/buy_product_by_id.php <product id> <max price in USD> [--custom-id=<id>] [--yes]
 * (WHITEMARKET_PARTNER_TOKEN in the environment). THIS SPENDS MONEY. Without `--yes` it only reads the listing and
 * prints what it would do.
 *
 * The listing is read first and refused above the max price. `buyProduct()` also sends the max price to the server,
 * which caps the price at the moment of the purchase: a seller who raises it between the read and the buy is refused
 * (observed live: the server answers `market.product.not_found_in_specified_price` and creates no deal). The purchase mutation is sent once and never retried. If the answer is lost (timeout, 5xx), the SDK looks the
 * deal up by `customId`; if it finds nothing it throws PurchaseOutcomeUnknownException and the outcome is UNKNOWN: do
 * not buy again, look the deal up with `deals->get(<customId>)` later. The id is printed BEFORE the call: a real
 * program stores it in its own database first, so it survives a crash.
 */

use WhiteMarket\Sdk\Buying\BuyOptions;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\failureReport;
use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

$confirmed = false;
$customId = null;
$positional = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--yes') {
        $confirmed = true;
    } elseif (str_starts_with($argument, '--custom-id=')) {
        $customId = substr($argument, strlen('--custom-id='));
    } else {
        $positional[] = $argument;
    }
}
if (count($positional) !== 2 || preg_match('/^\d+(\.\d+)?$/', $positional[1]) !== 1) {
    fwrite(STDERR, "Usage: php examples/buy_product_by_id.php <product id> <max price USD> [--custom-id=<id>] [--yes]\n");
    exit(2);
}
[$productId, $maxPrice] = $positional;
$customId ??= 'example-' . bin2hex(random_bytes(8)); // 1 to 100 characters; reuse it to retry the SAME purchase

$client = WhiteMarketClient::create(partnerToken: partnerToken());

try {
    $product = $client->market->get($productId);
} catch (NotFoundException) {
    fwrite(STDERR, "No listing with this id.\n");
    exit(1);
}
echo 'Listing ', $product->id, ': ', $product->item->nameHash ?? 'unknown item', ' for ', $product->price, "\n";
if ($product->isOwner) {
    fwrite(STDERR, "This is your own listing.\n");
    exit(1);
}
// Money is an exact decimal: compare it as one, never through a float.
if ($product->price->currency !== Currency::Usd || $product->price->amount->compare(Decimal::of($maxPrice)) > 0) {
    fwrite(STDERR, "The price is above {$maxPrice} USD: nothing was bought.\n");
    exit(1);
}

echo "Would buy it, customId {$customId}\n";
if (!$confirmed) {
    echo "Nothing was done. Add --yes to really buy.\n";
    exit(0);
}

// Persist $customId in your own storage here, BEFORE the call.
try {
    $result = $client->buying->buyProduct($product->id, Money::usd($maxPrice), new BuyOptions(customId: $customId));
    echo 'Deal ', $result->deal->id, ' price ', $result->deal->price, $result->reconciled ? ' (found by reconciliation)' : '', "\n";
} catch (PurchaseOutcomeUnknownException $e) {
    // Neither success nor a rejection is proven. Never buy again with a new id: check the old one.
    fwrite(STDERR, "Outcome unknown for customId {$e->customId}. Do NOT buy again; check it later:\n");
    fwrite(STDERR, "  php examples/track_deal_polling.php {$e->customId}\n");
    fwrite(STDERR, failureReport($e)); // what made the outcome ambiguous
    try {
        $deal = $client->deals->get($e->customId);
        echo 'The deal exists after all: ', $deal->id, "\n";
    } catch (NotFoundException) {
        fwrite(STDERR, "No deal with this customId yet.\n");
    } catch (WhiteMarketException $lookup) {
        fwrite(STDERR, 'Lookup failed: ' . failureReport($lookup));
    }
    exit(3);
} catch (GraphQLException $e) {
    // The server rejected the purchase before running it (for example insufficient funds, the listing is gone).
    fwrite(STDERR, 'Rejected: ' . failureReport($e));
    exit(1);
}
