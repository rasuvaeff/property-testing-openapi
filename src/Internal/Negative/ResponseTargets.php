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
    public function __construct(
        private ResponseCaseArbitrary $valid = new ResponseCaseArbitrary(),
        private JsonBodyWitness $witnesses = new JsonBodyWitness(),
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
        $target = $this->witnesses->find($schema, $kind);
        if ($target !== null) {
            return $target;
        }

        throw new UnsupportedGeneration(sprintf('Response for status %d of operation "%s" has no body value with a constructible %s mismatch', $status, $operation->key, $kind));
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
