<?php

declare(strict_types=1);

/*
 * Synthetic loopback HTTP/1.1 server for transport tests. Prints its port on the first line of stdout, then serves
 * keep-alive responses. Every request received is appended to the log file given as the first argument. The second
 * argument picks how a request is answered:
 * - drop-second: request #2 is read in full and its connection closed without a reply, the way a server drops an idle
 *   keep-alive connection;
 * - status-line-only: every request gets only `HTTP/1.1 200 OK` and the connection is closed, so libcurl ends without
 *   an error code and without a response.
 */

$usage = 'usage: loopback.php <log file> <drop-second|status-line-only>';
$log = $argv[1] ?? throw new RuntimeException($usage);
$mode = $argv[2] ?? throw new RuntimeException($usage);
if (!in_array($mode, ['drop-second', 'status-line-only'], true)) {
    throw new RuntimeException($usage);
}
$server = stream_socket_server('tcp://localhost:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException((string) $error);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, (int) strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

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
