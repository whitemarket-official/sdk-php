<?php

declare(strict_types=1);

/*
 * Lists the first page of cheap CS2 listings of the market.
 *
 * Run: php examples/search_market.php (WHITEMARKET_PARTNER_TOKEN in the environment). Read-only: no order,
 * purchase or listing is made.
 */

use WhiteMarket\Sdk\Market\ProductFilter;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

$client = WhiteMarketClient::create(partnerToken: partnerToken());

$page = $client->market->search(
    new ProductFilter(app: App::Csgo, priceTo: Money::usd('5.00')),
    new PageRequest(first: 10),
);

foreach ($page as $product) {
    echo $product->id, ' ', $product->price, "\n";
}
echo 'More pages: ', $page->hasNextPage ? 'yes' : 'no', "\n";
