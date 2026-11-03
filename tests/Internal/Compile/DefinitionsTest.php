<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal\Compile;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\Definitions;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Definitions::class)]
final class DefinitionsTest
{
    public function aListDefIsRefusedEvenWhenNothingRefersToIt(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: $defs member "N" must be a schema object');
        (new SchemaArbitraryCompiler())->compile(['$defs' => ['N' => [1]], 'type' => 'integer']);
    }

    public function theDefsGoOutOfScopeWithTheirRoot(): void
    {
        $compiler = new SchemaArbitraryCompiler();
        $compiler->compile(['$defs' => ['D' => ['type' => 'integer']], '$ref' => '#/$defs/D']);

        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: $ref "#/$defs/D" names no $defs member');
        $compiler->compile(['$ref' => '#/$defs/D']);
    }

    public function aDefReferencedTwiceCompilesTheSameBothTimes(): void
    {
        $schema = [
            '$defs' => ['D' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3]],
            'type' => 'object',
            'properties' => ['a' => ['$ref' => '#/$defs/D'], 'b' => ['$ref' => '#/$defs/D']],
            'required' => ['a', 'b'],
        ];
        $compiler = new SchemaArbitraryCompiler();

        $first = Gen::sample($compiler->compile($schema), count: 20, seed: 1);
        $second = Gen::sample($compiler->compile($schema), count: 20, seed: 1);

        Assert::same($second, $first);
        Assert::same($first, Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 1));
        foreach ($first as $value) {
            Assert::true(is_array($value) && is_int($value['a']) && is_int($value['b']));
        }
    }

    public function aRecursiveDefCompilesTheSameOnAReusedCompiler(): void
    {
        $schema = [
            '$defs' => ['Node' => ['type' => 'object', 'properties' => ['children' => ['type' => 'array', 'maxItems' => 2, 'items' => ['$ref' => '#/$defs/Node']]]]],
            '$ref' => '#/$defs/Node',
        ];
        $compiler = new SchemaArbitraryCompiler();

        $first = Gen::sample($compiler->compile($schema), count: 20, seed: 3);

        Assert::same(Gen::sample($compiler->compile($schema), count: 20, seed: 3), $first);
        Assert::same(Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: 3), $first);
    }

    public function aDefInlinedIntoTwoConjunctionsIsNotItsOwnMember(): void
    {
        $schema = [
            '$defs' => ['D' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5]],
            'type' => 'object',
            'properties' => [
                'a' => ['allOf' => [['$ref' => '#/$defs/D'], ['multipleOf' => 1]]],
                'b' => ['allOf' => [['$ref' => '#/$defs/D']]],
            ],
            'required' => ['a', 'b'],
        ];

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 10, seed: 1) as $value) {
            Assert::true(is_array($value) && is_int($value['a']) && is_int($value['b']));
        }
    }
}
