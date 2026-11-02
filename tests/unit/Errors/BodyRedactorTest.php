<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\BodyRedactor;

#[CoversClass(BodyRedactor::class)]
final class BodyRedactorTest extends TestCase
{
    private const LIMIT = 512;

    /** A synthetic JWT (`{"sub":"SYNTHETIC"}`), split so secret scanners do not flag the fixture. */
    private const JWT = 'eyJhbGciOiJIUzI1NiJ9' . '.eyJzdWIiOiJTWU5USEVUSUMifQ' . '.synthetic-signature';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function jsonBodies(): iterable
    {
        yield 'token field, other fields kept' => ['{"accessToken":"synthetic-access","code":1}', '{"accessToken":"***","code":1}'];
        yield 'any case, spacing is normalized' => ['{"X-Partner-TOKEN" :  "synthetic"}', '{"X-Partner-TOKEN":"***"}'];
        yield 'every credential key word' => [
            '{"airwallexSecret":"a","password":"b","Authorization":"c","Set-Cookie":"d","api_key":"e","apiKey":"f","jwt":"g","credentials":"h","sessionId":"i"}',
            '{"airwallexSecret":"***","passw***":"***","Authorization":"***","Set-Cookie":"***","api_key":"***","apiKey":"***","jwt":"***","credential***":"***","session***":"***"}',
        ];
        yield 'escaped quote in the value' => ['{"token":"syn\"thetic","ok":true}', '{"token":"***","ok":true}'];
        yield 'key spelled with a \u escape' => ["{\"\x5Cu0074oken\":\"synthetic\",\"p\x5Cu0061ssword\":\"synthetic\"}", '{"token":"***","passw***":"***"}'];
        yield 'non-string value under a credential key' => ['{"cookies":[{"value":"synthetic"}],"tokenExpired":true}', '{"cookie***":"***","token***":"***"}'];
        yield 'nested in GraphQL errors' => ['{"errors":[{"extensions":{"partnerToken":"synthetic"}}]}', '{"errors":[{"extensions":{"partnerToken":"***"}}]}'];
        yield 'message without a marker kept' => ['{"errors":[{"message":"Item not found","path":["market_buy"]}]}', '{"errors":[{"message":"Item not found","path":["market_buy"]}]}'];
        yield 'message cut at a credential word' => ['{"error":"bad header Bearer abc\/synthetic","code":1}', '{"error":"bad header Bearer***","code":1}'];
        yield 'quoted pair inside a message' => ['{"error":"password=\"synthetic\" rejected"}', '{"error":"passw***"}'];
        yield 'escaped JSON inside a message' => ['{"error":"{\"token\":\"synthetic\"}"}', '{"error":"{\"token***"}'];
        yield 'JWT in a message' => ['{"error":"ctx ' . self::JWT . ' end"}', '{"error":"ctx eyJ***"}'];
        yield 'JWT that would cross the excerpt limit' => [
            '{"error":"' . str_repeat('x', 470) . self::JWT . '"}',
            '{"error":"' . str_repeat('x', 470) . 'eyJ***"}',
        ];
        yield 'cf_connect in a message' => ['{"url":"/live?cf_connect=synthetic%7B%7D&x=1"}', '{"url":"/live?cf_connect***"}'];
        yield 'escape-like text in a value is not decoded twice' => ['{"msg":"literal \\\\u0041 and \\\\/"}', '{"msg":"literal \\\\u0041 and \\\\/"}'];
        yield 'escaped marker in a value' => ['{"message":"upstream: \\\\u0074oken=synthetic"}', '{"message":"upstream: token***"}'];
        yield 'escaped key in escaped JSON in a value' => ['{"error":"{\"tok\\\\u0065n\":\"synthetic\"}"}', '{"error":"{\"token***"}'];
        yield 'escaped backslash before an escaped marker' => ['{"m":"a \\\\u005cu0074oken=synthetic"}', '{"m":"a token***"}'];
        yield 'scheme word before a credential word in a key' => ['{"bearer_token":"synthetic","basic_password":"synthetic"}', '{"bearer***":"***","basic***":"***"}'];
        yield 'key that is only a marker' => ['{"Bearer":"synthetic","cf_connect":1}', '{"Bearer":"***","cf_connect":"***"}'];
        yield 'marker in a key' => ['{"Bearer synthetic":1,"code":2}', '{"Bearer***":"***","code":2}'];
        yield 'escaped credential word in a key' => ['{"\\\\u0074oken=synthetic":1}', '{"token***":"***"}'];
        yield 'marker with nothing after it kept' => ['{"scheme":"Bearer"}', '{"scheme":"Bearer"}'];
        yield 'literal marker whose first letter an escape would swallow, in a value' => ['{"m":"\\\\u007basic U1lOVEhFVElD"}', '{"m":"{asic***"}'];
        yield 'literal marker whose first letter an escape would swallow, in a key' => ['{"\\\\u003cookie":"synthetic"}', '{"<ookie":"***"}'];
        yield 'escaped control character before a marker in a key' => ['{"\\\\u0000tokenX":"synthetic"}', '{"\\\\u0000token***":"***"}'];
        yield 'marker spelled across an escaped backslash, in a value' => ['{"message":"\\\\\\\\u007a\\\\u0070i_key=synthetic"}', '{"message":"zpi_key***"}'];
        yield 'marker spelled across an escaped backslash, in a key' => ['{"\\\\\\\\u002b\\\\u0061sic":"synthetic"}', '{"+asic":"***"}'];
        yield 'integer beyond 64 bits kept exact' => ['{"id":12345678901234567890}', '{"id":"12345678901234567890"}'];
        yield 'empty object and array kept apart' => ['{"data":{},"list":[]}', '{"data":{},"list":[]}'];
        yield 'leading whitespace, value past the limit' => [
            str_repeat(' ', self::LIMIT - 20) . '{"token":"synthetic-value-past-the-limit"}',
            '{"token":"***"}',
        ];
    }

    #[Test]
    #[DataProvider('jsonBodies')]
    public function redactsJsonByKeyAndCutsStringsAtACredentialMarker(string $body, string $expected): void
    {
        self::assertSame($expected, BodyRedactor::excerpt($body, self::LIMIT));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textBodies(): iterable
    {
        yield 'no marker -> kept' => ['<html><body>502 Bad Gateway</body></html>', '<html><body>502 Bad Gateway</body></html>'];
        yield 'bearer header echoed' => ['upstream said: Bearer synthetic.value-x', 'upstream said: Bearer***'];
        yield 'basic header echoed' => ['upstream said: Basic c3ludGhldGlj', 'upstream said: Basic***'];
        yield 'authorization header echoed' => ['Authorization: Bearer synthetic', 'Authorization***'];
        yield 'JWT anywhere' => ['<p>ctx ' . self::JWT . ' end</p>', '<p>ctx eyJ***'];
        yield 'JWT truncated by the server' => ['ctx eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJTWU5U', 'ctx eyJ***'];
        yield 'JWT cut by the limit' => [str_repeat('x', 500) . self::JWT, str_repeat('x', 500) . 'eyJ***'];
        yield 'cf_connect query value' => ['GET /live?cf_connect=synthetic%7B%7D&x=1', 'GET /live?cf_connect***'];
        yield 'form-style pair' => ['<p>token=synthetic&x=1</p>', '<p>token***'];
        yield 'double-quoted value' => ['error: password="synthetic"', 'error: passw***'];
        yield 'single-quoted value' => ["error: token='synthetic'", 'error: token***'];
        yield 'unquoted key, quoted value' => ['{password:"synthetic"}', '{passw***'];
        yield 'PHP array syntax' => ["'api_key' => 'synthetic'", "'api_key***"];
        yield 'cookie header with a quoted value' => ['Cookie: session="synthetic"', 'Cookie***'];
        yield 'top-level JSON string holding escaped JSON' => ['"{\"token\":\"synthetic\"}"', '"{"token***'];
        yield 'broken JSON, raw newline after a backslash' => ["<p>{\"token\":\"syn\\\nthetic\"}", '<p>{"token***'];
        yield 'broken JSON, key spelled with a \u escape' => ["<p>{\"\x5Cu0074oken\":\"synthetic\"}", '<p>{"token***'];
        yield 'broken JSON, escaped backslash before an escaped marker' => ['<p>{"m":"\\u005cu0074oken=synthetic', '<p>{"m":"token***'];
        yield 'literal JWT header whose first letter an escape would swallow' => ['err \\u007eyJhbGciOiJIUzI1NiJ9.QUJD.c2ln', 'err ~yJ***'];
        yield 'literal cookie whose first letter an escape would swallow' => ['Set: \\u003cookie: synthetic', 'Set: <ookie***'];
        yield 'marker spelled across an escaped backslash' => ['error: \\\\u007a\\u0070i_key=synthetic', 'error: zpi_key***'];
        yield 'lowest printable escape before a marker decoded' => ['a\u0020b token=synthetic', 'a b token***'];
        yield 'highest decoded escape before a marker' => ['a\u007Fb token=synthetic', "a\x7Fb token***"];
        yield 'control escape before a marker kept' => ['a\u001Fb token=synthetic', 'a\u001Fb token***'];
        yield 'escape past ASCII before a marker kept' => ['a\u0080b token=synthetic', 'a\u0080b token***'];
        yield 'broken JSON, escaped nested object' => ['{"error":"{\"token\":\"synthetic\"}"', '{"error":"{"token***'];
        yield 'broken JSON, bearer with an escaped slash' => ['<p>{"e":"Bearer abc\/synthetic"}', '<p>{"e":"Bearer***'];
        yield 'broken JSON, word crossing the limit' => [
            str_repeat(' ', self::LIMIT - 8) . '<p>{"token":"synthetic"}',
            str_repeat(' ', self::LIMIT - 8) . '<p>{"tok',
        ];
        yield 'JSON nested deeper than the decoder allows' => [
            str_repeat('[', 70) . '{"token":["synthetic"]}' . str_repeat(']', 70),
            str_repeat('[', 70) . '{"token***',
        ];
    }

    #[Test]
    #[DataProvider('textBodies')]
    public function cutsTextAtTheFirstCredentialMarker(string $body, string $expected): void
    {
        self::assertSame($expected, BodyRedactor::excerpt($body, self::LIMIT));
    }

    #[Test]
    public function redactsAJsonBodyTooLargeToDecodeAsText(): void
    {
        $body = '{"token":["synthetic"],"pad":"' . str_repeat('x', 2 * 1024 * 1024) . '"}';

        self::assertSame('{"token***', BodyRedactor::excerpt($body, self::LIMIT));
    }

    #[Test]
    public function keepsTheJsonStructureWhenALongValueIsCutInsideACharacter(): void
    {
        $body = '{"tokenId":"x","pad":"a' . str_repeat("\u{e9}", 300) . '"}';

        self::assertStringStartsWith('{"token***":"***","pad":"a' . "\u{e9}", BodyRedactor::excerpt($body, self::LIMIT));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function largeNestedEscapes(): iterable
    {
        $nested = str_repeat('u005c', 200_000);

        yield 'value' => ['{"m":"\\\\' . $nested . 'u0074oken=synthetic"}', substr('{"m":"\\\\' . $nested, 0, self::LIMIT)];
        yield 'key' => ['{"\\\\' . $nested . 'u0074oken=synthetic":1}', substr('{"\\\\' . $nested, 0, self::LIMIT)];
        yield 'text body' => ['<p>\\' . $nested . $nested . 'u0074oken=synthetic</p>', substr('<p>\\' . $nested . $nested, 0, self::LIMIT)];
    }

    #[Test]
    #[DataProvider('largeNestedEscapes')]
    public function decodesEscapesNestedThroughALargeInputOnlyUpToTheLimit(string $body, string $expected): void
    {
        self::assertSame($expected, BodyRedactor::excerpt($body, self::LIMIT));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentStarts(): iterable
    {
        yield 'whitespace before an object -> by structure' => [" \t\r\n" . '{"token":1,"code":2}', '{"token":"***","code":2}'];
        yield 'array -> by structure' => ['[{"token":1},{"code":2}]', '[{"token":"***"},{"code":2}]'];
        yield 'scalar JSON -> as text' => ['"token synthetic"', '"token***'];
        yield 'malformed object -> as text' => ['{"token":"synthetic",}', '{"token***'];
        yield 'empty body' => ['', ''];
        yield 'escaped NUL anywhere -> as text' => ['{"a":"\u0000x token synthetic"}', '{"a":"\u0000x token***'];
        yield 'escaped backslash before u0000 -> by structure' => ['{"a":"\\\\u0000","token":1}', '{"a":"\\\\u0000","token":"***"}'];
    }

    #[Test]
    #[DataProvider('documentStarts')]
    public function decodesOnlyAJsonObjectOrArray(string $body, string $expected): void
    {
        self::assertSame($expected, BodyRedactor::excerpt($body, self::LIMIT));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undecodableEnds(): iterable
    {
        yield 'malformed' => ['x'];
        yield 'valid JSON, property name starting with NUL' => [',"\u0000":1}'];
    }

    /**
     * A body json_decode() cannot decode must not reach it: decoding builds values until it runs out of memory, and the
     * fatal error backtrace (PHP 8.5) prints the start of the body as the json_decode() argument.
     */
    #[Test]
    #[DataProvider('undecodableEnds')]
    public function doesNotDecodeABodyThatCouldRunOutOfMemory(string $end): void
    {
        if (\PHP_VERSION_ID < 80500) {
            self::markTestSkipped('fatal_error_backtraces exists since PHP 8.5');
        }
        $child = 'require ' . var_export(\dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$body = \'{"t":"SYNTHETICVALUE","a":[\' . str_repeat(\'1,\', 400000) . \'1]\' . ' . var_export($end, true) . ';'
            . 'echo WhiteMarket\Sdk\Errors\BodyRedactor::excerpt($body, 16), "|DONE";';

        $output = $this->runPhp($child, ['memory_limit=8M', 'fatal_error_backtraces=1', 'zend.exception_ignore_args=0']);

        self::assertSame('{"t":"SYNTHETICV|DONE', $output);
    }

    /**
     * @param list<string> $ini
     */
    private function runPhp(string $code, array $ini): string
    {
        $command = [\PHP_BINARY, '-d', 'display_errors=stdout', '-d', 'log_errors=0'];
        foreach ($ini as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = '-r';
        $command[] = $code;
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = $pipes[1] ?? null;
        self::assertIsResource($stdout);
        $output = stream_get_contents($stdout);
        fclose($stdout);
        proc_close($process);

        return trim((string) $output);
    }

    #[Test]
    public function redactsAJsonBodyOfExactlyTheDecodeLimitByStructure(): void
    {
        $prefix = '{"tokenId":"x","pad":"';
        $body = $prefix . str_repeat('x', 1024 * 1024 - \strlen($prefix) - 2) . '"}';

        self::assertStringStartsWith('{"token***":"***","pad":"x', BodyRedactor::excerpt($body, self::LIMIT));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUtf8(): iterable
    {
        yield 'invalid byte in the middle -> replacement character' => ["a\xFFb", "a\u{FFFD}b"];
        yield 'stray continuation byte -> replacement character' => ["a\x80b", "a\u{FFFD}b"];
        yield 'overlong encoding -> replacement characters' => ["a\xC0\xAFb", "a\u{FFFD}\u{FFFD}b"];
        yield 'replacement characters do not push the excerpt past the limit' => [str_repeat("\x80", self::LIMIT), str_repeat("\u{FFFD}", 170)];
        yield 'invalid JSON body is handled as text' => ["{\"e\":\"\xFF\"}", "{\"e\":\"\u{FFFD}\"}"];
    }

    #[Test]
    #[DataProvider('invalidUtf8')]
    public function returnsValidUtf8(string $body, string $expected): void
    {
        $excerpt = BodyRedactor::excerpt($body, self::LIMIT);

        self::assertSame($expected, $excerpt);
        self::assertNotFalse(json_encode($excerpt));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cuts(): iterable
    {
        yield '2-byte char across the limit -> dropped' => [str_repeat('a', self::LIMIT - 1) . "\u{e9}tail", str_repeat('a', self::LIMIT - 1)];
        yield '3-byte char, 2 bytes in -> dropped' => [str_repeat('a', self::LIMIT - 2) . "\u{20ac}tail", str_repeat('a', self::LIMIT - 2)];
        yield '4-byte char, 3 bytes in -> dropped' => [str_repeat('a', self::LIMIT - 3) . "\u{1f600}tail", str_repeat('a', self::LIMIT - 3)];
        yield '2-byte char ending at the limit -> kept' => [str_repeat('a', self::LIMIT - 2) . "\u{e9}tail", str_repeat('a', self::LIMIT - 2) . "\u{e9}"];
        yield '4-byte char ending at the limit -> kept' => [str_repeat('a', self::LIMIT - 4) . "\u{1f600}tail", str_repeat('a', self::LIMIT - 4) . "\u{1f600}"];
        yield 'exactly the limit -> whole' => [str_repeat('a', self::LIMIT), str_repeat('a', self::LIMIT)];
    }

    #[Test]
    #[DataProvider('cuts')]
    public function cutsAtTheLimitWithoutSplittingACharacter(string $body, string $expected): void
    {
        self::assertSame($expected, BodyRedactor::excerpt($body, self::LIMIT));
    }
}
