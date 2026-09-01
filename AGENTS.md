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

## Mutation gate: known equivalent classes

`composer mutation` (minMsi 93) leaves a stable set of escaped mutants that
are equivalent by analysis — do not chase them, and re-classify anything new:
`Gen::frequency` weight bumps that scale every pair uniformly, values in
lookup maps read only through `isset()` (a `true`→`false` flip changes
nothing), redundant numeric casts on already-cast operands, mutually
compensating normalizations (a trim whose result is re-trimmed downstream),
float-precision boundaries where `min - 1.0 == min`, and `explode()` limit
bumps where only `[0]` is read.

`PatternWitness` adds two more equivalent classes: candidate-pool variations
(alphabet order, mixed-sample composition, accepted-draw / mutated-position /
search-budget constants, duplicate-skip bookkeeping, UTF-8 defense checks) —
every returned witness is still verified by the `preg_match()` oracle before
use — and fail-closed fast paths whose removal is masked because the
fail-closed valid base rejects the same schema at the public surface.

`DocumentExamples` adds: `(string)` casts on array *keys* (PHP normalizes a
numeric-string key to `int` either way, so the cast only matters where the
value is passed on as a `string` argument), `isset()` guarding an
`is_array()` on the same offset (the `isset` exists for psalm narrowing, not
behavior), and `true` values in a name-lookup map read only through
`array_keys()`.
