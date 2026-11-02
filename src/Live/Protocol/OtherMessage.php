<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * A well-formed message of a kind this SDK does not model (a `join`, `leave`, `message` or `refresh` push, a push or
 * top-level key of a newer server): ignored, never an error (CLAUDE.md rule 5). Carries nothing, the content is server
 * data.
 *
 * @internal
 */
final readonly class OtherMessage {}
