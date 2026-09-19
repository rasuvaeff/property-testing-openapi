<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\CompositionArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\Definitions;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\SchemaFacts;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\Unproducible;

/**
 * Compiles the explicit JSON-compatible schema subset into shrinkable values.
 *
 * @internal Reach it through {@see RequestCaseArbitrary} and {@see ResponseCaseArbitrary}.
 */
final readonly class SchemaArbitraryCompiler
{
    /**
     * How many levels a recursive def unfolds before its leaf: a tree three
     * nodes deep, the breadth of each level bounded by the containers'
     * collection budget.
     */
    private const int RECURSION_DEPTH = 3;

    private SchemaFacts $facts;

    private CompositionArbitraries $composition;

    private ScalarArbitraries $scalars;

    private ContainerArbitraries $containers;

    private Definitions $definitions;

    /**
     * @param string $excludedCharacters characters no generated plain string
     *        may contain — the separator of a delimited parameter style, which
     *        that style has no way to escape
     * @param null|SchemaDirection $direction the direction the values travel
     *        in, when they travel in one: a property the other direction owns
     *        (`readOnly` on a request, `writeOnly` on a response) is declared
     *        and typed by the contract but must not be sent, so an object
     *        never carries it — while its name stays reserved, so no undeclared
     *        member is generated under it either. A parameter has no direction.
     */
    public function __construct(string $excludedCharacters = '', ?SchemaDirection $direction = null)
    {
        $facts = new SchemaFacts();
        $this->facts = $facts;
        $this->definitions = new Definitions();
        $this->composition = new CompositionArbitraries($this, $facts, $this->definitions);
        $this->scalars = new ScalarArbitraries($facts, $excludedCharacters);
        $this->containers = new ContainerArbitraries($this, $facts, $direction);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function compile(array $schema): ArbitraryInterface
    {
        if (array_key_exists('$defs', $schema)) {
            $defs = $schema['$defs'];
            if (!is_array($defs)) {
                throw UnsupportedGeneration::forSchema('$defs must be an object of schemas');
            }
            unset($schema['$defs']);

            return $this->definitions->within($defs, fn(): ArbitraryInterface => $this->compile($schema));
        }
        if (array_key_exists('$ref', $schema)) {
            return $this->reference($schema['$ref']);
        }
        $combinator = $this->composition->combinator($schema);
        if ($combinator instanceof ArbitraryInterface) {
            return $combinator;
        }
        if (array_key_exists('not', $schema)) {
            return $this->composition->not($schema);
        }
        $this->assertSupported($schema);
        if ($schema === []) {
            return $this->containers->additionalValue();
        }
        if (($schema['nullable'] ?? false) === true) {
            unset($schema['nullable']);
            // OAS 3.0: with an enum (or const), null is selectable only when
            // the enum itself lists it — the validator rejects a bare null.
            if (isset($schema['enum']) && is_array($schema['enum']) && !in_array(null, $schema['enum'], strict: true)) {
                return $this->compile($schema);
            }
            if (array_key_exists('const', $schema) && $schema['const'] !== null) {
                return $this->compile($schema);
            }

            return Gen::nullable($this->compile($schema));
        }
        if (array_key_exists('const', $schema)) {
            return Gen::constant($schema['const']);
        }
        if (array_key_exists('enum', $schema)) {
            $values = $schema['enum'];
            if (!is_array($values) || $values === []) {
                throw UnsupportedGeneration::forSchema('enum must be a non-empty list');
            }

            return Gen::elements(array_values($values));
        }

        $types = $this->facts->types($schema['type'] ?? null);
        if ($types !== null && count($types) > 1) {
            $branches = [];
            foreach ($types as $candidate) {
                $branch = $schema;
                $branch['type'] = $candidate;
                $branches[] = $this->compile($branch);
            }

            return Gen::frequency(array_map(static fn(ArbitraryInterface $branch): array => [1, $branch], $branches));
        }

        $type = $this->type($schema);

        return match ($type) {
            'string' => $this->scalars->string($schema),
            'integer' => $this->scalars->integer($schema),
            'number' => $this->scalars->number($schema),
            'boolean' => Gen::bool(),
            'null' => Gen::constant(null),
            'array' => $this->containers->array($schema),
            'object' => $this->containers->object($schema),
            default => throw UnsupportedGeneration::forSchema(sprintf('type "%s" is not supported', $type)),
        };
    }

    /**
     * A local reference into the schema's `$defs`: the compiled form of a
     * schema that refers to itself. The def unfolds {@see RECURSION_DEPTH}
     * levels, each compiled with the def's own name bound to the level
     * below, down to a leaf compiled with the name bound to nothing — where
     * a reference back to the def is unproducible and the containers around
     * it leave it out. A def whose leaf has no value at all (a required
     * member that is the def itself) has no finite instance, and is refused.
     */
    private function reference(mixed $reference): ArbitraryInterface
    {
        [$name, $body] = $this->definitions->target($reference);
        if ($this->definitions->isBound($name)) {
            return $this->definitions->bound($name) ?? throw new Unproducible($name);
        }

        try {
            $leaf = $this->definitions->bind($name, null, fn(): ArbitraryInterface => $this->compile($body));
        } catch (Unproducible) {
            throw UnsupportedGeneration::forSchema(sprintf('recursive schema "%s" has no finite instance', $name));
        }

        return Gen::recursive(
            $leaf,
            fn(ArbitraryInterface $level): ArbitraryInterface => $this->definitions->bind($name, $level, fn(): ArbitraryInterface => $this->compile($body)),
            self::RECURSION_DEPTH,
        );
    }

    /** @param array<string, mixed> $schema */
    private function type(array $schema): string
    {
        $types = $this->facts->types($schema['type'] ?? null);
        if ($types !== null) {
            foreach ($types as $candidate) {
                if ($candidate !== 'null') {
                    return $candidate;
                }
            }
            if (in_array('null', $types, strict: true)) {
                return 'null';
            }
        }
        if (array_key_exists('properties', $schema)) {
            return 'object';
        }
        if (array_key_exists('items', $schema)) {
            return 'array';
        }

        throw UnsupportedGeneration::forSchema('a type, properties, or items declaration is required');
    }

    /** @param array<string, mixed> $schema */
    private function assertSupported(array $schema): void
    {
        foreach ([
            'allOf', 'anyOf', 'oneOf', 'if', 'then', 'else',
            'contains', 'prefixItems', 'patternProperties',
            'propertyNames', 'unevaluatedProperties',
        ] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw UnsupportedGeneration::forSchema(sprintf('keyword "%s" is outside the initial support matrix', $keyword));
            }
        }
        if (array_key_exists('additionalProperties', $schema)) {
            $this->facts->additionalPropertiesSchema($schema);
        }
        if (($schema['exclusiveMinimum'] ?? false) !== false && !is_bool($schema['exclusiveMinimum'])) {
            throw UnsupportedGeneration::forSchema('numeric exclusiveMinimum is not supported');
        }
        if (($schema['exclusiveMaximum'] ?? false) !== false && !is_bool($schema['exclusiveMaximum'])) {
            throw UnsupportedGeneration::forSchema('numeric exclusiveMaximum is not supported');
        }
    }
}
