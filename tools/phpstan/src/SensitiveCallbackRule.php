<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * An internal function that calls back into PHP (`array_map`, `preg_replace_callback`, ...) keeps its arguments in a
 * frame without `#[\SensitiveParameter]`: anything thrown inside the callback, a stack overflow included, carries the
 * raw data in its trace. A method with a sensitive parameter therefore uses a loop instead. Syntactic on purpose: the
 * rule does not track whether the sensitive value itself reaches the call.
 *
 * @implements Rule<InClassMethodNode>
 */
final class SensitiveCallbackRule implements Rule
{
    private const CALLBACK_FUNCTIONS = [
        'array_filter',
        'array_map',
        'array_reduce',
        'array_walk',
        'array_walk_recursive',
        'call_user_func',
        'call_user_func_array',
        'iterator_apply',
        'preg_replace_callback',
        'preg_replace_callback_array',
        'uasort',
        'uksort',
        'usort',
    ];

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getOriginalNode();
        if ($method->stmts === null || !SensitiveParameters::anyMarked($method->params)) {
            return [];
        }

        $errors = [];
        foreach ((new NodeFinder())->findInstanceOf($method->stmts, FuncCall::class) as $call) {
            if (!$call->name instanceof Name) {
                continue;
            }
            $function = $call->name->toLowerString();
            if (\in_array($function, self::CALLBACK_FUNCTIONS, true)) {
                $errors[] = $this->error($node->getClassReflection()->getName(), $method->name->toString(), $function, $call->getStartLine());
            }
        }

        return $errors;
    }

    private function error(string $class, string $method, string $function, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            '%s::%s() has a #[\SensitiveParameter] parameter and calls %s(): the internal frame keeps its arguments in exception traces; use a loop.',
            $class,
            $method,
            $function,
        ))->identifier('whitemarket.sensitiveCallback')->line($line)->build();
    }
}
