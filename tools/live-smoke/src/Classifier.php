<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\NotFoundException;

/**
 * Turns a throwable of a step into PASS or FAIL. A categorized GraphQL error such as "not found" proves the operation
 * is reachable and decoded; transport, decode, auth, HTTP and uncategorized errors do not.
 */
final class Classifier
{
    /** Categories that mean the call did not really reach the business logic. */
    private const UNREACHABLE_PREFIXES = ['auth', 'internal', 'server'];

    private function __construct() {}

    public static function classify(string $name, \Throwable $e, int $milliseconds): StepResult
    {
        if ($e instanceof GraphQLException && self::isReachable($e)) {
            return new StepResult($name, StepStatus::Pass, $milliseconds, 'reachable (categorized GraphQL error)');
        }

        return new StepResult($name, StepStatus::Fail, $milliseconds, self::describe($e));
    }

    /**
     * Exception class and, for GraphQL errors, the sanitised `extensions.category` values. Never the message.
     */
    private static function describe(\Throwable $e): string
    {
        $detail = $e::class;
        if ($e instanceof GraphQLException) {
            $categories = [];
            foreach ($e->errors as $error) {
                $categories[] = self::safeCategory($error->category);
            }
            $detail .= ' category=' . implode(',', array_unique($categories));
        }

        return $detail;
    }

    private static function isReachable(#[\SensitiveParameter] GraphQLException $e): bool
    {
        if ($e instanceof NotFoundException) {
            return true;
        }
        foreach ($e->errors as $error) {
            $category = $error->category;
            if ($category === null || $category === '') {
                return false;
            }
            foreach (self::UNREACHABLE_PREFIXES as $prefix) {
                if (str_starts_with(strtolower($category), $prefix)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function safeCategory(?string $category): string
    {
        if ($category === null) {
            return '(none)';
        }

        return preg_match('/\A[A-Za-z0-9_.\-]{1,64}\z/', $category) === 1 ? $category : '(invalid)';
    }
}
