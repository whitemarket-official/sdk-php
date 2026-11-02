<?php

declare(strict_types=1);

/*
 * Synthetic Server-Sent Events server for live transport tests. Prints its port on the first line of stdout, serves
 * one connection and exits. The first argument picks the script:
 * - delayed: HTTP 200 `text/event-stream`, the connect reply at once, then it waits for the test to release it (a line
 *   on stdin, `FixtureServer::release()`), one event 3 s after the release, then the connection stays idle until the
 *   client leaves (at most 12 s). Counting from the release, not from the connect reply, keeps the timing tests stable
 *   when the runner stalls between the connect and the test's read;
 * - mid-event: the connect reply, then half an event, then the connection is closed;
 * - burst: the connect reply and three events in one write, then the connection is closed;
 * - chunked: like delayed, but `Transfer-Encoding: chunked` (the connect reply at once, one event after 2.5 s), the body
 *   framing a real reverse proxy uses.
 * Whatever the mode, the server gives up after 12 s, so a client that blocks cannot hang the test suite.
 */

$mode = $argv[1] ?? throw new RuntimeException('usage: sse.php <delayed|mid-event|burst|chunked>');
if (!in_array($mode, ['delayed', 'mid-event', 'burst', 'chunked'], true)) {
    throw new RuntimeException('usage: sse.php <delayed|mid-event|burst|chunked>');
}
$server = stream_socket_server('tcp://localhost:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException((string) $error);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, (int) strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$connection = @stream_socket_accept($server, 12);
if ($connection === false) {
    exit(1);
}
$head = '';
while (!str_contains($head, "\r\n\r\n")) {
    $byte = fread($connection, 1);
    if ($byte === '' || $byte === false) {
        exit(1);
    }
    $head .= $byte;
}

$connect = "data: {\"connect\":{\"client\":\"synthetic-client\",\"ping\":25,\"pong\":false,\"ttl\":86400,\"expires\":true,\"subs\":{}}}\n\n";
$event = "data: {\"channel\":\"synthetic-channel\",\"pub\":{\"data\":{\"message\":\"{}\"}}}\n\n";
$framing = $mode === 'chunked' ? "Transfer-Encoding: chunked\r\n" : '';
fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\n" . $framing . "Connection: close\r\n\r\n");
$send = static function (string $data) use ($connection, $mode): void {
    fwrite($connection, $mode === 'chunked' ? dechex(strlen($data)) . "\r\n" . $data . "\r\n" : $data);
};

if ($mode === 'burst') {
    fwrite($connection, $connect . $event . $event . $event);
    fclose($connection);
    exit(0);
}
$send($connect);
if ($mode === 'mid-event') {
    usleep(300_000);
    fwrite($connection, substr($event, 0, 20));
    usleep(300_000);
    fclose($connection);
    exit(0);
}

if ($mode === 'chunked') {
    usleep(2_500_000);
} else {
    $stdin = [STDIN];
    $none = null;
    if (stream_select($stdin, $none, $none, 12) !== 1 || fgets(STDIN) === false) {
        exit(1);
    }
    sleep(3);
}
$send($event);
// Hold the connection open until the client closes it, with a hard limit.
$until = time() + 9;
while (time() < $until && !feof($connection)) {
    usleep(100_000);
}
fclose($connection);
