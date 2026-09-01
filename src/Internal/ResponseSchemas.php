<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * The response-direction view of a schema: `writeOnly` properties are not
 * part of a response, so they leave `properties` and `required` the way the
 * contract validator drops them before checking a response body.
 *
 * @internal
 */
final readonly class ResponseSchemas
{
    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function effective(array $schema): array
    {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            /** @var array<array-key, mixed> $properties */
            $properties = $schema['properties'];
            $kept = [];
            foreach (array_keys($properties) as $name) {
                $property = $properties[$name];
                if (!is_string($name) || !is_array($property) || array_is_list($property)) {
                    continue;
                }
                if (($property['writeOnly'] ?? false) === true) {
                    continue;
                }
                /** @var array<string, mixed> $property */
                $kept[$name] = $this->effective($property);
            }
            $schema['properties'] = $kept;
            if (isset($schema['required']) && is_array($schema['required'])) {
                $schema['required'] = array_values(array_filter($schema['required'], static fn(mixed $name): bool => is_string($name) && array_key_exists($name, $kept)));
            }
        }
        if (isset($schema['items']) && is_array($schema['items']) && !array_is_list($schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = $schema['items'];
            $schema['items'] = $this->effective($items);
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword]) || !array_is_list($schema[$keyword])) {
                continue;
            }
            /** @var list<mixed> $parts */
            $parts = $schema[$keyword];
            $schema[$keyword] = array_map(function (mixed $part): mixed {
                if (!is_array($part) || array_is_list($part)) {
                    return $part;
                }

                /** @var array<string, mixed> $part */
                return $this->effective($part);
            }, $parts);
        }

        return $schema;
    }
}
