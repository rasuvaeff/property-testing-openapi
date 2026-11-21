<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal\Compile;

use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDialect;
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
    /**
     * `oneOf` over objects told apart by a required property pinned with a
     * `const` or an `enum` (#167): every value is accepted by exactly one
     * branch, and every branch is drawn.
     */
    #[DataProvider('taggedOneOfProvider')]
    public function aTaggedOneOfDrawsValuesExactlyOneBranchAccepts(array $schema, string $tag, int $branches): void
    {
        $check = new SchemaCheck();
        /** @var list<array<string, mixed>> $members */
        $members = $schema['oneOf'];
        $defs = $schema['$defs'] ?? [];
        $seen = [];
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 120, seed: 9) as $value) {
            Assert::true(is_array($value) && array_key_exists($tag, $value), json_encode($value, JSON_THROW_ON_ERROR));
            $json = json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), flags: JSON_THROW_ON_ERROR);
            $accepting = array_keys(array_filter($members, static fn(array $member): bool => $check->accepts($json, $defs === [] ? $member : $member + ['$defs' => $defs], SchemaDialect::OpenApi31)));

            Assert::same(count($accepting), 1, json_encode($value, JSON_THROW_ON_ERROR));
            Assert::true($check->accepts($json, $schema, SchemaDialect::OpenApi31));
            $seen[$accepting[0]] = true;
        }

        Assert::same(count($seen), $branches);
    }

    public static function taggedOneOfProvider(): iterable
    {
        $cat = ['type' => 'object', 'required' => ['kind', 'lives'], 'properties' => ['kind' => ['type' => 'string', 'const' => 'cat'], 'lives' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9]]];
        $dog = ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'const' => 'dog'], 'good' => ['type' => 'boolean']]];
        yield 'two consts' => [['oneOf' => [$cat, $dog]], 'kind', 2];
        yield 'enums that do not meet' => [['oneOf' => [
            ['type' => 'object', 'required' => ['t'], 'properties' => ['t' => ['enum' => ['a', 'b']]]],
            ['type' => 'object', 'required' => ['t'], 'properties' => ['t' => ['enum' => ['c', 1]]]],
            ['type' => 'object', 'required' => ['t'], 'properties' => ['t' => ['const' => true]]],
        ]], 't', 3];
        yield 'a tag that is not the first pinned property' => [['oneOf' => [
            ['type' => 'object', 'required' => ['v', 't'], 'properties' => ['v' => ['const' => 1], 't' => ['const' => 'x']]],
            ['type' => 'object', 'required' => ['v', 't'], 'properties' => ['v' => ['const' => 1], 't' => ['const' => 'y']]],
        ]], 't', 2];
        yield 'references into $defs' => [['$defs' => ['Cat' => $cat, 'Dog' => $dog], 'oneOf' => [['$ref' => '#/$defs/Cat'], ['$ref' => '#/$defs/Dog']]], 'kind', 2];
    }

    #[DataProvider('untaggedOneOfProvider')]
    public function aOneOfWithoutAProvableTagIsRefused(array $schema): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation: oneOf branches must be provably disjoint');

        (new SchemaArbitraryCompiler())->compile($schema);
    }

    public static function untaggedOneOfProvider(): iterable
    {
        $object = static fn(array $tag, bool $required = true): array => ['type' => 'object', 'required' => $required ? ['t'] : [], 'properties' => ['t' => $tag]];
        yield 'the same const twice' => [['oneOf' => [$object(['const' => 'a']), $object(['const' => 'a'])]]];
        yield 'enums that meet' => [['oneOf' => [$object(['enum' => ['a', 'b']]), $object(['enum' => ['b', 'c']])]]];
        yield 'one and one point zero are one number' => [['oneOf' => [$object(['const' => 1]), $object(['const' => 1.0])]]];
        yield 'a tag one branch does not require' => [['oneOf' => [$object(['const' => 'a']), $object(['const' => 'b'], required: false)]]];
        yield 'a tag one branch does not pin' => [['oneOf' => [$object(['const' => 'a']), $object(['type' => 'string'])]]];
        yield 'an object tag' => [['oneOf' => [$object(['const' => ['a' => 1, 'b' => 2]]), $object(['const' => ['b' => 2, 'a' => 1]])]]];
        yield 'an empty enum' => [['oneOf' => [$object(['enum' => []]), $object(['const' => 'b'])]]];
        yield 'a branch that is not an object' => [['oneOf' => [['type' => 'object', 'required' => ['t'], 'properties' => ['t' => ['const' => 'a']]], ['required' => ['t'], 'properties' => 'x']]]];
        yield 'a tag only the later branches share' => [['oneOf' => [$object(['const' => 'a']), ['type' => 'object', 'required' => ['u'], 'properties' => ['u' => ['const' => 'b']]]]]];
    }

    public function anUnevaluatedPropertiesSiblingClosesTheConjunction(): void
    {
        $schema = ['allOf' => [['type' => 'object']], 'unevaluatedProperties' => false];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 30, seed: 1) as $value) {
            Assert::same($value, []);
        }
    }

    /**
     * An assertion beside `allOf` is one more member of its conjunction, as
     * the contract reads it (#153).
     */
    public function aSiblingAfterAnUnevaluatedKeywordJoinsTheConjunction(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['allOf' => [['type' => 'integer', 'maximum' => 3]], 'unevaluatedItems' => false, 'minimum' => 1]), count: 30, seed: 1) as $value) {
            Assert::true(is_int($value) && $value >= 1 && $value <= 3);
        }
    }

    /**
     * A sibling `allOf` meets in another combinator is still refused: `anyOf`
     * and `oneOf` are not conjunctions.
     */
    public function aSiblingOfAUnionIsStillRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: anyOf with sibling keyword "minimum" is outside the supported subset');
        (new SchemaArbitraryCompiler())->compile(['anyOf' => [['type' => 'integer']], 'minimum' => 1, 'description' => 'a', 'x-note' => 'b']);
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

    /**
     * Annotations are carried through the conjunction, not merged as
     * constraints: two components with their own `description` compose
     * (#153).
     */
    public function branchesThatDifferOnlyInAnnotationsMerge(): void
    {
        $schema = ['allOf' => [
            ['type' => 'object', 'description' => 'A', 'title' => 'a', 'x-owner' => 'one', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3]]],
            ['type' => 'object', 'description' => 'B', 'title' => 'b', 'x-owner' => 'two', 'example' => ['b' => 'x'], 'properties' => ['b' => ['type' => 'string', 'maxLength' => 2]]],
        ]];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 30, seed: 1) as $value) {
            Assert::true(is_array($value) && is_int($value['a'] ?? null));
        }
    }

    /**
     * The same bound from two branches is met by the tighter one (#153).
     *
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('repeatedBoundProvider')]
    public function aBoundRepeatedAcrossBranchesIsTheTighterOne(array $first, array $second, \Closure $holds): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['allOf' => [$first, $second]]), count: 60, seed: 2) as $value) {
            Assert::true($holds($value), json_encode($value, JSON_THROW_ON_ERROR));
        }
    }

    public static function repeatedBoundProvider(): iterable
    {
        yield 'minimum' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 9], ['minimum' => 5], static fn(mixed $v): bool => is_int($v) && $v >= 5 && $v <= 9];
        yield 'maximum' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 9], ['maximum' => 3], static fn(mixed $v): bool => is_int($v) && $v >= 1 && $v <= 3];
        yield 'maximum, the looser one second' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 3], ['maximum' => 9], static fn(mixed $v): bool => is_int($v) && $v <= 3];
        yield 'minLength and maxLength' => [['type' => 'string', 'minLength' => 1, 'maxLength' => 6], ['minLength' => 3, 'maxLength' => 4], static fn(mixed $v): bool => is_string($v) && mb_strlen($v) >= 3 && mb_strlen($v) <= 4];
        yield 'minItems and maxItems' => [['type' => 'array', 'items' => ['type' => 'boolean'], 'minItems' => 1, 'maxItems' => 5], ['minItems' => 2, 'maxItems' => 3], static fn(mixed $v): bool => is_array($v) && count($v) >= 2 && count($v) <= 3];
        yield 'minProperties and maxProperties' => [['type' => 'object', 'minProperties' => 1, 'maxProperties' => 4], ['minProperties' => 2, 'maxProperties' => 2], static fn(mixed $v): bool => is_array($v) && count($v) === 2];
    }

    /**
     * @param array<string, mixed> $second
     */
    #[DataProvider('unmergeableBoundProvider')]
    public function aBoundThatCannotBeIntersectedIsStillRefused(array $second, string $keyword): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage(sprintf('Unsupported OpenAPI schema generation: allOf constraint "%s" cannot be merged safely', $keyword));
        (new SchemaArbitraryCompiler())->compile(['allOf' => [['type' => 'integer', 'minimum' => 1, 'multipleOf' => 2], $second]]);
    }

    public static function unmergeableBoundProvider(): iterable
    {
        yield 'a keyword that is no bound' => [['multipleOf' => 3], 'multipleOf'];
        yield 'a bound that is not a number' => [['minimum' => '5'], 'minimum'];
        yield 'beside an exclusive bound in the later branch' => [['minimum' => 5, 'exclusiveMinimum' => true], 'minimum'];
        yield 'beside an exclusive upper bound in the later branch' => [['minimum' => 5, 'exclusiveMaximum' => true], 'minimum'];
    }

    public function aBoundBesideAnExclusiveOneInTheEarlierBranchIsStillRefused(): void
    {
        foreach (['exclusiveMinimum', 'exclusiveMaximum'] as $exclusive) {
            try {
                (new SchemaArbitraryCompiler())->compile(['allOf' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 9, $exclusive => true], ['minimum' => 5]]]);
                Assert::true(actual: false, message: $exclusive . ' was merged');
            } catch (UnsupportedGeneration $refusal) {
                Assert::same($refusal->getMessage(), 'Unsupported OpenAPI schema generation: allOf constraint "minimum" cannot be merged safely');
            }
        }
    }

    /**
     * The contract compiles a 3.1 `$ref` with an asserting sibling into
     * `allOf` plus the target's hoisted `type` and `description`; that shape
     * generates, and the sibling assertion holds (#153).
     */
    public function theContractsFormOfAReferenceWithSiblingsGenerates(): void
    {
        $schema = ['allOf' => [['type' => 'string', 'description' => 'a code', 'minLength' => 1], ['maxLength' => 4]], 'type' => 'string', 'description' => 'a code'];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 40, seed: 3) as $value) {
            Assert::true(is_string($value) && mb_strlen($value) >= 1 && mb_strlen($value) <= 4);
        }
    }

    /**
     * OAS 3.0 `nullable` beside an `allOf` of one reference: the sibling
     * joins the conjunction instead of refusing it (#153).
     */
    public function aNullableSiblingOfAnAllOfIsAccepted(): void
    {
        $schema = ['nullable' => true, 'allOf' => [['type' => 'object', 'description' => 'owner', 'required' => ['n'], 'properties' => ['n' => ['type' => 'integer']]]]];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 3) as $value) {
            Assert::true(is_array($value) && is_int($value['n'] ?? null));
        }
    }

    public function anAnnotationOrExtensionBesideAUnionIsAccepted(): void
    {
        $schema = ['anyOf' => [['const' => 'v']], 'example' => 'v', 'default' => 'v', 'discriminator' => ['propertyName' => 'k'], 'x-note' => 1, 'externalDocs' => ['url' => 'https://example.com'], 'xml' => ['name' => 'v']];

        Assert::same(Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 3, seed: 1), ['v', 'v', 'v']);
    }

    public function aNumberBranchMayCarryAnyAnnotation(): void
    {
        $schema = ['oneOf' => [['type' => 'integer', 'minimum' => 0, 'maximum' => 9], ['type' => 'number', 'minimum' => 0, 'maximum' => 9, 'x-unit' => 'kg', 'default' => 1.5]]];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 1) as $value) {
            Assert::true(is_int($value) || is_float($value));
        }
    }
}
