<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * Every error of the response has a category ending with `.not_found` (SDK_SPEC 1.5).
 */
final class NotFoundException extends GraphQLException {}
