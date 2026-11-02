<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Doppler / Gamma Doppler phase.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum Phase: string
{
    case BlackPearl = 'BLACK_PEARL';
    case Emerald = 'EMERALD';
    case Phase1 = 'PHASE1';
    case Phase2 = 'PHASE2';
    case Phase3 = 'PHASE3';
    case Phase4 = 'PHASE4';
    case Ruby = 'RUBY';
    case Sapphire = 'SAPPHIRE';
}
