<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ConstructibleCategories;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ResponseTargets;

/**
 * Produces invalid response cases through constructive, corpus-safe
 * mutations of a valid case, for proving that an API client rejects a
 * provider payload the contract forbids instead of mapping it. Every
 * category is first proven invalid by `Contract::validateResponse()`; the
 * `misuse` metadata names the deliberate invalidation and survives
 * shrinking because the mutation is re-applied to every shrunk valid case.
 *
 * @api
 *
 * @psalm-import-type ResponseCaseData from ResponseCaseArbitrary
 * @psalm-import-type Witness from \Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\JsonBodyWitness
 */
final readonly class NegativeResponseCaseArbitrary
{
    private ResponseTargets $targets;

    public function __construct(
        private ResponseCaseArbitrary $valid = new ResponseCaseArbitrary(),
    ) {
        $this->targets = new ResponseTargets($valid);
    }

    /**
     * Weighted choice among every category the response supports
     * constructively ({@see categoriesForOperation()}), each with the same
     * weight; a response with no constructible category fails closed.
     *
     * @return ArbitraryInterface<ResponseCaseData>
     */
    public function forOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return ConstructibleCategories::union(
            $this->categoriesForOperation($operation, $status),
            sprintf('Response for status %d of operation "%s" supports no constructible negative case category', $status, $operation->key),
        );
    }

    /**
     * The same union as {@see forOperation()}, under the name the request
     * side uses for it ({@see NegativeRequestCaseArbitrary::anyForOperation()}).
     *
     * @return ArbitraryInterface<ResponseCaseData>
     */
    public function anyForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->forOperation($operation, $status);
    }

    /**
     * Every category the response supports constructively, keyed by its
     * misuse kind, in weighting order; a category the response gives no
     * target is absent, and {@see coverageForOperation()} says why (#164).
     *
     * @return array<non-empty-string, ArbitraryInterface<ResponseCaseData>>
     */
    public function categoriesForOperation(Operation $operation, int $status): array
    {
        return ConstructibleCategories::built(array_map(
            static fn(array $category): \Closure => $category['build'],
            $this->categories($operation, $status),
        ));
    }

    /**
     * What the negative phase covers for one response, and why it covers
     * nothing where it does not — computed from the document, without
     * drawing a case. The response-side twin of
     * {@see NegativeRequestCaseArbitrary::coverageForOperation()}: `covered`
     * lists one entry per `(kind, location, name)` a category can reach,
     * `skipped` the refusal each unreachable category would have raised
     * (#171).
     *
     * @return array{
     *     covered: list<array{kind: non-empty-string, location: 'status'|'header'|'body', name: string}>,
     *     skipped: list<array{kind: non-empty-string, location: 'status'|'header'|'body', reason: string}>,
     * }
     */
    public function coverageForOperation(Operation $operation, int $status): array
    {
        $covered = [];
        $skipped = [];
        foreach ($this->categories($operation, $status) as ['kind' => $kind, 'location' => $location, 'find' => $find]) {
            try {
                foreach ($find() as $name) {
                    $covered[] = ['kind' => $kind, 'location' => $location, 'name' => $name];
                }
            } catch (UnsupportedGeneration $refusal) {
                $skipped[] = ['kind' => $kind, 'location' => $location, 'reason' => $refusal->getMessage()];
            }
        }

        return ['covered' => $covered, 'skipped' => $skipped];
    }

    /**
     * The one registry of response categories, in weighting order: draws
     * depend on it, so a new category is appended, never inserted. `format`
     * is the last one (#171).
     *
     * @return array<non-empty-string, array{
     *     kind: non-empty-string,
     *     location: 'status'|'header'|'body',
     *     find: \Closure(): list<string>,
     *     build: \Closure(): ArbitraryInterface<ResponseCaseData>,
     * }>
     */
    private function categories(Operation $operation, int $status): array
    {
        return [
            'undeclared-status' => [
                'kind' => 'undeclared-status',
                'location' => 'status',
                'find' => fn(): array => [(string) $this->targets->undeclaredStatus($operation)],
                'build' => fn(): ArbitraryInterface => $this->undeclaredStatusForOperation($operation, $status),
            ],
            'missing-required-header' => [
                'kind' => 'missing-required',
                'location' => 'header',
                'find' => fn(): array => [$this->targets->requiredHeader($operation, $status)],
                'build' => fn(): ArbitraryInterface => $this->missingRequiredHeaderForOperation($operation, $status),
            ],
            'missing-required' => [
                'kind' => 'missing-required',
                'location' => 'body',
                'find' => fn(): array => [$this->targets->missingRequired($operation, $status)],
                'build' => fn(): ArbitraryInterface => $this->missingRequiredForOperation($operation, $status),
            ],
            'type' => $this->witnessCategory($operation, $status, 'type', fn(): ArbitraryInterface => $this->typeMismatchForOperation($operation, $status)),
            'enum' => $this->witnessCategory($operation, $status, 'enum', fn(): ArbitraryInterface => $this->enumMismatchForOperation($operation, $status)),
            'const' => $this->witnessCategory($operation, $status, 'const', fn(): ArbitraryInterface => $this->constMismatchForOperation($operation, $status)),
            'boundary' => $this->witnessCategory($operation, $status, 'boundary', fn(): ArbitraryInterface => $this->boundaryMismatchForOperation($operation, $status)),
            'length' => $this->witnessCategory($operation, $status, 'length', fn(): ArbitraryInterface => $this->lengthMismatchForOperation($operation, $status)),
            'pattern' => $this->witnessCategory($operation, $status, 'pattern', fn(): ArbitraryInterface => $this->patternMismatchForOperation($operation, $status)),
            'additional-properties' => [
                'kind' => 'additional-properties',
                'location' => 'body',
                'find' => fn(): array => [$this->targets->additionalProperty($operation, $status)],
                'build' => fn(): ArbitraryInterface => $this->additionalPropertyForOperation($operation, $status),
            ],
            'media-type' => [
                'kind' => 'media-type',
                'location' => 'body',
                'find' => function () use ($operation, $status): array {
                    $this->targets->mediaTypeMismatch($operation, $status);

                    return ['body'];
                },
                'build' => fn(): ArbitraryInterface => $this->mediaTypeMismatchForOperation($operation, $status),
            ],
            'json-syntax' => [
                'kind' => 'json-syntax',
                'location' => 'body',
                'find' => function () use ($operation, $status): array {
                    $this->targets->requireJsonBody($operation, $status, 'malformed JSON case');

                    return ['body'];
                },
                'build' => fn(): ArbitraryInterface => $this->malformedJsonForOperation($operation, $status),
            ],
            'format' => $this->witnessCategory($operation, $status, 'format', fn(): ArbitraryInterface => $this->formatMismatchForOperation($operation, $status)),
        ];
    }

    /**
     * @param 'type'|'enum'|'const'|'boundary'|'length'|'format'|'pattern' $kind
     * @param \Closure(): ArbitraryInterface<ResponseCaseData> $build
     * @return array{kind: non-empty-string, location: 'body', find: \Closure(): list<string>, build: \Closure(): ArbitraryInterface<ResponseCaseData>}
     */
    private function witnessCategory(Operation $operation, int $status, string $kind, \Closure $build): array
    {
        return [
            'kind' => $kind,
            'location' => 'body',
            'find' => fn(): array => array_map(static fn(array $target): string => $target['name'], $this->targets->bodyWitness($operation, $status, $kind)),
            'build' => $build,
        ];
    }

    /**
     * A status no Response Object resolves to, carrying the body and headers
     * generated for the declared one.
     *
     * @return ArbitraryInterface<ResponseCaseData>
     */
    public function undeclaredStatusForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $undeclared = $this->targets->undeclaredStatus($operation);

        return $this->mutate($operation, $status, static function (array $case) use ($undeclared): array {
            $case['status'] = $undeclared;
            $case['misuse'] = ['kind' => 'undeclared-status', 'location' => 'status', 'name' => (string) $undeclared];

            return $case;
        });
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function missingRequiredHeaderForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $name = $this->targets->requiredHeader($operation, $status);

        return $this->mutate($operation, $status, static function (array $case) use ($name): array {
            unset($case['headers'][$name]);
            $case['misuse'] = ['kind' => 'missing-required', 'location' => 'header', 'name' => $name];

            return $case;
        });
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function missingRequiredForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $name = $this->targets->missingRequired($operation, $status);

        return $this->mutateBody($operation, $status, 'missing-required', $name, static function (array $value) use ($name): array {
            unset($value[$name]);

            return $value;
        });
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function typeMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->witness($operation, $status, 'type');
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function enumMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->witness($operation, $status, 'enum');
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function constMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->witness($operation, $status, 'const');
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function boundaryMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->witness($operation, $status, 'boundary');
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function lengthMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->witness($operation, $status, 'length');
    }

    /**
     * The body value its `format` provably rejects (#171).
     *
     * @return ArbitraryInterface<ResponseCaseData>
     */
    public function formatMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->witness($operation, $status, 'format');
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function patternMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        return $this->witness($operation, $status, 'pattern');
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function additionalPropertyForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $name = $this->targets->additionalProperty($operation, $status);

        return $this->mutateBody($operation, $status, 'additional-properties', $name, static function (array $value) use ($name): array {
            $value[$name] = true;

            return $value;
        });
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function mediaTypeMismatchForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $target = $this->targets->mediaTypeMismatch($operation, $status);

        return $this->mutate($operation, $status, mutation: static function (array $case) use ($target): array {
            $body = $case['body'];
            if ($body === null) {
                throw new \LogicException('JSON response body expected for a media type misuse');
            }
            $body['mediaType'] = $target['invalid'];
            $case['body'] = $body;
            $case['misuse'] = ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'];

            return $case;
        }, json: true);
    }

    /** @return ArbitraryInterface<ResponseCaseData> */
    public function malformedJsonForOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $mediaType = $this->targets->requireJsonBody($operation, $status, 'malformed JSON case')['mediaType'];

        return $this->mutate($operation, $status, static function (array $case) use ($mediaType): array {
            $case['body'] = ['mediaType' => $mediaType, 'encoding' => 'raw', 'value' => '{"malformed":'];
            $case['misuse'] = ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
    }

    /**
     * @param 'type'|'enum'|'const'|'boundary'|'length'|'format'|'pattern' $kind
     * @return ArbitraryInterface<ResponseCaseData>
     */
    private function witness(Operation $operation, int $status, string $kind): ArbitraryInterface
    {
        $targets = $this->targets->bodyWitness($operation, $status, $kind);

        // The valid case is drawn first and the target second, so a case
        // carries the same body its seed produces on its own (#99).
        $drawn = Gen::record([
            'case' => $this->jsonCases($operation, $status),
            'target' => Gen::elements($targets),
        ]);

        /** @var ArbitraryInterface<ResponseCaseData> $mutated */
        $mutated = Gen::map($drawn, function (array $draw) use ($kind): array {
            /** @var array{case: ResponseCaseData, target: array{name: string, invalid: Witness}} $draw */
            $name = $draw['target']['name'];
            $invalid = $draw['target']['invalid'];

            return $this->writeBody($draw['case'], $kind, $name, static function (array $value) use ($name, $invalid): mixed {
                if ($name === '$') {
                    return $invalid;
                }

                // `array_replace()` rather than `array_merge()`: merging
                // renumbers integer keys, and a numeric-string property name
                // (`'12'`) is an integer key in PHP (#98).
                return array_replace($value, [$name => $invalid]);
            }, root: $name === '$');
        });

        return $mutated;
    }

    /**
     * @param non-empty-string $kind
     * @param \Closure(array<string, mixed>): mixed $mutation
     * @return ArbitraryInterface<ResponseCaseData>
     */
    private function mutateBody(Operation $operation, int $status, string $kind, string $name, \Closure $mutation, bool $root = false): ArbitraryInterface
    {
        return $this->mutate($operation, $status, mutation: fn(array $case): array => $this->writeBody($case, $kind, $name, $mutation, $root), json: true);
    }

    /**
     * Writes the mutated value over the drawn case's JSON body and records
     * the misuse.
     *
     * @param ResponseCaseData $case
     * @param non-empty-string $kind
     * @param \Closure(array<string, mixed>): mixed $mutation
     * @return ResponseCaseData
     */
    private function writeBody(array $case, string $kind, string $name, \Closure $mutation, bool $root = false): array
    {
        $body = $case['body'];
        if ($body === null) {
            throw new \LogicException('JSON response body expected for a body misuse');
        }
        if (!$root && (!is_array($body['value']) || (array_is_list($body['value']) && $body['value'] !== []))) {
            throw new \LogicException('JSON object response body expected for a property misuse');
        }
        /** @var array<string, mixed> $input */
        $input = $root || !is_array($body['value']) ? [] : $body['value'];
        $case['body'] = array_merge($body, ['value' => $mutation($input)]);
        $case['misuse'] = ['kind' => $kind, 'location' => 'body', 'name' => $name];

        return $case;
    }

    /**
     * @param \Closure(ResponseCaseData): ResponseCaseData $mutation
     * @return ArbitraryInterface<ResponseCaseData>
     */
    private function mutate(Operation $operation, int $status, \Closure $mutation, bool $json = false): ArbitraryInterface
    {
        /** @var ArbitraryInterface<ResponseCaseData> $mutated */
        $mutated = Gen::map($json ? $this->jsonCases($operation, $status) : $this->valid->forOperation($operation, $status), $mutation);

        return $mutated;
    }

    /**
     * The valid cases that carry the JSON body: a response may offer a body
     * sent as it is beside it (#170), and a misuse of the JSON value is
     * written over a JSON value only. Where JSON is the only body, every
     * draw passes and the draws are the valid ones.
     *
     * @return ArbitraryInterface<ResponseCaseData>
     */
    private function jsonCases(Operation $operation, int $status): ArbitraryInterface
    {
        /** @var ArbitraryInterface<ResponseCaseData> $json */
        $json = Gen::filter(
            $this->valid->forOperation($operation, $status),
            static fn(mixed $case): bool => is_array($case) && is_array($case['body'] ?? null) && ($case['body']['encoding'] ?? null) === 'json',
        );

        return $json;
    }
}
