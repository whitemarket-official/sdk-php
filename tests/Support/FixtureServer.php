<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

/**
 * Runs one of the synthetic servers of `tests/fixtures/servers/` (`websocket`, `sse`) in a child process for one test.
 * The scenarios and the extra arguments are described in the fixture.
 */
final class FixtureServer
{
    /**
     * @param resource $process
     * @param resource $stdin
     */
    private function __construct(private $process, private $stdin, public readonly int $port) {}

    /**
     * @param list<string> $args the scenario and whatever else the fixture reads from its command line
     */
    public static function start(string $fixture, array $args): self
    {
        $process = proc_open([\PHP_BINARY, __DIR__ . '/../fixtures/servers/' . $fixture . '.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException(\sprintf('The %s fixture server did not start.', $fixture));
        }
        $stdin = $pipes[0] ?? throw new \RuntimeException(\sprintf('The %s fixture has no stdin.', $fixture));
        $stdout = $pipes[1] ?? throw new \RuntimeException(\sprintf('The %s fixture has no stdout.', $fixture));
        $port = (int) trim((string) fgets($stdout));
        if ($port <= 0) {
            proc_terminate($process);
            proc_close($process);

            throw new \RuntimeException(\sprintf('The %s fixture reported no port.', $fixture));
        }
        // The server prints the port just before it listens.
        usleep(200_000);

        return new self($process, $stdin, $port);
    }

    public function baseUrl(): string
    {
        return 'http://localhost:' . $this->port;
    }

    /**
     * Lets a scenario that waits for the test go on (a line on the fixture's stdin), so the scenario's delays count
     * from this call and not from a moment the test does not control (a slow CI runner stalls between steps).
     */
    public function release(): void
    {
        fwrite($this->stdin, "release\n");
        fflush($this->stdin);
    }

    public function stop(): void
    {
        if (\is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
