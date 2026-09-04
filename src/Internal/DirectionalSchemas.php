<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * One direction of a schema: properties flagged for the other direction
 * (`readOnly` for a request, `writeOnly` for a response) are dropped, along
 * with their `required` entries; nested schemas under `items` and the
 * combinators follow. Malformed members pass through untouched, so the
 * compiler still fails closed on them.
 *
 * Direction is the only reason a property is dropped, which is also how
 * `openapi-contract` reads its own effective schema — the two used to differ,
 * because the contract additionally discarded any member shape it did not
 * recognise, and that silently unchecked part of the document. Dropping the
 * last property drops `properties` itself rather than leaving an empty map,
 * for the same reason the contract does: an empty `properties` forbids
 * nothing, and what the document says about undeclared members keeps saying
 * it.
 *
 * @internal
 */
final readonly class DirectionalSchemas
{
    /**
     * @param 'readOnly'|'writeOnly' $flag
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function effective(array $schema, string $flag): array
    {
        if (is_array($schema['properties'] ?? null)) {
            /** @var array<array-key, mixed> $properties */
            $properties = (array) $schema['properties'];
            /** @var array<array-key, mixed> $kept */
            $kept = [];
            $dropped = [];
            foreach (array_keys($properties) as $name) {
                $property = $properties[$name];
                if (!is_array($property) || array_is_list($property)) {
                    $kept += [$name => $property];

                    continue;
                }
                if (($property[$flag] ?? false) === true) {
                    $dropped[$name] = true;

                    continue;
                }
                /** @var array<string, mixed> $property */
                $kept += [$name => $this->effective($property, $flag)];
            }
            if ($kept === []) {
                unset($schema['properties']);
            } else {
                $schema['properties'] = $kept;
            }
            if (array_key_exists('required', $schema) && is_array($schema['required'])) {
                $schema['required'] = array_values(array_filter($schema['required'], static fn(mixed $name): bool => !is_string($name) || !isset($dropped[$name])));
            }
        }
        if (is_array($schema['items'] ?? null) && !array_is_list((array) $schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = (array) $schema['items'];
            $schema['items'] = $this->effective($items, $flag);
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null) || !array_is_list((array) $schema[$keyword])) {
                continue;
            }
            /** @var list<mixed> $parts */
            $parts = (array) $schema[$keyword];
            $schema[$keyword] = array_map(function (mixed $part) use ($flag): mixed {
                if (!is_array($part) || array_is_list($part)) {
                    return $part;
                }

                /** @var array<string, mixed> $part */
                return $this->effective($part, $flag);
            }, $parts);
        }

        return $schema;
    }

    /**
     * Drops the flagged members from a value the way {@see effective()} drops
     * them from its schema, so a document example shared between directions
     * stays valid for one of them.
     *
     * @param 'readOnly'|'writeOnly' $flag
     * @param array<string, mixed> $schema
     */
    public function value(mixed $value, array $schema, string $flag): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $items = $schema['items'] ?? null;
            if (!is_array($items) || array_is_list($items)) {
                return $value;
            }

            /** @var array<string, mixed> $items */
            return array_map(fn(mixed $item): mixed => $this->value($item, $items, $flag), $value);
        }
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return $value;
        }
        /** @var array<array-key, mixed> $result */
        $result = [];
        foreach (array_keys($value) as $name) {
            if ($this->isFlagged($properties[$name] ?? null, $flag)) {
                continue;
            }
            $result += [$name => $this->member($value[$name], $properties[$name] ?? null, $flag)];
        }

        return $result;
    }

    /** @param 'readOnly'|'writeOnly' $flag */
    private function isFlagged(mixed $property, string $flag): bool
    {
        return is_array($property) && !array_is_list($property) && ($property[$flag] ?? false) === true;
    }

    /** @param 'readOnly'|'writeOnly' $flag */
    private function member(mixed $member, mixed $property, string $flag): mixed
    {
        if (!is_array($property) || array_is_list($property)) {
            return $member;
        }

        /** @var array<string, mixed> $property */
        return $this->value($member, $property, $flag);
    }
}
