<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Transport\OptionRules;
use WhiteMarket\Sdk\Transport\TransportOptions;
use WhiteMarket\Sdk\Version;

#[CoversClass(TransportOptions::class)]
#[CoversClass(OptionRules::class)]
final class TransportOptionsTest extends TestCase
{
    #[Test]
    public function defaultsToThePartnerEndpoint(): void
    {
        $options = new TransportOptions();

        self::assertSame('https://api.white.market/graphql/partner', $options->graphqlUrl());
        self::assertSame(Version::userAgent(), $options->userAgent());
        self::assertSame([10.0, 30.0, 33_554_432], [$options->connectTimeout, $options->requestTimeout, $options->maxResponseBytes]);
    }

    #[Test]
    public function replacesOnlyTheBaseUrl(): void
    {
        $options = new TransportOptions('https://api.example.test', '/graphql/custom', 2.5, 7.5, 1024, 'app/1.0');

        $changed = $options->withBaseUrl('https://staging.example.test');

        self::assertSame(
            ['baseUrl' => 'https://staging.example.test'] + get_object_vars($options),
            get_object_vars($changed),
        );
    }

    #[Test]
    public function validatesTheReplacementBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TransportOptions())->withBaseUrl('http://api.example.test');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function urls(): iterable
    {
        yield 'trailing slash' => ['https://api.example.test/', '/graphql/partner', 'https://api.example.test/graphql/partner'];
        yield 'port' => ['https://api.example.test:8443', '/gql', 'https://api.example.test:8443/gql'];
        yield 'upper-case scheme' => ['HTTPS://api.example.test', '/gql', 'HTTPS://api.example.test/gql'];
    }

    #[Test]
    #[DataProvider('urls')]
    public function joinsBaseUrlAndPath(string $baseUrl, string $path, string $expected): void
    {
        self::assertSame($expected, (new TransportOptions(baseUrl: $baseUrl, graphqlPath: $path))->graphqlUrl());
    }

    /**
     * @return iterable<string, array{\Closure(): TransportOptions}>
     */
    public static function invalid(): iterable
    {
        yield 'plain http' => [static fn(): TransportOptions => new TransportOptions(baseUrl: 'http://api.white.market')];
        yield 'base url with a path' => [static fn(): TransportOptions => new TransportOptions(baseUrl: 'https://api.white.market/graphql')];
        yield 'base url with a query' => [static fn(): TransportOptions => new TransportOptions(baseUrl: 'https://api.white.market?x=1')];
        yield 'space in the host' => [static fn(): TransportOptions => new TransportOptions(baseUrl: 'https://api.white .market')];
        yield 'user and password in the base url' => [static fn(): TransportOptions => new TransportOptions(baseUrl: 'https://user:pass@api.white.market')];
        yield 'no host' => [static fn(): TransportOptions => new TransportOptions(baseUrl: 'https://')];
        yield 'relative path' => [static fn(): TransportOptions => new TransportOptions(graphqlPath: 'graphql/partner')];
        yield 'path with a query' => [static fn(): TransportOptions => new TransportOptions(graphqlPath: '/graphql?x=1')];
        yield 'zero connect timeout' => [static fn(): TransportOptions => new TransportOptions(connectTimeout: 0.0)];
        yield 'negative request timeout' => [static fn(): TransportOptions => new TransportOptions(requestTimeout: -1.0)];
        yield 'zero response limit' => [static fn(): TransportOptions => new TransportOptions(maxResponseBytes: 0)];
        yield 'empty user agent suffix' => [static fn(): TransportOptions => new TransportOptions(userAgentSuffix: '')];
        yield 'header injection in the suffix' => [static fn(): TransportOptions => new TransportOptions(userAgentSuffix: "bot\r\nX-Injected: 1")];
        yield 'zero request timeout' => [static fn(): TransportOptions => new TransportOptions(requestTimeout: 0.0)];
        // `$` without the D modifier matches before a final line feed.
        yield 'trailing line feed in the base url' => [static fn(): TransportOptions => new TransportOptions(baseUrl: "https://api.white.market\n")];
        yield 'trailing line feed in the path' => [static fn(): TransportOptions => new TransportOptions(graphqlPath: "/graphql/partner\n")];
        yield 'trailing line feed in the suffix' => [static fn(): TransportOptions => new TransportOptions(userAgentSuffix: "bot\n")];
    }

    /**
     * @param \Closure(): TransportOptions $create
     */
    #[Test]
    #[DataProvider('invalid')]
    public function rejectsInvalidSettings(\Closure $create): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $create();
    }

    #[Test]
    public function acceptsTheSmallestValidValues(): void
    {
        $options = new TransportOptions(connectTimeout: 0.001, requestTimeout: 0.001, maxResponseBytes: 1, userAgentSuffix: 'a');

        self::assertSame(Version::userAgent() . ' a', $options->userAgent());
    }
}
