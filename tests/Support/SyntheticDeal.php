<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

/**
 * Synthetic wire values (as PHP arrays) of a deal, an archived deal and a listing history entry, for the generated
 * result classes of the operations that select them (`market_buy`, `deal_item`, `deal_history_item`, `market_remove`, ...).
 *
 * The namespace of the generated result names the classes: `Result`, `Product`, `ProductParamsCSGOInventoryItem`,
 * `ProductDescriptionCSGOSteamItem`, `ItemCSGOInventoryItem`. An operation whose classes are named differently passes a
 * class map (keys `result`, `product`, `params`, `description`, `item`). Overrides replace whole fields.
 *
 * Synthetic data only (CLAUDE.md "Data policy"); the arrays go through the real generated `fromValue()`.
 */
final class SyntheticDeal
{
    public const DEAL_ID = '0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d70';

    public const PRODUCT_ID = '4f1d9e2a-7b3c-4d5e-8f6a-1b2c3d4e5f60';

    private const SUFFIXES = [
        'result' => '\Result',
        'product' => '\Product',
        'params' => '\ProductParamsCSGOInventoryItem',
        'description' => '\ProductDescriptionCSGOSteamItem',
        'item' => '\ItemCSGOInventoryItem',
    ];

    private function __construct() {}

    /**
     * A deal in progress (`Deal`).
     *
     * @param string $namespace of the generated result
     * @param array<string, mixed> $overrides fields of the deal
     * @param array<string, mixed> $itemOverrides fields of the product's item
     * @param array<string, class-string> $classes see the class doc
     *
     * @return array<string, mixed>
     */
    public static function inProgress(string $namespace, array $overrides = [], array $itemOverrides = [], array $classes = []): array
    {
        return $overrides + [
            'id' => self::DEAL_ID,
            'customId' => 'synthetic-custom-id-7',
            'status' => 'OFFERED',
            'price' => ['currency' => 'USD', 'value' => '25.000'],
            'createdAt' => '2026-10-30T09:00:00+00:00',
            'deliveryType' => 'SEMI',
            'steamId' => '76561190000000002',
            'tradeId' => '9000000000000000002',
            'isTradeProtected' => false,
            'settlementTimeAt' => null,
            'expiredAt' => '2026-10-30T21:00:00+00:00',
            'isCancelByBuyerSupported' => true,
            'cancelByBuyerNeedWait' => 1800,
            'product' => self::productHistory($namespace, $itemOverrides, $classes),
            'item' => SyntheticResult::typed('CSGOInventoryItem', self::classOf($namespace, 'item', $classes), self::item()),
        ] + SyntheticResult::of(self::classOf($namespace, 'result', $classes));
    }

    /**
     * An archived deal (`DealHistory`).
     *
     * @param array<string, mixed> $overrides fields of the deal
     * @param array<string, class-string> $classes see the class doc
     *
     * @return array<string, mixed>
     */
    public static function archived(string $namespace, array $overrides = [], array $classes = []): array
    {
        return $overrides + [
            'id' => self::DEAL_ID,
            'customId' => 'synthetic-custom-id-7',
            'status' => 'STEAM_ROLLBACK',
            'cancelReason' => 'STEAM_ISSUES',
            'price' => ['currency' => 'USD', 'value' => '25.000'],
            'archivedAt' => '2026-10-31T10:00:00+00:00',
            'reversePenaltyValue' => ['currency' => 'USD', 'value' => '1.250'],
            'reverseRefundValue' => ['currency' => 'USD', 'value' => '23.750'],
            'product' => self::productHistory($namespace, [], $classes),
        ] + SyntheticResult::of(self::classOf($namespace, 'result', $classes));
    }

    /**
     * A `MarketProductHistory`: the item's leaf fields in `params`, its Steam description on the product.
     *
     * @param array<string, mixed> $itemOverrides fields of the item
     * @param array<string, class-string> $classes see the class doc
     *
     * @return array<string, mixed>
     */
    public static function productHistory(string $namespace, array $itemOverrides = [], array $classes = []): array
    {
        return [
            'id' => self::PRODUCT_ID,
            'status' => 'DEAL',
            'price' => ['currency' => 'USD', 'value' => '25.000'],
            'archivedAt' => '2026-10-31T10:00:00+00:00',
            'deliveryType' => 'MANUAL',
            'assetId' => '40000000000000000002',
            'classId' => '638240019',
            'instanceId' => '188530139',
            'description' => SyntheticResult::typed('CSGOSteamItem', self::classOf($namespace, 'description', $classes), ['name' => 'M4A4 | Howl']),
            'params' => SyntheticResult::typed('CSGOInventoryItem', self::classOf($namespace, 'params', $classes), $itemOverrides + self::item()),
        ] + SyntheticResult::of(self::classOf($namespace, 'product', $classes));
    }

    /**
     * The leaf fields of an inventory item.
     *
     * @return array<string, mixed>
     */
    public static function item(): array
    {
        return ['assetId' => '40000000000000000002', 'classId' => '638240019', 'instanceId' => '188530139', 'float' => '0.0123'];
    }

    /**
     * @param key-of<self::SUFFIXES> $role
     * @param array<string, class-string> $classes
     *
     * @return class-string
     */
    private static function classOf(string $namespace, string $role, array $classes): string
    {
        /** @var class-string $class */
        $class = $classes[$role] ?? $namespace . self::SUFFIXES[$role];

        return $class;
    }
}
