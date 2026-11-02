<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

/**
 * A generated GraphQL input object.
 */
interface InputObject
{
    /**
     * JSON-ready representation; fields left `null` are omitted, so the server applies its defaults.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
