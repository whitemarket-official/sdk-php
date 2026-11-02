<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * An enum value the server sent that this SDK version does not know yet (CLAUDE.md rule 5). Keeps the raw value.
 */
final readonly class UnknownEnumValue
{
    /**
     * @param class-string<\BackedEnum> $enum
     */
    public function __construct(
        public string $enum,
        public string $value,
    ) {}
}
