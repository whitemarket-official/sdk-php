<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\Codegen;

/**
 * Replaces the content of a generated directory: stale classes from removed operations must disappear.
 */
final class OutputDirectory
{
    private function __construct() {}

    /**
     * @param array<string, string> $files relative path => content
     */
    public static function replace(string $dir, array $files): void
    {
        if (is_dir($dir)) {
            self::clear($dir);
        }
        foreach ($files as $path => $content) {
            $target = $dir . '/' . $path;
            if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0o777, true) && !is_dir(\dirname($target))) {
                throw new \RuntimeException(\sprintf('Cannot create %s', \dirname($target)));
            }
            if (file_put_contents($target, $content) === false) {
                throw new \RuntimeException(\sprintf('Cannot write %s', $target));
            }
        }
    }

    /**
     * Deletes the generated PHP files and the directories left empty. Anything else means a wrong target path: nothing
     * is deleted then.
     */
    private static function clear(string $dir): void
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if (!$entry->isDir() && $entry->getExtension() !== 'php') {
                throw new \RuntimeException(\sprintf('Refusing to clear %s: %s is not a generated PHP file', $dir, $entry->getPathname()));
            }
            $entries[] = $entry;
        }

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }
}
