<?php

declare(strict_types=1);

/*
 * Walks the market listings lazily with `market->iterate()`: one request per page, the next page is fetched only
 * when the loop needs it. The walk stops after a bounded number of listings (default 200), because the whole market
 * holds millions of lots; breaking out of the loop stops the requests.
 *
 * Run: php examples/iterate_all_listings.php [max listings] (WHITEMARKET_PARTNER_TOKEN in the environment).
 * Read-only: no order, purchase or listing is made. For the whole market use the products export instead
 * (examples/export_products_stream.php): it is one file, not millions of requests.
 */

use WhiteMarket\Sdk\Market\ProductFilter;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

$limit = isset($argv[1]) ? (int) $argv[1] : 200;
if ($limit < 1) {
    fwrite(STDERR, "Usage: php examples/iterate_all_listings.php [max listings, at least 1]\n");
    exit(2);
}

$client = WhiteMarketClient::create(partnerToken: partnerToken());

$count = 0;
foreach ($client->market->iterate(new ProductFilter(app: App::Csgo, priceTo: Money::usd('5.00')), pageSize: 50) as $product) {
    echo $product->id, ' ', $product->price, "\n";
    if (++$count >= $limit) {
        break; // the generator stops here: no further page is requested
    }
}

echo "Listed {$count} listings\n";
