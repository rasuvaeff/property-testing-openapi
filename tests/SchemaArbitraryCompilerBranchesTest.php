<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * The conditional and dependent-keyword branches of the compiler: which
 * branches are built, how often each is chosen, and what they are refused for.
 */
#[Test]
#[Covers(SchemaArbitraryCompiler::class)]
final class SchemaArbitraryCompilerBranchesTest
{
    public function aThenWithoutAnIfLeavesTheSchemaUnchanged(): void
    {
        $compiler = new SchemaArbitraryCompiler();

        Assert::same(count(Gen::sample($compiler->compile(['then' => ['type' => 'string']]), count: 5, seed: 1)), 5);
        foreach (Gen::sample($compiler->compile(['type' => 'integer', 'minimum' => 0, 'maximum' => 3, 'then' => ['type' => 'string']]), count: 20, seed: 1) as $value) {
            Assert::true(is_int($value) && $value >= 0 && $value <= 3);
        }
    }

    public function aNullableEnumThatIsNotAListIsRefusedByTheEnumRule(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: enum must be a non-empty list');
        (new SchemaArbitraryCompiler())->compile(['nullable' => true, 'enum' => 'x']);
    }

    public function bothConditionalSidesAreEquallyLikelyAndKeepTheBase(): void
    {
        $schema = [
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 9,
            'if' => ['multipleOf' => 2],
            'then' => [],
            'else' => ['multipleOf' => 3],
        ];

        $values = Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 1000, seed: 5);
        $then = 0;
        foreach ($values as $value) {
            Assert::true(is_int($value) && $value >= 0 && $value <= 9);
            if ($value % 2 === 0) {
                ++$then;
            } else {
                Assert::same($value % 3, 0);
            }
        }
        Assert::true($then > 420 && $then < 580, sprintf('then side drawn %d times in 1000', $then));
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('conditionShapes')]
    public function anEmptyConditionIsASchemaAndAListIsNot(array $schema, ?string $refusal): void
    {
        if ($refusal !== null) {
            Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation: ' . $refusal);
        }

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 10, seed: 1) as $value) {
            Assert::true(is_int($value) && $value >= 0 && $value <= 3);
        }
    }

    public static function conditionShapes(): iterable
    {
        yield 'empty if' => [['type' => 'integer', 'if' => [], 'then' => ['minimum' => 0, 'maximum' => 3]], null];
        yield 'list if' => [['type' => 'integer', 'if' => [1], 'then' => ['minimum' => 0, 'maximum' => 3]], 'if must be a schema object'];
    }

    public function theConditionIsJudgedInTheCompilersDirection(): void
    {
        // A readOnly property is required of a response and not of a request:
        // the same empty object meets the condition one way and not the other.
        $schema = [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'integer']],
            'additionalProperties' => false,
            'if' => ['required' => ['p'], 'properties' => ['p' => ['readOnly' => true]]],
            'else' => false,
        ];

        Assert::same(count(Gen::sample((new SchemaArbitraryCompiler('', SchemaDirection::Request))->compile($schema), count: 3, seed: 1)), 3);

        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: if/then/else admits no producible value');
        (new SchemaArbitraryCompiler('', SchemaDirection::Response))->compile($schema);
    }

    public function aConditionTheValidatorCannotEvaluateIsRefused(): void
    {
        try {
            (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'if' => ['pattern' => '(']]);
        } catch (UnsupportedGeneration $exception) {
            Assert::true(str_starts_with($exception->getMessage(), 'Unsupported OpenAPI schema generation: if condition cannot be evaluated: '));

            return;
        }

        Assert::true(actual: false, message: 'the schema was compiled');
    }

    public function aTriggerNamedByBothKeywordsCountsOnce(): void
    {
        $names = ['a', 'b', 'c', 'd', 'e'];
        $schema = [
            'type' => 'object',
            'properties' => array_fill_keys([...$names, 'x'], ['type' => 'integer', 'minimum' => 0, 'maximum' => 1]),
            'dependentRequired' => array_fill_keys($names, ['x']),
            'dependentSchemas' => array_fill_keys($names, ['required' => ['x']]),
        ];

        $seen = [];
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 200, seed: 2) as $value) {
            Assert::true(is_array($value));
            foreach (array_keys($value) as $name) {
                $seen[$name] = true;
            }
        }
        ksort($seen);
        Assert::same(array_keys($seen), [...$names, 'x']);
    }

    public function aTriggerAfterADuplicateIsStillReachable(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => array_fill_keys(['a', 'b', 'c', 'x'], ['type' => 'integer', 'minimum' => 0, 'maximum' => 1]),
            'dependentRequired' => ['a' => ['x'], 'b' => ['x']],
            'dependentSchemas' => ['a' => ['required' => ['x']], 'c' => ['required' => ['x']]],
        ];

        $withC = 0;
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 200, seed: 2) as $value) {
            Assert::true(is_array($value));
            if (array_key_exists('c', $value)) {
                Assert::true(array_key_exists('x', $value));
                ++$withC;
            }
        }
        Assert::true($withC > 0);
    }

    public function eightTriggersAreWithinTheBudget(): void
    {
        $names = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
        $schema = [
            'type' => 'object',
            'properties' => array_fill_keys($names, ['type' => 'boolean']),
            'dependentRequired' => array_fill_keys($names, []),
        ];

        Assert::same(count(Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 3, seed: 1)), 3);
    }

    public function everyPresenceCombinationIsEquallyLikely(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'boolean'], 'b' => ['type' => 'boolean']],
            'required' => ['b'],
            'dependentRequired' => ['a' => ['b']],
        ];

        $present = 0;
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 1000, seed: 9) as $value) {
            Assert::true(is_array($value) && array_key_exists('b', $value));
            if (array_key_exists('a', $value)) {
                ++$present;
            }
        }
        Assert::true($present > 420 && $present < 580, sprintf('trigger present %d times in 1000', $present));
    }

    public function aPlainPropertyUnderADirectionKeepsItsDependencies(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']],
            'dependentRequired' => ['a' => ['b']],
        ];

        $values = Gen::sample((new SchemaArbitraryCompiler('', SchemaDirection::Request))->compile($schema), count: 50, seed: 1);
        foreach ($values as $value) {
            Assert::true(is_array($value));
            Assert::true(!array_key_exists('a', $value) || array_key_exists('b', $value));
        }
    }

    public function aDependentObjectCarryingAnUnsupportedAssertionIsRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: dependent keywords admit no constructible presence combination');
        (new SchemaArbitraryCompiler())->compile([
            'type' => 'object',
            'properties' => ['a' => ['type' => 'integer']],
            'dependentRequired' => ['a' => []],
            'exclusiveMinimum' => 3,
        ]);
    }

    public function aRecursiveLeafDropsTheCombinationThatWouldNeedMoreOfIt(): void
    {
        $schema = [
            '$defs' => ['Node' => [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'integer'], 'child' => ['$ref' => '#/$defs/Node']],
                'dependentRequired' => ['a' => ['child']],
            ]],
            '$ref' => '#/$defs/Node',
        ];

        $check = static function (mixed $node) use (&$check): void {
            Assert::true(is_array($node));
            if (array_key_exists('a', $node)) {
                Assert::true(array_key_exists('child', $node));
            }
            if (array_key_exists('child', $node)) {
                $check($node['child']);
            }
        };
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 50, seed: 4) as $value) {
            $check($value);
        }
    }
}
