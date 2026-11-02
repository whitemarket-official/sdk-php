<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * HTTP 5xx. Catch this; the concrete class is {@see ServiceUnavailableException} for 503, {@see GeneralServerException} otherwise.
 */
abstract class ServerException extends HttpException {}
