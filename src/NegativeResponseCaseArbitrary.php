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
     * constructively; a response with no constructible category fails closed.
     *
     * @return ArbitraryInterface<ResponseCaseData>
     */
    public function forOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $factories = [
            fn(): ArbitraryInterface => $this->undeclaredStatusForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->missingRequiredHeaderForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->missingRequiredForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->typeMismatchForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->enumMismatchForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->constMismatchForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->boundaryMismatchForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->lengthMismatchForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->patternMismatchForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->additionalPropertyForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->mediaTypeMismatchForOperation($operation, $status),
            fn(): ArbitraryInterface => $this->malformedJsonForOperation($operation, $status),
        ];

        return ConstructibleCategories::anyOf($factories, sprintf('Response for status %d of operation "%s" supports no constructible negative case category', $status, $operation->key));
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

        return $this->mutate($operation, $status, static function (array $case) use ($target): array {
            $body = $case['body'];
            if ($body === null) {
                throw new \LogicException('JSON response body expected for a media type misuse');
            }
            $body['mediaType'] = $target['invalid'];
            $case['body'] = $body;
            $case['misuse'] = ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
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
     * @param 'type'|'enum'|'const'|'boundary'|'length'|'pattern' $kind
     * @return ArbitraryInterface<ResponseCaseData>
     */
    private function witness(Operation $operation, int $status, string $kind): ArbitraryInterface
    {
        $targets = $this->targets->bodyWitness($operation, $status, $kind);

        // The valid case is drawn first and the target second, so a case
        // carries the same body its seed produces on its own (#99).
        $drawn = Gen::record([
            'case' => $this->valid->forOperation($operation, $status),
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
        return $this->mutate($operation, $status, fn(array $case): array => $this->writeBody($case, $kind, $name, $mutation, $root));
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
    private function mutate(Operation $operation, int $status, \Closure $mutation): ArbitraryInterface
    {
        /** @var ArbitraryInterface<ResponseCaseData> $mutated */
        $mutated = Gen::map($this->valid->forOperation($operation, $status), $mutation);

        return $mutated;
    }
}
