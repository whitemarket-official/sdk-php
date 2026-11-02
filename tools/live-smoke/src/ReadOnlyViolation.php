<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

/**
 * A mutation reached the live smoke's executor. A programming error, never an API outcome.
 */
final class ReadOnlyViolation extends \LogicException {}
