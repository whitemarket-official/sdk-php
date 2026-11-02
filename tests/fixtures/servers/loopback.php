<?php

declare(strict_types=1);

/*
 * Synthetic loopback HTTP/1.1 server for transport tests. Prints its port on the first line of stdout, then serves
 * keep-alive responses. Every request received is appended to the log file given as the first argument. The second
 * argument picks how a request is answered:
 * - drop-second: request #2 is read in full and its connection closed without a reply, the way a server drops an idle
 *   keep-alive connection;
 * - status-line-only: every request gets only `HTTP/1.1 200 OK` and the connection is closed, so libcurl ends without
 *   an error code and without a response;
 * - redirect-elsewhere: every request is answered `307 Temporary Redirect` to a second listener addressed by its IP
 *   literal, another host than the `localhost` the client was given. A request that reaches the second listener is
 *   logged as `redirected` with whether it carried `Authorization` and `X-partner-token` (never their values).
 */

$usage = 'usage: loopback.php <log file> <drop-second|status-line-only|redirect-elsewhere>';
$log = $argv[1] ?? throw new RuntimeException($usage);
$mode = $argv[2] ?? throw new RuntimeException($usage);
if (!in_array($mode, ['drop-second', 'status-line-only', 'redirect-elsewhere'], true)) {
    throw new RuntimeException($usage);
}
$server = stream_socket_server('tcp://localhost:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException((string) $error);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, (int) strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

if ($mode === 'redirect-elsewhere') {
    $elsewhere = stream_socket_server('tcp://localhost:0', $errno, $error);
    if ($elsewhere === false) {
        throw new RuntimeException((string) $error);
    }
    // The socket name is `127.0.0.1:port` or `[::1]:port`: already a URL authority.
    $location = 'http://' . stream_socket_get_name($elsewhere, false) . '/elsewhere';
    while (true) {
        $ready = [$server, $elsewhere];
        $none = null;
        $changed = stream_select($ready, $none, $none, 30);
        if ($changed === false || $changed === 0) {
            exit(0);
        }
        foreach ($ready as $listener) {
            $connection = @stream_socket_accept($listener, 1);
            if ($connection === false) {
                continue;
            }
            $head = '';
            while (!str_contains($head, "\r\n\r\n")) {
                $byte = fread($connection, 1);
                if ($byte === '' || $byte === false) {
                    break;
                }
                $head .= $byte;
            }
            $length = preg_match('/^Content-Length: (\d+)/mi', $head, $match) === 1 ? (int) $match[1] : 0;
            $body = '';
            while (strlen($body) < $length && !feof($connection)) {
                $body .= (string) fread($connection, max(1, $length - strlen($body)));
            }
            if ($listener === $server) {
                file_put_contents($log, $body . "\n", \FILE_APPEND);
                fwrite($connection, "HTTP/1.1 307 Temporary Redirect\r\nLocation: {$location}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            } else {
                $carried = static fn(string $header): string => preg_match('/^' . $header . ':/mi', $head) === 1 ? 'yes' : 'no';
                file_put_contents($log, 'redirected authorization=' . $carried('Authorization') . ' x-partner-token=' . $carried('X-partner-token') . ' ' . $body . "\n", \FILE_APPEND);
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 11\r\nConnection: close\r\n\r\n{\"data\":{}}");
            }
            fclose($connection);
        }
    }
}

$count = 0;
while (($connection = @stream_socket_accept($server, 30)) !== false) {
    while (true) {
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $byte = fread($connection, 1);
            if ($byte === '' || $byte === false) {
                fclose($connection);

                continue 3;
            }
            $head .= $byte;
        }
        $length = preg_match('/^Content-Length: (\d+)/mi', $head, $match) === 1 ? (int) $match[1] : 0;
        $body = '';
        while (strlen($body) < $length) {
            $body .= (string) fread($connection, max(1, $length - strlen($body)));
        }
        ++$count;
        file_put_contents($log, $body . "\n", \FILE_APPEND);
        if ($mode === 'status-line-only') {
            fwrite($connection, "HTTP/1.1 200 OK\r\n");
            fclose($connection);

            continue 2;
        }
        if ($count === 2) { // drop-second: the mode was validated above
            fclose($connection);

            continue 2;
        }
        $response = '{"data":{}}';
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: keep-alive\r\n\r\n" . $response);
    }
}
