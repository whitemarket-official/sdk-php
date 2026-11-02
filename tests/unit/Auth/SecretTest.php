<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\Secret;

#[CoversClass(Secret::class)]
final class SecretTest extends TestCase
{
    private const VALUE = 'synthetic-partner-token-0123456789';

    #[Test]
    public function revealsTheValueOnRequest(): void
    {
        self::assertSame(self::VALUE, (new Secret(self::VALUE))->reveal());
    }

    #[Test]
    public function rejectsAnEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Secret('');
    }

    /**
     * Every way PHP turns an object into text: none of them may contain the value.
     *
     * @return iterable<string, array{\Closure(Secret): string}>
     */
    public static function renderings(): iterable
    {
        yield 'string cast' => [static fn(Secret $s): string => (string) $s];
        yield 'string interpolation' => [static fn(Secret $s): string => "token={$s}"];
        yield 'json_encode' => [static fn(Secret $s): string => (string) json_encode(['token' => $s])];
        yield 'var_dump' => [static fn(Secret $s): string => self::captured(static function () use ($s): void {
            var_dump($s);
        })];
        yield 'print_r' => [static fn(Secret $s): string => print_r($s, true)];
        yield 'var_export' => [static fn(Secret $s): string => var_export($s, true)];
        yield 'mangled object vars (what an array cast returns)' => [static fn(Secret $s): string => var_export(get_mangled_object_vars($s), true)];
        yield 'print_r of the mangled object vars (what an array cast returns)' => [static fn(Secret $s): string => print_r(get_mangled_object_vars($s), true)];
        yield 'var_dump of the mangled object vars' => [static fn(Secret $s): string => self::captured(static function () use ($s): void {
            var_dump(get_mangled_object_vars($s));
        })];
        // What dumpers such as Symfony VarDumper read through reflection: closure variables and generator frames.
        yield 'reflection of every property' => [static fn(Secret $s): string => var_export(self::reflected($s), true)];
        yield 'get_object_vars from outside' => [static fn(Secret $s): string => var_export(get_object_vars($s), true)];
        yield 'debug_zval_dump' => [static fn(Secret $s): string => self::captured(static function () use ($s): void {
            debug_zval_dump($s);
        })];
        // The trace is captured when the exception is created and lists the arguments of every frame, this one included.
        yield 'exception trace of a function that received it' => [static function (Secret $s): string {
            $fail = static fn(Secret $secret): \LogicException => new \LogicException(\sprintf('failed with a %s argument', $secret::class));

            return (string) $fail($s);
        }];
    }

    /**
     * @param \Closure(Secret): string $render
     */
    #[Test]
    #[DataProvider('renderings')]
    public function neverPrintsTheValue(\Closure $render): void
    {
        $output = $render(new Secret(self::VALUE));

        self::assertStringNotContainsString(self::VALUE, $output);
        self::assertStringNotContainsString('0123456789', $output, 'no part of the value either');
    }

    #[Test]
    public function printsAMaskAsAString(): void
    {
        $secret = new Secret(self::VALUE);

        self::assertSame('***', (string) $secret);
        self::assertSame('"***"', json_encode($secret));
        self::assertStringContainsString('[value] => ***', print_r($secret, true), 'debug output shows the mask, not an empty object');
    }

    #[Test]
    public function refusesToBeSerialized(): void
    {
        $this->expectException(\LogicException::class);

        serialize(new Secret(self::VALUE));
    }

    #[Test]
    public function refusesToBeUnserialized(): void
    {
        $this->expectException(\LogicException::class);

        unserialize(\sprintf('O:%d:"%s":1:{s:5:"value";s:1:"x";}', \strlen(Secret::class), Secret::class));
    }

    /**
     * @return list<mixed>
     */
    private static function reflected(Secret $secret): array
    {
        $found = [];
        foreach (get_mangled_object_vars($secret) as $property) {
            if ($property instanceof \Closure) {
                $found[] = (new \ReflectionFunction($property))->getStaticVariables();
            }
            if ($property instanceof \Generator) {
                $found[] = (new \ReflectionGenerator($property))->getTrace();
            }
        }

        return $found;
    }

    /**
     * @param \Closure(): void $print
     */
    private static function captured(\Closure $print): string
    {
        ob_start();
        $print();

        return (string) ob_get_clean();
    }
}
