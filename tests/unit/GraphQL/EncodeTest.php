<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\GraphQL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\GraphQL\Encode;
use WhiteMarket\Sdk\GraphQL\InputObject;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(Encode::class)]
final class EncodeTest extends TestCase
{
    #[Test]
    public function dropsNullFieldsSoTheServerAppliesDefaults(): void
    {
        self::assertSame(['a' => 1, 'c' => false, 'd' => ''], Encode::object(['a' => 1, 'b' => null, 'c' => false, 'd' => '']));
    }

    #[Test]
    public function encodesNestedValues(): void
    {
        $input = new class implements InputObject {
            public function toArray(): array
            {
                return ['nested' => true];
            }
        };

        self::assertSame(
            [
                'input' => ['nested' => true],
                'enum' => 'query',
                'date' => '2023-11-29T23:33:27+03:00',
                'list' => ['mutation', ['nested' => true], null],
                'money' => '0.10',
            ],
            Encode::object([
                'input' => $input,
                'enum' => OperationType::Query,
                'date' => new \DateTimeImmutable('2023-11-29T23:33:27+03:00'),
                'list' => [OperationType::Mutation, $input, null],
                'money' => '0.10',
            ]),
        );
    }

    #[Test]
    public function inputObjectEncodesItsPublicPropertiesWithoutNulls(): void
    {
        $input = new class (OperationType::Mutation, null, ['x']) implements InputObject {
            /**
             * @param list<string> $ids
             */
            public function __construct(
                public readonly OperationType $type,
                public readonly ?string $omitted,
                public readonly array $ids,
                private readonly string $secret = 'not encoded',
            ) {}

            public function toArray(): array
            {
                return Encode::inputObject($this) + ['unused' => $this->secret];
            }
        };

        self::assertSame(['type' => 'mutation', 'ids' => ['x']], Encode::inputObject($input));
    }

    /**
     * A user input object that throws while a list holding a credential is encoded: no frame of the encoder,
     * including the internal frames it calls, may carry the list.
     */
    #[Test]
    public function keepsAListOutOfTheTraceWhenAnElementThrows(): void
    {
        $credential = 'synthetic-credential-91c2';
        $throwing = new class implements InputObject {
            public function toArray(): array
            {
                throw new \RuntimeException('input failed');
            }
        };

        $e = TraceArguments::caught(static fn(): array => Encode::object(['ids' => [$credential, $throwing]]));

        self::assertSame('input failed', $e->getMessage());
        self::assertStringNotContainsString($credential, TraceArguments::exportedSdkFrames($e));
    }
}
