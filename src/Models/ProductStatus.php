<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Why a listing left the market ({@see ProductHistory}). A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum ProductStatus: string
{
    /** Sold: the listing became a deal. */
    case Deal = 'DEAL';
    case Deleted = 'DELETED';
    case Expired = 'EXPIRED';
    case Gone = 'GONE';
}
