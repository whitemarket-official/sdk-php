<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Live\Event\LiveEvent;

/**
 * The outcome of {@see EventMapper::map()}: the event to deliver and, when a known type had malformed content, the
 * {@see DecodeException} for `onError` (the event is then an `UnknownEvent`).
 *
 * @internal
 */
final readonly class MappedEvent
{
    public function __construct(
        public LiveEvent $event,
        public ?DecodeException $error = null,
    ) {}
}
