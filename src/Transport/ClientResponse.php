<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Errors\TransportException;

/**
 * The one door to a response an injected PSR-18 client returned. Every method of that response is client code, so any
 * Throwable it throws (an `AuthenticationException` built from the client's own 401 included) becomes a
 * {@see TransportException} naming the class only: not chained, because the original may hold the request, and not an
 * outcome of the API, so nothing downstream can replay a mutation or call it rejected because of it.
 *
 * @internal
 */
final class ClientResponse
{
    private ?ClientStream $body = null;

    public function __construct(private readonly ResponseInterface $response) {}

    public function statusCode(): int
    {
        try {
            return $this->response->getStatusCode();
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
    }

    public function headerLine(string $name): string
    {
        try {
            return $this->response->getHeaderLine($name);
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
    }

    public function hasHeader(string $name): bool
    {
        try {
            return $this->response->hasHeader($name);
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
    }

    public function body(): ClientStream
    {
        if ($this->body instanceof ClientStream) {
            return $this->body;
        }
        try {
            return $this->body = new ClientStream($this->response->getBody());
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
    }

    /**
     * Closes the body of a response nobody reads.
     */
    public function discard(): void
    {
        $this->body()->close();
    }

    /**
     * @internal for {@see ClientStream}
     */
    public static function failure(#[\SensitiveParameter] \Throwable $e): TransportException
    {
        return new TransportException(\sprintf('The HTTP client returned a response that failed (%s).', $e::class));
    }
}
