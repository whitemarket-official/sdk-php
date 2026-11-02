<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * HTTP 401 that survived one token refresh and replay, or an invalid Partner Token.
 */
final class AuthenticationException extends HttpException {}
