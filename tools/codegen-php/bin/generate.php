<?php

declare(strict_types=1);

/**
 * Regenerates the typed PHP layer (ADR 0002). Run through `make codegen`; `make codegen-check` writes to a scratch
 * directory and diffs it against `src/Generated/`. Usage: see {@see WhiteMarket\Sdk\Tools\Codegen\Cli}.
 */

use WhiteMarket\Sdk\Tools\Codegen\Cli;

require __DIR__ . '/../../../vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];

exit(Cli::run(is_array($argv) ? array_values(array_filter($argv, \is_string(...))) : [], \STDOUT, \STDERR));
