<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Fixtures\PHPStan;

use WhiteMarket\Sdk\Auth\Secret;

final class WithSensitiveConstructor
{
    public function __construct(public readonly string $label, #[\SensitiveParameter] public readonly string $token) {}
}

final class WithPlainConstructor
{
    public function __construct(public readonly string $label) {}
}

final class SecretTakers
{
    public function __construct(public Secret $promoted, #[\SensitiveParameter] public ?Secret $promotedMarked = null) {}

    public function plain(Secret $secret): void {}

    public function nullable(?Secret $secret): void {}

    public function union(Secret|string $secret): void {}

    public function marked(#[\SensitiveParameter] Secret $secret, #[\sensitiveparameter] ?Secret $other): void {}

    public function sensitiveClass(WithSensitiveConstructor $options): void {}

    public function sensitiveClassMarked(#[\SensitiveParameter] WithSensitiveConstructor $options): void {}

    public function plainClass(WithPlainConstructor $options, string $text, ?int $count): void {}

    public function unionWithNull(Secret|string|null $secret): void {}

    public function closures(): void
    {
        $closure = static function (Secret $secret): void {};
        $arrow = static fn(?Secret $secret): bool => $secret === null;
        $marked = static fn(#[\SensitiveParameter] Secret $secret): bool => true;
        $closure(new Secret('x'));
        $arrow(null);
        $marked(new Secret('x'));
    }
}

function takesSecret(Secret $secret): void {}

final class ConnectionTakers
{
    public function plain(\WhiteMarket\Sdk\Live\Connection $connection): void {}

    public function concrete(?\WhiteMarket\Sdk\Live\Sse\SseConnection $connection): void {}

    public function marked(#[\SensitiveParameter] \WhiteMarket\Sdk\Live\Connection $connection): void {}
}
