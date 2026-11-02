<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Memory;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\Event\ProductEdited;
use WhiteMarket\Sdk\Live\Event\ProductRemoved;
use WhiteMarket\Sdk\Live\LiveHandlers;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveSession;
use WhiteMarket\Sdk\Tests\Support\FixedRandomSource;
use WhiteMarket\Sdk\Tests\Support\LiveFixture;
use WhiteMarket\Sdk\Tests\Support\ManualClock;
use WhiteMarket\Sdk\Tests\Support\PeakMemory;
use WhiteMarket\Sdk\Tests\Support\ScriptedConnection;
use WhiteMarket\Sdk\Tests\Support\ScriptedTokens;

/**
 * SDK_SPEC 3.11 / plan L6: a live session that receives 100k product events keeps nothing of them: memory does not grow
 * with the number of messages. The messages are produced lazily, a batch at a time, by the event handler itself, so the
 * script is not in memory either. Run with `make test-memory` (the `memory` suite, not part of `make check`).
 */
final class LiveSessionMemoryTest extends TestCase
{
    private const MESSAGES = 100_000;

    private const BATCH = 1_000;

    private const MAX_GROWTH_BYTES = 8 * 1024 * 1024;

    #[Test]
    public function oneHundredThousandProductEventsAreHandledInConstantMemory(): void
    {
        $clock = new ManualClock('2026-01-01T00:00:00+00:00');
        $connection = new ScriptedConnection($clock, [new ConnectInfo(25, true, 86_400, [])], $this->batch(0));
        $session = new LiveSession(
            new ScriptedTokens(),
            static fn(): ScriptedConnection => $connection,
            new LiveOptions(),
            new NullLogger(),
            $clock,
            $clock,
            $handlers = new LiveHandlers(),
            new FixedRandomSource(1.0),
        );

        $counts = ['added' => 0, 'edited' => 0, 'removed' => 0, 'other' => 0];
        $total = 0;
        $handlers->onEvent(function (LiveEvent $event) use (&$counts, &$total, $connection, $session): void {
            $key = match (true) {
                $event instanceof ProductAdded => 'added',
                $event instanceof ProductEdited => 'edited',
                $event instanceof ProductRemoved => 'removed',
                default => 'other',
            };
            ++$counts[$key];
            ++$total;
            if ($total === self::MESSAGES) {
                $session->close();
            } elseif ($total % self::BATCH === 0) {
                $connection->queueReads($this->batch($total));
            }
        });

        $growth = PeakMemory::growth(static function () use ($session): void {
            $session->run();
        });

        self::assertSame(self::MESSAGES, $total);
        self::assertSame(0, $counts['other']);
        self::assertSame(self::MESSAGES, $counts['added'] + $counts['edited'] + $counts['removed']);
        self::assertGreaterThan(0, $counts['added']);
        self::assertGreaterThan(0, $counts['edited']);
        self::assertGreaterThan(0, $counts['removed']);
        self::assertLessThan(self::MAX_GROWTH_BYTES, $growth, \sprintf('peak memory grew by %.1f MiB', $growth / 1048576));
        fwrite(\STDERR, \sprintf("\nlive session memory: peak growth %.2f MiB for %d messages\n", $growth / 1048576, self::MESSAGES));
    }

    /**
     * The messages `$from` .. `$from + BATCH - 1`: a mix of added, edited and removed events with distinct ids.
     *
     * @return list<string>
     */
    private function batch(int $from): array
    {
        $content = LiveFixture::message('product_added.json')['content'];
        $messages = [];
        for ($i = $from; $i < $from + self::BATCH; ++$i) {
            $content['id'] = \sprintf('00000000-0000-4000-8000-%012d', $i);
            $content['asset_id'] = (string) (30_000_000_000_000_000 + $i);
            $inner = json_encode(['type' => match ($i % 3) {
                0 => 'market_product_added',
                1 => 'market_product_edited',
                default => 'market_product_removed',
            }, 'content' => $content], \JSON_THROW_ON_ERROR);
            $messages[] = json_encode(['push' => ['channel' => 'market_products_updates', 'pub' => ['data' => ['message' => $inner]]]], \JSON_THROW_ON_ERROR);
        }

        return $messages;
    }
}
