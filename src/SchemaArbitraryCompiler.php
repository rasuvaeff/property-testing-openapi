<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\CompositionArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\Definitions;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\SchemaFacts;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\Unproducible;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Rasuvaeff\PropertyTesting\Random;

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

    private const int CONDITIONAL_PROBES = 8;

    private const int CONDITIONAL_PROBE_SEED = 11;

    private SchemaFacts $facts;

    private CompositionArbitraries $composition;

    private ScalarArbitraries $scalars;

    private ContainerArbitraries $containers;

    private Definitions $definitions;

    private SchemaCheck $check;

    private JsonBodyEncoder $encoder;

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
    public function __construct(string $excludedCharacters = '', private ?SchemaDirection $direction = null)
    {
        $facts = new SchemaFacts();
        $this->facts = $facts;
        $this->check = new SchemaCheck();
        $this->encoder = new JsonBodyEncoder();
        $this->definitions = new Definitions();
        $this->composition = new CompositionArbitraries($this, $facts, $this->definitions);
        $this->scalars = new ScalarArbitraries($facts, $excludedCharacters);
        $this->containers = new ContainerArbitraries($this, $facts, $this->direction);
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
        if (array_key_exists('dependentRequired', $schema) || array_key_exists('dependentSchemas', $schema)) {
            return $this->dependencies($schema);
        }
        if ((array_key_exists('unevaluatedProperties', $schema) || array_key_exists('unevaluatedItems', $schema))
            && (array_key_exists('if', $schema) || array_key_exists('anyOf', $schema) || array_key_exists('oneOf', $schema))) {
            throw UnsupportedGeneration::forSchema('unevaluated keywords with conditional or union applicators are outside the constructive subset');
        }
        if (array_key_exists('if', $schema) || array_key_exists('then', $schema) || array_key_exists('else', $schema)) {
            return $this->conditional($schema);
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
     * Compiles JSON Schema's conditional branch into a choice of constructive
     * schemas. The true branch is built as a conjunction of the base schema,
     * the condition and `then`, which makes the condition hold by
     * construction rather than by filtering; the false branch is built from
     * the base schema and `else` and filtered by the condition, which is what
     * an unsatisfiable `else` looks like from the outside. A branch the
     * conditional does not declare is the base schema on that side, filtered
     * the same way — the conditional leaves that side unchanged.
     *
     * A branch that yields no value at all — `if: false` beside a `then` that
     * demands what the base schema forbids — is dropped from the choice rather
     * than generated from and filtered, and a conditional where neither side
     * survives has no finite instance and fails closed.
     *
     * @param array<string, mixed> $schema
     */
    private function conditional(array $schema): ArbitraryInterface
    {
        if (!array_key_exists('if', $schema)) {
            unset($schema['then'], $schema['else']);

            return $this->compile($schema);
        }
        $condition = $this->conditionSchema($schema['if'], 'if');
        $hasThen = array_key_exists('then', $schema);
        $hasElse = array_key_exists('else', $schema);
        $then = $hasThen ? $this->conditionSchema($schema['then'], 'then') : [];
        $else = $hasElse ? $this->conditionSchema($schema['else'], 'else') : [];
        unset($schema['if'], $schema['then'], $schema['else']);

        $pairs = [];
        if ($hasThen) {
            $true = $condition === false || $then === false
                ? $this->impossible()
                : $this->compile(['allOf' => [$schema, $this->asSchema($condition), $this->asSchema($then)]]);
        } else {
            $true = $this->compile($schema);
            $true = Gen::filter($true, fn(mixed $value): bool => $this->matchesCondition($value, $condition));
        }
        if ($this->yieldsSomething($true)) {
            $pairs[] = [1, $true];
        }

        if ($hasElse) {
            $false = ($else === false)
                ? $this->impossible()
                : $this->compile(['allOf' => [$schema, $this->asSchema($else)]]);
        } else {
            $false = $this->compile($schema);
        }
        $false = Gen::filter($false, fn(mixed $value): bool => !$this->matchesCondition($value, $condition));
        if ($this->yieldsSomething($false)) {
            $pairs[] = [1, $false];
        }
        if ($pairs === []) {
            throw UnsupportedGeneration::forSchema('if/then/else admits no producible value');
        }

        return Gen::frequency($pairs);
    }

    /** @param array<string, mixed> $schema */
    private function dependencies(array $schema): ArbitraryInterface
    {
        if (($schema['type'] ?? 'object') !== 'object' || array_key_exists('patternProperties', $schema)
            || array_key_exists('if', $schema) || array_key_exists('allOf', $schema)
            || array_key_exists('anyOf', $schema) || array_key_exists('oneOf', $schema)) {
            throw UnsupportedGeneration::forSchema('dependent keywords require a plain object schema');
        }
        $requiredMap = $this->facts->schemaObject($schema['dependentRequired'] ?? [], 'dependentRequired must be an object of lists');
        $schemaMap = $this->facts->schemaObject($schema['dependentSchemas'] ?? [], 'dependentSchemas must be an object of schemas');
        /** @var array<array-key, mixed> $requiredMap */
        /** @var array<array-key, mixed> $schemaMap */
        $properties = $this->facts->schemaObject($schema['properties'] ?? [], 'object properties must be an object');
        /** @var list<array-key> $triggers */
        $triggers = array_values(array_unique([...array_keys($requiredMap), ...array_keys($schemaMap)]));
        if (count($triggers) > 8) {
            throw UnsupportedGeneration::forSchema('dependent keywords exceed the eight-trigger generation budget');
        }
        foreach ($triggers as $trigger) {
            if (!array_key_exists($trigger, $properties)) {
                throw UnsupportedGeneration::forSchema('dependent trigger must have a declared property schema');
            }
        }
        foreach ($requiredMap as $members) {
            if (!is_array($members) || !array_is_list($members)) {
                throw UnsupportedGeneration::forSchema('dependentRequired values must be lists of names');
            }
            /** @var mixed $member */
            foreach ($members as $member) {
                if (!is_string($member)) {
                    throw UnsupportedGeneration::forSchema('dependentRequired values must contain names');
                }
            }
        }
        /** @var mixed $member */
        foreach ($schemaMap as $member) {
            if (!is_array($member) || ($member !== [] && array_is_list($member))) {
                throw UnsupportedGeneration::forSchema('dependentSchemas values must be schema objects');
            }
            /** @var array<string, mixed> $member */
            foreach (array_keys($member) as $keyword) {
                if (!in_array($keyword, ['type', 'required', 'properties', 'minProperties', 'maxProperties', 'additionalProperties', 'description', 'title'], strict: true)) {
                    throw UnsupportedGeneration::forSchema(sprintf('dependentSchemas keyword "%s" is outside the constructive subset', $keyword));
                }
            }
        }
        $foreignFlag = $this->direction?->foreignFlag();
        if ($foreignFlag !== null) {
            /** @var mixed $property */
            foreach ($properties as $property) {
                if (is_array($property) && ($property[$foreignFlag] ?? false) === true) {
                    throw UnsupportedGeneration::forSchema('dependent keywords with foreign-direction properties are outside the constructive subset');
                }
            }
        }
        unset($schema['dependentRequired'], $schema['dependentSchemas']);
        $pairs = [];
        for ($mask = 0; $mask < (1 << count($triggers)); ++$mask) {
            $present = [];
            $absent = [];
            $branches = [$schema];
            foreach ($triggers as $index => $trigger) {
                $triggerName = (string) $trigger;
                if (($mask & (1 << $index)) === 0) {
                    $absent[] = $triggerName;

                    continue;
                }
                $present[] = $triggerName;
                if (isset($requiredMap[$triggerName])) {
                    /** @var list<string> $members */
                    $members = $requiredMap[$triggerName];
                    $branches[] = ['required' => $members];
                }
                if (isset($schemaMap[$triggerName])) {
                    /** @var array<string, mixed> $dependent */
                    $dependent = $schemaMap[$triggerName];
                    $branches[] = $dependent;
                }
            }
            $branches[] = ['required' => $present];

            try {
                $merged = $this->composition->mergeAllOf($branches);
                $this->assertSupported($merged);
                $pairs[] = [1, $this->containers->object($merged, $absent)];
            } catch (UnsupportedGeneration|Unproducible) {
                // A presence combination can be impossible: a required
                // trigger is absent, or its dependency exceeds cardinality.
            }
        }
        if ($pairs === []) {
            throw UnsupportedGeneration::forSchema('dependent keywords admit no constructible presence combination');
        }

        return Gen::frequency($pairs);
    }

    /** @return bool|array<string, mixed> */
    private function conditionSchema(mixed $value, string $keyword): bool|array
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw UnsupportedGeneration::forSchema(sprintf('%s must be a schema object', $keyword));
        }

        /** @var array<string, mixed> $schema */
        $schema = $value;

        return $schema;
    }

    /** @param bool|array<string, mixed> $schema */
    private function matchesCondition(mixed $value, bool|array $schema): bool
    {
        if (is_bool($schema)) {
            return $schema;
        }

        try {
            return $this->check->accepts(
                $this->encoder->jsonValue($value, $schema),
                $schema,
                SchemaDialect::OpenApi31,
                $this->direction ?? SchemaDirection::Request,
            );
        } catch (\Throwable $exception) {
            throw UnsupportedGeneration::forSchema(sprintf('if condition cannot be evaluated: %s', $exception->getMessage()));
        }
    }

    /**
     * A boolean subschema as the conjunction member this compiler builds: the
     * schema `true` writes. `false` is not one — it is the branch that admits
     * nothing, and the caller has already turned it into {@see impossible()}.
     *
     * @param true|array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function asSchema(true|array $schema): array
    {
        return $schema === true ? [] : $schema;
    }

    private function impossible(): ArbitraryInterface
    {
        return Gen::filter(Gen::constant(null), static fn(): bool => false);
    }

    private function yieldsSomething(ArbitraryInterface $arbitrary): bool
    {
        $random = new Random(self::CONDITIONAL_PROBE_SEED);
        for ($probe = 0; $probe < self::CONDITIONAL_PROBES; ++$probe) {
            try {
                $arbitrary->generate($random);

                return true;
            } catch (GenerationExhaustedException) {
                continue;
            }
        }

        return false;
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
        if (array_key_exists('dependentRequired', $schema) || array_key_exists('dependentSchemas', $schema) || array_key_exists('unevaluatedProperties', $schema)) {
            return 'object';
        }
        if (array_key_exists('items', $schema) || array_key_exists('prefixItems', $schema) || array_key_exists('contains', $schema)) {
            return 'array';
        }

        throw UnsupportedGeneration::forSchema('a type, properties, or items declaration is required');
    }

    /** @param array<string, mixed> $schema */
    private function assertSupported(array $schema): void
    {
        foreach ([
            'allOf', 'anyOf', 'oneOf',
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
