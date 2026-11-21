<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Which schema an empty PHP map is read under: it is `{}` on the wire only
 * where some applicable schema says `object`, and `[]` everywhere else.
 */
#[Test]
#[Covers(JsonBodyEncoder::class)]
final class JsonBodyEncoderTest
{
    #[DataProvider('encodingProvider')]
    public function anEmptyMapIsAnObjectWhereASchemaSaysSo(mixed $value, array $schema, string $expected): void
    {
        Assert::same((new JsonBodyEncoder())->encode($value, $schema), $expected);
    }

    public static function encodingProvider(): iterable
    {
        // A union or a conditional gives the value its shape when the schema
        // itself declares none (#154).
        yield 'empty value under anyOf of an object and null' => [
            [],
            ['anyOf' => [['type' => 'object', 'properties' => ['k' => ['type' => 'string']]], ['type' => 'null']]],
            '{}',
        ];
        yield 'empty value under oneOf of an object and null' => [
            [],
            ['oneOf' => [['type' => 'null'], ['type' => 'object']]],
            '{}',
        ];
        yield 'empty value under then' => [
            [],
            ['if' => ['const' => 1], 'then' => ['type' => 'object'], 'else' => ['type' => 'null']],
            '{}',
        ];
        yield 'empty value under else' => [
            [],
            ['if' => ['const' => 1], 'then' => true, 'else' => ['properties' => []]],
            '{}',
        ];
        yield 'empty value under a union of an array only' => [
            [],
            ['anyOf' => [['type' => 'array'], ['type' => 'null']]],
            '[]',
        ];
        yield 'empty value under a union of both shapes stays as it was' => [
            [],
            ['anyOf' => [['type' => 'array'], ['type' => 'object']]],
            '[]',
        ];
        yield 'a map takes the object branch, and its empty member stays an object' => [
            ['o' => []],
            ['anyOf' => [['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'object', 'properties' => ['o' => ['type' => 'object']]]]],
            '{"o":{}}',
        ];
        yield 'a list takes the array branch, and its empty item stays an object' => [
            [[]],
            ['anyOf' => [['type' => 'object'], ['type' => 'array', 'items' => ['type' => 'object']]]],
            '[{}]',
        ];
        yield 'a referenced branch is read through $defs' => [
            [],
            ['$defs' => ['O' => ['type' => 'object']], 'anyOf' => [['$ref' => '#/$defs/O'], ['type' => 'null'], 'malformed']],
            '{}',
        ];
        // `contains` adds to `items`; it does not replace it.
        yield 'items keep their own type beside contains' => [
            [[]],
            ['type' => 'array', 'items' => ['type' => 'object'], 'contains' => ['minimum' => 0]],
            '[{}]',
        ];
        yield 'contains supplies the type an untyped items schema lacks' => [
            [[]],
            ['type' => 'array', 'items' => [], 'contains' => ['type' => 'object']],
            '[{}]',
        ];
        // Without `items`, `contains` is what an item is read under before
        // `unevaluatedItems`.
        yield 'contains comes before unevaluatedItems' => [
            [[]],
            ['type' => 'array', 'contains' => ['type' => 'object'], 'unevaluatedItems' => ['type' => 'string']],
            '[{}]',
        ];
        // An `allOf` branch lends the root its type and keeps every keyword
        // the root already declares.
        yield 'a branch type joins the root keywords' => [
            ['a' => []],
            ['allOf' => [['type' => 'object']], 'properties' => ['a' => ['type' => 'object']]],
            '{"a":{}}',
        ];
        // A boolean branch carries no shape and is passed over, not the end of
        // the branches.
        yield 'a boolean allOf branch does not hide the next one' => [
            [],
            ['allOf' => [true, ['type' => 'object']]],
            '{}',
        ];
        yield 'boolean items carry no shape' => [
            [[]],
            ['type' => 'array', 'items' => true],
            '[[]]',
        ];
        yield 'a boolean prefix item carries no shape' => [
            [[], []],
            ['type' => 'array', 'prefixItems' => [true], 'items' => ['type' => 'object']],
            '[[],{}]',
        ];
    }
}
