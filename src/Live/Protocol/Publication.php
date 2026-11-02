<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * A message published to a channel: `data` is the decoded `pub.data` object (`{"message": ...}`).
 *
 * @internal
 */
final readonly class Publication
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        public string $channel,
        #[\SensitiveParameter]
        public array $data,
    ) {}
}
