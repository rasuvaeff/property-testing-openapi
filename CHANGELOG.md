# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
