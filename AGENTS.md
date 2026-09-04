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
  template segment after percent decoding: `Internal\ParameterSchemas` raises
  `minLength` to 1 on every path string, drops unsafe `enum` members, refuses
  slash-carrying formats, and `RequestCaseArbitrary` filters the generated
  value with `isPathSafe()`. Keep both halves — the schema rewrite constructs,
  the filter guards what the rewrite cannot see (a `pattern`).
- Schemas are generated per direction: `Internal\RequestSchemas` (drops
  `readOnly`) for request bodies and document examples, `ResponseSchemas`
  (drops `writeOnly`) for responses, both over `DirectionalSchemas`. Malformed
  members pass through untouched so the compiler still fails closed on them.
- Parameters travel as text: `ParameterSchemas` strips OAS 3.0 `nullable`,
  `null` enum members and a `null` const at every nesting level.
- Every unsatisfiable combination the compiler can recognise fails closed at
  compile time (`pattern` + asserted `format`, format length bands,
  `uniqueItems` over a finite domain, `not.type` covering the source, `allOf`
  branch bounding `additionalProperties` without its siblings' properties).
  Do not push such checks into `Gen::filter()`; a run-time
  `GenerationExhausted` is a defect here.
- The end-to-end oracle for the valid phase is `tests/Support/ZooContracts.php`
  + `ContractSuiteTest::zooValidCasesPassTheBuiltInChecks`: one operation per
  schema feature, checked through materialize → validate → transport →
  validate the exchange. Add a zoo operation with every new keyword or
  location rule; a unit test on the compiler alone does not prove the wire.
- `Psr15Transport` mimics the SAPI (`parse_str()` semantics for query/form/
  multipart names, `uploadedFiles` only with both PSR-17 factories). The
  local `make mutation` needs the `/repo` mount when the contract comes from a
  path repository (`vendor/rasuvaeff/openapi-contract` is a symlink into the
  monorepo) plus `git config --global --add safe.directory "*"`.
- Preserve the public documentation in `README.md`, `README.ru.md`,
  `llms.txt`, and `examples/` with public API changes.
- **No `@internal` type in a public signature.** An `@api` class does not take
  one as a constructor or method parameter, even with a default: the default
  keeps callers from naming it, but the signature still publishes it and
  invites an override the package does not support. Build the collaborator in
  the constructor body instead.
- `SchemaArbitraryCompiler`, `DocumentExamples` and `RequestReproducer` are
  `@internal` classes that deliberately live at the root of `src/` rather than
  under `Internal\`: the public docblocks point at them with `{@see}` so a
  reader can follow the mechanism, and `llms.txt` names each one as the
  internal form of a public route. That is a considered exception, not an
  oversight — do not re-file it, and do not let one of them appear in a public
  signature either. The test is what the docs tell a user to do:
  `SecuritySelector` was `@internal` while the README showed a snippet
  constructing it, and it is `@api` now because that snippet is the contract.

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

`RequestCaseArbitrary` (form/multipart generation) adds: redundant `(array)` /
`(string)` / `(int)` casts on operands already narrowed by `is_array()` /
`is_string()` / `is_int()`; `LogicException` guards on shapes the generator
itself produced (unreachable typing guards); the default lower bound of a
part-list or byte-string budget where an empty list of parts is
indistinguishable from an absent property on the wire; the `16`-item and
`64`-byte generation budgets; the composition and offset of the boundary
hash (any deterministic 16-hex boundary is equivalent); the `max(1, …)`
floor in `nonEmptyContainer()` which makes the default of a missing
`minItems`/`minProperties` irrelevant; and guards masked by `??` on scalar
offsets (`'x'['k'] ?? null` is `null`, not an error).

`ParameterSchemas`/`DirectionalSchemas`/`MultipartParser` add: `+=` versus
`[$k => $v]` assignment forms (equivalent for unique keys), the order of
equivalent `str_contains()` guards, `array_values()` re-indexing after a
filter whose consumer iterates values only, and boundary-read offsets in the
multipart reader that only move where a malformed payload already ends the
read.

The 2026-09-04 warning batch adds: the `explode('.')`-based decimal count in
`ScalarArbitraries::decimals()` (a `%.*F` rendering always carries a `.`, so the
appended one and the index only matter for a value that cannot reach here); the
probe budget and loop bounds of `ScalarArbitraries::fitsLengthWindow()` (the
budget dominates `Gen::filter()`'s 100 retries by design, so shrinking it still
answers the same question for any window a test can express, and `mb_strlen`
versus `strlen` agrees on the ASCII alphabets the supported pattern subset
generates); the `===` in the const-witness collision loop of
`ParameterTargets::constMismatch()` (the witness is a string and PHP 8 no longer
compares a string loosely equal to a number, so `==` decides the same); and the
`continue` guarding an example-less media type in `DocumentExamples::bodyPart()`
(the next entry is examined either way — only an entry that both fails this
guard and carries an example would tell them apart, which the guard's own
condition excludes).

Response generation (`ResponseTargets`, `NegativeResponseCaseArbitrary`,
`ResponseCaseArbitrary`, `ResponseMaterializer`, `ResponseSchemas`) adds:
witness-container and witness-value variations that the oracle cannot tell
apart (any non-conforming value still falsifies the schema, so replacing an
out-of-range number, a `not-a-<type>` string, or a dropped `['value' => …]`
key with another invalid value or `null` keeps `validateResponse()` red);
mutations that only widen an already invalid case (slicing the object,
dropping `array_merge`, flipping the additional property's boolean);
the undeclared-status candidate ladder constants (any first undeclared
candidate serves); guard precedence masked by `??` on scalar offsets or by a
later fail-closed check; and the `64`-item construction budget with its
redundant int casts.
