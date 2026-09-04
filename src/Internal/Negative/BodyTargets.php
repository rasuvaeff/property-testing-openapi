<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Finds the required JSON body the body-side misuse categories invalidate.
 *
 * @internal
 */
final readonly class BodyTargets
{
    public function __construct(
        private SchemaProbe $probe = new SchemaProbe(),
    ) {}

    /** @return array{name: non-empty-string} */
    public function additionalProperty(Operation $operation): array
    {
        $body = $this->jsonBody($operation);
        if ($body !== null
            && in_array('object', $this->probe->declaredTypes($body['schema']), strict: true)
            && ($body['schema']['additionalProperties'] ?? null) === false
        ) {
            return ['name' => $this->unusedPropertyName($body['schema']['properties'] ?? null)];
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required JSON object body rejecting additional properties', $operation->key));
    }

    /**
     * A declared wildcard media type could match the substitute Content-Type,
     * so such operations fail closed.
     *
     * @return array{invalid: non-empty-string}
     */
    public function mediaTypeMismatch(Operation $operation): array
    {
        if ($this->jsonBody($operation) === null) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no required JSON body for a media type mismatch', $operation->key));
        }
        $content = $operation->requestBody['content'] ?? null;
        if (!is_array($content)) {
            throw new UnsupportedGeneration('Request body content must be an object');
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

    /** @return array{mediaType: non-empty-string, schema: array<string, mixed>}|null */
    public function jsonBody(Operation $operation): ?array
    {
        if (($operation->requestBody['required'] ?? false) !== true) {
            return null;
        }
        $content = $operation->requestBody['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }
        foreach ($content as $mediaType => $definition) {
            if (!is_string($mediaType) || $mediaType === '' || !is_array($definition)) {
                continue;
            }
            if (!MediaType::isJson($mediaType)) {
                continue;
            }
            $schema = $definition['schema'] ?? [];
            if (!is_array($schema) || array_is_list($schema)) {
                return null;
            }
            /** @var array<string, mixed> $schema */

            return ['mediaType' => $mediaType, 'schema' => $schema];
        }

        return null;
    }

    /** @return non-empty-string */
    private function unusedPropertyName(mixed $properties): string
    {
        $name = '__openapi_extra_property__';
        if (is_array($properties)) {
            while (array_key_exists($name, $properties)) {
                $name .= '_';
            }
        }

        return $name;
    }
}
