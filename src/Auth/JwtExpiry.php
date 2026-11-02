<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * When an access token expires, read from the JWT payload without verifying the signature (the SDK has no key;
 * WHITEMARKET_API.md 3). The lifetime is `exp - iat` when both are present, `exp - now` otherwise, and it is counted
 * from the local clock so a skewed host clock does not shorten it. Anything unreadable gives the documented 24 h, and
 * no lifetime is taken as longer than that.
 *
 * @internal used by {@see PartnerTokenProvider}
 */
final class JwtExpiry
{
    private const DOCUMENTED_LIFETIME_SECONDS = 24 * 3600;

    private function __construct() {}

    public static function of(#[\SensitiveParameter] string $jwt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimestamp($now->getTimestamp() + self::lifetimeSeconds($jwt, $now->getTimestamp()));
    }

    private static function lifetimeSeconds(#[\SensitiveParameter] string $jwt, int $now): int
    {
        $claims = self::claims($jwt);
        $exp = $claims['exp'] ?? null;
        if (!\is_int($exp)) {
            return self::DOCUMENTED_LIFETIME_SECONDS;
        }
        $iat = $claims['iat'] ?? null;
        // A negative `iat` could overflow `exp - iat` into a float; `exp - now` only overflows below zero.
        $lifetime = \is_int($iat) && $iat >= 0 && $exp > $iat ? $exp - $iat : $exp - $now;

        return $lifetime > 0 ? min($lifetime, self::DOCUMENTED_LIFETIME_SECONDS) : self::DOCUMENTED_LIFETIME_SECONDS;
    }

    /**
     * @return array<mixed>
     */
    private static function claims(#[\SensitiveParameter] string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (\count($parts) !== 3) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($json === false) {
            return [];
        }
        $claims = json_decode($json, true);

        return \is_array($claims) ? $claims : [];
    }
}
