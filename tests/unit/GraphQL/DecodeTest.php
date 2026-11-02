<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\GraphQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\GraphQL\Decode;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\GraphQL\UnknownTypeValue;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

#[CoversClass(Decode::class)]
#[CoversClass(DecodeException::class)]
#[CoversClass(UnknownEnumValue::class)]
#[CoversClass(UnknownTypeValue::class)]
final class DecodeTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(mixed, string): mixed, mixed, mixed}>
     */
    public static function validScalars(): iterable
    {
        yield 'string' => [Decode::string(...), 'abc', 'abc'];
        yield 'empty string' => [Decode::string(...), '', ''];
        yield 'money stays a decimal string' => [Decode::string(...), '0.07000000000000000001', '0.07000000000000000001'];
        yield 'int' => [Decode::int(...), 42, 42];
        yield 'int zero' => [Decode::int(...), 0, 0];
        yield 'float' => [Decode::float(...), 0.25, 0.25];
        yield 'float from a JSON integer' => [Decode::float(...), 3, 3.0];
        yield 'bool true' => [Decode::bool(...), true, true];
        yield 'bool false' => [Decode::bool(...), false, false];
    }

    /**
     * @param \Closure(mixed, string): mixed $decoder
     */
    #[Test]
    #[DataProvider('validScalars')]
    public function decodesScalars(\Closure $decoder, mixed $input, mixed $expected): void
    {
        self::assertSame($expected, $decoder($input, 'x'));
    }

    /**
     * @return iterable<string, array{\Closure(mixed, string): mixed, mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'string from int' => [Decode::string(...), 1];
        yield 'string from null' => [Decode::string(...), null];
        yield 'secret from int' => [Decode::secret(...), 1];
        yield 'secret from an empty string' => [Decode::secret(...), ''];
        yield 'int from numeric string' => [Decode::int(...), '1'];
        yield 'int from float' => [Decode::int(...), 1.0];
        yield 'float from numeric string' => [Decode::float(...), '0.1'];
        yield 'bool from int' => [Decode::bool(...), 1];
        yield 'object from list' => [Decode::object(...), [1, 2]];
        yield 'object from string' => [Decode::object(...), 'x'];
        yield 'list from object' => [Decode::listOf(Decode::int(...)), ['a' => 1]];
        yield 'list from null' => [Decode::listOf(Decode::int(...)), null];
        yield 'list item of wrong type' => [Decode::listOf(Decode::int(...)), [1, 'two']];
        yield 'non-null item is null' => [Decode::listOf(Decode::int(...)), [1, null]];
        yield 'enum from int' => [Decode::enumOf(OperationType::class), 1];
        yield 'abstract without __typename' => [Decode::abstractOf(['A' => Decode::object(...)]), ['id' => 'x']];
        yield 'date without offset' => [Decode::dateTime(...), '2023-11-29T23:33:27'];
        yield 'date only' => [Decode::dateTime(...), '2023-11-29'];
        yield 'date out of range' => [Decode::dateTime(...), '2023-13-45T25:61:61+00:00'];
        yield 'date as unix time' => [Decode::dateTime(...), 1701300807];
    }

    /**
     * @param \Closure(mixed, string): mixed $decoder
     */
    #[Test]
    #[DataProvider('invalidValues')]
    public function rejectsValuesOfTheWrongShape(\Closure $decoder, mixed $input): void
    {
        $this->expectException(DecodeException::class);

        $decoder($input, 'root');
    }

    /**
     * @return iterable<string, array{\Closure(mixed, string): mixed, mixed, string}>
     */
    public static function failureMessages(): iterable
    {
        yield 'object from a string' => [Decode::object(...), 'x', 'expected an object, got string'];
        yield 'object from a list' => [Decode::object(...), [1, 2], 'expected an object, got array'];
        yield 'string from an int' => [Decode::string(...), 1, 'expected a string, got int'];
        yield 'int from a numeric string' => [Decode::int(...), '1', 'expected an integer, got string'];
        yield 'float from null' => [Decode::float(...), null, 'expected a number, got null'];
        yield 'bool from an int' => [Decode::bool(...), 1, 'expected a boolean, got int'];
        yield 'list from an object' => [Decode::listOf(Decode::int(...)), ['a' => 1], 'expected a list, got array'];
        yield 'list from a string' => [Decode::listOf(Decode::int(...)), 'x', 'expected a list, got string'];
    }

    /**
     * @param \Closure(mixed, string): mixed $decoder
     */
    #[Test]
    #[DataProvider('failureMessages')]
    public function namesThePathAndTheActualTypeInTheMessage(\Closure $decoder, mixed $input, string $problem): void
    {
        try {
            $decoder($input, 'root.field');
            self::fail('DecodeException expected');
        } catch (DecodeException $e) {
            self::assertSame('root.field', $e->path);
            self::assertSame(\sprintf('Cannot decode the response at "root.field": %s', $problem), $e->getMessage());
        }
    }

    /**
     * A field the server filled with something else (a token in a date-time field) must not be copied into the
     * message, which ends up in logs.
     */
    #[Test]
    public function keepsAMalformedDateTimeOutOfTheMessage(): void
    {
        try {
            Decode::dateTime('Bearer synthetic-credential-0d5e', 'root');
            self::fail('DecodeException expected');
        } catch (DecodeException $e) {
            self::assertStringNotContainsString('synthetic-credential', $e->getMessage());
            self::assertStringContainsString('expected an ISO-8601 date-time with offset', $e->getMessage());
        }
    }

    #[Test]
    public function errorCarriesTheJsonPathOfTheBrokenValue(): void
    {
        $decode = Decode::listOf(static fn(mixed $item, string $path): string => Decode::field(
            Decode::object($item, $path),
            'price',
            $path,
            Decode::string(...),
        ));

        try {
            $decode([['price' => '1'], ['price' => 2]], 'market_list.edges');
            self::fail('DecodeException expected');
        } catch (DecodeException $e) {
            self::assertSame('market_list.edges[1].price', $e->path);
            self::assertStringContainsString('market_list.edges[1].price', $e->getMessage());
        }
    }

    #[Test]
    public function missingFieldIsAShapeErrorEvenForNullableFields(): void
    {
        try {
            Decode::field(['other' => 1], 'price', 'node', Decode::nullableOf(Decode::string(...)));
            self::fail('DecodeException expected');
        } catch (DecodeException $e) {
            self::assertSame('node.price', $e->path);
        }
    }

    #[Test]
    public function fieldAtTheRootHasNoLeadingDot(): void
    {
        try {
            Decode::field([], 'market_list', '', Decode::string(...));
            self::fail('DecodeException expected');
        } catch (DecodeException $e) {
            self::assertSame('market_list', $e->path);
        }
    }

    #[Test]
    public function nullableAcceptsNullAndDelegatesOtherwise(): void
    {
        $decode = Decode::nullableOf(Decode::int(...));

        self::assertNull($decode(null, 'x'));
        self::assertSame(5, $decode(5, 'x'));
    }

    #[Test]
    public function emptyJsonObjectDecodesAsObject(): void
    {
        self::assertSame([], Decode::object([], 'x'));
    }

    #[Test]
    public function listKeepsOrderAndAllowsNullItemsWhenNullable(): void
    {
        self::assertSame([3, null, 1], Decode::listOf(Decode::nullableOf(Decode::int(...)))([3, null, 1], 'x'));
        self::assertSame([], Decode::listOf(Decode::int(...))([], 'x'));
    }

    #[Test]
    public function knownEnumValueDecodesToTheCase(): void
    {
        self::assertSame(OperationType::Mutation, Decode::enumOf(OperationType::class)('mutation', 'x'));
    }

    #[Test]
    public function unknownEnumValueIsWrappedNotThrown(): void
    {
        $value = Decode::enumOf(OperationType::class)('subscription', 'x');

        self::assertInstanceOf(UnknownEnumValue::class, $value);
        self::assertSame(OperationType::class, $value->enum);
        self::assertSame('subscription', $value->value);
    }

    #[Test]
    public function abstractPicksTheVariantByTypename(): void
    {
        $decode = Decode::abstractOf([
            'A' => static fn(mixed $v, string $p): string => 'A:' . Decode::field(Decode::object($v, $p), 'id', $p, Decode::string(...)),
            'B' => static fn(mixed $v, string $p): string => 'B' . \count(Decode::object($v, $p)),
        ]);

        self::assertSame('A:1', $decode(['__typename' => 'A', 'id' => '1'], 'x'));
        self::assertSame('B1', $decode(['__typename' => 'B'], 'x'));
    }

    #[Test]
    public function unknownTypenameKeepsTheRawObject(): void
    {
        $raw = ['__typename' => 'NewKind', 'id' => '7'];

        $value = Decode::abstractOf(['A' => Decode::object(...)])($raw, 'x');

        self::assertInstanceOf(UnknownTypeValue::class, $value);
        self::assertSame('NewKind', $value->typename);
        self::assertSame($raw, $value->raw);
    }

    #[Test]
    public function interfaceWithoutImplementationsKeepsTheRawObject(): void
    {
        $value = Decode::unknownType(['__typename' => 'MarketOrderSteam', 'id' => '1'], 'x');

        self::assertSame('MarketOrderSteam', $value->typename);
        self::assertSame(['__typename' => 'MarketOrderSteam', 'id' => '1'], $value->raw);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dateTimes(): iterable
    {
        yield 'offset without colon (partner docs example)' => ['2023-11-29T23:33:27+0000', '2023-11-29T23:33:27+00:00'];
        yield 'offset with colon' => ['2023-11-29T23:33:27+03:00', '2023-11-29T23:33:27+03:00'];
        yield 'Z' => ['2023-11-29T23:33:27Z', '2023-11-29T23:33:27+00:00'];
        yield 'fraction' => ['2023-11-29T23:33:27.123456-05:00', '2023-11-29T23:33:27-05:00'];
    }

    #[Test]
    #[DataProvider('dateTimes')]
    public function decodesIsoDateTimesKeepingTheOffset(string $input, string $expected): void
    {
        self::assertSame($expected, Decode::dateTime($input, 'x')->format(\DATE_ATOM));
    }

    #[Test]
    public function decodesACredentialToASecret(): void
    {
        $secret = Decode::secret('synthetic-access-token', 'auth_token.accessToken');

        self::assertSame('synthetic-access-token', $secret->reveal());
        self::assertSame('***', (string) $secret);
    }
}
