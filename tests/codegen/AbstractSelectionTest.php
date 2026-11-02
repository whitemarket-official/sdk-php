<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Codegen;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\TypeInfo;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SDK_SPEC 1.2 (P22 B): every interface or union field in an operation document selects `__typename`, the depth limit
 * included (`__typename id` there), so the response always names the concrete type. Covers generated documents and
 * hand-written overrides alike, since the overrides are copied into graphql/operations.
 */
#[CoversNothing]
final class AbstractSelectionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        $files = glob(self::ROOT . '/graphql/operations/{query,mutation}/*.graphql', \GLOB_BRACE);
        self::assertIsArray($files);
        foreach ($files as $file) {
            yield basename(\dirname($file)) . ' ' . basename($file, '.graphql') => [$file];
        }
    }

    #[Test]
    public function theDocumentsAreFound(): void
    {
        // 69 root fields (2026-11-02); far fewer means the glob broke.
        self::assertGreaterThanOrEqual(60, iterator_count(self::documents()));
    }

    #[Test]
    #[DataProvider('documents')]
    public function everyAbstractFieldSelectsItsTypename(string $file): void
    {
        $schema = BuildSchema::build((string) file_get_contents(self::ROOT . '/schema/partner.graphql'));
        $fragments = '';
        $fragmentFiles = glob(self::ROOT . '/graphql/fragments/*.graphql');
        self::assertIsArray($fragmentFiles);
        foreach ($fragmentFiles as $fragmentFile) {
            $fragments .= file_get_contents($fragmentFile) . "\n";
        }
        $document = Parser::parse(file_get_contents($file) . "\n" . $fragments);

        $typeInfo = new TypeInfo($schema);
        $missing = [];
        Visitor::visit($document, Visitor::visitWithTypeInfo($typeInfo, [
            NodeKind::FIELD => static function (Node $node) use ($typeInfo, &$missing): void {
                $type = $typeInfo->getType();
                if (!$node instanceof FieldNode || !$type instanceof Type || !$node->selectionSet instanceof SelectionSetNode || !Type::getNamedType($type) instanceof AbstractType) {
                    return;
                }
                foreach ($node->selectionSet->selections as $selection) {
                    if ($selection instanceof FieldNode && $selection->name->value === '__typename') {
                        return;
                    }
                }
                $missing[] = $node->name->value . ' (' . $type->toString() . ', line ' . ($node->loc?->startToken->line ?? 0) . ')';
            },
        ]));

        self::assertSame([], $missing, 'interface or union fields without __typename');
    }
}
