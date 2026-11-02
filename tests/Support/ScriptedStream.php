<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A body stream that plays a script: each `read()` takes the next step, a string it returns or a throwable it throws,
 * like a socket stream whose read slice expired (a throw with `timed_out` metadata). After the script ends reads return
 * '' . `eof()` and `getMetadata()` answer what the constructor says; `$eof` null means "the script is used up".
 */
final class ScriptedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private bool $wasClosed = false;

    private int $readCalls = 0;

    /**
     * @param list<string|\Throwable> $reads
     * @param array<string, mixed>|\Throwable $metadata
     */
    public function __construct(
        private array $reads,
        private readonly array|\Throwable $metadata = [],
        private readonly bool|\Throwable|null $eof = null,
    ) {
        $this->stream = Utils::streamFor('');
    }

    /**
     * A read that expired its slice: the throw of psr7 with the socket flags set.
     *
     * @param list<string|\Throwable> $reads
     */
    public static function expiringSlices(array $reads, bool $eof = false): self
    {
        return new self($reads, ['timed_out' => true], $eof);
    }

    public static function sliceExpired(): \RuntimeException
    {
        return new \RuntimeException('Unable to read from stream');
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function wasClosed(): bool
    {
        return $this->wasClosed;
    }

    public function readCalls(): int
    {
        return $this->readCalls;
    }

    public function read(int $length): string
    {
        ++$this->readCalls;
        $step = array_shift($this->reads) ?? '';
        if ($step instanceof \Throwable) {
            throw $step;
        }

        return $step;
    }

    public function eof(): bool
    {
        if ($this->eof instanceof \Throwable) {
            throw $this->eof;
        }

        return $this->eof ?? $this->reads === [];
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($this->metadata instanceof \Throwable) {
            throw $this->metadata;
        }

        return $key === null ? $this->metadata : ($this->metadata[$key] ?? null);
    }

    public function close(): void
    {
        $this->wasClosed = true;
    }
}
