<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * HTTP 400: malformed request or more than one mutation in a request. Never retried.
 */
final class BadRequestException extends HttpException {}
