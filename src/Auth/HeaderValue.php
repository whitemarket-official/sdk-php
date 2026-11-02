<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * A credential that is sent as the whole value of an HTTP header (the Partner Token): visible ASCII, inner spaces
 * allowed, no leading or trailing whitespace, no control bytes. The Partner Token format itself is undocumented, so
 * only the header grammar is enforced.
 *
 * @internal
 */
final class HeaderValue
{
    public const PATTERN = '/^[\x21-\x7E](?:[\x20-\x7E]*[\x21-\x7E])?$/D';

    public static function isValid(#[\SensitiveParameter] string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
