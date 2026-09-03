# property-testing-openapi

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-openapi/v)](https://packagist.org/packages/rasuvaeff/property-testing-openapi)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-openapi/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-openapi)
[![Build](https://github.com/rasuvaeff/property-testing-openapi/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-openapi/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-openapi/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-openapi/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[Русская версия](README.ru.md)

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
parts, with binary payloads represented as base64. Multipart parts are scalar
or binary only — nested objects and arrays fail closed as
`UnsupportedGeneration` — and travel with the OAS default content type of the
item schema unless the Encoding Object names one. Required
parameters and request bodies are always present; optional parameters and JSON
bodies take both present and absent branches. It does not include security
credentials and can therefore be persisted by the property corpus.

An empty array or object has no form-style wire form (RFC 6570 treats it as
undefined), so the materializer omits such a parameter or form property and
the generator produces required array/object parameters and required form
properties non-empty.

Object schemas honor `minProperties`, `maxProperties`, and boolean or
schema-valued `additionalProperties` within the generation budget.

The request target is built against the operation's first effective server
(operation > path > root precedence, server variables substituted with their
defaults by `openapi-contract`): a relative server such as `/api/v1` yields a
path-only URI that stays host-agnostic, an absolute server yields an absolute
URI (`https://api.example.com:8443/v2/pets/42`). `withBaseUri()` replaces the
declared server with an explicit `scheme://host[:port][/base]` or root-relative
`/base` for a consumer environment; the contract still decides whether the
result matches, so an absolute override that contradicts every declared server
fails validation with `request.server.mismatch` instead of silently matching.
Credentials are applied after the URI is chosen and cannot change scheme or
host.

```php
$local = (new RequestMaterializer($factory, $factory))->withBaseUri('/v1');
```

The constructive `not` subset supports `const`, `enum`, and `type` exclusions;
other negative assertions remain fail-closed as `UnsupportedGeneration`.

Parameters are generated for the wire. OAS 3.0 `nullable` is dropped from a
parameter schema (an optional parameter's "null" is its absent branch; a
required one cannot carry it), together with `null` enum members. A path
parameter never leaves its template segment: every string of its value is
generated non-empty and without `/` or `\`, `enum` members that cannot be
carried are dropped, and a `uri`/`uri-reference`/`url` format fails closed.
Request bodies are generated from the request direction of their schema —
`readOnly` properties leave `properties` and `required` the way the contract
validator drops them — and document examples lose their `readOnly` members
the same way.

### Supported schema keywords

| Keyword | Generation |
|---|---|
| `type` (single or list), `const`, `enum`, `nullable` (OAS 3.0) | supported; a type list is a weighted union |
| `minimum`, `maximum`, boolean `exclusiveMinimum`/`exclusiveMaximum`, `multipleOf` | supported; a fractional bound on an integer rounds inward, an open bound steps inside by a tenth (or a quarter of a narrow window) |
| `minLength`, `maxLength` (capped at 64), `pattern` (PCRE subset) | supported |
| `format`: `uuid`, `email`, `ipv4`, `uri`, `uri-reference`, `url`, `date`, `date-time`, `password` (annotation) | supported; a length window the format cannot satisfy, or `pattern` combined with an asserted format, fails closed |
| `items`, `minItems`, `maxItems` (capped at 16), `uniqueItems` | supported; `uniqueItems` over a finite item domain smaller than `minItems` fails closed |
| `properties`, `required`, `minProperties`, `maxProperties` (capped at 16), `additionalProperties` (boolean or schema) | supported |
| `readOnly` (requests), `writeOnly` (responses) | dropped per direction |
| `anyOf`, `oneOf` (provably disjoint branches), `allOf` (mergeable branches; a branch bounding `additionalProperties` must declare every sibling property) | supported |
| `not` with `const`, `enum`, or `type` | supported; a `not` that excludes every declared type fails closed |
| `$ref`, `if`/`then`/`else`, `contains`, `prefixItems`, `patternProperties`, `propertyNames`, `unevaluatedProperties`, numeric `exclusiveMinimum`/`exclusiveMaximum`, other formats | fail closed as `UnsupportedGeneration` |

Every unsatisfiable combination the compiler can recognise is refused at
compile time; what remains probabilistic (a `pattern` that rarely matches its
length window) surfaces as `GenerationExhausted` from the generator budget.

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
`patternMismatchForOperation()` replaces one required `string` parameter with a
searched wire value that provably fails its `pattern` and records
`misuse.kind = 'pattern'`. The pattern itself is the oracle: bounded candidates
(alphabet samples and mutations of an accepted value) are checked with
`preg_match()` against the exact regex the validation backend compiles.
Parameters carrying `enum`, `const`, or `format` are skipped, the witness stays
inside the `minLength`/`maxLength` window, and a PCRE error or an exhausted
candidate/time budget fails closed instead of guessing.
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

`Psr15Transport` hands the handler what the SAPI would have populated: query
parameters from the URI, cookie parameters from the `Cookie` header, the
parsed body of `application/x-www-form-urlencoded` and `multipart/form-data`
payloads (PHP's `parse_str()` semantics for names, so `tags[]` becomes a
list), and — when a `StreamFactoryInterface` and an
`UploadedFileFactoryInterface` are passed as the fourth and fifth constructor
arguments — uploaded files for multipart parts with a filename (the
materializer names every binary part `filename="<part>"`). Without the
factories file parts are left out of the parsed body and no uploaded files
are attached. A body that needs no parsing (JSON, raw bytes) is never read by
the transport: a seekable stream is rewound, a non-seekable one is passed
through untouched. A form or multipart body on a non-seekable stream is
buffered into a fresh seekable stream through the `StreamFactoryInterface`;
without one the transport throws `LogicException` rather than handing the
handler an exhausted stream.

```php
$transport = new Psr15Transport($handler, $psr17, null, $psr17, $psr17);
```

Requests are materialized against the document's own `servers` (or the
`baseUri()` override). A transport that performs real network I/O will
therefore send generated traffic wherever an untrusted document points; run
foreign contracts only through in-process or callable transports, or pin the
target with `baseUri()`.

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

`baseUri()` materializes every request against an explicit base URI instead
of the operation's declared server — a root-relative `/v1` keeps in-process
requests host-agnostic when the document declares a production host, while an
absolute override must agree with a declared server or `checkValid()` fails
closed with `request.server.mismatch` before transport.

`checkValid()` materializes the case (applying credentials selected through
the configured `CredentialsProviderInterface`), requires the request to be
valid before transport, sends it, and fails with `CheckFailed` on a 5xx status
or a non-conforming exchange. A declared non-JSON response (`text/plain`,
`application/octet-stream`, ...) is not a violation: `openapi-contract`
treats it as opaque without a schema and validates the raw payload against a
string-typed schema; only a non-JSON media type with a schema it cannot
evaluate is reported, as `response.body.unsupported`. Present response
headers with a `schema` are part of the exchange check too: `openapi-contract`
decodes them `simple`-style and validates the value, so a server answering
`X-RateLimit-Remaining: banana` for a `type: integer` header fails with
`response.header.schema`. `checkNegative()` requires the case to carry
`misuse` metadata and to be invalid before transport, then asserts that
invalid input does not produce a 5xx. A stricter oracle is the opt-in
`RejectionPolicy` — OpenAPI itself does not promise `invalid -> 4xx`:

A `CheckFailed` raised for a validation result keeps it in `$result` and
renders every violation in its message through `openapi-contract`'s
`ValidationResultFormatter` — operation, code, location, instance path, spec
pointer, bounded expected/actual — deterministically, with header, cookie,
query and secret-like actual values redacted. `OperationPropertyFailed`
carries that text as the cause, so a falsified phase shows the complete
diagnostics without inspecting objects:

```text
Exchange for operation "pets.get" violates the contract
OpenAPI contract validation failed with 1 violation(s)
1. code: "response.body.schema"
   operation: "pets.get"
   location: "body"
   instancePath: "/name"
   specPointer: "/components/schemas/Pet"
   expected: {"type":"string"}
   actual: null
   message: "..."
```

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

`exampleCases()` derives named valid cases from the document's own
`example`/`examples` declarations (`DocumentExamples`): every parameter and
the request body contribute their named examples (`examples` map of Example
Objects with a `value`) and one unnamed example (`example`, then the Schema
Object's `example`, then the first entry of its `examples` list). One case is
produced per distinct example name across all parts, plus a case named
`example` when any part declares an unnamed one; a part without an example
under a given name falls back to its unnamed example, then to a deterministic
base case drawn with a fixed seed. The result is identical under every
`PROPERTY_SEED`, JSON-compatible, and free of credentials. Example Objects with
only `externalValue` and multipart bodies contribute nothing; an example that
cannot be represented as a wire value (a nested object in a parameter) throws
`UnsupportedGeneration`. The cases are not validated here — an example that
violates its own schema fails `checkValid()` before transport like any other
case, as a diagnosable document defect rather than a silently skipped example.

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

## Response Generation

For testing an API client without live traffic, the package generates the
responses a documented provider may return — and the ones it must not:

```php
use Rasuvaeff\PropertyTesting\OpenApi\NegativeResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;

$case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(42))->value;
$response = (new ResponseMaterializer($responseFactory, $streamFactory))->materialize($operation, $case);
$contract->validateResponse('payments.get', $response)->assertValid();
```

`ResponseCaseArbitrary::forOperation()` takes an explicit concrete status; the
Response Object is the one the contract resolves it to (exact code, then
`NXX`, then `default` — the same selection `validateResponse()` applies, via
`Operation::responseFor()`). Required response headers are always present,
optional ones take both branches, and the JSON body is generated with
`writeOnly` properties left out. `ResponseMaterializer` serializes header
values with the `simple` style like request headers — percent-encoded, a list
joined with commas — so control characters never reach the PSR-7 factory and
a comma inside an item survives the round trip. `ResponseCaseData` is JSON-compatible and
corpus-safe like its request counterpart. An undeclared status, a required
header without a schema, or a body without a JSON media type fail closed as
`UnsupportedGeneration`.

`NegativeResponseCaseArbitrary` mutates a valid case constructively:
`undeclared-status`, a `missing-required` header, and the body categories
`missing-required`, `type`, `enum`, `const`, `boundary`, `length`, `pattern`
(through the same bounded `preg_match()` oracle as request generation),
`additional-properties`, `media-type`, and `json-syntax` — on top-level
object properties, or on a scalar/array body root (`misuse.name` is `$`).
`forOperation()` is the weighted union of every constructible category.
Every case is provably invalid under `Contract::validateResponse()`, the
`misuse` metadata survives shrinking, and a category without a constructible
witness fails closed. Feed the valid cases through your client's decoder to
exercise documented optional fields and enum states, and the invalid ones to
prove the client rejects a malformed provider payload instead of mapping it.

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

The valid phase starts with the document's examples (`exampleCases()`): they
run before corpus replay and the random phase under every seed and run count,
so a point fault the document itself describes — one specific id the server
chokes on — is found on the first run instead of by chance. A failing example
throws `OperationPropertyFailed` with its name in `$example`, the case
unshrunk, `runsBeforeFailure` of zero, and the same curl reproducer:

```text
Operation "pets.get" failed the valid phase on document example "legacy": Operation "pets.get" responded with server error status 500
Case: {"operationKey":"pets.get","path":{"id":"7"},...}
Reproduce: curl -X GET '/pets/7?verbose=true'
```

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

## Coverage Report

`OperationCoverage` answers the question a first run leaves open: which of
the selected operations actually ran a trial. Attach one caller-owned record
to the suite; every `checkValid()`/`checkNegative()` trial records its
operation key and the observed response status, and `coverageReport()`
restricts the record to the resolved selection:

```php
use Rasuvaeff\PropertyTesting\OpenApi\OperationCoverage;
use Testo\Lifecycle\AfterClass;

#[Test]
final class ApiContractTest
{
    private static ?OperationCoverage $coverage = null;

    #[DataProvider('operations')]
    public function operationConforms(string $operationKey): void
    {
        OperationProperty::check(self::suite(), $operationKey, runs: 100);
    }

    #[AfterClass]
    public static function reportCoverage(): void
    {
        $report = self::suite()->coverageReport();
        file_put_contents(__DIR__ . '/../build/openapi-coverage.json', $report->toJson());
        $report->assertComplete();
    }

    private static function suite(): ContractSuite
    {
        return ContractSuite::fromContract($contract, $factory, $factory)
            ->allSafeOperations()
            ->coverage(self::$coverage ??= new OperationCoverage())
            ->transport($transport);
    }
}
```

Hold the record in a static property: a suite rebuilt per test case must
share it, and suites derived by further fluent calls (`exclude()`,
`transport()`) share it automatically. Under PHPUnit the same shape uses
`tearDownAfterClass()`; `composer build` runs that fixture too.

`CoverageReport` lists `selected`, `covered`, and `uncovered` operation keys
in selection order plus the per-operation status distribution — `statuses`,
e.g. `{"pets.get": {"204": 100, "400": 100}}` — and renders as stable JSON
through `toArray()`/`toJson()`. Operations exercised outside the selection are
not reported. A 5xx counts as exercised: the trial ran, the check failing is a
separate outcome.

The gate is opt-in: `assertComplete()` throws `CoverageIncomplete` (report
attached in `$report`) when a selected operation never ran a trial — the
typical cause is a data provider built from a different selection than the
one under test. Response statuses are diagnostics only, never a gate: a
request generator cannot make the server produce every documented 404 or 409.
The record is process-local by design; under process isolation or parallel
workers write one JSON report per process and merge the lists outside the
package.

See [examples](examples/README.md) for the runnable scripts.
