<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\ErrorCategory;
use WhiteMarket\Sdk\Errors\GeneralGraphQLException;
use WhiteMarket\Sdk\Errors\GraphQLErrorEntry;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(GraphQLException::class)]
#[CoversClass(ErrorCategory::class)]
#[CoversClass(GraphQLErrorEntry::class)]
final class GraphQLExceptionTest extends TestCase
{
    private const CREDENTIAL = 'synthetic-credential-7f3a';

    /**
     * @return iterable<string, array{non-empty-list<GraphQLErrorEntry>, class-string<GraphQLException>}>
     */
    public static function errorSets(): iterable
    {
        $notFound = new GraphQLErrorEntry('Oops! Item not found.', ErrorCategory::MARKET_PRODUCT_NOT_FOUND);
        $otherNotFound = new GraphQLErrorEntry('Deal not found', 'market.deal.not_found');
        $other = new GraphQLErrorEntry('Nope', 'market.product.forbidden');
        $uncategorized = new GraphQLErrorEntry('Internal error occurred');

        yield 'one not found -> not found' => [[$notFound], NotFoundException::class];
        yield 'two different not found -> not found' => [[$notFound, $otherNotFound], NotFoundException::class];
        yield 'not found + other -> generic' => [[$notFound, $other], GeneralGraphQLException::class];
        yield 'other + not found -> generic' => [[$other, $notFound], GeneralGraphQLException::class];
        yield 'no category -> generic' => [[$uncategorized], GeneralGraphQLException::class];
        yield 'other category -> generic' => [[$other], GeneralGraphQLException::class];
    }

    /**
     * @param non-empty-list<GraphQLErrorEntry> $errors
     * @param class-string<GraphQLException> $expected
     */
    #[Test]
    #[DataProvider('errorSets')]
    public function picksTheClassFromTheCategories(array $errors, string $expected): void
    {
        $e = GraphQLException::fromErrors($errors, ['market_item' => null]);

        self::assertSame($e::class, $expected);
        self::assertSame($errors, $e->errors);
        self::assertSame(['market_item' => null], $e->partialData);
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function categories(): iterable
    {
        yield 'documented not found' => [ErrorCategory::MARKET_PRODUCT_NOT_FOUND, true];
        yield 'any area not found' => ['wallet.transfer.not_found', true];
        yield 'bare not_found without a dot' => ['not_found', false];
        yield 'not_found in the middle' => ['market.not_found.product', false];
        yield 'suffix glued to a word' => ['market.productnot_found', false];
        yield 'empty' => ['', false];
        yield 'none' => [null, false];
    }

    #[Test]
    #[DataProvider('categories')]
    public function recognisesNotFoundBySuffix(?string $category, bool $expected): void
    {
        self::assertSame($expected, ErrorCategory::isNotFound($category));
    }

    #[Test]
    public function messageNamesTheFirstErrorAndCountsTheRest(): void
    {
        $e = GraphQLException::fromErrors([
            new GraphQLErrorEntry('first', 'a.b'),
            new GraphQLErrorEntry('second'),
            new GraphQLErrorEntry('third'),
        ]);

        self::assertSame('GraphQL error [a.b]: first (and 2 more)', $e->getMessage());
    }

    #[Test]
    public function messageOfASingleUncategorizedError(): void
    {
        self::assertSame('GraphQL error: boom', GraphQLException::fromErrors([new GraphQLErrorEntry('boom')])->getMessage());
    }

    #[Test]
    public function findsACategoryInAnyError(): void
    {
        $e = GraphQLException::fromErrors([new GraphQLErrorEntry('x', 'a.one'), new GraphQLErrorEntry('y', 'a.two')]);

        self::assertTrue($e->hasCategory('a.two'));
        self::assertFalse($e->hasCategory('a.three'));
        self::assertNull($e->partialData);
    }

    /**
     * The server's `data` next to the errors may hold a credential (`auth_token` succeeding partially), and the error
     * message or `extensions` may echo one: neither may reach the trace arguments of the exception.
     *
     * @return iterable<string, array{non-empty-list<GraphQLErrorEntry>, array<mixed>|null}>
     */
    public static function errorsNextToACredential(): iterable
    {
        yield 'partial data' => [[new GraphQLErrorEntry('boom')], ['auth_token' => ['accessToken' => self::CREDENTIAL]]];
        yield 'error message' => [[new GraphQLErrorEntry('bad token ' . self::CREDENTIAL)], null];
        yield 'error extensions' => [[new GraphQLErrorEntry('boom', 'a.b', extensions: ['token' => self::CREDENTIAL])], null];
    }

    /**
     * @param non-empty-list<GraphQLErrorEntry> $errors
     * @param array<mixed>|null $partialData
     */
    #[Test]
    #[DataProvider('errorsNextToACredential')]
    public function keepsTheResponseOutOfItsOwnTrace(array $errors, ?array $partialData): void
    {
        $e = TraceArguments::caught(static function () use ($errors, $partialData): never {
            throw GraphQLException::fromErrors($errors, $partialData);
        });

        self::assertInstanceOf(GraphQLException::class, $e);
        self::assertStringNotContainsString(self::CREDENTIAL, TraceArguments::exportedSdkFrames($e));
    }

    /**
     * A malformed server error (here: a category that is an array) fails inside the constructors with a TypeError whose
     * frames would carry every other argument; built through reflection because the types forbid it statically
     * (reflection calls in coercive mode, so the value must not be coercible to the parameter type).
     *
     * @return iterable<string, array{class-string, list<mixed>}>
     */
    public static function malformedArgumentsNextToACredential(): iterable
    {
        yield 'entry: message' => [GraphQLErrorEntry::class, ['bad token ' . self::CREDENTIAL, ['not-a-string']]];
        yield 'entry: category' => [GraphQLErrorEntry::class, ['boom', self::CREDENTIAL, [], 'not-a-list']];
        yield 'entry: extensions' => [GraphQLErrorEntry::class, ['boom', ['not-a-string'], [], [], ['token' => self::CREDENTIAL]]];
        yield 'exception: errors' => [GeneralGraphQLException::class, [[new GraphQLErrorEntry('bad token ' . self::CREDENTIAL)], 'not-an-array']];
        yield 'exception: partial data' => [GeneralGraphQLException::class, ['not-a-list', ['accessToken' => self::CREDENTIAL]]];
    }

    /**
     * @param class-string $class
     * @param list<mixed> $arguments
     */
    #[Test]
    #[DataProvider('malformedArgumentsNextToACredential')]
    public function keepsTheOtherArgumentsOutOfAConstructorTypeError(string $class, array $arguments): void
    {
        $e = TraceArguments::caught(static fn(): object => (new \ReflectionClass($class))->newInstanceArgs($arguments));

        self::assertInstanceOf(\TypeError::class, $e);
        self::assertStringNotContainsString(self::CREDENTIAL, TraceArguments::exportedSdkFrames($e));
    }
}
