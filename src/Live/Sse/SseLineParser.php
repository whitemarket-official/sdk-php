<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Sse;

use WhiteMarket\Sdk\Errors\TransportException;

/**
 * Incremental parser of a `text/event-stream` body. Feed raw chunks (split anywhere, also inside "\r\n"), take the
 * complete events with {@see next()}. Only `data:` fields are used; comments, `event:`, `id:`, `retry:` and unknown
 * fields are ignored. Several `data:` lines of one event are joined with "\n". An event still open when the stream
 * ends is dropped, as the SSE specification says.
 *
 * `$maxMessageBytes` caps the joined data of one event. A raw line may be at most 6 bytes longer (`data:` and one
 * space), so a line is never buffered past the cap plus that overhead; a violation throws before more is buffered.
 * Events wait in a queue only until the caller takes them, so memory does not grow with the event count.
 *
 * @internal
 */
final class SseLineParser
{
    private const LINE_OVERHEAD = 6;

    private string $line = '';

    private string $data = '';

    private bool $hasData = false;

    private bool $skipLineFeed = false;

    /** @var \SplQueue<string> */
    private readonly \SplQueue $events;

    public function __construct(private readonly int $maxMessageBytes)
    {
        if ($maxMessageBytes < 1) {
            throw new \InvalidArgumentException('maxMessageBytes must be at least 1.');
        }
        $this->events = new \SplQueue();
    }

    /**
     * @throws TransportException when a line or an event exceeds the cap
     */
    public function feed(string $chunk): void
    {
        $length = \strlen($chunk);
        $pos = 0;
        if ($this->skipLineFeed && $length > 0) {
            $this->skipLineFeed = false;
            if ($chunk[0] === "\n") {
                $pos = 1;
            }
        }

        while ($pos < $length) {
            $run = strcspn($chunk, "\r\n", $pos);
            $this->appendToLine($chunk, $pos, $run);
            $pos += $run;
            if ($pos >= $length) {
                return;
            }

            $this->processLine();
            $terminator = $chunk[$pos];
            ++$pos;
            if ($terminator === "\r") {
                if ($pos < $length) {
                    if ($chunk[$pos] === "\n") {
                        ++$pos;
                    }
                } else {
                    $this->skipLineFeed = true;
                }
            }
        }
    }

    /**
     * The data of the next complete event, or null when none is complete yet.
     */
    public function next(): ?string
    {
        return $this->events->isEmpty() ? null : $this->events->dequeue();
    }

    private function appendToLine(string $chunk, int $pos, int $run): void
    {
        if (\strlen($this->line) + $run > $this->maxMessageBytes + self::LINE_OVERHEAD) {
            throw new TransportException('A live event line exceeds the size limit.');
        }
        $this->line .= substr($chunk, $pos, $run);
    }

    private function processLine(): void
    {
        $line = $this->line;
        $this->line = '';

        if ($line === '') {
            if ($this->hasData) {
                $this->events->enqueue($this->data);
                $this->data = '';
                $this->hasData = false;
            }

            return;
        }

        $colon = strpos($line, ':');
        if ($colon === 0) {
            return;
        }
        $field = $colon === false ? $line : substr($line, 0, $colon);
        if ($field !== 'data') {
            return;
        }

        $value = $colon === false ? '' : substr($line, $colon + 1);
        if ($value !== '' && $value[0] === ' ') {
            $value = substr($value, 1);
        }

        $size = \strlen($this->data) + ($this->hasData ? 1 : 0) + \strlen($value);
        if ($size > $this->maxMessageBytes) {
            throw new TransportException('A live event exceeds the size limit.');
        }
        $this->data = $this->hasData ? $this->data . "\n" . $value : $value;
        $this->hasData = true;
    }
}
