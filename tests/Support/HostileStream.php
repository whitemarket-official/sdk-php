<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * The body stream of a {@see HostileResponse}.
 */
final class HostileStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private StreamInterface $stream, private readonly string $point, private readonly \Throwable $failure) {}

    public function read(int $length): string
    {
        return $this->point === 'read' ? throw $this->failure : $this->stream->read($length);
    }

    public function eof(): bool
    {
        return $this->point === 'eof' ? throw $this->failure : $this->stream->eof();
    }

    public function close(): void
    {
        $this->point === 'close' ? throw $this->failure : $this->stream->close();
    }
}
