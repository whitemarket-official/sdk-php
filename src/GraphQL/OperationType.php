<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

/**
 * Kind of a GraphQL operation. The transport retries queries only, never mutations (SDK_SPEC 1.4).
 */
enum OperationType: string
{
    case Query = 'query';
    case Mutation = 'mutation';
}
