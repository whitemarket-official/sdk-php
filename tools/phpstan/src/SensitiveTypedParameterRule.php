<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Live\Connection;

/**
 * A parameter typed as `Secret`, or as a class of this SDK whose constructor has a `#[\SensitiveParameter]`
 * parameter (`BuyOptions` with its trade URL), or a live `Connection` (it holds the token while open), carries a
 * credential into the frame: without the attribute every
 * exception trace raised below it shows the value. Functions, methods, closures, arrow functions and promoted
 * constructor parameters are all covered. Generated code and unit tests are skipped, see SKIPPED_PATHS.
 *
 * @implements Rule<Param>
 */
final readonly class SensitiveTypedParameterRule implements Rule
{
    private const SECRET_CLASS = Secret::class;

    /**
     * An open live connection holds the token: the SSE body stream keeps the request URL (token in the query) in its
     * metadata.
     */
    private const CONNECTION_CLASS = Connection::class;
    private const SDK_NAMESPACE = 'WhiteMarket\Sdk\\';
    /**
     * Not checked: generator output (`src/Generated`, the codegen golden files), rewritten by `make codegen`; and unit
     * tests, which feed synthetic values and some of which must see the unmasked frame (SecretTest's trace case).
     */
    private const SKIPPED_PATHS = ['/src/Generated/', '/tests/fixtures/codegen/golden/', '/tests/unit/'];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return Param::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->type === null || SensitiveParameters::isMarked($node) || $this->isSkipped($scope->getFile())) {
            return [];
        }

        $type = $scope->getFunctionType($node->type, false, false);
        foreach (TypeUtils::flattenTypes($type) as $member) {
            foreach ($member->getObjectClassNames() as $className) {
                if ($this->carriesCredential($className)) {
                    return [$this->error($node, $type->describe(VerbosityLevel::typeOnly()))];
                }
            }
        }

        return [];
    }

    private function isSkipped(string $file): bool
    {
        foreach (self::SKIPPED_PATHS as $path) {
            if (str_contains($file, $path)) {
                return true;
            }
        }

        return false;
    }

    private function carriesCredential(string $className): bool
    {
        if (strcasecmp($className, self::SECRET_CLASS) === 0 || strcasecmp($className, self::CONNECTION_CLASS) === 0) {
            return true;
        }
        if (!str_starts_with($className, self::SDK_NAMESPACE) || !$this->reflectionProvider->hasClass($className)) {
            return false;
        }
        $class = $this->reflectionProvider->getClass($className);
        if ($class->implementsInterface(self::CONNECTION_CLASS)) {
            return true;
        }
        $constructor = $class->getNativeReflection()->getConstructor();

        return $constructor instanceof \ReflectionMethod && SensitiveParameters::anyMarkedIn($constructor);
    }

    private function error(Param $parameter, string $type): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'Parameter $%s of type %s must be #[\SensitiveParameter]: the type carries a credential that exception traces would show.',
            SensitiveParameters::name($parameter),
            $type,
        ))->identifier('whitemarket.sensitiveTypedParameter')->line($parameter->getStartLine())->build();
    }
}
