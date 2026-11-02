<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Fixtures\PHPStan;

interface Sink
{
    public function write(string $label, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] string $token): void;
}

abstract class BaseSink
{
    abstract public function flush(#[\SensitiveParameter] string $buffer): void;

    public function plain(string $text): void {}
}

final class MarkedSink implements Sink
{
    public function write(string $label, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] string $token): void {}
}

final class UnmarkedSink implements Sink
{
    public function write(
        string $label,
        array $payload,
        #[\SensitiveParameter]
        string $token,
    ): void {}
}

final class UnmarkedSubclass extends BaseSink
{
    public function flush(string $buffer): void {}

    public function plain(string $text): void {}
}

final class WithoutAncestor
{
    public function write(string $label, array $payload, string $token): void {}
}

interface Flushable
{
    public function flush(#[\SensitiveParameter] string $buffer): void;
}

final class UnmarkedOnTwoAncestors extends BaseSink implements Flushable
{
    public function flush(string $buffer): void {}
}

interface VariadicSink
{
    public function writeAll(#[\SensitiveParameter] string ...$parts): void;
}

final class MarkedExtraVariadic implements VariadicSink
{
    public function writeAll(#[\SensitiveParameter] string $first = '', #[\SensitiveParameter] string ...$rest): void {}
}

final class UnmarkedExtraVariadic implements VariadicSink
{
    public function writeAll(#[\SensitiveParameter] string $first = '', string ...$rest): void {}
}

interface Executor
{
    public function run(string $operation, #[\SensitiveParameter] string $token): void;
}

final class UnmarkedVariadicChild implements Executor
{
    public function run(string ...$arguments): void {}
}

final class MarkedVariadicChild implements Executor
{
    public function run(#[\SensitiveParameter] string ...$arguments): void {}
}

abstract class TokenHolder
{
    public function __construct(#[\SensitiveParameter] public string $token) {}

    private function hidden(#[\SensitiveParameter] string $secret): void {}
}

final class UnrelatedConstructor extends TokenHolder
{
    public function __construct(public int $code, #[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
    }

    public function hidden(string $text): void {}
}

abstract class AbstractConstructed
{
    abstract public function __construct(#[\SensitiveParameter] string $token);
}

final class UnmarkedAbstractConstructor extends AbstractConstructed
{
    public function __construct(string $token) {}
}

final class LowercaseAttribute implements Sink
{
    public function write(string $label, #[\sensitiveparameter] array $payload, #[\SensitiveParameter] string $token): void {}
}
