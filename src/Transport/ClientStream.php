<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Http\Message\StreamInterface;
use WhiteMarket\Sdk\Errors\TransportException;

/**
 * The body stream of a {@see ClientResponse}: client code, so any Throwable becomes a {@see TransportException}
 * (see there).
 *
 * @internal
 */
final readonly class ClientStream
{
    public function __construct(private StreamInterface $stream) {}

    /**
     * @throws TransportException
     */
    public function read(int $length): string
    {
        try {
            return $this->stream->read($length);
        } catch (\Throwable $e) {
            throw ClientResponse::failure($e);
        }
    }

    /**
     * One read of a stream whose client was given a read timeout (the live SSE client): '' when the slice expired
     * without data, the bytes otherwise. psr7 reports an expired slice as a failed read (`Stream::read()` throws when
     * `fread()` returns false) with `timed_out` set in the socket metadata and no end of stream; the stream keeps
     * working. Only that combination is "slice expired". The flag is consulted after a failed read only: on chunked
     * bodies it stays true after successful reads. Any other failure, also one of the metadata or `eof()` calls, is a
     * {@see TransportException} as in {@see read()}.
     *
     * @throws TransportException
     */
    public function readSlice(int $length): string
    {
        try {
            return $this->stream->read($length);
        } catch (\Throwable $e) {
            if (!$this->sliceExpired()) {
                throw ClientResponse::failure($e);
            }

            return '';
        }
    }

    /**
     * Asked after a failed read only; a failure of the question itself is "not expired".
     */
    private function sliceExpired(): bool
    {
        try {
            return $this->stream->getMetadata('timed_out') === true && !$this->stream->eof();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws TransportException
     */
    public function eof(): bool
    {
        try {
            return $this->stream->eof();
        } catch (\Throwable $e) {
            throw ClientResponse::failure($e);
        }
    }

    /**
     * @throws TransportException
     */
    public function close(): void
    {
        try {
            $this->stream->close();
        } catch (\Throwable $e) {
            throw ClientResponse::failure($e);
        }
    }
}
