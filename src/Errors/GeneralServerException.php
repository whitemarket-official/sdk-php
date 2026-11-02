<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * HTTP 5xx other than 503. Catch {@see ServerException}.
 */
final class GeneralServerException extends ServerException {}
