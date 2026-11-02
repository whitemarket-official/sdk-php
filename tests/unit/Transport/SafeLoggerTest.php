<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use WhiteMarket\Sdk\Tests\Support\MemoryLogger;
use WhiteMarket\Sdk\Tests\Support\ThrowingLogger;
use WhiteMarket\Sdk\Transport\SafeLogger;

#[CoversClass(SafeLogger::class)]
final class SafeLoggerTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(LoggerInterface): void}>
     */
    public static function levelMethods(): iterable
    {
        yield 'emergency' => [static fn(LoggerInterface $l) => $l->emergency('message', ['k' => 'v'])];
        yield 'alert' => [static fn(LoggerInterface $l) => $l->alert('message', ['k' => 'v'])];
        yield 'critical' => [static fn(LoggerInterface $l) => $l->critical('message', ['k' => 'v'])];
        yield 'error' => [static fn(LoggerInterface $l) => $l->error('message', ['k' => 'v'])];
        yield 'warning' => [static fn(LoggerInterface $l) => $l->warning('message', ['k' => 'v'])];
        yield 'notice' => [static fn(LoggerInterface $l) => $l->notice('message', ['k' => 'v'])];
        yield 'info' => [static fn(LoggerInterface $l) => $l->info('message', ['k' => 'v'])];
        yield 'debug' => [static fn(LoggerInterface $l) => $l->debug('message', ['k' => 'v'])];
    }

    /**
     * @param \Closure(LoggerInterface): void $call
     */
    #[Test]
    #[DataProvider('levelMethods')]
    public function aThrowingInnerLoggerDoesNotEscapeAnyLevelMethod(\Closure $call): void
    {
        $inner = new ThrowingLogger();

        $call(SafeLogger::wrap($inner));

        self::assertCount(1, $inner->calls(), 'the inner logger was reached, and its exception stayed inside');
    }

    #[Test]
    public function aThrowingInnerLoggerDoesNotEscapeLog(): void
    {
        $inner = new ThrowingLogger();

        SafeLogger::wrap($inner)->log(LogLevel::ERROR, 'message');

        self::assertCount(1, $inner->calls());
    }

    #[Test]
    public function wrapReturnsTheSameInstanceForAnAlreadyWrappedLogger(): void
    {
        $wrapped = SafeLogger::wrap(new MemoryLogger());

        self::assertSame($wrapped, SafeLogger::wrap($wrapped));
    }

    #[Test]
    public function wrapReturnsADifferentLoggerForAPlainOne(): void
    {
        $inner = new MemoryLogger();

        self::assertNotSame($inner, SafeLogger::wrap($inner));
    }

    #[Test]
    public function passesLevelMessageAndContextToTheInnerLoggerUnchanged(): void
    {
        $inner = new MemoryLogger();
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'from stringable {x}';
            }
        };

        $logger = SafeLogger::wrap($inner);
        $logger->warning('request {id} failed', ['id' => 7, 'nested' => ['a' => null]]);
        $logger->info($stringable);

        self::assertSame(
            [
                ['level' => LogLevel::WARNING, 'message' => 'request {id} failed', 'context' => ['id' => 7, 'nested' => ['a' => null]]],
                ['level' => LogLevel::INFO, 'message' => 'from stringable {x}', 'context' => []],
            ],
            $inner->records(),
        );
    }
}
