<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * The wire view of a parameter schema. A parameter travels as text, so OAS
 * 3.0 `nullable` has no representation ("absent" is the optional branch)
 * and leaves every nested schema together with `null` enum members and a
 * `null` const; a path parameter additionally has to stay
 * inside its template segment after percent-decoding, so its strings are
 * generated non-empty and without `/` or `\`, and a format that always
 * carries a slash fails closed.
 *
 * @internal
 */
final readonly class ParameterSchemas
{
    private const array SLASH_FORMATS = ['uri', 'uri-reference', 'url'];

    /**
     * @param array<string, mixed> $schema
     * @param 'path'|'query'|'header'|'cookie' $location
     * @return array<string, mixed>
     */
    public function forLocation(array $schema, string $location): array
    {
        return $this->rewrite($schema, $location === 'path');
    }

    /**
     * Whether every string of a generated path value (scalar, list item,
     * map key or value) survives the template segment.
     */
    public function isPathSafe(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '' && !str_contains($value, '/') && !str_contains($value, '\\');
        }
        if (!is_array($value)) {
            return true;
        }
        foreach (array_keys($value) as $key) {
            if (is_string($key) && !$this->isPathSafe($key)) {
                return false;
            }
            if (!$this->isPathSafe($value[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function rewrite(array $schema, bool $path): array
    {
        unset($schema['nullable']);
        if (array_key_exists('const', $schema) && $schema['const'] === null) {
            throw UnsupportedGeneration::forSchema('a parameter cannot carry a null const');
        }
        if (is_array($schema['enum'] ?? null)) {
            $members = array_values(array_filter((array) $schema['enum'], static fn(mixed $member): bool => $member !== null));
            if ($members === []) {
                throw UnsupportedGeneration::forSchema('a parameter enum needs a non-null member');
            }
            $schema['enum'] = $members;
        }
        if ($path) {
            $schema = $this->pathSegment($schema);
        }
        foreach (['items', 'additionalProperties', 'not'] as $keyword) {
            if (is_array($schema[$keyword] ?? null) && !array_is_list((array) $schema[$keyword])) {
                /** @var array<string, mixed> $nested */
                $nested = $schema[$keyword];
                $schema[$keyword] = $this->rewrite($nested, $path);
            }
        }
        if (is_array($schema['properties'] ?? null)) {
            /** @var array<array-key, mixed> $properties */
            $properties = (array) $schema['properties'];
            foreach (array_keys($properties) as $name) {
                if (is_array($properties[$name]) && !array_is_list($properties[$name])) {
                    /** @var array<string, mixed> $property */
                    $property = $properties[$name];
                    $properties[$name] = $this->rewrite($property, $path);
                }
            }
            $schema['properties'] = $properties;
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null) || !array_is_list((array) $schema[$keyword])) {
                continue;
            }
            /** @var list<mixed> $branches */
            $branches = (array) $schema[$keyword];
            $schema[$keyword] = array_map(function (mixed $branch) use ($path): mixed {
                if (!is_array($branch) || array_is_list($branch)) {
                    return $branch;
                }

                /** @var array<string, mixed> $branch */
                return $this->rewrite($branch, $path);
            }, $branches);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function pathSegment(array $schema): array
    {
        if (array_key_exists('const', $schema) && !$this->isPathSafe($schema['const'])) {
            throw UnsupportedGeneration::forSchema('path parameter const cannot be carried by a template segment');
        }
        if (is_array($schema['enum'] ?? null)) {
            $safe = array_values(array_filter((array) $schema['enum'], $this->isPathSafe(...)));
            if ($safe === []) {
                throw UnsupportedGeneration::forSchema('no path parameter enum member can be carried by a template segment');
            }
            $schema['enum'] = $safe;
        }
        if (!$this->isStringSchema($schema)) {
            return $schema;
        }
        $format = is_string($schema['format'] ?? null) ? (string) $schema['format'] : null;
        if ($format !== null && in_array($format, self::SLASH_FORMATS, strict: true)) {
            throw UnsupportedGeneration::forSchema(sprintf('path parameter format "%s" always carries a slash', $format));
        }
        if (is_int($schema['minLength'] ?? 0)) {
            $schema['minLength'] = max(1, (int) ($schema['minLength'] ?? 0));
        }

        return $schema;
    }

    /** @param array<string, mixed> $schema */
    private function isStringSchema(array $schema): bool
    {
        if (($schema['type'] ?? null) === 'string') {
            return true;
        }
        if (is_array($schema['type'] ?? null)) {
            return in_array('string', (array) $schema['type'], strict: true);
        }

        return !array_key_exists('type', $schema) && (array_key_exists('format', $schema) || array_key_exists('pattern', $schema) || array_key_exists('minLength', $schema) || array_key_exists('maxLength', $schema));
    }
}
