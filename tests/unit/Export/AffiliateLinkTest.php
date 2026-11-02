<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\AffiliateLink;

#[CoversClass(AffiliateLink::class)]
final class AffiliateLinkTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function links(): iterable
    {
        yield 'no query' => ['https://white.market/item/x', 'abc', 'https://white.market/item/x?ref=abc'];
        yield 'an existing query' => ['https://white.market/item/x?id=1', 'abc', 'https://white.market/item/x?id=1&ref=abc'];
        yield 'an existing ref is replaced' => ['https://white.market/item/x?ref=old&id=1', 'abc', 'https://white.market/item/x?id=1&ref=abc'];
        yield 'a ref among several' => ['https://white.market/x?a=1&ref=old&b=2&ref=older', 'abc', 'https://white.market/x?a=1&b=2&ref=abc'];
        yield 'a parameter that only starts with ref' => ['https://white.market/x?reference=1', 'abc', 'https://white.market/x?reference=1&ref=abc'];
        yield 'the fragment is kept' => ['https://white.market/x?id=1#details', 'abc', 'https://white.market/x?id=1&ref=abc#details'];
        yield 'a fragment without a query' => ['https://white.market/x#details', 'abc', 'https://white.market/x?ref=abc#details'];
        yield 'an empty query mark' => ['https://white.market/x?', 'abc', 'https://white.market/x?ref=abc'];
        yield 'a subdomain' => ['https://app.white.market/x', 'abc', 'https://app.white.market/x?ref=abc'];
        yield 'a host in capitals' => ['HTTPS://White.Market/x', 'abc', 'https://White.Market/x?ref=abc'];
        yield 'a deeper subdomain in capitals' => ['https://A.B.WHITE.MARKET/x', 'abc', 'https://A.B.WHITE.MARKET/x?ref=abc'];
        yield 'a dash in a label' => ['https://my-shop.white.market/x', 'abc', 'https://my-shop.white.market/x?ref=abc'];
        yield 'the bare host' => ['https://white.market', 'abc', 'https://white.market?ref=abc'];
        yield 'the code is percent-encoded' => ['https://white.market/x', 'a b&c=d#e', 'https://white.market/x?ref=a%20b%26c%3Dd%23e'];
    }

    #[Test]
    #[DataProvider('links')]
    public function setsTheRefParameter(string $link, string $code, string $expected): void
    {
        self::assertSame($expected, AffiliateLink::of($link, $code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'another host' => ['https://evil.example.test/x'];
        yield 'a lookalike suffix' => ['https://notwhite.market/x'];
        yield 'white.market as a subdomain of another host' => ['https://white.market.evil.example.test/x'];
        yield 'white.market in the path' => ['https://evil.example.test/white.market'];
        yield 'credentials before the host' => ['https://white.market@evil.example.test/x'];
        yield 'credentials with white.market host' => ['https://user@white.market/x'];
        yield 'a port' => ['https://white.market:8443/x'];
        yield 'plain http' => ['http://white.market/x'];
        yield 'protocol-relative' => ['//white.market/x'];
        yield 'a relative path' => ['/item/x'];
        yield 'empty' => [''];
        yield 'whitespace in the link' => ['https://white.market/x y'];
        yield 'a final line feed' => ["https://white.market/x\n"];
        yield 'a final line feed after a query' => ["https://white.market/x?a=1\n"];
        yield 'a final line feed after a fragment' => ["https://white.market/x#f\n"];
        yield 'a line feed inside the fragment' => ["https://white.market/x#a\nb"];
        yield 'a line feed before the host' => ["https://\nwhite.market/x"];
        yield 'a host with a prefix' => ['https://xwhite.market/x'];
        yield 'a host with a suffix' => ['https://white.market.evil.test/x'];
        yield 'the domain only in the path' => ['https://evil.test/white.market'];
        yield 'the domain only in the query' => ['https://evil.test?u=white.market'];
        yield 'the domain only in the fragment' => ['https://evil.test#.white.market'];
        yield 'credentials before a subdomain' => ['https://user@app.white.market/x'];
        yield 'credentials with a password before a subdomain' => ['https://user:pw@app.white.market/x'];
        yield 'an underscore before the subdomain label' => ['https://a_b.white.market/x'];
        yield 'a space before a subdomain' => ['https:// app.white.market/x'];
        yield 'a dot-less lookalike with a dash' => ['https://evil-white.market/x'];
        yield 'a prefix before the scheme' => ['xhttps://white.market/x'];
        yield 'a text before the scheme' => [" https://white.market/x"];
    }

    #[Test]
    #[DataProvider('refusedLinks')]
    public function refusesALinkThatIsNotAnHttpsLinkOnWhiteMarket(string $link): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AffiliateLink::of($link, 'abc');
    }

    #[Test]
    public function refusesAnEmptyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AffiliateLink::of('https://white.market/x', '');
    }
}
