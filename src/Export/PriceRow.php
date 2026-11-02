<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Money;

/**
 * One row of the prices export: the cheapest lot of an item (SDK_SPEC 3.10). `price` is USD. Ids are strings:
 * `cheapestAssetId` is a JSON number beyond 2^53 in the file. `cheapestFloat` is null for items without a float and
 * sometimes for the cheapest lot. `inspectLink` is null where the file has an empty string. `extra` holds every key of
 * the row this SDK version does not know, as decoded (a new server field is never an error).
 *
 * The record key is `(marketHashName, paintIndex)`: Doppler phases share a name and differ by paint index
 * ({@see PriceList}).
 */
final readonly class PriceRow
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $marketHashName,
        public Money $price,
        public string $marketProductLink,
        public int $marketProductCount,
        public string $cheapestAssetId,
        public ?Decimal $cheapestFloat,
        public string $paintIndex,
        public string $paintSeed,
        public ?string $inspectLink,
        public array $extra = [],
    ) {}
}
