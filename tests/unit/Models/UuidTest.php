<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\Uuid;

#[CoversClass(Uuid::class)]
final class UuidTest extends TestCase
{
    private const V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /**
     * @return iterable<string, array{string, string}> 16 bytes as hex, expected UUID
     */
    public static function uuidBytes(): iterable
    {
        yield 'all ones: version and variant bits overwrite the top bits' => [str_repeat('ff', 16), 'ffffffff-ffff-4fff-bfff-ffffffffffff'];
        yield 'all zeros get the version and variant bits' => [str_repeat('00', 16), '00000000-0000-4000-8000-000000000000'];
        yield 'sequential bytes show every group boundary' => [bin2hex(implode('', array_map(chr(...), range(0, 15)))), '00010203-0405-4607-8809-0a0b0c0d0e0f'];
        yield 'distinct bytes in the version and variant positions are masked, not replaced' => ['112233445566778899aabbccddeeff00', '11223344-5566-4788-99aa-bbccddeeff00'];
    }

    #[Test]
    #[DataProvider('uuidBytes')]
    public function formatsSixteenBytesAsAUuidV4(string $hex, string $expected): void
    {
        $bytes = hex2bin($hex);
        self::assertIsString($bytes);
        self::assertSame($expected, Uuid::v4FromBytes($bytes));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongLengths(): iterable
    {
        yield 'empty' => [''];
        yield '15 bytes' => [str_repeat("\x01", 15)];
        yield '17 bytes' => [str_repeat("\x01", 17)];
    }

    #[Test]
    #[DataProvider('wrongLengths')]
    public function rejectsAnythingButSixteenBytes(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Uuid::v4FromBytes($bytes);
    }

    #[Test]
    public function generatesDistinctUuidV4Values(): void
    {
        $first = Uuid::v4();

        self::assertMatchesRegularExpression(self::V4_PATTERN, $first);
        self::assertNotSame($first, Uuid::v4());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'lower case' => ['0b9a1f10-6a3e-4c55-9d2b-7f1e2a3b4c5d', true];
        yield 'upper case' => ['0B9A1F10-6A3E-4C55-9D2B-7F1E2A3B4C5D', true];
        yield 'the version is not checked' => ['00000000-0000-0000-0000-000000000000', true];
        yield 'braces' => ['{0b9a1f10-6a3e-4c55-9d2b-7f1e2a3b4c5d}', false];
        yield 'no dashes' => ['0b9a1f106a3e4c559d2b7f1e2a3b4c5d', false];
        yield '35 characters' => ['0b9a1f10-6a3e-4c55-9d2b-7f1e2a3b4c5', false];
        yield 'trailing newline' => ["0b9a1f10-6a3e-4c55-9d2b-7f1e2a3b4c5d\n", false];
        yield 'non-hex digit' => ['0b9a1f10-6a3e-4c55-9d2b-7f1e2a3b4c5g', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('candidates')]
    public function recognisesTheUuidShape(string $value, bool $expected): void
    {
        self::assertSame($expected, Uuid::isValid($value));
    }
}
