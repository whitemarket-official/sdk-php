<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\PHPStan;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;

/**
 * Reads `#[\SensitiveParameter]` from parser nodes and reflection for the rules in this directory.
 */
final class SensitiveParameters
{
    private function __construct() {}

    /**
     * Class names are case-insensitive in PHP, and the engine honours `#[\sensitiveparameter]` too.
     */
    public static function isMarked(Param $parameter): bool
    {
        foreach ($parameter->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strcasecmp($attribute->name->toString(), \SensitiveParameter::class) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<Param> $parameters
     */
    public static function anyMarked(array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            if (self::isMarked($parameter)) {
                return true;
            }
        }

        return false;
    }

    public static function anyMarkedIn(\ReflectionFunctionAbstract $function): bool
    {
        foreach ($function->getParameters() as $parameter) {
            if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                return true;
            }
        }

        return false;
    }

    public static function name(Param $parameter): string
    {
        return $parameter->var instanceof Variable && \is_string($parameter->var->name) ? $parameter->var->name : '?';
    }
}
