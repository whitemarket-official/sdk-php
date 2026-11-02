<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Who sends the Steam trade offer of a listing (WHITEMARKET_API.md 4). A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum DeliveryType: string
{
    /** WhiteMarket sends the offer, the seller confirms it in Steam Mobile (the default for new listings). */
    case Semi = 'SEMI';
    /** The seller sends the offer. */
    case Manual = 'MANUAL';
    /** Reserved by the server; listing with it currently fails. */
    case Auto = 'AUTO';
}
