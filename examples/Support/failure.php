<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Examples;

use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;

/**
 * A failure as text for the terminal: every exception of the chain with its class, HTTP status, message and, for a
 * GraphQL error, each error's category, path and server message. Control characters of server text are replaced so a
 * message cannot drive the terminal. Branch on the category in code, never on this text (CLAUDE.md rule 4).
 */
function failureReport(\Throwable $e): string
{
    $lines = [];
    for ($current = $e, $depth = 0; $current instanceof \Throwable; $current = $current->getPrevious(), ++$depth) {
        $lines[] = ($depth === 0 ? '' : str_repeat('  ', $depth) . 'caused by ') . $current::class
            . ($current instanceof HttpException ? ' (HTTP ' . $current->status . ')' : '') . ': ' . printable($current->getMessage());
        if ($current instanceof GraphQLException) {
            foreach ($current->errors as $error) {
                $lines[] = str_repeat('  ', $depth + 1) . '- [' . ($error->category ?? 'no category') . '] at '
                    . ($error->path === [] ? '(no path)' : implode('.', $error->path)) . ': ' . printable($error->message);
            }
        }
        if ($current instanceof PurchaseOutcomeUnknownException && $current->lastLookupError instanceof \Throwable) {
            $lookup = $current->lastLookupError;
            $lines[] = str_repeat('  ', $depth + 1) . 'last lookup: ' . $lookup::class . ': ' . printable($lookup->getMessage());
        }
    }

    return implode("\n", $lines) . "\n";
}

function printable(string $text): string
{
    return preg_replace('/[\x00-\x1F\x7F]/', '?', $text) ?? '';
}
