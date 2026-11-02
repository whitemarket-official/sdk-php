<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\TokenProvider;

/**
 * A token provider that counts its calls. `accessToken()` returns the current synthetic JWT; `refresh()` makes a new
 * one (`$renews` false: it keeps returning the current one, like a provider that cannot renew).
 */
final class ScriptedTokens implements TokenProvider
{
    private int $accessCalls = 0;

    private int $refreshCalls = 0;

    /** @var list<\Throwable> thrown by the next calls of accessToken() or refresh(), in order */
    private array $failures = [];

    private string $current;

    public function __construct(private readonly bool $renews = true)
    {
        $this->current = SyntheticJwt::withClaims(['sub' => 'synthetic-subject', 'n' => 0]);
    }

    public function accessToken(): Secret
    {
        ++$this->accessCalls;
        $this->failNext();

        return new Secret($this->current);
    }

    public function refresh(): Secret
    {
        ++$this->refreshCalls;
        $this->failNext();
        if ($this->renews) {
            $this->current = SyntheticJwt::withClaims(['sub' => 'synthetic-subject', 'n' => $this->refreshCalls]);
        }

        return new Secret($this->current);
    }

    private function failNext(): void
    {
        $failure = array_shift($this->failures);
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }

    public function refreshCalls(): int
    {
        return $this->refreshCalls;
    }

    public function accessCalls(): int
    {
        return $this->accessCalls;
    }

    /**
     * The next call of `accessToken()` or `refresh()` throws `$failure`.
     */
    public function failNextWith(\Throwable $failure): void
    {
        $this->failures[] = $failure;
    }

    public function current(): string
    {
        return $this->current;
    }
}
