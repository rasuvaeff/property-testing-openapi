<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NegativeRequestCaseArbitrary::class)]
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
}
