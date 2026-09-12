<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\ContractException;
use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;

/**
 * Decides whether a candidate witness earns the category it is offered for.
 *
 * A witness discriminates when the full schema rejects it and the schema
 * without the category's keywords accepts it. That is the property the probes
 * used to approximate with three hand-maintained lists of mutually-excluded
 * keywords — one per probe, with different contents, and none at all on the
 * boundary probe. The lists were both too strict and inconsistent: a schema of
 * `format: email` with `maxLength: 255`, the most common string shape there
 * is, got neither a `format` case nor a `length` one, even though
 * `'not-an-email'` is twelve characters and provably violates only the format
 * (#100). Relaxing a list meant reasoning about keyword interactions by hand,
 * which is how the asymmetry between the pattern and format probes arose in
 * the first place (#102).
 *
 * Checking the property directly costs two schema validations per candidate.
 * Schemas are static per target, so the answers are memoised and the work is
 * done once per document rather than once per draw.
 *
 * The check runs against the contract's own validator, not a second copy of
 * it: a candidate judged by different rules than the ones that will judge the
 * generated request is a case that passes for the wrong reason.
 *
 * @internal
 *
 * @psalm-import-type Kind from JsonBodyWitness
 * @psalm-import-type Witness from JsonBodyWitness
 */
final class WitnessCheck
{
    /**
     * The keywords each category claims to contradict. Removing them must
     * leave a schema the witness satisfies, or the category name is a lie
     * about which assertion rejected the request.
     */
    private const array KEYWORDS = [
        'type' => ['type'],
        'enum' => ['enum'],
        'const' => ['const'],
        'boundary' => ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum'],
        'length' => ['minLength', 'maxLength', 'minItems', 'maxItems'],
        'format' => ['format'],
        'pattern' => ['pattern'],
    ];

    private readonly SchemaCheck $schemas;

    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct()
    {
        $this->schemas = new SchemaCheck();
    }

    /**
     * The first candidate that provably violates the category's keywords and
     * nothing else; `null` when none does.
     *
     * @param list<Witness> $candidates in preference order
     * @param array<string, mixed> $schema
     * @param Kind $kind
     * @return Witness|null
     */
    public function firstDiscriminating(array $candidates, array $schema, string $kind, SchemaDialect $dialect, SchemaDirection $direction): int|float|bool|string|array|null
    {
        foreach ($candidates as $candidate) {
            if ($this->discriminates($candidate, $schema, $kind, $dialect, $direction)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @param Kind $kind
     */
    public function discriminates(mixed $value, array $schema, string $kind, SchemaDialect $dialect, SchemaDirection $direction): bool
    {
        $key = $this->key($value, $schema, $kind, $dialect, $direction);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $stripped = $schema;
        foreach (self::KEYWORDS[$kind] as $keyword) {
            unset($stripped[$keyword]);
        }

        try {
            $decoded = $this->asJson($value);
            // A schema the contract cannot evaluate cannot judge the generated
            // request either, so no witness built from it is provable and the
            // category fails closed rather than promising a contradiction.
            $answer = !$this->schemas->accepts($decoded, $schema, $dialect, $direction)
                && $this->schemas->accepts($decoded, $stripped, $dialect, $direction);
        } catch (ContractException|\JsonException) {
            $answer = false;
        }

        return $this->memo[$key] = $answer;
    }

    /**
     * The value as the validator will receive it, not as PHP holds it: a body
     * travels as JSON and comes back with its objects as `stdClass`, while an
     * associative PHP array is a JSON *array* no `type: object` schema
     * admits.
     *
     * The conversion is spelled out rather than done with a JSON round trip,
     * so the result carries a type the caller can act on instead of `mixed`.
     *
     * @return array<array-key, mixed>|bool|float|int|string|object|null
     */
    private function asJson(mixed $value): array|bool|float|int|string|object|null
    {
        if (is_array($value)) {
            $converted = [];
            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $converted[$key] = $this->asJson($item);
            }

            return array_is_list($value) ? $converted : (object) $converted;
        }
        if (is_object($value)) {
            return $value;
        }

        return is_bool($value) || is_float($value) || is_int($value) || is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $schema */
    private function key(mixed $value, array $schema, string $kind, SchemaDialect $dialect, SchemaDirection $direction): string
    {
        return hash('xxh128', implode("\0", [
            $dialect->name,
            $direction->name,
            $kind,
            serialize($value),
            serialize($schema),
        ]));
    }
}
