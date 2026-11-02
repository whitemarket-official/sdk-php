<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;

// PHP level comes from composer.json "require.php" (^8.2): no syntax newer than 8.2 is introduced.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/tools/codegen-php', __DIR__ . '/tools/phpstan', __DIR__ . '/tools/live-smoke'])
    ->withSkip([
        __DIR__ . '/src/Generated',
        // Generator output for the golden test: same policy as src/Generated (FQCNs, no refactoring).
        __DIR__ . '/tests/fixtures/codegen/golden',
        // Deliberate violations with fixed line numbers, asserted by SensitiveParameterInheritanceRuleTest.
        __DIR__ . '/tests/fixtures/phpstan',
        // php-cs-fixer enforces static self::assert*() (php_unit_test_case_static_method_calls); one style, one owner.
        PreferPHPUnitThisCallRector::class,
    ])
    ->withCache(__DIR__ . '/var/cache/rector')
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
