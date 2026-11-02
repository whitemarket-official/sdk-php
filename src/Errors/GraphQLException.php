<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * HTTP 200 with a non-empty `errors` list (SDK_SPEC 1.5 `GraphQLError`). `partialData` is the `data` object the server returned next to the errors,
 * if any (a response may carry both). `dataPresent` is true when the response had a `data` key, even a null one: per the
 * GraphQL spec (section 7.1.2) a request error (parse, validation) answers without `data`, while `data: null` means
 * execution started.
 */
abstract class GraphQLException extends WhiteMarketException
{
    public readonly bool $dataPresent;

    /**
     * @param non-empty-list<GraphQLErrorEntry> $errors
     * @param array<mixed>|null $partialData
     * @param ?bool $dataPresent whether the response had a `data` key; when null (unknown), true: execution may have started, so a batch does not read it as a request error
     */
    final public function __construct(
        #[\SensitiveParameter]
        public readonly array $errors,
        #[\SensitiveParameter]
        public readonly ?array $partialData = null,
        ?bool $dataPresent = null,
    ) {
        $this->dataPresent = $dataPresent ?? true;
        $first = $errors[0];
        $more = \count($errors) - 1;
        parent::__construct(\sprintf(
            'GraphQL error%s: %s%s',
            $first->category === null ? '' : ' [' . $first->category . ']',
            $first->message,
            $more > 0 ? \sprintf(' (and %d more)', $more) : '',
        ));
    }

    /**
     * {@see NotFoundException} when every error is a "not found" one, otherwise the generic exception: a response that
     * mixes a missing object with another failure must not be handled as a plain "not found".
     *
     * @internal called by the transport
     *
     * @param non-empty-list<GraphQLErrorEntry> $errors
     * @param array<mixed>|null $partialData
     */
    public static function fromErrors(#[\SensitiveParameter] array $errors, #[\SensitiveParameter] ?array $partialData = null, ?bool $dataPresent = null): self
    {
        foreach ($errors as $error) {
            if (!ErrorCategory::isNotFound($error->category)) {
                return new GeneralGraphQLException($errors, $partialData, $dataPresent);
            }
        }

        return new NotFoundException($errors, $partialData, $dataPresent);
    }

    public function hasCategory(string $category): bool
    {
        foreach ($this->errors as $error) {
            if ($error->category === $category) {
                return true;
            }
        }

        return false;
    }
}
