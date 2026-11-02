<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Http\Client\ClientInterface;

/**
 * The PSR-18 clients {@see HttpTransport} sends with: one for queries and one for mutations. There is no default for
 * the mutation client, so a caller cannot get the query client for mutations by leaving an argument out: the default
 * Guzzle query client may send a request twice, which a mutation must never be (CLAUDE.md rule 2,
 * {@see GuzzleClientFactory::createForMutations()}). {@see from()} is where the client makes the choice between the
 * SDK's clients and the user's, so the tests of that choice exercise the code the client runs.
 *
 * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
 */
final readonly class HttpClients
{
    public function __construct(
        public ClientInterface $query,
        public ClientInterface $mutation,
    ) {}

    /**
     * The SDK's default clients ({@see GuzzleClientFactory}) with the timeouts of `$options`, or `$injected` as is for
     * both: whether a client the user configured may resend a request is the user's choice.
     */
    public static function from(?ClientInterface $injected, TransportOptions $options): self
    {
        if ($injected instanceof ClientInterface) {
            return new self($injected, $injected);
        }

        return new self(GuzzleClientFactory::create($options), GuzzleClientFactory::createForMutations($options));
    }
}
