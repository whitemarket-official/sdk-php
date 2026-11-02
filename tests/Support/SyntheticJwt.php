<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

/**
 * Builds JWT-shaped strings with chosen claims and a dummy signature (nothing signs or verifies them).
 */
final class SyntheticJwt
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $claims
     */
    public static function withClaims(array $claims): string
    {
        return self::segment(['alg' => 'none', 'typ' => 'JWT']) . '.' . self::segment($claims) . '.synthetic-signature';
    }

    /**
     * A token issued at `$issuedAt` that lives `$seconds`.
     */
    public static function issuedAt(\DateTimeImmutable $issuedAt, int $seconds, string $subject = 'synthetic-subject'): string
    {
        return self::withClaims(['sub' => $subject, 'iat' => $issuedAt->getTimestamp(), 'exp' => $issuedAt->getTimestamp() + $seconds]);
    }

    /**
     * @param array<mixed> $value
     */
    private static function segment(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
