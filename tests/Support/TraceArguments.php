<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Catches an exception with trace arguments switched on (`zend.exception_ignore_args=0`, the php.ini-development
 * value) and exports or prints the frames raised inside the SDK, so a test can assert that no credential reaches them.
 */
final class TraceArguments
{
    /**
     * @param-immediately-invoked-callable $act
     *
     * @param \Closure(): mixed $act
     */
    public static function caught(\Closure $act): \Throwable
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $act();
        } catch (\Throwable $e) {
            return $e;
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        Assert::fail('expected an exception');
    }

    /**
     * The frames from the first SDK frame up to the first one that belongs to the test side: a test class
     * (`Tests\Unit`) or a class outside the SDK (PHPUnit, `ReflectionClass::newInstanceArgs`). The frames below hold
     * the credential by construction; the ones above the first SDK frame are user code the SDK called (a throwing
     * callback). Frames of internal functions called by the SDK (`array_map`) have no class and are kept: their
     * arguments leak too. Test doubles in `Tests\Support` stand in for SDK collaborators and are kept.
     */
    public static function exportedSdkFrames(\Throwable $e): string
    {
        return var_export(self::sdkFrames($e), true);
    }

    /**
     * The same frames as {@see exportedSdkFrames()}, through `print_r`: it honours `__debugInfo`, but shows what a
     * closure argument captured (`$this` included) and the private state of every object reached from there.
     */
    public static function printedSdkFrames(\Throwable $e): string
    {
        return print_r(self::sdkFrames($e), true);
    }

    /**
     * @return non-empty-list<array<string, mixed>>
     */
    private static function sdkFrames(\Throwable $e): array
    {
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? null;
            $sdk = $class === null
                || (str_starts_with($class, 'WhiteMarket\\Sdk\\') && !str_starts_with($class, 'WhiteMarket\\Sdk\\Tests\\Unit\\'));
            if (!$sdk && $frames !== []) {
                break;
            }
            if ($sdk) {
                $frames[] = $frame;
            }
        }
        Assert::assertNotSame([], $frames, 'no SDK frame in the trace');

        return $frames;
    }
}
