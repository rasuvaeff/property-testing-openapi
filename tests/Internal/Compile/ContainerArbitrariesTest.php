<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal\Compile;

use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ContainerArbitraries::class)]
final class ContainerArbitrariesTest
{
    public function aPrefixWithoutItemsLeavesTheTailOpen(): void
    {
        $longest = 0;
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'array', 'prefixItems' => [['type' => 'integer']], 'maxItems' => 4]), count: 50, seed: 1) as $value) {
            Assert::true(is_array($value) && count($value) <= 4);
            if ($value !== []) {
                Assert::true(is_int($value[0]));
            }
            $longest = max($longest, count($value));
        }
        Assert::true($longest > 1);
    }

    public function containsDemandsOneWitnessByDefault(): void
    {
        $lengths = [];
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'array', 'contains' => ['type' => 'integer'], 'maxItems' => 3]), count: 60, seed: 1) as $value) {
            Assert::true(is_array($value));
            $lengths[count($value)] = true;
        }
        ksort($lengths);
        Assert::same(array_keys($lengths), [1, 2, 3]);
    }

    public function prefixMembersPastMaxItemsAreNeverCompiled(): void
    {
        $schema = ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'unknown'], 5], 'maxItems' => 1];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 1) as $value) {
            Assert::true(is_array($value) && count($value) <= 1);
        }
    }

    public function aFalsePrefixMemberClosesTheTupleBeforeTheRest(): void
    {
        $schema = ['type' => 'array', 'prefixItems' => [['type' => 'integer'], false, 5]];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 1) as $value) {
            Assert::true(is_array($value) && count($value) <= 1);
        }
    }

    public function containsIsConjoinedWithItems(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5], 'contains' => ['type' => 'integer', 'multipleOf' => 2], 'maxItems' => 3];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 40, seed: 1) as $value) {
            Assert::true(is_array($value) && $value !== []);
            foreach ($value as $item) {
                Assert::true(is_int($item) && $item >= 0 && $item <= 5 && $item % 2 === 0);
            }
        }
    }

    public function aTrueContainsKeepsTheItemsSchema(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5], 'contains' => true, 'maxItems' => 3];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 40, seed: 1) as $value) {
            Assert::true(is_array($value) && $value !== []);
            foreach ($value as $item) {
                Assert::true(is_int($item) && $item >= 0 && $item <= 5);
            }
        }
    }

    public function aPropertyWithoutADirectionFlagIsSentInEitherDirection(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a']];

        foreach ([SchemaDirection::Request, SchemaDirection::Response] as $direction) {
            foreach (Gen::sample((new SchemaArbitraryCompiler('', $direction))->compile($schema), count: 10, seed: 1) as $value) {
                Assert::true(is_array($value) && is_int($value['a'] ?? null));
            }
        }
    }

    public function unevaluatedPropertiesFalseClosesAnObjectWithoutAdditionalProperties(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'object', 'unevaluatedProperties' => false]), count: 30, seed: 1) as $value) {
            Assert::same($value, []);
        }
    }

    public function aPatternMemberNeverReplacesADeclaredOne(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 0]],
            'required' => ['a'],
            'patternProperties' => ['^[ab]$' => ['type' => 'integer']],
            'minProperties' => 2,
            'maxProperties' => 2,
        ];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 30, seed: 1) as $value) {
            Assert::true(is_array($value));
            Assert::same(count($value), 2);
            Assert::same($value['a'], 0);
            Assert::true(is_int($value['b'] ?? null));
        }
    }

    public function propertyNamesBoundPatternGeneratedNames(): void
    {
        $schema = [
            'type' => 'object',
            'patternProperties' => ['^[a-z]$' => ['type' => 'integer']],
            'propertyNames' => ['pattern' => '^[a-c]$'],
            'minProperties' => 1,
            'maxProperties' => 2,
        ];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 30, seed: 1) as $value) {
            Assert::true(is_array($value) && $value !== []);
            foreach (array_keys($value) as $name) {
                Assert::true(in_array($name, ['a', 'b', 'c'], strict: true), sprintf('name "%s"', $name));
            }
        }
    }

    public function propertyNamesBoundFreelyGeneratedNames(): void
    {
        $schema = ['type' => 'object', 'propertyNames' => ['pattern' => '^[a-m]'], 'minProperties' => 1, 'maxProperties' => 2];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 30, seed: 1) as $value) {
            Assert::true(is_array($value) && $value !== []);
            foreach (array_keys($value) as $name) {
                Assert::same(preg_match('/^[a-m]/', (string) $name), 1, sprintf('name "%s"', $name));
            }
        }
    }

    public function propertyNamesWithoutAPatternAdmitEveryDeclaredName(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a'], 'propertyNames' => []];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 5, seed: 1) as $value) {
            Assert::true(is_array($value) && is_int($value['a'] ?? null));
        }
    }

    public function aPropertyNamesPatternThatCannotBeEvaluatedIsRefused(): void
    {
        // Catastrophic backtracking: preg_match() gives up and returns false.
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: propertyNames pattern is not supported');
        (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'properties' => [str_repeat('a', 40) . 'b' => ['type' => 'integer']],
            'propertyNames' => ['pattern' => '^(a+)+$'],
        ]);
    }

    public function anUnsupportedPatternPropertiesKeyIsRefusedWithItsReason(): void
    {
        try {
            (new SchemaArbitraryCompiler())->compile(['type' => 'object', 'patternProperties' => ['(?=a)' => ['type' => 'integer']]]);
        } catch (UnsupportedGeneration $exception) {
            Assert::true(str_starts_with($exception->getMessage(), 'Unsupported OpenAPI schema generation: patternProperties key is not supported: '));

            return;
        }

        Assert::true(actual: false, message: 'the schema was compiled');
    }

    public function anUnconstrainedValueKeepsItsTypeMix(): void
    {
        $kinds = ['string' => 0, 'integer' => 0, 'boolean' => 0, 'null' => 0];
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile([]), count: 2800, seed: 8) as $value) {
            ++$kinds[get_debug_type($value) === 'int' ? 'integer' : (get_debug_type($value) === 'bool' ? 'boolean' : get_debug_type($value))];
        }
        // Weights 3 : 2 : 1 : 1.
        foreach (['string' => 1200, 'integer' => 800, 'boolean' => 400, 'null' => 400] as $kind => $expected) {
            Assert::true(abs($kinds[$kind] - $expected) < 110, sprintf('%s drawn %d times in 2800', $kind, $kinds[$kind]));
        }
    }
}
