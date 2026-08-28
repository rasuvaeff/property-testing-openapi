<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SchemaArbitraryCompiler::class)]
#[Covers(UnsupportedGeneration::class)]
final class SchemaArbitraryCompilerTest
{
    public function generatesBoundedObjectsDeterministically(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['id', 'name'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 9],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                'tags' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 4]],
            ],
        ];
        $compiler = new SchemaArbitraryCompiler();

        $first = Gen::sample($compiler->compile($schema), count: 10, seed: 17);
        $second = Gen::sample($compiler->compile($schema), count: 10, seed: 17);

        Assert::same($first, $second);
        foreach ($first as $value) {
            Assert::true(is_array($value));
            Assert::true(is_int($value['id']) && $value['id'] >= 2 && $value['id'] <= 9);
            Assert::true(is_string($value['name']) && mb_strlen($value['name']) >= 1 && mb_strlen($value['name']) <= 8);
            if (array_key_exists('tags', $value)) {
                Assert::true(is_array($value['tags']) && count($value['tags']) >= 1 && count($value['tags']) <= 3);
            }
        }
    }

    public function preservesConstAndEnumValues(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        Assert::same(Gen::sample($compiler->compile(['const' => 'fixed']), count: 3, seed: 3), ['fixed', 'fixed', 'fixed']);
        foreach (Gen::sample($compiler->compile(['enum' => ['small', 'large']]), count: 10, seed: 3) as $value) {
            Assert::true(in_array($value, ['small', 'large'], strict: true));
        }
    }

    public function supportsNullableFormatsAndMultiples(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        $nullable = Gen::sample($compiler->compile(['type' => ['string', 'null'], 'minLength' => 2]), count: 30, seed: 11);
        Assert::true(in_array(null, $nullable, strict: true));
        foreach ($nullable as $value) {
            Assert::true($value === null || (is_string($value) && strlen($value) >= 2));
        }

        foreach (Gen::sample($compiler->compile(['type' => 'integer', 'minimum' => -20, 'maximum' => 20, 'multipleOf' => 4]), count: 20, seed: 7) as $value) {
            Assert::true(is_int($value) && $value % 4 === 0);
        }
        foreach (Gen::sample($compiler->compile(['type' => 'string', 'format' => 'uuid']), count: 5, seed: 3) as $value) {
            Assert::true(is_string($value) && preg_match('/^[0-9a-f-]{36}$/', $value) === 1);
        }
    }

    public function optionalObjectPropertiesAreGeneratedAsBranches(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
            ],
        ]);
        $values = Gen::sample($arbitrary, count: 40, seed: 5);
        Assert::true(count(array_filter($values, static fn(mixed $value): bool => is_array($value) && array_key_exists('label', $value))) > 0);
        Assert::true(count(array_filter($values, static fn(mixed $value): bool => is_array($value) && !array_key_exists('label', $value))) > 0);
        foreach ($values as $value) {
            Assert::true(is_array($value) && array_key_exists('id', $value));
        }
    }

    public function rejectsUnsupportedPatternSyntax(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'pattern' => '(?=x)']);
    }

    public function rejectsUnknownFormats(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => 'binary']);
    }

    public function honorsObjectCardinalityAndAdditionalPropertyPolicy(): void
    {
        $schema = [
            'type' => 'object',
            'minProperties' => 2,
            'maxProperties' => 3,
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => 'integer'],
                'label' => ['type' => 'string'],
                'active' => ['type' => 'boolean'],
            ],
        ];
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 13) as $value) {
            Assert::true(is_array($value));
            Assert::true(count($value) >= 2 && count($value) <= 3);
            Assert::true(array_diff(array_keys($value), ['id', 'label', 'active']) === []);
        }
    }

    public function rejectsImpossibleObjectCardinality(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'minProperties' => 2,
            'additionalProperties' => false,
            'properties' => ['id' => ['type' => 'integer']],
        ]);
    }

    public function generatesSchemaConstrainedAdditionalProperties(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'minProperties' => 2,
            'maxProperties' => 2,
            'additionalProperties' => [
                'type' => 'integer',
                'minimum' => 7,
                'maximum' => 7,
            ],
            'properties' => [],
        ]);

        foreach (Gen::sample($arbitrary, count: 20, seed: 19) as $value) {
            Assert::true(is_array($value) && count($value) === 2);
            Assert::same(array_values($value), [7, 7]);
        }
    }

    public function rejectsInvalidAdditionalPropertiesShape(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'additionalProperties' => ['string'],
        ]);
    }

    public function acceptsAnEmptyAdditionalPropertiesSchema(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'minProperties' => 1,
            'maxProperties' => 1,
            'additionalProperties' => [],
            'properties' => [],
        ]);

        foreach (Gen::sample($arbitrary, count: 20, seed: 23) as $value) {
            Assert::true(is_array($value) && count($value) === 1);
            Assert::true(is_string(array_values($value)[0]) || is_int(array_values($value)[0])
                || is_bool(array_values($value)[0]) || array_values($value)[0] === null);
        }
    }

    public function rejectsUnsupportedNumericExclusiveBounds(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'number', 'exclusiveMinimum' => 0]);
    }

    public function rejectsRequiredPropertiesWithoutASchema(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'object', 'required' => ['id'], 'properties' => []]);
    }

    public function compilesAnyOfBranches(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'anyOf' => [
                ['type' => 'string', 'const' => 'ready'],
                ['type' => 'integer', 'minimum' => 3, 'maximum' => 3],
            ],
        ]);

        foreach (Gen::sample($arbitrary, count: 20, seed: 31) as $value) {
            Assert::true($value === 'ready' || $value === 3);
        }
    }

    public function compilesDisjointOneOfBranches(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'oneOf' => [
                ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
                ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            ],
        ]);

        foreach (Gen::sample($arbitrary, count: 20, seed: 37) as $value) {
            Assert::true((is_string($value) && $value !== '') || (is_int($value) && $value >= 1 && $value <= 4));
        }
    }

    public function rejectsOverlappingOneOfBranches(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile([
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'string', 'minLength' => 2],
            ],
        ]);
    }

    public function supportsNullAsASeparateOneOfBranch(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'oneOf' => [
                ['type' => 'null'],
                ['type' => 'string', 'const' => 'value'],
            ],
        ]);

        foreach (Gen::sample($arbitrary, count: 20, seed: 43) as $value) {
            Assert::true($value === null || $value === 'value');
        }
    }

    public function rejectsCombinatorAssertionSiblings(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile([
            'anyOf' => [['type' => 'string']],
            'minLength' => 1,
        ]);
    }

    public function mergesObjectAllOfBranches(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'allOf' => [
                [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]],
                ],
                [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4]],
                ],
            ],
        ]);

        foreach (Gen::sample($arbitrary, count: 20, seed: 41) as $value) {
            Assert::true(is_array($value) && array_key_exists('id', $value) && array_key_exists('name', $value));
        }
    }
}
