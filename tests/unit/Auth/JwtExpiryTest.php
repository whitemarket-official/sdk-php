<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\JwtExpiry;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;

#[CoversClass(JwtExpiry::class)]
final class JwtExpiryTest extends TestCase
{
    private const NOW = 1_767_225_600; // 2026-01-01T00:00:00Z

    private const DAY = 86_400;

    /**
     * @return iterable<string, array{string, int}> token => expected lifetime in seconds from NOW
     */
    public static function tokens(): iterable
    {
        yield 'exp and iat -> exp - iat' => [SyntheticJwt::withClaims(['iat' => self::NOW, 'exp' => self::NOW + 3600]), 3600];
        yield 'host clock 2 h ahead of the issuer -> still the full lifetime' => [SyntheticJwt::withClaims(['iat' => self::NOW - 7200, 'exp' => self::NOW - 7200 + self::DAY]), self::DAY];
        yield 'host clock 2 h behind the issuer -> still the full lifetime' => [SyntheticJwt::withClaims(['iat' => self::NOW + 7200, 'exp' => self::NOW + 7200 + self::DAY]), self::DAY];
        yield 'exp without iat -> exp - now' => [SyntheticJwt::withClaims(['exp' => self::NOW + 600]), 600];
        yield 'exp one second ahead, no iat' => [SyntheticJwt::withClaims(['exp' => self::NOW + 1]), 1];
        yield 'exp equal to iat -> exp - now' => [SyntheticJwt::withClaims(['iat' => self::NOW + 50, 'exp' => self::NOW + 50]), 50];
        yield 'exp before iat -> exp - now' => [SyntheticJwt::withClaims(['iat' => self::NOW + 900, 'exp' => self::NOW + 60]), 60];
        yield 'iat as a string is ignored' => [SyntheticJwt::withClaims(['iat' => (string) self::NOW, 'exp' => self::NOW + 120]), 120];
        yield 'exp equal to now -> 24 h' => [SyntheticJwt::withClaims(['exp' => self::NOW]), self::DAY];
        yield 'exp in the past -> 24 h' => [SyntheticJwt::withClaims(['exp' => self::NOW - 10]), self::DAY];
        yield 'no exp -> 24 h' => [SyntheticJwt::withClaims(['iat' => self::NOW]), self::DAY];
        yield 'exp as a string -> 24 h' => [SyntheticJwt::withClaims(['exp' => (string) (self::NOW + 60)]), self::DAY];
        yield 'exp as a float -> 24 h' => [SyntheticJwt::withClaims(['exp' => self::NOW + 60.5]), self::DAY];
        yield 'payload is a JSON scalar -> 24 h' => ['e30.' . rtrim(base64_encode('42'), '=') . '.s', self::DAY];
        yield 'payload is not JSON -> 24 h' => ['e30.' . rtrim(base64_encode('{exp:1}'), '=') . '.s', self::DAY];
        yield 'payload is not base64 -> 24 h' => ['e30.***.s', self::DAY];
        yield 'two segments -> 24 h' => ['e30.' . rtrim(base64_encode('{"exp":' . (self::NOW + 60) . '}'), '='), self::DAY];
        yield 'four segments -> 24 h' => ['e30.' . rtrim(base64_encode('{"exp":' . (self::NOW + 60) . '}'), '=') . '.s.x', self::DAY];
        yield 'opaque token -> 24 h' => ['synthetic-opaque-token', self::DAY];
        yield 'lifetime longer than documented -> capped at 24 h' => [SyntheticJwt::withClaims(['iat' => self::NOW, 'exp' => self::NOW + self::DAY + 1]), self::DAY];
        yield 'exp at the largest integer -> capped at 24 h' => [SyntheticJwt::withClaims(['iat' => 1, 'exp' => \PHP_INT_MAX]), self::DAY];
        yield 'exp at the largest integer, no iat -> capped at 24 h' => [SyntheticJwt::withClaims(['exp' => \PHP_INT_MAX]), self::DAY];
        yield 'negative iat is ignored' => [SyntheticJwt::withClaims(['iat' => \PHP_INT_MIN, 'exp' => self::NOW + 90]), 90];
        yield 'exp at the smallest integer -> 24 h' => [SyntheticJwt::withClaims(['exp' => \PHP_INT_MIN]), self::DAY];
    }

    #[Test]
    #[DataProvider('tokens')]
    public function countsTheLifetimeFromTheLocalClock(string $jwt, int $lifetime): void
    {
        $now = new \DateTimeImmutable('@' . self::NOW);

        self::assertSame(self::NOW + $lifetime, JwtExpiry::of($jwt, $now)->getTimestamp());
    }

    #[Test]
    public function readsBase64UrlPayloads(): void
    {
        // Characters whose base64 has "/" or "+": the payload needs the URL alphabet ("_", "-").
        $jwt = SyntheticJwt::withClaims(['exp' => self::NOW + 70, 'sub' => '?>?']);
        self::assertMatchesRegularExpression('/[-_]/', explode('.', $jwt)[1] ?? '');

        self::assertSame(self::NOW + 70, JwtExpiry::of($jwt, new \DateTimeImmutable('@' . self::NOW))->getTimestamp());
    }
}
