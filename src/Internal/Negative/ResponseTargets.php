<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Finds the constructive invalidation targets of one response: an
 * undeclared status, a required header, or a JSON body property (the root
 * value when the body is not an object) whose schema admits a witness that
 * the contract validator provably rejects.
 *
 * @internal
 *
 * @psalm-type Target = array{name: string, invalid: mixed}
 */
final readonly class ResponseTargets
{
    private const string ROOT = '$';

    public function __construct(
        private ResponseCaseArbitrary $valid = new ResponseCaseArbitrary(),
        private SchemaProbe $probe = new SchemaProbe(),
        private PatternWitness $patterns = new PatternWitness(),
    ) {}

    /**
     * A status no Response Object resolves to; a `default` response makes
     * every status declared.
     */
    public function undeclaredStatus(Operation $operation): int
    {
        if (array_key_exists('default', $operation->responses)) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" declares a default response; every status is declared', $operation->key));
        }
        for ($candidate = 599; $candidate >= 100; --$candidate) {
            if ($operation->responseFor($candidate) === null) {
                return $candidate;
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" declares every candidate status', $operation->key));
    }

    /** @return non-empty-string */
    public function requiredHeader(Operation $operation, int $status): string
    {
        $definition = $operation->responseFor($status)['definition'] ?? [];
        $headers = $this->mapOf($definition['headers'] ?? null);
        /** @var mixed $header */
        foreach ($headers as $name => $header) {
            if (is_string($name) && $name !== '' && is_array($header) && ($header['required'] ?? false) === true) {
                return $name;
            }
        }

        throw new UnsupportedGeneration(sprintf('Response for status %d of operation "%s" declares no required header', $status, $operation->key));
    }

    /**
     * @return array{invalid: non-empty-string}
     */
    public function mediaTypeMismatch(Operation $operation, int $status): array
    {
        $this->requireJsonBody($operation, $status, 'media type mismatch');
        $selected = $operation->responseFor($status);
        $content = $selected['definition']['content'] ?? [];
        if (!is_array($content)) {
            throw new UnsupportedGeneration('Response content must be an object');
        }
        foreach (array_keys($content) as $declared) {
            if (is_string($declared) && str_contains($declared, '*')) {
                throw new UnsupportedGeneration(sprintf('Operation "%s" declares wildcard media type "%s"; an undeclared media type cannot be promised', $operation->key, $declared));
            }
        }
        $invalid = 'application/x-openapi-misuse';
        while (array_key_exists($invalid, $content)) {
            $invalid .= '-x';
        }

        return ['invalid' => $invalid];
    }

    /** @return array{mediaType: non-empty-string, schema: array<string, mixed>} */
    public function requireJsonBody(Operation $operation, int $status, string $purpose): array
    {
        $body = $this->valid->jsonBody($operation, $status);
        if ($body === null) {
            throw new UnsupportedGeneration(sprintf('Response for status %d of operation "%s" has no JSON body for a %s', $status, $operation->key, $purpose));
        }

        return $body;
    }

    /** @return non-empty-string */
    public function missingRequired(Operation $operation, int $status): string
    {
        $schema = $this->requireJsonBody($operation, $status, 'missing required property')['schema'];
        $required = $this->mapOf($schema['required'] ?? null);
        if ($this->isObject($schema)) {
            /** @var mixed $name */
            foreach ($required as $name) {
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        }

        throw new UnsupportedGeneration(sprintf('Response for status %d of operation "%s" has no required body property', $status, $operation->key));
    }

    /** @return non-empty-string */
    public function additionalProperty(Operation $operation, int $status): string
    {
        $schema = $this->requireJsonBody($operation, $status, 'additional property')['schema'];
        if (!$this->isObject($schema) || ($schema['additionalProperties'] ?? null) !== false) {
            throw new UnsupportedGeneration(sprintf('Response for status %d of operation "%s" does not reject additional properties', $status, $operation->key));
        }
        $properties = $this->mapOf($schema['properties'] ?? null);
        $name = '__openapi_extra_property__';
        while (array_key_exists($name, $properties)) {
            $name .= '_';
        }

        return $name;
    }

    /**
     * @param 'type'|'enum'|'const'|'boundary'|'length'|'pattern' $kind
     * @return Target
     */
    public function bodyWitness(Operation $operation, int $status, string $kind): array
    {
        $schema = $this->requireJsonBody($operation, $status, $kind . ' mismatch')['schema'];
        foreach ($this->candidates($schema) as $name => $candidate) {
            $invalid = $this->witness($candidate, $kind);
            if ($invalid !== null) {
                return ['name' => $name, 'invalid' => $invalid['value']];
            }
        }

        throw new UnsupportedGeneration(sprintf('Response for status %d of operation "%s" has no body value with a constructible %s mismatch', $status, $operation->key, $kind));
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>> keyed by property name, or `$` for a scalar root
     */
    private function candidates(array $schema): array
    {
        if (!$this->isObject($schema)) {
            return [self::ROOT => $schema];
        }
        $candidates = [];
        $properties = $this->mapOf($schema['properties'] ?? null);
        /** @var mixed $property */
        foreach ($properties as $name => $property) {
            if (is_string($name) && $name !== '' && is_array($property) && !array_is_list($property)) {
                /** @var array<string, mixed> $property */
                $candidates[$name] = $property;
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $schema
     * @param 'type'|'enum'|'const'|'boundary'|'length'|'pattern' $kind
     * @return null|array{value: mixed}
     */
    private function witness(array $schema, string $kind): ?array
    {
        if (($schema['nullable'] ?? false) === true || array_key_exists('not', $schema)) {
            return null;
        }
        $types = array_values($this->probe->declaredTypes($schema));

        return match ($kind) {
            'type' => $this->typeWitness($schema, $types),
            'enum' => $this->enumWitness($schema),
            'const' => $this->constWitness($schema),
            'boundary' => $this->numericWitness($this->probe->outOfRangeValue($schema), $types),
            'length' => $this->lengthWitness($schema, $types),
            'pattern' => $this->patternWitness($schema),
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed> $types
     * @return null|array{value: mixed}
     */
    private function typeWitness(array $schema, array $types): ?array
    {
        if (count($types) !== 1 || array_key_exists('enum', $schema) || array_key_exists('const', $schema)) {
            return null;
        }

        return match ($types[0]) {
            'integer', 'number', 'boolean', 'null', 'array', 'object' => ['value' => 'not-a-' . $types[0]],
            'string' => ['value' => 4096],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @return null|array{value: mixed}
     */
    private function enumWitness(array $schema): ?array
    {
        $enum = $schema['enum'] ?? null;
        if (!is_array($enum) || $enum === [] || !$this->probe->isScalarEnum($enum)) {
            return null;
        }
        $invalid = '__openapi_misuse__';
        while (in_array($invalid, $enum, strict: true)) {
            $invalid .= '_';
        }

        return ['value' => $invalid];
    }

    /**
     * @param array<string, mixed> $schema
     * @return null|array{value: mixed}
     */
    private function constWitness(array $schema): ?array
    {
        if (!array_key_exists('const', $schema)) {
            return null;
        }
        $const = $schema['const'];
        if (!is_scalar($const) && $const !== null) {
            return null;
        }

        return ['value' => is_string($const) ? $const . '__openapi_misuse__' : '__openapi_misuse__'];
    }

    /**
     * @param list<mixed> $types
     * @return null|array{value: mixed}
     */
    private function numericWitness(?string $wire, array $types): ?array
    {
        if ($wire === null) {
            return null;
        }

        return ['value' => in_array('integer', $types, strict: true) ? (int) $wire : (float) $wire];
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed> $types
     * @return null|array{value: mixed}
     */
    private function lengthWitness(array $schema, array $types): ?array
    {
        $string = $this->probe->outOfLengthValue($schema);
        if ($string !== null) {
            return ['value' => $string];
        }
        if (!in_array('array', $types, strict: true)) {
            return null;
        }
        $minItems = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;
        if ($minItems >= 1) {
            return ['value' => []];
        }
        $maxItems = is_int($schema['maxItems'] ?? null) ? (int) $schema['maxItems'] : 64;
        if ($maxItems >= 0 && $maxItems < 64) {
            return ['value' => array_fill(0, $maxItems + 1, null)];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return null|array{value: mixed}
     */
    private function patternWitness(array $schema): ?array
    {
        $constraints = $this->probe->patternConstraints($schema);
        if ($constraints === null) {
            return null;
        }
        /** @var non-empty-string $pattern */
        $pattern = $constraints['pattern'];
        /** @var int<0, max> $minLength */
        $minLength = $constraints['minLength'];
        /** @var int<0, max> $maxLength */
        $maxLength = $constraints['maxLength'];
        $witness = $this->patterns->search($pattern, $minLength, $maxLength);

        return $witness === null ? null : ['value' => $witness];
    }

    /** @param array<string, mixed> $schema */
    private function isObject(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object' || array_key_exists('properties', $schema);
    }

    /** @return array<array-key, mixed> */
    private function mapOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
