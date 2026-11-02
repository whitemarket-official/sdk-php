<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * What may follow `Bearer ` in an Authorization header: RFC 6750 b64token / RFC 9110 token68. A token with any other
 * byte (CR/LF, a space, a comma) would corrupt or split the header, wherever it came from: the server, a shared store,
 * the caller.
 *
 * @internal
 */
final class BearerToken
{
    public const PATTERN = '/^[A-Za-z0-9\-._~+\/]+=*$/D';

    public static function isValid(#[\SensitiveParameter] string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
