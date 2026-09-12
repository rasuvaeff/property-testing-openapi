<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\JsonBodyWitness;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ParameterTargets;

/**
 * Produces invalid request cases through constructive, corpus-safe mutations.
 *
 * The generated value remains corpus-safe; `misuse` identifies the deliberate
 * invalidation and is never interpreted as a secret or a PSR-7 object.
 *
 * @psalm-import-type RequestCaseData from RequestCaseArbitrary
 * @psalm-import-type Kind from JsonBodyWitness
 * @psalm-type CoverageData = array{
 *     covered: list<array{kind: non-empty-string, location: string, name: string}>,
 *     skipped: list<array{kind: non-empty-string, side: 'request'|'parameter'|'body', reason: string}>,
 * }
 * @psalm-import-type Witness from JsonBodyWitness
 * @psalm-type NegativeRequestCaseData = array{
 *     operationKey: string,
 *     path: array<string, string|list<string>|array<string, string>>,
 *     query: array<string, string|list<string>|array<string, string>>,
 *     headers: array<string, string|list<string>|array<string, string>>,
 *     cookies: array<string, string|list<string>|array<string, string>>,
 *     body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed},
 *     misuse: array{kind: 'missing-required'|'type'|'enum'|'const'|'boundary'|'length'|'format'|'pattern'|'additional-properties'|'media-type'|'part-content-type'|'json-syntax', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
 * }
 *
 * @api
 */
final readonly class NegativeRequestCaseArbitrary
{
    /**
     * The case key each parameter location writes to. Every misuse that
     * targets a parameter goes through this map, so a location can only be
     * mishandled in one place.
     */
    private const array CASE_KEYS = [
        'path' => 'path',
        'query' => 'query',
        'header' => 'headers',
        'cookie' => 'cookies',
    ];

    private ParameterTargets $parameterTargets;

    private BodyTargets $bodyTargets;

    public function __construct(
        private RequestCaseArbitrary $valid = new RequestCaseArbitrary(),
    ) {
        $this->parameterTargets = new ParameterTargets();
        $this->bodyTargets = new BodyTargets();
    }

    /**
     * Drops one required parameter, or the whole required body.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function forOperation(Operation $operation): ArbitraryInterface
    {
        $targets = $this->parameterTargets->missingRequired($operation);

        return $this->overTargets($this->valid->forOperation($operation), $targets, static function (array $target): \Closure {
            /** @var array{location: 'path'|'query'|'header'|'cookie'|'body', name: string} $target */
            $location = $target['location'];
            $name = $target['name'];

            return static function (array $case) use ($location, $name): array {
                /** @var RequestCaseData $case */
                if ($location === 'body') {
                    $case['body'] = null;
                } else {
                    unset($case[self::CASE_KEYS[$location]][$name]);
                }
                $case['misuse'] = ['kind' => 'missing-required', 'location' => $location, 'name' => $name];

                return $case;
            };
        });
    }

    /**
     * Replaces one scalar parameter with a wire value that cannot satisfy its
     * integer, number, boolean, or null schema type.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function typeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('type', $operation, $this->parameterTargets->typeMismatch($operation));
    }

    /**
     * Replaces one scalar parameter with a value absent from its finite
     * enum.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function enumMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('enum', $operation, $this->parameterTargets->enumMismatch($operation));
    }

    /**
     * Replaces one scalar parameter with a value other than the single one
     * its `const` admits.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function constMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('const', $operation, $this->parameterTargets->constMismatch($operation));
    }

    /**
     * Replaces one numeric parameter with a wire value just outside its
     * `minimum`/`maximum` bound, honouring boolean exclusive bounds.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function boundaryMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('boundary', $operation, $this->parameterTargets->boundaryMismatch($operation));
    }

    /**
     * Replaces one string parameter with a wire value whose length falls
     * just outside its `minLength`/`maxLength` bound.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function lengthMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('length', $operation, $this->parameterTargets->lengthMismatch($operation));
    }

    /**
     * Replaces one string parameter with a wire value that provably violates
     * its asserted `format`.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function formatMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('format', $operation, $this->parameterTargets->formatMismatch($operation));
    }

    /**
     * Replaces one string parameter with a searched wire value that provably
     * fails its `pattern`; the pattern itself is the oracle, and an exhausted
     * search budget fails closed.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function patternMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('pattern', $operation, $this->parameterTargets->patternMismatch($operation));
    }

    /**
     * Adds one undeclared property to a required JSON object body whose schema
     * sets `additionalProperties: false`.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function additionalPropertyForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->bodyTargets->additionalProperty($operation);
        $name = $target['name'];

        return $this->mutateJsonBody($operation, $target['mediaType'], static function (array $case) use ($name): array {
            $body = $case['body'];
            $value = $body['value'] ?? null;
            if ($body === null || !is_array($value)) {
                throw new \LogicException('Required JSON object body expected for an additional property misuse');
            }
            $value[$name] = true;
            $case['body'] = ['mediaType' => $body['mediaType'], 'encoding' => 'json', 'value' => $value];
            $case['misuse'] = ['kind' => 'additional-properties', 'location' => 'body', 'name' => $name];

            return $case;
        });
    }

    /**
     * Keeps the schema-valid JSON body but sends it under an undeclared
     * Content-Type, so the media type is the only deviation.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function mediaTypeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->bodyTargets->mediaTypeMismatch($operation);
        $invalid = $target['invalid'];

        return $this->mutateJsonBody($operation, $target['mediaType'], static function (array $case) use ($invalid): array {
            $body = $case['body'];
            if ($body === null) {
                throw new \LogicException('Required JSON body expected for a media type misuse');
            }
            $case['body'] = ['mediaType' => $invalid, 'encoding' => 'json', 'value' => $body['value'] ?? null];
            $case['misuse'] = ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
    }

    /**
     * Keeps the multipart body valid and sends one part under a media type its
     * `encoding.contentType` does not allow, so the part's declared type is
     * the only deviation.
     *
     * This is the only misuse that can see a validator reading
     * `encoding.contentType` and ignoring it: neglecting the keyword is
     * fail-open, so every valid case passes either way (#80).
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function partContentTypeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->bodyTargets->partContentTypeMismatch($operation);
        $property = $target['property'];
        $invalid = $target['invalid'];

        return $this->mutate($operation, static function (array $case) use ($property, $invalid): array {
            $body = $case['body'];
            if ($body === null || !isset($body['parts'])) {
                throw new \LogicException('Required multipart body expected for a part content type misuse');
            }
            $parts = [];
            foreach ($body['parts'] as $part) {
                if ($part['name'] === $property) {
                    $part['contentType'] = $invalid;
                }
                $parts[] = $part;
            }
            $body['parts'] = $parts;
            $case['body'] = $body;
            $case['misuse'] = ['kind' => 'part-content-type', 'location' => 'body', 'name' => $property];

            return $case;
        });
    }

    /**
     * Replaces the required JSON body with a deliberately malformed raw JSON
     * payload under the declared media type.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function malformedJsonForOperation(Operation $operation): ArbitraryInterface
    {
        $body = $this->bodyTargets->jsonBody($operation);
        if ($body === null) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no required JSON body for a malformed JSON case', $operation->key));
        }
        $mediaType = $body['mediaType'];

        // Unfiltered, unlike the other two body mutations (#97): this one
        // replaces the body wholesale and never reads the valid case's
        // `value`, so a draw that carried a multipart or form body is as good
        // a base as a JSON one. Filtering would discard most draws of a
        // multi-media-type body for nothing.
        return $this->mutate($operation, static function (array $case) use ($mediaType): array {
            $case['body'] = ['mediaType' => $mediaType, 'encoding' => 'raw', 'value' => '{"malformed":'];
            $case['misuse'] = ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
    }

    /**
     * Replaces one top-level value of the required JSON body with one that
     * cannot satisfy its single declared schema type.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function bodyTypeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->bodyWitness('type', $operation);
    }

    /**
     * Replaces one top-level value of the required JSON body with a value
     * absent from its finite scalar enum.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function bodyEnumMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->bodyWitness('enum', $operation);
    }

    /**
     * Replaces one top-level value of the required JSON body with a value
     * other than the single one its `const` admits.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function bodyConstMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->bodyWitness('const', $operation);
    }

    /**
     * Replaces one top-level numeric value of the required JSON body with one
     * just outside its `minimum`/`maximum` bound, honouring boolean exclusive
     * bounds.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function bodyBoundaryMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->bodyWitness('boundary', $operation);
    }

    /**
     * Replaces one top-level string or array value of the required JSON body
     * with one whose length falls just outside its `minLength`/`maxLength` or
     * `minItems`/`maxItems` bound.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function bodyLengthMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->bodyWitness('length', $operation);
    }

    /**
     * Replaces one top-level string value of the required JSON body with a
     * fixed witness that provably violates its asserted `format`.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function bodyFormatMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->bodyWitness('format', $operation);
    }

    /**
     * Replaces one top-level string value of the required JSON body with a
     * searched witness that provably fails its `pattern`; the pattern itself
     * is the oracle, and an exhausted search budget fails closed.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function bodyPatternMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->bodyWitness('pattern', $operation);
    }

    /**
     * Writes one body target's invalid value over the top-level JSON property
     * it names (or over the scalar root, named `$`) and records the kind with
     * `location: 'body'`.
     *
     * Built on the valid cases that carry the JSON media type the target was
     * found under: a body declared under several media types is generated
     * under each of them, and only the JSON one has the value to overwrite.
     *
     * @param Kind $kind
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    private function bodyWitness(string $kind, Operation $operation): ArbitraryInterface
    {
        $found = $this->bodyTargets->bodyWitness($operation, $kind);
        $mediaType = $found['mediaType'];

        return $this->overTargets($this->jsonCases($operation, $mediaType), $found['targets'], static function (array $target) use ($kind): \Closure {
            /** @var array{name: string, invalid: Witness} $target */
            $name = $target['name'];
            $invalid = $target['invalid'];

            return static function (array $case) use ($kind, $name, $invalid): array {
                /** @var RequestCaseData $case */
                $body = $case['body'];
                $members = $body['value'] ?? null;
                if ($body === null) {
                    throw new \LogicException('Required JSON body expected for a body witness misuse');
                }
                if ($name === JsonBodyWitness::ROOT) {
                    $value = $invalid;
                } else {
                    if (!is_array($members)) {
                        throw new \LogicException('Required JSON body value expected for a property misuse');
                    }
                    if (array_is_list($members) && $members !== []) {
                        throw new \LogicException('Required JSON object body expected for a property misuse');
                    }
                    // `array_replace()` rather than an element write: it keeps
                    // a numeric-string property name (`'12'`) the int key PHP
                    // gave it, in the position the valid case put it.
                    $value = array_replace($members, [$name => $invalid]);
                }
                $case['body'] = ['mediaType' => $body['mediaType'], 'encoding' => 'json', 'value' => $value];
                $case['misuse'] = ['kind' => $kind, 'location' => 'body', 'name' => $name];

                return $case;
            };
        });
    }

    /**
     * Writes one target's invalid wire value over the parameter it names.
     * Every parameter misuse differs only in the `kind` it records, so they
     * all come through here rather than restating the location handling.
     *
     * The write is an assignment, not a replacement: an optional parameter
     * the valid case left out is carried by the negative one, which is what
     * lets every category target optional parameters at all (#93).
     *
     * @param 'type'|'enum'|'const'|'boundary'|'length'|'format'|'pattern' $kind
     * @param non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}> $targets
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    private function parameter(string $kind, Operation $operation, array $targets): ArbitraryInterface
    {
        return $this->overTargets($this->valid->forOperation($operation), $targets, static function (array $target) use ($kind): \Closure {
            /** @var array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} $target */
            return static function (array $case) use ($kind, $target): array {
                /** @var RequestCaseData $case */
                $case[self::CASE_KEYS[$target['location']]][$target['name']] = $target['invalid'];
                $case['misuse'] = ['kind' => $kind, 'location' => $target['location'], 'name' => $target['name']];

                return $case;
            };
        });
    }

    /**
     * Every misuse this operation admits, and the reason for each one it does
     * not — without drawing anything.
     *
     * Whether a category is constructible is a pure function of the document,
     * and until this existed the only way to find out was to sample: build
     * `negativeCases()`, draw a few hundred times and tally `misuse`. That
     * works, but it cannot prove a negative — a category absent from 300 draws
     * might appear at 10 000 — and it cannot say *why* one is absent.
     *
     * The gap mattered exactly where the information was needed. A hand-written
     * test asserting `per_page > maximum` → 422 was deleted twice in one
     * document on the premise that the negative phase built that case itself.
     * It did not, both deletions passed review, and the suite stayed green
     * either way: a green suite looks identical whether a category is
     * generating and the application is correctly rejecting, or the category
     * was never constructed. `assertSame($expected, $suite->negativeCoverage())`
     * fails the moment such a deletion lands (#101).
     *
     * @return CoverageData
     */
    public function coverageForOperation(Operation $operation): array
    {
        $covered = [];
        $skipped = [];
        foreach ($this->coverageSources($operation) as [$kind, $side, $find]) {
            try {
                foreach ($find() as $target) {
                    $covered[] = ['kind' => $kind, 'location' => $target['location'], 'name' => $target['name']];
                }
            } catch (UnsupportedGeneration $refusal) {
                $skipped[] = ['kind' => $kind, 'side' => $side, 'reason' => $refusal->getMessage()];
            }
        }

        return ['covered' => $covered, 'skipped' => $skipped];
    }

    /**
     * Each category paired with the finder that decides it, in the order
     * {@see ContractSuite::negativeCases()} weights them.
     *
     * The finders are the ones the arbitraries use, so a category listed as
     * covered is one an arbitrary can build and a category listed as skipped
     * carries the refusal an arbitrary would have thrown.
     *
     * A category is identified by its kind *and* its side: `format` names one
     * category over the parameters and another over the body, and a refusal
     * that did not say which would be unreadable.
     *
     * @return list<array{0: non-empty-string, 1: 'request'|'parameter'|'body', 2: \Closure(): list<array{location: string, name: string}>}>
     */
    private function coverageSources(Operation $operation): array
    {
        $body = static fn(string $name): array => [['location' => 'body', 'name' => $name]];

        return [
            ['missing-required', 'request', fn(): array => $this->located($this->parameterTargets->missingRequired($operation))],
            ['type', 'parameter', fn(): array => $this->located($this->parameterTargets->typeMismatch($operation))],
            ['enum', 'parameter', fn(): array => $this->located($this->parameterTargets->enumMismatch($operation))],
            ['const', 'parameter', fn(): array => $this->located($this->parameterTargets->constMismatch($operation))],
            ['boundary', 'parameter', fn(): array => $this->located($this->parameterTargets->boundaryMismatch($operation))],
            ['length', 'parameter', fn(): array => $this->located($this->parameterTargets->lengthMismatch($operation))],
            ['format', 'parameter', fn(): array => $this->located($this->parameterTargets->formatMismatch($operation))],
            ['pattern', 'parameter', fn(): array => $this->located($this->parameterTargets->patternMismatch($operation))],
            ['additional-properties', 'body', fn(): array => $body($this->bodyTargets->additionalProperty($operation)['name'])],
            ['media-type', 'body', function () use ($operation, $body): array {
                $this->bodyTargets->mediaTypeMismatch($operation);

                return $body('body');
            }],
            ['part-content-type', 'body', fn(): array => $body($this->bodyTargets->partContentTypeMismatch($operation)['property'])],
            ['json-syntax', 'body', function () use ($operation, $body): array {
                if ($this->bodyTargets->jsonBody($operation) === null) {
                    throw new UnsupportedGeneration(sprintf('Operation "%s" has no required JSON body for a malformed JSON case', $operation->key));
                }

                return $body('body');
            }],
            ...array_map(
                fn(string $kind): array => [$kind, 'body', function () use ($operation, $kind): array {
                    /** @var Kind $kind */
                    $found = $this->bodyTargets->bodyWitness($operation, $kind);

                    return array_map(
                        static fn(array $target): array => ['location' => 'body', 'name' => $target['name']],
                        $found['targets'],
                    );
                }],
                ['type', 'enum', 'const', 'boundary', 'length', 'format', 'pattern'],
            ),
        ];
    }

    /**
     * Drops the witness value from a target list: coverage reports where a
     * category lands, not what it writes there.
     *
     * @param list<array{location: string, name: string, invalid?: mixed}> $targets
     * @return list<array{location: string, name: string}>
     */
    private function located(array $targets): array
    {
        return array_map(
            static fn(array $target): array => ['location' => $target['location'], 'name' => $target['name']],
            $targets,
        );
    }

    /**
     * Draws a target alongside the valid case, so repeated draws of one
     * category spread across every parameter the document declares that way
     * rather than landing on the first eligible one forever (#99).
     *
     * `Gen::elements()` shrinks toward the head of the list, which is
     * declaration order, so the minimal counterexample is the target the
     * first-match search used to return.
     *
     * @param ArbitraryInterface<RequestCaseData> $valid
     * @param non-empty-list<array<string, mixed>> $targets
     * @param \Closure(array<string, mixed>): \Closure(RequestCaseData): NegativeRequestCaseData $mutationFor
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    private function overTargets(ArbitraryInterface $valid, array $targets, \Closure $mutationFor): ArbitraryInterface
    {
        // The valid case is drawn first and the target second, so a case
        // carries the same body its seed produces on its own — the mutation
        // lands beside what the valid draw built, never in place of it.
        $drawn = Gen::record(['case' => $valid, 'target' => Gen::elements($targets)]);

        /** @var ArbitraryInterface<NegativeRequestCaseData> $mutated */
        $mutated = Gen::map($drawn, static function (array $draw) use ($mutationFor): array {
            /** @var array{case: RequestCaseData, target: array<string, mixed>} $draw */
            return $mutationFor($draw['target'])($draw['case']);
        });

        return $mutated;
    }

    /**
     * Mutates only the valid cases that carry one JSON media type.
     *
     * Since #79 a body declared under several media types is generated under
     * each of them, so a valid case for an operation offering
     * `multipart/form-data` beside `application/json` carries parts and no
     * `value`. A mutation that rewrites that `value` — or that promises to
     * keep it while changing something else — is built on the media type its
     * target was found under rather than on the unfiltered valid cases (#97).
     *
     * @param non-empty-string $mediaType
     * @param \Closure(RequestCaseData): NegativeRequestCaseData $mutation
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    private function mutateJsonBody(Operation $operation, string $mediaType, \Closure $mutation): ArbitraryInterface
    {
        /** @var ArbitraryInterface<NegativeRequestCaseData> $mutated */
        $mutated = Gen::map($this->jsonCases($operation, $mediaType), $mutation);

        return $mutated;
    }

    /**
     * The valid cases of this operation that carry one JSON media type.
     *
     * @param non-empty-string $mediaType
     * @return ArbitraryInterface<RequestCaseData>
     */
    private function jsonCases(Operation $operation, string $mediaType): ArbitraryInterface
    {
        $carriesJson = static function (array $case) use ($mediaType): bool {
            /** @var RequestCaseData $case */
            $body = $case['body'];

            return $body !== null && $body['encoding'] === 'json' && $body['mediaType'] === $mediaType;
        };

        return Gen::filter($this->valid->forOperation($operation), $carriesJson);
    }

    /**
     * @param \Closure(RequestCaseData): NegativeRequestCaseData $mutation
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    private function mutate(Operation $operation, \Closure $mutation): ArbitraryInterface
    {
        /** @var ArbitraryInterface<NegativeRequestCaseData> $mutated */
        $mutated = Gen::map($this->valid->forOperation($operation), $mutation);

        return $mutated;
    }
}
