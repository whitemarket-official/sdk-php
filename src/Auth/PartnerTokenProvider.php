<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\HttpTransport;
use WhiteMarket\Sdk\Transport\SystemClock;

/**
 * Exchanges the Partner Token for an access token with `auth_token` (WHITEMARKET_API.md 3) and keeps it in the
 * {@see TokenStore} until {@see REFRESH_MARGIN_SECONDS} before it expires. `auth_token` is rate limited, so a token
 * another worker stored is reused instead of exchanging again (ADR 0003).
 *
 * @internal built by the client from the `partnerToken` option
 */
final readonly class PartnerTokenProvider implements TokenProvider
{
    private const REFRESH_MARGIN_SECONDS = 300;

    private GeneratedClient $exchange;

    public function __construct(
        HttpTransport $transport,
        #[\SensitiveParameter]
        Secret $partnerToken,
        private TokenStore $store = new InMemoryTokenStore(),
        private Clock $clock = new SystemClock(),
    ) {
        $this->exchange = new GeneratedClient(new GraphQLExecutor($transport, new PartnerTokenCredentials($partnerToken)));
    }

    public function accessToken(): Secret
    {
        $stored = $this->store->get();
        $now = $this->clock->now()->getTimestamp();
        if ($stored instanceof StoredToken && !BearerToken::isValid($stored->token->reveal())) {
            // Written by something else (a shared cache, an older SDK): never sent, replaced by a fresh exchange.
            $this->store->clear();
        } elseif ($stored instanceof StoredToken && $now < $stored->expiresAt->getTimestamp() - self::REFRESH_MARGIN_SECONDS) {
            return $stored->token;
        }

        return $this->refresh();
    }

    /**
     * @throws DecodeException the server's access token is not a valid bearer token; nothing is stored
     */
    public function refresh(): Secret
    {
        $token = $this->exchange->authToken()->accessToken;
        if (!BearerToken::isValid($token->reveal())) {
            throw new DecodeException('auth_token.accessToken', 'not a valid bearer token');
        }
        // A token that is already expired or within the margin is still used for the request that asked for it, and is
        // never served from the store afterwards (SDK_SPEC 3.1): the next request exchanges again.
        $this->store->set($token, JwtExpiry::of($token->reveal(), $this->clock->now()));

        return $token;
    }
}
