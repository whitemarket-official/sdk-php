<?php

declare(strict_types=1);

use function WhiteMarket\Sdk\Examples\failureReport;

/**
 * Shared setup for every script in `examples/`: `require __DIR__ . '/bootstrap.php';` at the top, then
 * `\WhiteMarket\Sdk\Examples\partnerToken()` (examples/Support/env.php).
 *
 * The token comes from the `WHITEMARKET_PARTNER_TOKEN` environment variable or, when it is not set, from the
 * git-ignored `.env` file in the repository root (copy `.env.example` to `.env` and fill it in).
 * Scripts never print the token: it is a long-lived secret of your account.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// A failure no example catches (a rejected listing, a failed report, ...) ends the script with its full text: class,
// HTTP status, GraphQL categories, paths and server messages (examples/Support/failure.php), never a bare trace.
set_exception_handler(static function (\Throwable $e): void {
    fwrite(\STDERR, failureReport($e));
    exit(1);
});
