<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Generated;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The server fails `updatedAt` of a deal's product item (`MarketProductHistory.params`) with "Internal error occurred",
 * and `params.order { id }` with HTTP 500; a mutation that selects either is rolled back: `market_buy` answered the
 * error and created no deal (observed live 2026-11-02, twice). No operation document may select them there
 * (overrides in graphql/overrides/, DEVIATIONS). A schema sync that brings a new such selection fails here.
 */
#[CoversNothing]
final class ProductHistoryParamsSelectionTest extends TestCase
{
    /**
     * One case per `params` selection on the inventory item interface, keyed by operation and position.
     *
     * @return iterable<string, array{string}>
     */
    public static function itemParamsSelections(): iterable
    {
        $files = glob(__DIR__ . '/../../../src/Generated/Operation/{Query,Mutation}/*.php', \GLOB_BRACE);
        self::assertIsArray($files);
        foreach ($files as $file) {
            $class = 'WhiteMarket\Sdk\Generated\Operation\\' . basename(\dirname($file)) . '\\' . basename($file, '.php');
            if (\defined($class . '::DOCUMENT')) {
                $document = \constant($class . '::DOCUMENT');
                \assert(\is_string($document));

                foreach (self::itemParamsBlocks($document) as $i => $block) {
                    yield basename($file, '.php') . ' #' . $i => [$block];
                }
            }
        }
    }

    #[Test]
    public function theSelectionsAreFound(): void
    {
        // 13 operations select a deal's product item (2026-11-02); far fewer means the scan broke.
        self::assertGreaterThanOrEqual(13, iterator_count(self::itemParamsSelections()));
    }

    #[Test]
    #[DataProvider('itemParamsSelections')]
    public function aDealProductItemSelectsNeitherItsUpdateTimeNorItsOrder(string $block): void
    {
        self::assertDoesNotMatchRegularExpression('/\bupdatedAt\b/', $block);
        self::assertStringNotContainsString('...CSGOInventoryItemFields', $block, 'the fragment holds updatedAt');
        self::assertDoesNotMatchRegularExpression('/\border\s*\{/', $block);
    }

    /**
     * Every `params { ... }` selection on the inventory item interface (the one with `... on CSGOInventoryItem`).
     *
     * @return list<string>
     */
    private static function itemParamsBlocks(string $document): array
    {
        $blocks = [];
        $offset = 0;
        while (preg_match('/\bparams\s*\{/', $document, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
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
            $block = substr($document, $start, $end - $start);
            if (str_contains($block, 'on CSGOInventoryItem')) {
                $blocks[] = $block;
            }
            $offset = $start;
        }

        return $blocks;
    }
}
