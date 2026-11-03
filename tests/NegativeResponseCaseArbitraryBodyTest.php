<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NegativeResponseCaseArbitrary::class)]
final class NegativeResponseCaseArbitraryBodyTest
{
    public function anEmptyObjectBodyStillTakesTheAdditionalProperty(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['a' => ['type' => 'integer']],
        ]]]]]);

        // Seed 2 draws the valid body `{}`.
        $case = (new NegativeResponseCaseArbitrary())->additionalPropertyForOperation($operation, 200)->generate(new Random(2))->value;

        Assert::same($case['body']['value'] ?? null, ['__openapi_extra_property__' => true]);
        Assert::same($case['misuse'], ['kind' => 'additional-properties', 'location' => 'body', 'name' => '__openapi_extra_property__']);
    }
}
