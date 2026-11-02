<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Profile;

/**
 * The account's standing in the trading competition (`PersonFull.tradingCompetitionStat`); both figures are null
 * when the account does not take part.
 */
final readonly class TradingCompetitionStat
{
    public function __construct(
        public ?int $position,
        public ?int $topLevel,
    ) {}
}
