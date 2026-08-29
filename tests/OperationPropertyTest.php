<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\OpenApiOperations;
use Rasuvaeff\PropertyTesting\OpenApi\OperationProperty;
use Rasuvaeff\PropertyTesting\OpenApi\OperationPropertyFailed;
use Rasuvaeff\PropertyTesting\OpenApi\SuiteConfigurationError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OperationProperty::class)]
#[Covers(OpenApiOperations::class)]
#[Covers(OperationPropertyFailed::class)]
final class OperationPropertyTest
{
    public function conformingOperationsPassBothPhases(): void
    {
        $suite = $this->suite(static fn(Contract $contract, RequestInterface $request): Response
            => $contract->validateRequest($request)->isValid() ? new Response(204) : new Response(400));

        OperationProperty::check($suite, 'pets.get', runs: 15, seed: 17);

        Assert::true(actual: true);
    }

    public function skipsTheNegativePhaseWithoutAConstructibleCategory(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/ping' => ['get' => ['operationId' => 'ping', 'responses' => ['204' => []]]]],
        ]);
        $factory = new Psr17Factory();
        $suite = ContractSuite::fromContract($contract, $factory, $factory)
            ->operations(['ping'])
            ->transport(new CallableTransport(static fn(): Response => new Response(204)));

        OperationProperty::check($suite, 'ping', runs: 3, seed: 5);

        Assert::true(actual: true);
    }

    public function reportsAFalsifiedValidPhaseWithAReproducer(): void
    {
        $suite = $this->suite(static fn(): Response => new Response(500));

        try {
            OperationProperty::check($suite, 'pets.get', runs: 10, seed: 17);
            Assert::true(actual: false, message: 'Expected a falsified valid phase');
        } catch (OperationPropertyFailed $failure) {
            Assert::same($failure->phase, 'valid');
            Assert::same($failure->operationKey, 'pets.get');
            Assert::string($failure->getMessage())->contains('failed the valid phase');
            Assert::same(strtok($failure->getMessage(), "\n"), sprintf(
                'Operation "pets.get" failed the valid phase after %d run(s) (seed 17): Operation "pets.get" responded with server error status 500',
                $failure->counterExample->runsBeforeFailure,
            ));
            Assert::string($failure->getMessage())->contains('curl');
            Assert::string($failure->reproducer)->contains('/pets/');
            Assert::same($failure->counterExample->seed, 17);
            Assert::same($failure->getCode(), 0);
        }
    }

    public function reportsAFalsifiedNegativePhase(): void
    {
        $suite = $this->suite(static function (Contract $contract, RequestInterface $request): Response {
            $valid = $contract->validateRequest($request)->isValid();

            return $valid ? new Response(204) : new Response(500);
        });

        try {
            OperationProperty::check($suite, 'pets.get', runs: 10, seed: 19);
            Assert::true(actual: false, message: 'Expected a falsified negative phase');
        } catch (OperationPropertyFailed $failure) {
            Assert::same($failure->phase, 'negative');
            Assert::string($failure->getMessage())->contains('failed the negative phase');
        }
    }

    public function honorsTheRunsAndSeedEnvironment(): void
    {
        $calls = 0;
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/ping' => ['get' => ['operationId' => 'ping', 'responses' => ['204' => []]]]],
        ]);
        $factory = new Psr17Factory();
        $suite = ContractSuite::fromContract($contract, $factory, $factory)
            ->operations(['ping'])
            ->transport(new CallableTransport(static function () use (&$calls): Response {
                ++$calls;

                return new Response(204);
            }));

        putenv('PROPERTY_RUNS=5');

        try {
            OperationProperty::check($suite, 'ping', runs: 50);
        } finally {
            putenv('PROPERTY_RUNS');
        }

        Assert::same($calls, 5);
    }

    public function rejectsMalformedEnvironmentValues(): void
    {
        $suite = $this->suite(static fn(): Response => new Response(204));

        foreach ([
            'PROPERTY_RUNS=zero' => 'PROPERTY_RUNS must be a positive integer, got "zero"',
            'PROPERTY_RUNS=x5' => 'PROPERTY_RUNS must be a positive integer, got "x5"',
            'PROPERTY_SEED=x5' => 'PROPERTY_SEED must be an integer, got "x5"',
            'PROPERTY_DB=redis://127.0.0.1' => 'PROPERTY_DB "redis://127.0.0.1" names a remote corpus; the OpenAPI operation property supports only directory corpora',
        ] as $env => $message) {
            putenv($env);

            try {
                OperationProperty::check($suite, 'pets.get', runs: 1);
                Assert::true(actual: false, message: 'Expected a configuration exception');
            } catch (\InvalidArgumentException|SuiteConfigurationError $failure) {
                Assert::same($failure->getMessage(), $message);
            } finally {
                putenv(strtok($env, '='));
            }
        }
    }

    public function acceptsDirectoryCorporaWithEmbeddedSchemeLikeSegments(): void
    {
        $directory = sys_get_temp_dir() . '/openapi-op-' . bin2hex(random_bytes(4)) . '/a://b';
        $suite = $this->suite(static fn(Contract $contract, RequestInterface $request): Response
            => $contract->validateRequest($request)->isValid() ? new Response(204) : new Response(400));

        putenv('PROPERTY_DB=' . $directory);

        try {
            OperationProperty::check($suite, 'pets.get', runs: 2, seed: 3);
        } finally {
            putenv('PROPERTY_DB');
        }

        Assert::true(actual: true);
    }

    public function replaysTheCorpusOnlyWithoutAPinnedSeed(): void
    {
        $directory = sys_get_temp_dir() . '/openapi-op-replay-' . bin2hex(random_bytes(4));
        $failing = $this->suite(static fn(): Response => new Response(500));

        putenv('PROPERTY_DB=' . $directory);

        try {
            try {
                OperationProperty::check($failing, 'pets.get', runs: 3);
                Assert::true(actual: false, message: 'Expected a falsified valid phase');
            } catch (OperationPropertyFailed) {
                Assert::true(actual: true);
            }

            try {
                OperationProperty::check($failing, 'pets.get', runs: 3);
                Assert::true(actual: false, message: 'Expected a corpus replay failure');
            } catch (\Rasuvaeff\PropertyTesting\RegressionViolationException) {
                Assert::true(actual: true);
            }

            try {
                OperationProperty::check($failing, 'pets.get', runs: 3, seed: 23);
                Assert::true(actual: false, message: 'Expected a falsified valid phase');
            } catch (OperationPropertyFailed $failure) {
                Assert::same($failure->counterExample->seed, 23);
            }
        } finally {
            putenv('PROPERTY_DB');
            $entries = glob($directory . '/*.json');
            foreach (is_array($entries) ? $entries : [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function storesFalsificationsInTheDirectoryCorpus(): void
    {
        $directory = sys_get_temp_dir() . '/openapi-operation-property-' . bin2hex(random_bytes(4));
        $suite = $this->suite(static fn(): Response => new Response(500));

        putenv('PROPERTY_DB=' . $directory);

        try {
            OperationProperty::check($suite, 'pets.get', runs: 5);
            Assert::true(actual: false, message: 'Expected a falsified valid phase');
        } catch (OperationPropertyFailed) {
            $stored = glob($directory . '/*.json');
            Assert::true(is_array($stored) && $stored !== []);
        } finally {
            putenv('PROPERTY_DB');
            $entries = glob($directory . '/*.json');
            foreach (is_array($entries) ? $entries : [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function enumeratesTheSuiteSelectionAsNamedTuples(): void
    {
        $suite = $this->suite(static fn(): Response => new Response(204));

        Assert::same(iterator_to_array(OpenApiOperations::keys($suite)), ['pets.get' => ['pets.get']]);
    }

    /** @param callable(Contract, RequestInterface): Response $handler */
    private function suite(callable $handler): ContractSuite
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
                        ],
                        'responses' => ['204' => [], '400' => []],
                    ],
                ],
            ],
        ]);
        $factory = new Psr17Factory();

        return ContractSuite::fromContract($contract, $factory, $factory)
            ->operations(['pets.get'])
            ->transport(new CallableTransport(static fn(RequestInterface $request): Response => $handler($contract, $request)));
    }
}
