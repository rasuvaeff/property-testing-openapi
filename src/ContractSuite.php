<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ConstructibleCategories;

/**
 * Framework-neutral suite model: explicit operation selection, transport,
 * credentials, and the built-in per-trial checks.
 *
 * The default selection is empty; every executed operation is either listed
 * explicitly or added by the {@see allSafeOperations()} opt-in. An unsafe
 * HTTP method additionally requires {@see allowUnsafeOperations()} —
 * a selection that names an unsafe operation without that gate fails closed
 * instead of silently filtering it out.
 *
 * @psalm-type CaseData = array{
 *     operationKey: string,
 *     path: array<string, string|list<string>|array<string, string>>,
 *     query: array<string, string|list<string>|array<string, string>>,
 *     headers: array<string, string|list<string>|array<string, string>>,
 *     cookies: array<string, string|list<string>|array<string, string>>,
 *     body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed},
 *     misuse: null|array{kind: non-empty-string, location: non-empty-string, name: string},
 * }
 *
 * @api
 */
final class ContractSuite
{
    private const array SAFE_METHODS = ['GET', 'HEAD'];

    /** @var list<string> */
    private array $selected = [];

    /** @var list<string> */
    private array $excluded = [];

    private bool $unsafeAllowed = false;

    private ?TransportInterface $transport = null;

    private ?CredentialsProviderInterface $credentials = null;

    private ?RejectionPolicy $rejectionPolicy = null;

    private ?OperationCoverage $coverage = null;

    private function __construct(
        private readonly Contract $contract,
        private RequestMaterializer $materializer,
        private readonly RequestCaseArbitrary $valid = new RequestCaseArbitrary(),
        private readonly NegativeRequestCaseArbitrary $negative = new NegativeRequestCaseArbitrary(),
        private readonly DocumentExamples $examples = new DocumentExamples(),
    ) {}

    public static function fromContract(Contract $contract, RequestFactoryInterface $requests, StreamFactoryInterface $streams): self
    {
        return new self($contract, new RequestMaterializer($requests, $streams));
    }

    /** @param list<string> $operationKeys */
    public function operations(array $operationKeys): self
    {
        $suite = clone $this;
        foreach ($operationKeys as $key) {
            $this->contract->operation($key);
            $suite->selected[] = $key;
        }

        return $suite;
    }

    /**
     * Adds every GET/HEAD operation of the document to the selection.
     */
    public function allSafeOperations(): self
    {
        $suite = clone $this;
        foreach ($this->contract->operations() as $operation) {
            if (in_array($operation->method, self::SAFE_METHODS, strict: true)) {
                $suite->selected[] = $operation->key;
            }
        }

        return $suite;
    }

    /** @param list<string> $operationKeys */
    public function exclude(array $operationKeys): self
    {
        $suite = clone $this;
        foreach ($operationKeys as $key) {
            $suite->excluded[] = $key;
        }

        return $suite;
    }

    public function allowUnsafeOperations(): self
    {
        $suite = clone $this;
        $suite->unsafeAllowed = true;

        return $suite;
    }

    /**
     * Materializes every request against this base URI instead of the
     * operation's declared server. A root-relative override keeps the request
     * host-agnostic for in-process transports; an absolute one must agree
     * with a declared server, or the built-in checks fail closed with
     * `request.server.mismatch` before transport.
     */
    public function baseUri(string $baseUri): self
    {
        $suite = clone $this;
        $suite->materializer = $this->materializer->withBaseUri($baseUri);

        return $suite;
    }

    public function transport(TransportInterface $transport): self
    {
        $suite = clone $this;
        $suite->transport = $transport;

        return $suite;
    }

    public function credentials(CredentialsProviderInterface $provider): self
    {
        $suite = clone $this;
        $suite->credentials = $provider;

        return $suite;
    }

    /**
     * Opt-in negative oracle: without a policy an accepted invalid request is
     * not a contract bug, only a 5xx is.
     */
    public function rejectionPolicy(RejectionPolicy $policy): self
    {
        $suite = clone $this;
        $suite->rejectionPolicy = $policy;

        return $suite;
    }

    /**
     * Records every exercised operation and observed response status into a
     * caller-owned {@see OperationCoverage}. Suites derived from this one by
     * further fluent calls share the same record.
     */
    public function coverage(OperationCoverage $coverage): self
    {
        $suite = clone $this;
        $suite->coverage = $coverage;

        return $suite;
    }

    /**
     * The configured coverage record restricted to the resolved selection.
     */
    public function coverageReport(): CoverageReport
    {
        if (!$this->coverage instanceof OperationCoverage) {
            throw new SuiteConfigurationError('No coverage record is configured; call coverage() before requesting a report');
        }

        return $this->coverage->report($this->operationKeys());
    }

    /**
     * The resolved selection in insertion order, with the unsafe gate applied.
     *
     * @return list<string>
     */
    public function operationKeys(): array
    {
        $keys = [];
        foreach ($this->selected as $key) {
            if (in_array($key, $this->excluded, strict: true) || in_array($key, $keys, strict: true)) {
                continue;
            }
            $operation = $this->contract->operation($key);
            if (!$this->unsafeAllowed && !in_array($operation->method, self::SAFE_METHODS, strict: true)) {
                throw new SuiteConfigurationError(sprintf('Operation "%s" uses unsafe method %s; call allowUnsafeOperations() to include it', $key, $operation->method));
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed},
     *     misuse: null,
     * }>
     */
    public function validCases(string $operationKey): ArbitraryInterface
    {
        return $this->valid->forOperation($this->requireSelected($operationKey));
    }

    /**
     * Named valid cases derived from the document's `example`/`examples`
     * declarations (see {@see DocumentExamples}); empty when the operation
     * declares none. {@see OperationProperty} runs them before the random
     * phase; each one is still checked with {@see checkValid()}.
     *
     * @return array<string, CaseData>
     */
    public function exampleCases(string $operationKey): array
    {
        return $this->examples->forOperation($this->requireSelected($operationKey));
    }

    /**
     * Weighted choice among every negative category the operation supports
     * constructively; an operation with no constructible category fails
     * closed with {@see UnsupportedGeneration}.
     */
    public function negativeCases(string $operationKey): ArbitraryInterface
    {
        $operation = $this->requireSelected($operationKey);
        $factories = [
            fn(): ArbitraryInterface => $this->negative->forOperation($operation),
            fn(): ArbitraryInterface => $this->negative->typeMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->enumMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->constMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->boundaryMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->lengthMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->formatMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->patternMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->additionalPropertyForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->mediaTypeMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->partContentTypeMismatchForOperation($operation),
            fn(): ArbitraryInterface => $this->negative->malformedJsonForOperation($operation),
        ];

        return ConstructibleCategories::anyOf($factories, sprintf('Operation "%s" supports no constructible negative case category', $operation->key));
    }

    /**
     * Runs the built-in checks for one valid trial: the materialized request
     * must validate before transport, the response must not be a 5xx, and the
     * whole exchange must conform to the contract.
     *
     * @param CaseData $case
     */
    public function checkValid(string $operationKey, array $case): void
    {
        if ($case['misuse'] !== null) {
            throw new \InvalidArgumentException('A valid check requires a case without misuse metadata');
        }
        $operation = $this->requireSelected($operationKey);
        $request = $this->materialize($operation, $case);

        $result = $this->contract->validateRequest($request);
        if (!$result->isValid()) {
            throw CheckFailed::invalidGeneratedRequest($operation->key, $result);
        }

        $response = $this->send($operation, $request);
        if ($response->getStatusCode() >= 500) {
            throw CheckFailed::serverError($operation->key, $response->getStatusCode());
        }

        $exchange = $this->contract->validateExchange($request, $response);
        if (!$exchange->isValid()) {
            throw CheckFailed::exchangeViolations($operation->key, $exchange);
        }
    }

    /**
     * Runs the built-in checks for one negative trial: the materialized
     * request must be invalid before transport, and invalid input must not
     * produce a 5xx. Any stricter rejection oracle is a separate opt-in
     * policy, not implied by OpenAPI.
     *
     * @param CaseData $case
     */
    public function checkNegative(string $operationKey, array $case): void
    {
        if ($case['misuse'] === null) {
            throw new \InvalidArgumentException('A negative check requires a case with misuse metadata');
        }
        $operation = $this->requireSelected($operationKey);
        $request = $this->materialize($operation, $case);

        if ($this->contract->validateRequest($request)->isValid()) {
            throw CheckFailed::unexpectedlyValidRequest($operation->key);
        }

        $response = $this->send($operation, $request);
        if ($response->getStatusCode() >= 500) {
            throw CheckFailed::serverError($operation->key, $response->getStatusCode());
        }
        if ($this->rejectionPolicy instanceof RejectionPolicy && !$this->rejectionPolicy->accepts($operation->key, $response->getStatusCode())) {
            throw CheckFailed::notRejected($operation->key, $response->getStatusCode());
        }
    }

    /**
     * Redacted curl reproducer for one case of a selected operation.
     * Credentials are never applied here.
     *
     * @param CaseData $case
     */
    public function reproduce(string $operationKey, array $case, RedactionPolicy $policy = new RedactionPolicy()): string
    {
        return (new RequestReproducer($this->materializer))->curl($this->requireSelected($operationKey), $case, $policy);
    }

    private function requireSelected(string $operationKey): Operation
    {
        if (!in_array($operationKey, $this->operationKeys(), strict: true)) {
            throw new SuiteConfigurationError(sprintf('Operation "%s" is not part of the suite selection', $operationKey));
        }

        return $this->contract->operation($operationKey);
    }

    private function send(Operation $operation, RequestInterface $request): ResponseInterface
    {
        if (!$this->transport instanceof TransportInterface) {
            throw new SuiteConfigurationError('A transport must be configured before checks run');
        }
        $response = $this->transport->send($request);
        $this->coverage?->record($operation->key, $response->getStatusCode());

        return $response;
    }

    /** @param CaseData $case */
    private function materialize(Operation $operation, array $case): RequestInterface
    {
        return $this->materializer->materialize($operation, $case, $this->credentialsFor($operation));
    }

    private function credentialsFor(Operation $operation): ?Credentials
    {
        if ($operation->security === []) {
            return null;
        }
        $provider = $this->credentials ?? new class implements CredentialsProviderInterface {
            #[\Override]
            public function provide(SecurityRequirement $requirement): Credentials
            {
                throw new CredentialsUnavailable('No credentials provider is configured');
            }
        };
        $selection = (new SecuritySelector())->select($operation, $provider);

        return $selection === null ? null : $selection['credentials'];
    }
}
