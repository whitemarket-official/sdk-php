<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use Psr\SimpleCache\CacheInterface;
use WhiteMarket\Sdk\Transport\Clock;

/**
 * In-memory PSR-16 cache with TTLs measured on an injected clock; entries can be written raw to simulate what another
 * process or SDK version left behind.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{mixed, ?int}> key => [value, expires at (unix seconds) or null] */
    private array $entries = [];

    public function __construct(private readonly Clock $clock = new FrozenClock()) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? ($this->entries[$key][0] ?? $default) : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $now = $this->clock->now();
        $expiresAt = match (true) {
            $ttl === null => null,
            $ttl instanceof \DateInterval => $now->add($ttl)->getTimestamp(),
            default => $now->getTimestamp() + $ttl,
        };
        $this->entries[$key] = [$value, $expiresAt];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set(\is_string($key) ? $key : throw new \LogicException('PSR-16 keys are strings'), $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $entry = $this->entries[$key] ?? null;

        return $entry !== null && ($entry[1] === null || $entry[1] > $this->clock->now()->getTimestamp());
    }

    /**
     * @return array<string, array{mixed, ?int}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
