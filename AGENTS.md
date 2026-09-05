# AGENTS.md — property-testing-openapi

Guidance for AI agents working on this package. Read before changing code.

## What this is

This package generates data-only OpenAPI request cases and materializes them
as PSR-7 requests, in namespace `Rasuvaeff\PropertyTesting\OpenApi`,
published as `rasuvaeff/property-testing-openapi` (0.x) on top of
`rasuvaeff/openapi-contract` and `rasuvaeff/property-testing-core`. The
public surface: `RequestCaseArbitrary` / `NegativeRequestCaseArbitrary` /
`ResponseCaseArbitrary` / `NegativeResponseCaseArbitrary`, `RequestMaterializer`
/ `ResponseMaterializer`, `DocumentExamples`, `ContractSuite` with its
transports and credentials, `OperationProperty`, `OperationCoverage`,
`RequestReproducer`, `SchemaArbitraryCompiler`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Unsupported generation and serialization semantics fail closed.** Never
   use a generic string fallback for a schema assertion that is not
   implemented — throw `UnsupportedGeneration` instead.
4. **Preserve the public contract.** Update README EN/RU, `llms.txt`,
   `examples/`, and tests with any API change.

## Commands

No PHP or Composer on the host. Run through Docker:

```bash
make install
make cs-fix
make build
make rector
make mutation
make release-check
```

The local `make mutation` needs the `/repo` mount when the contract comes
from a path repository (`vendor/rasuvaeff/openapi-contract` is a symlink
into the monorepo) plus `git config --global --add safe.directory "*"`.

## Invariants & gotchas

- Keep `RequestCaseData` JSON-compatible. It must never contain PSR-7 objects,
  credentials, closures, or application DTOs.
- A materialized valid case must pass `Contract::validateRequest()` before a
  transport may observe it.
- A header is written verbatim, a path and a query are percent-encoded, and a
  cookie is percent-encoded. That is not a style question but a wire question:
  the validator reads a header field value as sent (openapi-contract#66), so
  encoding one here would put a string on the wire that no client sends.
  `ParameterSchemas::separatorOf()` narrows the alphabet accordingly and
  `isHeaderSafe()` guards what a `pattern` or a `format` can still put outside
  a field value — the same two halves as the path rule below. A CR or an LF
  reaching a materializer is refused by name, never encoded away.
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
- The zoo doubles as the source of `openapi-contract`'s generated corpus.
  `bin/record-openapi-corpus` (monorepo root) replays `ZooContracts::document()`
  and `legacyDocument()` through the generator and freezes the cases as JSON
  in that package, which cannot depend on this one. **Adding a zoo operation
  means re-recording**, or the corpus silently stops covering the feature the
  operation was added for — `bin/record-openapi-corpus --check` says whether
  you need to, and names any case that kept its name and changed its intent.
  It records the generator's intent, never a verdict.
- Two oracles are external to both packages, and neither is optional.
  `tests/SapiAgreementTest.php` asks whether the application behind the
  validator receives the value the case recorded; `tests/Differential/` asks
  whether an independent reader of the same document reaches the same verdict
  on generated traffic. A suite in which the generator and the validator only
  agree with each other proves nothing — that is exactly the state in which a
  query `+` was a literal plus to both for the life of the package.
- Keep the differential fixture inside the intersection of what both readers
  claim to support, and record every exclusion with its reason in
  `tests/Support/DifferentialContracts.php`. Widening it past that intersection
  does not find bugs; it accumulates pinned limitations of the other library,
  which is the hand-written corpus the generator exists to escape. A genuine
  disagreement gets pinned as its own deterministic case, never generated a few
  hundred times per run.
- `Psr15Transport` mimics the SAPI (`parse_str()` semantics for query/form/
  multipart names, `uploadedFiles` only with both PSR-17 factories).
- The suite runs under both test runners: Testo drives the package's own
  tests, and a PHPUnit fixture (`tests/PhpUnit/`, `composer test:phpunit`,
  part of `composer build`) pins the runner-integration shape the README
  documents. Do not remove `phpunit.xml` or the `test:phpunit` script.
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
- `examples/` is part of the public contract; every listed script must run.
- CI actions stay SHA-pinned with read-only permissions and checkout
  credentials disabled.

## Mutation gate: known equivalent classes

`composer mutation` (minMsi 92, against a measured 93.05% — see the comment
in `infection.json5` for why the gate is set below the score and not at it)
leaves a stable set of escaped mutants that
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

The 2026-09-05 wave adds: the `true` in the part-name lookup
`RequestReproducer` builds for `isset()`; the `(string)` cast on a PSR-7 header
name, which is a string already; the case-level cookie loop, which is
unobservable in the rendered command because the `Cookie` header is redacted
wholesale by the default header set (see issue #73); and `??=` versus `=` when
`SecuritySelector` remembers the anonymous security alternative, since every
anonymous alternative carries the same empty credentials.

Casts of an array key to `string` are equivalent by the same rule the
`DocumentExamples` paragraph above states, and are therefore not written:
`[(string) $k => $v]` and `[$k => $v]` build the same array. The cast belongs
only where the key becomes a string argument — `ParameterSerializer::encode()`
is the case that matters, and it is covered.

A guard chain that re-reads the same discriminator is worth reading twice when
its mutants escape: the second read only repeats what the first decided, so
every mutation of it is masked. `RequestReproducer::redactCase()` had four such
guards over `$body['encoding']` and became one `match`; the escapes went with
them. Masked mutants are a shape, not a fact of life.

## When you finish

Run `composer build`, `composer rector`, and `git diff --check`. Run mutation
when source behavior changes.
