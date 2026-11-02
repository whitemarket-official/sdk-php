<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Profile;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\PersonEmailInput;
use WhiteMarket\Sdk\Generated\Input\ProxyInput;
use WhiteMarket\Sdk\Generated\Input\ReferrerCodeInput;
use WhiteMarket\Sdk\Generated\Input\ReferrerInput;
use WhiteMarket\Sdk\Generated\Input\SteamLinkInput;
use WhiteMarket\Sdk\Models\ModelMapper;

/**
 * `$client->profile` (SDK_SPEC 3.9): the partner's own account, Steam link, Steam proxy and token, email and referrer.
 *
 * {@see get()}, {@see id()} and {@see steamProxy()} are queries, so the transport retries them on transient failures
 * (SDK_SPEC 1.4). Every other method is a mutation: it is sent exactly once and never retried (CLAUDE.md rule 2), and a
 * failure propagates as it is. No money moves here, so there is no "outcome unknown" wrapper; the caller may repeat a
 * call after checking the state with {@see get()}.
 *
 * The Steam proxy URL and the Steam token payload are secrets: they are taken and returned as {@see Secret} and
 * revealed only when the request body is built.
 */
final readonly class ProfileApi
{
    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
    ) {}

    /**
     * The person id of the account (`profile_id`).
     *
     * @throws WhiteMarketException
     */
    public function id(): string
    {
        return $this->api->profileId();
    }

    /**
     * The account (`person_profile`).
     *
     * @throws WhiteMarketException
     */
    public function get(): Profile
    {
        return $this->mapper->profile($this->api->personProfile());
    }

    /**
     * Sets the Steam trade URL (`profile_steam_link`).
     *
     * @return bool the server's answer
     *
     * @throws WhiteMarketException
     */
    public function setTradeUrl(#[\SensitiveParameter] string $url): bool
    {
        return $this->api->profileSteamLink(new SteamLinkInput($url));
    }

    /**
     * The Steam proxy URL with its credentials (`profile_steam_proxy_get`); null when none is set.
     *
     * @throws WhiteMarketException
     */
    public function steamProxy(): ?Secret
    {
        return $this->mapper->optionalSecret($this->api->profileSteamProxyGet());
    }

    /**
     * Sets the Steam proxy (`profile_steam_proxy_set`).
     *
     * @param ?int $ttl passed to the server unchanged; the API documents neither its unit nor its meaning (proposal P16 point 3)
     *
     * @return bool the server's answer
     *
     * @throws WhiteMarketException
     */
    public function setSteamProxy(#[\SensitiveParameter] Secret $proxy, ?int $ttl = null): bool
    {
        return $this->api->profileSteamProxySet(new ProxyInput($proxy->reveal()), $ttl);
    }

    /**
     * Removes the Steam proxy (`profile_steam_proxy_delete`).
     *
     * @return bool the server's answer
     *
     * @throws WhiteMarketException
     */
    public function deleteSteamProxy(): bool
    {
        return $this->api->profileSteamProxyDelete();
    }

    /**
     * Stores the Steam token payload (`profile_token_set`).
     *
     * @param bool $ownProxyRequired always sent; the server's default is false
     * @param ?int $ttl passed to the server unchanged; the API documents neither its unit nor its meaning (proposal P16 point 3)
     *
     * @return bool the server's answer
     *
     * @throws WhiteMarketException
     */
    public function setSteamToken(#[\SensitiveParameter] Secret $payload, bool $ownProxyRequired = false, ?int $ttl = null): bool
    {
        return $this->api->profileTokenSet($payload->reveal(), $ownProxyRequired, $ttl);
    }

    /**
     * Asks the server to check the stored Steam token (`profile_token_check`). A mutation, sent once.
     *
     * @return ?int the server's Int, unchanged; the API does not document what the number means
     *
     * @throws WhiteMarketException
     */
    public function checkSteamToken(): ?int
    {
        return $this->api->profileTokenCheck();
    }

    /**
     * Removes the stored Steam token (`profile_token_delete`).
     *
     * @return bool the server's answer
     *
     * @throws WhiteMarketException
     */
    public function deleteSteamToken(): bool
    {
        return $this->api->profileTokenDelete();
    }

    /**
     * Sets the email (`email_set`); the server sends a confirmation code to it.
     *
     * @throws WhiteMarketException
     */
    public function setEmail(#[\SensitiveParameter] string $email): Profile
    {
        return $this->mapper->profile($this->api->emailSet(new PersonEmailInput($email)));
    }

    /**
     * Confirms the email with the code from the message (`email_confirm`).
     *
     * @throws WhiteMarketException
     */
    public function confirmEmail(#[\SensitiveParameter] string $code, #[\SensitiveParameter] ?string $sign = null): Profile
    {
        return $this->mapper->profile($this->api->emailConfirm($code, $sign));
    }

    /**
     * Sends the confirmation message again (`email_confirmation_resend`).
     *
     * @throws WhiteMarketException
     */
    public function resendEmailConfirmation(): Profile
    {
        return $this->mapper->profile($this->api->emailConfirmationResend());
    }

    /**
     * Links the account to the referrer with this code (`profile_referrer`).
     *
     * @return bool the server's answer
     *
     * @throws WhiteMarketException
     */
    public function linkReferrer(#[\SensitiveParameter] string $code, ?string $tag = null): bool
    {
        return $this->api->profileReferrer(new ReferrerInput(new ReferrerCodeInput($code), $tag));
    }

    /**
     * Changes the account's own referrer code (`profile_referrer_code`).
     *
     * @return bool the server's answer
     *
     * @throws WhiteMarketException
     */
    public function changeReferrerCode(#[\SensitiveParameter] string $code): bool
    {
        return $this->api->profileReferrerCode(new ReferrerCodeInput($code));
    }
}
