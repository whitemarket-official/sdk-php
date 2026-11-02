<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * Whether a failure of a mutation means the server did not run it (CLAUDE.md rule 2: a mutation is never resent, so
 * the caller must know whether it may have happened). A malformed request, a rejected credential and a rate limit are
 * rejections; so is a GraphQL error that can only have come from the root field itself (for a batch: only a request error, a response without `data` and without a path). Every other failure
 * (a timeout, a 5xx, an unreadable response, a broken collaborator) may have happened after the mutation ran.
 *
 * @internal shared by the high-level API classes; not part of the public API
 */
final class MutationRejection
{
    /**
     * GraphQL answers 200 with partial `data` next to `errors` (WHITEMARKET_API.md 2). A real rejection of the non-null
     * root field nulls the whole `data` (GraphQL spec, error propagation), so `data: null` is the clear case; a nullable
     * root field may instead leave `data: {"<field>": null}`. Anything else is ambiguous: an empty or list `data`, a
     * missing `<field>` key, a non-null value (the mutation produced a result), or an error on a nested path such as
     * `["<field>","item","description"]` (the mutation ran). Residual: a root-path error raised after the effect was
     * committed still reads as a rejection (needs the backend's category catalogue).
     *
     * A batch (`*_multiple`) resolver acts on its items one by one and can fail part-way, which also answers `data: null`
     * with an error at `["<field>"]`. So with `$rootFieldErrorRejects = false` a GraphQL error is a rejection only when
     * the response has no `data` key and EVERY error has no path: a request error (parse, validation) is raised before
     * execution, so the response omits `data` (GraphQL spec, October 2021, section 7.1.2 "Data"); `data: null` means
     * execution started, and an error at the root field means the same.
     *
     * @param string $field the root field of the mutation, for example `market_buy`
     * @param bool $rootFieldErrorRejects true (single mutation): an error at the root field is a rejection; false (batch):
     *                                    only a pathless error is
     */
    public function isRejection(\Throwable $e, string $field, bool $rootFieldErrorRejects = true): bool
    {
        if ($e instanceof BadRequestException || $e instanceof AuthenticationException || $e instanceof RateLimitException) {
            return true;
        }
        if (!$e instanceof GraphQLException) {
            return false;
        }
        // `errors` is a non-empty list by construction, but an empty one must not pass the loop below vacuously.
        if ($e->errors === []) {
            return false;
        }
        if (!$rootFieldErrorRejects && $e->dataPresent) {
            return false;
        }
        $data = $e->partialData;
        if ($data !== null && (!\array_key_exists($field, $data) || $data[$field] !== null)) {
            return false;
        }
        foreach ($e->errors as $error) {
            if ($error->path !== [] && ($error->path !== [$field] || !$rootFieldErrorRejects)) {
                return false;
            }
        }

        return true;
    }
}
