<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

/**
 * Classifies a hand-written GraphQL document for `GeneratedClient::execute()`.
 *
 * @internal called by generated code only; not part of the public API
 */
final class RawDocument
{
    /** Ignored tokens before the first definition: BOM, whitespace, commas, `#` comments. */
    private const IGNORED_PREFIX = '/^(?:\xEF\xBB\xBF)?(?:[\s,]+|#[^\r\n]*)*';

    private const FIRST_TOKEN = self::IGNORED_PREFIX . '(query\b|mutation\b|subscription\b|fragment\b|\{)/';

    private const OPERATION_NAME = self::IGNORED_PREFIX . '(?:query|mutation|subscription)\s+([_A-Za-z]\w*)/';

    private function __construct() {}

    /**
     * A document that is clearly a query (`query ...` or the `{ ... }` shorthand) is a query; everything else is
     * treated as a mutation, so the transport never retries it (CLAUDE.md rule 2). A document whose first definition
     * is a fragment may hold the operation anywhere, and a document that mentions `mutation` anywhere (a second
     * operation, even a comment) may run one, so neither is guessed.
     */
    public static function operationType(string $document): OperationType
    {
        if (preg_match('/\bmutation\b/', $document) === 1 || preg_match(self::FIRST_TOKEN, $document, $match) !== 1) {
            return OperationType::Mutation;
        }

        return \in_array($match[1], ['query', '{'], true) ? OperationType::Query : OperationType::Mutation;
    }

    /**
     * The name of the first operation, `''` for an anonymous one (the server then runs the only operation).
     */
    public static function operationName(string $document): string
    {
        return preg_match(self::OPERATION_NAME, $document, $match) === 1
            ? $match[1]
            : '';
    }
}
