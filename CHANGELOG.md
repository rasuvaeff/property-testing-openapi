# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
