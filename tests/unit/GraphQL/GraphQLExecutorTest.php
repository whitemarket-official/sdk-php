<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\GraphQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\BearerCredentials;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\StaticAccessToken;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\ErrorCategory;
use WhiteMarket\Sdk\Errors\GeneralGraphQLException;
use WhiteMarket\Sdk\Errors\GraphQLErrorEntry;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(GraphQLExecutor::class)]
final class GraphQLExecutorTest extends TestCase
{
    private const TOKEN = 'synthetic-jwt-51c2';

    private const VARIABLE_SECRET = 'synthetic-variable-secret-90de';

    #[Test]
    public function sendsTheOperationAndReturnsData(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['test_ping' => 'pong']]));

        $data = $this->executor($http)->execute('query Ping { test_ping }', 'Ping', OperationType::Query, []);

        self::assertSame(['test_ping' => 'pong'], $data);
        self::assertSame('{"query":"query Ping { test_ping }","operationName":"Ping","variables":{}}', $http->lastBody(), 'no variables -> an empty JSON object');
        self::assertSame('Bearer ' . self::TOKEN, $http->lastRequest()->getHeaderLine('Authorization'));
    }

    #[Test]
    public function encodesVariablesExactly(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['x' => true]]));

        $this->executor($http)->execute('mutation M($v: In!) { x }', 'M', OperationType::Mutation, ['v' => ['price' => '0.07', 'url' => 'https://a/b', 'name' => "\u{2605} Knife", 'ratio' => 1.0]]);

        self::assertSame('{"query":"mutation M($v: In!) { x }","operationName":"M","variables":{"v":{"price":"0.07","url":"https://a/b","name":"' . "\u{2605}" . ' Knife","ratio":1.0}}}', $http->lastBody());
    }

    #[Test]
    public function rejectsVariablesThatAreNotJsonWithoutRevealingThem(): void
    {
        $http = new FakeHttpClient();

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, ['v' => self::VARIABLE_SECRET . "\xB1"]));

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        self::assertStringNotContainsString(self::VARIABLE_SECRET, $e->getMessage() . TraceArguments::exportedSdkFrames($e));
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function keepsIntegersBeyond64BitsExact(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, '{"data":{"assetId":123456789012345678901234567890}}'));

        self::assertSame(['assetId' => '123456789012345678901234567890'], $this->executor($http)->execute('query Q { assetId }', 'Q', OperationType::Query, []));
    }

    #[Test]
    public function throwsNotFoundWhenEveryErrorIsANotFoundOne(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json([
            'errors' => [[
                'message' => 'Product not found',
                'path' => ['market_item'],
                'locations' => [['line' => 1, 'column' => 9]],
                'extensions' => ['category' => ErrorCategory::MARKET_PRODUCT_NOT_FOUND],
            ]],
            'data' => ['market_item' => null],
        ]));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { market_item }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(NotFoundException::class, $e);
        self::assertCount(1, $e->errors);
        self::assertSame(
            get_object_vars(new GraphQLErrorEntry('Product not found', ErrorCategory::MARKET_PRODUCT_NOT_FOUND, ['market_item'], [['line' => 1, 'column' => 9]], ['category' => ErrorCategory::MARKET_PRODUCT_NOT_FOUND])),
            get_object_vars($e->errors[0]),
        );
        self::assertSame(['market_item' => null], $e->partialData);
        self::assertCount(1, $http->requests(), 'never retried');
    }

    #[Test]
    public function throwsTheGeneralExceptionForOtherErrors(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['errors' => [
            ['message' => 'Product not found', 'extensions' => ['category' => ErrorCategory::MARKET_PRODUCT_NOT_FOUND]],
            ['message' => 'Internal error', 'path' => ['a', 0], 'locations' => [['line' => 'x']], 'extensions' => ['category' => 7]],
        ]]));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(GeneralGraphQLException::class, $e);
        self::assertNull($e->partialData);
        self::assertCount(2, $e->errors);
        self::assertSame(
            get_object_vars(new GraphQLErrorEntry('Internal error', null, ['a', 0], [], ['category' => 7])),
            get_object_vars($e->errors[1]),
            'malformed locations are dropped, the error is kept',
        );
    }

    /**
     * @return iterable<string, array{string, bool}> the response body, whether it had a `data` key
     */
    public static function dataKeyPresence(): iterable
    {
        yield 'no data key (a request error)' => ['{"errors":[{"message":"x"}]}', false];
        yield 'data null' => ['{"data":null,"errors":[{"message":"x"}]}', true];
        yield 'data object' => ['{"data":{"a":null},"errors":[{"message":"x"}]}', true];
        yield 'data empty object' => ['{"data":{},"errors":[{"message":"x"}]}', true];
    }

    #[Test]
    #[DataProvider('dataKeyPresence')]
    public function tellsAResponseWithoutADataKeyFromOneWithNullData(string $body, bool $present): void
    {
        $e = TraceArguments::caught(fn(): array => $this->executor(new FakeHttpClient(FakeHttpClient::status(200, $body)))->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(GraphQLException::class, $e);
        self::assertSame($present, $e->dataPresent);
    }

    /**
     * @return iterable<string, array{array<mixed>, list<array{line: int, column: int}>}> the `locations` of the error, the kept ones
     */
    public static function locationsOfAnError(): iterable
    {
        yield 'line and column are integers' => [[['line' => 3, 'column' => 7]], [['line' => 3, 'column' => 7]]];
        yield 'line is valid, column is not an integer' => [[['line' => 3, 'column' => '7']], []];
        yield 'line is valid, column is missing' => [[['line' => 3]], []];
        yield 'column is valid, line is not an integer' => [[['line' => '3', 'column' => 7]], []];
        yield 'column is valid, line is missing' => [[['column' => 7]], []];
        yield 'a valid location before an invalid one drops all' => [[['line' => 1, 'column' => 1], ['line' => 2]], []];
    }

    /**
     * @param array<mixed> $locations
     * @param list<array{line: int, column: int}> $expected
     */
    #[Test]
    #[DataProvider('locationsOfAnError')]
    public function keepsLocationsOnlyWhenEveryLineAndColumnIsAnInteger(array $locations, array $expected): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['errors' => [['message' => 'Rejected', 'locations' => $locations]]]));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(GraphQLException::class, $e);
        self::assertSame($expected, $e->errors[0]->locations);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedResponses(): iterable
    {
        yield 'not JSON' => ['<html>502</html>', '$'];
        yield 'JSON scalar' => ['"ok"', '$'];
        yield 'data is a string' => ['{"data":"x"}', 'data'];
        yield 'no data, no errors' => ['{}', 'data'];
        yield 'null data, no errors' => ['{"data":null}', 'data'];
        yield 'errors is an object' => ['{"errors":{"message":"x"}}', 'errors'];
        yield 'error without a message' => ['{"errors":[{"extensions":{}}]}', 'errors[0].message'];
        yield 'error that is a string' => ['{"errors":["boom"]}', 'errors[0].message'];
        yield 'path with a null segment' => ['{"errors":[{"message":"m","path":["market_buy","item",null]}]}', 'errors[0].path'];
        yield 'path with a float segment' => ['{"errors":[{"message":"m","path":["market_buy","item",1.5]}]}', 'errors[0].path'];
        yield 'path with a nested array segment' => ['{"errors":[{"message":"m","path":["market_buy",["x"]]}]}', 'errors[0].path'];
        yield 'path as a dotted string' => ['{"errors":[{"message":"m","path":"market_buy.item.description"}]}', 'errors[0].path'];
        yield 'path as an object' => ['{"errors":[{"message":"m","path":{"a":"market_buy"}}]}', 'errors[0].path'];
        yield 'second error with a bad path' => ['{"errors":[{"message":"a"},{"message":"m","path":[true]}]}', 'errors[1].path'];
        yield 'data is a non-empty list next to errors' => ['{"data":[{"market_buy":{"id":"1"}}],"errors":[{"message":"m"}]}', 'data'];
        yield 'data is a non-empty list without errors' => ['{"data":[1]}', 'data'];
        yield 'extensions is a string' => ['{"errors":[{"message":"m","extensions":"x"}]}', 'errors[0].extensions'];
    }

    #[Test]
    #[DataProvider('malformedResponses')]
    public function rejectsAResponseThatIsNotAGraphQLResponse(string $body, string $path): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, $body));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
        self::assertNull($e->getPrevious());
    }

    /**
     * @return iterable<string, array{string, list<string|int>}>
     */
    public static function acceptedPaths(): iterable
    {
        yield 'no path' => ['{"errors":[{"message":"m"}]}', []];
        yield 'null path' => ['{"errors":[{"message":"m","path":null}]}', []];
        yield 'empty list' => ['{"errors":[{"message":"m","path":[]}]}', []];
        yield 'string and int segments' => ['{"errors":[{"message":"m","path":["market_buy","stickers",1]}]}', ['market_buy', 'stickers', 1]];
    }

    /**
     * @param list<string|int> $expected
     */
    #[Test]
    #[DataProvider('acceptedPaths')]
    public function readsAnAbsentOrNullPathAsNoPath(string $body, array $expected): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, $body));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(GeneralGraphQLException::class, $e);
        self::assertSame($expected, $e->errors[0]->path);
    }

    #[Test]
    public function acceptsAnEmptyDataNextToErrors(): void
    {
        // json_decode(assoc) cannot tell `{}` from `[]`, so an empty `data` stays valid.
        $http = new FakeHttpClient(FakeHttpClient::status(200, '{"data":{},"errors":[{"message":"m"}]}'));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(GeneralGraphQLException::class, $e);
        self::assertSame([], $e->partialData);
    }

    #[Test]
    public function returnsAnEmptyErrorListResponseAsData(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['x' => 1], 'errors' => []]));

        self::assertSame(['x' => 1], $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, []));
    }

    #[Test]
    public function passesTheOperationTypeToTheTransport(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::json(['data' => ['x' => 1]]));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('mutation M { x }', 'M', OperationType::Mutation, []));

        self::assertInstanceOf(ServiceUnavailableException::class, $e);
        self::assertCount(1, $http->requests(), 'a mutation is never repeated');
    }

    /**
     * Codegen smoke test through the HTTP path (plan T1.4): the generated client, this executor and the transport.
     */
    #[Test]
    public function runsAGeneratedOperationOverHttp(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['auth_token' => ['accessToken' => self::TOKEN, 'isNewPerson' => false]]]));

        $tokens = (new GeneratedClient($this->executor($http)))->authToken();

        self::assertSame(self::TOKEN, $tokens->accessToken->reveal());
        $payload = $http->lastPayload();
        self::assertSame('AuthToken', $payload['operationName'] ?? null);
        self::assertIsString($payload['query'] ?? null);
        self::assertStringStartsWith('mutation AuthToken {', $payload['query']);
    }

    #[Test]
    public function keepsEveryTopLevelFieldOfData(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['first' => 'a', 'second' => 'b', 'third' => 'c']]));

        $data = $this->executor($http)->execute('query Q { first second third }', 'Q', OperationType::Query, []);

        self::assertSame(['first' => 'a', 'second' => 'b', 'third' => 'c'], $data);
    }

    #[Test]
    public function keepsEveryLocationOfAnError(): void
    {
        $locations = [['line' => 1, 'column' => 2], ['line' => 3, 'column' => 4], ['line' => 5, 'column' => 6]];
        $http = new FakeHttpClient(FakeHttpClient::json(['errors' => [['message' => 'Rejected', 'locations' => $locations]]]));

        $e = TraceArguments::caught(fn(): array => $this->executor($http)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(GraphQLException::class, $e);
        self::assertSame($locations, $e->errors[0]->locations);
    }

    /**
     * @return iterable<string, array{int, bool}> the number of list levels counted by the test below, whether it decodes
     */
    public static function nestingDepths(): iterable
    {
        yield '511 nested lists -> decoded' => [511, true];
        yield '512 nested lists -> shape error' => [512, false];
    }

    #[Test]
    #[DataProvider('nestingDepths')]
    public function decodesAResponseUpToTheJsonDepthLimitOfFiveHundredTwelve(int $depth, bool $decoded): void
    {
        // `{"data":{"a":[[...0...]]}}`: PHP's limit of 512 counts the envelope, `data`, the lists and the leaf value, so the
        // largest accepted nesting is 511 here (observed, php 8.5).
        $body = '{"data":{"a":' . str_repeat('[', $depth - 2) . '0' . str_repeat(']', $depth - 2) . '}}';
        $http = new FakeHttpClient(FakeHttpClient::status(200, $body));

        $execute = fn(): array => $this->executor($http)->execute('query Q { a }', 'Q', OperationType::Query, []);

        if ($decoded) {
            self::assertArrayHasKey('a', $execute());

            return;
        }
        $e = TraceArguments::caught($execute);
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('$', $e->path);
    }

    private function executor(FakeHttpClient $http): GraphQLExecutor
    {
        return new GraphQLExecutor(TestTransport::over($http), new BearerCredentials(new StaticAccessToken(new Secret(self::TOKEN))));
    }
}
