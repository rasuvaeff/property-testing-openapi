<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The negative phase's category list, asked about one category at a time
 * over a document that supports only it. Kept apart from
 * {@see ContractSuiteTest}, whose end-to-end zoo property makes every
 * mutant mapped to it slow.
 */
#[Test]
#[Covers(ContractSuite::class)]
final class ContractSuiteNegativeCasesTest
{
    /** An unconstrained required parameter can only be left out. */
    public function aMissingRequiredParameterIsACategoryOfItsOwn(): void
    {
        $factory = new Psr17Factory();
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'operationId' => 'pets.list',
                'parameters' => [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $suite = ContractSuite::fromContract($contract, $factory, $factory)->operations(['pets.list']);

        $case = $suite->negativeCases('pets.list')->generate(new Random(1))->value;

        Assert::same($case['misuse'] ?? null, ['kind' => 'missing-required', 'location' => 'query', 'name' => 'q']);
    }

    /** The coverage record holds every selected operation, not only the first. */
    public function negativeCoverageHasAnEntryPerSelectedOperation(): void
    {
        $factory = new Psr17Factory();
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/a' => ['get' => ['operationId' => 'a', 'responses' => ['204' => []]]],
                '/b' => ['get' => ['operationId' => 'b', 'responses' => ['204' => []]]],
            ],
        ]);

        Assert::same(array_keys(ContractSuite::fromContract($contract, $factory, $factory)->operations(['a', 'b'])->negativeCoverage()), ['a', 'b']);
    }
}
