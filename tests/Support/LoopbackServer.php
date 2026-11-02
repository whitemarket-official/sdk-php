<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;

/**
 * Runs `tests/fixtures/servers/loopback.php` in a child process for the duration of one test and reports the bodies of
 * the requests it received, one per line: how often a real client sent a request over a real socket.
 */
final class LoopbackServer
{
    private function __construct() {}

    /**
     * Ways a server can close the connection without a response after it read a request: each one makes a client
     * that resends send the same request again. Arguments of {@see bodiesReceived()} after the client.
     *
     * @return iterable<string, array{0: string, 1: list<string>, 2?: bool}>
     */
    public static function connectionsClosedWithoutAResponse(): iterable
    {
        // The server reads request #2 in full and closes the connection: it may have run the mutation.
        yield 'reused connection closed before any response byte' => ['drop-second', ['first', 'second']];
        yield 'status line only, then close' => ['status-line-only', ['only']];
        yield 'status line only, body already read before sending' => ['status-line-only', ['only'], true];
    }

    /**
     * Sends each of `$bodies` with `$client` to a server in `$mode` and returns the request log of the server.
     *
     * @param list<string> $bodies
     * @param array<string, string> $headers sent with every request
     */
    public static function bodiesReceived(string $mode, ClientInterface $client, array $bodies, bool $bodyReadBeforeSending = false, array $headers = []): string
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'wm-requests-');
        $server = proc_open([\PHP_BINARY, __DIR__ . '/../fixtures/servers/loopback.php', $log, $mode], [1 => ['pipe', 'w']], $pipes);
        if (!\is_resource($server)) {
            throw new \RuntimeException('The loopback server did not start.');
        }
        try {
            $stdout = $pipes[1] ?? throw new \RuntimeException('The loopback server has no stdout.');
            $url = 'http://localhost:' . trim((string) fgets($stdout)) . '/graphql/partner';
            foreach ($bodies as $body) {
                self::send($client, $url, $body, $bodyReadBeforeSending, $headers);
            }

            return (string) file_get_contents($log);
        } finally {
            proc_terminate($server);
            proc_close($server);
            unlink($log);
        }
    }

    /** @param array<string, string> $headers */
    private static function send(ClientInterface $client, string $url, string $body, bool $bodyReadBeforeSending, array $headers): void
    {
        $factory = GuzzleClientFactory::messageFactory();
        $stream = $factory->createStream($body);
        if ($bodyReadBeforeSending) {
            $stream->getContents();
        }
        $request = $factory->createRequest('POST', $url)->withBody($stream);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        try {
            $client->sendRequest($request);
        } catch (ClientExceptionInterface) {
            // A dropped connection is a network error; what counts is how often the server received the request.
        }
    }
}
