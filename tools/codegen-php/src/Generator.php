<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\Codegen;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * Generates the typed PHP layer from the schema and the `.graphql` documents (ADR 0002 "Generator").
 *
 * One instance per run; output is a map `relative path => PHP source`, deterministic for the same input.
 */
final class Generator
{
    private const RUNTIME = '\\WhiteMarket\\Sdk\\GraphQL\\';
    private const UNKNOWN_ENUM = '\\' . UnknownEnumValue::class;

    /** Prefix of every decoder call in generated code; fully qualified, so no generated class name can clash with it. */
    private const DECODE = self::RUNTIME . 'Decode::';

    /** Output scalars: schema name => [PHP native type, decoder]. */
    /**
     * A string field whose name holds one of these words is a credential and decodes to Auth\Secret (SDK_SPEC 8).
     */
    private const CREDENTIAL_FIELD = '/token|secret|passw|authori[sz]ation|cookie|api[_-]?key|jwt|credential/i';

    private const SECRET = '\\' . Secret::class;

    /** Operation variables can carry credentials (a Steam token payload, a JWT): kept out of exception traces. */
    private const SENSITIVE = '#[\\SensitiveParameter] ';

    private const OUTPUT_SCALARS = [
        'String' => ['string', self::DECODE . 'string(...)'],
        'ID' => ['string', self::DECODE . 'string(...)'],
        'Uuid' => ['string', self::DECODE . 'string(...)'],
        'Int' => ['int', self::DECODE . 'int(...)'],
        'Float' => ['float', self::DECODE . 'float(...)'],
        'Boolean' => ['bool', self::DECODE . 'bool(...)'],
        'DateTime' => ['\\DateTimeImmutable', self::DECODE . 'dateTime(...)'],
    ];

    /** Input scalars: schema name => PHP native type. */
    private const INPUT_SCALARS = [
        'String' => 'string',
        'ID' => 'string',
        'Uuid' => 'string',
        'Int' => 'int',
        'Float' => 'float',
        'Boolean' => 'bool',
        'DateTime' => '\\DateTimeInterface',
    ];

    /**
     * Multipart uploads cannot travel in a JSON request; every input that has one also has a JSON alternative
     * (`PersonStoreInput.customAvatar` -> `encodedCustomAvatar`). Fields of these scalars are not generated.
     */
    private const SKIPPED_INPUT_SCALARS = ['FileUpload'];

    private readonly Schema $schema;

    /** @var array<string, FragmentDefinitionNode> */
    private array $fragments = [];

    /** @var array<string, string> relative path => source */
    private array $files = [];

    /** @var list<string> */
    private array $clientMethods = [];

    private function __construct(
        private readonly string $namespace,
        string $schemaSdl,
    ) {
        $this->schema = BuildSchema::build($schemaSdl);
    }

    /**
     * @param list<string> $fragmentSources one or more fragment definitions per source
     * @param list<string> $operationSources one operation per source
     *
     * @return array<string, string> relative path => PHP source, sorted by path
     */
    public static function generate(
        string $namespace,
        string $schemaSdl,
        array $fragmentSources,
        array $operationSources,
    ): array {
        $run = new self($namespace, $schemaSdl);
        $run->loadFragments($fragmentSources);
        $run->emitEnums();
        $run->emitInputs();
        $run->emitFragments();
        foreach (self::parseOperations($operationSources) as $operation) {
            $run->emitOperation($operation);
        }
        $run->emitClient();
        ksort($run->files);

        return $run->files;
    }

    /**
     * @param list<string> $sources
     */
    private function loadFragments(array $sources): void
    {
        foreach ($sources as $source) {
            foreach (Parser::parse($source)->definitions as $definition) {
                if (!$definition instanceof FragmentDefinitionNode) {
                    throw new \RuntimeException('Fragment files may contain fragment definitions only');
                }
                $this->fragments[$definition->name->value] = $definition;
            }
        }
        ksort($this->fragments);
    }

    /**
     * @param list<string> $sources
     *
     * @return array<string, OperationDefinitionNode> sorted by operation name
     */
    private static function parseOperations(array $sources): array
    {
        $operations = [];
        foreach ($sources as $source) {
            $definitions = iterator_to_array(Parser::parse($source)->definitions, false);
            $operation = $definitions[0] ?? null;
            if (\count($definitions) !== 1 || !$operation instanceof OperationDefinitionNode || !$operation->name instanceof NameNode) {
                throw new \RuntimeException('Every operation file must hold exactly one named operation');
            }
            $operations[$operation->name->value] = $operation;
        }
        ksort($operations);

        return $operations;
    }

    private function emitEnums(): void
    {
        foreach ($this->namedTypes() as $type) {
            if (!$type instanceof EnumType) {
                continue;
            }
            $cases = '';
            foreach ($type->getValues() as $value) {
                $cases .= Php::docBlock([
                    $value->description,
                    $value->deprecationReason === null ? null : '@deprecated ' . $value->deprecationReason,
                ], '    ');
                $cases .= \sprintf("    case %s = '%s';\n", $value->name, $value->name);
            }
            $fqcn = $this->enumClass($type->name);
            $this->addClass($fqcn, Php::docBlock([$type->description], '')
                . 'enum ' . Php::shortName($fqcn) . ": string\n{\n" . $cases . "}\n");
        }
    }

    private function emitInputs(): void
    {
        foreach ($this->namedTypes() as $type) {
            if (!$type instanceof InputObjectType) {
                continue;
            }
            $required = [];
            $optional = [];
            $docs = [];
            foreach ($type->getFields() as $field) {
                $php = $this->inputType($field->getType());
                if ($php === null) {
                    continue;
                }
                [$native, $doc] = $php;
                $isRequired = $field->getType() instanceof NonNull && !$field->defaultValueExists();
                $parameter = Php::docBlock([
                    $field->deprecationReason === null ? null : '@deprecated ' . $field->deprecationReason,
                ], '        ') . \sprintf(
                    '        public readonly %s $%s%s,',
                    $isRequired ? $native : Php::nullable($native),
                    $field->name,
                    $isRequired ? '' : ' = null',
                ) . "\n";
                if ($isRequired) {
                    $required[] = $parameter;
                } else {
                    $optional[] = $parameter;
                }
                $docs[] = Php::docTag('@param', $isRequired ? $doc : $doc . '|null', '$' . $field->name);
            }

            $fqcn = $this->inputClass($type);
            $this->addClass($fqcn, Php::docBlock([$type->description], '')
                . 'final class ' . Php::shortName($fqcn) . ' implements ' . self::RUNTIME . "InputObject\n{\n"
                . Php::docBlock($docs, '    ')
                . "    public function __construct(\n" . implode('', [...$required, ...$optional]) . "    ) {}\n\n"
                . "    public function toArray(): array\n    {\n"
                . '        return ' . self::RUNTIME . "Encode::inputObject(\$this);\n    }\n}\n");
        }
    }

    private function emitFragments(): void
    {
        foreach ($this->fragments as $name => $fragment) {
            $type = $this->schema->getType($fragment->typeCondition->name->value);
            if (!$type instanceof ObjectType) {
                throw new \RuntimeException(\sprintf('Fragment %s: only object type conditions are supported', $name));
            }
            $this->emitObject($this->fragmentClass($name), $type, [$fragment->selectionSet], null);
        }
    }

    private function emitOperation(OperationDefinitionNode $operation): void
    {
        \assert($operation->name instanceof NameNode);
        $name = $operation->name->value;
        $isMutation = $operation->operation === 'mutation';
        $rootType = $isMutation ? $this->schema->getMutationType() : $this->schema->getQueryType();
        if (!$rootType instanceof ObjectType || !\in_array($operation->operation, ['query', 'mutation'], true)) {
            throw new \RuntimeException(\sprintf('%s: only queries and mutations are supported', $name));
        }
        $document = $this->document($operation);
        $rootFields = $this->collectFields($rootType, [$operation->selectionSet]);
        if (\count($rootFields) !== 1) {
            throw new \RuntimeException(\sprintf('%s: exactly one root field is expected', $name));
        }
        $responseKey = array_key_first($rootFields);
        $rootField = $rootFields[$responseKey];
        if (Php::camel($rootField['name']) === 'execute') {
            throw new \RuntimeException('Root field `execute` would collide with GeneratedClient::execute()');
        }

        $fqcn = $this->namespace . '\\Operation\\' . ($isMutation ? 'Mutation' : 'Query') . '\\' . $name;
        $result = $this->outputType($rootType->getField($rootField['name'])->getType(), $rootField['sets'], $fqcn . '\\Result');
        $variables = $this->variables($operation);

        $this->addClass($fqcn, $this->operationClassSource($name, $rootField['name'], $isMutation, $document, $responseKey, $result, $variables));
        $this->clientMethods[] = $this->clientMethodSource($fqcn, $rootField['name'], $result, $variables);
    }

    /**
     * @param array{native: string, doc: string, decoder: string} $result
     */
    private function operationClassSource(
        string $name,
        string $rootFieldName,
        bool $isMutation,
        string $document,
        string $responseKey,
        array $result,
        OperationVariables $variables,
    ): string {
        return Php::docBlock(['`' . $rootFieldName . '` ' . ($isMutation ? 'mutation' : 'query') . '.'], '')
            . 'final class ' . $name . "\n{\n"
            . "    public const NAME = '" . $name . "';\n\n"
            . '    public const TYPE = ' . self::RUNTIME . 'OperationType::' . ($isMutation ? 'Mutation' : 'Query') . ";\n\n"
            . '    public const DOCUMENT = ' . Php::nowdoc($document, 'GRAPHQL', '        ') . ";\n\n"
            . Php::docBlock([...$variables->docs, '', '@return array<string, mixed>'], '    ')
            . '    public static function variables(' . $variables->parameters . "): array\n    {\n"
            . '        return ' . self::RUNTIME . "Encode::object([\n" . $variables->encoded . "        ]);\n    }\n\n"
            . Php::docBlock(['@param array<mixed> $data', Php::docTag('@return', $result['doc'])], '    ')
            . '    public static function decode(' . self::SENSITIVE . 'array $data): ' . $result['native'] . "\n    {\n"
            . \sprintf("        return %sfield(\$data, '%s', '', %s);\n", self::DECODE, $responseKey, $result['decoder'])
            . "    }\n}\n";
    }

    /**
     * @param array{native: string, doc: string, decoder: string} $result
     */
    private function clientMethodSource(string $fqcn, string $rootFieldName, array $result, OperationVariables $variables): string
    {
        $operation = '\\' . $fqcn;

        return Php::docBlock([...$variables->docs, Php::docTag('@return', $result['doc'])], '    ')
            . '    public function ' . Php::camel($rootFieldName) . '(' . $variables->parameters . '): ' . $result['native'] . "\n    {\n"
            . '        return ' . $operation . '::decode($this->executor->execute(' . $operation . '::DOCUMENT, '
            . $operation . '::NAME, ' . $operation . '::TYPE, ' . $operation . '::variables(' . $variables->arguments . ")));\n    }\n";
    }

    private function emitClient(): void
    {
        $this->addClass(
            $this->namespace . '\\GeneratedClient',
            Php::docBlock(['One typed method per partner API operation (ADR 0002).'], '')
            . "final class GeneratedClient\n{\n"
            . '    public function __construct(private readonly ' . self::RUNTIME . "OperationExecutor \$executor) {}\n"
            . implode('', array_map(static fn(string $method): string => "\n" . $method, $this->clientMethods))
            . "\n" . Php::docBlock([
                'Runs a hand-written document and returns its raw `data`. A document that is not clearly a query',
                'is executed as a mutation, so it is never retried (RawDocument::operationType()).',
                '',
                '@param array<string, mixed> $variables JSON-ready values',
                '@param string|null $operationName required when the document holds several operations',
                '',
                '@return array<mixed>',
            ], '    ')
            . "    public function execute(string \$document, " . self::SENSITIVE . "array \$variables = [], ?string \$operationName = null): array\n    {\n"
            . '        return $this->executor->execute($document, $operationName ?? ' . self::RUNTIME
            . 'RawDocument::operationName($document), ' . self::RUNTIME . "RawDocument::operationType(\$document), \$variables);\n"
            . "    }\n}\n",
        );
    }

    private function variables(OperationDefinitionNode $operation): OperationVariables
    {
        $required = [];
        $optional = [];
        $docs = [];
        $arguments = [];
        $encoded = '';
        foreach ($operation->variableDefinitions as $definition) {
            $name = $definition->variable->name->value;
            $type = $this->typeFromNode($definition->type);
            $php = $this->inputType($type) ?? throw new \RuntimeException(\sprintf('Variable $%s: unsupported type', $name));
            [$native, $doc] = $php;
            $isRequired = $type instanceof NonNull && $definition->defaultValue === null;
            if ($isRequired) {
                $required[] = self::SENSITIVE . $native . ' $' . $name;
            } else {
                $optional[] = self::SENSITIVE . Php::nullable($native) . ' $' . $name . ' = null';
            }
            $docs[] = Php::docTag('@param', $isRequired ? $doc : $doc . '|null', '$' . $name);
            $arguments[] = $name . ': $' . $name;
            $encoded .= \sprintf("            '%s' => \$%s,\n", $name, $name);
        }

        return new OperationVariables(implode(', ', [...$required, ...$optional]), $docs, implode(', ', $arguments), $encoded);
    }

    /**
     * Emits a result class for a selection on a concrete object type, or on an interface type that is not
     * discriminated (no `__typename`, no concrete-type fragment): then the interface's own fields are the class.
     * The caller references it by `$fqcn`.
     *
     * @param list<SelectionSetNode> $sets
     * @param string|null $interface FQCN of the selection interface this class implements (abstract types)
     */
    private function emitObject(string $fqcn, ObjectType|InterfaceType $type, array $sets, ?string $interface): void
    {
        $parameters = '';
        $docs = [];
        $assignments = '';
        foreach ($this->collectFields($type, $sets) as $key => $field) {
            $result = $this->outputType($type->getField($field['name'])->getType(), $field['sets'], $this->childClass($fqcn, $key));
            if (preg_match(self::CREDENTIAL_FIELD, $field['name']) === 1 && \in_array($result['native'], ['string', '?string'], true)) {
                $result = $this->secretType($result['native'] === '?string');
            }
            $parameters .= \sprintf("        public readonly %s $%s,\n", $result['native'], $key);
            $docs[] = Php::docTag('@param', $result['doc'], '$' . $key);
            $assignments .= \sprintf("            %s: %sfield(\$data, '%s', \$path, %s),\n", $key, self::DECODE, $key, $result['decoder']);
        }

        $constructor = $parameters === ''
            ? "    public function __construct() {}\n"
            : Php::docBlock($docs, '    ') . "    public function __construct(\n" . $parameters . "    ) {}\n";
        $build = $assignments === ''
            ? '        ' . self::DECODE . "object(\$value, \$path);\n\n        return new self();\n"
            : '        $data = ' . self::DECODE . "object(\$value, \$path);\n\n        return new self(\n" . $assignments . "        );\n";

        $this->addClass($fqcn, Php::docBlock(['Selection of `' . $type->name . '`.'], '')
            . 'final class ' . Php::shortName($fqcn) . ($interface === null ? '' : ' implements \\' . $interface) . "\n{\n"
            . $constructor . "\n"
            . "    public static function fromValue(" . self::SENSITIVE . "mixed \$value, string \$path): self\n    {\n" . $build . "    }\n}\n", true);
    }

    /**
     * Whether the response can name the concrete type: `__typename` is selected, or a fragment targets a concrete type.
     *
     * @param list<SelectionSetNode> $sets
     */
    private function discriminates(array $sets): bool
    {
        foreach ($sets as $set) {
            foreach ($set->selections as $selection) {
                if ($selection instanceof FieldNode) {
                    if ($selection->name->value === '__typename') {
                        return true;
                    }

                    continue;
                }
                $parts = $this->fragmentParts($selection);
                if ($parts === null) {
                    continue;
                }
                [$condition, $nested] = $parts;
                if (($condition !== null && $this->schema->getType($condition) instanceof ObjectType) || $this->discriminates([$nested])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{native: string, doc: string, decoder: string}
     */
    private function secretType(bool $nullable): array
    {
        $decoder = self::DECODE . 'secret(...)';

        return $nullable
            ? ['native' => '?' . self::SECRET, 'doc' => self::SECRET . '|null', 'decoder' => self::DECODE . 'nullableOf(' . $decoder . ')']
            : ['native' => self::SECRET, 'doc' => self::SECRET, 'decoder' => $decoder];
    }

    /**
     * PHP type, PHPDoc type and decoder expression for an output type; emits the classes it needs.
     *
     * @param list<SelectionSetNode> $sets sub-selections of the field (empty for leaf types)
     *
     * @return array{native: string, doc: string, decoder: string}
     */
    private function outputType(Type $type, array $sets, string $classBase): array
    {
        if ($type instanceof NonNull) {
            return $this->outputNonNull($type->getWrappedType(), $sets, $classBase);
        }
        $inner = $this->outputNonNull($type, $sets, $classBase);

        return [
            'native' => Php::nullable($inner['native']),
            'doc' => $inner['doc'] . '|null',
            'decoder' => self::DECODE . 'nullableOf(' . $inner['decoder'] . ')',
        ];
    }

    /**
     * @param list<SelectionSetNode> $sets
     *
     * @return array{native: string, doc: string, decoder: string}
     */
    private function outputNonNull(Type $type, array $sets, string $classBase): array
    {
        if ($type instanceof ListOfType) {
            $item = $this->outputType($type->getWrappedType(), $sets, $classBase);

            return ['native' => 'array', 'doc' => 'list<' . $item['doc'] . '>', 'decoder' => self::DECODE . 'listOf(' . $item['decoder'] . ')'];
        }
        if ($type instanceof ScalarType) {
            [$native, $decoder] = self::OUTPUT_SCALARS[$type->name]
                ?? throw new \RuntimeException(\sprintf('Scalar %s cannot be selected', $type->name));

            return ['native' => $native, 'doc' => $native, 'decoder' => $decoder];
        }
        if ($type instanceof EnumType) {
            $enum = '\\' . $this->enumClass($type->name);
            $native = $enum . '|' . self::UNKNOWN_ENUM;

            return ['native' => $native, 'doc' => $native, 'decoder' => self::DECODE . 'enumOf(' . $enum . '::class)'];
        }
        if ($type instanceof ObjectType) {
            $fqcn = $this->reusedFragment($type, $sets);
            if ($fqcn === null) {
                $fqcn = $classBase;
                $this->emitObject($fqcn, $type, $sets, null);
            }

            return ['native' => '\\' . $fqcn, 'doc' => '\\' . $fqcn, 'decoder' => '\\' . $fqcn . '::fromValue(...)'];
        }
        if ($type instanceof InterfaceType || $type instanceof UnionType) {
            return $this->abstractType($type, $sets, $classBase);
        }

        throw new \RuntimeException(\sprintf('Type %s cannot be selected', $type->toString()));
    }

    /**
     * An interface per selection and one class per possible type; unknown `__typename` decodes to UnknownTypeValue.
     * An InterfaceType selection that is not discriminated (no `__typename`, no per-type fragment) is the exception:
     * it becomes ONE class over the interface's own fields, named `$interface`, and no interface is emitted.
     *
     * @param list<SelectionSetNode> $sets
     *
     * @return array{native: string, doc: string, decoder: string}
     */
    private function abstractType(InterfaceType|UnionType $type, array $sets, string $interface): array
    {
        $possibleTypes = $this->schema->getPossibleTypes($type);
        if ($possibleTypes === []) {
            // The partner schema hides every implementation (e.g. `MarketOrder`): only the raw object can be kept.
            $native = self::RUNTIME . 'UnknownTypeValue';

            return ['native' => $native, 'doc' => $native, 'decoder' => self::DECODE . 'unknownType(...)'];
        }
        if (!$this->discriminates($sets)) {
            if ($type instanceof UnionType) {
                throw new \RuntimeException(\sprintf('Union %s is selected without __typename or a type condition: its members cannot be told apart', $type->name));
            }
            // Nothing in the response tells the implementations apart (no `__typename`, no per-type fragment):
            // keep the fields of the interface itself in one class instead of an interface the decoder cannot choose from.
            $this->emitObject($interface, $type, $sets, null);
            $native = '\\' . $interface;

            return ['native' => $native, 'doc' => $native, 'decoder' => $native . '::fromValue(...)'];
        }
        $this->addClass($interface, Php::docBlock([
            'Selection of `' . $type->name . '`: one implementation per possible type, chosen by `__typename`.',
            'A type this SDK version does not know decodes to UnknownTypeValue.',
        ], '') . 'interface ' . Php::shortName($interface) . " {}\n");

        usort($possibleTypes, static fn(ObjectType $a, ObjectType $b): int => strcmp($a->name, $b->name));
        $variants = [];
        foreach ($possibleTypes as $possibleType) {
            $fqcn = $interface . Php::pascal($possibleType->name);
            $this->emitObject($fqcn, $possibleType, $sets, $interface);
            $variants[] = \sprintf("'%s' => \\%s::fromValue(...)", $possibleType->name, $fqcn);
        }
        $native = '\\' . $interface . '|' . self::RUNTIME . 'UnknownTypeValue';

        return ['native' => $native, 'doc' => $native, 'decoder' => self::DECODE . 'abstractOf([' . implode(', ', $variants) . '])'];
    }

    /**
     * @return array{string, string}|null [native, PHPDoc] or null for a field that is not generated
     */
    private function inputType(Type $type): ?array
    {
        $inner = $type instanceof NonNull ? $type->getWrappedType() : $type;
        if ($inner instanceof ListOfType) {
            $itemType = $inner->getWrappedType();
            $item = $this->inputType($itemType);

            // A nullable item stays nullable: dropping the nulls would shift positional lists (slots).
            return $item === null ? null : ['array', 'list<' . $item[1] . ($itemType instanceof NonNull ? '' : '|null') . '>'];
        }
        if ($inner instanceof ScalarType) {
            if (\in_array($inner->name, self::SKIPPED_INPUT_SCALARS, true)) {
                return null;
            }
            $native = self::INPUT_SCALARS[$inner->name]
                ?? throw new \RuntimeException(\sprintf('Input scalar %s is not mapped', $inner->name));
        } elseif ($inner instanceof EnumType) {
            $native = '\\' . $this->enumClass($inner->name);
        } elseif ($inner instanceof InputObjectType) {
            $native = '\\' . $this->inputClass($inner);
        } else {
            throw new \RuntimeException(\sprintf('Type %s is not an input type', $inner->toString()));
        }

        return [$native, $native];
    }

    private function typeFromNode(ListTypeNode|NamedTypeNode|NonNullTypeNode $node): Type
    {
        if ($node instanceof NonNullTypeNode) {
            $inner = $this->typeFromNode($node->type);
            \assert($inner instanceof NullableType);

            return Type::nonNull($inner);
        }
        if ($node instanceof ListTypeNode) {
            return Type::listOf($this->typeFromNode($node->type));
        }

        return $this->schema->getType($node->name->value)
            ?? throw new \RuntimeException(\sprintf('Unknown type %s', $node->name->value));
    }

    /**
     * The type condition (null for an inline fragment without one) and the selections of a fragment spread or inline
     * fragment; null for any other selection.
     *
     * @return array{?string, SelectionSetNode}|null
     */
    private function fragmentParts(SelectionNode $selection): ?array
    {
        if ($selection instanceof FragmentSpreadNode) {
            $fragment = $this->fragment($selection->name->value);

            return [$fragment->typeCondition->name->value, $fragment->selectionSet];
        }
        if ($selection instanceof InlineFragmentNode) {
            return [$selection->typeCondition?->name->value, $selection->selectionSet];
        }

        return null;
    }

    /**
     * Fields that apply to `$type` (a concrete object type, or an interface type in the non-discriminated case), merged
     * by response key (GraphQL field collection).
     *
     * @param list<SelectionSetNode> $sets
     *
     * @return array<string, array{name: string, sets: list<SelectionSetNode>}>
     */
    private function collectFields(ObjectType|InterfaceType $type, array $sets): array
    {
        $fields = [];
        foreach ($sets as $set) {
            foreach ($set->selections as $selection) {
                if ($selection instanceof FieldNode) {
                    $name = $selection->name->value;
                    if ($name === '__typename') {
                        continue;
                    }
                    $key = $selection->alias->value ?? $name;
                    $fields[$key] ??= ['name' => $name, 'sets' => []];
                    if ($selection->selectionSet instanceof SelectionSetNode) {
                        $fields[$key]['sets'][] = $selection->selectionSet;
                    }

                    continue;
                }
                $parts = $this->fragmentParts($selection);
                if ($parts === null) {
                    continue;
                }
                [$condition, $nested] = $parts;
                if ($condition !== null && !$this->appliesTo($condition, $type)) {
                    continue;
                }
                foreach ($this->collectFields($type, [$nested]) as $key => $field) {
                    $fields[$key] ??= ['name' => $field['name'], 'sets' => []];
                    $fields[$key]['sets'] = [...$fields[$key]['sets'], ...$field['sets']];
                }
            }
        }

        return $fields;
    }

    private function appliesTo(string $condition, ObjectType|InterfaceType $type): bool
    {
        if ($condition === $type->name) {
            return true;
        }
        foreach ($type->getInterfaces() as $interface) {
            if ($interface->name === $condition) {
                return true;
            }
        }
        $conditionType = $this->schema->getType($condition);

        return $conditionType instanceof UnionType && \in_array($type, $conditionType->getTypes(), true);
    }

    /**
     * A selection that is exactly `...XFields` on the fragment's own type reuses the shared fragment class.
     *
     * @param list<SelectionSetNode> $sets
     */
    private function reusedFragment(ObjectType $type, array $sets): ?string
    {
        $selections = [];
        foreach ($sets as $set) {
            foreach ($set->selections as $selection) {
                $selections[] = $selection;
            }
        }
        $only = $selections[0] ?? null;
        if (\count($selections) !== 1 || !$only instanceof FragmentSpreadNode) {
            return null;
        }

        return $this->fragment($only->name->value)->typeCondition->name->value === $type->name
            ? $this->fragmentClass($only->name->value)
            : null;
    }

    /**
     * The operation plus every fragment it uses (transitively), validated against the schema.
     */
    private function document(OperationDefinitionNode $operation): string
    {
        $used = [];
        $this->usedFragments($operation->selectionSet, $used);
        ksort($used);
        $parts = [Printer::doPrint($operation)];
        foreach (array_keys($used) as $name) {
            $parts[] = Printer::doPrint($this->fragment($name));
        }
        $document = implode("\n\n", $parts);

        $errors = DocumentValidator::validate($this->schema, Parser::parse($document));
        if ($errors !== []) {
            throw new \RuntimeException(\sprintf(
                "Operation %s is invalid against the schema:\n%s",
                $operation->name->value ?? '?',
                implode("\n", array_map(static fn(\Throwable $error): string => $error->getMessage(), $errors)),
            ));
        }

        return $document;
    }

    /**
     * @param array<string, true> $used
     */
    private function usedFragments(SelectionSetNode $set, array &$used): void
    {
        foreach ($set->selections as $selection) {
            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                if (isset($used[$name])) {
                    continue;
                }
                $used[$name] = true;
                $this->usedFragments($this->fragment($name)->selectionSet, $used);
            } elseif (($selection instanceof FieldNode || $selection instanceof InlineFragmentNode) && $selection->selectionSet instanceof SelectionSetNode) {
                $this->usedFragments($selection->selectionSet, $used);
            }
        }
    }

    private function fragment(string $name): FragmentDefinitionNode
    {
        return $this->fragments[$name] ?? throw new \RuntimeException(\sprintf('Unknown fragment %s', $name));
    }

    /**
     * Children of an operation's `Result` sit next to it (`MarketList\Edges`); deeper ones append the key to the parent.
     */
    private function childClass(string $parent, string $key): string
    {
        $short = Php::shortName($parent);
        if ($short === 'Result') {
            return Php::namespaceOf($parent) . '\\' . Php::className(Php::pascal($key));
        }

        return Php::namespaceOf($parent) . '\\' . Php::className($short . Php::pascal($key));
    }

    private function fragmentClass(string $name): string
    {
        return $this->namespace . '\\Fragment\\' . Php::className($name);
    }

    private function inputClass(InputObjectType $type): string
    {
        return $this->namespace . '\\Input\\' . Php::className($type->name);
    }

    private function enumClass(string $name): string
    {
        return $this->namespace . '\\Enum\\' . Php::className($name);
    }

    /**
     * @return list<NamedType&Type> schema types without introspection types, sorted by name
     */
    private function namedTypes(): array
    {
        $types = [];
        foreach ($this->schema->getTypeMap() as $name => $type) {
            if (!str_starts_with($name, '__')) {
                $types[$name] = $type;
            }
        }
        ksort($types);

        return array_values($types);
    }

    /**
     * Registers a class file; the same class may be requested again only when it is the same selection.
     */
    private function addClass(string $fqcn, string $body, bool $allowSameContent = false): void
    {
        $path = str_replace('\\', '/', substr($fqcn, \strlen($this->namespace) + 1)) . '.php';
        $source = Php::file(Php::namespaceOf($fqcn), $body);
        if (isset($this->files[$path]) && (!$allowSameContent || $this->files[$path] !== $source)) {
            throw new \RuntimeException(\sprintf('Generated class name collision: %s', $fqcn));
        }
        $this->files[$path] = $source;
    }
}
