# property-testing-openapi

Data-only OpenAPI request-case generators and PSR-7 materialization for
`rasuvaeff/property-testing-core` and `rasuvaeff/openapi-contract`.

The current pre-release slice generates valid scalar, array, and object values
from the documented JSON Schema subset, materializes OpenAPI 3 parameter styles
and JSON, form-urlencoded, or multipart request bodies, then lets the contract validator check the request
before it reaches a transport.

## Install

```bash
composer require --dev rasuvaeff/property-testing-openapi
```

## Generate And Materialize

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Random;

$contract = Contract::fromArray($document);
$operation = $contract->operation('pets.get');
$case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(42))->value;
$request = (new RequestMaterializer(new Psr17Factory(), new Psr17Factory()))->materialize($operation, $case);

$contract->validateRequest($request)->assertValid();
```

`RequestCaseData` is an associative JSON-compatible array with independent
`path`, `query`, `headers`, `cookies`, and optional `body` maps. Form bodies keep
logical values; multipart bodies keep deterministic boundaries and data-only
parts, with binary payloads represented as base64. Required
parameters and request bodies are always present; optional parameters and JSON
bodies take both present and absent branches. It does not include security
credentials and can therefore be persisted by the property corpus.

Object schemas honor `minProperties`, `maxProperties`, and boolean or
schema-valued `additionalProperties` within the generation budget.

The constructive `not` subset supports `const`, `enum`, and `type` exclusions;
other negative assertions remain fail-closed as `UnsupportedGeneration`.

## Security Credentials

Security requirements are inherited by operations; an explicit empty
`security` list means anonymous access, and an empty requirement object (`{}`)
is an anonymous alternative. `SecuritySelector` tries alternatives in
document order through a `CredentialsProviderInterface`; a provider can reject
one alternative with `CredentialsUnavailable`:

```php
$selection = (new SecuritySelector())->select($operation, $credentials);
$request = (new RequestMaterializer($requests, $streams))->materialize(
    $operation,
    $case,
    $selection['credentials'] ?? null,
);
```

`Credentials` accepts either a plain string or a list of strings for each
header, query, and cookie value. Its public maps are normalized to lists. The
credentials are applied only at materialization time, so secrets never enter
`RequestCaseData` or persisted property examples:

```php
$credentials = new Credentials(
    headers: ['Authorization' => 'Bearer token'],
    query: ['tenant' => ['public', 'fallback']],
);
```

`NegativeRequestCaseArbitrary` provides constructive negative categories. The
`forOperation()` arbitrary removes one required path, query, header, cookie, or
body component and records `misuse.kind = 'missing-required'`. The
`typeMismatchForOperation()` arbitrary replaces one required scalar
`integer`/`number`/`boolean`/`null` parameter with a deliberately invalid wire
value and records `misuse.kind = 'type'`. `enumMismatchForOperation()` similarly
uses a value outside a required scalar enum and records `misuse.kind = 'enum'`,
and `constMismatchForOperation()` uses a value different from a required scalar
`const` and records `misuse.kind = 'const'`.
`boundaryMismatchForOperation()` replaces one required `integer`/`number`
parameter with a wire value just outside its `minimum`/`maximum` bound
(honouring boolean exclusive bounds) and records `misuse.kind = 'boundary'`.
`lengthMismatchForOperation()` replaces one required `string` parameter with a
value whose length falls just outside its `minLength`/`maxLength` bound and
records `misuse.kind = 'length'`; parameters carrying `enum`, `const`,
`pattern`, or `format` are skipped because a pure length mismatch cannot be
promised there.
`formatMismatchForOperation()` replaces one required `string` parameter with a
fixed witness that provably violates its `format` (`uuid`, `email`, `ipv4`,
`uri`, `uri-reference`, `date`, `date-time`) and records
`misuse.kind = 'format'`; `url` is excluded because the validation backend does
not assert it.
`additionalPropertyForOperation()` adds one undeclared property to a required
JSON object body whose schema sets `additionalProperties: false` and records
`misuse.kind = 'additional-properties'` with the injected property name.
`mediaTypeMismatchForOperation()` keeps the schema-valid JSON body but sends it
under an undeclared Content-Type and records `misuse.kind = 'media-type'`;
operations declaring wildcard media types fail closed.
`malformedJsonForOperation()` replaces the required JSON body with a raw
malformed payload (`encoding: 'raw'`) under the declared media type and records
`misuse.kind = 'json-syntax'`.
Resulting requests are expected to
fail contract validation before a transport is called; other negative
categories remain unsupported until they have their own invalidation oracle.

Unsupported schema assertions and non-JSON request bodies throw
`UnsupportedGeneration`; they are never silently widened to arbitrary strings.

## Transports

Execution is explicit. Use `CallableTransport` for a closure or
`Psr15Transport` for an in-process PSR-15 handler:

```php
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;

$transport = new Psr15Transport(
    $handler,
    $serverRequestFactory,
    afterRequest: static fn() => $stateResetter->reset(),
);
$response = $transport->send($request);
```

Neither transport performs network I/O beyond the callable or PSR-15 boundary
it was given. Transport exceptions propagate to the caller unchanged. The
optional `afterRequest` hook runs exactly once in `finally`, including when the
handler throws. For Yii workers, pass `Yiisoft\Di\StateResetter::reset()`
through this hook while reusing the booted runner and handler; do not construct
a runner for every generated request.

## Suite

`ContractSuite` is the framework-neutral suite model: explicit operation
selection, a transport, optional credentials, and the built-in per-trial
checks.

```php
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;

$suite = ContractSuite::fromContract($contract, $requestFactory, $streamFactory)
    ->operations(['pets.get'])
    ->transport(new Psr15Transport($handler, $serverRequestFactory));

$case = $suite->validCases('pets.get')->generate(new Random(42))->value;
$suite->checkValid('pets.get', $case);
```

The default selection is empty. `operations()` adds explicit operation keys,
`allSafeOperations()` is the opt-in for every GET/HEAD operation, and
`exclude()` removes keys. An operation with an unsafe HTTP method must be
listed explicitly **and** enabled with `allowUnsafeOperations()`; a selection
naming an unsafe operation without that gate throws `SuiteConfigurationError`
instead of silently filtering it out.

`checkValid()` materializes the case (applying credentials selected through
the configured `CredentialsProviderInterface`), requires the request to be
valid before transport, sends it, and fails with `CheckFailed` on a 5xx status
or a non-conforming exchange. `checkNegative()` requires the case to carry
`misuse` metadata and to be invalid before transport, then asserts that
invalid input does not produce a 5xx. A stricter oracle is the opt-in
`RejectionPolicy` — OpenAPI itself does not promise `invalid -> 4xx`:

```php
use Rasuvaeff\PropertyTesting\OpenApi\RejectionPolicy;

$suite = $suite->rejectionPolicy(
    RejectionPolicy::rejectWith('4XX')->forOperation('legacy.get', 200),
);
```

With a policy configured, `checkNegative()` additionally fails when the
response status matches neither the default selectors nor the per-operation
override (exact codes or `NXX` ranges).

`negativeCases()` is a weighted choice among every negative category the
operation supports constructively; an operation with no constructible category
throws `UnsupportedGeneration`.

`reproduce()` renders one case as a redacted curl command. Credentials are
never applied there, so provider secrets cannot leak by construction;
`RedactionPolicy` additionally redacts named headers, query parameters,
cookies, and dot-separated JSON body paths, on top of a default header set
(`Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`). Body
previews are byte-bounded and never cut a UTF-8 sequence in half.

```php
use Rasuvaeff\PropertyTesting\OpenApi\RedactionPolicy;

echo $suite->reproduce('pets.get', $case, new RedactionPolicy(bodyPaths: ['owner.card']));
```

## Test Runner Integration

`OperationProperty` is the framework-neutral runner surface: each operation is
one test case, and each generated request is one property trial. It works
unchanged under Testo and PHPUnit because neither side needs framework hooks —
the data provider is a plain static method and the check is a plain call:

```php
use Rasuvaeff\PropertyTesting\OpenApi\OpenApiOperations;
use Rasuvaeff\PropertyTesting\OpenApi\OperationProperty;

#[Test]
final class ApiContractTest
{
    #[DataProvider('operations')]
    public function operationConforms(string $operationKey): void
    {
        OperationProperty::check(self::suite(), $operationKey, runs: 100);
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        return OpenApiOperations::keys(self::suite());
    }
}
```

`check()` runs the valid phase always and the negative phase when the
operation supports at least one constructible misuse category. A falsified
phase throws `OperationPropertyFailed` carrying the operation key, the phase,
the seed, the shrunk minimal case, and a redacted curl reproducer.

The parity is CI-verified: `composer build` runs the PHPUnit fixture suite
(`tests/PhpUnit/`, `composer test:phpunit`) alongside the Testo suite, and the
fixture passes unchanged on PHPUnit 11.5, 12, and 13.

Environment parity with the property-testing adapters: `PROPERTY_RUNS`
overrides the run count, `PROPERTY_SEED` fixes the seed unless an explicit
`seed:` argument is given (a pinned seed also disables corpus replay), and
`PROPERTY_DB` names either a directory-backed regression corpus or a shared
Redis corpus. Redis DSNs use `redis://host[:port][/key-prefix]`; the prefix
defaults to `property-testing:corpus:`. Install `ext-redis` or `predis/predis`.
Connections are opened lazily, and unsupported schemes fail closed.

See [examples](examples/README.md) for the runnable scripts.
