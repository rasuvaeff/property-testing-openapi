<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The generator and the validator are one oracle: every valid case this
 * package builds for a document must be accepted by `openapi-contract`, and
 * every negative one rejected. Each test here pins a document on which the
 * two used to disagree.
 */
#[Test]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(SchemaArbitraryCompiler::class)]
#[Covers(ContainerArbitraries::class)]
#[Covers(WireValue::class)]
final class WireAgreementTest
{
    private const int DRAWS = 200;

    /**
     * A `readOnly` property is declared and typed by the contract, and a
     * request should not carry it: the valid phase never emits it, and never
     * generates an undeclared member under its name either — the contract
     * would type-check that member as the declared property.
     */
    public function readOnlyPropertiesAreNeverSentAndTheirNamesStayReserved(): void
    {
        $contract = $this->jsonBodyContract([
            'type' => 'object',
            'required' => ['id'],
            'minProperties' => 1,
            'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]],
        ]);

        foreach ($this->validCases($contract, 'things.create', 300) as $case) {
            Assert::false(array_key_exists('id', (array) ($case['body']['value'] ?? [])));
        }
    }

    /**
     * A JSON media type without a `schema`, or with the `true` schema, admits
     * any value in OpenAPI, and the contract reads both as unconstrained.
     */
    public function aJsonBodyWithoutASchemaIsGeneratedUnconstrained(): void
    {
        foreach ([[], ['schema' => true]] as $definition) {
            $contract = Contract::fromArray([
                'openapi' => '3.1.0',
                'paths' => ['/things' => ['post' => [
                    'operationId' => 'things.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => $definition]],
                    'responses' => ['201' => []],
                ]]],
            ]);

            Assert::same(count($this->validCases($contract, 'things.create', 50)), 50);
        }
    }

    /**
     * Every draw must be accepted by the contract; the cases are returned so
     * a test can pin what they carry as well.
     *
     * @return list<array<string, mixed>>
     */
    private function validCases(Contract $contract, string $operationKey, int $draws = self::DRAWS): array
    {
        $operation = $contract->operation($operationKey);
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);
        $cases = [];
        foreach (range(1, $draws) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $result = $contract->validateRequest($materializer->materialize($operation, $case));
            Assert::true($result->isValid(), sprintf('Seed %d: %s', $seed, json_encode([$case, $result->violations], JSON_THROW_ON_ERROR)));
            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * Every draw must be rejected by the contract.
     *
     * @param ArbitraryInterface<array<string, mixed>> $arbitrary
     * @return list<array<string, mixed>>
     */
    private function negativeCases(Contract $contract, string $operationKey, ArbitraryInterface $arbitrary, int $draws = self::DRAWS): array
    {
        $operation = $contract->operation($operationKey);
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $cases = [];
        foreach (range(1, $draws) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $result = $contract->validateRequest($materializer->materialize($operation, $case));
            Assert::false($result->isValid(), sprintf('Seed %d accepted: %s', $seed, json_encode($case, JSON_THROW_ON_ERROR)));
            $cases[] = $case;
        }

        return $cases;
    }

    /** @param array<string, mixed> $schema */
    private function jsonBodyContract(array $schema, string $version = '3.1.0'): Contract
    {
        return Contract::fromArray([
            'openapi' => $version,
            'paths' => ['/things' => ['post' => [
                'operationId' => 'things.create',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]],
                'responses' => ['201' => []],
            ]]],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    private function parameterContract(array $parameters, string $path = '/things'): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [$path => ['get' => [
                'operationId' => 'things.list',
                'parameters' => $parameters,
                'responses' => ['200' => []],
            ]]],
        ]);
    }
}
