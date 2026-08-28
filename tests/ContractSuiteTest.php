<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\UnknownOperation;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\CheckFailed;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsProviderInterface;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsUnavailable;
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
