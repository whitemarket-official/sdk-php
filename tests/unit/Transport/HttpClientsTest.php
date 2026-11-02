<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\LoopbackServer;
use WhiteMarket\Sdk\Transport\HttpClients;
use WhiteMarket\Sdk\Transport\TransportOptions;

#[CoversClass(HttpClients::class)]
final class HttpClientsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: list<string>, 2?: bool}> */
    public static function connectionsClosedWithoutAResponse(): iterable
    {
        yield from LoopbackServer::connectionsClosedWithoutAResponse();
    }

    /** @param list<string> $bodies */
    #[Test]
    #[DataProvider('connectionsClosedWithoutAResponse')]
    public function withoutAnInjectedClientTheMutationClientSendsEachMutationOnce(string $mode, array $bodies, bool $bodyReadBeforeSending = false): void
    {
        $clients = HttpClients::from(null, new TransportOptions());

        $received = LoopbackServer::bodiesReceived($mode, $clients->mutation, $bodies, $bodyReadBeforeSending);

        self::assertSame(implode("\n", $bodies) . "\n", $received);
    }

    #[Test]
    public function withoutAnInjectedClientTheQueryClientMayResend(): void
    {
        $clients = HttpClients::from(null, new TransportOptions());

        $received = LoopbackServer::bodiesReceived('status-line-only', $clients->query, ['only']);

        // Control: the loopback server does provoke a resend, so the test above can tell the two clients apart.
        self::assertGreaterThan(1, substr_count($received, 'only'));
    }

    #[Test]
    public function anInjectedClientSendsBothQueriesAndMutations(): void
    {
        $client = new FakeHttpClient();

        $clients = HttpClients::from($client, new TransportOptions());

        self::assertSame([$client, $client], [$clients->query, $clients->mutation]);
    }
}
