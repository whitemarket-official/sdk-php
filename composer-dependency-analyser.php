<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

// Fails on shadow dependencies (code uses a package that is only installed transitively, CLAUDE.md rule 10),
// unused declared dependencies and dev dependencies used in production code.
return (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    ->addPathToScan(__DIR__ . '/tools/codegen-php', isDev: true)
    ->addPathToScan(__DIR__ . '/tools/phpstan', isDev: true)
    ->addPathToScan(__DIR__ . '/tools/live-smoke', isDev: true)
    ->addPathToScan(__DIR__ . '/examples', isDev: true)
    // Deliberate violations analysed by the rule tests in tests/phpstan (a lowercase `#[\sensitiveparameter]`).
    ->addPathToExclude(__DIR__ . '/tests/fixtures/phpstan')
    // Optional extensions of the products stream (ADR 0006): `suggest`ed, not required, and used only after
    // JsonStreamDecoder::isAvailable() checked they are loaded.
    ->ignoreUnknownClasses(['SimdJsonException', 'YajlStream\\ArrayParser', 'YajlStream\\ParseException']);
