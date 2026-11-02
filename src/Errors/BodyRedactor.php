<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * Makes the start of an HTTP error body safe to log (SDK_SPEC 1.3, 8): credentials the body may echo become `***`, and
 * the result is valid UTF-8 of at most `limit` bytes (invalid bytes become U+FFFD).
 *
 * A JSON body is redacted by structure: every value under a key holding a credential marker is masked whatever its
 * type, every key and every other string value is redacted as text, and the result is re-encoded compactly. Anything
 * else (not JSON, too deep or too large to decode) is redacted as text. Text is cut at its first credential marker, as
 * given and after each pass of JSON unescaping, so a secret after a marker never survives, whatever its format.
 *
 * Not detected, by design (owner decision 2026-10-31): a secret with no marker before it in the same string or key,
 * including a credential in a JSON value whose marker is in a sibling value (`{"name":"Cookie","value":"..."}`,
 * `["Authorization","..."]`). Each string is redacted on its own to keep the rest of the error body readable.
 * Re-encoding writes a TAB or backspace as `\t` / `\b`, so a value like `\u0009oken X` is logged as `\token X`: by JSON
 * rules it is a TAB before `oken`, not a marker, and it is kept.
 *
 * @internal used by HttpException
 */
final class BodyRedactor
{
    /** One JSON escape that can spell a marker letter: `\uXXXX`, or an escaped quote, backslash or slash. */
    private const ESCAPE = '/\\\\(?:u(?<code>[0-9A-Fa-f]{4})|(?<char>["\\\\\/]))/';

    /** A JSON `\u0000` escape, not preceded by an escaped backslash. */
    private const ESCAPED_NUL = '/(?<!\\\\)(?:\\\\\\\\)*\\\\u0000/';

    private const MASK = '***';

    private const JSON_MAX_BYTES = 1024 * 1024;

    private const JSON_MAX_DEPTH = 64;

    /**
     * Anything after one of these in a string, or under one in a key, may be a credential: credential words, header
     * schemes, a JWT header, the live connection token.
     */
    private const MARKER = '/token|secret|passw|authori[sz]ation|cookie|api[_-]?key|jwt|credential|session'
        . '|bearer|basic|eyJ|cf_connect/i';

    private const VALID_UTF8_SEQUENCE = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';

    private function __construct() {}

    public static function excerpt(#[\SensitiveParameter] string $body, int $limit): string
    {
        $redacted = self::redactJson($body, $limit) ?? self::redactText($body, $limit);

        // A replacement character is longer than the byte it replaces: cut after replacing.
        return self::validUtf8(substr(self::validUtf8($redacted), 0, $limit));
    }

    private static function redactJson(#[\SensitiveParameter] string $body, int $limit): ?string
    {
        if (\strlen($body) > self::JSON_MAX_BYTES || !self::isSafeToDecode($body)) {
            return null;
        }
        try {
            $data = json_decode($body, false, self::JSON_MAX_DEPTH, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) && !$data instanceof \stdClass) {
            return null;
        }
        $encoded = json_encode(
            self::redactValue($data, $limit),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? null : $encoded;
    }

    /**
     * Whether the body is a JSON object or array, checked before json_decode() sees it: the frame of json_decode()
     * holds the raw body, and a JsonException carries it in its trace (exception hooks, APM), as does a fatal error
     * backtrace (PHP 8.5 `fatal_error_backtraces`) when decoding a large malformed body runs out of memory.
     * json_validate() builds no values and throws nothing. PHP 8.2 has no json_validate(): there only the first
     * character is checked, and a malformed body that starts like JSON still reaches json_decode().
     *
     * Remaining exposure, by design: decoding a valid document of up to JSON_MAX_BYTES can still run out of memory;
     * the backtrace then shows the start of the body (`zend.exception_string_param_max_len`, 15 bytes by default).
     */
    private static function isSafeToDecode(#[\SensitiveParameter] string $body): bool
    {
        $first = $body[strspn($body, " \t\n\r")] ?? '';
        // json_validate() accepts a property name starting with NUL; decoding to objects rejects it. Any escaped NUL
        // (an odd run of backslashes before `u0000`) sends the body to text redaction.
        if (($first !== '{' && $first !== '[') || preg_match(self::ESCAPED_NUL, $body) !== 0) {
            return false;
        }

        return !\function_exists('json_validate') || json_validate($body, self::JSON_MAX_DEPTH);
    }

    private static function redactValue(#[\SensitiveParameter] mixed $value, int $limit): mixed
    {
        if (\is_string($value)) {
            return self::redactText($value, $limit);
        }
        if (\is_array($value)) {
            // A loop, not array_map(): its internal frame would hold the raw array in exception trace arguments.
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = self::redactValue($item, $limit);
            }

            return $redacted;
        }
        if (!$value instanceof \stdClass) {
            return $value;
        }
        $redacted = new \stdClass();
        foreach (get_object_vars($value) as $key => $item) {
            [$name, $marked] = self::cutAtFirstMarker((string) $key, $limit);
            $redacted->{$name} = $marked ? self::MASK : self::redactValue($item, $limit);
        }

        return $redacted;
    }

    private static function redactText(#[\SensitiveParameter] string $text, int $limit): string
    {
        return self::cutAtFirstMarker($text, $limit)[0];
    }

    /**
     * Keeps the text up to and including the first credential marker and replaces the rest with `***`. Cutting instead
     * of matching value formats means no quoting, escaping or nesting of the value can carry it past the redaction.
     * The text is cut to the limit first: no byte past the limit can reach the excerpt, and decoding stays cheap.
     *
     * The marker is searched in the text as given and again after every pass of escape decoding, and the text is cut
     * at each stage it shows up in: decoding can neither hide a marker (`\u005cu0074oken` -> `\u0074oken` -> `token`)
     * nor swallow the first letter of a literal one (`\u007eyJ` -> `~yJ`). Every pass shortens the text or ends the
     * loop. Text with no marker at any stage is returned as given; nothing after the marker means no `***`. A regex
     * failure returns an empty, marked text: the unredacted text must not leak.
     *
     * @return array{string, bool} the redacted text and whether it held a marker
     */
    private static function cutAtFirstMarker(#[\SensitiveParameter] string $text, int $limit): array
    {
        $text = substr($text, 0, $limit);
        $current = $text;
        $marked = false;
        $cut = false;
        do {
            $found = preg_match(self::MARKER, $current, $match, \PREG_OFFSET_CAPTURE);
            if ($found === false) {
                return ['', true];
            }
            if ($found === 1) {
                $end = $match[0][1] + \strlen($match[0][0]);
                $marked = true;
                $cut = $cut || $end < \strlen($current);
                $current = substr($current, 0, $end);
            }
            $previous = $current;
            $current = self::decodeEscapes($current);
            if ($current === null) {
                return ['', true];
            }
        } while ($current !== $previous);

        if (!$marked) {
            return [$text, false];
        }

        return [$cut ? $current . self::MASK : $current, true];
    }

    /**
     * One pass of JSON string unescaping, as a consumer decoding the excerpt reads it: `\\`, `\"`, `\/` and `\uXXXX`
     * from U+0020 to U+007F are decoded, each escape read left to right as one unit, so an escaped backslash never starts an
     * escape of its own (`\\u0074` -> `\u0074`, not `\t`). Other escapes cannot spell a marker and stay as they are;
     * a decoded control character such as NUL would be an invalid property name.
     */
    private static function decodeEscapes(#[\SensitiveParameter] string $text): ?string
    {
        // Matches first and decodes in a loop, not preg_replace_callback(): the internal frame below a callback holds
        // the raw text, and anything thrown inside (a stack overflow included) would carry it in its trace.
        if (preg_match_all(self::ESCAPE, $text, $escapes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $decoded = '';
        $offset = 0;
        foreach ($escapes as $escape) {
            [$whole, $start] = $escape[0];
            $decoded .= substr($text, $offset, $start - $offset) . self::decodeEscape($whole, $escape['code'][0] ?? '', $escape['char'][0] ?? '');
            $offset = $start + \strlen($whole);
        }

        return $decoded . substr($text, $offset);
    }

    /**
     * An unmatched group is an empty string (or absent at the end of the match).
     */
    private static function decodeEscape(string $whole, string $code, string $char): string
    {
        if ($char !== '') {
            return $char;
        }
        $codePoint = (int) hexdec($code);

        return $codePoint >= 0x20 && $codePoint <= 0x7F ? \chr($codePoint) : $whole;
    }

    /**
     * Drops a character cut by the limit, then replaces every remaining invalid byte with U+FFFD.
     */
    private static function validUtf8(#[\SensitiveParameter] string $text): string
    {
        $text = preg_replace('/(?:[\xC2-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF4][\x80-\xBF]{0,2})\z/', '', $text) ?? '';
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        return preg_replace('/\G(?:' . self::VALID_UTF8_SEQUENCE . ')*+\K[\x80-\xFF]/', "\u{FFFD}", $text) ?? '';
    }
}
