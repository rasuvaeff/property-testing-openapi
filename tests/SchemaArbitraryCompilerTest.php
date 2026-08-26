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
            Assert::true(is_array($value['tags']) && count($value['tags']) >= 1 && count($value['tags']) <= 3);
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

    public function rejectsUnimplementedAssertionKeywords(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'pattern' => '[a-z]+']);
    }

    public function rejectsUnimplementedFormats(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => 'uuid']);
    }

    public function rejectsUnimplementedObjectCardinality(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new SchemaArbitraryCompiler())->compile(['type' => 'object', 'maxProperties' => 1]);
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
}
