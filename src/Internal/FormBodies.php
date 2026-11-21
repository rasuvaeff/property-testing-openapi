<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Valid `application/x-www-form-urlencoded` request bodies: an object schema
 * in `form` style, every required container non-empty, and every exploded
 * object property without members its wire form cannot carry.
 *
 * @internal
 *
 * @psalm-import-type Schema from SchemaShape
 */
final readonly class FormBodies
{
    /** The body compiler: a request never carries a `readOnly` member. */
    private SchemaArbitraryCompiler $bodySchemas;

    public function __construct()
    {
        $this->bodySchemas = new SchemaArbitraryCompiler(direction: SchemaDirection::Request);
    }

    /**
     * @param Schema $schema the media type's schema, already narrowed to the request
     * @param array<string, mixed> $definition the Media Type Object
     * @return ArbitraryInterface<mixed> a `BodyData` of `encoding: 'form'`
     */
    public function arbitrary(string $mediaType, array $schema, array $definition): ArbitraryInterface
    {
        if (!SchemaShape::isObject($schema)) {
            throw UnsupportedGeneration::forSchema('Form request body schema must be an object');
        }
        $this->assertEncoding($definition['encoding'] ?? []);
        /** @var ArbitraryInterface<mixed> $form */
        $form = Gen::map($this->bodySchemas->compile($this->nonEmptyRequiredProperties($this->explodedObjectsWithoutExtras($schema, $definition['encoding'] ?? []))), static fn(mixed $value): array => [
            'mediaType' => $mediaType,
            'encoding' => 'form',
            'value' => $value,
        ]);

        return $form;
    }

    /**
     * A form property with an object schema and `explode: true` (the form
     * default) is written as flat `member=value` pairs, so its wire form can
     * carry only the members the document declares: an undeclared member the
     * generator added would land as a top-level member of the body, where it
     * collides with a declared property or violates `additionalProperties:
     * false`. Such an object is generated without extras, and one whose
     * `minProperties` its declared properties cannot meet fails closed here
     * rather than as a run-time exhaustion (#120).
     *
     * @param Schema $schema
     * @return Schema
     */
    private function explodedObjectsWithoutExtras(array $schema, mixed $encoding): array
    {
        $properties = is_array($schema['properties'] ?? null) ? (array) $schema['properties'] : [];
        foreach (array_keys($properties) as $name) {
            $property = $properties[$name];
            if (!is_array($property) || array_is_list($property)) {
                continue;
            }
            /** @var array<string, mixed> $property */
            if (!SchemaShape::isObject($property)) {
                continue;
            }
            $configuration = is_array($encoding) && is_array($encoding[$name] ?? null) ? (array) $encoding[$name] : [];
            if (($configuration['explode'] ?? true) !== true) {
                continue;
            }
            $declared = is_array($property['properties'] ?? null) ? count((array) $property['properties']) : 0;
            $minimum = is_int($property['minProperties'] ?? null) ? (int) $property['minProperties'] : 0;
            if ($minimum > $declared) {
                throw UnsupportedGeneration::forSchema(sprintf('form property "%s" is an exploded object whose minProperties %d cannot be met by its %d declared properties, and its wire form carries no undeclared member', (string) $name, $minimum, $declared));
            }
            $property['additionalProperties'] = false;
            $properties[$name] = $property;
        }

        return array_merge($schema, ['properties' => $properties]);
    }

    /**
     * @param Schema $schema
     * @return Schema
     */
    private function nonEmptyRequiredProperties(array $schema): array
    {
        $required = is_array($schema['required'] ?? null) ? (array) $schema['required'] : [];
        $properties = is_array($schema['properties'] ?? null) ? (array) $schema['properties'] : [];
        foreach ($required as $name) {
            if (!is_string($name)) {
                continue;
            }
            $property = $properties[$name] ?? null;
            if (!is_array($property) || array_is_list($property)) {
                continue;
            }
            /** @var array<string, mixed> $property */
            $properties[$name] = SchemaShape::nonEmpty($property);
        }

        return array_merge($schema, ['properties' => $properties]);
    }

    private function assertEncoding(mixed $encoding): void
    {
        if ($encoding === null || $encoding === []) {
            return;
        }
        if (!is_array($encoding) || array_is_list($encoding)) {
            throw UnsupportedGeneration::forSchema('Form encoding must be an object');
        }
        foreach ($encoding as $name => $configuration) {
            if (!is_string($name) || !is_array($configuration) || array_is_list($configuration)) {
                throw UnsupportedGeneration::forSchema('Form encoding entries must be objects');
            }
            if (($configuration['style'] ?? 'form') !== 'form' || (isset($configuration['explode']) && !is_bool($configuration['explode']))) {
                throw UnsupportedGeneration::forSchema('Form encoding supports only form style and boolean explode');
            }
        }
    }
}
