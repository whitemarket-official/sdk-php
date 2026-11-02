<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

/**
 * A publication this SDK version does not map (CLAUDE.md rule 5): a `type` it does not know (wallet, order, inventory,
 * a new one), a channel without typed events (`broadcast`), or a known type whose content is malformed (the session
 * reports that one through `onError`). `type` is null when the message has none. `rawContent` is the decoded `content`
 * (or the whole decoded message when it has no `content`), or the raw string when the message is not JSON.
 */
final readonly class UnknownEvent implements LiveEvent
{
    /**
     * @param array<array-key, mixed>|string $rawContent
     */
    public function __construct(
        public string $channel,
        public ?string $type,
        public array|string $rawContent,
    ) {}
}
