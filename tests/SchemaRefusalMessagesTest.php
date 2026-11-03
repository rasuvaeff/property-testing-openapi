<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\CompositionArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Every schema the generator refuses at compile time is refused with the
 * reason that names the offending keyword, not with a generic failure.
 */
#[Test]
#[Covers(SchemaArbitraryCompiler::class)]
#[Covers(CompositionArbitraries::class)]
#[Covers(ContainerArbitraries::class)]
#[Covers(ScalarArbitraries::class)]
#[Covers(UnsupportedGeneration::class)]
final class SchemaRefusalMessagesTest
{
    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('refusalProvider')]
    public function aSchemaTheGeneratorCannotConstructIsRefusedWithItsReason(array $schema, string $reason, string $excluded = ''): void
    {
        $compiler = new SchemaArbitraryCompiler($excluded);

        try {
            Gen::sample($compiler->compile($schema), count: 20, seed: 1);
        } catch (UnsupportedGeneration $exception) {
            Assert::same($exception->getMessage(), 'Unsupported OpenAPI schema generation: ' . $reason);

            return;
        }

        Assert::true(actual: false, message: 'the schema was compiled');
    }

    public static function refusalProvider(): iterable
    {
        yield 'unevaluatedProperties inside allOf' => [
            ['allOf' => [['type' => 'object', 'unevaluatedProperties' => false]]],
            'unevaluated keywords inside allOf branches are outside the constructive subset',
        ];
        yield 'unevaluatedItems inside allOf' => [
            ['allOf' => [['type' => 'array', 'unevaluatedItems' => false]]],
            'unevaluated keywords inside allOf branches are outside the constructive subset',
        ];
        yield 'allOf required not a list' => [
            ['allOf' => [['type' => 'object', 'required' => 'a']]],
            'allOf required must be a list',
        ];
        yield 'allOf required with a non-name' => [
            ['allOf' => [['type' => 'object', 'required' => [1]]]],
            'allOf required must contain property names',
        ];
        yield 'allOf properties not an object' => [
            ['allOf' => [['type' => 'object', 'properties' => 'x']]],
            'allOf properties must be an object',
        ];
        yield 'minItems above maxItems' => [
            ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 5, 'maxItems' => 2],
            'minItems exceeds maxItems or the generation budget',
        ];
        yield 'minProperties above maxProperties' => [
            ['type' => 'object', 'minProperties' => 3, 'maxProperties' => 1],
            'minProperties exceeds maxProperties or the generation budget',
        ];
        yield 'required with a non-name' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => [1]],
            'required must contain property names',
        ];
        yield 'required not a list' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => 'a'],
            'required must be a list of property names',
        ];
        yield 'required above maxProperties' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b'], 'maxProperties' => 1],
            'required properties exceed maxProperties',
        ];
        yield 'propertyNames with a foreign keyword' => [
            ['type' => 'object', 'propertyNames' => ['minLength' => 1]],
            'propertyNames keyword "minLength" is outside the supported generator subset',
        ];
        yield 'propertyNames pattern not a string' => [
            ['type' => 'object', 'propertyNames' => ['pattern' => 5], 'additionalProperties' => ['type' => 'integer'], 'minProperties' => 1],
            'propertyNames pattern must be a string',
        ];
        yield 'minLength above maxLength' => [
            ['type' => 'string', 'minLength' => 5, 'maxLength' => 2],
            'minLength exceeds maxLength or the generation budget',
        ];
        yield 'excluded characters leave no alphabet' => [
            ['type' => 'string', 'minLength' => 1],
            'the excluded characters leave no alphabet',
            implode('', array_map(chr(...), range(32, 126))),
        ];
        yield 'integer multipleOf with no value in range' => [
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'multipleOf' => 5],
            'integer multipleOf leaves no value',
        ];
        yield 'dependentRequired values not a list' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'dependentRequired' => ['a' => 'b']],
            'dependentRequired values must be lists of names',
        ];
        yield 'dependentRequired values not names' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'dependentRequired' => ['a' => [1]]],
            'dependentRequired values must contain names',
        ];
        yield 'dependentSchemas values not schemas' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'dependentSchemas' => ['a' => [1]]],
            'dependentSchemas values must be schema objects',
        ];
        yield 'dependentSchemas with a foreign keyword' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'dependentSchemas' => ['a' => ['not' => []]]],
            'dependentSchemas keyword "not" is outside the constructive subset',
        ];
        yield 'if not a schema' => [
            ['type' => 'object', 'if' => 'x', 'then' => ['type' => 'object']],
            'if must be a schema object',
        ];
    }
}
