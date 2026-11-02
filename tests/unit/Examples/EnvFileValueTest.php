<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Examples;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function WhiteMarket\Sdk\Examples\envFileValue;

#[CoversFunction('WhiteMarket\Sdk\Examples\envFileValue')]
final class EnvFileValueTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wm-env-');
        self::assertIsString($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function files(): iterable
    {
        yield 'plain value' => ["KEY=abc\n", 'abc'];
        yield 'spaces around name and value' => ["  KEY = abc  \n", 'abc'];
        yield 'double quotes' => ["KEY=\"a b\"\n", 'a b'];
        yield 'single quotes' => ["KEY='a b'\n", 'a b'];
        yield 'mismatched quotes kept' => ["KEY=\"abc'\n", "\"abc'"];
        yield 'single quote char kept' => ["KEY=\"\n", '"'];
        yield 'equals sign inside the value' => ["KEY=a=b\n", 'a=b'];
        yield 'empty value' => ["KEY=\n", ''];
        yield 'comment skipped' => ["#KEY=commented\nKEY=real\n", 'real'];
        yield 'other keys and prefix match ignored' => ["OTHER=1\nKEY_SUFFIX=2\nKEY=3\n", '3'];
        yield 'first occurrence wins' => ["KEY=1\nKEY=2\n", '1'];
        yield 'missing key' => ["OTHER=1\n", null];
        yield 'line without equals sign' => ["KEY\n", null];
    }

    #[Test]
    #[DataProvider('files')]
    public function readsOneKey(string $content, ?string $expected): void
    {
        file_put_contents($this->file, $content);

        self::assertSame($expected, envFileValue($this->file, 'KEY'));
    }

    #[Test]
    public function missingFileIsNotAnError(): void
    {
        unlink($this->file);

        self::assertNull(envFileValue($this->file, 'KEY'));
    }
}
