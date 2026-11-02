<?php

declare(strict_types=1);

/**
 * Helpers shared by the scripts in `examples/`, loaded through Composer `autoload-dev.files`.
 */

namespace WhiteMarket\Sdk\Examples;

use WhiteMarket\Sdk\Models\DealState;

/**
 * Returns the Partner Token, or null when it is not configured.
 *
 * Order: the `WHITEMARKET_PARTNER_TOKEN` environment variable, then the git-ignored `.env` file in the repository root.
 */
function partnerTokenOrNull(): ?string
{
    $token = getenv('WHITEMARKET_PARTNER_TOKEN');
    if (!\is_string($token) || $token === '') {
        $token = envFileValue(__DIR__ . '/../../.env', 'WHITEMARKET_PARTNER_TOKEN');
    }

    return $token === '' ? null : $token;
}

/**
 * Returns the Partner Token or stops the script with a hint when it is missing.
 */
function partnerToken(): string
{
    $token = partnerTokenOrNull();
    if ($token === null) {
        fwrite(\STDERR, "WHITEMARKET_PARTNER_TOKEN is not set: copy .env.example to .env and fill it in.\n");

        exit(1);
    }

    return $token;
}

/**
 * Reads one `KEY=value` line from a dotenv file. Supports comments (`#`), blank lines and values in single or
 * double quotes; no variable expansion. A small parser instead of a dotenv package keeps the SDK free of
 * dependencies that only the examples would use.
 */
function envFileValue(string $file, string $key): ?string
{
    if (!is_file($file)) {
        return null;
    }
    $lines = file($file, \FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return null;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        $separator = strpos($line, '=');
        if ($line === '' || str_starts_with($line, '#') || $separator === false) {
            continue;
        }
        if (trim(substr($line, 0, $separator)) !== $key) {
            continue;
        }
        $value = trim(substr($line, $separator + 1));
        $quote = $value[0] ?? '';
        if (\strlen($value) >= 2 && ($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    return null;
}

/**
 * Wraps a `waitFor` condition: prints `status <VALUE>` with a timestamp whenever the deal status differs from the
 * previous poll, then returns the verdict of `$until` unchanged. Prints `waiting...` once now.
 *
 * @param \Closure(DealState): bool $until
 *
 * @return \Closure(DealState): bool
 */
function printStatusChanges(\Closure $until): \Closure
{
    echo "waiting...\n";
    $previous = null;

    return static function (DealState $state) use ($until, &$previous): bool {
        $status = $state->status->value;
        if ($status !== $previous) {
            echo date('Y-m-d H:i:s'), ' status ', $status, "\n";
            $previous = $status;
        }

        return $until($state);
    };
}
