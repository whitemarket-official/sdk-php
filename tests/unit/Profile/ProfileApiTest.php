<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Operation\Query\PersonProfile\Result as ProfileResult;
use WhiteMarket\Sdk\Models\Locale;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Profile\Profile;
use WhiteMarket\Sdk\Profile\ProfileApi;
use WhiteMarket\Sdk\Tests\Support\DebugOutput;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\RetryPolicy;

#[CoversClass(ProfileApi::class)]
final class ProfileApiTest extends TestCase
{
    private const TOKEN_PAYLOAD = 'synthetic-steam-token-payload';
    private const PROXY = 'http://synthetic-user:synthetic-pass@proxy.invalid:8080';
    private const TRADE_URL = 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=syntheticTradeToken';

    private function api(FakeHttpClient $http, RetryPolicy $retry = new RetryPolicy()): ProfileApi
    {
        return new ProfileApi(TestTransport::generatedClient($http, $retry), new ModelMapper());
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invoke(ProfileApi $api, string $method, array $arguments): mixed
    {
        $callable = [$api, $method];
        self::assertIsCallable($callable);

        return $callable(...$arguments);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): ResponseInterface
    {
        return FakeHttpClient::json(['data' => $data]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function profile(array $overrides = []): array
    {
        return SyntheticResult::of(ProfileResult::class, ['id' => 'person-1'] + $overrides);
    }

    // ---- one request per method: operation, exact variables, result ----

    /**
     * @return iterable<string, array{string, list<mixed>, string, array<string, mixed>, array<string, mixed>, mixed}>
     *                                                                                                                 call, operationName, variables, response data, expected result (a Profile is compared by id)
     */
    public static function calls(): iterable
    {
        yield 'id' => ['id', [], 'ProfileId', [], ['profile_id' => 'person-77'], 'person-77'];
        yield 'setTradeUrl true' => ['setTradeUrl', [self::TRADE_URL], 'ProfileSteamLink', ['link' => ['value' => self::TRADE_URL]], ['profile_steam_link' => true], true];
        yield 'setTradeUrl false is the server Boolean' => ['setTradeUrl', [self::TRADE_URL], 'ProfileSteamLink', ['link' => ['value' => self::TRADE_URL]], ['profile_steam_link' => false], false];
        yield 'setSteamProxy without ttl' => ['setSteamProxy', [new Secret(self::PROXY)], 'ProfileSteamProxySet', ['proxy' => ['value' => self::PROXY]], ['profile_steam_proxy_set' => true], true];
        yield 'setSteamProxy with ttl passed through' => ['setSteamProxy', [new Secret(self::PROXY), 3600], 'ProfileSteamProxySet', ['proxy' => ['value' => self::PROXY], 'ttl' => 3600], ['profile_steam_proxy_set' => true], true];
        yield 'setSteamProxy with ttl 0 is not dropped' => ['setSteamProxy', [new Secret(self::PROXY), 0], 'ProfileSteamProxySet', ['proxy' => ['value' => self::PROXY], 'ttl' => 0], ['profile_steam_proxy_set' => true], true];
        yield 'deleteSteamProxy' => ['deleteSteamProxy', [], 'ProfileSteamProxyDelete', [], ['profile_steam_proxy_delete' => true], true];
        yield 'setSteamToken default sends ownProxyRequired false' => ['setSteamToken', [new Secret(self::TOKEN_PAYLOAD)], 'ProfileTokenSet', ['ownProxyRequired' => false, 'payload' => self::TOKEN_PAYLOAD], ['profile_token_set' => true], true];
        yield 'setSteamToken with ownProxyRequired and ttl' => ['setSteamToken', [new Secret(self::TOKEN_PAYLOAD), true, 60], 'ProfileTokenSet', ['ownProxyRequired' => true, 'payload' => self::TOKEN_PAYLOAD, 'ttl' => 60], ['profile_token_set' => true], true];
        yield 'checkSteamToken returns the Int verbatim' => ['checkSteamToken', [], 'ProfileTokenCheck', [], ['profile_token_check' => 7], 7];
        yield 'checkSteamToken null' => ['checkSteamToken', [], 'ProfileTokenCheck', [], ['profile_token_check' => null], null];
        yield 'deleteSteamToken' => ['deleteSteamToken', [], 'ProfileTokenDelete', [], ['profile_token_delete' => true], true];
        yield 'linkReferrer without tag' => ['linkReferrer', ['REF1'], 'ProfileReferrer', ['referrerInput' => ['referrerCode' => ['value' => 'REF1']]], ['profile_referrer' => true], true];
        yield 'linkReferrer with tag' => ['linkReferrer', ['REF1', 'tag-a'], 'ProfileReferrer', ['referrerInput' => ['referrerCode' => ['value' => 'REF1'], 'referrerTag' => 'tag-a']], ['profile_referrer' => true], true];
        yield 'changeReferrerCode' => ['changeReferrerCode', ['NEWCODE'], 'ProfileReferrerCode', ['referrerCode' => ['value' => 'NEWCODE']], ['profile_referrer_code' => true], true];
    }

    /**
     * @param list<mixed> $arguments
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('calls')]
    public function sendsOneRequestWithTheExactVariablesAndReturnsTheServerValue(string $method, array $arguments, string $operation, array $variables, array $data, mixed $expected): void
    {
        $http = new FakeHttpClient($this->ok($data));

        $result = $this->invoke($this->api($http), $method, $arguments);

        self::assertSame([[$operation, $variables]], $http->operations(true));
        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string, string}> method, arguments, operationName, root field
     */
    public static function profileCalls(): iterable
    {
        yield 'get' => ['get', [], 'PersonProfile', 'person_profile'];
        yield 'setEmail' => ['setEmail', ['someone@example.invalid'], 'EmailSet', 'email_set'];
        yield 'confirmEmail' => ['confirmEmail', ['123456', 'sign-1'], 'EmailConfirm', 'email_confirm'];
        yield 'resendEmailConfirmation' => ['resendEmailConfirmation', [], 'EmailConfirmationResend', 'email_confirmation_resend'];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[Test]
    #[DataProvider('profileCalls')]
    public function everyProfileReturningMethodMapsTheSameProfile(string $method, array $arguments, string $operation, string $field): void
    {
        $http = new FakeHttpClient($this->ok([$field => $this->profile(['email' => 'someone@example.invalid', 'locale' => 'UK_UA'])]));

        $profile = $this->invoke($this->api($http), $method, $arguments);

        self::assertSame($operation, ($http->operations()[0] ?? [])[0] ?? null);
        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame('person-1', $profile->id);
        self::assertSame(Locale::UkUa, $profile->locale);
    }

    #[Test]
    public function setEmailAndConfirmEmailSendTheirVariables(): void
    {
        $http = new FakeHttpClient(
            $this->ok(['email_set' => $this->profile()]),
            $this->ok(['email_confirm' => $this->profile()]),
            $this->ok(['email_confirm' => $this->profile()]),
        );
        $api = $this->api($http);

        $api->setEmail('someone@example.invalid');
        $api->confirmEmail('123456');
        $api->confirmEmail('123456', 'sign-1');

        self::assertSame([
            ['EmailSet', ['email' => ['value' => 'someone@example.invalid']]],
            ['EmailConfirm', ['code' => '123456']],
            ['EmailConfirm', ['code' => '123456', 'sign' => 'sign-1']],
        ], $http->operations(true));
    }

    // ---- steamProxy ----

    /**
     * @return iterable<string, array{?string, ?string}> server value, revealed secret
     */
    public static function proxyAnswers(): iterable
    {
        yield 'a URL -> Secret' => [self::PROXY, self::PROXY];
        yield 'null -> null' => [null, null];
        yield 'empty string -> null' => ['', null];
    }

    #[Test]
    #[DataProvider('proxyAnswers')]
    public function steamProxyWrapsTheUrlInASecretAndMapsNothingToNull(?string $server, ?string $expected): void
    {
        $http = new FakeHttpClient($this->ok(['profile_steam_proxy_get' => $server]));

        $proxy = $this->api($http)->steamProxy();

        self::assertSame([['ProfileSteamProxyGet', []]], $http->operations());
        if ($expected === null) {
            self::assertNull($proxy);

            return;
        }
        self::assertInstanceOf(Secret::class, $proxy);
        self::assertSame($expected, $proxy->reveal());
        foreach (DebugOutput::all($proxy) as $output) {
            self::assertStringNotContainsString('synthetic-pass', $output);
        }
    }

    #[Test]
    public function steamProxyIsRetriedLikeAQuery(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(503), $this->ok(['profile_steam_proxy_get' => null]));

        self::assertNull($this->api($http, new RetryPolicy(maxAttempts: 2))->steamProxy());
        self::assertCount(2, $http->requests());
    }

    // ---- mutations are never retried ----

    /**
     * @return iterable<string, array{string, list<mixed>}> method, arguments
     */
    public static function mutations(): iterable
    {
        yield 'setTradeUrl' => ['setTradeUrl', [self::TRADE_URL]];
        yield 'setSteamProxy' => ['setSteamProxy', [new Secret(self::PROXY)]];
        yield 'deleteSteamProxy' => ['deleteSteamProxy', []];
        yield 'setSteamToken' => ['setSteamToken', [new Secret(self::TOKEN_PAYLOAD)]];
        yield 'checkSteamToken' => ['checkSteamToken', []];
        yield 'deleteSteamToken' => ['deleteSteamToken', []];
        yield 'setEmail' => ['setEmail', ['someone@example.invalid']];
        yield 'confirmEmail' => ['confirmEmail', ['123456']];
        yield 'resendEmailConfirmation' => ['resendEmailConfirmation', []];
        yield 'linkReferrer' => ['linkReferrer', ['REF1']];
        yield 'changeReferrerCode' => ['changeReferrerCode', ['NEWCODE']];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[Test]
    #[DataProvider('mutations')]
    public function aMutationAnswered503IsSentExactlyOnceAndTheFailurePropagates(string $method, array $arguments): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(503), FakeHttpClient::status(503));

        try {
            $this->invoke($this->api($http, new RetryPolicy(maxAttempts: 3)), $method, $arguments);
            self::fail('Expected a WhiteMarketException');
        } catch (WhiteMarketException) {
            self::assertCount(1, $http->requests());
        }
    }

    // ---- secrets stay out of failures ----

    /**
     * @return iterable<string, array{string, list<mixed>, string}> method, arguments, the secret it sends
     */
    public static function secretCalls(): iterable
    {
        yield 'setSteamToken payload' => ['setSteamToken', [new Secret(self::TOKEN_PAYLOAD)], self::TOKEN_PAYLOAD];
        yield 'setSteamProxy credentials' => ['setSteamProxy', [new Secret(self::PROXY)], 'synthetic-pass'];
        yield 'setTradeUrl token' => ['setTradeUrl', [self::TRADE_URL], 'syntheticTradeToken'];
        yield 'confirmEmail code' => ['confirmEmail', ['synthetic-code-424242'], 'synthetic-code-424242'];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[Test]
    #[DataProvider('secretCalls')]
    public function aFailedMutationRevealsTheSecretNeitherInTheMessageNorInTheTrace(string $method, array $arguments, string $secret): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(500));
        $api = $this->api($http);

        $e = TraceArguments::caught(function () use ($method, $arguments, $api): void {
            $this->invoke($api, $method, $arguments);
        });

        self::assertInstanceOf(WhiteMarketException::class, $e);
        self::assertStringContainsString($secret, $http->lastBody(), 'the secret is sent in the request body');
        self::assertStringNotContainsString($secret, $e->getMessage());
        self::assertStringNotContainsString($secret, TraceArguments::exportedSdkFrames($e));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretParameterMethods(): iterable
    {
        yield 'setSteamToken' => ['setSteamToken'];
        yield 'setSteamProxy' => ['setSteamProxy'];
    }

    /**
     * A `Secret` masks itself, but the trace must not hold the object at all: a handler that unwraps arguments (or a
     * future change to `Secret`) would reveal it. `#[\SensitiveParameter]` replaces it with `SensitiveParameterValue`.
     */
    #[Test]
    #[DataProvider('secretParameterMethods')]
    public function theSecretArgumentIsReplacedInTheTraceFrameOfTheMethod(string $method): void
    {
        $api = $this->api(new FakeHttpClient(FakeHttpClient::status(500)));

        $e = TraceArguments::caught(function () use ($method, $api): void {
            $this->invoke($api, $method, [new Secret('synthetic-secret-argument')]);
        });

        $frames = array_values(array_filter(
            $e->getTrace(),
            static fn(array $frame): bool => ($frame['class'] ?? null) === ProfileApi::class && $frame['function'] === $method,
        ));
        self::assertCount(1, $frames);
        self::assertInstanceOf(\SensitiveParameterValue::class, $frames[0]['args'][0] ?? null);
    }

    // ---- Profile mapping ----

    #[Test]
    public function theProfileNeverPrintsTheTradeUrl(): void
    {
        $profile = $this->api(new FakeHttpClient($this->ok(['person_profile' => $this->profile(['steamTradeUrl' => self::TRADE_URL])])))->get();

        $outputs = DebugOutput::all($profile);
        foreach ($outputs as $output) {
            self::assertStringNotContainsString('syntheticTradeToken', $output);
        }
        self::assertStringContainsString('***', $outputs['print_r']);
    }
}
