<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Generated;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\GraphQL\UnknownTypeValue;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Enum\Currency;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Enum\Status;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Fragment\MoneyFields;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\GeneratedClient;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Input\MoneyInput;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Input\SearchInput;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Operation\Query\Product\ItemSticker as ProductItemSticker;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Operation\Query\Product\ListType as ProductList;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Operation\Query\Products\EdgesNodeItemSkin;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Operation\Query\Products\EdgesNodeItemSticker;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Operation\Query\Products\EdgesNodeParamsFlag;
use WhiteMarket\Sdk\Tests\Fixtures\Codegen\Golden\Operation\Query\Products\EdgesNodeParamsNote;
use WhiteMarket\Sdk\Tests\Support\FakeOperationExecutor;

/**
 * Executes the generator's golden output (tests/fixtures/codegen/golden, one example of every construct) against
 * synthetic responses. It runs on every supported PHP version; the generator itself is tested in tests/codegen on the
 * tools PHP version only.
 *
 * The golden classes are fixtures, not SDK source, so this test covers nothing by attribute.
 */
#[CoversNothing]
final class GoldenOutputTest extends TestCase
{
    #[Test]
    public function decodesAConnectionWithFragmentsInterfacesUnionsAndAliases(): void
    {
        $executor = new FakeOperationExecutor(['products' => [
            'edges' => [
                ['cursor' => 'c1', 'node' => $this->product([
                    'item' => ['__typename' => 'Skin', 'id' => 'i1', 'wear' => '0.07000000000000000001', 'fade' => 98.5],
                    'params' => [['__typename' => 'Flag', 'enabled' => true], ['__typename' => 'Note', 'note' => 'n']],
                    'discount' => ['currency' => 'EUR', 'value' => '1.50'],
                    'tags' => ['a', null],
                    'list' => [['currency' => 'USD', 'value' => '2']],
                ])],
                ['cursor' => 'c2', 'node' => $this->product([
                    'status' => 'SOMETHING_NEW',
                    'item' => ['__typename' => 'Sticker', 'id' => 'i2', 'slot' => 3],
                    'params' => [['__typename' => 'Badge', 'level' => 1]],
                    'hidden' => ['__typename' => 'HiddenImpl', 'id' => 'h'],
                ])],
            ],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'totalCount' => 2,
        ]]);

        $result = (new GeneratedClient($executor))->products();

        self::assertSame(2, $result->totalCount);
        self::assertFalse($result->pageInfo->hasNextPage);
        $first = $result->edges[0] ?? null;
        $second = $result->edges[1] ?? null;
        self::assertNotNull($first);
        self::assertNotNull($second);

        $node = $first->node;
        self::assertSame(Status::DONE, $node->status);
        self::assertSame('2023-11-29T23:33:27+00:00', $node->createdAt->format(\DATE_ATOM));
        self::assertInstanceOf(MoneyFields::class, $node->price, 'a selection that is exactly the fragment reuses its class');
        self::assertSame(Currency::USD, $node->price->currency);
        self::assertSame('10.00', $node->price->value);
        self::assertNotInstanceOf(MoneyFields::class, $node->discount, 'fragment plus extra fields gets its own class');
        self::assertSame('1.50', $node->discount?->value);
        self::assertInstanceOf(EdgesNodeItemSkin::class, $node->item);
        self::assertSame('0.07000000000000000001', $node->item->wear);
        self::assertSame(98.5, $node->item->fade);
        self::assertSame('i1', $node->item->id, 'fields selected on the interface reach every implementation');
        $flag = $node->params[0] ?? null;
        $note = $node->params[1] ?? null;
        self::assertInstanceOf(EdgesNodeParamsFlag::class, $flag);
        self::assertTrue($flag->enabled);
        self::assertInstanceOf(EdgesNodeParamsNote::class, $note);
        self::assertSame('n', $note->note, 'aliases become property names');
        self::assertSame(['a', null], $node->tags);
        self::assertInstanceOf(MoneyFields::class, $node->list[0] ?? null);
        self::assertNull($node->hidden);

        $node = $second->node;
        self::assertInstanceOf(UnknownEnumValue::class, $node->status);
        self::assertSame([Status::class, 'SOMETHING_NEW'], [$node->status->enum, $node->status->value]);
        self::assertInstanceOf(EdgesNodeItemSticker::class, $node->item);
        self::assertSame(3, $node->item->slot);
        $badge = $node->params[0] ?? null;
        self::assertInstanceOf(UnknownTypeValue::class, $badge);
        self::assertSame(['Badge', ['__typename' => 'Badge', 'level' => 1]], [$badge->typename, $badge->raw]);
        self::assertInstanceOf(UnknownTypeValue::class, $node->hidden);
        self::assertSame(['HiddenImpl', ['__typename' => 'HiddenImpl', 'id' => 'h']], [$node->hidden->typename, $node->hidden->raw]);
    }

    #[Test]
    public function sendsTheDocumentWithItsFragmentsAndOmitsUnsetVariables(): void
    {
        $executor = new FakeOperationExecutor(['products' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'totalCount' => 0]]);

        (new GeneratedClient($executor))->products(search: new SearchInput(
            name: 'AK-47',
            statuses: [Status::NEW, Status::DONE],
            price: new MoneyInput(Currency::USD, '0.10'),
            from: new \DateTimeImmutable('2024-01-02T03:04:05+02:00'),
        ));

        $call = $executor->lastCall();
        self::assertSame('Products', $call['operationName']);
        self::assertSame(OperationType::Query, $call['type']);
        self::assertStringStartsWith('query Products(', $call['document']);
        self::assertStringEndsWith("fragment MoneyFields on Money {\n  currency\n  value\n}", $call['document']);
        self::assertSame(
            ['search' => [
                'name' => 'AK-47',
                'statuses' => ['NEW', 'DONE'],
                'price' => ['currency' => 'USD', 'value' => '0.10'],
                'from' => '2024-01-02T03:04:05+02:00',
            ]],
            $call['variables'],
        );
    }

    #[Test]
    public function mutationReturningAScalarDecodesIt(): void
    {
        $executor = new FakeOperationExecutor(['product_remove' => true]);

        self::assertTrue((new GeneratedClient($executor))->productRemove(['a', 'b']));
        self::assertSame(OperationType::Mutation, $executor->lastCall()['type']);
        self::assertSame(['ids' => ['a', 'b']], $executor->lastCall()['variables']);
    }

    #[Test]
    public function inlineFragmentOnAnInterfaceAppliesToEveryImplementation(): void
    {
        $executor = new FakeOperationExecutor(['product' => [
            'id' => 'p',
            'item' => ['__typename' => 'Sticker', 'id' => 'i9'],
            'list' => [['value' => '1']],
            'value' => ['value' => '5'],
        ]]);

        $product = (new GeneratedClient($executor))->product('p');

        self::assertInstanceOf(ProductItemSticker::class, $product?->item);
        self::assertSame('i9', $product->item->id);
        self::assertInstanceOf(ProductList::class, $product->list[0] ?? null, 'a reserved word as class name gets a suffix');
        self::assertSame('5', $product->value?->value, 'a class named like a runtime class does not clash with it');
        self::assertSame(['id' => 'p'], $executor->lastCall()['variables']);
    }

    #[Test]
    public function nullableRootFieldDecodesNull(): void
    {
        self::assertNull((new GeneratedClient(new FakeOperationExecutor(['product' => null])))->product('x'));
    }

    #[Test]
    public function missingRequiredFieldFailsWithItsPath(): void
    {
        $node = $this->product([]);
        unset($node['price']);
        $executor = new FakeOperationExecutor(['products' => [
            'edges' => [['cursor' => 'c', 'node' => $node]],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'totalCount' => 1,
        ]]);

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('products.edges[0].node.price');

        (new GeneratedClient($executor))->products();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function product(array $overrides): array
    {
        return [...[
            'id' => 'p',
            'status' => 'DONE',
            'createdAt' => '2023-11-29T23:33:27+0000',
            'price' => ['currency' => 'USD', 'value' => '10.00'],
            'discount' => null,
            'item' => null,
            'params' => [],
            'hidden' => null,
            'tags' => null,
            'list' => null,
        ], ...$overrides];
    }
}
