<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Tools\Codegen\Generator;
use WhiteMarket\Sdk\Tools\Codegen\Php;

/**
 * The golden files under tests/fixtures/codegen/golden are the generator's output for a synthetic schema that has one
 * example of every construct. They are also analysed by PHPStan and php-cs-fixer like any other code, so a generator
 * change that emits invalid PHP fails `make check` (regenerate with `make codegen-golden`). How the golden output
 * decodes responses is tested in tests/unit/Generated/GoldenOutputTest.php on every supported PHP version.
 */
#[CoversClass(Generator::class)]
#[CoversClass(Php::class)]
final class GeneratorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/codegen';

    private const NAMESPACE = 'WhiteMarket\\Sdk\\Tests\\Fixtures\\Codegen\\Golden';

    #[Test]
    public function outputMatchesTheGoldenFilesByteForByte(): void
    {
        $files = $this->generate();

        $golden = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::FIXTURE . '/golden', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $golden[substr($file->getPathname(), \strlen(self::FIXTURE . '/golden/'))] = (string) file_get_contents($file->getPathname());
        }
        ksort($golden);

        self::assertSame(array_keys($golden), array_keys($files), 'run `make codegen-golden` after a deliberate generator change');
        foreach ($files as $path => $source) {
            self::assertSame($golden[$path] ?? null, $source, $path);
        }
    }

    #[Test]
    public function outputDoesNotDependOnTheOrderOfSourceFiles(): void
    {
        self::assertSame($this->generate(), $this->generate(reverse: true));
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'unknown field' => ['query Q { products { nope } }', [], 'invalid against the schema'];
        yield 'two root fields' => ['query Q { balances { value } hidden { id } }', [], 'exactly one root field'];
        yield 'anonymous operation' => ['{ balances { value } }', [], 'exactly one named operation'];
        yield 'unknown fragment' => ['query Q { balances { ...Missing } }', [], 'Unknown fragment Missing'];
        yield 'fragment on an interface' => ['query Q { hidden { ...H } }', ['fragment H on Hidden { id }'], 'only object type conditions'];
        yield 'operation in a fragment file' => ['query Q { balances { value } }', ['query X { hidden { id } }'], 'fragment definitions only'];
    }

    /**
     * @param list<string> $fragments
     */
    #[Test]
    #[DataProvider('invalidDocuments')]
    public function rejectsDocumentsItCannotGenerateCorrectly(string $operation, array $fragments, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        Generator::generate(self::NAMESPACE, $this->read('schema.graphql'), $fragments, [$operation]);
    }

    /**
     * A schema field named like the nowdoc label would end the DOCUMENT heredoc early: the rest of the document would
     * be parsed as PHP.
     */
    #[Test]
    public function keepsTheDocumentIntactWhenAFieldIsNamedLikeTheNowdocLabel(): void
    {
        $namespace = 'WhiteMarket\\Sdk\\Tests\\Fixtures\\Codegen\\NowdocLabel';
        $operation = "query Labels {\n  labels {\n    GRAPHQL\n    GRAPHQL_\n    after\n  }\n}";
        $files = Generator::generate(
            $namespace,
            'type Query { labels: Labels } type Labels { GRAPHQL: Int, GRAPHQL_: Int, after: Int }',
            [],
            [$operation],
        );

        $file = tempnam(sys_get_temp_dir(), 'nowdoc');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, $files['Operation/Query/Labels.php'] ?? self::fail('no operation file: ' . implode(', ', array_keys($files))));
            require $file;
        } finally {
            unlink($file);
        }

        $class = $namespace . '\\Operation\\Query\\Labels';
        self::assertSame($operation, \constant($class . '::DOCUMENT'));
    }

    /**
     * An interface selected without `__typename` or a per-type fragment cannot be told apart by the decoder (the server
     * sends no discriminator): one class with the interface's own fields, not an interface plus implementations.
     */
    #[Test]
    public function emitsOneClassForAnInterfaceSelectedWithoutATypename(): void
    {
        $files = $this->generate();

        $class = $files['Operation/Query/ItemIds/Result.php'] ?? self::fail('no ItemIds result class: ' . implode(', ', array_keys($files)));
        self::assertStringContainsString('final class Result', $class);
        self::assertStringContainsString('public readonly string $id,', $class);
        self::assertStringNotContainsString('abstractOf', $class);
        self::assertCount(1, array_filter(array_keys($files), static fn(string $path): bool => str_starts_with($path, 'Operation/Query/ItemIds/')), 'no per-type classes');
        self::assertArrayHasKey('Operation/Query/Product/ItemSkin.php', $files, 'a selection with __typename keeps its implementations');
    }

    /**
     * SDK_SPEC 8: a string field whose name holds a credential marker is a Secret, never a plain string.
     */
    #[Test]
    public function typesCredentialFieldsAsSecrets(): void
    {
        $result = $this->generate()['Operation/Mutation/AuthToken/Result.php'] ?? self::fail('no AuthToken result class');

        self::assertStringContainsString('public readonly \\WhiteMarket\\Sdk\\Auth\\Secret $accessToken,', $result);
        self::assertStringContainsString('public readonly ?\\WhiteMarket\\Sdk\\Auth\\Secret $refreshSecret,', $result);
        self::assertStringContainsString('public readonly int $expiresIn,', $result);
    }

    /**
     * Operation variables can carry credentials (a Steam token payload, a JWT): they never show in exception traces.
     */
    #[Test]
    public function marksEveryOperationVariableAsSensitive(): void
    {
        $files = $this->generate();

        self::assertStringContainsString('authToken(#[\\SensitiveParameter] string $secret)', $files['GeneratedClient.php'] ?? '');
        self::assertStringContainsString('variables(#[\\SensitiveParameter] string $secret)', $files['Operation/Mutation/AuthToken.php'] ?? '');
        self::assertStringContainsString('#[\\SensitiveParameter] array $variables = []', $files['GeneratedClient.php'] ?? '');
    }

    /**
     * @return array<string, string>
     */
    private function generate(bool $reverse = false): array
    {
        $operations = [];
        foreach (['query/products', 'query/product', 'query/balances', 'query/item_ids', 'mutation/product_remove', 'mutation/auth_token'] as $name) {
            $operations[] = $this->read('operations/' . $name . '.graphql');
        }

        return Generator::generate(
            self::NAMESPACE,
            $this->read('schema.graphql'),
            [$this->read('fragments/Money.graphql')],
            $reverse ? array_reverse($operations) : $operations,
        );
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(self::FIXTURE . '/' . $path);
    }
}
