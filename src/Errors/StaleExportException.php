<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * The export is older than the caller accepts (`maxAgeSeconds` option, SDK_SPEC 3.10): its `data_as_of` is more than
 * `maxAgeSeconds` before now. The data file was not downloaded.
 */
final class StaleExportException extends ExportException
{
    public function __construct(
        public readonly \DateTimeImmutable $dataAsOf,
        public readonly int $maxAgeSeconds,
    ) {
        parent::__construct(\sprintf(
            'The export data is as of %s, older than the %d seconds allowed.',
            $dataAsOf->format(\DATE_ATOM),
            $maxAgeSeconds,
        ));
    }
}
