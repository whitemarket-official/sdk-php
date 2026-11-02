<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

/**
 * Owns the closing contract of {@see RowSource} for any decoder: the wrapped source is closed exactly once, at the end
 * of the body, on any failure (the failure is rethrown unchanged) and on {@see close()}; after that {@see next()}
 * returns false without reaching the wrapped source.
 *
 * @internal
 */
final class GuardedRowSource implements RowSource
{
    private bool $closed = false;

    public function __construct(
        private readonly RowSource $inner,
    ) {}

    public function next(): bool
    {
        if ($this->closed) {
            return false;
        }
        try {
            if ($this->inner->next()) {
                return true;
            }
        } catch (\Throwable $e) {
            $this->close();

            throw $e;
        }
        $this->close();

        return false;
    }

    public function row(): mixed
    {
        return $this->inner->row();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->inner->close();
    }
}
