<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\JsonBodyWitness;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The body-side candidate search, exercised directly: which property a kind
 * lands on, and what it writes there.
 */
#[Test]
#[Covers(JsonBodyWitness::class)]
final class JsonBodyWitnessTest
{
    private JsonBodyWitness $witnesses;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->witnesses = new JsonBodyWitness();
    }

    /** @return list<array{name: string, invalid: mixed}> */
    private function find(array $schema, string $kind, SchemaDialect $dialect = SchemaDialect::OpenApi31): array
    {
        return $this->witnesses->findAll($schema, $kind, $dialect, SchemaDirection::Request);
    }

    /**
     * `null` is admitted in addition to the declared type, not instead of its
     * bounds: the witness the check approves must not be withheld from it
     * (#112).
     */
    #[DataProvider('nullableProvider')]
    public function aNullablePropertyKeepsItsBoundsUnder30(array $property, string $kind, mixed $expected): void
    {
        $found = $this->find(['type' => 'object', 'properties' => ['a' => $property]], $kind, SchemaDialect::OpenApi30);

        Assert::same($found, [['name' => 'a', 'invalid' => $expected]]);
    }

    public static function nullableProvider(): iterable
    {
        yield 'length' => [['type' => 'string', 'maxLength' => 2000, 'nullable' => true], 'length', str_repeat('a', 2001)];
        yield 'boundary' => [['type' => 'integer', 'nullable' => true, 'maximum' => 9], 'boundary', 10];
        yield 'type' => [['type' => 'string', 'nullable' => true], 'type', 4096];
        yield 'enum' => [['type' => 'string', 'nullable' => true, 'enum' => ['x', null]], 'enum', '__openapi_misuse__'];
        yield 'format' => [['type' => 'string', 'nullable' => true, 'format' => 'email'], 'format', 'not-an-email'];
        yield 'pattern' => [['type' => 'string', 'nullable' => true, 'pattern' => '^[a-z]+$'], 'pattern', ''];
    }

    #[DataProvider('objectBodyProvider')]
    public function everyEligiblePropertyIsFound(array $properties, string $kind, array $expected): void
    {
        Assert::same($this->find(['type' => 'object', 'properties' => $properties], $kind), $expected);
    }

    public static function objectBodyProvider(): iterable
    {
        yield 'both bounded properties, in declaration order' => [
            ['a' => ['type' => 'integer', 'maximum' => 9], 'b' => ['type' => 'integer', 'minimum' => 1]],
            'boundary',
            [['name' => 'a', 'invalid' => 10], ['name' => 'b', 'invalid' => 0]],
        ];
        // `nullable` is not a keyword in OAS 3.1: the contract refuses the
        // schema and the check fails closed. A type union is the 3.1 spelling.
        yield 'a nullable property is unreadable under 3.1 and skipped' => [
            ['a' => ['type' => 'integer', 'nullable' => true, 'maximum' => 9], 'b' => ['type' => 'integer', 'maximum' => 9]],
            'boundary',
            [['name' => 'b', 'invalid' => 10]],
        ];
        yield 'a type union with null keeps its bound' => [
            ['a' => ['type' => ['integer', 'null'], 'maximum' => 9]],
            'boundary',
            [['name' => 'a', 'invalid' => 10]],
        ];
        // A negated property is judged by a schema this search does not read.
        yield 'a negated property is skipped' => [
            ['a' => ['type' => 'integer', 'maximum' => 9, 'not' => ['const' => 1]], 'b' => ['type' => 'integer', 'maximum' => 9]],
            'boundary',
            [['name' => 'b', 'invalid' => 10]],
        ];
        yield 'a type union is skipped' => [
            ['a' => ['type' => ['integer', 'string']], 'b' => ['type' => 'integer']],
            'type',
            [['name' => 'b', 'invalid' => 'not-a-integer']],
        ];
        yield 'a string property is contradicted by a number' => [
            ['a' => ['type' => 'string']],
            'type',
            [['name' => 'a', 'invalid' => 4096]],
        ];
        yield 'an enum of strings takes the marker' => [
            ['a' => ['type' => 'string', 'enum' => ['x', 'y']]],
            'enum',
            [['name' => 'a', 'invalid' => '__openapi_misuse__']],
        ];
        yield 'an enum of integers takes a typed alternative' => [
            ['a' => ['type' => 'integer', 'enum' => [1, 2]]],
            'enum',
            [['name' => 'a', 'invalid' => 0]],
        ];
        yield 'an enum that already holds the marker is stepped past' => [
            ['a' => ['type' => 'string', 'enum' => ['__openapi_misuse__']]],
            'enum',
            [['name' => 'a', 'invalid' => '__openapi_misuse___']],
        ];
        yield 'a non-array enum is no enum' => [['a' => ['type' => 'string', 'enum' => 'x']], 'enum', []];
        yield 'an empty enum is no enum' => [['a' => ['type' => 'string', 'enum' => []]], 'enum', []];
        yield 'a non-scalar enum member is no enum' => [['a' => ['type' => 'string', 'enum' => [['x']]]], 'enum', []];
        yield 'a string const is appended to' => [
            ['a' => ['type' => 'string', 'const' => 'fixed']],
            'const',
            [['name' => 'a', 'invalid' => 'fixed__openapi_misuse__']],
        ];
        yield 'a non-scalar const is no const' => [['a' => ['type' => 'object', 'const' => ['x' => 1]]], 'const', []];
        yield 'a null const is contradicted by the marker' => [
            ['a' => ['const' => null]],
            'const',
            [['name' => 'a', 'invalid' => '__openapi_misuse__']],
        ];
    }

    #[DataProvider('arrayLengthProvider')]
    public function anArrayLengthWitnessBreaksOnlyTheItemCount(array $schema, array $expected): void
    {
        Assert::same($this->find(['type' => 'object', 'properties' => ['a' => $schema]], 'length'), $expected);
    }

    public static function arrayLengthProvider(): iterable
    {
        $items = ['type' => 'integer'];

        yield 'minItems is broken by the empty array' => [
            ['type' => 'array', 'minItems' => 1, 'items' => $items],
            [['name' => 'a', 'invalid' => []]],
        ];
        // The filler has to satisfy `items`; a list of `null`s would break
        // those as well, which is not the length mismatch the kind records.
        yield 'maxItems 0 is broken by one admissible item' => [
            ['type' => 'array', 'maxItems' => 0, 'items' => $items],
            [['name' => 'a', 'invalid' => [0]]],
        ];
        yield 'maxItems 2 is broken by three' => [
            ['type' => 'array', 'maxItems' => 2, 'items' => $items],
            [['name' => 'a', 'invalid' => [0, 0, 0]]],
        ];
        yield 'string items take a string filler' => [
            ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'string']],
            [['name' => 'a', 'invalid' => ['', '']]],
        ];
        yield 'no item bound at all' => [['type' => 'array', 'items' => $items], []];
        yield 'a non-integer bound is ignored' => [['type' => 'array', 'maxItems' => '2', 'items' => $items], []];
    }

    /** A body that is not an object is contradicted at its root, named `$`. */
    public function aScalarRootIsItsOwnTarget(): void
    {
        Assert::same($this->find(['type' => 'integer', 'minimum' => 1], 'boundary'), [['name' => JsonBodyWitness::ROOT, 'invalid' => 0]]);
    }

    /** A property declared with a malformed schema is not a candidate. */
    public function malformedPropertyDeclarationsAreSkipped(): void
    {
        $properties = [
            '' => ['type' => 'integer', 'maximum' => 3],
            'flag' => true,
            'list' => [['type' => 'integer', 'maximum' => 3]],
            'n' => ['type' => 'integer', 'maximum' => 3],
        ];

        Assert::same($this->find(['type' => 'object', 'properties' => $properties], 'boundary'), [['name' => 'n', 'invalid' => 4]]);
    }
}
