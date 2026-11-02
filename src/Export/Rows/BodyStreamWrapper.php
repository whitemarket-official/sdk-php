<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

/**
 * A PHP stream wrapper (`whitemarket-export-body://<id>`) that reads a {@see JsonArrayChunks}, so a parser that opens
 * only a path (`YajlStream\ArrayParser`) reads the export body through the SDK's reader: gzip, the `Content-Length`
 * and end-of-gzip checks, the array check and closing on `break` all stay in force, and nothing is written to disk.
 *
 * PHP creates the wrapper instance itself (`stream_open()` with the URL only), so the bodies are found through a
 * registry keyed by a random id: static state of this one class (DEVIATIONS), added by {@see register()} and removed
 * by {@see release()}. A failure while reading cannot cross the parser (an exception thrown inside a wrapper called
 * from an extension is not a defined path), so it is kept for the caller and the read reports an error;
 * {@see release()} hands it back.
 *
 * PHP sets a `context` property on every wrapper instance; it is allowed dynamically instead of declared, so the
 * class has no public mutable property.
 *
 * @internal used by {@see YajlStreamRows}
 */
#[\AllowDynamicProperties]
final class BodyStreamWrapper
{
    public const SCHEME = 'whitemarket-export-body';

    /** @var array<string, JsonArrayChunks> */
    private static array $bodies = [];

    /** @var array<string, \Throwable> */
    private static array $failures = [];

    private ?string $id = null;

    private ?JsonArrayChunks $chunks = null;

    private bool $started = false;

    private bool $eof = false;

    private string $pending = '';

    /**
     * Makes `$chunks` readable at the returned URL until {@see release()}.
     */
    public static function register(JsonArrayChunks $chunks): string
    {
        if (!\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        $id = bin2hex(random_bytes(16));
        self::$bodies[$id] = $chunks;

        return self::SCHEME . '://' . $id;
    }

    /**
     * Forgets the body of `$url`; returns the failure a read of it ran into, if any.
     */
    public static function release(string $url): ?\Throwable
    {
        $id = self::idOf($url);
        $failure = self::$failures[$id] ?? null;
        unset(self::$bodies[$id], self::$failures[$id]);

        return $failure;
    }

    public function stream_open(string $path, string $mode): bool
    {
        $id = self::idOf($path);
        if (!isset(self::$bodies[$id]) || !\in_array($mode, ['r', 'rb'], true)) {
            return false;
        }
        $this->id = $id;
        $this->chunks = self::$bodies[$id];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunks = $this->chunks;
        $id = $this->id;
        if (!$chunks instanceof JsonArrayChunks || $id === null) {
            return false;
        }
        try {
            while ($this->pending === '' && !$this->eof) {
                $this->pull($chunks);
            }
        } catch (\Throwable $e) {
            self::$failures[$id] = $e;
            $this->eof = true;

            return false;
        }
        $data = substr($this->pending, 0, $count);
        $this->pending = substr($this->pending, \strlen($data));

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->eof && $this->pending === '';
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        $this->chunks = null;
    }

    /**
     * @throws \Throwable whatever the body reader throws
     */
    private function pull(#[\SensitiveParameter] JsonArrayChunks $chunks): void
    {
        if ($this->started) {
            $chunks->next();
        } else {
            $this->started = true;
            $chunks->rewind();
        }
        if (!$chunks->valid()) {
            $this->eof = true;

            return;
        }
        $this->pending = $chunks->current();
    }

    private static function idOf(string $url): string
    {
        return substr($url, \strlen(self::SCHEME) + 3);
    }
}
