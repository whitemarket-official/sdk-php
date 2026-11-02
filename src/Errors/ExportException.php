<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * An export download or parse failed (SDK_SPEC 1.5 `ExportError`); the concrete class tells which one. Non-2xx
 * statuses and network failures of an export GET are not export errors: they are the {@see HttpException} and
 * {@see TransportException} every other call throws, so one `catch` handles both APIs.
 */
abstract class ExportException extends WhiteMarketException {}
