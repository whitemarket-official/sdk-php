<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WhiteMarket\Sdk\Tools\PHPStan\SensitiveParameterInheritanceRule;

/**
 * @extends RuleTestCase<SensitiveParameterInheritanceRule>
 */
#[CoversClass(SensitiveParameterInheritanceRule::class)]
final class SensitiveParameterInheritanceRuleTest extends RuleTestCase
{
    #[Test]
    public function requiresTheAttributeWhereTheAncestorHasIt(): void
    {
        $this->analyse([__DIR__ . '/../fixtures/phpstan/sensitive-parameter-inheritance.php'], [
            [
                'Parameter $payload of WhiteMarket\Sdk\Tests\Fixtures\PHPStan\UnmarkedSink::write() must be #[\SensitiveParameter] like in WhiteMarket\Sdk\Tests\Fixtures\PHPStan\Sink: PHP does not inherit the attribute.',
                28,
            ],
            [
                'Parameter $buffer of WhiteMarket\Sdk\Tests\Fixtures\PHPStan\UnmarkedSubclass::flush() must be #[\SensitiveParameter] like in WhiteMarket\Sdk\Tests\Fixtures\PHPStan\BaseSink: PHP does not inherit the attribute.',
                36,
            ],
            [
                'Parameter $buffer of WhiteMarket\Sdk\Tests\Fixtures\PHPStan\UnmarkedOnTwoAncestors::flush() must be #[\SensitiveParameter] like in WhiteMarket\Sdk\Tests\Fixtures\PHPStan\Flushable: PHP does not inherit the attribute.',
                53,
            ],
            [
                'Parameter $rest of WhiteMarket\Sdk\Tests\Fixtures\PHPStan\UnmarkedExtraVariadic::writeAll() must be #[\SensitiveParameter] like in WhiteMarket\Sdk\Tests\Fixtures\PHPStan\VariadicSink: PHP does not inherit the attribute.',
                68,
            ],
            [
                'Parameter $arguments of WhiteMarket\Sdk\Tests\Fixtures\PHPStan\UnmarkedVariadicChild::run() must be #[\SensitiveParameter] like in WhiteMarket\Sdk\Tests\Fixtures\PHPStan\Executor: PHP does not inherit the attribute.',
                78,
            ],
            [
                'Parameter $token of WhiteMarket\Sdk\Tests\Fixtures\PHPStan\UnmarkedAbstractConstructor::__construct() must be #[\SensitiveParameter] like in WhiteMarket\Sdk\Tests\Fixtures\PHPStan\AbstractConstructed: PHP does not inherit the attribute.',
                110,
            ],
        ]);
    }

    protected function getRule(): Rule
    {
        return new SensitiveParameterInheritanceRule();
    }
}
