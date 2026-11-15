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

    private const MARGIN = 300;

    /**
     * @return iterable<string, array{string, int}> token => expected lifetime in seconds from NOW
     */
    public static function tokens(): iterable
    {
        yield 'exp and iat -> exp - iat' => [SyntheticJwt::withClaims(['iat' => self::NOW, 'exp' => self::NOW + 3600]), 3600];
        yield 'host clock 2 h ahead of the issuer -> exp - now: the token never outlives its exp' => [SyntheticJwt::withClaims(['iat' => self::NOW - 7200, 'exp' => self::NOW - 7200 + self::DAY]), self::DAY - 7200];
        yield 'host clock 2 h behind the issuer -> still the full lifetime' => [SyntheticJwt::withClaims(['iat' => self::NOW + 7200, 'exp' => self::NOW + 7200 + self::DAY]), self::DAY];
        yield 'exp without iat -> exp - now' => [SyntheticJwt::withClaims(['exp' => self::NOW + 600]), 600];
        yield 'exp one second ahead, no iat' => [SyntheticJwt::withClaims(['exp' => self::NOW + 1]), 1];
        yield 'exp equal to iat -> exp - now' => [SyntheticJwt::withClaims(['iat' => self::NOW + 50, 'exp' => self::NOW + 50]), 50];
        yield 'exp before iat -> exp - now' => [SyntheticJwt::withClaims(['iat' => self::NOW + 900, 'exp' => self::NOW + 60]), 60];
        yield 'iat as a string is ignored' => [SyntheticJwt::withClaims(['iat' => (string) self::NOW, 'exp' => self::NOW + 120]), 120];
        yield 'exp equal to now -> expired' => [SyntheticJwt::withClaims(['exp' => self::NOW]), 0];
        yield 'exp in the past -> expired' => [SyntheticJwt::withClaims(['exp' => self::NOW - 10]), 0];
        yield 'exp in the past with an earlier iat -> expired, not exp - iat' => [SyntheticJwt::withClaims(['iat' => self::NOW - self::DAY, 'exp' => self::NOW - 10]), 0];
        yield 'exp within the margin with an earlier iat -> exp - now, not exp - iat' => [SyntheticJwt::withClaims(['iat' => self::NOW - 3600, 'exp' => self::NOW + self::MARGIN]), self::MARGIN];
        yield 'exp 1 s past the margin with an earlier iat -> exp - now' => [SyntheticJwt::withClaims(['iat' => self::NOW - 3600, 'exp' => self::NOW + self::MARGIN + 1]), self::MARGIN + 1];
        yield 'no exp -> 24 h' => [SyntheticJwt::withClaims(['iat' => self::NOW]), self::DAY];
        yield 'exp as a string -> 24 h' => [SyntheticJwt::withClaims(['exp' => (string) (self::NOW + 60)]), self::DAY];
        yield 'exp as a float -> rounded down' => [SyntheticJwt::withClaims(['exp' => self::NOW + 60.5]), 60];
        yield 'exp as a float just in the past -> expired' => [SyntheticJwt::withClaims(['exp' => self::NOW - 0.5]), 0];
        yield 'iat as a float -> rounded down' => [SyntheticJwt::withClaims(['iat' => self::NOW + 100.7, 'exp' => self::NOW + 160]), 60];
        yield 'exp as a float beyond the integer range -> 24 h' => [SyntheticJwt::withClaims(['exp' => 1.0e300]), self::DAY];
        yield 'exp as a float far below the integer range -> expired' => [SyntheticJwt::withClaims(['exp' => -1.0e300]), 0];
        yield 'exp as an integer literal just below the integer range -> expired' => ['e30.' . rtrim(base64_encode('{"exp":-9223372036854775809}'), '=') . '.s', 0];
        yield 'exp overflowing to minus infinity -> expired' => ['e30.' . rtrim(base64_encode('{"exp":-1e400}'), '=') . '.s', 0];
        yield 'exp overflowing to plus infinity -> capped at 24 h' => ['e30.' . rtrim(base64_encode('{"exp":1e400}'), '=') . '.s', self::DAY];
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
        yield 'exp at the smallest integer -> expired' => [SyntheticJwt::withClaims(['exp' => \PHP_INT_MIN]), 0];
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
