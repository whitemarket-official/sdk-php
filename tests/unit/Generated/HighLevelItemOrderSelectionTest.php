<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Generated;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketEdit;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNew;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketItem;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketMy;

/**
 * The schema sync of 2026-11-02 added CSGOMarketOrderPublic, which grew every inventory item's `order` selection to
 * `order { ...CSGOMarketOrderPublicFields inventory { id } }`. The high-level layer does not read `order`, and the server
 * has failed `order` selections before (graphql/overrides/mutation/market_buy.graphql), so the operations it uses keep
 * `order { __typename }` through overrides in graphql/overrides/. A schema sync that widens them again fails here.
 */
#[CoversNothing]
final class HighLevelItemOrderSelectionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function highLevelDocuments(): iterable
    {
        yield 'market_list' => [MarketList::DOCUMENT];
        yield 'market_item' => [MarketItem::DOCUMENT];
        yield 'market_my' => [MarketMy::DOCUMENT];
        yield 'market_new' => [MarketNew::DOCUMENT];
        yield 'market_edit' => [MarketEdit::DOCUMENT];
    }

    #[Test]
    #[DataProvider('highLevelDocuments')]
    public function anItemOrderSelectsOnlyItsTypename(string $document): void
    {
        $blocks = $this->orderBlocks($document);

        self::assertNotSame([], $blocks, 'the item order selection is expected in the document');
        foreach ($blocks as $block) {
            self::assertSame('__typename', trim($block));
        }
    }

    /**
     * The body of every `order { ... }` selection.
     *
     * @return list<string>
     */
    private function orderBlocks(string $document): array
    {
        $blocks = [];
        $offset = 0;
        while (preg_match('/\border\s*\{/', $document, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + \strlen($match[0][0]);
            $depth = 1;
            $end = $start;
            while ($depth > 0 && $end < \strlen($document)) {
                $depth += match ($document[$end]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                ++$end;
            }
            $blocks[] = substr($document, $start, $end - $start - 1);
            $offset = $start;
        }

        return $blocks;
    }
}
