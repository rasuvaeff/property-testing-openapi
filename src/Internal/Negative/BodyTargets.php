<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Finds the required body the body-side misuse categories invalidate: the JSON
 * body itself, or the multipart part whose declared media type one of them
 * contradicts.
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

    /**
     * The multipart part whose declared media type a misuse can contradict.
     *
     * `encoding.contentType` is the one body keyword whose neglect is
     * fail-open: a validator that reads it and ignores it accepts every valid
     * case unchanged, so no amount of valid traffic can tell the two apart.
     * Only a part built to carry the wrong media type can — which is why this
     * exists at all (#80).
     *
     * A declared wildcard would match the substitute type, so a part carrying
     * one is skipped for the next declared part rather than ending the search;
     * an operation whose declared parts are all wildcards fails closed rather
     * than promising a contradiction it cannot construct.
     *
     * @return array{property: non-empty-string, invalid: non-empty-string}
     */
    public function partContentTypeMismatch(Operation $operation): array
    {
        $wildcard = null;
        foreach ($this->multipartEncoding($operation) as $property => $contentType) {
            if (str_contains($contentType, '*')) {
                $wildcard ??= $contentType;

                continue;
            }
            $allowed = array_map(trim(...), explode(',', strtolower($contentType)));
            $invalid = 'application/x-openapi-misuse';
            while (in_array($invalid, $allowed, strict: true)) {
                $invalid .= '-x';
            }

            return ['property' => $property, 'invalid' => $invalid];
        }

        if ($wildcard !== null) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" declares wildcard part content type "%s"; a contradicting one cannot be promised', $operation->key, $wildcard));
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required multipart body declaring a part content type', $operation->key));
    }

    /**
     * The `contentType` each required multipart property declares, in
     * declaration order. A property without one is left out: its part carries
     * whatever media type the schema implies, and contradicting an implied
     * type would be a statement about this generator rather than about the
     * document.
     *
     * A body that can travel as more than one media type declares no single
     * part list to rewrite — which one a valid case carries is a generation
     * choice, not a document fact — so it declares nothing here.
     *
     * @return array<non-empty-string, non-empty-string>
     */
    private function multipartEncoding(Operation $operation): array
    {
        if (($operation->requestBody['required'] ?? false) !== true) {
            return [];
        }
        $content = $operation->requestBody['content'] ?? null;
        if (!is_array($content) || count($content) !== 1) {
            return [];
        }
        $mediaType = array_key_first($content);
        if (!is_string($mediaType) || !str_starts_with(MediaType::normalize($mediaType), 'multipart/') || !is_array($content[$mediaType])) {
            return [];
        }
        /** @var array<string, mixed> $definition */
        $definition = $content[$mediaType];
        $encoding = $definition['encoding'] ?? null;
        $schema = $definition['schema'] ?? null;
        if (!is_array($encoding) || !is_array($schema)) {
            return [];
        }
        // Only a required property: the misuse rewrites a part that is already
        // there, and an optional one is absent from most cases — the case
        // would then record a contradiction it does not carry.
        $required = $schema['required'] ?? null;
        if (!is_array($required)) {
            return [];
        }
        $declared = [];
        foreach (array_keys($encoding) as $property) {
            if (!is_string($property) || $property === '' || !is_array($encoding[$property]) || !in_array($property, $required, strict: true)) {
                continue;
            }
            /** @var array<string, mixed> $configuration */
            $configuration = $encoding[$property];
            // `isset()` narrows the offset for static analysis; the two reads
            // after it are the ones that decide.
            if (isset($configuration['contentType']) && is_string($configuration['contentType']) && $configuration['contentType'] !== '') {
                $declared[$property] = $configuration['contentType'];
            }
        }

        return $declared;
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
