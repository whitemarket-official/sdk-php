<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * How the export files and live messages write the delivery fields, in one place for both mappers: the type as a
 * lower-case word (`semi`), the promise as the window suffix (`10min`). The GraphQL schema uses the enum names instead.
 * A value this SDK version does not know becomes {@see UnknownEnumValue} with the raw wire value (CLAUDE.md rule 5).
 *
 * @internal used by the export and live mappers
 */
final class DeliveryWire
{
    private const PROMISES = [
        '10min' => DeliveryPromise::Minutes10,
        '1hour' => DeliveryPromise::Hour1,
        '12hours' => DeliveryPromise::Hours12,
        '24hours' => DeliveryPromise::Hours24,
    ];

    private function __construct() {}

    public static function type(#[\SensitiveParameter] string $wire): DeliveryType|UnknownEnumValue
    {
        return DeliveryType::tryFrom(strtoupper($wire)) ?? new UnknownEnumValue(DeliveryType::class, strtoupper($wire));
    }

    public static function promise(#[\SensitiveParameter] string $wire): DeliveryPromise|UnknownEnumValue
    {
        return self::PROMISES[$wire] ?? new UnknownEnumValue(DeliveryPromise::class, $wire);
    }
}
