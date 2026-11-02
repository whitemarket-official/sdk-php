<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * The response does not have the shape the selection promises: a required field is missing, null or of the wrong type,
 * or the body is not a GraphQL response (or, for exports, not the JSON file) at all. `path` points at the offending value, for example
 * `market_list.edges[3].node.price`.
 */
final class DecodeException extends WhiteMarketException
{
    public function __construct(
        public readonly string $path,
        string $problem,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('Cannot decode the response at "%s": %s', $path, $problem), 0, $previous);
    }
}
