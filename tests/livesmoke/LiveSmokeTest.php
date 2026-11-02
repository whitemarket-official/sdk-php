<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\LiveSmoke;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralGraphQLException;
use WhiteMarket\Sdk\Errors\GraphQLErrorEntry;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Tests\Support\FakeOperationExecutor;
use WhiteMarket\Sdk\Tools\LiveSmoke\Classifier;
use WhiteMarket\Sdk\Tools\LiveSmoke\QueryCatalog;
use WhiteMarket\Sdk\Tools\LiveSmoke\ReadOnlyExecutor;
use WhiteMarket\Sdk\Tools\LiveSmoke\ReadOnlyViolation;
use WhiteMarket\Sdk\Tools\LiveSmoke\Report;
use WhiteMarket\Sdk\Tools\LiveSmoke\StepResult;
use WhiteMarket\Sdk\Tools\LiveSmoke\StepStatus;

/**
 * SDK_SPEC 6.4: the live smoke never runs a mutation and covers every generated query.
 */
final class LiveSmokeTest extends TestCase
{
    private const OPERATIONS = __DIR__ . '/../../src/Generated/Operation';

    /**
     * @return iterable<string, array{string}>
     */
    public static function mutations(): iterable
    {
        foreach (self::operationNames('Mutation') as $name) {
            yield $name => ['WhiteMarket\\Sdk\\Generated\\Operation\\Mutation\\' . $name];
        }
    }

    #[DataProvider('mutations')]
    public function testEveryGeneratedMutationIsRefusedBeforeItIsSent(string $class): void
    {
        self::assertTrue(class_exists($class));
        $reflection = new \ReflectionClass($class);
        $document = $reflection->getConstant('DOCUMENT');
        $name = $reflection->getConstant('NAME');
        $type = $reflection->getConstant('TYPE');
        self::assertIsString($document);
        self::assertIsString($name);
        self::assertSame(OperationType::Mutation, $type);
        $inner = new FakeOperationExecutor();
        $executor = new ReadOnlyExecutor($inner);

        $this->expectException(ReadOnlyViolation::class);
        try {
            $executor->execute($document, $name, $type, []);
        } finally {
            self::assertSame([], $inner->calls(), 'nothing may reach the inner executor');
        }
    }

    public function testAMutationDocumentIsRefusedEvenWhenDeclaredAsQuery(): void
    {
        $inner = new FakeOperationExecutor();

        $this->expectException(ReadOnlyViolation::class);
        try {
            (new ReadOnlyExecutor($inner))->execute("mutation X {\n  a\n}", 'X', OperationType::Query, []);
        } finally {
            self::assertSame([], $inner->calls());
        }
    }

    public function testAQueryDeclaredAsMutationIsRefused(): void
    {
        $inner = new FakeOperationExecutor();

        $this->expectException(ReadOnlyViolation::class);
        try {
            (new ReadOnlyExecutor($inner))->execute('query X { a }', 'X', OperationType::Mutation, []);
        } finally {
            self::assertSame([], $inner->calls());
        }
    }

    public function testAQueryPassesThrough(): void
    {
        $inner = new FakeOperationExecutor(['a' => 1]);

        $data = (new ReadOnlyExecutor($inner))->execute("  query X {\n a }", 'X', OperationType::Query, ['v' => 1]);

        self::assertSame(['a' => 1], $data);
        self::assertCount(1, $inner->calls());
        self::assertSame('X', $inner->lastCall()['operationName']);
        self::assertSame(['v' => 1], $inner->lastCall()['variables']);
    }

    public function testTheCatalogCoversExactlyTheGeneratedQueries(): void
    {
        $called = [];
        foreach (QueryCatalog::steps(new GeneratedClient(new FakeOperationExecutor())) as $key => $_) {
            $inner = new FakeOperationExecutor();
            $steps = QueryCatalog::steps(new GeneratedClient($inner));
            self::assertArrayHasKey($key, $steps);
            $step = $steps[$key];
            try {
                $step();
            } catch (\LogicException) { // nothing queued: the call is recorded, then the fake refuses
            }
            $calls = $inner->calls();
            self::assertCount(1, $calls, $key . ' must run exactly one operation');
            self::assertSame($key, $calls[0]['operationName'], 'the step keyed ' . $key . ' runs another operation');
            $called[] = $calls[0]['operationName'];
        }

        $generated = self::operationNames('Query');
        sort($called);
        self::assertSame($generated, $called, 'add the new query to tools/live-smoke/src/QueryCatalog.php');
    }

    public function testTheSpecifiedCallsUseTheSpecifiedVariables(): void
    {
        $seen = [];
        foreach (['MarketList', 'DealBuyerStatuses'] as $key) {
            $inner = new FakeOperationExecutor();
            $steps = QueryCatalog::steps(new GeneratedClient($inner));
            self::assertArrayHasKey($key, $steps);
            try {
                $steps[$key]();
            } catch (\LogicException) { // nothing queued: the call is recorded, then the fake refuses
            }
            $seen[$key] = $inner->calls()[0]['variables'] ?? [];
        }

        self::assertSame(['non-existent'], $seen['DealBuyerStatuses']['ids'] ?? null);
        self::assertSame(['first' => 1], $seen['MarketList']['forwardPagination'] ?? null);
    }

    /**
     * @return iterable<string, array{\Throwable, StepStatus, string}>
     */
    public static function outcomes(): iterable
    {
        $entry = static fn(?string $category): GraphQLErrorEntry => new GraphQLErrorEntry('SECRET-MESSAGE', $category);
        yield 'not found is reachable' => [new NotFoundException([$entry('market.product.not_found')]), StepStatus::Pass, ''];
        yield 'other business category is reachable' => [new GeneralGraphQLException([$entry('deal.invalid')]), StepStatus::Pass, ''];
        yield 'uncategorized error fails' => [new GeneralGraphQLException([$entry(null)]), StepStatus::Fail, 'category=(none)'];
        yield 'auth category fails' => [new GeneralGraphQLException([$entry('auth.token.expired')]), StepStatus::Fail, 'category=auth.token.expired'];
        yield 'internal category fails' => [new GeneralGraphQLException([$entry('internal')]), StepStatus::Fail, 'category=internal'];
        yield 'one bad error among good ones fails' => [new GeneralGraphQLException([$entry('a.b'), $entry('internal.x')]), StepStatus::Fail, 'category=a.b,internal.x'];
        yield 'odd category is not echoed' => [new GeneralGraphQLException([$entry('auth SECRET')]), StepStatus::Fail, 'category=(invalid)'];
        yield 'http 401 fails' => [new AuthenticationException(401, 'SECRET-MESSAGE'), StepStatus::Fail, AuthenticationException::class];
        yield 'decode error fails' => [new DecodeException('$.a', 'SECRET-MESSAGE'), StepStatus::Fail, DecodeException::class];
    }

    #[DataProvider('outcomes')]
    public function testClassification(\Throwable $e, StepStatus $status, string $detailContains): void
    {
        $result = Classifier::classify('step', $e, 3);

        self::assertSame($status, $result->status);
        self::assertStringContainsString($detailContains, $result->detail);
        self::assertStringNotContainsString('SECRET', $result->detail);
    }

    public function testTheReportShowsCountsAndFailuresAndExitsNonZeroOnFail(): void
    {
        $results = [
            new StepResult('a', StepStatus::Pass, 5),
            new StepResult('b|c', StepStatus::Fail, 7, 'X | Y'),
        ];

        $md = Report::render($results);

        self::assertStringContainsString('| a | PASS | 5 |  |', $md);
        self::assertStringContainsString('| b\\|c | FAIL | 7 | X \\| Y |', $md);
        self::assertStringContainsString('PASS 1, FAIL 1', $md);
        self::assertSame(1, Report::exitCode($results));
        self::assertSame(0, Report::exitCode([$results[0]]));
    }

    /**
     * @return list<string> sorted class names of the generated operations of one kind
     */
    private static function operationNames(string $kind): array
    {
        $files = glob(self::OPERATIONS . '/' . $kind . '/*.php');
        self::assertIsArray($files);
        $names = array_map(static fn(string $f): string => basename($f, '.php'), $files);
        sort($names);
        self::assertNotSame([], $names);

        return $names;
    }
}
