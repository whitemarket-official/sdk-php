<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use WhiteMarket\Sdk\Errors\TransportException;

/**
 * The attempt loop {@see HttpTransport} and the export transport share (SDK_SPEC 1.4): send, log, hand a final response
 * to the caller, otherwise ask the caller whether the failure may be repeated, back off and go again. What may be
 * repeated is the caller's rule ({@see RetryPolicy::shouldRetry()} for queries, {@see RetryPolicy::shouldRetryRead()}
 * for export GETs; a mutation never), so this class cannot make a mutation retryable.
 *
 * The loggers receive the attempt number, status, duration and the caller's context only: never headers, URLs or bodies.
 *
 * @internal
 */
final readonly class RetryLoop
{
    public function __construct(
        private RetryPolicy $retryPolicy,
        private Clock $clock,
        private Sleeper $sleeper,
        private RandomSource $random,
        private LoggerInterface $logger,
    ) {}

    /**
     * Sends one request through a client. Every Throwable the client throws, an SDK exception included (an injected
     * client may throw anything, e.g. an `AuthenticationException` built from its own 401), becomes a
     * {@see ClientFailure}, so it can neither pass for an answer of the API (a replayed or "rejected" mutation) nor carry
     * what the client chained to it. Call it for the client's `sendRequest()` only, never around SDK logic. The response comes back as a
     * {@see ClientResponse}: its methods and its body stream are client code too and fail the same way.
     *
     * @throws ClientFailure
     */
    public static function sendVia(ClientInterface $client, #[\SensitiveParameter] RequestInterface $request): ClientResponse
    {
        try {
            $response = $client->sendRequest($request);
        } catch (\Throwable $e) {
            throw new ClientFailure($e, $e instanceof NetworkExceptionInterface);
        }

        return new ClientResponse($response);
    }

    /**
     * @template T of object|string
     * @template A
     *
     * @param-immediately-invoked-callable $build
     * @param-immediately-invoked-callable $send
     * @param-immediately-invoked-callable $mayRetry
     * @param-immediately-invoked-callable $accept
     * @param-immediately-invoked-callable $failure
     * @param-immediately-invoked-callable $transportFailure
     *
     * Every closure is `#[\SensitiveParameter]`: each captures the client (or `$this`, which holds it), a client may
     * keep the requests it sent with their credential headers, and `print_r` of a frame shows what a closure captured.
     *
     * @param string $subject names the requests in the log: "WhiteMarket <subject> request"
     * @param array<string, mixed> $attemptContext logged with every attempt, before `attempt`, `status` and `durationMs`
     * @param array<string, mixed> $retryContext logged with every back-off, before `attempt` and `delaySeconds`
     * @param \Closure(): A $build builds what one attempt sends, outside the failure handling (a bug or a rejected header
     *                             surfaces as itself)
     * @param \Closure(A): ClientResponse $send sends it through {@see sendVia()}: a {@see ClientFailure} is a failure of the attempt, anything else
     *                                          it throws is the SDK's own and propagates
     * @param \Closure(int, ?int, ?int): bool $mayRetry attempt, status (null: no response), `Retry-After` seconds
     * @param \Closure(ClientResponse): ?T $accept the result for a final response, null for any other
     * @param \Closure(ClientResponse, int, ?int): \Throwable $failure the exception for a response that is neither
     *                                                                 accepted nor retried; receives the status and `Retry-After` seconds
     * @param \Closure(string, \Throwable): TransportException $transportFailure what failed ("network error",
     *                                                                           "request failed") and the client's exception, which must not be chained
     *
     * @return T
     *
     * @throws \Throwable whatever `$failure` and `$transportFailure` return, or what `$send` itself throws apart from a {@see ClientFailure}
     *                    (a {@see TransportException} when a method of the response fails, see {@see ClientResponse})
     */
    public function run(
        string $subject,
        array $attemptContext,
        array $retryContext,
        #[\SensitiveParameter]
        \Closure $build,
        #[\SensitiveParameter]
        \Closure $send,
        #[\SensitiveParameter]
        \Closure $mayRetry,
        #[\SensitiveParameter]
        \Closure $accept,
        #[\SensitiveParameter]
        \Closure $failure,
        #[\SensitiveParameter]
        \Closure $transportFailure,
    ): mixed {
        for ($attempt = 1; ; ++$attempt) {
            $input = $build();
            $started = hrtime(true);
            $clientFailure = null;
            try {
                $response = $send($input);
            } catch (ClientFailure $e) {
                // Only what the client threw lands here. Whatever else `$send` throws is the SDK's own and passes through.
                // Not chained: the client's exception holds the request.
                $response = null;
                $clientFailure = $e;
            }
            if ($response === null) {
                if (!$clientFailure->network) {
                    // PSR-18 or not (a non-default client, a middleware): say what failed, not the request it holds.
                    throw $transportFailure('request failed', $clientFailure->failure);
                }
                $this->logAttempt($subject, $attemptContext, $attempt, null, $started);
                if ($mayRetry($attempt, null, null)) {
                    $this->backOff($subject, $retryContext, $attempt);

                    continue;
                }

                throw $transportFailure('network error', $clientFailure->failure);
            }

            $status = $response->statusCode();
            $this->logAttempt($subject, $attemptContext, $attempt, $status, $started);
            $result = $accept($response);
            if ($result !== null) {
                return $result;
            }
            $retryAfter = RetryAfter::seconds($response->headerLine('Retry-After'), $this->clock->now());
            if ($mayRetry($attempt, $status, $retryAfter)) {
                $response->discard();
                $this->backOff($subject, $retryContext, $attempt);

                continue;
            }

            throw $failure($response, $status, $retryAfter);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function backOff(string $subject, array $context, int $attempt): void
    {
        $delay = $this->retryPolicy->delay($attempt, $this->random);
        $this->logger->info(\sprintf('Retrying WhiteMarket %s request', $subject), $context + [
            'attempt' => $attempt,
            'delaySeconds' => $delay,
        ]);
        $this->sleeper->sleep($delay);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAttempt(string $subject, array $context, int $attempt, ?int $status, int|float $started): void
    {
        $this->logger->debug(\sprintf('WhiteMarket %s request', $subject), $context + [
            'attempt' => $attempt,
            'status' => $status,
            'durationMs' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
