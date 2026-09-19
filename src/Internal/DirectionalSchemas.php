<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDirection;

/**
 * One direction of a schema and of a value.
 *
 * The schema view is the contract's own ({@see SchemaCheck::effective()}):
 * a property flagged for the other direction (`readOnly` for a request,
 * `writeOnly` for a response) loses its `required` entry and keeps its
 * subschema, recursively. This package used to carry its own copy of that
 * rewrite, which drifted from the validator's — it never recursed into
 * `additionalProperties`, for one — so the rewrite is no longer written here.
 * Whether such a property is *generated* is the compiler's decision
 * ({@see \Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler}), not the
 * schema's: a request never carries a `readOnly` member, but its name stays
 * declared so no undeclared member is generated under it.
 *
 * @internal
 */
final readonly class DirectionalSchemas
{
    private SchemaCheck $check;

    public function __construct()
    {
        $this->check = new SchemaCheck();
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function effective(array $schema, SchemaDirection $direction): array
    {
        return $this->check->effective($schema, $direction);
    }

    /**
     * Drops the members flagged for the other direction from a value, so a
     * document example shared between directions stays valid for one of them.
     *
     * @param array<string, mixed> $schema
     */
    public function value(mixed $value, array $schema, SchemaDirection $direction): mixed
    {
        $flag = $direction->foreignFlag();
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $items = $schema['items'] ?? null;
            if (!is_array($items) || array_is_list($items)) {
                return $value;
            }

            /** @var array<string, mixed> $items */
            return array_map(fn(mixed $item): mixed => $this->value($item, $items, $direction), $value);
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
            $result += [$name => $this->member($value[$name], $properties[$name] ?? null, $direction)];
        }

        return $result;
    }

    private function isFlagged(mixed $property, string $flag): bool
    {
        return is_array($property) && !array_is_list($property) && ($property[$flag] ?? false) === true;
    }

    private function member(mixed $member, mixed $property, SchemaDirection $direction): mixed
    {
        if (!is_array($property) || array_is_list($property)) {
            return $member;
        }

        /** @var array<string, mixed> $property */
        return $this->value($member, $property, $direction);
    }
}
