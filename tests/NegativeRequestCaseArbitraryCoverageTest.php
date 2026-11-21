<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ConstructibleCategories;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ZooContracts;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(ConstructibleCategories::class)]
final class NegativeRequestCaseArbitraryCoverageTest
{
    public function coverageNamesEveryTargetByKindLocationAndNameOnly(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/p' => ['post' => [
            'operationId' => 'p.create',
            'parameters' => [['name' => 'n', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']]],
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['age'],
                'properties' => ['age' => ['type' => 'integer']],
            ]]]],
            'responses' => ['204' => ['description' => 'none']],
        ]]]]);

        $coverage = (new NegativeRequestCaseArbitrary())->coverageForOperation($contract->operation('p.create'));

        Assert::same($coverage['covered'], [
            ['kind' => 'missing-required', 'location' => 'query', 'name' => 'n'],
            ['kind' => 'missing-required', 'location' => 'body', 'name' => 'body'],
            ['kind' => 'type', 'location' => 'query', 'name' => 'n'],
            ['kind' => 'additional-properties', 'location' => 'body', 'name' => '__openapi_extra_property__'],
            ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'],
            ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'],
            ['kind' => 'type', 'location' => 'body', 'name' => 'age'],
        ]);
    }

    public function categoriesAreKeyedBySideAndKindInWeightingOrder(): void
    {
        $categories = (new NegativeRequestCaseArbitrary())->categoriesForOperation($this->operation());

        Assert::same(array_keys($categories), ['missing-required', 'type', 'additional-properties', 'media-type', 'json-syntax', 'body-type']);
        foreach ($categories as $key => $category) {
            $kind = $category->generate(new Random(3))->value['misuse']['kind'] ?? null;

            Assert::same($kind, str_starts_with($key, 'body-') ? substr($key, 5) : $key);
        }
    }

    public function everyCategoryKeyMatchesTheCoverageOfTheSameCategory(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        $operation = ZooContracts::contract()->operation('profiles.create');
        $covered = [];
        foreach ($negative->coverageForOperation($operation)['covered'] as $entry) {
            $covered[($entry['location'] === 'body' && in_array($entry['kind'], ['type', 'enum', 'const', 'boundary', 'length', 'format', 'pattern'], strict: true) ? 'body-' : '') . $entry['kind']] = true;
        }

        Assert::same(array_keys($negative->categoriesForOperation($operation)), array_keys($covered));
    }

    public function theAnyUnionDrawsWhatTheSuiteDraws(): void
    {
        $operation = ZooContracts::contract()->operation('profiles.create');
        $any = (new NegativeRequestCaseArbitrary())->anyForOperation($operation);
        $suite = ZooContracts::suiteFor('profiles.create')->negativeCases('profiles.create');

        foreach (range(1, 20) as $seed) {
            Assert::same($any->generate(new Random($seed))->value, $suite->generate(new Random($seed))->value);
        }
    }

    public function theAnyUnionFailsClosedWithoutACategory(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op');

        Expect::exception(UnsupportedGeneration::class)->withMessage('Operation "op" supports no constructible negative case category');

        (new NegativeRequestCaseArbitrary())->anyForOperation($operation);
    }

    public function anOperationWithoutTargetsHasNoCategory(): void
    {
        Assert::same((new NegativeRequestCaseArbitrary())->categoriesForOperation(new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op')), []);
    }

    private function operation(): Operation
    {
        return Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/p' => ['post' => [
            'operationId' => 'p.create',
            'parameters' => [['name' => 'n', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']]],
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['age'],
                'properties' => ['age' => ['type' => 'integer']],
            ]]]],
            'responses' => ['204' => ['description' => 'none']],
        ]]]])->operation('p.create');
    }
}
