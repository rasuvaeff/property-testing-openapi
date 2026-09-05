<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * The wire view of a parameter schema. A parameter travels as text, so
 * neither OAS 3.0 `nullable` nor a 3.1 `null` member of a type union has a
 * representation ("absent" is the optional branch), and both leave every
 * nested schema together with `null` enum members and a `null` const.
 *
 * A location or a style can narrow it further, and always by construction: a
 * path parameter has to stay inside its template segment after
 * percent-decoding, so its strings are generated non-empty and without `/` or
 * `\`, and a format that always carries a slash fails closed; a delimited
 * query parameter has no escape for its own separator, so no generated string
 * may carry one.
 *
 * @internal
 */
final readonly class ParameterSchemas
{
    private const array SLASH_FORMATS = ['uri', 'uri-reference', 'url'];

    /**
     * The separator each delimited style joins its items with. The style has
     * no escape for it, so a value carrying one is unrepresentable rather
     * than merely awkward — {@see ParameterSerializer::delimited()} refuses to
     * emit it, and refusing at generation time is the difference between a
     * narrowed domain and four runs in five dying on an error that cannot
     * shrink.
     */
    private const array STYLE_SEPARATORS = [
        'spaceDelimited' => ' ',
        'pipeDelimited' => '|',
    ];

    /**
     * @param array<string, mixed> $schema
     * @param 'path'|'query'|'header'|'cookie' $location
     * @return array<string, mixed>
     */
    public function forLocation(array $schema, string $location, string $style = 'form'): array
    {
        return $this->rewrite($schema, $location === 'path', self::separatorOf($location, $style, $schema));
    }

    /**
     * The characters a generated value for this parameter may not contain, or
     * `null` when nothing about the wire forbids one.
     *
     * A header used to impose nothing here, because a value that carried the
     * style's comma could be percent-encoded past it. It cannot any more: a
     * header field value is read exactly as sent (openapi-contract#66), so the
     * comma of a list or an object header separates whatever a member meant by
     * it, and the optional whitespace RFC 9110 allows around that comma is
     * stripped from both ends of every member. A scalar header is untouched by
     * both rules and keeps the whole alphabet.
     *
     * @param array<string, mixed> $schema
     */
    public static function separatorOf(string $location, string $style, array $schema = []): ?string
    {
        if ($location === 'header') {
            // A space is out whatever the shape: a field value is read with
            // the optional whitespace stripped from both ends, and a generator
            // does not control where in a string its space lands. A list or an
            // object loses the comma too, which is what separates its members
            // now that nothing escapes it.
            return SchemaShape::isArray($schema) || SchemaShape::isObject($schema) ? ', ' : ' ';
        }

        return $location === 'query' ? (self::STYLE_SEPARATORS[$style] ?? null) : null;
    }

    /**
     * Whether every string of a generated value survives its style's
     * separator. The schema rewrite constructs values without it; this guards
     * what the rewrite cannot see — a `pattern`, whose alphabet is the
     * pattern's own.
     */
    public function isSeparatorSafe(mixed $value, string $separator): bool
    {
        if (is_string($value)) {
            // `$separator` is a set of characters, not one string to look for:
            // a header excludes both the comma it splits on and the whitespace
            // trimmed around it.
            return strcspn($value, $separator) === strlen($value);
        }
        if (!is_array($value)) {
            return true;
        }
        foreach (array_keys($value) as $key) {
            if (is_string($key) && str_contains($key, $separator)) {
                return false;
            }
            if (!$this->isSeparatorSafe($value[$key], $separator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every string of a generated value can travel as an HTTP field
     * value at all. RFC 9110 admits visible characters and interior
     * whitespace, and a PSR-7 implementation refuses the rest outright — a
     * newline in a header is a request smuggling primitive, not a value.
     *
     * The schema rewrite already keeps generated strings inside printable
     * ASCII; this guards what it cannot see, a `pattern` or a `format` whose
     * alphabet is its own.
     */
    public function isHeaderSafe(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/\A[\x21-\x7e](?:[\x20-\x7e]*[\x21-\x7e])?\z/', $value) === 1 || $value === '';
        }
        if (!is_array($value)) {
            return true;
        }
        foreach (array_keys($value) as $key) {
            if (is_string($key) && !$this->isHeaderSafe($key)) {
                return false;
            }
            if (!$this->isHeaderSafe($value[$key])) {
                return false;
            }
        }

        return true;
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
    private function rewrite(array $schema, bool $path, ?string $separator): array
    {
        unset($schema['nullable']);
        $schema = $this->withoutNullType($schema);
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
        if ($separator !== null) {
            $schema = $this->delimitedItem($schema, $separator);
        }
        foreach (['items', 'additionalProperties', 'not'] as $keyword) {
            if (is_array($schema[$keyword] ?? null) && !array_is_list((array) $schema[$keyword])) {
                /** @var array<string, mixed> $nested */
                $nested = $schema[$keyword];
                $schema[$keyword] = $this->rewrite($nested, $path, $separator);
            }
        }
        if (is_array($schema['properties'] ?? null)) {
            /** @var array<array-key, mixed> $properties */
            $properties = (array) $schema['properties'];
            foreach (array_keys($properties) as $name) {
                if (is_array($properties[$name]) && !array_is_list($properties[$name])) {
                    /** @var array<string, mixed> $property */
                    $property = $properties[$name];
                    $properties[$name] = $this->rewrite($property, $path, $separator);
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
            $schema[$keyword] = array_map(function (mixed $branch) use ($path, $separator): mixed {
                if (!is_array($branch) || array_is_list($branch)) {
                    return $branch;
                }

                /** @var array<string, mixed> $branch */
                return $this->rewrite($branch, $path, $separator);
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

    /**
     * OAS 3.1 spells the absent branch as a `null` member of a type union, the
     * way 3.0 spells it `nullable`. A parameter travels as text and has no
     * representation for either.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withoutNullType(array $schema): array
    {
        $declared = $schema['type'] ?? null;
        if (!is_array($declared) || !in_array('null', $declared, strict: true)) {
            return $schema;
        }
        $types = [];
        /** @var mixed $type */
        foreach ($declared as $type) {
            if ($type === 'null') {
                continue;
            }
            if (!is_string($type)) {
                // Malformed, and not ours to repair: the compiler fails closed
                // on it with a message about the type it actually found.
                return $schema;
            }
            $types[] = $type;
        }
        if ($types === []) {
            throw UnsupportedGeneration::forSchema('a parameter type union needs a member other than null');
        }
        $schema['type'] = count($types) === 1 ? $types[0] : $types;

        return $schema;
    }

    /**
     * A delimited style joins its items with a character it cannot escape, so
     * a value carrying one is unrepresentable. The members that can be
     * narrowed are narrowed here — a `const` or an `enum` fails closed or
     * loses the unusable members — and the alphabet of a plain string is
     * narrowed by the compiler; a `pattern` is the one form neither can see,
     * and {@see isSeparatorSafe()} guards it.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function delimitedItem(array $schema, string $separator): array
    {
        if (array_key_exists('const', $schema) && !$this->isSeparatorSafe($schema['const'], $separator)) {
            throw UnsupportedGeneration::forSchema(sprintf('a delimited parameter const cannot contain "%s"', $separator));
        }
        if (is_array($schema['enum'] ?? null)) {
            $safe = array_values(array_filter((array) $schema['enum'], fn(mixed $member): bool => $this->isSeparatorSafe($member, $separator)));
            if ($safe === []) {
                throw UnsupportedGeneration::forSchema(sprintf('no delimited parameter enum member can avoid "%s"', $separator));
            }
            $schema['enum'] = $safe;
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
