<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal\Compile;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\CompositionArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CompositionArbitraries::class)]
final class CompositionArbitrariesTest
{
    public function anUnevaluatedPropertiesSiblingClosesTheConjunction(): void
    {
        $schema = ['allOf' => [['type' => 'object']], 'unevaluatedProperties' => false];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 30, seed: 1) as $value) {
            Assert::same($value, []);
        }
    }

    public function aSiblingAfterAnUnevaluatedKeywordIsStillRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: allOf with sibling keyword "minimum" is outside the supported subset');
        (new SchemaArbitraryCompiler())->compile(['allOf' => [['type' => 'integer']], 'unevaluatedItems' => false, 'minimum' => 1]);
    }

    public function everyNumericOneOfBranchIsEquallyLikely(): void
    {
        $schema = ['oneOf' => [
            ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ['type' => 'number', 'minimum' => 200, 'maximum' => 300],
            ['type' => 'string', 'maxLength' => 3],
        ]];

        $kinds = ['int' => 0, 'float' => 0, 'string' => 0];
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 900, seed: 6) as $value) {
            ++$kinds[match (true) {
                is_int($value) => 'int',
                is_float($value) => 'float',
                default => 'string',
            }];
        }
        foreach ($kinds as $kind => $count) {
            Assert::true($count > 225 && $count < 375, sprintf('%s drawn %d times in 900', $kind, $count));
        }
    }

    public function aNumberBranchWithANonNumericMultipleIsRefusedByItsOwnRule(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: number multipleOf must be numeric');
        (new SchemaArbitraryCompiler())->compile(['oneOf' => [
            ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
            ['type' => 'number', 'multipleOf' => 'x'],
        ]]);
    }

    public function aNotWithAConstBesideATypeExcludesOnlyTheConst(): void
    {
        $schema = ['type' => 'integer', 'minimum' => 0, 'maximum' => 10, 'not' => ['const' => 5, 'type' => 'integer']];

        $values = Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 100, seed: 2);
        foreach ($values as $value) {
            Assert::true(is_int($value) && $value !== 5);
        }
        Assert::true(count(array_unique($values)) > 5);
    }

    public function aNotConstOfNullLeavesAStringSchemaAlone(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'string', 'maxLength' => 3, 'not' => ['const' => null]]), count: 20, seed: 2) as $value) {
            Assert::true(is_string($value));
        }
    }

    public function aNotTypeEqualToTheDeclaredTypeIsRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: not excludes every value of the declared type');
        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'not' => ['type' => 'string']]);
    }

    public function aDeclaredTypeListOutranksTheTypeItsKeywordsImply(): void
    {
        $schema = ['type' => ['object', 'null'], 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a'], 'not' => ['type' => 'object']];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 10, seed: 1) as $value) {
            Assert::null($value);
        }
    }

    /**
     * `[]` is the empty array and the empty object alike: its type under
     * `not` comes from the schema that produced it (#146).
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('emptyContainerProvider')]
    public function anEmptyContainerTakesItsTypeFromTheSchema(array $schema, bool $emptyKept): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 100, seed: 1);

        Assert::same(in_array([], $values, strict: true), $emptyKept);
    }

    public static function emptyContainerProvider(): iterable
    {
        yield 'an empty object is an object' => [['type' => ['object', 'null'], 'properties' => ['a' => ['type' => 'string']], 'not' => ['type' => 'object']], false];
        yield 'properties alone make it an object' => [['properties' => ['a' => ['type' => 'boolean']], 'not' => ['type' => 'array']], true];
        yield 'an empty array is no object' => [['type' => 'array', 'items' => ['type' => 'boolean'], 'maxItems' => 1, 'not' => ['type' => 'object']], true];
        yield 'an empty object is no array' => [['type' => 'object', 'properties' => ['a' => ['type' => 'boolean']], 'not' => ['type' => 'array']], true];
        yield 'items alone make it an array' => [['items' => ['type' => 'boolean'], 'maxItems' => 1, 'not' => ['type' => 'object']], true];
        yield 'a nullable empty object is no array' => [['type' => ['object', 'null'], 'properties' => ['a' => ['type' => 'boolean']], 'not' => ['type' => 'array']], true];
        yield 'a nullable empty array is no object' => [['type' => ['array', 'null'], 'items' => ['type' => 'boolean'], 'maxItems' => 1, 'not' => ['type' => 'object']], true];
        yield 'a nullable empty array is an array' => [['type' => ['array', 'null'], 'items' => ['type' => 'boolean'], 'maxItems' => 1, 'not' => ['type' => 'array']], false];
        yield 'either container is an object too' => [['type' => ['array', 'object', 'null'], 'items' => ['type' => 'boolean'], 'maxItems' => 1, 'properties' => ['a' => ['type' => 'boolean']], 'not' => ['type' => 'object']], false];
        yield 'either container is both' => [['type' => ['array', 'object', 'null'], 'items' => ['type' => 'boolean'], 'maxItems' => 1, 'properties' => ['a' => ['type' => 'boolean']], 'not' => ['type' => 'array']], false];
    }

    public function aBranchCarryingItsOwnPrefixBesideItsItemsMerges(): void
    {
        $schema = ['allOf' => [[
            'type' => 'array',
            'prefixItems' => [['type' => 'integer', 'minimum' => 0, 'maximum' => 0]],
            'items' => ['type' => 'string', 'maxLength' => 2],
            'minItems' => 1,
            'maxItems' => 3,
        ]]];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 1) as $value) {
            Assert::true(is_array($value));
            Assert::same($value[0], 0);
        }
    }
}
