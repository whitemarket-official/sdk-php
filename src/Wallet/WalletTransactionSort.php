<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\WalletTransactionSortInput;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Models\TransactionSortField;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * How the wallet transactions are ordered (see {@see WalletTransactionFilter::$sort}).
 */
final readonly class WalletTransactionSort
{
    public function __construct(
        public TransactionSortField $field,
        public SortDirection $direction = SortDirection::Asc,
    ) {}

    /**
     * @internal used by {@see WalletTransactionFilter}
     */
    public function toInput(): WalletTransactionSortInput
    {
        $wire = new WireEncoder();

        return new WalletTransactionSortInput(
            $wire->enum(Wire\WalletTransactionSortField::class, $this->field),
            $wire->enum(Wire\SortType::class, $this->direction),
        );
    }
}
