<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Generated;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Enum\AchievementParamType;
use WhiteMarket\Sdk\Generated\Enum\AchievementStatusType;
use WhiteMarket\Sdk\Generated\Enum\DeliveryPromise;
use WhiteMarket\Sdk\Generated\Fragment\AuthTokensFields;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\ForwardPaginationInput;
use WhiteMarket\Sdk\Generated\Operation\Query\StoreMy\AchievementsParamsAchievementBooleanParam;
use WhiteMarket\Sdk\Generated\Operation\Query\StoreMy\AchievementsParamsAchievementStringParam;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\GraphQL\UnknownTypeValue;
use WhiteMarket\Sdk\Tests\Support\FakeOperationExecutor;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

/**
 * Codegen smoke test on the real schema (plan T1.4): a connection query, a union query with aliases (the `store_my`
 * override) and a mutation, through the fake executor. Responses are synthetic.
 *
 * Generated code is excluded from coverage, so this test covers nothing by attribute.
 */
#[CoversNothing]
final class GeneratedClientTest extends TestCase
{
    private const CREDENTIAL = 'synthetic-credential-4b1d';

    #[Test]
    public function connectionQuery(): void
    {
        $executor = new FakeOperationExecutor(['order_list' => [
            'edges' => [['cursor' => 'YXJyYXljb25uZWN0aW9uOjA=', 'node' => ['__typename' => 'MarketOrderPublicSteam']]],
            'pageInfo' => ['endCursor' => 'YXJyYXljb25uZWN0aW9uOjA=', 'hasNextPage' => true, 'hasPreviousPage' => false, 'startCursor' => null],
            'totalCount' => 501,
        ]]);

        $result = (new GeneratedClient($executor))->orderList(forwardPagination: new ForwardPaginationInput(first: 1));

        self::assertSame(501, $result->totalCount);
        self::assertNotNull($result->pageInfo);
        self::assertTrue($result->pageInfo->hasNextPage);
        self::assertNull($result->pageInfo->startCursor);
        $edge = $result->edges[0] ?? null;
        self::assertNotNull($edge);
        self::assertSame('YXJyYXljb25uZWN0aW9uOjA=', $edge->cursor);
        self::assertInstanceOf(UnknownTypeValue::class, $edge->node, 'the schema hides every MarketOrderPublic implementation');
        self::assertSame('MarketOrderPublicSteam', $edge->node->typename);

        $call = $executor->lastCall();
        self::assertSame(['OrderList', OperationType::Query], [$call['operationName'], $call['type']]);
        self::assertSame(['forwardPagination' => ['first' => 1]], $call['variables']);
    }

    #[Test]
    public function unionQueryWithAliasedMembers(): void
    {
        $executor = new FakeOperationExecutor(['store_my' => [
            'avatar' => null,
            'banner' => null,
            'customAvatar' => null,
            'deliveryPromise' => 'HOUR1',
            'id' => '1ee4bd8c-e669-695e-9ba4-0242ac1d0008',
            'isAchievementsPublic' => true,
            'isFavoritesPublic' => false,
            'isItemsOnSalePublic' => true,
            'isKycVerified' => false,
            'isStoreNamePublic' => true,
            'isTopSeller' => false,
            'isTradeHistoryPublic' => false,
            'leftPoints' => 10,
            'level' => 2,
            'points' => 90,
            'registeredAt' => '2023-11-29T23:33:27+0000',
            'slug' => 'example-store',
            'steamAvatar' => null,
            'storeName' => 'Example store',
            'years' => 1,
            'achievements' => [[
                'name' => 'COMBO_WOMBO',
                'points' => 5,
                'status' => 'IN_PROGRESS',
                'type' => 'CHECKLIST',
                'unlockedAt' => null,
                'params' => [
                    ['__typename' => 'AchievementBooleanParam', 'name' => 'CONDITION_BUY_KNIFE', 'booleanValue' => true],
                    ['__typename' => 'AchievementStringParam', 'name' => 'CONDITION_BUY_GLOVES', 'stringValue' => '3/5'],
                ],
            ]],
        ]]);

        $store = (new GeneratedClient($executor))->storeMy();

        self::assertSame('Example store', $store->storeName);
        self::assertSame(DeliveryPromise::HOUR1, $store->deliveryPromise);
        $achievement = $store->achievements[0] ?? null;
        self::assertNotNull($achievement);
        self::assertSame(AchievementStatusType::IN_PROGRESS, $achievement->status);
        $params = $achievement->params ?? [];
        $boolean = $params[0] ?? null;
        $string = $params[1] ?? null;
        self::assertInstanceOf(AchievementsParamsAchievementBooleanParam::class, $boolean);
        self::assertSame(AchievementParamType::CONDITION_BUY_KNIFE, $boolean->name);
        self::assertTrue($boolean->booleanValue);
        self::assertInstanceOf(AchievementsParamsAchievementStringParam::class, $string);
        self::assertSame('3/5', $string->stringValue);
        self::assertSame([], $executor->lastCall()['variables']);
    }

    #[Test]
    public function rawDocumentRunsAsAQueryOnlyWhenItIsClearlyOne(): void
    {
        $executor = new FakeOperationExecutor(['test_ping' => 'pong'], ['auth_token' => []]);
        $client = new GeneratedClient($executor);

        self::assertSame(['test_ping' => 'pong'], $client->execute('query Ping { test_ping }'));
        self::assertSame(['Ping', OperationType::Query, []], [$executor->lastCall()['operationName'], $executor->lastCall()['type'], $executor->lastCall()['variables']]);

        $client->execute('query A { test_ping } mutation B { auth_token { accessToken } }', ['x' => 1], 'B');
        self::assertSame(['B', OperationType::Mutation, ['x' => 1]], [$executor->lastCall()['operationName'], $executor->lastCall()['type'], $executor->lastCall()['variables']]);
    }

    #[Test]
    public function mutation(): void
    {
        $executor = new FakeOperationExecutor(['auth_token' => ['accessToken' => 'synthetic-access-token', 'isNewPerson' => false]]);

        $tokens = (new GeneratedClient($executor))->authToken();

        self::assertSame('synthetic-access-token', $tokens->accessToken->reveal());
        self::assertSame('***', (string) $tokens->accessToken, 'a credential field is a Secret');
        self::assertFalse($tokens->isNewPerson);
        $call = $executor->lastCall();
        self::assertSame(['AuthToken', OperationType::Mutation], [$call['operationName'], $call['type']]);
        self::assertStringStartsWith('mutation AuthToken {', $call['document']);
        self::assertStringContainsString('fragment AuthTokensFields on AuthTokens', $call['document']);
    }

    /**
     * @return iterable<string, array{\Closure(GeneratedClient): mixed, array<mixed>}>
     */
    public static function malformedResponsesNextToACredential(): iterable
    {
        yield 'object: bad sibling of accessToken' => [
            static fn(GeneratedClient $client): mixed => $client->authToken(),
            ['auth_token' => ['accessToken' => self::CREDENTIAL, 'isNewPerson' => 'nope']],
        ];
        yield 'list: bad sibling of airwallexSecret' => [
            static fn(GeneratedClient $client): mixed => $client->walletDeposits(),
            ['wallet_deposits' => [['airwallexSecret' => self::CREDENTIAL, 'comment' => 5]]],
        ];
    }

    /**
     * @param \Closure(GeneratedClient): mixed $call
     * @param array<mixed> $response
     */
    #[Test]
    #[DataProvider('malformedResponsesNextToACredential')]
    public function decodeErrorKeepsTheCredentialOutOfTheTrace(\Closure $call, array $response): void
    {
        $client = new GeneratedClient(new FakeOperationExecutor($response));

        $e = TraceArguments::caught(static fn() => $call($client));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringContainsString('expected a', $e->getMessage(), 'the malformed sibling, not the credential, failed');
        self::assertStringNotContainsString(self::CREDENTIAL, TraceArguments::exportedSdkFrames($e));
    }

    #[Test]
    public function executorErrorKeepsCredentialVariablesOutOfTheTrace(): void
    {
        $client = new GeneratedClient(new FakeOperationExecutor());

        $e = TraceArguments::caught(static fn(): AuthTokensFields => $client->authSteamJwt(self::CREDENTIAL));

        self::assertInstanceOf(\LogicException::class, $e, 'the fake throws when nothing is queued');
        self::assertStringNotContainsString(self::CREDENTIAL, TraceArguments::exportedSdkFrames($e));
    }
}
