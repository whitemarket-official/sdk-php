<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\GraphQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\GraphQL\RawDocument;

#[CoversClass(RawDocument::class)]
final class RawDocumentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, OperationType}>
     */
    public static function documents(): iterable
    {
        yield 'named query' => ['query Ping { test_ping }', OperationType::Query];
        yield 'anonymous query' => ['query { test_ping }', OperationType::Query];
        yield 'shorthand query' => ['{ test_ping }', OperationType::Query];
        yield 'leading comments, commas, BOM' => ["\xEF\xBB\xBF# comment\n ,\n# another\nquery { test_ping }", OperationType::Query];
        yield 'mutation' => ['mutation { auth_token { accessToken } }', OperationType::Mutation];
        yield 'query followed by a mutation' => ['query A { test_ping } mutation B { auth_token { accessToken } }', OperationType::Mutation];
        yield 'mutation only in a comment' => ["# not a mutation\nquery { test_ping }", OperationType::Mutation];
        yield 'fragment first' => ['fragment F on Money { value } query { wallet_balance { ...F } }', OperationType::Mutation];
        yield 'subscription' => ['subscription { x }', OperationType::Mutation];
        yield 'empty' => ['', OperationType::Mutation];
        yield 'garbage' => ['SELECT 1', OperationType::Mutation];
        yield 'keyword prefix is not a keyword' => ['queryX { test_ping }', OperationType::Mutation];
    }

    #[Test]
    #[DataProvider('documents')]
    public function onlyAClearQueryIsAQuery(string $document, OperationType $expected): void
    {
        self::assertSame($expected, RawDocument::operationType($document));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'named query' => ["# c\nquery Ping { test_ping }", 'Ping'];
        yield 'named mutation with variables' => ['mutation Buy($id: Uuid) { market_buy(id: $id) { id } }', 'Buy'];
        yield 'anonymous' => ['query { test_ping }', ''];
        yield 'shorthand' => ['{ test_ping }', ''];
    }

    #[Test]
    #[DataProvider('names')]
    public function readsTheFirstOperationName(string $document, string $expected): void
    {
        self::assertSame($expected, RawDocument::operationName($document));
    }
}
