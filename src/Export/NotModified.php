<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

/**
 * The server answered 304 to a conditional request (SDK_SPEC 3.10): the export at `url` is the one the caller already
 * has, so nothing was downloaded.
 */
final readonly class NotModified
{
    public function __construct(
        public string $url,
    ) {}
}
