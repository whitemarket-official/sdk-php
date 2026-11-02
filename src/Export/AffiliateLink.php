<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

/**
 * Adds a referral code to a `market_product_link` of an export row (SDK_SPEC 3.10): the `ref` query parameter of the
 * affiliate program. Only https links on `white.market` or a subdomain are accepted: export rows are data, and the
 * SDK never builds a link to another host from them (SDK_SPEC 8).
 */
final class AffiliateLink
{
    private const DOMAIN = 'white.market';

    private function __construct() {}

    /**
     * The link with `ref=<code>` as its last query parameter, replacing any `ref` it already had; the fragment is kept.
     *
     * @throws \InvalidArgumentException the link is not an https URL on white.market (a port or credentials in it
     *                                   included), or the code is empty
     */
    public static function of(string $marketProductLink, string $referralCode): string
    {
        if ($referralCode === '') {
            throw new \InvalidArgumentException('The referral code must not be empty.');
        }
        // `D`: without it `$` also matches before a final line feed. No whitespace or control byte anywhere.
        if (preg_match('~^https://([^/?#]+)([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~iD', $marketProductLink, $match) !== 1
            || preg_match('/^[\x21-\x7E]+$/D', $marketProductLink) !== 1) {
            throw new \InvalidArgumentException('The link is not an https URL.');
        }
        $host = strtolower($match[1]);
        if ($host !== self::DOMAIN && !str_ends_with($host, '.' . self::DOMAIN) || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            throw new \InvalidArgumentException('The link must point to ' . self::DOMAIN . ' or a subdomain of it.');
        }

        $parameters = [];
        foreach (explode('&', $match[3] ?? '') as $parameter) {
            if ($parameter !== '' && explode('=', $parameter, 2)[0] !== 'ref') {
                $parameters[] = $parameter;
            }
        }
        $parameters[] = 'ref=' . rawurlencode($referralCode);
        $fragment = isset($match[4]) ? '#' . $match[4] : '';

        return 'https://' . $match[1] . $match[2] . '?' . implode('&', $parameters) . $fragment;
    }
}
