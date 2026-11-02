<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Buying;

/**
 * Optional parts of a purchase (SDK_SPEC 3.3).
 */
final readonly class BuyOptions
{
    /** Longest `customId` the API accepts (SDK_SPEC 3.3), counted in characters. */
    public const MAX_CUSTOM_ID_LENGTH = 100;

    /**
     * @param ?string $customId your own id of the purchase, 1 to 100 characters; reconciliation finds the deal by it.
     *                          A UUIDv4 is generated when null. Must be unique per purchase: reusing the id of an
     *                          earlier purchase can make reconciliation return that earlier deal (server-side
     *                          uniqueness is not documented, WHITEMARKET_API.md 6). Pass and persist your own id
     *                          before calling when a lost outcome is unacceptable: a fatal error (process killed,
     *                          memory exhausted) ends the call without an answer.
     * @param ?string $tradeUrl Steam trade URL of a third party to deliver the item to; the buyer's own when null
     *
     * @throws \InvalidArgumentException `$customId` is empty, longer than 100 characters or not valid UTF-8
     */
    public function __construct(
        public ?string $customId = null,
        #[\SensitiveParameter]
        public ?string $tradeUrl = null,
    ) {
        // `/u` counts characters without ext-mbstring and fails on invalid UTF-8, which JSON could not carry anyway.
        if ($customId !== null && preg_match('/^.{1,' . self::MAX_CUSTOM_ID_LENGTH . '}$/sDu', $customId) !== 1) {
            throw new \InvalidArgumentException(\sprintf('customId must be 1 to %d characters of valid UTF-8.', self::MAX_CUSTOM_ID_LENGTH));
        }
    }

    /**
     * The trade URL carries the Steam trade token, so `var_dump()` and debuggers show `***` instead.
     *
     * @return array{customId: ?string, tradeUrl: ?string}
     */
    public function __debugInfo(): array
    {
        return ['customId' => $this->customId, 'tradeUrl' => $this->tradeUrl === null ? null : '***'];
    }
}
