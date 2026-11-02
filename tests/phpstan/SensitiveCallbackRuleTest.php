<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WhiteMarket\Sdk\Tests\Fixtures\PHPStan\CallbackUsers;
use WhiteMarket\Sdk\Tools\PHPStan\SensitiveCallbackRule;
use WhiteMarket\Sdk\Tools\PHPStan\SensitiveParameters;

/**
 * @extends RuleTestCase<SensitiveCallbackRule>
 */
#[CoversClass(SensitiveCallbackRule::class)]
#[CoversClass(SensitiveParameters::class)]
final class SensitiveCallbackRuleTest extends RuleTestCase
{
    private const SUFFIX = ': the internal frame keeps its arguments in exception traces; use a loop.';

    #[Test]
    public function forbidsCallbackFunctionsInMethodsWithASensitiveParameter(): void
    {
        $class = CallbackUsers::class;

        $this->analyse([__DIR__ . '/../fixtures/phpstan/sensitive-callback.php'], [
            [$class . '::mapsSensitive() has a #[\SensitiveParameter] parameter and calls array_map()' . self::SUFFIX, 16],
            [$class . '::replacesSensitive() has a #[\SensitiveParameter] parameter and calls preg_replace_callback()' . self::SUFFIX, 21],
            [$class . '::sortsSensitive() has a #[\SensitiveParameter] parameter and calls usort()' . self::SUFFIX, 31],
        ]);
    }

    protected function getRule(): Rule
    {
        return new SensitiveCallbackRule();
    }
}
