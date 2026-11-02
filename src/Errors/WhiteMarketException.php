<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * Base of every exception the SDK throws for a failed API interaction (SDK_SPEC 1.5); never thrown directly.
 * Messages never contain tokens or request bodies. Branch on the subclass and on {@see GraphQLErrorEntry::$category},
 * never on the message.
 */
abstract class WhiteMarketException extends \RuntimeException {}
