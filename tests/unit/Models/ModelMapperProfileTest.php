<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Generated\Operation\Mutation\EmailSet\Result as EmailSetResult;
use WhiteMarket\Sdk\Generated\Operation\Query\PersonProfile\Result;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\Locale;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Profile\Profile;
use WhiteMarket\Sdk\Profile\TradingCompetitionStat;
use WhiteMarket\Sdk\Tests\Support\DebugOutput;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(Profile::class)]
#[CoversClass(TradingCompetitionStat::class)]
final class ModelMapperProfileTest extends TestCase
{
    private const TRADE_URL = 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=syntheticTradeToken';

    /**
     * @param array<string, mixed> $overrides
     */
    private function map(array $overrides = []): Profile
    {
        return (new ModelMapper())->profile(Result::fromValue(SyntheticResult::of(Result::class, ['id' => 'p-1'] + $overrides), 'person_profile'));
    }

    #[Test]
    public function mapsEveryField(): void
    {
        $profile = $this->map([
            'registeredAt' => '2026-01-02T03:04:05+00:00',
            'locale' => 'RU_RU',
            'email' => 'someone@example.invalid',
            'isEmailConfirmed' => true,
            'canBuy' => true,
            'canSell' => true,
            'canDeposit' => true,
            'canWithdraw' => true,
            'isKycVerified' => true,
            'isPartnerTokenExist' => true,
            'marketOrderAutoActivate' => true,
            'deliveryPromiseSelected' => 'HOURS12',
            'steamId' => '76561190000000001',
            'steamName' => 'Synthetic Name',
            'steamLevel' => 12,
            'steamAvatarMedium' => 'https://avatars.invalid/m.jpg',
            'steamAvatarFull' => 'https://avatars.invalid/f.jpg',
            'steamRegisteredAt' => '2020-05-06T07:08:09+00:00',
            'steamTradeUrl' => self::TRADE_URL,
            'inventoryUpdatedAt' => '2026-02-03T04:05:06+00:00',
            'discountPercent' => 5,
            'discountActiveTo' => '2026-03-04T05:06:07+00:00',
            'sellBannedUntil' => '2026-04-05T06:07:08+00:00',
            'vacationUntil' => '2026-05-06T07:08:09+00:00',
            'level' => 3,
            'points' => 100,
            'leftPoints' => 20,
            'percent' => '42.5',
            'referralsCount' => 8,
            'inviterCode' => 'INV',
            'referrerCode' => 'REF',
            'referralTag' => 'TAG',
            'countryCodeOfResidence' => 'UA',
            'countryCodeOfCurrentLocation' => 'PL',
            'isAchievementsEnabled' => true,
            'isSkinTranslationEnabled' => true,
            'isMcBattlePassEnabled' => true,
            'tradingCompetitionStat' => ['position' => 4, 'topLevel' => 2],
        ]);

        self::assertSame('p-1', $profile->id);
        self::assertSame('2026-01-02T03:04:05+00:00', $profile->registeredAt->format(\DATE_ATOM));
        self::assertSame(Locale::RuRu, $profile->locale);
        self::assertSame('someone@example.invalid', $profile->email);
        self::assertTrue($profile->isEmailConfirmed);
        self::assertTrue($profile->canBuy);
        self::assertTrue($profile->canSell);
        self::assertTrue($profile->canDeposit);
        self::assertTrue($profile->canWithdraw);
        self::assertTrue($profile->isKycVerified);
        self::assertTrue($profile->hasPartnerToken);
        self::assertTrue($profile->ordersAutoActivate);
        self::assertSame(DeliveryPromise::Hours12, $profile->deliveryPromise);
        self::assertSame('76561190000000001', $profile->steamId);
        self::assertSame('Synthetic Name', $profile->steamName);
        self::assertSame(12, $profile->steamLevel);
        self::assertSame('https://avatars.invalid/m.jpg', $profile->steamAvatarMedium);
        self::assertSame('https://avatars.invalid/f.jpg', $profile->steamAvatarFull);
        self::assertSame('2020-05-06T07:08:09+00:00', $profile->steamRegisteredAt?->format(\DATE_ATOM));
        self::assertInstanceOf(Secret::class, $profile->steamTradeUrl);
        self::assertSame(self::TRADE_URL, $profile->steamTradeUrl->reveal());
        self::assertSame('2026-02-03T04:05:06+00:00', $profile->inventoryUpdatedAt?->format(\DATE_ATOM));
        self::assertSame(5, $profile->discountPercent);
        self::assertSame('2026-03-04T05:06:07+00:00', $profile->discountActiveTo?->format(\DATE_ATOM));
        self::assertSame('2026-04-05T06:07:08+00:00', $profile->sellBannedUntil?->format(\DATE_ATOM));
        self::assertSame('2026-05-06T07:08:09+00:00', $profile->vacationUntil?->format(\DATE_ATOM));
        self::assertSame(3, $profile->level);
        self::assertSame(100, $profile->points);
        self::assertSame(20, $profile->leftPoints);
        self::assertSame('42.5', $profile->percent);
        self::assertSame(8, $profile->referralsCount);
        self::assertSame('INV', $profile->inviterCode);
        self::assertSame('REF', $profile->referrerCode);
        self::assertSame('TAG', $profile->referralTag);
        self::assertSame('UA', $profile->countryCodeOfResidence);
        self::assertSame('PL', $profile->countryCodeOfCurrentLocation);
        self::assertTrue($profile->isAchievementsEnabled);
        self::assertTrue($profile->isSkinTranslationEnabled);
        self::assertTrue($profile->isMcBattlePassEnabled);
        self::assertSame([4, 2], [$profile->tradingCompetitionStat->position, $profile->tradingCompetitionStat->topLevel]);
    }

    #[Test]
    public function mapsAnEmptyAccountToNulls(): void
    {
        $profile = $this->map();

        self::assertNull($profile->email);
        self::assertNull($profile->deliveryPromise);
        self::assertNull($profile->steamTradeUrl);
        self::assertNull($profile->percent);
        self::assertSame([null, null], [$profile->tradingCompetitionStat->position, $profile->tradingCompetitionStat->topLevel]);
        self::assertFalse($profile->hasPartnerToken);
        self::assertFalse($profile->ordersAutoActivate);
    }

    #[Test]
    public function renamesTheWireFieldsToTheSdkNames(): void
    {
        $profile = $this->map(['isPartnerTokenExist' => true, 'deliveryPromiseSelected' => 'MINUTES10', 'locale' => 'EN_US']);
        $other = $this->map(['marketOrderAutoActivate' => true]);

        self::assertTrue($profile->hasPartnerToken);
        self::assertFalse($profile->ordersAutoActivate);
        self::assertFalse($other->hasPartnerToken);
        self::assertTrue($other->ordersAutoActivate);
        self::assertSame(DeliveryPromise::Minutes10, $profile->deliveryPromise);
        self::assertSame(Locale::EnUs, $profile->locale);
    }

    #[Test]
    public function keepsTheFlagsApart(): void
    {
        $profile = $this->map(['canBuy' => true, 'canDeposit' => true, 'isKycVerified' => true]);

        self::assertTrue($profile->canBuy);
        self::assertFalse($profile->canSell);
        self::assertTrue($profile->canDeposit);
        self::assertFalse($profile->canWithdraw);
        self::assertTrue($profile->isKycVerified);
        self::assertFalse($profile->isEmailConfirmed);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function tradeUrls(): iterable
    {
        yield 'set' => ['https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic', 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic'];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
    }

    #[Test]
    #[DataProvider('tradeUrls')]
    public function wrapsTheTradeUrlInASecretAndMapsEmptyToNull(?string $server, ?string $expected): void
    {
        $profile = $this->map(['steamTradeUrl' => $server]);

        if ($expected === null) {
            self::assertNull($profile->steamTradeUrl);

            return;
        }
        self::assertInstanceOf(Secret::class, $profile->steamTradeUrl);
        self::assertSame($expected, $profile->steamTradeUrl->reveal());
        foreach (DebugOutput::all($profile) as $output) {
            self::assertStringNotContainsString('token=synthetic', $output);
        }
    }

    #[Test]
    public function aNewLocaleAndDeliveryPromiseStayUnknown(): void
    {
        $profile = $this->map(['locale' => 'JA_JP', 'deliveryPromiseSelected' => 'MINUTES5']);

        self::assertInstanceOf(UnknownEnumValue::class, $profile->locale);
        self::assertSame('JA_JP', $profile->locale->value);
        self::assertInstanceOf(UnknownEnumValue::class, $profile->deliveryPromise);
        self::assertSame('MINUTES5', $profile->deliveryPromise->value);
    }

    #[Test]
    public function mapsTheTradingCompetitionStat(): void
    {
        $profile = $this->map(['tradingCompetitionStat' => ['position' => 9, 'topLevel' => 1]]);

        self::assertSame(9, $profile->tradingCompetitionStat->position);
        self::assertSame(1, $profile->tradingCompetitionStat->topLevel);
    }

    #[Test]
    public function theEmailMutationsResultMapsLikeThePersonProfile(): void
    {
        $profile = (new ModelMapper())->profile(
            EmailSetResult::fromValue(SyntheticResult::of(EmailSetResult::class, ['id' => 'p-2', 'email' => 'someone@example.invalid', 'isEmailConfirmed' => true]), 'email_set'),
        );

        self::assertSame('p-2', $profile->id);
        self::assertSame('someone@example.invalid', $profile->email);
        self::assertTrue($profile->isEmailConfirmed);
    }
}
