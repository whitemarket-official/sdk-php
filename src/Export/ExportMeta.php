<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

/**
 * The freshness sidecar of an export file, `<file>.meta.json` (SDK_SPEC 3.10). `dataAsOf` is the real age of the data
 * (always earlier than the file's `Last-Modified`), `generatedAt` when the file was built, `items` the record count.
 */
final readonly class ExportMeta
{
    public function __construct(
        public string $file,
        public \DateTimeImmutable $dataAsOf,
        public \DateTimeImmutable $generatedAt,
        public int $items,
    ) {}
}
