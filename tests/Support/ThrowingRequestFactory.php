<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-17 request factory that refuses a URL like a strict PSR-7 implementation does: with a message that quotes what it
 * was given. The named constructors choose the methods it refuses; the others are built by Guzzle. Every call is
 * recorded.
 */
final class ThrowingRequestFactory implements RequestFactoryInterface
{
    /** @var list<array{string, string}> */
    private array $calls = [];

    private readonly string $encodedMessage;

    /**
     * @param list<string>|null $refusing HTTP methods refused; null: every method
     */
    private function __construct(string $message, private readonly ?array $refusing)
    {
        // Held encoded: a trace dump of an SDK frame that received this double must not show the message it throws.
        $this->encodedMessage = base64_encode($message);
    }

    public static function refusingEvery(string $message): self
    {
        return new self($message, null);
    }

    public static function refusing(string $message, string ...$methods): self
    {
        return new self($message, array_values($methods));
    }

    /** Refuses nothing; only records the calls. */
    public static function recordingOnly(): self
    {
        return new self('unused', []);
    }

    /**
     * @return list<array{string, string}> method and URL of every call
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param UriInterface|string $uri
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        $this->calls[] = [$method, (string) $uri];
        if ($this->refusing === null || \in_array($method, $this->refusing, true)) {
            throw new \RuntimeException((string) base64_decode($this->encodedMessage, true));
        }

        return (new HttpFactory())->createRequest($method, $uri);
    }
}
