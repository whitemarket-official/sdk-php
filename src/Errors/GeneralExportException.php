<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * An export could not be delivered to its destination (a directory given as a path, a file that cannot be written or
 * renamed). Catch {@see ExportException}.
 */
final class GeneralExportException extends ExportException {}
