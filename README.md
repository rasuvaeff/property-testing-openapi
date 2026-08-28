# property-testing-openapi

Data-only OpenAPI request-case generators and PSR-7 materialization for
`rasuvaeff/property-testing-core` and `rasuvaeff/openapi-contract`.

The current pre-release slice generates valid scalar, array, and object values
from the documented JSON Schema subset, materializes OpenAPI 3 parameter styles
and JSON request bodies, then lets the contract validator check the request
before it reaches a transport.

## Install

```bash
composer require --dev rasuvaeff/property-testing-openapi
```

During this pre-release repository work, `openapi-contract` is resolved through
the local path repository declared in `composer.json`.

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
`path`, `query`, `headers`, `cookies`, and optional JSON `body` maps. Required
parameters and request bodies are always present; optional parameters and JSON
bodies take both present and absent branches. It does not include security
credentials and can therefore be persisted by the property corpus.

Object schemas honor `minProperties`, `maxProperties`, and boolean or
schema-valued `additionalProperties` within the generation budget.

## Security Credentials

Security requirements are inherited by operations and an explicit empty
requirement means anonymous access. `SecuritySelector` tries alternatives in
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

`Credentials` applies headers, query values, and cookies only at materialization
time. Secrets never enter `RequestCaseData` or persisted property examples.

`NegativeRequestCaseArbitrary` provides the first safe negative category: it
removes one required path, query, header, cookie, or body component and records
`misuse: {kind: 'missing-required', ...}`. The resulting request is expected to
fail contract validation before a transport is called; other negative
categories remain unsupported until they have their own invalidation oracle.

Unsupported schema assertions and non-JSON request bodies throw
`UnsupportedGeneration`; they are never silently widened to arbitrary strings.

## Transports

Execution is explicit. Use `CallableTransport` for a closure or
`Psr15Transport` for an in-process PSR-15 handler:

```php
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;

$transport = new Psr15Transport($handler, $serverRequestFactory);
$response = $transport->send($request);
```

Neither transport performs network I/O beyond the callable or PSR-15 boundary
it was given. Transport exceptions propagate to the caller; the future suite
layer will classify them separately from contract violations.

See [examples](examples/README.md) for the runnable script.
