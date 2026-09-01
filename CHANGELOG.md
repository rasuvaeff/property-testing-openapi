# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
