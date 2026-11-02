<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Tools\Codegen\Cli;
use WhiteMarket\Sdk\Tools\Codegen\OutputDirectory;

#[CoversClass(Cli::class)]
#[CoversClass(OutputDirectory::class)]
final class CliTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/codegen';

    private string $out = '';

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/wm-codegen-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->out)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->out, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            self::assertInstanceOf(\SplFileInfo::class, $entry);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->out);
    }

    #[Test]
    public function printsUsageWithoutEnoughArguments(): void
    {
        [$code, , $stderr] = $this->runCli(['generate.php', 'schema.graphql']);

        self::assertSame(2, $code);
        self::assertStringStartsWith('Usage: generate.php', $stderr);
        self::assertDirectoryDoesNotExist($this->out);
    }

    #[Test]
    public function writesTheGoldenOutputAndRemovesStaleClasses(): void
    {
        mkdir($this->out . '/Operation/Query/Removed', 0o777, true);
        file_put_contents($this->out . '/Operation/Query/Removed/Stale.php', '<?php');

        [$code, $stdout] = $this->runCli($this->arguments());

        self::assertSame(0, $code);
        self::assertSame("codegen-php: 31 files written to {$this->out}\n", $stdout);
        self::assertFileDoesNotExist($this->out . '/Operation/Query/Removed/Stale.php');
        self::assertDirectoryDoesNotExist($this->out . '/Operation/Query/Removed');
        self::assertFileEquals(self::FIXTURE . '/golden/GeneratedClient.php', $this->out . '/GeneratedClient.php');
    }

    #[Test]
    public function refusesToClearADirectoryWithForeignFiles(): void
    {
        mkdir($this->out . '/Enum', 0o777, true);
        file_put_contents($this->out . '/Enum/Old.php', '<?php');
        file_put_contents($this->out . '/README.md', 'not generated');

        try {
            $this->runCli($this->arguments());
            self::fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('README.md is not a generated PHP file', $e->getMessage());
        }
        self::assertFileExists($this->out . '/Enum/Old.php', 'nothing is deleted when the target looks wrong');
    }

    /**
     * @return list<string>
     */
    private function arguments(): array
    {
        return [
            'generate.php',
            self::FIXTURE . '/schema.graphql',
            self::FIXTURE . '/operations',
            self::FIXTURE . '/fragments',
            $this->out,
            'WhiteMarket\\Sdk\\Tests\\Fixtures\\Codegen\\Golden',
        ];
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string}
     */
    private function runCli(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $code = Cli::run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
