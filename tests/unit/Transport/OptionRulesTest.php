<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\Sse\SseConnection;
use WhiteMarket\Sdk\Live\WebSocket\WebSocketConnection;
use WhiteMarket\Sdk\Tests\Support\ThrowingRequestFactory;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\OptionRules;
use WhiteMarket\Sdk\Transport\TransportOptions;

/**
 * The endpoint rules of SDK_SPEC 2 (printable ASCII, 2048 characters, a URL the HTTP stack can build) through the option
 * classes that use them.
 */
#[CoversClass(OptionRules::class)]
final class OptionRulesTest extends TestCase
{
    /**
     * Every endpoint option: its name in the message, whether it holds an origin or a path, and a constructor for it.
     *
     * @return iterable<string, array{string, bool, \Closure(string): object}>
     */
    public static function options(): iterable
    {
        yield 'TransportOptions baseUrl' => ['baseUrl', true, static fn(string $v): object => new TransportOptions(baseUrl: $v)];
        yield 'ExportOptions baseUrl' => ['baseUrl', true, static fn(string $v): object => new ExportOptions(baseUrl: $v)];
        yield 'graphqlPath' => ['graphqlPath', false, static fn(string $v): object => new TransportOptions(graphqlPath: $v)];
        yield 'ssePath' => ['ssePath', false, static fn(string $v): object => new LiveOptions(ssePath: $v)];
        yield 'wsPath' => ['wsPath', false, static fn(string $v): object => new LiveOptions(wsPath: $v)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenCharacters(): iterable
    {
        yield 'NUL' => ["\x00"];
        yield '0x1F' => ["\x1F"];
        yield 'DEL' => ["\x7F"];
        yield 'TAB' => ["\t"];
        yield 'U+0080' => ["\u{80}"];
        yield 'U+0085' => ["\u{85}"];
        yield 'U+00A0' => ["\u{a0}"];
        yield 'U+00E9' => ["\u{e9}"];
        yield 'U+2028' => ["\u{2028}"];
        yield 'U+FEFF' => ["\u{feff}"];
        yield 'emoji' => ["\u{1F600}"];
        yield 'invalid UTF-8' => ["\xFF"];
    }

    /**
     * @return iterable<string, array{string, bool, \Closure(string): object, string}>
     */
    public static function optionsWithForbiddenCharacters(): iterable
    {
        foreach (self::options() as $optionName => [$name, $origin, $make]) {
            foreach (self::forbiddenCharacters() as $characterName => [$character]) {
                yield "$optionName with $characterName" => [$name, $origin, $make, $character];
            }
        }
    }

    /**
     * @param \Closure(string): object $make
     */
    #[Test]
    #[DataProvider('optionsWithForbiddenCharacters')]
    public function refusesACharacterOutsidePrintableAscii(string $name, bool $origin, \Closure $make, string $character): void
    {
        $value = $origin ? 'https://api' . $character . '.example.test' : '/p' . $character . 'q';

        self::assertSame(
            $name . ' must be printable ASCII (an internationalised host in its xn-- form).',
            $this->refusal(static fn() => $make($value)),
        );
    }

    /**
     * Without the `D` modifier `$` also matches before a final line feed; the later patterns would then refuse the
     * value with another message, so the message tells which rule caught it.
     *
     * @param \Closure(string): object $make
     */
    #[Test]
    #[DataProvider('options')]
    public function refusesAFinalLineFeedAsOutsidePrintableAscii(string $name, bool $origin, \Closure $make): void
    {
        $value = ($origin ? 'https://api.example.test' : '/graphql') . "\n";

        self::assertSame(
            $name . ' must be printable ASCII (an internationalised host in its xn-- form).',
            $this->refusal(static fn() => $make($value)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function originsWithAPathQueryOrFragment(): iterable
    {
        yield 'path' => ['https://api.example/v1'];
        yield 'query' => ['https://api.example?x=1'];
        yield 'fragment' => ['https://api.example#top'];
        yield 'path after a port' => ['https://api.example:8443/v1'];
    }

    #[Test]
    #[DataProvider('originsWithAPathQueryOrFragment')]
    public function refusesAnOriginWithAPathQueryOrFragment(string $baseUrl): void
    {
        self::assertSame(
            'baseUrl must be an https:// origin without a path, query or fragment.',
            $this->refusal(static fn() => OptionRules::assertHttpsOrigin($baseUrl)),
        );
    }

    /**
     * @return iterable<string, array{string, bool, \Closure(string): object, string}>
     */
    public static function optionsWithAcceptedValues(): iterable
    {
        foreach (self::options() as $optionName => [$name, $origin, $make]) {
            if ($origin) {
                yield "$optionName ending in a tilde" => [$name, $origin, $make, 'https://api.example.test~'];
                yield "$optionName with a hyphen and digits in the host" => [$name, $origin, $make, 'https://api-2.example.test'];
                yield "$optionName with an internationalised host in its xn-- form" => [$name, $origin, $make, 'https://xn--bcher-kva.example'];
            } else {
                yield "$optionName ending in a tilde" => [$name, $origin, $make, '/graphql~'];
                yield "$optionName with an exclamation mark" => [$name, $origin, $make, '/a!b'];
            }
        }
    }

    /**
     * @param \Closure(string): object $make
     */
    #[Test]
    #[DataProvider('optionsWithAcceptedValues')]
    public function acceptsTheWholePrintableAsciiRange(string $name, bool $origin, \Closure $make, string $value): void
    {
        self::assertIsObject($make($value));
    }

    /**
     * @return iterable<string, array{string, bool, \Closure(string): object, int, bool}>
     */
    public static function optionsAtTheLengthLimit(): iterable
    {
        foreach (self::options() as $optionName => [$name, $origin, $make]) {
            yield "$optionName 2048 characters -> accepted" => [$name, $origin, $make, 2048, true];
            yield "$optionName 2049 characters -> refused" => [$name, $origin, $make, 2049, false];
        }
    }

    /**
     * @param \Closure(string): object $make
     */
    #[Test]
    #[DataProvider('optionsAtTheLengthLimit')]
    public function limitsEveryEndpointOptionToTwoThousandFortyEightCharacters(string $name, bool $origin, \Closure $make, int $length, bool $accepted): void
    {
        $value = $origin
            ? 'https://' . str_repeat('a', $length - \strlen('https://'))
            : '/' . str_repeat('a', $length - 1);
        self::assertSame($length, \strlen($value));

        if ($accepted) {
            self::assertIsObject($make($value));

            return;
        }
        self::assertSame($name . ' must be at most 2048 characters.', $this->refusal(static fn() => $make($value)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function origins(): iterable
    {
        yield 'port with letters (one layer reads it as port 44)' => ['https://api.example:44a3', false];
        yield 'port above 65535' => ['https://api.example:99999', false];
        yield 'port 65536' => ['https://api.example:65536', false];
        yield 'port 65535' => ['https://api.example:65535', true];
        yield 'port 0' => ['https://api.example:0', false];
        yield 'port 00' => ['https://api.example:00', false];
        yield 'port with a sign' => ['https://api.example:+443', false];
        yield 'port 1' => ['https://api.example:1', true];
        yield 'port 8443' => ['https://api.example:8443', true];
        yield 'six digit port' => ['https://api.example:000443', false];
        yield 'empty port' => ['https://api.example:', false];
        yield 'two colons in a plain host' => ['https://api.example:1:2', false];
        yield 'empty host with a port' => ['https://:443', false];
        yield 'IPv6 literal that is not an address' => ['https://[zz]', false];
        yield 'IPv6 literal without closing bracket' => ['https://[::1', false];
        yield 'text after the IPv6 literal' => ['https://[::1]x', false];
        yield 'IPv6 literal' => ['https://[::1]', true];
        yield 'IPv6 literal with a port' => ['https://[::1]:8443', true];
        yield 'IPv6 literal with a bad port' => ['https://[::1]:x', false];
        // Every layer of the PHP HTTP stack builds and sends these [observed 2026-11-15, Guzzle 7.16.1, psr7 2.13.2];
        // they fail at DNS or connect, so the SDK does not guess (docs/DEVIATIONS.md, SPEC 2).
        yield 'IPv4 address out of range: the PHP HTTP stack accepts it and it fails at connect' => ['https://999.999.999.999', true];
        yield 'xn-- label that is not punycode: the PHP HTTP stack accepts it and it fails at connect' => ['https://xn--zz', true];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedOrigins(): iterable
    {
        foreach (self::origins() as $name => [$baseUrl, $accepted]) {
            if (!$accepted) {
                yield $name => [$baseUrl];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedOrigins(): iterable
    {
        foreach (self::origins() as $name => [$baseUrl, $accepted]) {
            if ($accepted) {
                yield $name => [$baseUrl];
            }
        }
    }

    /**
     * Observed 2026-11-15: the Guzzle curl handler refuses these host characters before any I/O ("Bad hostname"), and
     * Guzzle's own host check refuses `%`.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function hosts(): iterable
    {
        foreach (str_split('!$\'()*+,;=&"<>^`{}') as $character) {
            yield 'refused ' . $character => ['ap' . $character . 'i.example', false];
        }
        yield 'refused percent escape' => ['api%41.example', false];
        yield 'refused semicolon at the end' => ['api.example;x', false];
        yield 'letters' => ['api.example', true];
        yield 'upper-case letters' => ['API.Example', true];
        yield 'digits' => ['a1.example2', true];
        yield 'hyphen' => ['a-b.example', true];
        yield 'dot' => ['a.b.c', true];
        yield 'underscore (curl accepts it)' => ['a_b.example', true];
        yield 'tilde (curl accepts it)' => ['a~b.example', true];
    }

    /**
     * @return iterable<string, array{string, bool, \Closure(string): object}>
     */
    public static function hostsPerOrigin(): iterable
    {
        $constructors = [
            'TransportOptions' => static fn(string $v): object => new TransportOptions(baseUrl: $v),
            'ExportOptions' => static fn(string $v): object => new ExportOptions(baseUrl: $v),
        ];
        foreach (self::hosts() as $name => [$host, $accepted]) {
            foreach ($constructors as $class => $make) {
                yield $class . ' ' . $name => ['https://' . $host, $accepted, $make];
            }
        }
    }

    /**
     * @param \Closure(string): object $make
     */
    #[Test]
    #[DataProvider('hostsPerOrigin')]
    public function acceptsOnlyHostnameCharactersInAHost(string $baseUrl, bool $accepted, \Closure $make): void
    {
        if ($accepted) {
            self::assertIsObject($make($baseUrl));

            return;
        }
        self::assertSame('baseUrl is not a valid URL.', $this->refusal(static fn() => $make($baseUrl)));
    }

    /**
     * Every URL option that takes an origin reads the port through the same rule (PARITY T4). Observed 2026-11-15:
     * the PHP layers read an empty and a zero-padded port differently (`https://h:` is the default port for psr7 and
     * port 0 for phrity; `:000443` is refused by psr7 and by `parse_url()`), so both are refused, unlike in Python.
     *
     * @return iterable<string, array{string, \Closure(string): object}>
     */
    public static function refusedPortsPerOption(): iterable
    {
        $constructors = [
            'TransportOptions' => static fn(string $v): object => new TransportOptions(baseUrl: $v),
            'ExportOptions' => static fn(string $v): object => new ExportOptions(baseUrl: $v),
        ];
        foreach (['0', '00', '65536', '+443', '', '000443'] as $port) {
            foreach ($constructors as $class => $make) {
                yield \sprintf('%s port "%s"', $class, $port) => ['https://api.example:' . $port, $make];
            }
        }
    }

    /**
     * @param \Closure(string): object $make
     */
    #[Test]
    #[DataProvider('refusedPortsPerOption')]
    public function refusesAPortTheLayersReadDifferentlyForEveryOriginOption(string $baseUrl, \Closure $make): void
    {
        self::assertSame('baseUrl is not a valid URL.', $this->refusal(static fn() => $make($baseUrl)));
    }

    #[Test]
    #[DataProvider('refusedOrigins')]
    public function refusesAPortOrBracketedHostTheHttpStackReadsDifferently(string $baseUrl): void
    {
        self::assertSame('baseUrl is not a valid URL.', $this->refusal(static fn() => OptionRules::assertHttpsOrigin($baseUrl)));
    }

    #[Test]
    #[DataProvider('acceptedOrigins')]
    public function acceptsAnOriginWithAValidPortOrBracketedHost(string $baseUrl): void
    {
        $this->expectNotToPerformAssertions();

        OptionRules::assertHttpsOrigin($baseUrl);
    }

    #[Test]
    public function namesTheOptionInTheOriginMessages(): void
    {
        self::assertSame('exportUrl is not a valid URL.', $this->refusal(static fn() => OptionRules::assertHttpsOrigin('https://[zz]', 'exportUrl')));
        self::assertSame('exportUrl must not contain credentials.', $this->refusal(static fn() => OptionRules::assertHttpsOrigin('https://u:p@api.example', 'exportUrl')));
    }

    #[Test]
    public function reportsAFactoryRefusalWithoutItsMessageOrItsException(): void
    {
        $factory = ThrowingRequestFactory::refusingEvery('Unable to parse URI: https://api.example/?t=synthetic-token-91c2');

        $e = TraceArguments::caught(static fn() => OptionRules::assertBuildable($factory, 'wsPath', 'https://api.example/ws'));

        TraceArguments::assertOpaque($e, \InvalidArgumentException::class, 'wsPath is not a valid URL.', 'synthetic-token-91c2');
        self::assertSame([['GET', 'https://api.example/ws']], $factory->calls());
    }

    #[Test]
    public function buildsEveryUrlTheSdkBuildsFromOptionsAtTheLengthLimit(): void
    {
        // Curl handler of Guzzle 7.16.1: "HTTP request too large" at a request head above ~1 MiB; the longest request
        // path that still went through was 1048509 characters [observed 2026-11-15].
        $curlRequestHeadCap = 1_048_509;
        $factory = new HttpFactory();
        $base = 'https://' . str_repeat('a', 2048 - 8);
        $path = '/' . str_repeat('p', 2047);
        // A connect command with a 4 KiB synthetic token, percent-encoded as the SSE request carries it.
        $connect = json_encode(['token' => str_repeat('t', 4096), 'subs' => new \stdClass()], \JSON_THROW_ON_ERROR);
        $transport = new TransportOptions(baseUrl: $base, graphqlPath: $path);
        $live = new LiveOptions(wsPath: $path, ssePath: $path);
        $export = new ExportOptions(baseUrl: $base);

        $urls = [
            'graphql' => $transport->graphqlUrl(),
            'sse' => SseConnection::url($base, $live->ssePath, $connect),
            'ws' => WebSocketConnection::url($base, $live->wsPath),
            'export file' => rtrim($export->baseUrl, '/') . '/v1/prices_730.json.gz',
        ];

        foreach ($urls as $name => $url) {
            $request = $factory->createRequest('GET', $url);
            self::assertSame($url, (string) $request->getUri(), $name);
            self::assertLessThan($curlRequestHeadCap, \strlen($url), $name);
        }
        self::assertGreaterThan(8000, \strlen($urls['sse']), 'the SSE URL is the longest one');
    }

    /**
     * @param-immediately-invoked-callable $act
     *
     * @param \Closure(): mixed $act
     */
    private function refusal(\Closure $act): string
    {
        return TraceArguments::caught($act)->getMessage();
    }
}
