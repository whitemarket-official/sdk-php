<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Live\Connection;
use WhiteMarket\Sdk\Live\Sse\SseConnection;
use WhiteMarket\Sdk\Tools\PHPStan\SensitiveParameters;
use WhiteMarket\Sdk\Tools\PHPStan\SensitiveTypedParameterRule;

/**
 * @extends RuleTestCase<SensitiveTypedParameterRule>
 */
#[CoversClass(SensitiveTypedParameterRule::class)]
#[CoversClass(SensitiveParameters::class)]
final class SensitiveTypedParameterRuleTest extends RuleTestCase
{
    private const SUFFIX = ' must be #[\SensitiveParameter]: the type carries a credential that exception traces would show.';

    #[Test]
    public function requiresTheAttributeOnSecretsAndOnClassesWithASensitiveConstructor(): void
    {
        $secret = Secret::class;
        // The fixture namespace is not autoloaded (excluded from analysis), so the class is named as text.
        $options = 'WhiteMarket\Sdk\Tests\Fixtures\PHPStan\\' . 'WithSensitiveConstructor';

        $this->analyse([__DIR__ . '/../fixtures/phpstan/sensitive-typed-parameter.php'], [
            ['Parameter $promoted of type ' . $secret . self::SUFFIX, 21],
            ['Parameter $secret of type ' . $secret . self::SUFFIX, 23],
            ['Parameter $secret of type ' . $secret . '|null' . self::SUFFIX, 25],
            ['Parameter $secret of type ' . 'string|' . $secret . self::SUFFIX, 27],
            ['Parameter $options of type ' . $options . self::SUFFIX, 31],
            ['Parameter $secret of type ' . 'string|' . $secret . '|null' . self::SUFFIX, 37],
            ['Parameter $secret of type ' . $secret . self::SUFFIX, 41],
            ['Parameter $secret of type ' . $secret . '|null' . self::SUFFIX, 42],
            ['Parameter $secret of type ' . $secret . self::SUFFIX, 50],
            ['Parameter $connection of type ' . Connection::class . self::SUFFIX, 54],
            ['Parameter $connection of type ' . SseConnection::class . '|null' . self::SUFFIX, 56],
        ]);
    }

    protected function getRule(): Rule
    {
        return new SensitiveTypedParameterRule($this->createReflectionProvider());
    }
}
