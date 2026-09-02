# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
