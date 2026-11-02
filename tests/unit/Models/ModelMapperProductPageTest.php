<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketCsgoStickersSuggestion\Result as SuggestionsResult;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\PageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\Result;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\Sticker;
use WhiteMarket\Sdk\Models\StickerFilm;
use WhiteMarket\Sdk\Models\StickerSuggestion;
use WhiteMarket\Sdk\Models\StickerSuggestions;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(Sticker::class)]
#[CoversClass(StickerSuggestion::class)]
#[CoversClass(StickerSuggestions::class)]
#[CoversClass(Product::class)]
final class ModelMapperProductPageTest extends TestCase
{
    private const PATH = 'market_list';

    /**
     * @param array<string, mixed> $overrides
     */
    private function page(array $overrides): Result
    {
        return Result::fromValue(
            $overrides + ['edges' => [], 'pageInfo' => SyntheticResult::of(PageInfo::class), 'totalCount' => null],
            self::PATH,
        );
    }

    /**
     * @return array{cursor: string, node: array<string, mixed>|null}
     */
    private function edge(?string $slug): array
    {
        return ['cursor' => 'c', 'node' => $slug === null ? null : SyntheticResult::of(EdgesNode::class, ['slug' => $slug])];
    }

    #[Test]
    public function mapsProductsCursorAndTotalCount(): void
    {
        $page = (new ModelMapper())->productPage($this->page([
            'edges' => [$this->edge('a'), $this->edge('b')],
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => 'cursor-b', 'hasNextPage' => true]),
            'totalCount' => 42,
        ]), self::PATH);

        self::assertSame(['a', 'b'], array_map(static fn(Product $p): string => $p->slug, $page->items));
        self::assertSame('cursor-b', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(42, $page->totalCount);
    }

    #[Test]
    public function aNullEdgeListIsAnEmptyPage(): void
    {
        $page = (new ModelMapper())->productPage($this->page(['edges' => null]), self::PATH);

        self::assertSame([], $page->items);
    }

    #[Test]
    public function skipsNullEdgesAndNullNodesKeepingTheOrder(): void
    {
        $page = (new ModelMapper())->productPage($this->page(['edges' => [$this->edge('a'), null, $this->edge(null), $this->edge('b')]]), self::PATH);

        self::assertSame(['a', 'b'], array_map(static fn(Product $p): string => $p->slug, $page->items));
        self::assertSame([0, 1], array_keys($page->items), 'items stay a list');
    }

    #[Test]
    public function aMissingPageInfoMeansNoNextPage(): void
    {
        $page = (new ModelMapper())->productPage($this->page(['edges' => [$this->edge('a')], 'pageInfo' => null]), self::PATH);

        self::assertNull($page->endCursor);
        self::assertFalse($page->hasNextPage);
        self::assertCount(1, $page);
    }

    #[Test]
    public function reportsTheEdgeIndexInTheDecodePathOfABadProduct(): void
    {
        $bad = $this->edge('b');
        $bad['node']['price'] = ['currency' => 'USD', 'value' => 'not-a-number'];
        $result = $this->page(['edges' => [$this->edge('a'), $bad]]);

        try {
            (new ModelMapper())->productPage($result, self::PATH);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('market_list.edges[1].node.price.value', $e->path);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{string, StickerFilm|string|null}>}> suggestions -> [name, film or unknown raw value]
     */
    public static function suggestionLists(): iterable
    {
        yield 'null list -> empty' => [['suggestions' => null], []];
        yield 'empty list -> empty' => [['suggestions' => []], []];
        yield 'null entry skipped' => [
            ['suggestions' => [null, self::suggestion('s1', 'HOLO'), null, self::suggestion('s2', null)]],
            [['s1', StickerFilm::Holo], ['s2', null]],
        ];
        yield 'unknown film kept raw' => [['suggestions' => [self::suggestion('s1', 'PLASMA')]], [['s1', 'PLASMA']]];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<array{string, StickerFilm|string|null}> $expected
     */
    #[Test]
    #[DataProvider('suggestionLists')]
    public function mapsStickerSuggestions(array $overrides, array $expected): void
    {
        $result = SuggestionsResult::fromValue($overrides + ['moreCount' => 3], 'market_csgo_stickers_suggestion');

        $mapped = (new ModelMapper())->stickerSuggestions($result);

        self::assertSame(3, $mapped->moreCount);
        self::assertCount(\count($expected), $mapped);
        self::assertSame(
            $expected,
            array_map(static fn(StickerSuggestion $s): array => [$s->name, $s->film instanceof UnknownEnumValue ? $s->film->value : $s->film], iterator_to_array($mapped, false)),
            'iterating gives the items',
        );
        self::assertSame(
            $expected,
            array_map(static fn(StickerSuggestion $s): array => [$s->name, $s->film instanceof UnknownEnumValue ? $s->film->value : $s->film], $mapped->items),
        );
    }

    #[Test]
    public function mapsAllSuggestionFields(): void
    {
        $result = SuggestionsResult::fromValue(['moreCount' => 0, 'suggestions' => [self::suggestion('s1', 'FOIL')]], 'p');

        $suggestion = (new ModelMapper())->stickerSuggestions($result)->items[0] ?? self::fail('no suggestion mapped');

        self::assertSame(['s1', 's1 title', 'https://example.test/s1.png', StickerFilm::Foil, 4], [$suggestion->name, $suggestion->title, $suggestion->icon, $suggestion->film, $suggestion->total]);
    }

    #[Test]
    public function anUnknownFilmIsAnUnknownEnumValueOfStickerFilm(): void
    {
        $result = SuggestionsResult::fromValue(['moreCount' => 0, 'suggestions' => [self::suggestion('s1', 'PLASMA')]], 'p');

        $film = ((new ModelMapper())->stickerSuggestions($result)->items[0] ?? self::fail('no suggestion mapped'))->film;

        self::assertInstanceOf(UnknownEnumValue::class, $film);
        self::assertSame([StickerFilm::class, 'PLASMA'], [$film->enum, $film->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function suggestion(string $name, ?string $film): array
    {
        return ['name' => $name, 'title' => $name . ' title', 'icon' => 'https://example.test/' . $name . '.png', 'film' => $film, 'total' => 4];
    }
}
