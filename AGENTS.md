# AGENTS.md - property-testing-openapi

This package generates data-only OpenAPI request cases and materializes them
as PSR-7 requests. It is pre-release work for milestone 3 of the monorepo
OpenAPI contract plan.

## Rules

- Keep `RequestCaseData` JSON-compatible. It must never contain PSR-7 objects,
  credentials, closures, or application DTOs.
- Unsupported generation and serialization semantics fail closed. Do not use a
  generic string fallback for a schema assertion that is not implemented.
- A materialized valid case must pass `Contract::validateRequest()` before a
  transport may observe it.
- Keep parameter serialization location-aware. A path value must not escape its
  template segment after percent decoding.
- Preserve the public documentation in `README.md`, `README.ru.md`,
  `llms.txt`, and `examples/` with public API changes.

Run `make build`, `make rector`, and `git diff --check` before handoff. Run
`make mutation` when source behavior changes.
