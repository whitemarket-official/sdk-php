<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * Sends the provider's token as `Authorization: Bearer` (SDK_SPEC 1.3). After a 401 it takes the provider's current
 * token (another worker may have stored a new one) or else forces a refresh, and offers a replay only with a token
 * different from the rejected one (SDK_SPEC 1.4). A token that is not a valid bearer token ({@see BearerToken}) is never
 * sent, whichever provider returned it.
 *
 * @internal built by the client from the auth options
 */
final readonly class BearerCredentials implements Credentials
{
    private const HEADER = 'Authorization';

    private const PREFIX = 'Bearer ';

    public function __construct(private TokenProvider $provider) {}

    /**
     * @throws \UnexpectedValueException the provider returned a value that is not a valid bearer token
     */
    public function headers(string $operationName): array
    {
        return $this->headersFor($this->provider->accessToken());
    }

    /**
     * @throws \UnexpectedValueException the provider returned a value that is not a valid bearer token
     */
    public function headersAfterRejection(#[\SensitiveParameter] array $rejected): ?array
    {
        $rejectedHeader = $rejected[self::HEADER] ?? null;
        $token = $this->provider->accessToken();
        if ($this->wasRejected($token, $rejectedHeader)) {
            $token = $this->provider->refresh();
        }

        return $this->wasRejected($token, $rejectedHeader) ? null : $this->headersFor($token);
    }

    /**
     * @return array<string, Secret>
     *
     * @throws \UnexpectedValueException the provider returned a value that is not a valid bearer token
     */
    private function headersFor(#[\SensitiveParameter] Secret $token): array
    {
        if (!BearerToken::isValid($token->reveal())) {
            throw new \UnexpectedValueException('The token provider returned a value that is not a valid bearer token.');
        }

        return [self::HEADER => new Secret(self::PREFIX . $token->reveal())];
    }

    /**
     * Whether the rejected request already carried this token.
     */
    private function wasRejected(#[\SensitiveParameter] Secret $token, #[\SensitiveParameter] ?Secret $rejectedHeader): bool
    {
        return $rejectedHeader instanceof Secret && hash_equals($rejectedHeader->reveal(), self::PREFIX . $token->reveal());
    }
}
