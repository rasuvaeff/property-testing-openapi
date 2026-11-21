<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;

/**
 * The two map readings the materializers and the JSON encoder take from a
 * schema they are handed with a case: a Schema Object, and a map keyed by
 * member name. Anything else is a malformed case, `InvalidCase` — not a
 * document limitation. The compiler's own reading
 * ({@see Compile\SchemaFacts::schemaObject()}) raises `UnsupportedGeneration`
 * instead, and stays apart for that reason.
 *
 * @internal
 */
final readonly class CaseMaps
{
    /**
     * A Schema Object: a map keyed by JSON Schema keywords, which are never
     * numeric, so `array<string, mixed>` is a promise PHP can keep here.
     *
     * @return array<string, mixed>
     */
    public static function schemaObject(mixed $value, string $message): array
    {
        /** @var array<string, mixed> $map */
        $map = self::memberMap($value, $message);

        return $map;
    }

    /**
     * A map keyed by member name — a `properties` declaration, or an object
     * value. The key type is `array-key` and not `string` because PHP
     * normalizes a numeric-string key to an integer: a member the document
     * named `"2020"` really does arrive as `int 2020`. Saying `string` here
     * was the lie behind refusing such a name outright.
     *
     * `array_replace`, not `array_merge`: the latter renumbers integer-like
     * keys, so the very names this exists to preserve would be the ones it
     * lost.
     *
     * @return array<array-key, mixed>
     */
    public static function memberMap(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new InvalidCase($message);
        }
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidCase($message);
        }

        /** @var array<array-key, mixed> $result */
        $result = [];
        foreach (array_keys($value) as $key) {
            $result = array_replace($result, [$key => $value[$key]]);
        }

        return $result;
    }
}
