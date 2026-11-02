<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Live\Protocol\Publication;

/**
 * The synthetic live messages of `tests/fixtures/live`.
 */
final class LiveFixture
{
    private function __construct() {}

    /**
     * The file as it is (one raw envelope, or a pretty-printed `{type, content}` message).
     */
    public static function raw(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/live/' . $name);
    }

    /**
     * A `{type, content}` message file, decoded.
     *
     * @return array{type: string, content: array<string, mixed>}
     */
    public static function message(string $name): array
    {
        $message = json_decode(self::raw($name), true, 64, \JSON_THROW_ON_ERROR);
        \assert(\is_array($message) && \is_string($message['type'] ?? null) && \is_array($message['content'] ?? null));

        /** @var array{type: string, content: array<string, mixed>} $message */
        return $message;
    }

    /**
     * A publication as the server sends it: `message` is the JSON-encoded string.
     *
     * @param array<array-key, mixed> $message
     */
    public static function publication(array $message, string $channel = 'market_products_updates'): Publication
    {
        return new Publication($channel, ['message' => json_encode($message, \JSON_THROW_ON_ERROR)]);
    }

    /**
     * A fixture message with some content keys replaced.
     *
     * @param array<string, mixed> $content
     *
     * @return array{type: string, content: array<string, mixed>}
     */
    public static function with(string $fixture, array $content): array
    {
        $message = self::message($fixture);
        $message['content'] = $content + $message['content'];

        return $message;
    }

    /**
     * A message without some content keys.
     *
     * @param array{type: string, content: array<string, mixed>} $message
     * @param list<string> $drop
     *
     * @return array{type: string, content: array<string, mixed>}
     */
    public static function without(array $message, array $drop): array
    {
        $message['content'] = array_diff_key($message['content'], array_flip($drop));

        return $message;
    }
}
