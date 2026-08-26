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
`path`, `query`, `headers`, `cookies`, and optional JSON `body` maps. It does
not include security credentials and can therefore be persisted by the property
corpus.

Unsupported schema assertions and non-JSON request bodies throw
`UnsupportedGeneration`; they are never silently widened to arbitrary strings.

See [examples](examples/README.md) for the runnable script.
