<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

use WhiteMarket\Sdk\Models\Uuid;

/**
 * Tells the two ways of naming an item or a listing apart by format (P12): a UUID, or a 64-bit Steam asset id written
 * in digits and kept as a string so it never loses precision.
 *
 * @internal shared by the selling value objects and {@see SellingApi}; not part of the public API
 */
final class ItemReference
{
    private const ASSET_ID_PATTERN = '/^[0-9]{1,20}$/D';

    private const SHOWN_LENGTH = 40;

    private function __construct() {}

    /**
     * @return array{inventoryId: ?string, assetId: ?string} exactly one of them is set
     *
     * @throws \InvalidArgumentException `$item` is neither a UUID nor digits
     */
    public static function inventoryOrAsset(string $item): array
    {
        [$inventoryId, $assetId] = self::uuidOrAsset($item, 'item must be an inventory id (UUID)');

        return ['inventoryId' => $inventoryId, 'assetId' => $assetId];
    }

    /**
     * @return array{productId: ?string, assetId: ?string} exactly one of them is set
     *
     * @throws \InvalidArgumentException `$product` is neither a UUID nor digits
     */
    public static function productOrAsset(string $product): array
    {
        [$productId, $assetId] = self::uuidOrAsset($product, 'product must be a listing id (UUID)');

        return ['productId' => $productId, 'assetId' => $assetId];
    }

    /**
     * @throws \InvalidArgumentException `$productId` is not a UUID
     */
    public static function assertProductId(string $productId): void
    {
        if (!Uuid::isValid($productId)) {
            throw new \InvalidArgumentException(\sprintf('The product id must be a UUID, got "%s".', self::shown($productId)));
        }
    }

    /**
     * @return array{?string, ?string} the UUID or the asset id, in this order; the other is null
     *
     * @throws \InvalidArgumentException `$value` is neither a UUID nor digits
     */
    private static function uuidOrAsset(string $value, string $expected): array
    {
        if (Uuid::isValid($value)) {
            return [$value, null];
        }
        if (self::isAssetId($value)) {
            return [null, $value];
        }

        throw new \InvalidArgumentException(\sprintf('The %s or a Steam asset id (digits), got "%s".', $expected, self::shown($value)));
    }

    private static function isAssetId(string $value): bool
    {
        return preg_match(self::ASSET_ID_PATTERN, $value) === 1;
    }

    /**
     * The value for an error message: pure ASCII (every byte outside 0x20..0x7E is written as `\xNN`, so a logger's
     * `json_encode` never meets invalid UTF-8 and a control sequence never reaches a terminal), at most
     * {@see SHOWN_LENGTH} characters, cut between whole escapes, with `...` when cut.
     */
    private static function shown(string $value): string
    {
        $shown = '';
        for ($i = 0, $length = \strlen($value); $i < $length; ++$i) {
            $byte = \ord($value[$i]);
            $piece = $byte >= 0x20 && $byte < 0x7F ? $value[$i] : \sprintf('\\x%02x', $byte);
            if (\strlen($shown) + \strlen($piece) > self::SHOWN_LENGTH) {
                return $shown . '...';
            }
            $shown .= $piece;
        }

        return $shown;
    }
}
