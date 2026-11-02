<?php

declare(strict_types=1);

/*
 * Downloads the public prices export and looks one item up by (market_hash_name, paint_index).
 *
 * Run: php examples/export_prices_lookup.php. The export needs no authentication, so no token is used. Read-only.
 */

use WhiteMarket\Sdk\Errors\StaleExportException;
use WhiteMarket\Sdk\Export\ExportClient;
use WhiteMarket\Sdk\Export\ExportWindow;
use WhiteMarket\Sdk\Export\NotModified;
use WhiteMarket\Sdk\Export\PriceRow;

require __DIR__ . '/bootstrap.php';

$exports = ExportClient::create();

try {
    // Refuse data older than 15 minutes before the file is downloaded.
    $prices = $exports->prices(window: ExportWindow::Minutes10, maxAgeSeconds: 900);
} catch (StaleExportException $e) {
    fwrite(\STDERR, "The export is stale: {$e->getMessage()}\n");

    exit(1);
}

if ($prices instanceof NotModified) { // only with conditional: true
    exit(0);
}

echo 'Rows: ', count($prices), "\n";
$row = $prices->get('AK-47 | Redline (Field-Tested)', '282');
echo $row instanceof PriceRow ? "{$row->marketHashName}: {$row->price}\n" : "Not listed\n";
