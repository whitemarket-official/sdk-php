<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * When an access token expires, read from the JWT payload without verifying the signature (the SDK has no key;
 * WHITEMARKET_API.md 3). The lifetime is `exp - now` by the local clock (taken in UTC and rounded UP to whole seconds, so the expiry is the
 * exact clock plus that lifetime: never past `exp`, at most 1 s before it), and never more than `exp - iat` when both
 * are present, so a token never outlives its `exp` and a host clock behind the issuer does not lengthen it. An `exp`
 * in the past gives 0; with the provider's refresh margin, an `exp` within 5 minutes counts as expired too, and the
 * token is exchanged again before the next request instead of after a 401 (SDK_SPEC 3.1). A non-integer NumericDate
 * is rounded down, one outside the integer range is clamped to its end. Anything unreadable gives the documented
 * 24 h, and no lifetime is taken as longer than that.
 *
 * @internal used by {@see PartnerTokenProvider}
 */
final class JwtExpiry
{
    private const DOCUMENTED_LIFETIME_SECONDS = 24 * 3600;

    private function __construct() {}

    public static function of(#[\SensitiveParameter] string $jwt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        return $utc->add(new \DateInterval(\sprintf('PT%dS', self::lifetimeSeconds($jwt, self::secondsRoundedUp($utc)))));
    }

    /**
     * The clock in whole seconds, rounded up. getTimestamp() floors, also before 1970.
     */
    private static function secondsRoundedUp(\DateTimeImmutable $clock): int
    {
        $seconds = $clock->getTimestamp();

        return $seconds < \PHP_INT_MAX && (int) $clock->format('u') > 0 ? $seconds + 1 : $seconds;
    }

    private static function lifetimeSeconds(#[\SensitiveParameter] string $jwt, int $now): int
    {
        $claims = self::claims($jwt);
        $exp = self::numericDate($claims['exp'] ?? null);
        if ($exp === null) {
            return self::DOCUMENTED_LIFETIME_SECONDS;
        }
        if ($exp <= $now) {
            return 0;
        }
        // Never past `exp` by the local clock (SDK_SPEC 3.1), so an expired token is never served from the store.
        $lifetime = $exp - $now;
        $iat = self::numericDate($claims['iat'] ?? null);
        // A negative `iat` could overflow `exp - iat` into a float.
        if ($iat !== null && $iat >= 0 && $exp > $iat) {
            $lifetime = min($lifetime, $exp - $iat);
        }

        return min($lifetime, self::DOCUMENTED_LIFETIME_SECONDS);
    }

    /**
     * A NumericDate (RFC 7519 2) as whole seconds, rounded down; outside the integer range (infinity included) it is
     * clamped to the nearest end, so a date far in the past still reads as expired. Null when it is not a number.
     */
    private static function numericDate(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (!\is_float($value) || is_nan($value)) {
            return null;
        }
        if ($value < -9.2233720368547E+18) {
            return \PHP_INT_MIN;
        }
        if ($value >= 9.2233720368547E+18) {
            return \PHP_INT_MAX;
        }

        return (int) floor($value);
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
