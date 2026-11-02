<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * PHP does not inherit `#[\SensitiveParameter]`: an implementation of `OperationExecutor::execute()` without it puts
 * the variables (tokens included) into every exception trace raised below it. This rule requires the attribute on
 * each parameter whose counterpart in a parent class or interface carries it.
 *
 * @implements Rule<InClassMethodNode>
 */
final class SensitiveParameterInheritanceRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getOriginalNode();
        $name = $method->name->toString();
        $class = $node->getClassReflection();

        // One error per parameter, naming the first ancestor that marks it.
        $errors = [];
        foreach ($class->getAncestors() as $ancestor) {
            $inherited = $this->inheritedMethod($ancestor->getNativeReflection(), $class->getName(), $name);
            if (!$inherited instanceof \ReflectionMethod) {
                continue;
            }
            foreach ($inherited->getParameters() as $position => $parameter) {
                if ($parameter->getAttributes(\SensitiveParameter::class) === []) {
                    continue;
                }
                foreach ($this->receivers($method->params, $position, $parameter->isVariadic()) as $index => $own) {
                    if (!isset($errors[$index]) && !SensitiveParameters::isMarked($own)) {
                        $errors[$index] = $this->error($class->getName(), $name, $own, $ancestor->getName());
                    }
                }
            }
        }

        return array_values($errors);
    }

    /**
     * The ancestor's method the analysed one has to match, or null: a private method is not inherited, and a
     * constructor is bound to the parent's signature only when the parent declares it abstract (interfaces included).
     *
     * @param \ReflectionClass<object> $ancestor
     */
    private function inheritedMethod(\ReflectionClass $ancestor, string $class, string $name): ?\ReflectionMethod
    {
        if ($ancestor->getName() === $class || !$ancestor->hasMethod($name)) {
            return null;
        }
        $method = $ancestor->getMethod($name);
        if ($method->isPrivate() || ($method->isConstructor() && !$method->isAbstract())) {
            return null;
        }

        return $method;
    }

    /**
     * The analysed method's parameters that receive the argument at `$position`: the parameter there; for a variadic
     * ancestor parameter also every extra one after it; the child's own variadic when it starts at or before it.
     *
     * @param array<Param> $parameters
     *
     * @return array<Param>
     */
    private function receivers(array $parameters, int $position, bool $variadic): array
    {
        if ($variadic) {
            return \array_slice($parameters, $position, null, true);
        }
        if (isset($parameters[$position])) {
            return [$position => $parameters[$position]];
        }
        $last = array_key_last($parameters);
        if ($last !== null && $parameters[$last]->variadic) {
            return [$last => $parameters[$last]];
        }

        return [];
    }

    private function error(string $class, string $method, Param $parameter, string $ancestor): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'Parameter $%s of %s::%s() must be #[\SensitiveParameter] like in %s: PHP does not inherit the attribute.',
            SensitiveParameters::name($parameter),
            $class,
            $method,
            $ancestor,
        ))->identifier('whitemarket.sensitiveParameterInheritance')->line($parameter->getStartLine())->build();
    }
}
