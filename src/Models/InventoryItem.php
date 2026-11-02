<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * A CS2 item: in an inventory, behind a listing or in a deal.
 *
 * Steam ids (`assetId`, `classId`, `instanceId`) are 64-bit numbers kept as strings (CLAUDE.md rule 3). Fields taken
 * from the item's Steam description (`name`, `isStatTrak`, `isSouvenir`) are null when the server sends none.
 */
final readonly class InventoryItem
{
    /**
     * @param string $id WhiteMarket id of the inventory item
     * @param ?Decimal $float wear value as an exact decimal (pattern hunters compare many digits)
     * @param ?string $inspectLink the Steam inspect link
     * @param list<Sticker> $stickers
     */
    public function __construct(
        public string $id,
        public string $assetId,
        public string $classId,
        public string $instanceId,
        public ?string $nameHash,
        public ?string $name,
        public ?Decimal $float,
        public ?string $paintSeed,
        public ?string $paintIndex,
        public ?string $phase,
        public ?string $inspectLink,
        public ?bool $isStatTrak,
        public ?bool $isSouvenir,
        public array $stickers,
    ) {}
}
