<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ResponseTargets;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * The response-side target finders, asked directly over hand-built
 * operations: which status, header or property each category lands on.
 */
#[Test]
#[Covers(ResponseTargets::class)]
final class ResponseTargetsTest
{
    /** @param array<string, mixed> $responses */
    private function operation(array $responses): Operation
    {
        return new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: $responses);
    }

    /** @param array<string, mixed> $schema */
    private function jsonResponse(array $schema): Operation
    {
        return $this->operation(['200' => ['content' => ['application/json' => ['schema' => $schema]]]]);
    }

    /** The lowest candidate is a candidate: status 100 is offered when nothing above it is free. */
    public function theLadderReachesStatus100(): void
    {
        $responses = ['2XX' => [], '3XX' => [], '4XX' => [], '5XX' => []];
        for ($status = 101; $status <= 199; ++$status) {
            $responses[(string) $status] = [];
        }

        Assert::same((new ResponseTargets())->undeclaredStatus($this->operation($responses)), 100);
    }

    public function anOperationDeclaringEveryStatusHasNoUndeclaredOne(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Operation "op" declares every candidate status');

        (new ResponseTargets())->undeclaredStatus($this->operation(['1XX' => [], '2XX' => [], '3XX' => [], '4XX' => [], '5XX' => []]));
    }

    /** A header that does not say `required: true` is not a required one. */
    public function anOptionalHeaderIsNotTheRequiredOne(): void
    {
        $operation = $this->operation(['200' => ['headers' => [
            'X-Optional' => ['schema' => ['type' => 'string']],
            'X-Declined' => ['required' => false, 'schema' => ['type' => 'string']],
            'X-Truthy' => ['required' => 1, 'schema' => ['type' => 'string']],
            'X-Required' => ['required' => true, 'schema' => ['type' => 'string']],
        ]]]);

        Assert::same((new ResponseTargets())->requiredHeader($operation, 200), 'X-Required');
    }

    /** Every bounded property is a target, not only the first. */
    public function everyBodyWitnessIsReturned(): void
    {
        $operation = $this->jsonResponse(['type' => 'object', 'properties' => [
            'a' => ['type' => 'integer', 'maximum' => 9],
            'b' => ['type' => 'integer', 'minimum' => 1],
        ]]);

        Assert::same((new ResponseTargets())->bodyWitness($operation, 200, 'boundary'), [
            ['name' => 'a', 'invalid' => 10],
            ['name' => 'b', 'invalid' => 0],
        ]);
    }

    /** `type: object` alone makes an object body, with or without `properties`. */
    public function anObjectWithoutPropertiesStillHasRequiredMembers(): void
    {
        $operation = $this->jsonResponse(['type' => 'object', 'required' => ['id']]);

        Assert::same((new ResponseTargets())->missingRequired($operation, 200), 'id');
    }

    /** And `properties` alone makes one too, with or without `type`. */
    public function untypedPropertiesStillRejectAnAdditionalOne(): void
    {
        $operation = $this->jsonResponse(['properties' => ['__openapi_extra_property__' => ['type' => 'string']], 'additionalProperties' => false]);

        Assert::same((new ResponseTargets())->additionalProperty($operation, 200), '__openapi_extra_property___');
    }

    public function aScalarBodyRejectsNoAdditionalProperty(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 200 of operation "op" does not reject additional properties');

        (new ResponseTargets())->additionalProperty($this->jsonResponse(['type' => 'string', 'additionalProperties' => false]), 200);
    }
}
