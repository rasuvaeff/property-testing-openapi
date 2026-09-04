# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Fixed.** `spaceDelimited` and `pipeDelimited` query parameters generate
  again (#58). The serializer refuses a value carrying the style's own
  separator — the style has no escape for it — but nothing constrained the
  generated strings, and `Gen::stringOf()` draws from printable ASCII, which
  contains both a space and a `|`. Four materializations in five aborted with
  an error that cannot shrink and never reaches the corpus. The alphabet is
  narrowed at compile time instead, an unusable `enum` member is dropped and a
  `const` fails closed; the run-time filter now only guards a `pattern`, whose
  alphabet neither can see. Measured over 200 runs, the abort rate goes from
  163/200 and 144/200 to 0.
- **Fixed.** `allowReserved` no longer changes what the wire says (#59). The
  reserved set is style-aware: a character the chosen style uses as a separator
  stays percent-encoded, so a form list carrying a comma is no longer read back
  as two items. Across every style, explode and value shape — 212 combinations
  serialized here and parsed by `openapi-contract` — the round trip went from
  12 broken to none. The flag is also applied where the specification defines
  it, `in: query`, rather than everywhere but the path.
- **Fixed.** A parameter whose type is an OAS 3.1 union containing `null`
  generates (#62). A parameter travels as text and has no representation for
  the null branch, which is why 3.0 `nullable` is dropped; the 3.1 spelling was
  not, so a free-form `type: ["object", "null"]` reached the generator and the
  wire conversion then rejected the map it produced. `Internal\SchemaShape`
  reads a type union as membership rather than identity, which is what it
  exists to decide in one place.
- **Changed.** Requires `rasuvaeff/openapi-contract` `^0.6`, which fixes the
  contract-side halves of this wave — among them OAS 3.0 documents using a
  boolean `additionalProperties`, and properties that were silently not
  validated at all.
- **Internal.** The zoo gains `delimited.get`, `reserved.get` and `unions.get`,
  and `ZooContracts::suite()` derives its selection from `VALID_OPERATIONS`
  instead of repeating it — an operation added to one list and not the other
  was selected nowhere, and the property that exercises it aborted before it
  could say why.

## Unreleased

- **Fixed.** A required multipart array property is generated non-empty (#60).
  An empty array became zero parts, and a multipart entity with no parts is not
  one — RFC 2046 §5.1.1 requires at least one, and `openapi-contract` rejects
  the payload with `request.body.decode`, as does
  `league/openapi-psr7-validator`. About 9% of runs therefore broke the
  invariant this package states about itself: a materialized valid case must
  pass `Contract::validateRequest()`. Form bodies already forced a required
  container non-empty; multipart does now too. Measured over 80 runs, empty
  bodies go from 7 to 0.
- **Fixed.** Every supported request body media type is generated, not only the
  first one recognised (#66). A body declaring `application/json` beside
  `application/x-www-form-urlencoded` was always exercised through one of them,
  so the other was declared and never sent.
- **Internal.** The zoo gains `uploads.create` and `dual.create`, and the
  end-to-end property gates on both media types of the dual body actually being
  chosen.

## 0.8.0 — 2026-09-04

- **Breaking.** `RequestMaterializer`, `RequestCaseArbitrary` and
  `ResponseCaseArbitrary` no longer accept their `@internal` collaborators as
  constructor parameters (#53). Every one had a default and nothing in the
  package, its tests, its examples or its documentation ever passed one, so no
  documented usage changes — but the signature published a type the package
  does not support overriding. `RequestMaterializer` keeps `$baseUri`, now its
  third parameter; `withBaseUri()` is the documented way to set it either way.
- `SecuritySelector` is `@api`. It was marked `@internal` while the README
  showed a snippet constructing it to materialize a request outside a suite;
  the snippet is the contract, and the class only ever exposed public types.
- The seven parameter misuse categories of `NegativeRequestCaseArbitrary` no
  longer restate the path/query/header/cookie handling and the full case shape
  once each: one `parameter()` helper writes the target's value through a
  single location map, and one imported psalm type describes the case. A
  location can now be mishandled in exactly one place.
- Helpers that were carried verbatim in several classes are shared:
  `MediaType` (normalization and the JSON test, nine call sites),
  `SchemaShape` (array/object schema, three copies), `WireValue` (the scalar
  wire spelling, four copies with four different failure wordings), and
  `ConstructibleCategories` (drop the misuse categories a document does not
  admit, then choose uniformly — two copies).
- `ResponseCaseArbitrary` discovers the JSON media type of a response once
  instead of once per caller; each caller still words its own "no JSON media
  type" failure.
- Removed a re-check of `is_string()` in `ContainerArbitraries` that the loop
  above already enforced, and the single-use `withValue()` wrapper in
  `RequestMaterializer`.
- README (EN/RU) carries the sections the package conventions require:
  Requirements, Examples, Development and License, plus the `llms.txt` pointer
  and a Psalm-level badge.
- `examples/README.md` lists the scripts as a table and says how to run them.

## 0.7.0 — 2026-09-04

- Requires `rasuvaeff/openapi-contract` `^0.5` (was `^0.4`), which closes the
  same review's ten warnings. The oracle is correspondingly stricter and more
  accurate: header list values lose the whitespace around their separator, a
  boolean `schema: false` rejects instead of accepting everything, `multipart`
  honours `encoding.explode`, an OAS 3.1 type union is read as the shape it
  declares, and parameter diagnostics point at the declaration that carries
  them. Body values are redacted from rendered diagnostics, which `CheckFailed`
  passes through — a `CheckFailed` message no longer shows a body's `actual`.

- The negative const witness never collides with the const it contradicts.
  `constMismatch()` used a fixed literal, so a parameter declaring that literal
  as its `const` got an "invalid" value that was in fact valid. `enumMismatch()`
  already walked away from the collision; the const side now agrees.
- An unsupported media type no longer hides the request-body examples declared
  after it. `content` is a map, and giving up on the first entry this phase
  cannot encode lost every JSON example a document happened to list after a
  multipart body.
- A decimal `multipleOf` no longer accumulates float error: `3 * 0.1` was
  emitted as `0.30000000000000004`. Our own oracle tolerates that, but a server
  checking `fmod` without a tolerance does not, and the resulting failure lands
  on the user's API rather than on the generator. The product is rounded back to
  the precision the multiple itself carries.
- A `pattern` that cannot satisfy its length window fails closed at compile
  time, naming both constraints, instead of exhausting `Gen::filter()` mid-run.
  The package's own rules call a run-time `GenerationExhausted` a defect; the
  probe is deterministic and twice the retry budget the filter would have had,
  so a window it cannot hit is one the filter would have exhausted anyway.

## 0.6.0 — 2026-09-04

- `ParameterSerializer` emits the wire format the specification declares for
  `label` and the delimited styles (#45), matching the parser fix in
  `rasuvaeff/openapi-contract` 0.4.0 — which this package now requires
  (`^0.4`, was `^0.3`). An unexploded `label` array is comma-separated
  (`.blue,black,brown`), not dot-separated; `spaceDelimited` emits the
  separator as `%20`, since a raw space is not a legal URI character. The two
  packages carried mirror-image bugs, so they agreed with each other and every
  generated request was accepted by the oracle while no conforming server would
  have taken it. Generating a list item that contains its own separator now
  fails closed as `UnsupportedGeneration`: the styles have no escape for one,
  so such a value has no representation on the wire.
- An optional `multipart/form-data` request body generates instead of throwing
  `LogicException` from inside the arbitrary (#41). The "body present" branch of
  an optional body wrapped the generated body and read its shape back, and that
  read accepted the `json` and `form` encodings only — multipart carries `parts`
  rather than a `value`. The optional form now picks between the body arbitrary
  and no body at all, so nothing re-reads a shape the generator just built.
  Every multipart fixture declared `required: true`, which is why the hole was
  invisible.
- A negative type-mismatch witness is built only for a parameter with a single
  declared type (#42). An OAS 3.1 union admits every type it lists, so
  `not-null` is a valid value for `["string", "null"]` — the "invalid" case was
  valid and falsified the whole negative phase. `ResponseTargets` already
  required a single type; the parameter side now agrees.
- The JSON body encoder reads the `additionalProperties` schema for a key
  outside `properties` (#43). Losing it encoded a nested empty object as `[]`
  instead of `{}`, and the contract correctly rejected the result — surfacing as
  `invalidGeneratedRequest` on a valid contract.
- `allOf` merges OAS 3.0 `nullable` as an intersection (#44): null is generated
  only where every branch admits it. Merging it as an ordinary last-write key
  let `{type: string, nullable: true}` next to `{type: string, minLength: 3}`
  generate `null`, which the second branch rejects. A branch omitting `nullable`
  now counts as `nullable: false`, which is what OAS 3.0 means by it. The OAS
  3.1 spelling of the same shape (`type: [..., "null"]` in one branch only)
  already failed closed as conflicting types.

## 0.5.0 — 2026-09-03

- Requires `rasuvaeff/openapi-contract` `^0.3` (was `^0.2.1`, no longer
  supported). `ContractSuite::checkValid()` and the reproducer now see the
  contract's response header value validation (`response.header.schema`,
  `response.header.serialization`, `response.header.unsupported`) — a
  server answering `X-RateLimit-Remaining: banana` for a `type: integer`
  header is reported instead of passing. Documents whose
  `components.securitySchemes` lack a supported `type` or a field the type
  requires no longer compile (`InvalidContract`). Generated responses keep
  validating: every materialized response header survives the contract's
  `simple`-style decoding.

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
