<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\WebSocket;

/**
 * A socket read ran past its overall deadline (a server trickling bytes).
 *
 * @internal
 */
final class DeadlineExceeded extends \RuntimeException {}
