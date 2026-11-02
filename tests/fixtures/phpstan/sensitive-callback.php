<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Fixtures\PHPStan;

final class CallbackUsers
{
    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function mapsSensitive(#[\SensitiveParameter] array $values): array
    {
        return array_map(strtoupper(...), $values);
    }

    public function replacesSensitive(#[\sensitiveparameter] string $text): ?string
    {
        $inner = static fn(string $value): ?string => preg_replace_callback('/a/', static fn(): string => 'b', $value);

        return $inner($text);
    }

    /**
     * @param list<int> $values
     */
    public function sortsSensitive(#[\SensitiveParameter] array $values): void
    {
        USORT($values, static fn(int $a, int $b): int => $a <=> $b);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function mapsPlain(array $values): array
    {
        return array_map(strtoupper(...), $values);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function loopsSensitive(#[\SensitiveParameter] array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $result[] = strtoupper($value);
        }

        return $result;
    }
}
