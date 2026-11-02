<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

use WhiteMarket\Sdk\Auth\Credentials;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GraphQLErrorEntry;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Transport\HttpTransport;

/**
 * Runs an operation over HTTP (ADR 0002 "Layers"): sends `{query, operationName, variables}` with the credentials'
 * headers and returns `data`, or throws {@see GraphQLException} (a {@see \WhiteMarket\Sdk\Errors\NotFoundException}
 * when every error is a "not found" one) for a response with `errors`.
 *
 * @internal built by the client; users go through WhiteMarketClient
 */
final readonly class GraphQLExecutor implements OperationExecutor
{
    private const JSON_ENCODE_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private HttpTransport $transport,
        private Credentials $credentials,
    ) {}

    public function execute(
        string $document,
        string $operationName,
        OperationType $type,
        #[\SensitiveParameter]
        array $variables,
    ): array {
        // Without JSON_THROW_ON_ERROR: a JsonException would hold the variables in its trace.
        $body = json_encode(
            ['query' => $document, 'operationName' => $operationName, 'variables' => $variables === [] ? new \stdClass() : $variables],
            self::JSON_ENCODE_FLAGS,
        );
        if ($body === false) {
            throw new \InvalidArgumentException(\sprintf('The variables of %s cannot be encoded as JSON: %s.', $operationName, json_last_error_msg()));
        }

        return $this->dataOrThrow($this->postWithOneReplay($operationName, $type, $body));
    }

    /**
     * Mutations too: a 401 rejects the request before it runs (SDK_SPEC 1.4, ADR 0003).
     */
    private function postWithOneReplay(string $operationName, OperationType $type, #[\SensitiveParameter] string $body): string
    {
        $headers = $this->credentials->headers($operationName);
        try {
            return $this->transport->post($operationName, $type, $body, $headers);
        } catch (AuthenticationException $e) {
            $replay = $this->credentials->headersAfterRejection($headers);
            if ($replay === null) {
                throw $e;
            }

            return $this->transport->post($operationName, $type, $body, $replay);
        }
    }

    /**
     * @return array<mixed>
     */
    private function dataOrThrow(#[\SensitiveParameter] string $body): array
    {
        $response = json_decode($body, true, 512, \JSON_BIGINT_AS_STRING);
        if (!\is_array($response)) {
            throw new DecodeException('$', 'the response is not a JSON object');
        }
        $data = $response['data'] ?? null;
        if ($data !== null && !\is_array($data)) {
            throw new DecodeException('data', 'expected an object or null');
        }
        // `json_decode(assoc)` turns `{}` and `[]` into the same empty array, so only a NON-EMPTY list is told apart.
        if ($data !== null && $data !== [] && array_is_list($data)) {
            throw new DecodeException('data', 'expected an object or null');
        }
        $errors = $response['errors'] ?? [];
        if (!\is_array($errors) || !array_is_list($errors)) {
            throw new DecodeException('errors', 'expected a list');
        }
        if ($errors !== []) {
            throw GraphQLException::fromErrors($this->entries($errors), $data, \array_key_exists('data', $response));
        }
        if ($data === null) {
            throw new DecodeException('data', 'missing in a response without errors');
        }

        return $data;
    }

    /**
     * @param non-empty-list<mixed> $errors
     *
     * @return non-empty-list<GraphQLErrorEntry>
     */
    private function entries(#[\SensitiveParameter] array $errors): array
    {
        $entries = [];
        foreach ($errors as $index => $error) {
            $entries[] = $this->entry($error, \sprintf('errors[%d]', $index));
        }

        return $entries;
    }

    private function entry(#[\SensitiveParameter] mixed $error, string $path): GraphQLErrorEntry
    {
        if (!\is_array($error) || !\is_string($error['message'] ?? null)) {
            throw new DecodeException($path . '.message', 'expected a string');
        }
        $extensions = $error['extensions'] ?? [];
        if (!\is_array($extensions)) {
            throw new DecodeException($path . '.extensions', 'expected an object');
        }
        $category = $extensions['category'] ?? null;

        return new GraphQLErrorEntry(
            $error['message'],
            \is_string($category) ? $category : null,
            $this->errorPath($error['path'] ?? null, $path . '.path'),
            $this->locations($error['locations'] ?? []),
            $extensions,
        );
    }

    /**
     * An absent or null `path` is "no path". A present one that is not a list of strings and ints is a malformed error
     * entry: reading it as no path would make a nested error look like a root one (rejection classification).
     *
     * @return list<string|int>
     */
    private function errorPath(mixed $path, string $where): array
    {
        if ($path === null) {
            return [];
        }
        if (!\is_array($path) || !array_is_list($path)) {
            throw new DecodeException($where, 'expected a list of strings and integers');
        }
        $segments = [];
        foreach ($path as $segment) {
            if (!\is_string($segment) && !\is_int($segment)) {
                throw new DecodeException($where, 'expected a list of strings and integers');
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Malformed `locations` are diagnostics only: they are dropped, not turned into a decode failure that would hide
     * the error itself.
     *
     * @return list<array{line: int, column: int}>
     */
    private function locations(mixed $locations): array
    {
        if (!\is_array($locations)) {
            return [];
        }
        $result = [];
        foreach ($locations as $location) {
            if (!\is_array($location) || !\is_int($location['line'] ?? null) || !\is_int($location['column'] ?? null)) {
                return [];
            }
            $result[] = ['line' => $location['line'], 'column' => $location['column']];
        }

        return $result;
    }
}
