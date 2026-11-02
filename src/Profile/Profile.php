<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Profile;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\Locale;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * The partner's own account (`person_profile`, and the result of the `email_*` mutations; SDK_SPEC 3.9).
 *
 * `steamTradeUrl` is a {@see Secret}: the trade URL carries the account's trade token. Call `->reveal()` to read it.
 */
final readonly class Profile
{
    /**
     * @param DeliveryPromise|UnknownEnumValue|null $deliveryPromise the seller promise the account selected
     * @param ?string $percent the server's "personal percentage based on points earned", kept as sent (its format is undocumented)
     * @param ?Secret $steamTradeUrl null when no trade URL is set
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $registeredAt,
        public Locale|UnknownEnumValue $locale,
        public ?string $email,
        public bool $isEmailConfirmed,
        public bool $canBuy,
        public bool $canSell,
        public bool $canDeposit,
        public bool $canWithdraw,
        public bool $isKycVerified,
        public bool $hasPartnerToken,
        public bool $ordersAutoActivate,
        public DeliveryPromise|UnknownEnumValue|null $deliveryPromise,
        public ?string $steamId,
        public ?string $steamName,
        public ?int $steamLevel,
        public ?string $steamAvatarMedium,
        public ?string $steamAvatarFull,
        public ?\DateTimeImmutable $steamRegisteredAt,
        #[\SensitiveParameter]
        public ?Secret $steamTradeUrl,
        public ?\DateTimeImmutable $inventoryUpdatedAt,
        public ?int $discountPercent,
        public ?\DateTimeImmutable $discountActiveTo,
        public ?\DateTimeImmutable $sellBannedUntil,
        public ?\DateTimeImmutable $vacationUntil,
        public ?int $level,
        public ?int $points,
        public ?int $leftPoints,
        public ?string $percent,
        public ?int $referralsCount,
        public ?string $inviterCode,
        public ?string $referrerCode,
        public ?string $referralTag,
        public ?string $countryCodeOfResidence,
        public ?string $countryCodeOfCurrentLocation,
        public bool $isAchievementsEnabled,
        public bool $isSkinTranslationEnabled,
        public bool $isMcBattlePassEnabled,
        public TradingCompetitionStat $tradingCompetitionStat,
    ) {}
}
