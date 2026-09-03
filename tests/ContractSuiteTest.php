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
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\CheckFailed;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsProviderInterface;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsUnavailable;
use Rasuvaeff\PropertyTesting\OpenApi\OperationCoverage;
use Rasuvaeff\PropertyTesting\OpenApi\RejectionPolicy;
use Rasuvaeff\PropertyTesting\OpenApi\SuiteConfigurationError;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ZooContracts;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
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

    public function checkFailedRendersEveryViolationAndKeepsTheStructuredResult(): void
    {
        $result = new ValidationResult([
            new Violation(code: 'request.invalid', operation: 'pets.get', location: 'query', instancePath: 'q', specPointer: '/paths/pets', expected: 'string', actual: 1, message: 'bad query'),
            new Violation(code: 'response.body.schema', operation: 'pets.get', location: 'body', instancePath: '/name', specPointer: '/components/schemas/Pet', expected: ['type' => 'string'], actual: null, message: 'bad body'),
        ]);

        $exchange = CheckFailed::exchangeViolations('pets.get', $result);
        $request = CheckFailed::invalidGeneratedRequest('pets.get', $result);

        Assert::same($exchange->result, $result);
        Assert::same($request->result, $result);
        Assert::same($exchange->getMessage(), implode("\n", [
            'Exchange for operation "pets.get" violates the contract',
            'OpenAPI contract validation failed with 2 violation(s)',
            '1. code: "request.invalid"',
            '   operation: "pets.get"',
            '   location: "query"',
            '   instancePath: "q"',
            '   specPointer: "/paths/pets"',
            '   expected: "string"',
            '   actual: "[redacted]"',
            '   message: "bad query"',
            '2. code: "response.body.schema"',
            '   operation: "pets.get"',
            '   location: "body"',
            '   instancePath: "/name"',
            '   specPointer: "/components/schemas/Pet"',
            '   expected: {"type":"string"}',
            '   actual: null',
            '   message: "bad body"',
        ]));
        Assert::same(strtok($request->getMessage(), "\n"), 'Generated request for operation "pets.get" is invalid before transport');
        Assert::string($request->getMessage())->contains('2. code: "response.body.schema"');
        Assert::same(CheckFailed::serverError('pets.get', 503)->result, null);
        Assert::same(CheckFailed::exchangeViolations('pets.get', new ValidationResult())->getMessage(), 'Exchange for operation "pets.get" violates the contract');
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

    public function recordsExercisedOperationsAndObservedStatusesIntoTheCoverageRecord(): void
    {
        $coverage = new OperationCoverage();
        $suite = $this->suite()
            ->operations(['pets.list', 'pets.get', 'secure.get'])
            ->coverage($coverage)
            ->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface
                => $request->getUri()->getPath() === '/pets' ? new Response(204) : new Response(400)));

        $suite->checkValid('pets.list', $suite->validCases('pets.list')->generate(new Random(1))->value);
        $suite->checkValid('pets.list', $suite->validCases('pets.list')->generate(new Random(2))->value);
        $suite->checkNegative('pets.get', $suite->negativeCases('pets.get')->generate(new Random(3))->value);

        $report = $suite->coverageReport();
        Assert::same($report->selected, ['pets.list', 'pets.get', 'secure.get']);
        Assert::same($report->covered, ['pets.list', 'pets.get']);
        Assert::same($report->uncovered, ['secure.get']);
        Assert::same($report->statuses, ['pets.list' => [204 => 2], 'pets.get' => [400 => 1]]);
    }

    public function recordsAServerErrorAsAnExercisedOperation(): void
    {
        $coverage = new OperationCoverage();
        $suite = $this->suite()
            ->operations(['pets.get'])
            ->coverage($coverage)
            ->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(503)));

        try {
            $suite->checkValid('pets.get', $suite->validCases('pets.get')->generate(new Random(5))->value);
            Assert::true(actual: false, message: 'Expected CheckFailed');
        } catch (CheckFailed) {
        }

        Assert::same($suite->coverageReport()->statuses, ['pets.get' => [503 => 1]]);
    }

    public function derivedSuitesShareTheCoverageRecordAndReportTheirOwnSelection(): void
    {
        $coverage = new OperationCoverage();
        $base = $this->suite()
            ->allSafeOperations()
            ->coverage($coverage)
            ->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(204)));
        $narrow = $base->exclude(['secure.get']);

        $narrow->checkValid('pets.list', $narrow->validCases('pets.list')->generate(new Random(7))->value);

        Assert::same($narrow->coverageReport()->uncovered, ['pets.get']);
        Assert::same($base->coverageReport()->uncovered, ['pets.get', 'secure.get']);
        Assert::same($coverage->exercised(), ['pets.list']);
    }

    public function coverageConfigurationDoesNotMutateTheOriginalSuite(): void
    {
        $original = $this->suite()->operations(['pets.get']);
        $configured = $original->coverage(new OperationCoverage());

        Assert::same($configured->coverageReport()->uncovered, ['pets.get']);
        Expect::exception(SuiteConfigurationError::class);
        $original->coverageReport();
    }

    public function coverageReportRequiresAConfiguredRecord(): void
    {
        Expect::exception(SuiteConfigurationError::class);

        $this->suite()->operations(['pets.get'])->coverageReport();
    }

    public function checksRunWithoutACoverageRecord(): void
    {
        $suite = $this->suite()
            ->operations(['pets.get'])
            ->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(204)));

        $suite->checkValid('pets.get', $suite->validCases('pets.get')->generate(new Random(11))->value);

        Assert::true(actual: true);
    }

    /**
     * The zoo: every schema feature the valid phase has to get right runs
     * end to end — materialize, validate before transport, transport, validate
     * the exchange — with the contract as the oracle.
     *
     * @param array{key: string, case: array<string, mixed>} $tagged
     */
    #[Property(runs: 240, generators: [ZooContracts::class, 'taggedCase'])]
    public function zooValidCasesPassTheBuiltInChecks(array $tagged): void
    {
        $key = $tagged['key'];
        /** @var array{operationKey: string, path: array<string, string|list<string>|array<string, string>>, query: array<string, string|list<string>|array<string, string>>, headers: array<string, string|list<string>|array<string, string>>, cookies: array<string, string|list<string>|array<string, string>>, body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed}, misuse: null} $case */
        $case = $tagged['case'];
        foreach (ZooContracts::VALID_OPERATIONS as $operation) {
            Classify::when(condition: $key === $operation, label: $operation);
        }
        $suite = $key === 'search.get' ? ZooContracts::legacySuite() : ZooContracts::suite();

        $suite->checkValid($key, $case);

        if ($key === 'strings.get') {
            $key1 = $case['path']['key'];
            Assert::true(is_string($key1) && $key1 !== '' && !str_contains($key1, '/') && !str_contains($key1, '\\'));
            Classify::cover(condition: is_string($key1) && preg_match('/[^\x20-\x7e]/', $key1) === 1, label: 'non-ASCII path key', minPercent: 1.0);
            $tags = $case['path']['tag'];
            Assert::true(is_array($tags) && $tags !== []);
            foreach (is_array($tags) ? $tags : [] as $tag) {
                Assert::true(is_string($tag) && $tag !== '' && !str_contains($tag, '/') && !str_contains($tag, '\\'));
            }
        }
        if ($key === 'enum.get') {
            Assert::same($case['path']['mode'], 'ok');
        }
        if ($key === 'users.create') {
            $value = $case['body']['value'] ?? null;
            Assert::true(is_array($value) && !array_key_exists('id', $value));
            Assert::true(is_array($value) && is_array($value['profile']) && !array_key_exists('createdAt', $value['profile']));
            foreach (is_array($value) && is_array($value['history'] ?? null) ? $value['history'] : [] as $entry) {
                Assert::true(is_array($entry) && !array_key_exists('at', $entry));
            }
            Classify::cover(condition: is_array($value) && array_key_exists('history', $value), label: 'history present', minPercent: 1.0);
        }
        if ($key === 'search.get') {
            Assert::true(in_array($case['path']['scope'], ['all', 'mine'], strict: true));
            Assert::true($case['query']['limit'] !== 'null');
            Classify::cover(condition: array_key_exists('cursor', $case['query']), label: 'nullable cursor present', minPercent: 1.0);
            Classify::cover(condition: !array_key_exists('cursor', $case['query']), label: 'nullable cursor absent', minPercent: 1.0);
        }
    }

    /**
     * One fixed case per zoo operation runs before the random phase, so
     * every operation is exercised deterministically under any seed.
     *
     * @return iterable<string, array{array{key: string, case: array<string, mixed>}}>
     */
    public static function zooValidCasesPassTheBuiltInChecksExamples(): iterable
    {
        foreach (ZooContracts::taggedExamples() as $key => $tagged) {
            yield $key => [$tagged];
        }
    }

    public function zooOperationsTheGeneratorCannotServeFailClosedAtSelection(): void
    {
        $factory = new Psr17Factory();
        $suite = ContractSuite::fromContract(ZooContracts::contract(), $factory, $factory)
            ->operations(['uuid.get', 'links.get', 'conflict.create'])
            ->allowUnsafeOperations();

        foreach ([
            'uuid.get' => 'format "uuid" cannot satisfy the length window',
            'links.get' => 'path parameter format "uri" always carries a slash',
            'conflict.create' => 'allOf branch bounding additionalProperties cannot admit sibling property "b"',
        ] as $key => $message) {
            try {
                $suite->validCases($key);
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration $exception) {
                Assert::same($exception->getMessage(), 'Unsupported OpenAPI schema generation: ' . $message);
            }
        }
    }

    public function zooExampleCasesDropReadOnlyMembersAndPassTheChecks(): void
    {
        $suite = ZooContracts::suite();

        $examples = $suite->exampleCases('users.create');

        Assert::same(array_keys($examples), ['example']);
        $value = $examples['example']['body']['value'] ?? null;
        Assert::same($value, ['name' => 'Ann', 'profile' => ['slug' => 'ann'], 'history' => [['note' => 'hi']]]);
        $suite->checkValid('users.create', $examples['example']);
    }

    public function zooDeclaredNonJsonResponsesAreNotViolations(): void
    {
        $suite = ZooContracts::suite();

        foreach (['health.get', 'version.get', 'files.get'] as $key) {
            $suite->checkValid($key, $suite->validCases($key)->generate(new Random(3))->value);
        }

        $tooLong = ZooContracts::suite()->transport(new CallableTransport(static fn(RequestInterface $request): ResponseInterface => new Response(200, ['Content-Type' => 'text/plain'], 'v1.2.3-longer')));

        try {
            $tooLong->checkValid('version.get', $tooLong->validCases('version.get')->generate(new Random(3))->value);
            Assert::true(actual: false, message: 'Expected a check failure');
        } catch (CheckFailed $failure) {
            Assert::same($failure->result?->violations[0]->code, 'response.body.schema');
        }
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


    public function exampleCasesRequireASelectedOperation(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['get' => [
                'operationId' => 'pets.get',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'example' => 3]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $suite = ContractSuite::fromContract($contract, $factory, $factory);

        Assert::same(array_keys($suite->operations(['pets.get'])->exampleCases('pets.get')), ['example']);

        Expect::exception(SuiteConfigurationError::class);

        $suite->exampleCases('pets.get');
    }

    public function baseUriOverrideKeepsInProcessRequestsHostAgnostic(): void
    {
        $contract = $this->absoluteServerContract();
        $seen = [];
        $factory = new Psr17Factory();
        $suite = ContractSuite::fromContract($contract, $factory, $factory)
            ->operations(['pets.get'])
            ->baseUri('/v1')
            ->transport(new CallableTransport(static function (RequestInterface $request) use (&$seen): ResponseInterface {
                $seen[] = (string) $request->getUri();

                return new Response(204);
            }));

        $suite->checkValid('pets.get', ['operationKey' => 'pets.get', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null]);

        Assert::same($seen, ['/v1/pets/7']);
    }

    public function baseUriLeavesTheOriginalSuiteUntouched(): void
    {
        $contract = $this->absoluteServerContract();
        $seen = [];
        $factory = new Psr17Factory();
        $transport = new CallableTransport(static function (RequestInterface $request) use (&$seen): ResponseInterface {
            $seen[] = (string) $request->getUri();

            return new Response(204);
        });
        $original = ContractSuite::fromContract($contract, $factory, $factory)->operations(['pets.get'])->transport($transport);
        $original->baseUri('/v1');

        $original->checkValid('pets.get', ['operationKey' => 'pets.get', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null]);

        Assert::same($seen, ['https://api.example.com/v1/pets/7']);
    }

    public function anAbsoluteBaseUriContradictingTheContractFailsClosedBeforeTransport(): void
    {
        $contract = $this->absoluteServerContract();
        $factory = new Psr17Factory();
        $sent = false;
        $suite = ContractSuite::fromContract($contract, $factory, $factory)
            ->operations(['pets.get'])
            ->baseUri('http://localhost:8080/v1')
            ->transport(new CallableTransport(static function () use (&$sent): ResponseInterface {
                $sent = true;

                return new Response(204);
            }));

        try {
            $suite->checkValid('pets.get', ['operationKey' => 'pets.get', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null]);
            Assert::true(actual: false, message: 'Expected the mismatching base URI to fail closed');
        } catch (CheckFailed $failure) {
            Assert::string($failure->getMessage())->contains('request.server.mismatch');
        }

        Assert::false($sent);
    }

    private function absoluteServerContract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://api.example.com/v1']],
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                        'responses' => ['204' => []],
                    ],
                ],
            ],
        ]);
    }
}
