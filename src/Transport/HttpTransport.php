<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WhiteMarket\Sdk\Auth\HeaderValue;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\GraphQL\OperationType;

/**
 * Posts GraphQL request bodies over a PSR-18 client (SDK_SPEC 1.3, 1.4): sets the headers, repeats queries as
 * {@see RetryPolicy} allows (never mutations), maps a non-2xx status to an {@see HttpException} and stops reading a
 * response at `maxResponseBytes` after decompression. Redirects are not followed: a 3xx is an error like any other.
 *
 * The PSR-3 logger receives the operation name, type, attempt, status and duration only: never headers, URLs with
 * credentials or bodies, so nothing secret can reach a log (SDK_SPEC 1.3). Whatever the HTTP client throws becomes a
 * {@see TransportException} that is not chained, because the original holds the request with its `Authorization`
 * header.
 *
 * @internal used by {@see \WhiteMarket\Sdk\GraphQL\GraphQLExecutor}
 */
final readonly class HttpTransport
{
    /** `D`: without it `$` also matches before a final line feed, typical of a token read from a file. */
    private const HEADER_NAME = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    private RetryLoop $retryLoop;

    public function __construct(
        private HttpClients $clients,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private TransportOptions $options = new TransportOptions(),
        private RetryPolicy $retryPolicy = new RetryPolicy(),
        LoggerInterface $logger = new NullLogger(),
        Clock $clock = new SystemClock(),
        Sleeper $sleeper = new SystemSleeper(),
        RandomSource $random = new SystemRandomSource(),
    ) {
        $this->retryLoop = new RetryLoop($retryPolicy, $clock, $sleeper, $random, SafeLogger::wrap($logger));
    }

    /**
     * @param array<string, Secret> $headers authentication headers
     *
     * @return string the body of the 2xx response
     *
     * @throws HttpException the final response had a non-2xx status
     * @throws TransportException no response, or its body could not be read
     */
    public function post(
        string $operationName,
        OperationType $type,
        #[\SensitiveParameter]
        string $body,
        #[\SensitiveParameter]
        array $headers,
    ): string {
        $client = $this->clientFor($type);

        return $this->retryLoop->run(
            'API',
            ['operation' => $operationName, 'type' => $type->value],
            ['operation' => $operationName],
            // A new request per attempt: a client may leave the body stream of the previous one read to the end.
            fn(): RequestInterface => $this->request($body, $headers),
            static fn(#[\SensitiveParameter] RequestInterface $request): ClientResponse => RetryLoop::sendVia($client, $request),
            fn(int $attempt, ?int $status, ?int $retryAfter): bool => $this->retryPolicy->shouldRetry($type, $attempt, $status, $retryAfter),
            fn(ClientResponse $response): ?string => $response->statusCode() >= 200 && $response->statusCode() <= 299
                ? $this->successBody($response)
                : null,
            fn(ClientResponse $response, int $status, ?int $retryAfter): \Throwable => HttpException::fromStatus(
                $status,
                $this->errorExcerptBody($response),
                $retryAfter,
            ),
            fn(string $what, #[\SensitiveParameter] \Throwable $e): TransportException => $this->transportException($operationName, $what, $e),
        );
    }

    /**
     * @param array<string, Secret> $headers
     */
    private function request(#[\SensitiveParameter] string $body, #[\SensitiveParameter] array $headers): RequestInterface
    {
        $request = $this->requestFactory->createRequest('POST', $this->options->graphqlUrl())
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept-Encoding', 'gzip')
            ->withHeader('User-Agent', $this->options->userAgent())
            ->withBody($this->streamFactory->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $this->withCredentialHeader($request, $name, $value->reveal());
        }

        return $request;
    }

    private function clientFor(OperationType $type): ClientInterface
    {
        return $type === OperationType::Mutation ? $this->clients->mutation : $this->clients->query;
    }

    /**
     * The request is sensitive: it already carries the credential headers set before this one.
     */
    private function withCredentialHeader(#[\SensitiveParameter] RequestInterface $request, string $name, #[\SensitiveParameter] string $value): RequestInterface
    {
        // Checked here: an invalid value would make the PSR-7 implementation throw with the token as an argument.
        if (preg_match(self::HEADER_NAME, $name) !== 1) {
            throw new \InvalidArgumentException('A credential header name is not a valid HTTP token.');
        }
        if (!HeaderValue::isValid($value)) {
            throw new \InvalidArgumentException(\sprintf('The value of the %s header is not a valid HTTP header value.', $name));
        }
        try {
            return $request->withHeader($name, $value);
        } catch (\Throwable $e) {
            // PSR-7 specifies InvalidArgumentException; anything else thrown here holds the value just the same.
            throw $this->rejectedHeader($name, $e);
        }
    }

    /**
     * Not chained: a PSR-7 implementation stricter than the checks in {@see withCredentialHeader()} may name the value.
     */
    private function rejectedHeader(string $name, #[\SensitiveParameter] \Throwable $e): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('The PSR-7 implementation rejected the value of the %s header (%s).', $name, $e::class));
    }

    /**
     * The body of a 2xx response: over `maxResponseBytes` or not decodable, it fails.
     */
    private function successBody(ClientResponse $response): string
    {
        return BodyReader::readAll($response, $this->options->maxResponseBytes, 'maxResponseBytes');
    }

    /**
     * The start of an error body for the exception's excerpt: cut at the limit, and whatever decoded before a gzip
     * failure is kept.
     */
    private function errorExcerptBody(ClientResponse $response): string
    {
        return BodyReader::errorExcerpt($response, $this->options->maxResponseBytes);
    }

    /**
     * The client failure is sensitive: as a trace argument it would expose its own trace and request.
     */
    private function transportException(string $operationName, string $what, #[\SensitiveParameter] \Throwable $e): TransportException
    {
        return new TransportException(\sprintf('WhiteMarket API %s on %s (%s).', $what, $operationName, $e::class));
    }
}
