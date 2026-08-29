<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\UnknownOperation;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\Violation;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\CheckFailed;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsProviderInterface;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsUnavailable;
use Rasuvaeff\PropertyTesting\OpenApi\RejectionPolicy;
use Rasuvaeff\PropertyTesting\OpenApi\SuiteConfigurationError;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(ContractSuite::class)]
#[Covers(CheckFailed::class)]
#[Covers(SuiteConfigurationError::class)]
final class ContractSuiteTest
{
    public function defaultSelectionIsEmpty(): void
    {
        Assert::same($this->suite()->operationKeys(), []);
    }

    public function operationsValidatesUnknownKeys(): void
    {
        Expect::exception(UnknownOperation::class);

        $this->suite()->operations(['pets.rename']);
    }

    public function allSafeOperationsSelectsOnlyGetAndHead(): void
    {
        Assert::same($this->suite()->allSafeOperations()->operationKeys(), ['pets.list', 'pets.get', 'secure.get']);
    }

    public function excludeRemovesAnOperationFromTheSelection(): void
    {
        $suite = $this->suite()->allSafeOperations()->exclude(['pets.list', 'secure.get']);

        Assert::same($suite->operationKeys(), ['pets.get']);
    }

    public function fluentConfigurationDoesNotMutateTheOriginalSuite(): void
    {
        $original = $this->suite();
        $selected = $original->operations(['pets.get']);
        Assert::same($selected->operationKeys(), ['pets.get']);
        Assert::same($original->operationKeys(), []);

        $safe = $original->allSafeOperations();
        Assert::same($safe->operationKeys(), ['pets.list', 'pets.get', 'secure.get']);
        Assert::same($original->operationKeys(), []);

        $excluded = $selected->exclude(['pets.get']);
        Assert::same($excluded->operationKeys(), []);
        Assert::same($selected->operationKeys(), ['pets.get']);

        $unsafeBase = $this->suite()->operations(['pets.create']);
        $unsafe = $unsafeBase->allowUnsafeOperations();
        Assert::same($unsafe->operationKeys(), ['pets.create']);
        Expect::exception(SuiteConfigurationError::class);
        $unsafeBase->operationKeys();
    }

    public function rejectionPolicyConfigurationDoesNotMutateTheOriginalSuite(): void
    {
        $original = $this->suite()->operations(['pets.get'])->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(200)));
        $provider = Understudy::for(CredentialsProviderInterface::class);
        $withCredentials = $original->credentials($provider);
        $configured = $withCredentials->rejectionPolicy(RejectionPolicy::rejectWith('4XX'));
        $case = $original->negativeCases('pets.get')->generate(new Random(73))->value;

        $original->checkNegative('pets.get', $case);
        $withCredentials->checkNegative('pets.get', $case);
        Expect::exception(CheckFailed::class);
        $configured->checkNegative('pets.get', $case);
    }

    public function transportConfigurationDoesNotMutateTheOriginalSuite(): void
    {
        $original = $this->suite()->operations(['pets.get']);
        $configured = $original->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(204)));
        $case = $configured->validCases('pets.get')->generate(new Random(79))->value;

        $configured->checkValid('pets.get', $case);
        Expect::exception(SuiteConfigurationError::class);
        $original->checkValid('pets.get', $case);
    }

    public function credentialsConfigurationDoesNotMutateTheOriginalSuite(): void
    {
        $original = $this->suite()->operations(['secure.get']);
        $provider = Understudy::for(CredentialsProviderInterface::class);
        expect(fn() => $provider->provide(Arg::any()))->returns(new Credentials(headers: ['X-Api-Key' => ['token']]));
        $configured = $original->credentials($provider)->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(204)));
        $case = $configured->validCases('secure.get')->generate(new Random(83))->value;

        try {
            $original->checkValid('secure.get', $case);
            Assert::true(actual: false);
        } catch (CredentialsUnavailable) {
            Assert::true(actual: true);
        }

        $configured->checkValid('secure.get', $case);
    }

    public function checkFailedSummarizesTheFirstViolation(): void
    {
        $result = new ValidationResult([new Violation(
            code: 'request.invalid',
            operation: 'pets.get',
            location: 'query',
            instancePath: 'q',
            specPointer: '/paths/pets',
            expected: 'string',
            actual: 1,
            message: 'bad query',
        )]);

        $exception = CheckFailed::exchangeViolations('pets.get', $result);

        Assert::same($exception->getMessage(), 'Exchange for operation "pets.get" violates the contract: 1 violation(s), first [request.invalid] bad query');
    }

    public function unsafeSelectionFailsClosedWithoutTheGate(): void
    {
        $suite = $this->suite()->operations(['pets.create']);

        Expect::exception(SuiteConfigurationError::class);
        $suite->operationKeys();
    }

    public function allowUnsafeOperationsAdmitsAnExplicitUnsafeOperation(): void
    {
        $suite = $this->suite()->operations(['pets.create'])->allowUnsafeOperations();

        Assert::same($suite->operationKeys(), ['pets.create']);
    }

    public function checkValidRunsTheExchangeThroughTheTransport(): void
    {
        $calls = 0;
        $suite = $this->suite()->operations(['pets.get'])->transport(new CallableTransport(static function (RequestInterface $request) use (&$calls): ResponseInterface {
            ++$calls;

            return new Response(204);
        }));
        $case = $suite->validCases('pets.get')->generate(new Random(5))->value;

        $suite->checkValid('pets.get', $case);

        Assert::same($calls, 1);
    }

    public function checkValidRejectsAServerErrorResponse(): void
    {
        $suite = $this->suite()->operations(['pets.get'])->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(500)));
        $case = $suite->validCases('pets.get')->generate(new Random(7))->value;

        Expect::exception(CheckFailed::class);
        $suite->checkValid('pets.get', $case);
    }

    public function checkValidRejectsExactlyStatus500(): void
    {
        $suite = $this->suite()->operations(['pets.get'])
            ->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(500)));
        $case = $suite->validCases('pets.get')->generate(new Random(71))->value;

        try {
            $suite->checkValid('pets.get', $case);
            Assert::true(actual: false);
        } catch (CheckFailed $exception) {
            Assert::same($exception->getMessage(), 'Operation "pets.get" responded with server error status 500');
        }
    }

    public function checkValidRejectsANonConformingResponse(): void
    {
        $suite = $this->suite()->operations(['pets.get'])->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(418)));
        $case = $suite->validCases('pets.get')->generate(new Random(11))->value;

        Expect::exception(CheckFailed::class);
        $suite->checkValid('pets.get', $case);
    }

    public function checkValidRequiresATransport(): void
    {
        $suite = $this->suite()->operations(['pets.get']);
        $case = $suite->validCases('pets.get')->generate(new Random(13))->value;

        Expect::exception(SuiteConfigurationError::class);
        $suite->checkValid('pets.get', $case);
    }

    public function checkValidRejectsAMisuseCase(): void
    {
        $suite = $this->suite()->operations(['pets.get']);
        $case = $suite->validCases('pets.get')->generate(new Random(17))->value;
        $case['misuse'] = ['kind' => 'type', 'location' => 'path', 'name' => 'id'];

        Expect::exception(\InvalidArgumentException::class);
        $suite->checkValid('pets.get', $case);
    }

    public function checkValidRejectsAnUnselectedOperation(): void
    {
        $suite = $this->suite()->operations(['pets.list']);

        Expect::exception(SuiteConfigurationError::class);
        $suite->checkValid('pets.get', [
            'operationKey' => 'pets.get',
            'path' => [],
            'query' => [],
            'headers' => [],
            'cookies' => [],
            'body' => null,
            'misuse' => null,
        ]);
    }

    public function checkNegativeAcceptsARejectionWithoutServerError(): void
    {
        $calls = 0;
        $suite = $this->suite()->operations(['pets.get'])->transport(new CallableTransport(static function (RequestInterface $request) use (&$calls): ResponseInterface {
            ++$calls;

            return new Response(400);
        }));
        $case = $suite->negativeCases('pets.get')->generate(new Random(19))->value;

        $suite->checkNegative('pets.get', $case);

        Assert::same($calls, 1);
    }

    public function checkNegativeRejectsAServerErrorResponse(): void
    {
        $suite = $this->suite()->operations(['pets.get'])->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(500)));
        $case = $suite->negativeCases('pets.get')->generate(new Random(23))->value;

        Expect::exception(CheckFailed::class);
        $suite->checkNegative('pets.get', $case);
    }

    public function checkNegativeRequiresMisuseMetadata(): void
    {
        $suite = $this->suite()->operations(['pets.get']);
        $case = $suite->validCases('pets.get')->generate(new Random(29))->value;

        Expect::exception(\InvalidArgumentException::class);
        $suite->checkNegative('pets.get', $case);
    }

    public function checkNegativeRejectsAnUnexpectedlyValidCase(): void
    {
        $suite = $this->suite()->operations(['pets.get']);
        $case = $suite->validCases('pets.get')->generate(new Random(31))->value;
        $case['misuse'] = ['kind' => 'type', 'location' => 'path', 'name' => 'id'];

        Expect::exception(CheckFailed::class);
        $suite->checkNegative('pets.get', $case);
    }

    public function rejectionPolicyAcceptsAConformingRejection(): void
    {
        $calls = 0;
        $suite = $this->suite()->operations(['pets.get'])
            ->rejectionPolicy(RejectionPolicy::rejectWith('4XX'))
            ->transport(new CallableTransport(static function (RequestInterface $request) use (&$calls): ResponseInterface {
                ++$calls;

                return new Response(422);
            }));
        $case = $suite->negativeCases('pets.get')->generate(new Random(47))->value;

        $suite->checkNegative('pets.get', $case);

        Assert::same($calls, 1);
    }

    public function rejectionPolicyRejectsAnAcceptedInvalidRequest(): void
    {
        $suite = $this->suite()->operations(['pets.get'])
            ->rejectionPolicy(RejectionPolicy::rejectWith('4XX'))
            ->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(200)));
        $case = $suite->negativeCases('pets.get')->generate(new Random(53))->value;

        Expect::exception(CheckFailed::class);
        $suite->checkNegative('pets.get', $case);
    }

    public function reproduceRendersACurlWithoutCredentials(): void
    {
        $provider = Understudy::for(CredentialsProviderInterface::class);
        $suite = $this->suite()->operations(['secure.get'])->credentials($provider);
        $case = $suite->validCases('secure.get')->generate(new Random(59))->value;

        $curl = $suite->reproduce('secure.get', $case);

        Assert::string($curl)->contains("curl -X GET '/secure'");
        Assert::false(str_contains($curl, 'X-Api-Key'));
        verify(fn() => $provider->provide(Arg::any()), never: true);
    }

    public function negativeCasesFailClosedWithoutAConstructibleCategory(): void
    {
        $suite = $this->suite()->operations(['pets.list']);

        Expect::exception(UnsupportedGeneration::class);
        $suite->negativeCases('pets.list');
    }

    public function negativeCasesCombineConstructibleCategories(): void
    {
        $suite = $this->suite()->operations(['pets.get']);
        $kinds = [];
        foreach ([3, 7, 19, 41, 53, 67, 71, 97] as $seed) {
            $case = $suite->negativeCases('pets.get')->generate(new Random($seed))->value;

            Assert::true(in_array($case['misuse']['kind'], ['missing-required', 'type', 'boundary'], strict: true));
            $kinds[$case['misuse']['kind']] = true;
        }

        Assert::true(count($kinds) > 1);

        $missingRequired = false;
        foreach (range(1, 40) as $seed) {
            $case = $suite->negativeCases('pets.get')->generate(new Random($seed))->value;
            if ($case['misuse']['kind'] === 'missing-required') {
                $missingRequired = true;
                break;
            }
        }
        Assert::true($missingRequired);
    }

    public function credentialsAreAppliedToTheMaterializedRequest(): void
    {
        $provider = Understudy::for(CredentialsProviderInterface::class);
        expect(fn() => $provider->provide(Arg::any()))->returns(new Credentials(headers: ['X-Api-Key' => ['secret-token']], secretFields: ['X-Api-Key']));
        $calls = 0;
        $suite = $this->suite()
            ->operations(['secure.get'])
            ->credentials($provider)
            ->transport(new CallableTransport(static function (RequestInterface $request) use (&$calls): ResponseInterface {
                ++$calls;
                Assert::same($request->getHeaderLine('X-Api-Key'), 'secret-token');

                return new Response(204);
            }));
        $case = $suite->validCases('secure.get')->generate(new Random(37))->value;

        $suite->checkValid('secure.get', $case);

        Assert::same($calls, 1);
    }

    public function securedOperationWithoutAProviderFailsClosed(): void
    {
        $suite = $this->suite()->operations(['secure.get'])->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(204)));
        $case = $suite->validCases('secure.get')->generate(new Random(43))->value;

        Expect::exception(CredentialsUnavailable::class);
        $suite->checkValid('secure.get', $case);
    }

    private function suite(): ContractSuite
    {
        $factory = new Psr17Factory();

        return ContractSuite::fromContract($this->contract(), $factory, $factory);
    }

    private function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => [
                'securitySchemes' => [
                    'api' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'operationId' => 'pets.list',
                        'responses' => ['204' => []],
                    ],
                    'post' => [
                        'operationId' => 'pets.create',
                        'responses' => ['201' => []],
                    ],
                ],
                '/pets/{id}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [[
                            'name' => 'id', 'in' => 'path', 'required' => true,
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                        ]],
                        'responses' => ['204' => []],
                    ],
                ],
                '/secure' => [
                    'get' => [
                        'operationId' => 'secure.get',
                        'security' => [['api' => []]],
                        'responses' => ['204' => []],
                    ],
                ],
            ],
        ]);
    }
}
