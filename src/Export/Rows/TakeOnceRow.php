<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

/**
 * A decoded row handed over once. json-machine keeps the value it yielded (in its generator and in locals) until the
 * next row is decoded, so a row yielded as such is alive together with its successor; yielded in this box, the row is
 * held by the caller alone, and dropped before the next one is decoded (SDK_SPEC 3.10 rule 10).
 *
 * @internal
 */
final class TakeOnceRow
{
    public function __construct(
        #[\SensitiveParameter]
        private mixed $row,
    ) {}

    public function take(): mixed
    {
        $row = $this->row;
        $this->row = null;

        return $row;
    }
}
