<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-memory PSR-18 client, the outer boundary of the SDK in tests (no network): returns queued responses or throws
 * queued client exceptions, in order, and records every request with its body as sent. A queued closure builds the
 * exception from the request it received, as Guzzle does, so the exception holds the credential headers.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|\Throwable|\Closure(RequestInterface): ClientExceptionInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<string> */
    private array $bodies = [];

    /**
     * @param ResponseInterface|\Throwable|\Closure(RequestInterface): ClientExceptionInterface ...$queue a throwable that is
     *                                                                                                    not a {@see ClientExceptionInterface} stands for a non-default client or middleware failing outside PSR-18
     */
    public function __construct(ResponseInterface|\Throwable|\Closure ...$queue)
    {
        $this->queue = array_values($queue);
    }

    /**
     * A 200 response whose body is `$payload` encoded as JSON.
     *
     * @param array<mixed> $payload
     */
    public static function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * The `auth_token` response carrying `$jwt`.
     */
    public static function authToken(string $jwt): ResponseInterface
    {
        return self::json(['data' => ['auth_token' => ['accessToken' => $jwt, 'isNewPerson' => false]]]);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function status(int $status, string $body = '', array $headers = []): ResponseInterface
    {
        return new Response($status, $headers, $body);
    }

    public static function networkError(): ClientExceptionInterface
    {
        return self::networkErrorHoldingTheRequest()((new HttpFactory())->createRequest('POST', 'https://example.invalid/'));
    }

    /**
     * A network error that holds the request as sent, headers included.
     *
     * @return \Closure(RequestInterface): ClientExceptionInterface
     */
    public static function networkErrorHoldingTheRequest(): \Closure
    {
        return static fn(RequestInterface $request): ClientExceptionInterface => new ConnectException('cURL error 7: synthetic connection refused', $request);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();
        $next = array_shift($this->queue) ?? throw new \LogicException('No response queued for request #' . \count($this->requests));
        if ($next instanceof \Closure) {
            throw $next($request);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[\count($this->requests) - 1] ?? throw new \LogicException('No request was sent');
    }

    /**
     * The JSON body of the last request, decoded.
     *
     * @return array<mixed>
     */
    public function lastPayload(): array
    {
        $payload = json_decode($this->bodies[\count($this->bodies) - 1] ?? throw new \LogicException('No request was sent'), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($payload)) {
            throw new \LogicException('The request body is not a JSON object');
        }

        return $payload;
    }

    /**
     * The JSON body of every request, decoded, in order.
     *
     * @return list<array<mixed>>
     */
    public function payloads(): array
    {
        return array_map(static function (string $body): array {
            $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($payload)) {
                throw new \LogicException('The request body is not a JSON object');
            }

            return $payload;
        }, $this->bodies);
    }

    /**
     * Operation name and variables of every request, in order.
     *
     * @param bool $sortVariables sort the variables by key, so a comparison does not depend on the order they were built in
     *
     * @return list<array{string, array<string, mixed>}>
     */
    public function operations(bool $sortVariables = false): array
    {
        $operations = [];
        foreach ($this->payloads() as $payload) {
            $name = $payload['operationName'] ?? null;
            $variables = $payload['variables'] ?? [];
            if (!\is_string($name) || !\is_array($variables)) {
                throw new \LogicException('The request body has no operationName or its variables are not an object');
            }
            if ($sortVariables) {
                ksort($variables);
            }
            /** @var array<string, mixed> $variables */
            $operations[] = [$name, $variables];
        }

        return $operations;
    }

    public function lastBody(): string
    {
        return $this->bodies[\count($this->bodies) - 1] ?? throw new \LogicException('No request was sent');
    }
}
