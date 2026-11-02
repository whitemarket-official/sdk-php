<?php

declare(strict_types=1);

/*
 * Buys the cheapest listing of an item by its market hash name, with an idempotent `customId`.
 *
 * Run: php examples/buy_cheapest_by_name.php "<market hash name>" <max price in USD> [--custom-id=<id>] [--yes]
 * (WHITEMARKET_PARTNER_TOKEN in the environment). THIS SPENDS MONEY. Without `--yes` it only prints what it would do.
 *
 * The purchase mutation is sent once and never retried. If the answer is lost (timeout, 5xx), the SDK waits and looks
 * the deal up by `customId`; if it still finds nothing it throws PurchaseOutcomeUnknownException and the outcome is
 * UNKNOWN: do not buy again, look the deal up with `deals->get(<customId>)` later. The id is printed BEFORE the call:
 * a real program stores it in its own database first, so it survives a crash.
 */

use WhiteMarket\Sdk\Buying\BuyOptions;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Models\App;
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
    fwrite(STDERR, "Usage: php examples/buy_cheapest_by_name.php \"<market hash name>\" <max price USD> [--custom-id=<id>] [--yes]\n");
    exit(2);
}
[$nameHash, $maxPrice] = $positional;
$customId ??= 'example-' . bin2hex(random_bytes(8)); // 1 to 100 characters; reuse it to retry the SAME purchase

echo "Would buy the cheapest '{$nameHash}' for at most {$maxPrice} USD, customId {$customId}\n";
if (!$confirmed) {
    echo "Nothing was done. Add --yes to really buy.\n";
    exit(0);
}

$client = WhiteMarketClient::create(partnerToken: partnerToken());

// Persist $customId in your own storage here, BEFORE the call.
try {
    $result = $client->buying->buyCheapest(
        App::Csgo,
        $nameHash,
        maxPrice: Money::usd($maxPrice),
        options: new BuyOptions(customId: $customId),
    );
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
    // The server rejected the purchase before running it (for example insufficient funds): nothing was bought.
    fwrite(STDERR, 'Rejected: ' . failureReport($e));
    exit(1);
}
