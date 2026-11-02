<?php

declare(strict_types=1);

/*
 * Streams the public products export (millions of lots) and finds the cheapest lot among the first 10,000 rows.
 *
 * Run: php examples/export_products_stream.php. The export needs no authentication, so no token is used. Read-only.
 * Breaking out of the loop closes the connection, so only the bytes read so far are downloaded.
 */

use WhiteMarket\Sdk\Export\ExportClient;
use WhiteMarket\Sdk\Export\ExportWindow;
use WhiteMarket\Sdk\Export\ProductRow;

require __DIR__ . '/bootstrap.php';

$exports = ExportClient::create();

$cheapest = null;
foreach ($exports->streamProducts(window: ExportWindow::Minutes10) as $index => $row) {
    if ($cheapest === null || $row->price->amount->compare($cheapest->price->amount) < 0) {
        $cheapest = $row;
    }
    if ($index >= 9_999) {
        break;
    }
}

echo $cheapest instanceof ProductRow ? "{$cheapest->marketHashName}: {$cheapest->price}\n" : "No rows\n";
