<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\Connection;
use WhiteMarket\Sdk\Live\ServerClosed;

/**
 * Like an open SSE connection, it keeps the token in its own state (a private property): any frame that receives this
 * object as an argument would show it in a trace. It connects, then the server closes it with a terminal code.
 */
final class TokenHoldingConnection implements Connection
{
    /** @var list<string> */
    private array $tokens = [];

    private string $heldToken = '';

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function open(#[\SensitiveParameter] Secret $token, array $channels, float $timeout): ConnectInfo
    {
        $this->heldToken = $token->reveal();
        $this->tokens[] = $this->heldToken;

        return new ConnectInfo(25, true, 86400, []);
    }

    public function read(float $timeout): ?string
    {
        if ($this->heldToken === '') {
            throw new TransportException('not open');
        }

        throw new ServerClosed(3600, 'terminal');
    }

    public function subscribesAfterConnect(): bool
    {
        return true;
    }

    public function subscribe(string $channel): void {}

    public function pong(): void {}

    public function close(): void {}
}
