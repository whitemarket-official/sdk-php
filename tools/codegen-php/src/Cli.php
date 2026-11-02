<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\Codegen;

/**
 * `generate.php <schema.graphql> <operations dir> <fragments dir> <output dir> [namespace]`.
 */
final class Cli
{
    private const DEFAULT_NAMESPACE = 'WhiteMarket\\Sdk\\Generated';

    private function __construct() {}

    /**
     * @param list<string> $argv
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return int exit code
     */
    public static function run(array $argv, $stdout, $stderr): int
    {
        if (\count($argv) < 5) {
            fwrite($stderr, "Usage: generate.php <schema> <operations dir> <fragments dir> <output dir> [namespace]\n");

            return 2;
        }
        [, $schemaFile, $operationsDir, $fragmentsDir, $outputDir] = $argv;

        $files = Generator::generate(
            $argv[5] ?? self::DEFAULT_NAMESPACE,
            self::read($schemaFile),
            array_map(self::read(...), self::graphqlFiles($fragmentsDir)),
            array_map(self::read(...), self::graphqlFiles($operationsDir)),
        );
        OutputDirectory::replace($outputDir, $files);
        fwrite($stdout, \sprintf("codegen-php: %d files written to %s\n", \count($files), $outputDir));

        return 0;
    }

    /**
     * @return list<string> every `*.graphql` file under $dir, sorted
     */
    private static function graphqlFiles(string $dir): array
    {
        $paths = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'graphql') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(\sprintf('Cannot read %s', $path));
        }

        return $content;
    }
}
