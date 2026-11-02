<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;

/**
 * A readable stream that hands out at most `$chunkBytes` per read, to put chunk boundaries at chosen positions of a
 * body. It remembers whether it was closed.
 */
final class ChunkedStream extends Stream
{
    private bool $wasClosed = false;

    /**
     * @param resource $resource
     */
    private function __construct(mixed $resource, private readonly int $chunkBytes)
    {
        parent::__construct($resource);
    }

    public static function of(string $content, int $chunkBytes): self
    {
        $resource = Utils::tryFopen('php://temp', 'r+');
        fwrite($resource, $content);
        rewind($resource);

        return new self($resource, $chunkBytes);
    }

    public function wasClosed(): bool
    {
        return $this->wasClosed;
    }

    public function read($length): string
    {
        return parent::read(min($length, $this->chunkBytes));
    }

    public function close(): void
    {
        $this->wasClosed = true;
        parent::close();
    }
}
