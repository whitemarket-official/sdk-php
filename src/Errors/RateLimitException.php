<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * HTTP 429. `retryAfter` (seconds) is set when the server sent `Retry-After`.
 */
final class RateLimitException extends HttpException {}
