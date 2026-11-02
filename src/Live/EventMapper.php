<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Live\Event\DealCancelled;
use WhiteMarket\Sdk\Live\Event\DealCompleted;
use WhiteMarket\Sdk\Live\Event\DealEvent;
use WhiteMarket\Sdk\Live\Event\DealExchanged;
use WhiteMarket\Sdk\Live\Event\DealExpired;
use WhiteMarket\Sdk\Live\Event\DealHold;
use WhiteMarket\Sdk\Live\Event\DealInitiated;
use WhiteMarket\Sdk\Live\Event\DealNeedConfirmation;
use WhiteMarket\Sdk\Live\Event\DealOffered;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\Event\ProductEdited;
use WhiteMarket\Sdk\Live\Event\ProductRemoved;
use WhiteMarket\Sdk\Live\Event\UnknownEvent;
use WhiteMarket\Sdk\Live\Protocol\Publication;
use WhiteMarket\Sdk\Live\Protocol\WireJson;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryWire;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * A {@see Publication} to a {@see LiveEvent}: the one place that knows the shapes of live messages (SDK_SPEC 4).
 *
 * `pub.data.message` is a JSON string (observed) or an already decoded object (docs); the decoded message is
 * `{type, content}`. Every scalar of a content object is a string. Never throws for a publication: an unknown type, an
 * unknown channel (`broadcast`) or a message that is not JSON gives an {@see UnknownEvent}; a known type with a missing
 * or mistyped required field gives an UnknownEvent plus the {@see DecodeException} in {@see MappedEvent::$error}.
 * Blank optional strings are null; a blank required string is malformed. `price` is USD (live messages carry no
 * currency, owner decision O1). Keys this version does not know are kept in `extra`. A failure names the field
 * (`live.market_product_added.price`), never a server value.
 *
 * @internal used by the live session
 */
final class EventMapper
{
    private const ADDED = 'market_product_added';
    private const EDITED = 'market_product_edited';
    private const REMOVED = 'market_product_removed';

    private const PRODUCT_KEYS = [
        'id', 'app_id', 'asset_id', 'class_id', 'instance_id', 'price', 'name_hash', 'inspect_url', 'delivery_type',
        'delivery_promise', 'float', 'paint_index', 'paint_seed', 'stickers', 'keychains',
    ];
    private const REMOVED_KEYS = ['id', 'app_id', 'asset_id', 'class_id', 'instance_id', 'price', 'name_hash'];
    private const DEAL_KEYS = ['deal_id', 'deal_action_type', 'market_product_name', 'deal_steam_id'];

    /**
     * @var array<string, class-string<DealEvent>>
     */
    private const DEALS = [
        'market_deal_initiated' => DealInitiated::class,
        'market_deal_offered' => DealOffered::class,
        'market_deal_need_confirmation' => DealNeedConfirmation::class,
        'market_deal_exchanged' => DealExchanged::class,
        'market_deal_completed' => DealCompleted::class,
        'market_deal_expired' => DealExpired::class,
        'market_deal_cancelled' => DealCancelled::class,
        'market_deal_hold' => DealHold::class,
    ];

    private function __construct() {}

    public static function map(#[\SensitiveParameter] Publication $publication): MappedEvent
    {
        $channel = $publication->channel;
        $message = $publication->data['message'] ?? null;
        if (\is_string($message)) {
            try {
                $decoded = WireJson::decode($message, 'live.message');
            } catch (DecodeException) {
                return new MappedEvent(new UnknownEvent($channel, null, $message));
            }
        } else {
            $decoded = $message ?? $publication->data;
        }
        $object = WireJson::objectOrNull($decoded);
        if ($object === null) {
            return new MappedEvent(new UnknownEvent($channel, null, \is_string($message) ? $message : $publication->data));
        }

        $type = \is_string($object['type'] ?? null) ? $object['type'] : null;
        $content = $object['content'] ?? null;
        $raw = \is_array($content) ? $content : $object;
        if ($type === null || !self::isKnown($type)) {
            return new MappedEvent(new UnknownEvent($channel, $type, $raw));
        }

        try {
            return new MappedEvent(self::known($channel, $type, WireJson::objectOrNull($content) ?? throw new DecodeException('live.' . $type, 'content is not an object')));
        } catch (DecodeException $e) {
            return new MappedEvent(new UnknownEvent($channel, $type, $raw), $e);
        }
    }

    private static function isKnown(string $type): bool
    {
        return \in_array($type, [self::ADDED, self::EDITED, self::REMOVED], true) || isset(self::DEALS[$type]);
    }

    /**
     * @param array<string, mixed> $content
     *
     * @throws DecodeException
     */
    private static function known(string $channel, string $type, #[\SensitiveParameter] array $content): LiveEvent
    {
        $path = 'live.' . $type;
        if (isset(self::DEALS[$type])) {
            return self::deal(self::DEALS[$type], $channel, $content, $path);
        }

        return match ($type) {
            self::ADDED => self::added($channel, $content, $path),
            self::EDITED => self::edited($channel, $content, $path),
            default => self::removed($channel, $content, $path),
        };
    }

    /**
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function added(string $channel, #[\SensitiveParameter] array $c, string $path): ProductAdded
    {
        return new ProductAdded(
            channel: $channel,
            id: self::required($c, 'id', $path),
            appId: self::requiredId($c, 'app_id', $path),
            assetId: self::requiredId($c, 'asset_id', $path),
            classId: self::requiredId($c, 'class_id', $path),
            instanceId: self::requiredId($c, 'instance_id', $path),
            price: self::price($c, $path),
            marketHashName: self::required($c, 'name_hash', $path),
            inspectLink: self::optional($c, 'inspect_url', $path),
            deliveryType: self::optionalWith($c, 'delivery_type', $path, DeliveryWire::type(...)),
            deliveryPromise: self::optionalWith($c, 'delivery_promise', $path, DeliveryWire::promise(...)),
            float: self::optionalFloat($c, $path),
            paintIndex: self::optional($c, 'paint_index', $path),
            paintSeed: self::optional($c, 'paint_seed', $path),
            stickers: self::list($c, 'stickers', $path) ?? [],
            keychains: self::list($c, 'keychains', $path) ?? [],
            extra: array_diff_key($c, array_flip(self::PRODUCT_KEYS)),
        );
    }

    /**
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function edited(string $channel, #[\SensitiveParameter] array $c, string $path): ProductEdited
    {
        return new ProductEdited(
            channel: $channel,
            id: self::required($c, 'id', $path),
            appId: self::optionalId($c, 'app_id', $path),
            assetId: self::optionalId($c, 'asset_id', $path),
            classId: self::optionalId($c, 'class_id', $path),
            instanceId: self::optionalId($c, 'instance_id', $path),
            price: self::optional($c, 'price', $path) === null ? null : self::price($c, $path),
            marketHashName: self::optional($c, 'name_hash', $path),
            inspectLink: self::optional($c, 'inspect_url', $path),
            deliveryType: self::optionalWith($c, 'delivery_type', $path, DeliveryWire::type(...)),
            deliveryPromise: self::optionalWith($c, 'delivery_promise', $path, DeliveryWire::promise(...)),
            float: self::optionalFloat($c, $path),
            paintIndex: self::optional($c, 'paint_index', $path),
            paintSeed: self::optional($c, 'paint_seed', $path),
            stickers: self::list($c, 'stickers', $path),
            keychains: self::list($c, 'keychains', $path),
            extra: array_diff_key($c, array_flip(self::PRODUCT_KEYS)),
        );
    }

    /**
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function removed(string $channel, #[\SensitiveParameter] array $c, string $path): ProductRemoved
    {
        return new ProductRemoved(
            channel: $channel,
            id: self::required($c, 'id', $path),
            appId: self::requiredId($c, 'app_id', $path),
            assetId: self::requiredId($c, 'asset_id', $path),
            classId: self::requiredId($c, 'class_id', $path),
            instanceId: self::requiredId($c, 'instance_id', $path),
            price: self::price($c, $path),
            marketHashName: self::required($c, 'name_hash', $path),
            extra: array_diff_key($c, array_flip(self::REMOVED_KEYS)),
        );
    }

    /**
     * @param class-string<DealEvent> $class
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function deal(string $class, string $channel, #[\SensitiveParameter] array $c, string $path): DealEvent
    {
        $action = strtoupper(self::required($c, 'deal_action_type', $path));

        return new $class(
            $channel,
            self::required($c, 'deal_id', $path),
            DealAction::tryFrom($action) ?? new UnknownEnumValue(DealAction::class, $action),
            self::optional($c, 'market_product_name', $path),
            self::optional($c, 'deal_steam_id', $path),
            array_diff_key($c, array_flip(self::DEAL_KEYS)),
        );
    }

    /**
     * A non-blank string.
     *
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function required(#[\SensitiveParameter] array $c, string $key, string $path): string
    {
        return self::optional($c, $key, $path) ?? throw new DecodeException($path . '.' . $key, 'missing or blank');
    }

    /**
     * A non-blank string, null when the key is missing, null or blank.
     *
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function optional(#[\SensitiveParameter] array $c, string $key, string $path): ?string
    {
        $value = $c[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw new DecodeException($path . '.' . $key, 'not a string');
        }

        return trim($value) === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function requiredId(#[\SensitiveParameter] array $c, string $key, string $path): string
    {
        return self::optionalId($c, $key, $path) ?? throw new DecodeException($path . '.' . $key, 'missing or blank');
    }

    /**
     * A 64-bit id as a digit string, never converted to a number.
     *
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function optionalId(#[\SensitiveParameter] array $c, string $key, string $path): ?string
    {
        $value = self::optional($c, $key, $path);
        if ($value !== null && preg_match('/^\d+$/D', $value) !== 1) {
            throw new DecodeException($path . '.' . $key, 'not an integer');
        }

        return $value;
    }

    /**
     * @template T
     *
     * @param array<string, mixed> $c
     * @param \Closure(string): T $convert
     *
     * @return T|null
     *
     * @throws DecodeException
     */
    private static function optionalWith(#[\SensitiveParameter] array $c, string $key, string $path, \Closure $convert): mixed
    {
        $value = self::optional($c, $key, $path);

        return $value === null ? null : $convert($value);
    }

    /**
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function optionalFloat(#[\SensitiveParameter] array $c, string $path): ?Decimal
    {
        return self::optionalWith($c, 'float', $path, static fn(string $value): Decimal => Decimal::tryOf($value) ?? throw new DecodeException($path . '.float', 'not a decimal number'));
    }

    /**
     * @param array<string, mixed> $c
     *
     * @throws DecodeException
     */
    private static function price(#[\SensitiveParameter] array $c, string $path): Money
    {
        $price = Decimal::tryOf(self::required($c, 'price', $path));

        return new Money($price ?? throw new DecodeException($path . '.price', 'not a decimal number'), Currency::Usd);
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return list<array<string, mixed>>|null null when the key is missing or null
     *
     * @throws DecodeException
     */
    private static function list(#[\SensitiveParameter] array $c, string $key, string $path): ?array
    {
        $value = $c[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw new DecodeException($path . '.' . $key, 'not a list');
        }
        foreach ($value as $index => $item) {
            if (WireJson::objectOrNull($item) === null || $item === []) {
                throw new DecodeException(\sprintf('%s.%s[%d]', $path, $key, $index), 'not an object');
            }
        }
        /** @var list<array<string, mixed>> $value */
        return $value;
    }
}
