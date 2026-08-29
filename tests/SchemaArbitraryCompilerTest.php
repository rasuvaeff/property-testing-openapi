<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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

    public function compilesAnEmptySchemaToJsonCompatibleValues(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([]), count: 40, seed: 13);

        foreach ($values as $value) {
            Assert::true($value === null || is_bool($value) || is_int($value) || is_string($value));
        }
        Assert::true(count(array_unique(array_map(get_debug_type(...), $values))) >= 2);
    }

    public function keepsNullOnlySchemasNull(): void
    {
        Assert::same(Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'null']), count: 5, seed: 17), [null, null, null, null, null]);
    }

    public function infersArrayAndObjectFromStructuralKeywords(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        foreach (Gen::sample($compiler->compile(['items' => ['type' => 'integer', 'const' => 3]]), count: 5, seed: 19) as $value) {
            Assert::true(is_array($value) && array_is_list($value));
            foreach ($value as $item) {
                Assert::same($item, 3);
            }
        }
        foreach (Gen::sample($compiler->compile(['properties' => ['id' => ['type' => 'integer', 'const' => 3]], 'required' => ['id']]), count: 5, seed: 23) as $value) {
            Assert::true(is_array($value) && !array_is_list($value));
            Assert::same($value['id'], 3);
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
        $nullable = Gen::sample($compiler->compile(['type' => 'string', 'nullable' => true, 'const' => 'value']), count: 30, seed: 73);
        Assert::true(in_array(null, $nullable, strict: true));
        Assert::true(in_array('value', $nullable, strict: true));
    }

    public function honorsPatternAndLengthBounds(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'string',
            'pattern' => '^x+$',
            'minLength' => 2,
            'maxLength' => 4,
        ]), count: 20, seed: 61) as $value) {
            Assert::true(is_string($value) && preg_match('/^x+$/', $value) === 1);
            Assert::true(mb_strlen($value) >= 2 && mb_strlen($value) <= 4);
        }
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'string',
            'pattern' => '^xx$',
            'minLength' => 2,
            'maxLength' => 2,
        ]), count: 5, seed: 79) as $value) {
            Assert::same($value, 'xx');
        }
    }

    public function generatesSupportedDateFormats(): void
    {
        $compiler = new SchemaArbitraryCompiler();
        foreach (['date', 'date-time'] as $format) {
            foreach (Gen::sample($compiler->compile(['type' => 'string', 'format' => $format]), count: 5, seed: 67) as $value) {
                Assert::true(is_string($value));
                Assert::true($format === 'date'
                    ? preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                    : preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1);
            }
        }
    }

    #[DataProvider('stringFormatCases')]
    public function generatesEverySupportedStringFormat(string $format, string $pattern): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => $format]), count: 10, seed: 97) as $value) {
            Assert::true(is_string($value) && preg_match($pattern, $value) === 1);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function stringFormatCases(): iterable
    {
        yield 'email' => ['email', '/^[^@]+@[^@]+\\.[^@]+$/'];
        yield 'ipv4' => ['ipv4', '/^(?:\\d{1,3}\\.){3}\\d{1,3}$/'];
        yield 'uri' => ['uri', '/^[a-z]+:\\/\\//'];
        yield 'uri-reference' => ['uri-reference', '/^.+$/'];
        yield 'url' => ['url', '/^[a-z]+:\\/\\//'];
    }

    public function honorsExclusiveNumberBoundaries(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'number',
            'minimum' => 1.0,
            'maximum' => 2.0,
            'exclusiveMinimum' => true,
            'exclusiveMaximum' => true,
        ]), count: 20, seed: 101) as $value) {
            Assert::true(is_float($value) && $value > 1.0 && $value < 2.0);
        }
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'number',
            'minimum' => 1.0,
            'maximum' => 1.2,
            'exclusiveMinimum' => true,
        ]), count: 20, seed: 109) as $value) {
            Assert::true(is_float($value) && $value > 1.0 && $value <= 1.2);
        }
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'number',
            'minimum' => 1.0,
            'maximum' => 1.2,
            'exclusiveMaximum' => true,
        ]), count: 20, seed: 113) as $value) {
            Assert::true(is_float($value) && $value >= 1.0 && $value < 1.2);
        }
    }

    public function honorsNumberBoundsAndMultiples(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'number',
            'minimum' => -4.0,
            'maximum' => 4.0,
            'multipleOf' => 2,
        ]), count: 20, seed: 71) as $value) {
            Assert::true(is_float($value) && $value >= -4.0 && $value <= 4.0);
            Assert::same(fmod($value, 2.0), 0.0);
        }
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'number',
            'minimum' => -4.0,
            'maximum' => 4.0,
            'multipleOf' => 2.5,
        ]), count: 50, seed: 107);
        foreach ($values as $value) {
            Assert::true(in_array($value, [-2.5, 0.0, 2.5], strict: true));
        }
    }

    public function honorsNonAlignedIntegerMultipleBounds(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'integer',
            'minimum' => -5,
            'maximum' => 5,
            'multipleOf' => 4,
        ]), count: 100, seed: 89);

        Assert::true(in_array(-4, $values, strict: true));
        Assert::true(in_array(0, $values, strict: true));
        Assert::true(in_array(4, $values, strict: true));
        foreach ($values as $value) {
            Assert::true(is_int($value) && $value >= -5 && $value <= 5 && $value % 4 === 0);
        }
    }

    #[DataProvider('invalidMultipleCases')]
    public function rejectsInvalidMultipleOf(array $schema): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile($schema);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidMultipleCases(): iterable
    {
        yield 'integer zero' => [['type' => 'integer', 'multipleOf' => 0]];
        yield 'integer float' => [['type' => 'integer', 'multipleOf' => 2.5]];
        yield 'number zero' => [['type' => 'number', 'multipleOf' => 0]];
        yield 'number negative' => [['type' => 'number', 'multipleOf' => -1]];
        yield 'number infinite' => [['type' => 'number', 'multipleOf' => INF]];
        yield 'number string' => [['type' => 'number', 'multipleOf' => '2']];
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

    #[DataProvider('unsupportedKeywordCases')]
    public function rejectsUnsupportedAssertionKeywords(string $keyword): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile([$keyword => []]);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedKeywordCases(): iterable
    {
        foreach (['$ref', 'allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', 'contains', 'prefixItems', 'patternProperties', 'propertyNames', 'unevaluatedProperties'] as $keyword) {
            yield $keyword => [$keyword];
        }
    }

    public function rejectsMalformedSchemaCombinatorsAndObjects(): void
    {
        $compiler = new SchemaArbitraryCompiler();
        foreach ([
            ['type' => ['string', 42]],
            ['enum' => 'invalid'],
            ['properties' => 'invalid'],
            ['required' => 'invalid'],
            ['items' => []],
        ] as $schema) {
            try {
                $compiler->compile($schema);
                Assert::true(actual: false);
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
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

    public function treatsOmittedAdditionalPropertiesAsAllowed(): void
    {
        $arbitrary = (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'minProperties' => 1,
            'maxProperties' => 1,
            'properties' => [],
        ]);

        foreach (Gen::sample($arbitrary, count: 10, seed: 29) as $value) {
            Assert::true(is_array($value) && count($value) === 1);
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

    #[DataProvider('invalidSchemaCases')]
    public function rejectsMalformedSchemaShapes(array $schema): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile($schema);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSchemaCases(): iterable
    {
        yield 'invalid enum shape' => [['enum' => 'value']];
        yield 'empty enum' => [['enum' => []]];
        yield 'unknown type' => [['type' => 'binary']];
        yield 'invalid type union' => [['type' => ['string', 42]]];
        yield 'invalid integer minimum' => [['type' => 'integer', 'minimum' => 1.5]];
        yield 'invalid integer maximum' => [['type' => 'integer', 'maximum' => '10']];
        yield 'invalid number minimum' => [['type' => 'number', 'minimum' => '0']];
        yield 'invalid string min length' => [['type' => 'string', 'minLength' => -1]];
        yield 'invalid string max length' => [['type' => 'string', 'maxLength' => 1.5]];
        yield 'invalid array items' => [['type' => 'array', 'items' => ['string']]];
        yield 'invalid array min items' => [['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => -1]];
        yield 'invalid object properties' => [['type' => 'object', 'properties' => ['id']]];
        yield 'invalid object required' => [['type' => 'object', 'required' => ['id' => true], 'properties' => []]];
        yield 'invalid additional properties' => [['type' => 'object', 'additionalProperties' => ['string']]];
    }

    #[DataProvider('emptyExclusiveSchemas')]
    public function rejectsExclusiveBoundsWithNoValue(array $schema): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile($schema);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function emptyExclusiveSchemas(): iterable
    {
        yield 'integer exclusive minimum' => [['type' => 'integer', 'minimum' => 0, 'maximum' => 0, 'exclusiveMinimum' => true]];
        yield 'integer exclusive maximum' => [['type' => 'integer', 'minimum' => 0, 'maximum' => 0, 'exclusiveMaximum' => true]];
        yield 'number exclusive minimum' => [['type' => 'number', 'minimum' => 0, 'maximum' => 0, 'exclusiveMinimum' => true]];
        yield 'number exclusive maximum' => [['type' => 'number', 'minimum' => 0, 'maximum' => 0, 'exclusiveMaximum' => true]];
    }

    public function honorsExclusiveIntegerBoundaries(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 3,
            'exclusiveMinimum' => true,
            'exclusiveMaximum' => true,
        ]), count: 10, seed: 47);

        foreach ($values as $value) {
            Assert::true(is_int($value) && $value === 2);
        }
    }

    public function honorsUniqueArrayItemsAndCardinality(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'array',
            'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
            'minItems' => 2,
            'maxItems' => 2,
            'uniqueItems' => true,
        ]), count: 20, seed: 53);

        foreach ($values as $value) {
            Assert::true(is_array($value) && count($value) === 2 && $value[0] !== $value[1]);
        }
    }

    public function enforcesObjectAdditionalPropertyFalseWhenShapeIsEmpty(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'additionalProperties' => false,
            'maxProperties' => 0,
        ]), count: 5, seed: 59);

        Assert::same($values, [[], [], [], [], []]);
    }

    public function preservesOptionalPropertiesWhenAdditionalPropertiesAreAllowed(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'maxProperties' => 3,
            'properties' => ['id' => ['type' => 'integer', 'const' => 1]],
        ]), count: 50, seed: 103);

        foreach ($values as $value) {
            Assert::true(is_array($value) && count($value) <= 3);
            foreach ($value as $key => $item) {
                Assert::true(is_string($key));
                if ($key === 'id') {
                    Assert::same($item, 1);
                }
            }
        }
    }

    #[DataProvider('malformedCombinatorCases')]
    public function rejectsMalformedCombinatorBranches(array $schema): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile($schema);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedCombinatorCases(): iterable
    {
        yield 'anyOf scalar' => [['anyOf' => 'invalid']];
        yield 'anyOf empty' => [['anyOf' => []]];
        yield 'anyOf list branch' => [['anyOf' => [['type' => 'string'], ['string']]]];
        yield 'oneOf scalar' => [['oneOf' => 'invalid']];
        yield 'oneOf empty' => [['oneOf' => []]];
        yield 'oneOf list branch' => [['oneOf' => [['type' => 'string'], ['string']]]];
        yield 'allOf scalar' => [['allOf' => 'invalid']];
        yield 'allOf empty' => [['allOf' => []]];
        yield 'allOf list branch' => [['allOf' => [['type' => 'object'], ['object']]]];
        yield 'allOf conflicting type' => [['allOf' => [['type' => 'string'], ['type' => 'integer']]]];
        yield 'allOf invalid required' => [['allOf' => [['type' => 'object', 'required' => 'id']]]];
        yield 'allOf invalid property' => [['allOf' => [['type' => 'object', 'properties' => ['id' => ['string']]]]]];
        yield 'allOf conflicting constraint' => [['allOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'string', 'minLength' => 2]]]];
    }

    #[DataProvider('combinatorAnnotationCases')]
    public function permitsCombinatorAnnotations(array $schema): void
    {
        Assert::same(Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 1, seed: 83), ['value']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function combinatorAnnotationCases(): iterable
    {
        foreach (['title', 'description', 'deprecated', 'examples', '$comment'] as $annotation) {
            yield $annotation => [[
                'anyOf' => [['const' => 'value']],
                $annotation => $annotation === 'deprecated' ? true : ($annotation === 'examples' ? ['value'] : 'note'),
            ]];
        }
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

    public function compilesNotConstWithoutGeneratingTheForbiddenValue(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 4,
            'not' => ['const' => 'blocked'],
        ]), count: 30, seed: 127);

        foreach ($values as $value) {
            Assert::true(is_string($value) && $value !== 'blocked');
        }
        Assert::true(count(array_unique($values, SORT_STRING)) > 1);
    }

    public function compilesNotEnumAndNotType(): void
    {
        $compiler = new SchemaArbitraryCompiler();
        foreach (Gen::sample($compiler->compile([
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 3,
            'not' => ['enum' => [1, 2]],
        ]), count: 20, seed: 131) as $value) {
            Assert::true(is_int($value) && !in_array($value, [1, 2], strict: true));
        }
        foreach (Gen::sample($compiler->compile(['not' => ['type' => 'string']]), count: 30, seed: 137) as $value) {
            Assert::true(!is_string($value));
        }
    }

    #[DataProvider('malformedNotSchemas')]
    public function rejectsUnsupportedNotSchemas(array $schema): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile($schema);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedNotSchemas(): iterable
    {
        yield 'not scalar' => [['not' => 'invalid']];
        yield 'not empty' => [['not' => []]];
        yield 'not pattern' => [['not' => ['type' => 'string', 'pattern' => '^x$']]];
        yield 'not excludes const' => [['const' => 'x', 'not' => ['const' => 'x']]];
        yield 'not excludes enum' => [['enum' => ['x', 'y'], 'not' => ['enum' => ['x', 'y']]]];
    }

    public function reportsExactMessagesForUnsupportedSchemas(): void
    {
        $compiler = new SchemaArbitraryCompiler();
        foreach ([
            [['type' => 'string', 'not' => []], 'not cannot combine const and enum'],
            [['type' => 'string', 'not' => ['x']], 'not must be a schema object'],
            [['$ref' => []], 'keyword "$ref" is outside the initial support matrix'],
            [['type' => 'object', 'properties' => ['id']], 'object properties must be an object'],
            [['type' => ['string', 42]], 'a type, properties, or items declaration is required'],
            [['type' => 'string', 'format' => 42], 'format must be a string'],
            [['allOf' => [['type' => 'string'], ['type' => 'integer']]], 'allOf branches have conflicting types'],
            [['allOf' => [['type' => 'object', 'properties' => ['a' => ['x']]]]], 'allOf properties must contain schema objects'],
        ] as [$schema, $message]) {
            try {
                $compiler->compile($schema);
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration $exception) {
                Assert::same($exception->getMessage(), 'Unsupported OpenAPI schema generation: ' . $message);
            }
        }
    }

    public function rejectsMalformedNotShapes(): void
    {
        $compiler = new SchemaArbitraryCompiler();
        foreach ([
            ['type' => 'string', 'not' => ['enum' => 'x']],
            ['type' => 'string', 'not' => ['enum' => []]],
            ['type' => 'string', 'not' => ['type' => 42]],
            ['type' => 'string', 'not' => ['type' => 'weird']],
        ] as $schema) {
            try {
                $compiler->compile($schema);
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    public function keepsPartiallyExcludedEnums(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'enum' => ['x', 'y'],
            'not' => ['enum' => ['x']],
        ]), count: 20, seed: 41);

        Assert::same(array_values(array_unique($values)), ['y']);
    }

    public function excludesIntegersFromNotNumberPredicates(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'enum' => [1, 'a'],
            'not' => ['type' => 'number'],
        ]), count: 20, seed: 43);

        Assert::same(array_values(array_unique($values)), ['a']);
    }

    public function distinguishesValueTypesInNotPredicates(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        $floats = Gen::sample($compiler->compile(['enum' => [1.5, 'a'], 'not' => ['type' => 'boolean']]), count: 20, seed: 47);
        Assert::true(in_array(1.5, $floats, strict: true) && in_array('a', $floats, strict: true));

        $lists = Gen::sample($compiler->compile(['enum' => [[1]], 'not' => ['type' => 'object']]), count: 5, seed: 53);
        Assert::same($lists, [[1], [1], [1], [1], [1]]);

        $objects = Gen::sample($compiler->compile(['enum' => [['a' => 1]], 'not' => ['type' => 'array']]), count: 5, seed: 59);
        Assert::same($objects, [['a' => 1], ['a' => 1], ['a' => 1], ['a' => 1], ['a' => 1]]);
    }

    public function acceptsEnumsWithSparseKeys(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['enum' => [3 => 'small', 7 => 'large']]), count: 10, seed: 61) as $value) {
            Assert::true(in_array($value, ['small', 'large'], strict: true));
        }
    }

    public function mergesAllOfBranchesWithoutLosingConstraints(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        foreach (Gen::sample($compiler->compile([
            'allOf' => [['type' => 'integer', 'minimum' => 5], ['maximum' => 6]],
        ]), count: 20, seed: 67) as $value) {
            Assert::true(is_int($value) && $value >= 5 && $value <= 6);
        }

        foreach (Gen::sample($compiler->compile([
            'allOf' => [['required' => ['a'], 'minProperties' => 2, 'type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]],
        ]), count: 20, seed: 71) as $value) {
            Assert::true(is_array($value) && array_key_exists('a', $value) && count($value) >= 2);
        }

        foreach (Gen::sample($compiler->compile([
            'allOf' => [
                ['type' => 'object', 'properties' => ['a' => ['const' => 1]], 'required' => ['a']],
                ['required' => ['a'], 'properties' => ['a' => []]],
            ],
        ]), count: 10, seed: 73) as $value) {
            Assert::true(is_array($value) && $value['a'] === 1);
        }
    }

    public function acceptsEmptyPropertyMapsInsideAllOf(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'allOf' => [['type' => 'object', 'properties' => [], 'maxProperties' => 0]],
        ]), count: 5, seed: 79);

        Assert::same($values, [[], [], [], [], []]);
    }

    public function rejectsOneOfBranchesWithoutTypes(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['oneOf' => [['const' => 'x'], ['type' => 'integer']]]);
    }

    public function generatesEmptyStringsForZeroLengthBudgets(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        Assert::same(Gen::sample($compiler->compile(['type' => 'string', 'maxLength' => 0]), count: 3, seed: 83), ['', '', '']);
        Assert::same(Gen::sample($compiler->compile(['type' => 'string', 'format' => 'uuid', 'maxLength' => 0]), count: 3, seed: 89), ['', '', '']);
    }

    public function patternStringsHonorTheLengthFilter(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'string',
            'pattern' => '^x*$',
            'minLength' => 1,
        ]), count: 60, seed: 97) as $value) {
            Assert::true(is_string($value) && $value !== '');
        }

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'string',
            'format' => 'uuid',
            'pattern' => '^x*$',
            'minLength' => 1,
        ]), count: 60, seed: 113) as $value) {
            Assert::true(is_string($value) && $value !== '');
        }
    }

    public function integerDefaultsCoverTheFullBoundedDomain(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'integer']), count: 60, seed: 11);

        Assert::same(min($values), -1000);
        Assert::same(max($values), 1000);
    }

    public function integerMultiplesRoundInwardAtTheBoundaries(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'integer',
            'minimum' => 5,
            'maximum' => 11,
            'multipleOf' => 4,
        ]), count: 20, seed: 7);

        Assert::same(array_values(array_unique($values)), [8]);
    }

    public function numberBoundsSupportSingleValueWindows(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        Assert::same(Gen::sample($compiler->compile(['type' => 'number', 'minimum' => 2.5, 'maximum' => 2.5]), count: 3, seed: 3), [2.5, 2.5, 2.5]);
        Assert::same(Gen::sample($compiler->compile(['type' => 'number', 'minimum' => 2.5, 'maximum' => 2.5, 'multipleOf' => 2.5]), count: 3, seed: 5), [2.5, 2.5, 2.5]);
    }

    public function arraysMayBeEmptyByDefaultAndRepeatWithoutUniqueItems(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        $values = Gen::sample($compiler->compile(['type' => 'array', 'items' => ['const' => 1], 'maxItems' => 2]), count: 30, seed: 5);
        Assert::true(in_array([], $values, strict: true));

        $repeated = Gen::sample($compiler->compile([
            'type' => 'array',
            'items' => ['enum' => [1, 2]],
            'minItems' => 3,
            'maxItems' => 3,
        ]), count: 10, seed: 101);
        foreach ($repeated as $list) {
            Assert::true(is_array($list) && count($list) === 3);
        }
    }

    public function rejectsScalarRequiredDeclarations(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'object', 'required' => 'id', 'properties' => []]);
    }

    public function declaredPropertiesStayClosedWithoutMinProperties(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'properties' => ['a' => ['const' => 1]],
        ]), count: 40, seed: 3) as $value) {
            Assert::true(is_array($value) && array_diff(array_keys($value), ['a']) === []);
        }
    }

    public function additionalPropertiesFillMissingCardinality(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        foreach (Gen::sample($compiler->compile([
            'type' => 'object',
            'properties' => ['a' => ['const' => 1]],
            'minProperties' => 2,
        ]), count: 20, seed: 103) as $value) {
            Assert::true(is_array($value) && count($value) >= 2);
        }

        foreach (Gen::sample($compiler->compile([
            'type' => 'object',
            'properties' => ['a' => ['const' => 1], 'b' => ['const' => 2]],
            'required' => ['a', 'b'],
            'minProperties' => 1,
            'maxProperties' => 2,
        ]), count: 20, seed: 107) as $value) {
            Assert::same($value, ['a' => 1, 'b' => 2]);
        }
    }

    public function generatedExtrasStayWithinTheDocumentedDomain(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'minProperties' => 1,
            'maxProperties' => 3,
        ]), count: 120, seed: 13);

        $seenTypes = [];
        $seen = ['empty-string' => false, 'key-1' => false, 'key-8' => false, 'int-min' => false, 'int-max' => false, 'string-8' => false];
        foreach ($values as $object) {
            Assert::true(is_array($object) && count($object) >= 1 && count($object) <= 3);
            foreach ($object as $key => $item) {
                Assert::true(is_string($key) && strlen($key) >= 1 && strlen($key) <= 8);
                Assert::true($item === null || is_bool($item) || is_string($item) || is_int($item));
                $seen['key-1'] = $seen['key-1'] || strlen($key) === 1;
                $seen['key-8'] = $seen['key-8'] || strlen($key) === 8;
                if (is_string($item)) {
                    Assert::true(mb_strlen($item) <= 8);
                    $seen['empty-string'] = $seen['empty-string'] || $item === '';
                    $seen['string-8'] = $seen['string-8'] || mb_strlen($item) === 8;
                }
                if (is_int($item)) {
                    Assert::true($item >= -1000 && $item <= 1000);
                    $seen['int-min'] = $seen['int-min'] || $item === -1000;
                    $seen['int-max'] = $seen['int-max'] || $item === 1000;
                }
                $seenTypes[get_debug_type($item)] = true;
            }
        }
        Assert::same($seen, ['empty-string' => true, 'key-1' => true, 'key-8' => true, 'int-min' => true, 'int-max' => true, 'string-8' => true]);
        Assert::same(count($seenTypes), 4);
    }

    public function extrasStayOptionalWhenRequiredPropertiesSatisfyMinProperties(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'properties' => ['a' => ['const' => 1], 'b' => ['const' => 2]],
            'required' => ['a', 'b'],
            'minProperties' => 1,
        ]), count: 30, seed: 17);

        $bare = 0;
        foreach ($values as $object) {
            Assert::true(is_array($object) && count($object) <= 16);
            $bare += array_keys($object) === ['a', 'b'] ? 1 : 0;
        }
        Assert::true($bare > 0);
    }

    public function formatFiltersEnforceTheLengthBudget(): void
    {
        Expect::exception(GenerationExhausted::class);

        Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => 'uuid', 'maxLength' => 10]), count: 3, seed: 7);
    }

    public function reportsExactMessagesForMalformedPropertyValues(): void
    {
        try {
            (new SchemaArbitraryCompiler())->compile(['type' => 'object', 'properties' => ['a' => ['x']]]);
            Assert::true(actual: false, message: 'Expected unsupported generation exception');
        } catch (UnsupportedGeneration $exception) {
            Assert::same($exception->getMessage(), 'Unsupported OpenAPI schema generation: object properties must contain named schema objects');
        }

        try {
            (new SchemaArbitraryCompiler())->compile(['type' => 'integer', 'not' => ['type' => ['string', 42]]]);
            Assert::true(actual: false, message: 'Expected unsupported generation exception');
        } catch (UnsupportedGeneration $exception) {
            Assert::same($exception->getMessage(), 'Unsupported OpenAPI schema generation: not type must be a string or list of strings');
        }
    }

    public function rejectsNumericExclusiveMaximum(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'integer', 'exclusiveMaximum' => 5]);
    }

    public function checksAdditionalPropertiesShapeOnEveryType(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'additionalProperties' => ['x']]);
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
