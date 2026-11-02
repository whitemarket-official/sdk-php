<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\BadRequestException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralGraphQLException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\GraphQLErrorEntry;
use WhiteMarket\Sdk\Errors\MutationRejection;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Errors\TransportException;

#[CoversClass(MutationRejection::class)]
final class MutationRejectionTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, bool}>
     */
    public static function failures(): iterable
    {
        yield 'HTTP 400' => [new BadRequestException(400, ''), true];
        yield 'HTTP 401 that could not be refreshed' => [new AuthenticationException(401, ''), true];
        yield 'HTTP 429' => [new RateLimitException(429, '', 30), true];
        yield 'HTTP 500' => [new GeneralServerException(500, ''), false];
        yield 'HTTP 503' => [new ServiceUnavailableException(503, ''), false];
        yield 'a network failure' => [new TransportException('connection reset'), false];
        yield 'an unreadable response' => [new DecodeException('market_new', 'not an object'), false];
        yield 'a broken collaborator' => [new \RuntimeException('boom'), false];
        yield 'a type error' => [new \TypeError('boom'), false];
    }

    #[Test]
    #[DataProvider('failures')]
    public function classifiesTheFailuresThatAreNotGraphQlErrors(\Throwable $failure, bool $rejection): void
    {
        self::assertSame($rejection, (new MutationRejection())->isRejection($failure, 'market_new'));
    }

    /**
     * WHITEMARKET_API.md 2: a response may carry partial `data` next to `errors`; an error below the root field, or a
     * value in `data`, means the mutation ran.
     *
     * @return iterable<string, array{?array<mixed>, list<list<int|string>>, bool}> `data`, the `path` of every error, whether it is a rejection
     */
    public static function graphQlErrors(): iterable
    {
        yield 'data null, error without a path' => [null, [[]], true];
        yield 'data null, root-path error' => [null, [['market_new_multiple']], true];
        yield 'data null, nested-path error' => [null, [['market_new_multiple', 'product']], false];
        yield 'root field null in data, root-path error' => [['market_new_multiple' => null], [['market_new_multiple']], true];
        yield 'root field null in data, nested-path error' => [['market_new_multiple' => null], [['market_new_multiple', 0, 'product']], false];
        yield 'root field holds a result' => [['market_new_multiple' => [['index' => 0]]], [['market_new_multiple']], false];
        yield 'root field holds a scalar' => [['market_new_multiple' => 'x'], [['market_new_multiple']], false];
        yield 'empty data object' => [[], [['market_new_multiple']], false];
        yield 'data without the root field' => [['other' => null], [['market_new_multiple']], false];
        yield 'data is a list' => [[['market_new_multiple' => null]], [[]], false];
        yield 'the path of another root field' => [null, [['market_buy']], false];
        yield 'one root and one nested error' => [['market_new_multiple' => null], [['market_new_multiple'], ['market_new_multiple', 'x']], false];
        yield 'two root errors' => [null, [['market_new_multiple'], []], true];
    }

    /**
     * @param ?array<mixed> $data
     * @param list<list<int|string>> $paths
     */
    #[Test]
    #[DataProvider('graphQlErrors')]
    public function classifiesAGraphQlErrorByItsDataAndPaths(?array $data, array $paths, bool $rejection): void
    {
        $errors = array_map(static fn(array $path): GraphQLErrorEntry => new GraphQLErrorEntry('Failure', 'internal', $path), $paths);
        self::assertNotSame([], $errors);

        self::assertSame($rejection, (new MutationRejection())->isRejection(new GeneralGraphQLException($errors, $data), 'market_new_multiple'));
    }

    /**
     * A `*_multiple` resolver that fails after acting on some items answers with an error at the root field; only an
     * error without a path (a request error: parse, validation) proves nothing ran (GraphQL spec 7.1.2).
     *
     * @return iterable<string, array{?array<mixed>, list<list<int|string>>, bool}>
     */
    public static function batchGraphQlErrors(): iterable
    {
        yield 'data null, error without a path' => [null, [[]], true];
        yield 'data null, two errors without a path' => [null, [[], []], true];
        yield 'data null, root-path error' => [null, [['market_new_multiple']], false];
        yield 'root field null in data, root-path error' => [['market_new_multiple' => null], [['market_new_multiple']], false];
        yield 'data null, nested-path error' => [null, [['market_new_multiple', 0, 'product']], false];
        yield 'data null, one pathless and one root-path error' => [null, [[], ['market_new_multiple']], false];
        yield 'pathless error next to a result' => [['market_new_multiple' => []], [[]], false];
        yield 'pathless error, empty data object' => [[], [[]], false];
    }

    /**
     * @param ?array<mixed> $data
     * @param list<list<int|string>> $paths
     */
    #[Test]
    #[DataProvider('batchGraphQlErrors')]
    public function aBatchRejectsOnlyOnARequestErrorWithoutAnyPath(?array $data, array $paths, bool $rejection): void
    {
        $errors = array_map(static fn(array $path): GraphQLErrorEntry => new GraphQLErrorEntry('Failure', 'internal', $path), $paths);
        self::assertNotSame([], $errors);

        self::assertSame($rejection, (new MutationRejection())->isRejection(new GeneralGraphQLException($errors, $data, $data !== null), 'market_new_multiple', rootFieldErrorRejects: false));
    }

    #[Test]
    public function aBatchRejectsOnlyWhenTheResponseHadNoDataKey(): void
    {
        $errors = [new GraphQLErrorEntry('Failure', 'internal')];
        $rejection = new MutationRejection();

        self::assertTrue($rejection->isRejection(new GeneralGraphQLException($errors, null, false), 'market_new_multiple', rootFieldErrorRejects: false));
        self::assertFalse($rejection->isRejection(new GeneralGraphQLException($errors, null, true), 'market_new_multiple', rootFieldErrorRejects: false));
        self::assertTrue($rejection->isRejection(new GeneralGraphQLException($errors, null, true), 'market_buy'), 'a single mutation is unchanged');
    }

    #[Test]
    public function anExceptionBuiltWithoutTheDataFlagIsNotABatchRejection(): void
    {
        $exception = new GeneralGraphQLException([new GraphQLErrorEntry('Failure', 'internal')]);

        self::assertTrue($exception->dataPresent, 'unknown is read as: execution may have started');
        self::assertFalse((new MutationRejection())->isRejection($exception, 'market_new_multiple', rootFieldErrorRejects: false));
    }

    #[Test]
    #[DataProvider('failures')]
    public function theHttpClassificationDoesNotDependOnTheRootFieldRule(\Throwable $failure, bool $rejection): void
    {
        self::assertSame($rejection, (new MutationRejection())->isRejection($failure, 'market_new_multiple', rootFieldErrorRejects: false));
    }

    #[Test]
    public function aNotFoundErrorIsClassifiedLikeAnyOtherGraphQlError(): void
    {
        $notFound = static fn(?array $data): NotFoundException => new NotFoundException([new GraphQLErrorEntry('Gone', 'market.product.not_found', ['market_remove'])], $data);

        $rejection = new MutationRejection();

        self::assertTrue($rejection->isRejection($notFound(null), 'market_remove'));
        self::assertFalse($rejection->isRejection($notFound(['market_remove' => ['id' => 'x']]), 'market_remove'));
    }
}
