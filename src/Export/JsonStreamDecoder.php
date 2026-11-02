<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use YajlStream\ArrayParser;

/**
 * The parser {@see ExportClient::streamProducts()} reads a JSON products file with (ADR 0006). Every one keeps one
 * row in memory at a time and gives the same rows, with one difference: `YajlStream` turns an integer beyond the
 * 64-bit range into a float, so a row with such an id fails to decode instead of keeping it as a digit string.
 *
 * - `Auto` (the default): `YajlStream` when `ext-yajl_stream` is loaded, else `Simdjson` when `ext-simdjson` is,
 *   else `JsonMachine`.
 * - `JsonMachine`: `halaxa/json-machine`, pure PHP, always available.
 * - `Simdjson`: the SDK's array scanner, rows decoded in batches by `ext-simdjson`, falling back to `json_decode()`
 *   for a batch simdjson refuses (an integer beyond the 64-bit range), so ids stay exact.
 * - `YajlStream`: `ext-yajl_stream` (libyajl, C), the fastest.
 */
enum JsonStreamDecoder: string
{
    case Auto = 'auto';
    case JsonMachine = 'json-machine';
    case Simdjson = 'simdjson';
    case YajlStream = 'yajl_stream';

    /**
     * Whether this decoder can run in this PHP process (its extension is loaded).
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::Auto, self::JsonMachine => true,
            self::Simdjson => \function_exists('simdjson_decode'),
            self::YajlStream => class_exists(ArrayParser::class),
        };
    }

    /**
     * The decoder that runs: itself, or for `Auto` the first available of `YajlStream`, `Simdjson`, `JsonMachine`.
     *
     * @internal not part of the public API; callers choose a decoder and check it with isAvailable()
     */
    public function resolve(): self
    {
        if ($this !== self::Auto) {
            return $this;
        }
        foreach ([self::YajlStream, self::Simdjson] as $decoder) {
            if ($decoder->isAvailable()) {
                return $decoder;
            }
        }

        return self::JsonMachine;
    }
}
