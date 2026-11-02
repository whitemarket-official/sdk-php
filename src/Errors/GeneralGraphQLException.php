<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * GraphQL errors that are not all "not found". Catch {@see GraphQLException}.
 */
final class GeneralGraphQLException extends GraphQLException {}
