<?php

declare(strict_types=1);

/*
 * Synthetic Centrifugo-like WebSocket server for live transport tests (phrity Server, `ws://localhost`). Prints its port on
 * the first line of stdout, serves one client and exits; whatever happens it gives up after 12 s. Usage:
 * websocket.php <scenario> [size]. After the handshake every scenario but `reject` reads the connect command, checks its
 * shape and the User-Agent header (a mismatch closes with code 4999) and answers the connect reply (ping 25, pong true,
 * `#42` subscribed server-side), except where noted:
 * - hello: nothing more; the connection stays open for 1.5 s;
 * - delayed: the text `late` 2.5 s after the connect reply;
 * - multi: one frame with three messages and a trailing "\n";
 * - burst: 1000 text frames `{"n":<i>}` in one write;
 * - ping-frame: a ping frame, then `ok` when a pong frame came back, else close 4998;
 * - json-ping: the text `{}`, then `ok` when `{}` came back, else close 4998;
 * - close3501 / close4501: a close frame with that code and the reason `bye`; close-ctrl: reason with control bytes;
 * - oversize: one text frame of <size> bytes (default 2000000);
 * - eof: the text `one`, then the TCP connection is closed without a close frame;
 * - binary: a binary frame;
 * - subscribe: expects the subscribe command id 2 for `market_products_updates`, answers error 105; then expects id 3 for
 *   `late-channel` and answers an empty subscribe result; a different command closes with 4999;
 * - connect-error: answers the connect command with error 109;
 * - not-connect: answers the connect command with `{}`;
 * - silent: reads the connect command and answers nothing;
 * - reject: no WebSocket: answers the upgrade request with HTTP 403;
 * - trickle: after the connect reply a text frame header announcing 64 bytes, then one byte every 0.5 s (until 12 s);
 * - flood-pong / flood-empty-text / flood-ping: after the connect reply, complete tiny frames (pong, empty text `\n`, ping)
 *   written in large chunks for 6 s; the server never reads;
 * - ping-flood-no-read: pings flooded for 12 s, never read;
 * - no-read: the connect reply, then the server neither reads nor writes for 12 s (the client's send buffer fills);
 * - zero-byte: after the connect reply the text frame `{"n":1004}` one byte at a time (a lone "0" arrives in its own
 *   read), then `{"n":1005}` in one write;
 * - trickle-handshake: no WebSocket: the upgrade response (101 status line, then an endless header) at one byte every
 *   0.5 s (until 12 s).
 */

use Psr\Http\Message\RequestInterface;
use WebSocket\Configuration;
use WebSocket\Connection;
use WebSocket\Message\Binary;
use WebSocket\Message\Close;
use WebSocket\Message\Ping;
use WebSocket\Message\Pong;
use WebSocket\Message\Text;
use WebSocket\Server;

require __DIR__ . '/../../../vendor/autoload.php';

$scenario = $argv[1] ?? throw new RuntimeException('usage: websocket.php <scenario> [size]');
$size = (int) ($argv[2] ?? 2_000_000);
$started = microtime(true);

$probe = stream_socket_server('tcp://localhost:0', $errno, $error);
if ($probe === false) {
    throw new RuntimeException((string) $error);
}
$name = (string) stream_socket_get_name($probe, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);

if ($scenario === 'reject') {
    echo $port, "\n";
    fflush(STDOUT);
    $connection = @stream_socket_accept($probe, 12);
    if ($connection !== false) {
        fread($connection, 4096);
        fwrite($connection, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        sleep(1);
    }
    exit(0);
}
if ($scenario === 'trickle-handshake') {
    echo $port, "\n";
    fflush(STDOUT);
    $connection = @stream_socket_accept($probe, 12);
    if ($connection !== false) {
        fread($connection, 4096);
        $bytes = "HTTP/1.1 101 Switching Protocols\r\nX-Pad: " . str_repeat('a', 100);
        for ($i = 0; $i < strlen($bytes) && microtime(true) - $started < 12; ++$i) {
            if (@fwrite($connection, $bytes[$i]) === false) {
                break;
            }
            usleep(500_000);
        }
    }
    exit(0);
}
fclose($probe);

/** One RFC 6455 frame from the server, unmasked. */
function frame(string $payload, int $opcode = 1): string
{
    $length = strlen($payload);
    $head = chr(0x80 | $opcode);
    if ($length < 126) {
        $head .= chr($length);
    } elseif ($length < 65536) {
        $head .= chr(126) . pack('n', $length);
    } else {
        $head .= chr(127) . pack('J', $length);
    }

    return $head . $payload;
}

/** All bytes in one write, the way a busy server fills a TCP segment. */
function writeRaw(Connection $conn, string $bytes): void
{
    try {
        $conn->getStream()->write($bytes);
    } catch (Throwable) {
        // The client left.
    }
}

const CONNECT_REPLY = '{"id":1,"connect":{"client":"synthetic-client","version":"synthetic","expires":true,"ttl":86400,"ping":25,"pong":true,"subs":{"#42":{}}}}';

$server = new Server($port, false, new Configuration(timeout: 1.5));
$server->onTick(function (Server $server) use ($started): void {
    if (microtime(true) - $started > 12) {
        $server->stop();
    }
});
function serve(string $scenario, int $size, float $started, Connection $conn, RequestInterface $request): void
{
    $fail = static function (string $reason) use ($conn): void {
        $conn->send(new Close(4999, $reason));
        sleep(1);
    };
    $pull = static function () use ($conn): ?Text {
        try {
            $message = $conn->pullMessage();
        } catch (Throwable) {
            return null;
        }

        return $message instanceof Text ? $message : null;
    };

    $command = $pull();
    $decoded = $command instanceof Text ? json_decode($command->getContent(), true) : null;
    $connect = is_array($decoded) && is_array($decoded['connect'] ?? null) ? $decoded['connect'] : [];
    $token = $connect['token'] ?? null;
    if (
        !is_array($decoded)
        || ($decoded['id'] ?? null) !== 1
        || !is_string($token)
        || $token === ''
        || ($connect['name'] ?? null) !== 'whitemarket-sdk-php'
        || !str_starts_with($request->getHeaderLine('User-Agent'), 'whitemarket-sdk-php/')
    ) {
        $fail('bad-connect-command');

        return;
    }

    switch ($scenario) {
        case 'silent':
            sleep(2);

            return;
        case 'connect-error':
            $conn->send(new Text('{"id":1,"error":{"code":109,"message":"token expired","temporary":false}}'));
            sleep(1);

            return;
        case 'not-connect':
            $conn->send(new Text('{}'));
            sleep(1);

            return;
    }

    $conn->send(new Text(CONNECT_REPLY));

    switch ($scenario) {
        case 'hello':
            usleep(1_500_000);
            break;
        case 'delayed':
            usleep(2_500_000);
            $conn->send(new Text('late'));
            sleep(1);
            break;
        case 'multi':
            $conn->send(new Text("{\"n\":1}\n{\"n\":2}\n{\"n\":3}\n"));
            sleep(1);
            break;
        case 'burst':
            $buffer = '';
            for ($i = 0; $i < 1000; ++$i) {
                $buffer .= frame('{"n":' . $i . '}');
            }
            writeRaw($conn, $buffer);
            sleep(2);
            break;
        case 'ping-frame':
            $conn->send(new Ping('pp'));
            try {
                $answer = $conn->pullMessage();
            } catch (Throwable) {
                $answer = null;
            }
            if ($answer instanceof Pong && $answer->getContent() === 'pp') {
                $conn->send(new Text('ok'));
                sleep(1);
            } else {
                $fail('no-pong');
            }
            break;
        case 'json-ping':
            $conn->send(new Text('{}'));
            $answer = $pull();
            if ($answer instanceof Text && $answer->getContent() === '{}') {
                $conn->send(new Text('ok'));
                sleep(1);
            } else {
                $fail('no-json-pong');
            }
            break;
        case 'close3501':
            $conn->send(new Close(3501, 'bye'));
            sleep(1);
            break;
        case 'close4501':
            $conn->send(new Close(4501, 'bye'));
            sleep(1);
            break;
        case 'close-ctrl':
            $conn->send(new Close(3000, "a\x00b\x1b[31m\xff" . str_repeat('z', 200)));
            sleep(1);
            break;
        case 'oversize':
            writeRaw($conn, frame(str_repeat('x', $size)));
            sleep(2);
            break;
        case 'eof':
            $conn->send(new Text('one'));
            $resource = $conn->getStream()->getResource();
            if (is_resource($resource)) {
                fclose($resource);
            }
            break;
        case 'binary':
            $conn->send(new Binary('bin'));
            sleep(1);
            break;
        case 'trickle':
            foreach (str_split("\x81\x40" . str_repeat('x', 64)) as $byte) {
                if (microtime(true) - $started > 12) {
                    break;
                }
                writeRaw($conn, $byte);
                usleep(500_000);
            }
            break;
        case 'flood-pong':
        case 'flood-empty-text':
        case 'flood-ping':
            $chunk = str_repeat(match ($scenario) {
                'flood-pong' => "\x8A\x00",
                'flood-empty-text' => "\x81\x01\n",
                default => "\x89\x00",
            }, 500);
            while (microtime(true) - $started < 6) {
                writeRaw($conn, $chunk);
                usleep(1000);
            }
            break;
        case 'ping-flood-no-read':
            $chunk = str_repeat("\x89\x00", 500);
            while (microtime(true) - $started < 12) {
                writeRaw($conn, $chunk);
                usleep(1000);
            }
            break;
        case 'no-read':
            sleep(12);
            break;
        case 'zero-byte':
            foreach (str_split(frame('{"n":1004}')) as $byte) {
                writeRaw($conn, $byte);
                usleep(5000);
            }
            writeRaw($conn, frame('{"n":1005}'));
            sleep(1);
            break;
        case 'subscribe':
            foreach ([[2, 'market_products_updates', '{"id":2,"error":{"code":105,"message":"already subscribed","temporary":false}}'], [3, 'late-channel', '{"id":3,"subscribe":{}}']] as [$id, $channel, $answer]) {
                $command = $pull();
                $decoded = $command instanceof Text ? json_decode($command->getContent(), true) : null;
                $subscribe = is_array($decoded) && is_array($decoded['subscribe'] ?? null) ? $decoded['subscribe'] : [];
                if (!is_array($decoded) || ($decoded['id'] ?? null) !== $id || ($subscribe['channel'] ?? null) !== $channel) {
                    $fail('bad-subscribe-command');

                    return;
                }
                $conn->send(new Text($answer));
            }
            sleep(1);
            break;
    }
}

$server->onHandshake(static function (Server $server, Connection $conn, RequestInterface $request) use ($scenario, $size, $started): void {
    try {
        serve($scenario, $size, $started, $conn, $request);
    } catch (Throwable) {
        // The client left; the scenario is over.
    }
});

echo $port, "\n";
fflush(STDOUT);
$server->start();
