<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\PageRequest;

#[CoversClass(PageRequest::class)]
final class PageRequestTest extends TestCase
{
    /**
     * @return iterable<string, array{PageRequest, ?array<string, mixed>}> page request, expected wire input
     */
    public static function requests(): iterable
    {
        yield 'empty request -> no input' => [new PageRequest(), null];
        yield 'first only' => [new PageRequest(first: 50), ['first' => 50]];
        yield 'after only' => [new PageRequest(after: 'cursor-1'), ['after' => 'cursor-1']];
        yield 'first and after' => [new PageRequest(50, 'cursor-1'), ['after' => 'cursor-1', 'first' => 50]];
    }

    /**
     * @param ?array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('requests')]
    public function toInputSendsOnlyWhatIsGiven(PageRequest $request, ?array $expected): void
    {
        self::assertSame($expected, $request->toInput()?->toArray());
    }
}
