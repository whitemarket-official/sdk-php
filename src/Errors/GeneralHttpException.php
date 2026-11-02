<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * A non-2xx status no other subclass covers (for example 403 or 404). Catch {@see HttpException}.
 */
final class GeneralHttpException extends HttpException {}
