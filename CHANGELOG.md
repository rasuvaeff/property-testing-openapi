# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.4.0 — 2026-09-03

- `Psr15Transport` no longer consumes a non-seekable request body before the
  handler sees it (#36). A body that needs no parsing is not read at all: a
  seekable stream is rewound, a non-seekable one is passed through untouched.
  A form or multipart body on a non-seekable stream is buffered into a fresh
  seekable stream through the `StreamFactoryInterface`; without one the
  transport throws `LogicException` instead of handing over an exhausted
  stream. Previously every body was read up front and a non-seekable stream
  reached the handler at EOF.
- Requires `rasuvaeff/property-testing-core` `^0.5 || ^0.6` (was `^0.4`, no
  longer supported) and, for development, `rasuvaeff/property-testing-testo`
  `^0.7` and `rasuvaeff/understudy-testo` `^0.2` (understudy core `^0.5`).

## 0.3.0 — 2026-09-03

- String path parameters no longer break route matching (#27): every string
  of a path value is generated non-empty and without `/` or `\` at any
  nesting level, `enum` members no template segment can carry are dropped
  (an all-unsafe enum or const fails closed), and a `uri`/`uri-reference`/
  `url` path format fails closed at compile time.
- `readOnly` properties are no longer generated into request bodies (#28):
  `Internal\RequestSchemas` mirrors `ResponseSchemas` for the request
  direction (JSON, form and multipart bodies), and document examples lose
  their `readOnly` members the same way.
- OAS 3.0 `nullable` parameters never travel as the string `null` (#29):
  `nullable`, `null` enum members and a `null` const are dropped from
  parameter schemas at every nesting level (null is the absent branch of an
  optional parameter).
- `allOf` merge fails closed when a branch bounding `additionalProperties`
  does not declare every property a sibling branch adds (#30); the merged
  generator is checked against the contract validator in the test suite.
- Declared non-JSON responses (`text/plain`, `application/octet-stream`, …)
  are no longer red on every trial (#31): requires `rasuvaeff/openapi-contract`
  `^0.2.1`, which treats them as opaque or validates the raw payload against
  a string-typed schema; only `response.body.unsupported` remains a
  violation.
- `Psr15Transport` populates the server request like the SAPI (#32):
  `queryParams`, `cookieParams`, `parsedBody` for form-urlencoded and
  multipart bodies, and `uploadedFiles` for multipart parts with a filename
  when a `StreamFactoryInterface` and an `UploadedFileFactoryInterface` are
  passed as the new optional fourth and fifth constructor arguments. The
  materializer emits `filename="<part>"` for binary multipart parts.
- Unsatisfiable schema combinations fail closed at compile time instead of
  exhausting the generator at run time (#33): `pattern` combined with an
  asserted `format`; a length window outside the format's length band
  (including `maxLength: 0`); `uniqueItems` over a finite item domain smaller
  than `minItems`; a `not` type predicate covering every declared type.
  Fractional bounds on integers now round inward instead of failing, and an
  open number bound steps inside by a quarter of a narrow window instead of a
  fixed 0.1.
- Response headers are serialized with the `simple` style (#34):
  percent-encoded values, lists joined with commas, so control characters no
  longer throw from the PSR-7 factory and commas inside an item survive.
- Fail-closed strictness: the direction views no longer drop malformed
  `properties` entries silently; the compiler reports them as before.
- `SchemaArbitraryCompiler`, `SecuritySelector`, `RequestReproducer` and
  `DocumentExamples` are now `@internal`; reach them through
  `RequestCaseArbitrary`/`ResponseCaseArbitrary` and `ContractSuite`.
- README: supported schema keyword matrix, transport parsed-body semantics,
  and a note that generated traffic targets the document's own `servers`.
- Dev/packaging: `minimum-stability: dev` removed; `testo/testo` `^0.10.39`.

## 0.2.1 — 2026-09-02

- Operation coverage report: `OperationCoverage` attached with
  `ContractSuite::coverage()` records every exercised operation and observed
  response status; `coverageReport()` yields a `CoverageReport` with
  `selected`/`covered`/`uncovered` keys in selection order and the
  per-operation status distribution, stable `toArray()`/`toJson()`, and the
  opt-in `assertComplete()` gate throwing `CoverageIncomplete`. Statuses are
  diagnostics only; the record is process-local by design.

## 0.2.0 — 2026-09-01

- Generate `application/x-www-form-urlencoded` and `multipart/form-data`
  request bodies: form cases keep the logical object value and serialize with
  the form-style Encoding Object semantics; multipart cases carry
  deterministic boundaries and data-only scalar/binary parts (binary as
  base64), with per-part content types and required Encoding Object headers.
  Nested multipart objects and arrays fail closed.
- `Credentials` accepts plain strings alongside `list<string>` in its header,
  query, and cookie maps and normalizes the public properties to lists.
- `Psr15Transport` gained an optional `afterRequest` reset hook, executed
  exactly once in `finally` — including when the handler throws — with the
  Yii `StateResetter::reset()` recipe documented and a runnable example.
- `PROPERTY_DB` regression corpora resolve directories and `redis://` DSNs
  with the same semantics as the property-testing adapters (prefix, lazy
  ext-redis/predis clients, fail-closed unsupported schemes).
- Internal: dead generator code removed and the negative-category and
  schema-compiler god classes decomposed into `Internal\Negative\*` and
  `Internal\Compile\*`; no public API change from these.
- Stop generating `null` for `nullable: true` schemas whose `enum` does not
  list `null` (and for a non-null `const`): the validator rejects such a
  null, so the valid phase produced provably invalid values. Found by the
  payments-stripe response-contract fixture on a nullable refund `status`.
- Generate expected provider responses for API-client tests:
  `ResponseCaseArbitrary::forOperation(Operation, int $status)` produces
  JSON-compatible, corpus-safe response cases for the Response Object the
  status resolves to (exact → `NXX` → `default`, via the new
  `Operation::responseFor()` of `openapi-contract` 0.2), with required
  headers always present and `writeOnly` properties left out;
  `ResponseMaterializer` builds the PSR-7 response.
  `NegativeResponseCaseArbitrary` adds the constructive invalid categories
  `undeclared-status`, missing required header, and body
  `missing-required`/`type`/`enum`/`const`/`boundary`/`length`/`pattern`/
  `additional-properties`/`media-type`/`json-syntax` (top-level object
  properties or the scalar/array body root), each provably invalid under
  `Contract::validateResponse()` with misuse metadata that survives
  shrinking.
- Render complete contract diagnostics: `CheckFailed::invalidGeneratedRequest()`
  and `exchangeViolations()` keep the structured `ValidationResult` in the new
  `$result` property and format every violation through `openapi-contract`'s
  `ValidationResultFormatter` (operation, code, location, instance path, spec
  pointer, bounded expected/actual, sensitive actual values redacted) instead
  of a one-line summary of the first violation; `OperationPropertyFailed`
  carries the full text as its cause.
- Run the document's `example`/`examples` as the deterministic example phase:
  `DocumentExamples` / `ContractSuite::exampleCases()` derive one named valid
  case per example name across parameters and the request body (plus an
  `example` case for unnamed ones, with schema-level `example`/`examples[0]`
  fallbacks), and `OperationProperty::check()` runs them before corpus
  replay and the random phase under every seed. A failing example throws
  `OperationPropertyFailed` with the new `$example` property, the case
  unshrunk, and the curl reproducer; an example violating its own schema is
  reported as a document defect instead of being skipped.
- Fix form and multipart request bodies without an Encoding Object failing
  closed: an absent `encoding` arrived as an empty array and was rejected as
  a list. Form and multipart generation is now checked against the core
  oracle by property tests, which also pinned: an empty array/object has no
  form-style wire form (RFC 6570), so the materializer omits such a
  parameter or form property and required array/object parameters and form
  properties are generated non-empty; multipart part content types follow
  the item schema's OAS default, and nested multipart arrays fail closed as
  `UnsupportedGeneration` like nested objects.
- Require `rasuvaeff/openapi-contract` `^0.2`.
- Materialize requests against the operation's first effective server
  (`Operation::$servers`): a relative server yields a path-only, host-agnostic
  URI, an absolute server yields an absolute URI with substituted variable
  defaults, so documents with base paths or multiple hosts generate requests
  the same contract matches. `RequestMaterializer::withBaseUri()` /
  `ContractSuite::baseUri()` replace the declared server with an explicit
  base URI for in-process or consumer environments; an absolute override that
  contradicts every declared server fails closed with
  `request.server.mismatch` before transport. Hand-built `Operation`s without
  `servers` keep using `serverBases[0]`.
- Constructive `pattern` negative category:
  `NegativeRequestCaseArbitrary::patternMismatchForOperation()` searches a
  counter-witness among bounded alphabet samples and mutations of an accepted
  value, using the pattern itself as the `preg_match()` oracle, and fails
  closed on a PCRE error or an exhausted candidate/time budget.

## 0.1.0 — 2026-08-29

- Initial release: data-only OpenAPI request-case generators with a fail-closed
  schema support matrix, PSR-17 materialization of OpenAPI 3 parameter styles
  and JSON bodies, and pre-transport validity oracles.
- Ten constructive negative categories with misuse metadata and
  misuse-preserving shrinking.
- Framework-neutral `ContractSuite`: explicit operation selection, a double
  opt-in for unsafe methods, transports, credentials selection, no-5xx checks,
  opt-in `RejectionPolicy`, and a redacted curl reproducer.
- `OperationProperty`/`OpenApiOperations` runner surface working unchanged
  under Testo and PHPUnit 11.5–13, with `PROPERTY_RUNS`/`PROPERTY_SEED`/
  `PROPERTY_DB` environment parity.
