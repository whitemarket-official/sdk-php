<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\BadRequestException;
use WhiteMarket\Sdk\Errors\GeneralHttpException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;

#[CoversClass(HttpException::class)]
final class HttpExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, class-string<HttpException>}>
     */
    public static function statuses(): iterable
    {
        yield '300 -> generic' => [300, GeneralHttpException::class];
        yield '399 -> generic' => [399, GeneralHttpException::class];
        yield '400 -> bad request' => [400, BadRequestException::class];
        yield '401 -> authentication' => [401, AuthenticationException::class];
        yield '403 -> generic' => [403, GeneralHttpException::class];
        yield '404 -> generic' => [404, GeneralHttpException::class];
        yield '428 -> generic' => [428, GeneralHttpException::class];
        yield '429 -> rate limit' => [429, RateLimitException::class];
        yield '499 -> generic' => [499, GeneralHttpException::class];
        yield '500 -> server' => [500, GeneralServerException::class];
        yield '502 -> server' => [502, GeneralServerException::class];
        yield '503 -> service unavailable' => [503, ServiceUnavailableException::class];
        yield '504 -> server' => [504, GeneralServerException::class];
        yield '599 -> server' => [599, GeneralServerException::class];
        yield '600 -> generic' => [600, GeneralHttpException::class];
    }

    /**
     * @param class-string<HttpException> $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function mapsEveryStatusToItsClass(int $status, string $expected): void
    {
        $e = HttpException::fromStatus($status, 'body');

        self::assertSame($e::class, $expected);
        self::assertSame($status, $e->status);
    }

    /**
     * @return iterable<string, array{int, ?int}>
     */
    public static function retryAfterByStatus(): iterable
    {
        yield '429 keeps it' => [429, 7];
        yield '503 keeps it' => [503, 7];
        yield '500 drops it' => [500, null];
        yield '400 drops it' => [400, null];
    }

    #[Test]
    #[DataProvider('retryAfterByStatus')]
    public function keepsRetryAfterOnlyWhereTheSpecDefinesIt(int $status, ?int $expected): void
    {
        self::assertSame($expected, HttpException::fromStatus($status, '', 7)->retryAfter);
    }

    #[Test]
    public function keepsTheStartOfTheBodyOutOfTheMessage(): void
    {
        $body = str_repeat('a', HttpException::BODY_EXCERPT_BYTES) . 'TAIL';

        $e = HttpException::fromStatus(500, $body);

        self::assertSame(str_repeat('a', HttpException::BODY_EXCERPT_BYTES), $e->bodyExcerpt);
        self::assertSame('HTTP 500 from the WhiteMarket API', $e->getMessage());
    }

    #[Test]
    public function redactsSecretsInTheExcerpt(): void
    {
        $excerpt = HttpException::fromStatus(500, '{"accessToken":"synthetic-access","code":1}')->bodyExcerpt;

        self::assertSame('{"accessToken":"***","code":1}', $excerpt);
    }

    #[Test]
    public function keepsTheRawBodyOutOfTheTraceArguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $e = HttpException::fromStatus(401, 'synthetic-raw-body {"accessToken":"synthetic-access"}');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        self::assertStringNotContainsString('synthetic-access', var_export($e->getTrace(), true));
        self::assertStringNotContainsString('synthetic-raw', $e->getTraceAsString());
    }

    #[Test]
    public function keepsAShortBodyWhole(): void
    {
        self::assertSame('{"error":{"code":401}}', HttpException::fromStatus(401, '{"error":{"code":401}}')->bodyExcerpt);
    }
}
