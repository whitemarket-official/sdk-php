<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;

/**
 * A readable stream that remembers whether it was closed, to check that a reader releases the response body.
 */
final class TrackedStream extends Stream
{
    private bool $wasClosed = false;

    private int $bytesRead = 0;

    public static function of(string $content): self
    {
        $resource = Utils::tryFopen('php://temp', 'r+');
        fwrite($resource, $content);
        rewind($resource);

        return new self($resource);
    }

    public function wasClosed(): bool
    {
        return $this->wasClosed;
    }

    /**
     * How many bytes were read from the stream so far.
     */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function read($length): string
    {
        $chunk = parent::read($length);
        $this->bytesRead += \strlen($chunk);

        return $chunk;
    }

    public function close(): void
    {
        $this->wasClosed = true;
        parent::close();
    }
}
